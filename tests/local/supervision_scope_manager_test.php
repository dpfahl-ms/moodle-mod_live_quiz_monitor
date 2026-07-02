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
 * Unit tests for supervision_scope_manager.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../traits/group_scope_test_trait.php');

use advanced_testcase;
use context_module;
use moodle_exception;
use quiz_livequizmonitor\tests\traits\group_scope_test_trait;

/**
 * Tests for supervision_scope_manager.
 *
 * @covers \quiz_livequizmonitor\local\manager\supervision_scope_manager
 */
final class supervision_scope_manager_test extends advanced_testcase {
    use group_scope_test_trait;

    /**
     * Teacher may request monitor data for their own group.
     */
    public function test_validate_group_access_allows_visible_group(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $this->setUser($fixture['teacher']);

        supervision_scope_manager::validate_group_access((int) $fixture['groupa']->id, $fixture['cm']);
        $this->assertTrue(true);
    }

    /**
     * Teacher cannot request monitor data for a group they do not belong to.
     */
    public function test_validate_group_access_rejects_foreign_group(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $this->setUser($fixture['teacher']);

        $this->expectException(moodle_exception::class);
        try {
            supervision_scope_manager::validate_group_access((int) $fixture['groupb']->id, $fixture['cm']);
        } catch (moodle_exception $e) {
            $this->assertSame('error:groupnotvisible', $e->errorcode);
            throw $e;
        }
    }

    /**
     * Enrolled student in the active group is in cohort.
     */
    public function test_is_user_in_cohort_accepts_group_member(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $this->setUser($fixture['teacher']);
        $this->set_activity_group($fixture['cm'], (int) $fixture['groupa']->id);

        $this->assertTrue(supervision_scope_manager::is_user_in_cohort(
            (int) $fixture['studenta']->id,
            $fixture['context'],
            (int) $fixture['groupa']->id,
            $fixture['cm']
        ));
    }

    /**
     * Student in another group is not in the teacher's cohort.
     */
    public function test_is_user_in_cohort_rejects_other_group_student(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $this->setUser($fixture['teacher']);
        $this->set_activity_group($fixture['cm'], (int) $fixture['groupa']->id);

        $this->assertFalse(supervision_scope_manager::is_user_in_cohort(
            (int) $fixture['studentb']->id,
            $fixture['context'],
            (int) $fixture['groupa']->id,
            $fixture['cm']
        ));
    }

    /**
     * User not enrolled on the quiz is rejected.
     */
    public function test_is_user_in_cohort_rejects_outsider(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $outsider = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        $this->setUser($teacher);

        $this->assertTrue(supervision_scope_manager::is_user_in_cohort((int) $student->id, $context, 0, $cm));
        $this->assertFalse(supervision_scope_manager::is_user_in_cohort((int) $outsider->id, $context, 0, $cm));
    }
}
