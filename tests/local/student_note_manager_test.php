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
 * Unit tests for student_note_manager.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use advanced_testcase;
use moodle_exception;

/**
 * Tests for student_note_manager.
 *
 * @covers \quiz_livequizmonitor\local\manager\student_note_manager
 */
final class student_note_manager_test extends advanced_testcase {
    /**
     * Create a quiz with one enrolled student.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \context_module}
     */
    private function create_quiz_with_student(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return [$quiz, $student, $cm, $context];
    }

    /**
     * Save creates a note and get_hasnote_map reflects it.
     */
    public function test_save_note_creates_record(): void {
        global $DB;
        $this->resetAfterTest();

        [$quiz, $student] = $this->create_quiz_with_student();

        $outcome = student_note_manager::save_note((int) $quiz->id, (int) $student->id, 2, 'Bathroom break');

        $this->assertTrue($outcome->hasnote);
        $this->assertSame('Bathroom break', $outcome->content);
        $this->assertTrue($DB->record_exists('quiz_livequizmonitor_notes', [
            'quizid' => $quiz->id,
            'userid' => $student->id,
        ]));

        $map = student_note_manager::get_hasnote_map((int) $quiz->id, [(int) $student->id]);
        $this->assertTrue($map[$student->id]);
    }

    /**
     * Empty save deletes an existing note.
     */
    public function test_save_empty_deletes_note(): void {
        global $DB;
        $this->resetAfterTest();

        [$quiz, $student] = $this->create_quiz_with_student();

        student_note_manager::save_note((int) $quiz->id, (int) $student->id, 2, 'Temporary note');
        $outcome = student_note_manager::save_note((int) $quiz->id, (int) $student->id, 2, '   ');

        $this->assertFalse($outcome->hasnote);
        $this->assertFalse($DB->record_exists('quiz_livequizmonitor_notes', [
            'quizid' => $quiz->id,
            'userid' => $student->id,
        ]));
    }

    /**
     * Over-length content is rejected.
     */
    public function test_save_rejects_overlength_content(): void {
        $this->resetAfterTest();

        [$quiz, $student] = $this->create_quiz_with_student();

        $this->expectException(moodle_exception::class);
        student_note_manager::save_note(
            (int) $quiz->id,
            (int) $student->id,
            2,
            str_repeat('x', student_note_manager::MAX_LENGTH + 1)
        );
    }

    /**
     * Cohort check rejects users not enrolled on the quiz.
     */
    public function test_is_user_in_cohort_rejects_outsider(): void {
        $this->resetAfterTest();

        [$quiz, $student, $cm, $context] = $this->create_quiz_with_student();
        $outsider = $this->getDataGenerator()->create_user();

        $this->assertTrue(student_note_manager::is_user_in_cohort((int) $student->id, $context, 0, $cm));
        $this->assertFalse(student_note_manager::is_user_in_cohort((int) $outsider->id, $context, 0, $cm));
    }
}
