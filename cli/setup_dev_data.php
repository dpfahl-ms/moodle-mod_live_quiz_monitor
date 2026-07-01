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
require_once(__DIR__ . '/setup_lib.php');

use core\session\manager;

if (defined('CLI_SCRIPT') && !empty($_SERVER['SCRIPT_FILENAME'])
        && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {

list($options, $unrecognised) = cli_get_params(
    [
        'help' => false,
        'quiet' => false,
        'force' => false,
        'clear-overrides' => false,
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
  --force            Delete and recreate course if it already exists
  --clear-overrides  Remove all user quiz overrides in QUIZMON and exit
  --students=N       Number of demo students to enrol (default: 20)
  --quiet            Suppress informational output
  -h, --help         Print this help

Example:
  php mod/quiz/report/livequizmonitor/cli/setup_dev_data.php --force
  php mod/quiz/report/livequizmonitor/cli/setup_dev_data.php --clear-overrides

EOF;
    exit(0);
}

$quiet = !empty($options['quiet']);
$force = !empty($options['force']);
$clearoverrides = !empty($options['clear-overrides']);
$studentcount = max(1, (int) $options['students']);

manager::set_user(get_admin());

global $DB;

if ($clearoverrides) {
    $course = $DB->get_record('course', ['shortname' => DEMO_COURSE_SHORTNAME], '*', IGNORE_MISSING);
    if (!$course) {
        cli_error('Course ' . DEMO_COURSE_SHORTNAME . ' not found.');
    }
    demo_log('Clearing user quiz overrides in ' . DEMO_COURSE_SHORTNAME . ' (id ' . $course->id . ')', $quiet);
    $removed = demo_clear_course_user_quiz_overrides((int) $course->id, $quiet);
    if (!$quiet) {
        mtrace('Removed ' . $removed . ' user quiz override(s).');
    }
    exit(0);
}

$generator = testing_util::get_data_generator();
/** @var mod_quiz_generator $quizgenerator */
$quizgenerator = $generator->get_plugin_generator('mod_quiz');

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

demo_log('Clearing user quiz overrides', $quiet);
demo_clear_course_user_quiz_overrides((int) $course->id, $quiet);

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

}
