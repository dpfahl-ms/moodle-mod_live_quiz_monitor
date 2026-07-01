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
 * Unit tests for onesession_manager.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use advanced_testcase;
use context_module;
use mod_quiz\quiz_attempt;
use moodle_exception;

/**
 * Tests for onesession soft integration.
 *
 * @covers \quiz_livequizmonitor\local\manager\onesession_manager
 */
final class onesession_manager_test extends advanced_testcase {

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
     * Insert a onesession log event for an attempt.
     *
     * @param string $eventname Event class name.
     * @param \stdClass $attempt Attempt record.
     * @param \stdClass $cm Course module.
     * @param int $actorid User id stored on the log row.
     * @param int|null $timecreated Optional log timestamp.
     */
    private function insert_onesession_log_event(
        string $eventname,
        \stdClass $attempt,
        \stdClass $cm,
        int $actorid,
        ?int $timecreated = null
    ): void {
        global $DB;

        $context = context_module::instance($cm->id);
        $DB->insert_record('logstore_standard_log', (object) [
            'eventname' => $eventname,
            'component' => 'quizaccess_onesession',
            'action' => 'blocked',
            'target' => 'attempt',
            'objecttable' => 'quiz_attempts',
            'objectid' => $attempt->id,
            'crud' => 'r',
            'edulevel' => 2,
            'contextid' => $context->id,
            'contextlevel' => CONTEXT_MODULE,
            'contextinstanceid' => $cm->id,
            'userid' => $actorid,
            'courseid' => $cm->course,
            'timecreated' => $timecreated ?? time(),
        ]);
    }

    /**
     * Trigger attempt_blocked event for an attempt.
     *
     * @param \stdClass $attempt Attempt record.
     * @param \stdClass $cm Course module.
     * @param \stdClass $quiz Quiz record.
     */
    private function trigger_blocked_event(\stdClass $attempt, \stdClass $cm, \stdClass $quiz): void {
        $context = context_module::instance($cm->id);
        $event = \quizaccess_onesession\event\attempt_blocked::create([
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'courseid' => $cm->course,
            'context' => $context,
            'other' => ['quizid' => $quiz->id],
        ]);
        $event->trigger();
    }

    /**
     * Trigger attempt_unlocked event for an attempt.
     *
     * @param \stdClass $attempt Attempt record.
     * @param \stdClass $cm Course module.
     * @param \stdClass $quiz Quiz record.
     */
    private function trigger_unlocked_event(\stdClass $attempt, \stdClass $cm, \stdClass $quiz): void {
        $context = context_module::instance($cm->id);
        $event = \quizaccess_onesession\event\attempt_unlocked::create([
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'courseid' => $cm->course,
            'context' => $context,
            'other' => ['quizid' => $quiz->id],
        ]);
        $event->trigger();
    }

    /**
     * get_blocked_map returns all false when plugin absent or no events.
     */
    public function test_get_blocked_map_empty_when_no_events(): void {
        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $map = onesession_manager::get_blocked_map([1, 2, 3]);
        $this->assertFalse($map[1]);
        $this->assertFalse($map[2]);
        $this->assertFalse($map[3]);
    }

    /**
     * Latest attempt_blocked event marks attempt as blocked.
     */
    public function test_get_blocked_map_blocked_after_event(): void {
        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->insert_onesession_log_event(onesession_manager::EVENT_ATTEMPT_BLOCKED, $attempt, $cm, $student->id);

        $map = onesession_manager::get_blocked_map([(int) $attempt->id]);
        $this->assertTrue($map[(int) $attempt->id]);
    }

    /**
     * Latest attempt_unlocked clears blocked state.
     */
    public function test_get_blocked_map_cleared_after_unlock_event(): void {
        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->insert_onesession_log_event(onesession_manager::EVENT_ATTEMPT_BLOCKED, $attempt, $cm, $student->id, time());
        $this->insert_onesession_log_event(onesession_manager::EVENT_ATTEMPT_UNLOCKED, $attempt, $cm, $student->id, time() + 1);

        $map = onesession_manager::get_blocked_map([(int) $attempt->id]);
        $this->assertFalse($map[(int) $attempt->id]);
    }

    /**
     * is_active_for_quiz is false when rule not enabled.
     */
    public function test_is_active_for_quiz_false_when_disabled(): void {
        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz] = $this->create_quiz_with_question($course);

        $this->assertFalse(onesession_manager::is_active_for_quiz((int) $quiz->id, $quiz));
    }

    /**
     * is_active_for_quiz is true when rule enabled.
     */
    public function test_is_active_for_quiz_true_when_enabled(): void {
        $this->resetAfterTest();
        $this->require_onesession_plugin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz] = $this->create_quiz_with_question($course);
        $this->enable_onesession_for_quiz((int) $quiz->id);

        $this->assertTrue(onesession_manager::is_active_for_quiz((int) $quiz->id, $quiz));
    }

    /**
     * unblock_attempt deletes session lock and logs unlock event.
     */
    public function test_unblock_attempt_clears_blocked_state(): void {
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
        $this->insert_onesession_log_event(onesession_manager::EVENT_ATTEMPT_BLOCKED, $attempt, $cm, $student->id, time());

        $context = context_module::instance($cm->id);
        $this->setUser($teacher);
        onesession_manager::unblock_attempt((int) $attempt->id, $context);

        $this->assertFalse($DB->record_exists('quizaccess_onesession_sess', ['attemptid' => $attempt->id]));
        $this->insert_onesession_log_event(onesession_manager::EVENT_ATTEMPT_UNLOCKED, $attempt, $cm, $teacher->id, time() + 1);
        $map = onesession_manager::get_blocked_map([(int) $attempt->id]);
        $this->assertFalse($map[(int) $attempt->id]);
    }

    /**
     * unblock_attempt rejects finished attempts.
     */
    public function test_unblock_attempt_rejects_not_in_progress(): void {
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
        global $DB;
        $DB->update_record('quiz_attempts', $attempt);

        $context = context_module::instance($cm->id);
        $this->setUser($teacher);

        $this->expectException(moodle_exception::class);
        onesession_manager::unblock_attempt((int) $attempt->id, $context);
    }

    /**
     * unblock_attempt rejects when onesession inactive on quiz.
     */
    public function test_unblock_attempt_rejects_when_onesession_inactive(): void {
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

        $context = context_module::instance($cm->id);
        $this->setUser($teacher);

        $this->expectException(moodle_exception::class);
        onesession_manager::unblock_attempt((int) $attempt->id, $context);
    }
}
