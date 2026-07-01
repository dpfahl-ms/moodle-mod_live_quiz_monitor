<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External API tests for unblock_student.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

use advanced_testcase;
use context_module;
use mod_quiz\quiz_attempt;
use moodle_exception;
use quiz_livequizmonitor\local\manager\onesession_manager;
use required_capability_exception;

/**
 * Tests for the unblock_student external function.
 *
 * @covers \quiz_livequizmonitor\external\unblock_student
 * @runTestsInSeparateProcesses
 */
final class unblock_student_test extends advanced_testcase {

    /**
     * Require quizaccess_onesession or skip.
     */
    private function require_onesession_plugin(): void {
        if (!onesession_manager::is_plugin_installed()) {
            $this->markTestSkipped('quizaccess_onesession is not installed');
        }
    }

    /**
     * Create a course quiz with one short-answer question.
     *
     * @param \stdClass $course Course record.
     * @return array{0: \stdClass, 1: \stdClass, 2: \mod_quiz_generator}
     */
    private function create_quiz_with_question(\stdClass $course): array {
        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');

        $quiz = $quizgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'sumgrades' => 1,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $cat = $questiongenerator->create_question_category(['contextid' => context_module::instance($cm->id)->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        return [$quiz, $cm, $quizgenerator];
    }

    /**
     * Enable onesession for a quiz.
     *
     * @param int $quizid Quiz id.
     */
    private function enable_onesession_for_quiz(int $quizid): void {
        global $DB;

        $record = $DB->get_record('quizaccess_onesession', ['quizid' => $quizid]);
        if ($record) {
            $record->enabled = 1;
            $DB->update_record('quizaccess_onesession', $record);
        } else {
            $DB->insert_record('quizaccess_onesession', (object) [
                'quizid' => $quizid,
                'enabled' => 1,
            ]);
        }
    }

    /**
     * Unblock succeeds for in-progress blocked attempt.
     */
    public function test_execute_success(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);
        $this->enable_onesession_for_quiz((int) $quiz->id);

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $DB->insert_record('quizaccess_onesession_sess', (object) [
            'quizid' => $quiz->id,
            'attemptid' => $attempt->id,
            'sessionhash' => 'testhash',
        ]);

        $context = context_module::instance($cm->id);
        $event = \quizaccess_onesession\event\attempt_blocked::create([
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'courseid' => $cm->course,
            'context' => $context,
            'other' => ['quizid' => $quiz->id],
        ]);
        $event->trigger();

        $this->setUser($teacher);
        $result = unblock_student::execute($cm->id, $student->id, $attempt->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($DB->record_exists('quizaccess_onesession_sess', ['attemptid' => $attempt->id]));
    }

    /**
     * Idempotent unblock when no session lock remains.
     */
    public function test_execute_idempotent_when_already_unblocked(): void {
        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);
        $this->enable_onesession_for_quiz((int) $quiz->id);

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);
        $result = unblock_student::execute($cm->id, $student->id, $attempt->id);
        $this->assertTrue($result['success']);
    }

    /**
     * Rejects when attempt is not in progress.
     */
    public function test_execute_rejects_not_in_progress(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);
        $this->enable_onesession_for_quiz((int) $quiz->id);

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        $attempt->state = quiz_attempt::FINISHED;
        $attempt->timefinish = time();
        $DB->update_record('quiz_attempts', $attempt);

        $this->setUser($teacher);

        $this->expectException(moodle_exception::class);
        unblock_student::execute($cm->id, $student->id, $attempt->id);
    }

    /**
     * Rejects when onesession is not active on the quiz.
     */
    public function test_execute_rejects_onesession_inactive(): void {
        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);

        $this->expectException(moodle_exception::class);
        unblock_student::execute($cm->id, $student->id, $attempt->id);
    }

    /**
     * User without unlock capability cannot unblock.
     */
    public function test_execute_requires_unlock_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $viewer = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($viewer->id, $course->id, 'teacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);
        $this->enable_onesession_for_quiz((int) $quiz->id);

        $context = context_module::instance($cm->id);
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('quizaccess/onesession:unlockattempt', CAP_PROHIBIT, $roleid, $context->id, true);

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($viewer);

        $this->expectException(required_capability_exception::class);
        unblock_student::execute($cm->id, $student->id, $attempt->id);
    }
}
