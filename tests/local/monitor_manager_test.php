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
 * Unit tests for monitor_manager.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use advanced_testcase;
use mod_quiz\quiz_attempt;

/**
 * Tests for monitor_manager status mapping, sorting, and summary.
 *
 * @covers \quiz_livequizmonitor\local\manager\monitor_manager
 */
final class monitor_manager_test extends advanced_testcase {

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

        $cat = $questiongenerator->create_question_category(['contextid' => \context_module::instance($cm->id)->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        return [$quiz, $cm, $quizgenerator];
    }

    /**
     * Create a quiz attempt and optionally override its state.
     *
     * @param \mod_quiz_generator $quizgenerator Quiz generator.
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param string|null $state Optional attempt state override.
     * @return \stdClass Attempt record.
     */
    private function create_quiz_attempt($quizgenerator, int $quizid, int $userid, ?string $state = null): \stdClass {
        global $DB;

        $this->setUser($userid);
        $attempt = $quizgenerator->create_attempt($quizid, $userid);
        if ($state !== null && $attempt->state !== $state) {
            $attempt->state = $state;
            if ($state === quiz_attempt::FINISHED) {
                $attempt->timefinish = time();
            }
            $DB->update_record('quiz_attempts', $attempt);
        }
        return $attempt;
    }

    /**
     * Status mapping covers not started, in progress, and completed.
     */
    public function test_get_state_status_mapping(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $notstarted = $generator->create_user(['firstname' => 'Diane', 'lastname' => 'Delta']);
        $inprogress = $generator->create_user(['firstname' => 'Bert', 'lastname' => 'Beta']);
        $completed = $generator->create_user(['firstname' => 'Adam', 'lastname' => 'Alpha']);

        $generator->enrol_user($notstarted->id, $course->id, 'student');
        $generator->enrol_user($inprogress->id, $course->id, 'student');
        $generator->enrol_user($completed->id, $course->id, 'student');

        $this->create_quiz_attempt($quizgenerator, $quiz->id, $inprogress->id, quiz_attempt::IN_PROGRESS);
        $this->create_quiz_attempt($quizgenerator, $quiz->id, $completed->id, quiz_attempt::FINISHED);

        $state = monitor_manager::get_state($course, $cm, $quiz, 0);
        $bystatus = [];
        foreach ($state->students as $row) {
            $bystatus[$row->status][] = $row->fullname;
        }

        $this->assertCount(3, $state->students);
        $this->assertContains('Adam Alpha', $bystatus[monitor_manager::STATUS_COMPLETED]);
        $this->assertContains('Bert Beta', $bystatus[monitor_manager::STATUS_INPROGRESS]);
        $this->assertContains('Diane Delta', $bystatus[monitor_manager::STATUS_NOTSTARTED]);
    }

    /**
     * Unfinished attempt takes precedence over a finished attempt.
     */
    public function test_multiple_attempt_precedence(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        $this->create_quiz_attempt($quizgenerator, $quiz->id, $user->id, quiz_attempt::FINISHED);
        $this->create_quiz_attempt($quizgenerator, $quiz->id, $user->id, quiz_attempt::IN_PROGRESS);

        $state = monitor_manager::get_state($course, $cm, $quiz, 0);
        $this->assertCount(1, $state->students);
        $this->assertSame(monitor_manager::STATUS_INPROGRESS, $state->students[0]->status);
    }

    /**
     * Summary counts sum to total students.
     */
    public function test_summary_counts(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
        }

        $state = monitor_manager::get_state($course, $cm, $quiz, 0);
        $summary = $state->summary;
        $total = $summary->notstarted->count + $summary->inprogress->count + $summary->completed->count;

        $this->assertSame(3, $state->totalstudents);
        $this->assertSame(3, $total);
        $this->assertSame(100, $summary->notstarted->percent);
    }

    /**
     * Sort order is in progress, not started, completed, then alphabetical.
     */
    public function test_sort_order(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $users = [
            monitor_manager::STATUS_COMPLETED => $generator->create_user(['firstname' => 'Zed', 'lastname' => 'Zulu']),
            monitor_manager::STATUS_NOTSTARTED => $generator->create_user(['firstname' => 'Amy', 'lastname' => 'Alpha']),
            monitor_manager::STATUS_INPROGRESS => $generator->create_user(['firstname' => 'Bob', 'lastname' => 'Beta']),
        ];

        foreach ($users as $user) {
            $generator->enrol_user($user->id, $course->id, 'student');
        }

        $this->create_quiz_attempt($quizgenerator, $quiz->id, $users[monitor_manager::STATUS_INPROGRESS]->id, quiz_attempt::IN_PROGRESS);
        $this->create_quiz_attempt($quizgenerator, $quiz->id, $users[monitor_manager::STATUS_COMPLETED]->id, quiz_attempt::FINISHED);

        $state = monitor_manager::get_state($course, $cm, $quiz, 0);
        $statuses = array_map(static fn($row) => $row->status, $state->students);

        $this->assertSame([
            monitor_manager::STATUS_INPROGRESS,
            monitor_manager::STATUS_NOTSTARTED,
            monitor_manager::STATUS_COMPLETED,
        ], $statuses);
    }
}
