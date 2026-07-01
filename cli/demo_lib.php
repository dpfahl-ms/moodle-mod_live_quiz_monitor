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
 * Shared helpers for development bootstrap and live demo simulation.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use mod_quiz\quiz_attempt;

const DEMO_COURSE_SHORTNAME = 'QUIZMON';
const DEMO_QUIZ_NAME = 'Live Quiz Monitor Demo';
const DEMO_STUDENT_COUNT = 20;
const DEMO_STUDENT_PASSWORD = 'Student123!';
const DEMO_QUESTION_COUNT = 10;
const DEMO_QUIZ_TIMELIMIT = 300; // 5 minutes in seconds.
const DEMO_INTERVAL_MIN = 1;
const DEMO_INTERVAL_MAX = 1;
const DEMO_DURATION = 120;

/**
 * Build demo student usernames (student01 … studentNN).
 *
 * @param int $count
 * @return string[]
 */
function demo_student_usernames(int $count = DEMO_STUDENT_COUNT): array {
    $names = [];
    for ($i = 1; $i <= $count; $i++) {
        $names[] = sprintf('student%02d', $i);
    }
    return $names;
}

/**
 * Print a line when not running quietly.
 *
 * @param string $message
 * @param bool $quiet
 */
function demo_log(string $message, bool $quiet): void {
    if (!$quiet) {
        mtrace($message);
    }
}

/**
 * Resolve an existing user by username or create a demo student.
 *
 * @param string $username
 * @param string $password
 * @return stdClass
 */
function demo_get_user(string $username, string $password = DEMO_STUDENT_PASSWORD): stdClass {
    global $DB, $CFG;

    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', IGNORE_MISSING);
    if (!$user) {
        require_once($CFG->libdir . '/testing/classes/util.php');
        $generator = testing_util::get_data_generator();
        $user = $generator->create_user([
            'username' => $username,
            'firstname' => ucfirst($username),
            'lastname' => 'Student',
            'email' => $username . '@example.com',
            'password' => $password,
        ]);
    }
    return $user;
}

/**
 * Enrol users as students when manual enrolment is available.
 *
 * @param int $courseid
 * @param int[] $userids
 * @param bool $quiet
 */
function demo_enrol_students(int $courseid, array $userids, bool $quiet): void {
    global $DB;

    $instances = enrol_get_instances($courseid, true);
    $manual = null;
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            $manual = $instance;
            break;
        }
    }

    if (!$manual) {
        $manualid = enrol_get_plugin('manual')->add_instance($courseid);
        $manual = $DB->get_record('enrol', ['id' => $manualid], '*', MUST_EXIST);
    }

    $plugin = enrol_get_plugin('manual');
    foreach ($userids as $userid) {
        if ($DB->record_exists('user_enrolments', ['enrolid' => $manual->id, 'userid' => $userid])) {
            continue;
        }
        $plugin->enrol_user($manual, $userid, 5);
        demo_log("  enrolled user id {$userid}", $quiet);
    }
}

/**
 * Build post data to simulate a question response submission.
 *
 * @param question_attempt $qa
 * @param string $responsesummary
 * @return array
 */
function demo_simulated_post_data(question_attempt $qa, string $responsesummary): array {
    $question = $qa->get_question();
    if (!$question instanceof question_with_responses) {
        return [];
    }

    $postdata = [];
    $postdata[$qa->get_control_field_name('sequencecheck')] = (string) $qa->get_sequence_check_count();
    $postdata[$qa->get_flag_field_name()] = (string) (int) $qa->is_flagged();

    $response = $question->un_summarise_response($responsesummary);
    foreach ($response as $name => $value) {
        $postdata[$qa->get_qt_field_name($name)] = (string) $value;
    }

    return $postdata;
}

/**
 * Submit quiz responses using the public quiz_attempt API.
 *
 * @param int $attemptid
 * @param array $responses slot => response summary
 * @param bool $finish whether to finish the attempt
 */
function demo_submit_responses(int $attemptid, array $responses, bool $finish): void {
    $attemptobj = quiz_attempt::create($attemptid);
    $postdata = [];

    foreach ($responses as $slot => $summary) {
        $qa = $attemptobj->get_question_attempt($slot);
        $postdata += demo_simulated_post_data($qa, $summary);
    }

    $attemptobj->process_submitted_actions(time(), false, $postdata);
    if ($finish) {
        $attemptobj->process_finish(time(), false);
    }
}

/**
 * Resolve the demo course and quiz used by bootstrap scripts.
 *
 * @return array{course: stdClass, quiz: stdClass, cm: stdClass}
 */
function demo_resolve_course_quiz(): array {
    global $DB;

    $course = $DB->get_record('course', ['shortname' => DEMO_COURSE_SHORTNAME], '*', MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['course' => $course->id, 'name' => DEMO_QUIZ_NAME], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

    return ['course' => $course, 'quiz' => $quiz, 'cm' => $cm];
}

/**
 * Whether a question attempt has a saved answer (Moodle 4.5 compatible).
 *
 * Mirrors monitor_manager::count_answered_questions() state checks.
 *
 * @param question_attempt $qa
 * @return bool
 */
function demo_question_is_answered(question_attempt $qa): bool {
    switch ($qa->get_state_class(false)) {
        case 'answersaved':
        case 'complete':
        case 'gradedright':
        case 'gradedwrong':
        case 'gradedpartial':
        case 'mangrright':
        case 'mangrpartial':
        case 'mangrwrong':
            return true;
        default:
            return false;
    }
}

/**
 * Pick a random response summary for a question attempt.
 *
 * @param question_attempt $qa
 * @return string|null Null when the question type cannot be simulated.
 */
function demo_random_response_summary(question_attempt $qa): ?string {
    $question = $qa->get_question();

    if ($question instanceof qtype_shortanswer_question) {
        $options = ['frog', 'toad', 'wrong', 'fish', 'cat'];
        return $options[array_rand($options)];
    }

    if ($question instanceof qtype_truefalse_question) {
        return random_int(0, 1) ? get_string('true', 'qtype_truefalse') : get_string('false', 'qtype_truefalse');
    }

    if ($question instanceof qtype_multichoice_single_question || $question instanceof qtype_multichoice_multi_question) {
        $options = ['One', 'Two', 'Three', 'Four'];
        return $options[array_rand($options)];
    }

    return null;
}

/**
 * Build response summaries for demo seed data by slot number.
 *
 * Slots rotate shortanswer, truefalse, multichoice (1-based).
 *
 * @param int[] $slots Slot numbers to fill.
 * @return array<int, string>
 */
function demo_seed_responses_for_slots(array $slots): array {
    $responses = [];
    $shortoptions = ['frog', 'toad', 'wrong'];
    $mcoptions = ['One', 'Two', 'Three', 'Four'];
    $truestring = get_string('true', 'qtype_truefalse');
    $falsestring = get_string('false', 'qtype_truefalse');

    foreach ($slots as $slot) {
        switch (($slot - 1) % 3) {
            case 0:
                $responses[$slot] = $shortoptions[array_rand($shortoptions)];
                break;
            case 1:
                $responses[$slot] = random_int(0, 1) ? $truestring : $falsestring;
                break;
            default:
                $responses[$slot] = $mcoptions[array_rand($mcoptions)];
                break;
        }
    }

    return $responses;
}

/**
 * Pick a random wait duration in seconds for the demo simulator.
 *
 * @param int $min Minimum seconds (inclusive).
 * @param int $max Maximum seconds (inclusive).
 * @return int
 */
function demo_random_interval(int $min = DEMO_INTERVAL_MIN, int $max = DEMO_INTERVAL_MAX): int {
    return random_int(max(1, $min), max($min, $max));
}
