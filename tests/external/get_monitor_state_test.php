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
 * External API tests for get_monitor_state.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

require_once(__DIR__ . '/../traits/group_scope_test_trait.php');

use advanced_testcase;
use moodle_exception;
use quiz_livequizmonitor\external\get_monitor_state;
use quiz_livequizmonitor\tests\traits\group_scope_test_trait;
use required_capability_exception;

/**
 * Tests for the get_monitor_state external function.
 *
 * @covers \quiz_livequizmonitor\external\get_monitor_state
 * @runTestsInSeparateProcesses
 */
final class get_monitor_state_test extends advanced_testcase {
    use group_scope_test_trait;

    /**
     * Teacher with capability receives valid payload shape.
     */
    public function test_execute_returns_monitor_state(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $quiz = $quizgenerator->create_instance(['course' => $course->id, 'grade' => 100, 'sumgrades' => 1, 'timelimit' => 600]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $cat = $questiongenerator->create_question_category(['contextid' => \context_module::instance($cm->id)->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        $this->setUser($student);
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);
        $result = get_monitor_state::execute($cm->id, 0);

        $this->assertSame((int) $quiz->id, $result['quizid']);
        $this->assertSame((int) $cm->id, $result['cmid']);
        $this->assertTrue($result['hasstudents']);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('students', $result);
        $this->assertCount(1, $result['students']);
        $this->assertSame('inprogress', $result['students'][0]['status']);
        $this->assertSame('badge-warning', $result['students'][0]['statusclass']);
        $this->assertSame('bg-warning', $result['students'][0]['progressbarclass']);
        $this->assertArrayHasKey('progresspercent', $result['students'][0]);
        $this->assertArrayHasKey('searchtext', $result['students'][0]);
        $this->assertNotSame('', $result['students'][0]['searchtext']);
        $this->assertArrayHasKey('canextend', $result['students'][0]);
        $this->assertTrue($result['students'][0]['canextend']);
        $this->assertArrayHasKey('attemptendat', $result['students'][0]);
        $this->assertGreaterThan(0, $result['students'][0]['attemptendat']);
        $this->assertTrue($result['canextend']);
        $this->assertSame(1, $result['inprogresscount']);
        $this->assertSame('border-warning', $result['summary']['inprogress']['statusclass']);
    }

    /**
     * Poll payload includes hasnote on student rows.
     */
    public function test_execute_includes_hasnote(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $quiz = $quizgenerator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        \quiz_livequizmonitor\local\manager\student_note_manager::save_note(
            (int) $quiz->id,
            (int) $student->id,
            (int) $teacher->id,
            'Has a note'
        );

        $this->setUser($teacher);
        $result = get_monitor_state::execute($cm->id, 0);

        $this->assertArrayHasKey('hasnote', $result['students'][0]);
        $this->assertTrue($result['students'][0]['hasnote']);
    }

    /**
     * Student without report capability cannot call the external function.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $quiz = $quizgenerator->create_instance(['course' => $course->id, 'grade' => 100]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        get_monitor_state::execute($cm->id, 0);
    }

    /**
     * Teacher restricted to Group A cannot poll monitor state for Group B.
     */
    public function test_execute_rejects_out_of_scope_group(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $this->setUser($fixture['teacher']);

        $this->expectException(moodle_exception::class);
        try {
            get_monitor_state::execute($fixture['cm']->id, (int) $fixture['groupb']->id);
        } catch (moodle_exception $e) {
            $this->assertSame('error:groupnotvisible', $e->errorcode);
            throw $e;
        }
    }
}
