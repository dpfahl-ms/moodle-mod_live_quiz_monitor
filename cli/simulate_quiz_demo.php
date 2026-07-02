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
 * Simulate live quiz activity for the development demo course.
 *
 * Run from Moodle root (teacher opens Live Monitor in a browser first):
 *   php mod/quiz/report/livequizmonitor/cli/simulate_quiz_demo.php
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
require_once(__DIR__ . '/demo_lib.php');

use core\session\manager;
use mod_quiz\quiz_attempt;

/**
 * Return slot numbers that have no saved response yet.
 *
 * @param quiz_attempt $attemptobj
 * @return int[]
 */
function simulate_unanswered_slots(quiz_attempt $attemptobj): array {
    $slots = [];
    foreach ($attemptobj->get_slots() as $slot) {
        $qa = $attemptobj->get_question_attempt($slot);
        if (!demo_question_is_answered($qa)) {
            $slots[] = $slot;
        }
    }
    return $slots;
}

/**
 * Start a new quiz attempt for a user when none is in progress.
 *
 * @param stdClass $quiz
 * @param stdClass $user
 * @return quiz_attempt
 */
function simulate_start_attempt(stdClass $quiz, stdClass $user): quiz_attempt {
    global $DB;

    $existing = $DB->get_record('quiz_attempts', [
        'quiz' => $quiz->id,
        'userid' => $user->id,
        'state' => quiz_attempt::IN_PROGRESS,
        'preview' => 0,
    ], '*', IGNORE_MISSING);
    if ($existing) {
        return quiz_attempt::create((int) $existing->id);
    }

    $finished = $DB->record_exists_select(
        'quiz_attempts',
        'quiz = :quiz AND userid = :userid AND preview = 0 AND state IN (:finished, :submitted)',
        [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'finished' => quiz_attempt::FINISHED,
            'submitted' => 'submitted',
        ]
    );
    if ($finished) {
        throw new moodle_exception('Student already finished the quiz: ' . $user->username);
    }

    $generator = testing_util::get_data_generator();
    $quizgenerator = $generator->get_plugin_generator('mod_quiz');
    manager::set_user($user);
    $attempt = $quizgenerator->create_attempt($quiz->id, $user->id);
    manager::set_user(get_admin());
    return quiz_attempt::create((int) $attempt->id);
}

/**
 * Pick a random student who can answer their next question.
 *
 * @param stdClass $quiz
 * @param stdClass[] $students
 * @return array{user: stdClass, attempt: quiz_attempt, slot: int, finish: bool}|null
 */
function simulate_pick_next_action(stdClass $quiz, array $students): ?array {
    $candidates = $students;
    shuffle($candidates);

    foreach ($candidates as $user) {
        try {
            $attemptobj = simulate_start_attempt($quiz, $user);
        } catch (moodle_exception $e) {
            continue;
        }

        $unanswered = simulate_unanswered_slots($attemptobj);
        if ($unanswered === []) {
            continue;
        }

        $slot = min($unanswered);
        $qa = $attemptobj->get_question_attempt($slot);
        if (demo_random_response_summary($qa) === null) {
            continue;
        }

        return [
            'user' => $user,
            'attempt' => $attemptobj,
            'slot' => $slot,
            'finish' => count($unanswered) === 1,
        ];
    }

    return null;
}

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'quiet' => false,
        'interval-min' => DEMO_INTERVAL_MIN,
        'interval-max' => DEMO_INTERVAL_MAX,
        'duration' => DEMO_DURATION,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($options['help'])) {
    echo <<<EOF
Simulate students answering the Live Quiz Monitor demo quiz.

Each loop waits, then a random enrolled student answers their next unanswered
question. The quiz is submitted automatically when that answer completes the
attempt.

Options:
  --interval-min=N   Minimum seconds between answers (default: 1)
  --interval-max=N   Maximum seconds between answers (default: 1)
  --duration=N       Stop after N seconds (default: 120, use 0 to run until Ctrl+C)
  --quiet            Suppress informational output
  -h, --help         Print this help

Example:
  php mod/quiz/report/livequizmonitor/cli/setup_dev_data.php --force
  php mod/quiz/report/livequizmonitor/cli/simulate_quiz_demo.php

EOF;
    exit(0);
}

$quiet = !empty($options['quiet']);
$intervalmin = max(1, (int) $options['interval-min']);
$intervalmax = max($intervalmin, (int) $options['interval-max']);
$duration = max(0, (int) $options['duration']);

manager::set_user(get_admin());

['course' => $course, 'quiz' => $quiz, 'cm' => $cm] = demo_resolve_course_quiz();
$context = context_module::instance($cm->id);
$students = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.*', 'u.username ASC');

if (count($students) === 0) {
    cli_error('No enrolled students found. Run setup_dev_data.php first.');
}

$reporturl = (new moodle_url('/mod/quiz/report.php', [
    'id' => $cm->id,
    'mode' => 'livequizmonitor',
]))->out(false);

demo_log('Live quiz demo simulator', $quiet);
demo_log('Course: ' . DEMO_COURSE_SHORTNAME . ' (id ' . $course->id . ')', $quiet);
demo_log('Quiz:   ' . DEMO_QUIZ_NAME . ' (id ' . $quiz->id . ')', $quiet);
demo_log('Students: ' . count($students) . ' enrolled', $quiet);
demo_log('Interval: ' . ($intervalmin === $intervalmax
    ? $intervalmin . ' s between answers'
    : 'random ' . $intervalmin . '–' . $intervalmax . ' s between answers'), $quiet);
demo_log('Duration:  ' . ($duration > 0 ? $duration . ' s' : 'until Ctrl+C'), $quiet);
demo_log('Open Live Monitor: ' . $reporturl, $quiet);
demo_log('Press Ctrl+C to stop early.', $quiet);

$startedat = time();
$actions = 0;

while (true) {
    if ($duration > 0 && (time() - $startedat) >= $duration) {
        demo_log('Duration reached, stopping.', $quiet);
        break;
    }

    $waitfor = demo_random_interval($intervalmin, $intervalmax);
    if (!$quiet) {
        mtrace('  waiting ' . $waitfor . ' s…');
    }
    sleep($waitfor);

    $action = simulate_pick_next_action($quiz, array_values($students));
    if ($action === null) {
        demo_log('  all students finished — stopping', $quiet);
        break;
    }

    $user = $action['user'];
    $slot = $action['slot'];
    $finish = $action['finish'];
    manager::set_user($user);

    $qa = $action['attempt']->get_question_attempt($slot);
    $summary = demo_random_response_summary($qa);
    demo_submit_responses((int) $action['attempt']->get_attemptid(), [$slot => $summary], $finish);

    if ($finish) {
        demo_log('  ' . $user->username . ' answered question ' . $slot . ' of ' . DEMO_QUESTION_COUNT . ' and submitted', $quiet);
    } else {
        demo_log('  ' . $user->username . ' answered question ' . $slot . ' of ' . DEMO_QUESTION_COUNT, $quiet);
    }

    manager::set_user(get_admin());
    $actions++;
}

if (!$quiet) {
    echo PHP_EOL;
    mtrace('Demo simulator finished. Actions performed: ' . $actions);
    echo PHP_EOL;
}
