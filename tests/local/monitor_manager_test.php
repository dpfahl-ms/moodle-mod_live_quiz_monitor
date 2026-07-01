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
            if (in_array($state, monitor_manager::completed_attempt_states(), true)) {
                $attempt->timefinish = time();
            }
            $DB->update_record('quiz_attempts', $attempt);
        }
        return $attempt;
    }

    /**
     * Submit a short-answer response on slot 1 and optionally finish the attempt.
     *
     * @param int $attemptid Attempt id.
     * @param string $answer Response text.
     * @param bool $finish Whether to finish the attempt after saving.
     */
    private function submit_shortanswer(int $attemptid, string $answer, bool $finish = false): void {
        $attemptobj = quiz_attempt::create($attemptid);
        $qa = $attemptobj->get_question_attempt(1);
        $fieldname = $qa->get_qt_field_name('answer');
        $attemptobj->process_submitted_actions(time(), false, [$fieldname => $answer]);
        if ($finish) {
            $attemptobj->process_finish(time(), false);
        }
    }

    /**
     * Completed status uses proportional fill, not a forced 100%.
     */
    public function test_compute_progress_percent_completed_proportional(): void {
        $this->assertSame(0, monitor_manager::compute_progress_percent(
            monitor_manager::STATUS_COMPLETED,
            0,
            10
        ));
        $this->assertSame(60, monitor_manager::compute_progress_percent(
            monitor_manager::STATUS_COMPLETED,
            6,
            10
        ));
        $this->assertSame(100, monitor_manager::compute_progress_percent(
            monitor_manager::STATUS_COMPLETED,
            10,
            10
        ));
    }

    /**
     * Finished attempt with no saved answers shows 0% progress.
     */
    public function test_get_state_completed_zero_answers_progress(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $user = $generator->create_user(['firstname' => 'Zero', 'lastname' => 'Submit']);
        $generator->enrol_user($user->id, $course->id, 'student');

        $this->create_quiz_attempt($quizgenerator, $quiz->id, $user->id, quiz_attempt::FINISHED);

        $state = monitor_manager::get_state($course, $cm, $quiz, 0);
        $this->assertCount(1, $state->students);
        $row = $state->students[0];

        $this->assertSame(monitor_manager::STATUS_COMPLETED, $row->status);
        $this->assertSame(0, $row->progressanswered);
        $this->assertSame(0, $row->progresspercent);
        $this->assertStringContainsString('0 of 1 answered', $row->progresstext);
    }

    /**
     * Finished attempt with all questions answered shows 100% progress.
     */
    public function test_get_state_completed_full_answers_progress(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $user = $generator->create_user(['firstname' => 'Full', 'lastname' => 'Submit']);
        $generator->enrol_user($user->id, $course->id, 'student');

        $this->setUser($user);
        $attempt = $quizgenerator->create_attempt($quiz->id, $user->id);
        $this->submit_shortanswer($attempt->id, 'frog', true);

        $state = monitor_manager::get_state($course, $cm, $quiz, 0);
        $this->assertCount(1, $state->students);
        $row = $state->students[0];

        $this->assertSame(monitor_manager::STATUS_COMPLETED, $row->status);
        $this->assertSame(1, $row->progressanswered);
        $this->assertSame(100, $row->progresspercent);
        $this->assertStringContainsString('1 of 1 answered', $row->progresstext);
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

        $completedrow = null;
        $inprogressrow = null;

        foreach ($state->students as $row) {
            $presentation = monitor_manager::get_status_presentation($row->status);
            $this->assertSame($presentation['badgeclass'], $row->statusclass);
            $this->assertSame($presentation['progressbarclass'], $row->progressbarclass);
            $expectedpercent = monitor_manager::compute_progress_percent(
                $row->status,
                $row->progressanswered,
                $row->progresstotal
            );
            $this->assertSame($expectedpercent, $row->progresspercent);

            if ($row->status === monitor_manager::STATUS_COMPLETED) {
                $completedrow = $row;
            } else if ($row->status === monitor_manager::STATUS_INPROGRESS) {
                $inprogressrow = $row;
            }
        }

        $this->assertNotNull($completedrow);
        $this->assertNotNull($inprogressrow);
        $this->assertSame('bg-success', $completedrow->progressbarclass);
        $this->assertSame('bg-warning', $inprogressrow->progressbarclass);
        $this->assertSame($completedrow->progresspercent, $inprogressrow->progresspercent);
    }

    /**
     * Submitted attempt state (modified Moodle 4.5) maps to completed.
     */
    public function test_submitted_attempt_maps_to_completed(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $user = $generator->create_user(['firstname' => 'Sam', 'lastname' => 'Submitted']);
        $generator->enrol_user($user->id, $course->id, 'student');

        $this->create_quiz_attempt($quizgenerator, $quiz->id, $user->id, monitor_manager::QUIZ_ATTEMPT_SUBMITTED);

        $state = monitor_manager::get_state($course, $cm, $quiz, 0);
        $this->assertCount(1, $state->students);
        $this->assertSame(monitor_manager::STATUS_COMPLETED, $state->students[0]->status);
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
        $this->assertSame('border-secondary', $summary->notstarted->statusclass);
        $this->assertSame('border-warning', $summary->inprogress->statusclass);
        $this->assertSame('border-success', $summary->completed->statusclass);
    }

    /**
     * Progress percent rules per status.
     */
    public function test_progress_percent_by_status(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $notstarted = $generator->create_user();
        $inprogress = $generator->create_user();
        $completed = $generator->create_user();
        $generator->enrol_user($notstarted->id, $course->id, 'student');
        $generator->enrol_user($inprogress->id, $course->id, 'student');
        $generator->enrol_user($completed->id, $course->id, 'student');

        $this->create_quiz_attempt($quizgenerator, $quiz->id, $inprogress->id, quiz_attempt::IN_PROGRESS);
        $this->create_quiz_attempt($quizgenerator, $quiz->id, $completed->id, quiz_attempt::FINISHED);

        $state = monitor_manager::get_state($course, $cm, $quiz, 0);
        $byuserid = [];
        foreach ($state->students as $row) {
            $byuserid[$row->status] = $row;
        }

        $this->assertSame(0, $byuserid[monitor_manager::STATUS_NOTSTARTED]->progresspercent);
        $this->assertSame('bg-secondary', $byuserid[monitor_manager::STATUS_NOTSTARTED]->progressbarclass);
        $this->assertSame(0, $byuserid[monitor_manager::STATUS_COMPLETED]->progresspercent);
        $this->assertSame('bg-success', $byuserid[monitor_manager::STATUS_COMPLETED]->progressbarclass);
        $this->assertGreaterThanOrEqual(0, $byuserid[monitor_manager::STATUS_INPROGRESS]->progresspercent);
        $this->assertLessThanOrEqual(100, $byuserid[monitor_manager::STATUS_INPROGRESS]->progresspercent);
        $this->assertSame('bg-warning', $byuserid[monitor_manager::STATUS_INPROGRESS]->progressbarclass);
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

    /**
     * Search haystack includes only fullname when hidden fields are not permitted.
     */
    public function test_build_searchtext_without_hidden_fields(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane@example.com',
            'username' => 'jdoe',
            'idnumber' => 'S123',
        ]);

        $haystack = monitor_manager::build_searchtext($user, false);

        $this->assertStringContainsString('jane doe', $haystack);
        $this->assertStringNotContainsString('jane@example.com', $haystack);
        $this->assertStringNotContainsString('jdoe', $haystack);
        $this->assertStringNotContainsString('s123', $haystack);
    }

    /**
     * Search haystack includes permitted identity fields when hidden fields are visible.
     */
    public function test_build_searchtext_with_hidden_fields(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'Jane@Example.com',
            'username' => 'jdoe',
            'idnumber' => 'S123',
        ]);

        $haystack = monitor_manager::build_searchtext($user, true);

        $this->assertStringContainsString('jane doe', $haystack);
        $this->assertStringContainsString('jane@example.com', $haystack);
        $this->assertStringContainsString('jdoe', $haystack);
        $this->assertStringContainsString('s123', $haystack);
    }

    /**
     * Student rows include searchtext in monitor state.
     */
    public function test_student_rows_include_searchtext(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm] = $this->create_quiz_with_question($course);

        $teacher = $generator->create_user();
        $student = $generator->create_user(['firstname' => 'Search', 'lastname' => 'Target']);
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $this->setUser($teacher);
        $state = monitor_manager::get_state($course, $cm, $quiz, 0);

        $this->assertCount(1, $state->students);
        $this->assertObjectHasProperty('searchtext', $state->students[0]);
        $this->assertStringContainsString('search target', $state->students[0]->searchtext);
    }

    /**
     * Onesession fields are inactive when rule is not enabled.
     */
    public function test_onesession_inactive_when_rule_disabled(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $this->setUser($student);
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);
        $state = monitor_manager::get_state($course, $cm, $quiz, 0);

        $this->assertFalse($state->onesessionactive);
        $this->assertFalse($state->canunblock);
        foreach ($state->students as $row) {
            $this->assertFalse($row->isblocked);
            $this->assertFalse($row->unblockactionenabled);
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

        $context = \context_module::instance($cm->id);
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
     * isblocked is true when attempt_blocked event exists for in-progress attempt.
     */
    public function test_isblocked_when_onesession_event_seeded(): void {
        $this->resetAfterTest();

        if (!onesession_manager::is_plugin_installed()) {
            $this->markTestSkipped('quizaccess_onesession is not installed');
        }

        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $record = $DB->get_record('quizaccess_onesession', ['quizid' => $quiz->id]);
        if ($record) {
            $record->enabled = 1;
            $DB->update_record('quizaccess_onesession', $record);
        } else {
            $DB->insert_record('quizaccess_onesession', (object) [
                'quizid' => $quiz->id,
                'enabled' => 1,
            ]);
        }

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->insert_onesession_log_event(onesession_manager::EVENT_ATTEMPT_BLOCKED, $attempt, $cm, $student->id);

        $this->setUser($teacher);
        $state = monitor_manager::get_state($course, $cm, $quiz, 0);

        $this->assertTrue($state->onesessionactive);
        $this->assertTrue($state->canunblock);

        $blockedrow = null;
        foreach ($state->students as $row) {
            if ((int) $row->userid === (int) $student->id) {
                $blockedrow = $row;
                break;
            }
        }
        $this->assertNotNull($blockedrow);
        $this->assertTrue($blockedrow->isblocked);
        $this->assertTrue($blockedrow->unblockactionenabled);
    }
}
