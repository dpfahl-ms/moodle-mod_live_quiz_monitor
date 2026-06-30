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
 * Unit tests for extend_time_manager.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use advanced_testcase;

/**
 * Tests for extend_time_manager.
 *
 * @covers \quiz_livequizmonitor\local\manager\extend_time_manager
 */
final class extend_time_manager_test extends advanced_testcase {

    /**
     * Create a timed quiz with one question.
     *
     * @param \stdClass $course Course record.
     * @return array{0: \stdClass, 1: \stdClass, 2: \mod_quiz_generator}
     */
    private function create_timed_quiz(\stdClass $course): array {
        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');

        $quiz = $quizgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'sumgrades' => 1,
            'timelimit' => 600,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $cat = $questiongenerator->create_question_category(['contextid' => \context_module::instance($cm->id)->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        return [$quiz, $cm, $quizgenerator];
    }

    /**
     * Individual extend creates a user override with incremented timelimit.
     */
    public function test_extend_user_creates_timelimit_override(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($student);
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);
        $outcome = extend_time_manager::extend_quiz_time(
            $course,
            $cm,
            $quiz,
            0,
            15,
            extend_time_manager::SCOPE_INDIVIDUAL,
            $student->id
        );

        $this->assertSame(1, $outcome->extendedcount);
        $override = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $student->id]);
        $this->assertNotFalse($override);
        $this->assertGreaterThan(600, (int) $override->timelimit);
    }

    /**
     * Bulk extend only targets in-progress students from the obliged cohort.
     */
    public function test_bulk_extend_targets_inprogress_only(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $inprogress = $generator->create_user();
        $notstarted = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($inprogress->id, $course->id, 'student');
        $generator->enrol_user($notstarted->id, $course->id, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($inprogress);
        $quizgenerator->create_attempt($quiz->id, $inprogress->id);

        $this->setUser($teacher);
        $userids = extend_time_manager::get_inprogress_user_ids($course, $cm, $quiz, 0);
        $this->assertSame([(int) $inprogress->id], $userids);

        $outcome = extend_time_manager::extend_quiz_time(
            $course,
            $cm,
            $quiz,
            0,
            10,
            extend_time_manager::SCOPE_BULK
        );

        $this->assertSame(1, $outcome->extendedcount);
        $this->assertContains(fullname($inprogress), $outcome->usernames);
    }

    /**
     * user_can_extend requires manageoverrides capability.
     */
    public function test_user_can_extend_requires_manageoverrides(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        [$quiz, $cm] = $this->create_timed_quiz($course);
        $context = \context_module::instance($cm->id);

        $this->setUser($teacher);
        $this->assertTrue(extend_time_manager::user_can_extend($context));

        $this->setUser($student);
        $this->assertFalse(extend_time_manager::user_can_extend($context));
    }
}
