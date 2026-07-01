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
require_once(__DIR__ . '/demo_lib.php');

use core\session\manager;
use core_question\local\bank\question_version_status;

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
 * Create demo questions and attach them to a quiz.
 *
 * @param stdClass $quiz Quiz record.
 * @param int $contextid Module context id.
 * @return int Number of questions added.
 */
function setup_add_demo_questions(stdClass $quiz, int $contextid): int {
    global $DB;

    $category = setup_create_question_category($contextid);
    $feedback = ['text' => 'Well done.', 'format' => FORMAT_HTML];
    $incorrect = ['text' => 'Incorrect.', 'format' => FORMAT_HTML];

    $definitions = [
        ['type' => 'shortanswer', 'name' => 'Name an amphibian', 'text' => 'Name an amphibian: __________'],
        ['type' => 'truefalse', 'name' => '2 + 2 equals 4', 'text' => '2 + 2 equals 4.'],
        ['type' => 'multichoice', 'name' => 'Pick the mammal', 'text' => 'Pick the mammal.'],
        ['type' => 'shortanswer', 'name' => 'Name a reptile', 'text' => 'Name a reptile: __________'],
        ['type' => 'truefalse', 'name' => 'The sky is blue', 'text' => 'The sky is blue.'],
        ['type' => 'multichoice', 'name' => 'Pick a prime number', 'text' => 'Which is a prime number?'],
        ['type' => 'shortanswer', 'name' => 'Capital of France', 'text' => 'Capital of France: __________'],
        ['type' => 'truefalse', 'name' => 'Water freezes at 0°C', 'text' => 'Water freezes at 0 degrees Celsius.'],
        ['type' => 'multichoice', 'name' => 'Pick a colour', 'text' => 'Which is a primary colour?'],
        ['type' => 'shortanswer', 'name' => 'Name a bird', 'text' => 'Name a bird: __________'],
    ];

    $slot = 0;
    $sumgrades = 0.0;

    foreach ($definitions as $definition) {
        if ($definition['type'] === 'shortanswer') {
            $question = setup_save_question('shortanswer', $category->id, (object) [
                'name' => $definition['name'],
                'questiontext' => ['text' => $definition['text'], 'format' => FORMAT_HTML],
                'generalfeedback' => ['text' => 'Any reasonable answer counts for the demo.', 'format' => FORMAT_HTML],
                'defaultmark' => 1.0,
                'usecase' => false,
                'answer' => ['frog', 'toad', 'paris', 'eagle', '*'],
                'fraction' => ['1.0', '0.8', '1.0', '1.0', '0.0'],
                'feedback' => [
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
                ],
            ]);
        } else if ($definition['type'] === 'truefalse') {
            $question = setup_save_question('truefalse', $category->id, (object) [
                'name' => $definition['name'],
                'questiontext' => ['text' => $definition['text'], 'format' => FORMAT_HTML],
                'generalfeedback' => ['text' => 'True or false.', 'format' => FORMAT_HTML],
                'defaultmark' => 1.0,
                'correctanswer' => '1',
                'feedbacktrue' => $feedback,
                'feedbackfalse' => $incorrect,
            ]);
        } else {
            $question = setup_save_question('multichoice', $category->id, (object) [
                'name' => $definition['name'],
                'questiontext' => ['text' => $definition['text'], 'format' => FORMAT_HTML],
                'generalfeedback' => ['text' => 'Pick one option.', 'format' => FORMAT_HTML],
                'defaultmark' => 1.0,
                'noanswers' => 4,
                'numhints' => 0,
                'penalty' => 0.3333333,
                'shuffleanswers' => 1,
                'answernumbering' => '123',
                'showstandardinstruction' => 0,
                'single' => '1',
                'correctfeedback' => $feedback,
                'partiallycorrectfeedback' => $feedback,
                'incorrectfeedback' => $incorrect,
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
        }

        quiz_add_quiz_question($question->id, $quiz, $slot, 1.0);
        $sumgrades += 1.0;
        $slot++;
    }

    $quiz->sumgrades = $sumgrades;
    $quiz->grade = 10;
    $DB->update_record('quiz', $quiz);

    return $slot;
}

list($options, $unrecognised) = cli_get_params(
    [
        'help' => false,
        'quiet' => false,
        'force' => false,
        'students' => DEMO_STUDENT_COUNT,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($options['help'])) {
    echo <<<EOF
Create development course with quiz, enrolled students and sample attempts.

Options:
  --force      Delete and recreate course if it already exists
  --students=N Number of demo students to enrol (default: 20)
  --quiet      Suppress informational output
  -h, --help   Print this help

Example:
  php mod/quiz/report/livequizmonitor/cli/setup_dev_data.php --force
  php mod/quiz/report/livequizmonitor/cli/simulate_quiz_demo.php

EOF;
    exit(0);
}

$quiet = !empty($options['quiet']);
$force = !empty($options['force']);
$studentcount = max(1, (int) $options['students']);

manager::set_user(get_admin());

$generator = testing_util::get_data_generator();
/** @var mod_quiz_generator $quizgenerator */
$quizgenerator = $generator->get_plugin_generator('mod_quiz');

global $DB;

$existing = $DB->get_record('course', ['shortname' => DEMO_COURSE_SHORTNAME], 'id', IGNORE_MISSING);
if ($existing) {
    if (!$force) {
        cli_error('Course ' . DEMO_COURSE_SHORTNAME . ' already exists. Re-run with --force to recreate.');
    }
    demo_log('Deleting existing course ' . DEMO_COURSE_SHORTNAME . ' (id ' . $existing->id . ')', $quiet);
    delete_course($existing->id, false);
}

demo_log('Creating course ' . DEMO_COURSE_SHORTNAME, $quiet);
$course = $generator->create_course([
    'shortname' => DEMO_COURSE_SHORTNAME,
    'fullname' => 'Quiz Monitor Development',
    'summary' => 'Development course for the live quiz monitor report plugin.',
    'format' => 'topics',
    'numsections' => 1,
    'enablecompletion' => 0,
]);

$studentusernames = demo_student_usernames($studentcount);
$students = [];
foreach ($studentusernames as $username) {
    $students[$username] = demo_get_user($username);
}
demo_log('Enrolling ' . count($studentusernames) . ' students', $quiet);
demo_enrol_students($course->id, array_map(static fn(stdClass $u): int => (int) $u->id, $students), $quiet);

demo_log('Creating quiz with questions', $quiet);
$quiz = $quizgenerator->create_instance([
    'course' => $course->id,
    'name' => DEMO_QUIZ_NAME,
    'intro' => 'Sample quiz for live monitoring report development.',
    'sumgrades' => DEMO_QUESTION_COUNT,
    'grade' => 10,
    'preferredbehaviour' => 'deferredfeedback',
    'attempts' => 0,
    'timelimit' => DEMO_QUIZ_TIMELIMIT,
    'timeopen' => 0,
    'timeclose' => 0,
    'questionsperpage' => 1,
]);

$cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
$questioncount = setup_add_demo_questions($quiz, context_module::instance($cm->id)->id);

$quizid = (int) $quiz->id;

$courseurl = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
$quizurl = (new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]))->out(false);
$reporturl = (new moodle_url('/mod/quiz/report.php', [
    'id' => $cm->id,
    'mode' => 'livequizmonitor',
]))->out(false);

if (!$quiet) {
    echo PHP_EOL;
    mtrace('Development data ready.');
    mtrace('Course:    ' . DEMO_COURSE_SHORTNAME . ' (id ' . $course->id . ')');
    mtrace('Quiz:      ' . DEMO_QUIZ_NAME . ' (id ' . $quizid . ', cmid ' . $cm->id . ', ' . $questioncount . ' questions, 5 min time limit)');
    mtrace('Students:  ' . count($studentusernames) . ' enrolled (student01–student' . sprintf('%02d', $studentcount) . '), all not started');
    mtrace('Password:  ' . DEMO_STUDENT_PASSWORD . ' for all demo students');
    mtrace('URLs:');
    mtrace('  ' . $courseurl);
    mtrace('  ' . $quizurl);
    mtrace('  ' . $reporturl);
    mtrace('');
    mtrace('Run the live demo simulator:');
    mtrace('  php mod/quiz/report/livequizmonitor/cli/simulate_quiz_demo.php');
    echo PHP_EOL;
}
