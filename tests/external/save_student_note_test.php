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
 * External API tests for save_student_note.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

use advanced_testcase;
use moodle_exception;
use quiz_livequizmonitor\local\manager\student_note_manager;
use required_capability_exception;

/**
 * Tests for save_student_note external function.
 *
 * @covers \quiz_livequizmonitor\external\save_student_note
 * @runTestsInSeparateProcesses
 */
final class save_student_note_test extends advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass}
     */
    private function create_monitor_fixture(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        return [$teacher, $student, $cm, $quiz];
    }

    /**
     * Save persists note content.
     */
    public function test_execute_saves_note(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm] = $this->create_monitor_fixture();

        $this->setUser($teacher);
        $result = save_student_note::execute($cm->id, 0, $student->id, 'Proctor note');

        $this->assertTrue($result['hasnote']);
        $this->assertSame('Proctor note', $result['content']);
    }

    /**
     * Empty save removes note.
     */
    public function test_execute_deletes_on_empty_save(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm, $quiz] = $this->create_monitor_fixture();
        student_note_manager::save_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'To delete');

        $this->setUser($teacher);
        $result = save_student_note::execute($cm->id, 0, $student->id, '');

        $this->assertFalse($result['hasnote']);
        $this->assertSame('', $result['content']);
    }

    /**
     * Over-length content is rejected.
     */
    public function test_execute_rejects_overlength(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm] = $this->create_monitor_fixture();

        $this->setUser($teacher);
        $this->expectException(moodle_exception::class);
        save_student_note::execute(
            $cm->id,
            0,
            $student->id,
            str_repeat('a', student_note_manager::MAX_LENGTH + 1)
        );
    }

    /**
     * Users without monitor capability are denied.
     */
    public function test_execute_denies_without_capability(): void {
        $this->resetAfterTest();

        [, $student, $cm] = $this->create_monitor_fixture();

        $this->setUser($student);
        $this->expectException(required_capability_exception::class);
        save_student_note::execute($cm->id, 0, $student->id, 'Nope');
    }
}
