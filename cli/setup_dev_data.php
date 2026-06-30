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
 * Bootstrap course, quiz, enrolments and sample attempts for plugin development.
 *
 * Run from Moodle root:
 *   php mod/quiz/report/livequizmonitor/cli/setup_dev_data.php
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/classes/util.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/editlib.php');

use core\session\manager;
use core_question\local\bank\question_version_status;
use mod_quiz\quiz_attempt;

const COURSE_SHORTNAME = 'QUIZMON';
const QUIZ_NAME = 'Live Quiz Monitor Demo';

/**
 * Print a line when not running quietly.
 *
 * @param string $message
 * @param bool $quiet
 */
function setup_log(string $message, bool $quiet): void {
    if (!$quiet) {
        mtrace($message);
    }
}

/**
 * Resolve an existing user by username or create a demo student.
 *
 * @param string $username
 * @return stdClass
 */
function setup_get_user(string $username): stdClass {
    global $DB;

    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', IGNORE_MISSING);
    if (!$user) {
        $generator = testing_util::get_data_generator();
        $user = $generator->create_user([
            'username' => $username,
            'firstname' => ucfirst($username),
            'lastname' => 'Student',
            'email' => $username . '@example.com',
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
function setup_enrol_students(int $courseid, array $userids, bool $quiet): void {
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
        setup_log("  enrolled user id {$userid}", $quiet);
    }
}

/**
 * Create a question category in the given context.
 *
 * @param int $contextid
 * @return stdClass
 */
function setup_create_question_category(int $contextid): stdClass {
    global $DB;

    $record = new stdClass();
    $record->name = 'Demo questions';
    $record->contextid = $contextid;
    $record->info = '';
    $record->infoformat = FORMAT_HTML;
    $record->stamp = make_unique_id_code();
    $record->parent = question_get_top_category($contextid, true)->id;
    $record->sortorder = 999;
    $record->id = $DB->insert_record('question_categories', $record);

    return $record;
}

/**
 * Save a question from form data without PHPUnit dependencies.
 *
 * @param string $qtype
 * @param int $categoryid
 * @param stdClass $form
 * @return stdClass
 */
function setup_save_question(string $qtype, int $categoryid, stdClass $form): stdClass {
    $form->category = $categoryid;
    $form->status = $form->status ?? question_version_status::QUESTION_STATUS_READY;

    $question = new stdClass();
    $question->category = $categoryid;
    $question->qtype = $qtype;
    $question->createdby = get_admin()->id;
    $question->modifiedby = get_admin()->id;

    return question_bank::get_qtype($qtype)->save_question($question, $form);
}

/**
 * Build post data to simulate a question response submission.
 *
 * @param question_attempt $qa
 * @param string $responsesummary
 * @return array
 */
function setup_simulated_post_data(question_attempt $qa, string $responsesummary): array {
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
function setup_submit_responses(int $attemptid, array $responses, bool $finish): void {
    $attemptobj = quiz_attempt::create($attemptid);
    $postdata = [];

    foreach ($responses as $slot => $summary) {
        $qa = $attemptobj->get_question_attempt($slot);
        $postdata += setup_simulated_post_data($qa, $summary);
    }

    $attemptobj->process_submitted_actions(time(), false, $postdata);
    if ($finish) {
        $attemptobj->process_finish(time(), false);
    }
}

/**
 * Create demo questions and attach them to a quiz.
 *
 * @param stdClass $quiz Quiz record.
 * @param int $contextid Module context id.
 * @return void
 */
function setup_add_demo_questions(stdClass $quiz, int $contextid): void {
    global $DB;

    $category = setup_create_question_category($contextid);
    $feedback = ['text' => 'Well done.', 'format' => FORMAT_HTML];

    $shortanswer = setup_save_question('shortanswer', $category->id, (object) [
        'name' => 'Name an amphibian',
        'questiontext' => ['text' => 'Name an amphibian: __________', 'format' => FORMAT_HTML],
        'generalfeedback' => ['text' => 'Frog or toad would have been OK.', 'format' => FORMAT_HTML],
        'defaultmark' => 1.0,
        'usecase' => false,
        'answer' => ['frog', 'toad', '*'],
        'fraction' => ['1.0', '0.8', '0.0'],
        'feedback' => [
            ['text' => 'Frog is a very good answer.', 'format' => FORMAT_HTML],
            ['text' => 'Toad is an OK answer.', 'format' => FORMAT_HTML],
            ['text' => 'That is a bad answer.', 'format' => FORMAT_HTML],
        ],
    ]);

    $truefalse = setup_save_question('truefalse', $category->id, (object) [
        'name' => '2 + 2 equals 4',
        'questiontext' => ['text' => '2 + 2 equals 4.', 'format' => FORMAT_HTML],
        'generalfeedback' => ['text' => 'The answer is true.', 'format' => FORMAT_HTML],
        'defaultmark' => 1.0,
        'correctanswer' => '1',
        'feedbacktrue' => $feedback,
        'feedbackfalse' => ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
    ]);

    $multichoice = setup_save_question('multichoice', $category->id, (object) [
        'name' => 'Pick the mammal',
        'questiontext' => ['text' => 'Pick the mammal.', 'format' => FORMAT_HTML],
        'generalfeedback' => ['text' => 'One is the mammal.', 'format' => FORMAT_HTML],
        'defaultmark' => 2.0,
        'noanswers' => 4,
        'numhints' => 0,
        'penalty' => 0.3333333,
        'shuffleanswers' => 1,
        'answernumbering' => '123',
        'showstandardinstruction' => 0,
        'single' => '1',
        'correctfeedback' => $feedback,
        'partiallycorrectfeedback' => $feedback,
        'incorrectfeedback' => ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
        'shownumcorrect' => 1,
        'fraction' => ['1.0', '0.0', '0.0', '0.0'],
        'answer' => [
            ['text' => 'One', 'format' => FORMAT_PLAIN],
            ['text' => 'Two', 'format' => FORMAT_PLAIN],
            ['text' => 'Three', 'format' => FORMAT_PLAIN],
            ['text' => 'Four', 'format' => FORMAT_PLAIN],
        ],
        'feedback' => [
            ['text' => 'Correct.', 'format' => FORMAT_HTML],
            ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
            ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
            ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
        ],
    ]);

    quiz_add_quiz_question($shortanswer->id, $quiz, 0, 1);
    quiz_add_quiz_question($truefalse->id, $quiz, 1, 1);
    quiz_add_quiz_question($multichoice->id, $quiz, 2, 2);

    $quiz->sumgrades = 4;
    $quiz->grade = 10;
    $DB->update_record('quiz', $quiz);
}

list($options, $unrecognised) = cli_get_params(
    [
        'help' => false,
        'quiet' => false,
        'force' => false,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($options['help'])) {
    echo <<<EOF
Create development course with quiz, enrolled students and sample attempts.

Options:
  --force   Delete and recreate course if it already exists
  --quiet   Suppress informational output
  -h, --help  Print this help

Example:
  php mod/quiz/report/livequizmonitor/cli/setup_dev_data.php

EOF;
    exit(0);
}

$quiet = !empty($options['quiet']);
$force = !empty($options['force']);

manager::set_user(get_admin());

$generator = testing_util::get_data_generator();
/** @var mod_quiz_generator $quizgenerator */
$quizgenerator = $generator->get_plugin_generator('mod_quiz');

global $DB;

$existing = $DB->get_record('course', ['shortname' => COURSE_SHORTNAME], 'id', IGNORE_MISSING);
if ($existing) {
    if (!$force) {
        cli_error('Course ' . COURSE_SHORTNAME . ' already exists. Re-run with --force to recreate.');
    }
    setup_log('Deleting existing course ' . COURSE_SHORTNAME . ' (id ' . $existing->id . ')', $quiet);
    delete_course($existing->id, false);
}

setup_log('Creating course ' . COURSE_SHORTNAME, $quiet);
$course = $generator->create_course([
    'shortname' => COURSE_SHORTNAME,
    'fullname' => 'Quiz Monitor Development',
    'summary' => 'Development course for the live quiz monitor report plugin.',
    'format' => 'topics',
    'numsections' => 1,
    'enablecompletion' => 0,
]);

$studentusernames = ['adam', 'bert', 'colin', 'diane', 'emily', 'fred', 'gemma', 'hannah'];
$students = [];
foreach ($studentusernames as $username) {
    $students[$username] = setup_get_user($username);
}
setup_log('Enrolling students: ' . implode(', ', $studentusernames), $quiet);
setup_enrol_students($course->id, array_map(static fn(stdClass $u): int => (int) $u->id, $students), $quiet);

setup_log('Creating quiz with questions', $quiet);
$quiz = $quizgenerator->create_instance([
    'course' => $course->id,
    'name' => QUIZ_NAME,
    'intro' => 'Sample quiz for live monitoring report development.',
    'sumgrades' => 4,
    'grade' => 10,
    'preferredbehaviour' => 'deferredfeedback',
    'attempts' => 0,
    'timelimit' => 0,
    'timeopen' => 0,
    'timeclose' => 0,
    'questionsperpage' => 1,
]);

$cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
setup_add_demo_questions($quiz, context_module::instance($cm->id)->id);

$quizid = (int) $quiz->id;
$truestring = get_string('true', 'qtype_truefalse');
$falsestring = get_string('false', 'qtype_truefalse');

// Adam: finished attempt with correct answers.
setup_log('Creating finished attempt for adam', $quiet);
manager::set_user($students['adam']);
$attempt = $quizgenerator->create_attempt($quizid, $students['adam']->id);
setup_submit_responses($attempt->id, [
    1 => 'frog',
    2 => $truestring,
    3 => 'One',
], true);
manager::set_user(get_admin());

// Bert: in-progress attempt, first question answered only.
setup_log('Creating in-progress attempt for bert', $quiet);
manager::set_user($students['bert']);
$attempt = $quizgenerator->create_attempt($quizid, $students['bert']->id);
setup_submit_responses($attempt->id, [
    1 => 'toad',
], false);
manager::set_user(get_admin());

// Colin: attempt started, no answers yet.
setup_log('Creating empty in-progress attempt for colin', $quiet);
manager::set_user($students['colin']);
$quizgenerator->create_attempt($quizid, $students['colin']->id);
manager::set_user(get_admin());

// Emily: finished attempt with mixed results.
setup_log('Creating finished attempt for emily', $quiet);
manager::set_user($students['emily']);
$attempt = $quizgenerator->create_attempt($quizid, $students['emily']->id);
setup_submit_responses($attempt->id, [
    1 => 'wrong',
    2 => $falsestring,
    3 => 'Two',
], true);
manager::set_user(get_admin());

// Fred: finished attempt.
setup_log('Creating finished attempt for fred', $quiet);
manager::set_user($students['fred']);
$attempt = $quizgenerator->create_attempt($quizid, $students['fred']->id);
setup_submit_responses($attempt->id, [
    1 => 'frog',
    2 => $truestring,
    3 => 'Three',
], true);
manager::set_user(get_admin());

$courseurl = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
$quizurl = (new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]))->out(false);
$reporturl = (new moodle_url('/mod/quiz/report.php', [
    'id' => $cm->id,
    'mode' => 'livequizmonitor',
]))->out(false);

if (!$quiet) {
    echo PHP_EOL;
    mtrace('Development data ready.');
    mtrace('Course:    ' . COURSE_SHORTNAME . ' (id ' . $course->id . ')');
    mtrace('Quiz:      ' . QUIZ_NAME . ' (id ' . $quizid . ', cmid ' . $cm->id . ')');
    mtrace('Students:  ' . count($studentusernames) . ' enrolled; attempts for adam, bert, colin, emily, fred');
    mtrace('URLs:');
    mtrace('  ' . $courseurl);
    mtrace('  ' . $quizurl);
    mtrace('  ' . $reporturl);
    echo PHP_EOL;
}
