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
 * Builds live monitor state from quiz enrolment and attempt data.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use cm_info;
use context_module;
use mod_quiz\quiz_attempt;
use stdClass;

/**
 * Manager for cohort resolution, status mapping, and monitor payloads.
 */
class monitor_manager {

    /** @var string Monitor status: not started. */
    public const STATUS_NOTSTARTED = 'notstarted';

    /** @var string Monitor status: in progress. */
    public const STATUS_INPROGRESS = 'inprogress';

    /** @var string Monitor status: completed. */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Build the full monitor state for a quiz module.
     *
     * @param stdClass $course Course record.
     * @param cm_info|stdClass $cm Course module record or cached cm_info.
     * @param stdClass $quiz Quiz instance record.
     * @param int $groupid Active group id (0 = all visible groups).
     * @return stdClass MonitorState payload.
     */
    public static function get_state(stdClass $course, cm_info|stdClass $cm, stdClass $quiz, int $groupid = 0): stdClass {
        global $DB;

        $context = context_module::instance($cm->id);
        $now = time();

        // Resolve group for enrolment query (respect quiz report group mode).
        if ($groupid <= 0) {
            $groupid = groups_get_activity_group($cm, true) ?: 0;
        }

        $students = get_enrolled_users($context, 'mod/quiz:attempt', $groupid, 'u.*', 'u.lastname ASC, u.firstname ASC');
        $totalquestions = self::count_quiz_questions((int) $quiz->id);
        $showemail = has_capability('moodle/course:viewhiddenuserfields', $context);

        // Load all non-preview attempts for this quiz, newest first per user scan.
        $attempts = $DB->get_records_select(
            'quiz_attempts',
            'quiz = :quizid AND preview = 0',
            ['quizid' => $quiz->id],
            'timestart DESC'
        );

        $attemptsbyuser = [];
        foreach ($attempts as $attempt) {
            $attemptsbyuser[$attempt->userid][] = $attempt;
        }

        $rows = [];
        foreach ($students as $user) {
            $userattempts = $attemptsbyuser[$user->id] ?? [];
            $relevant = self::pick_relevant_attempt($userattempts);
            $rows[] = self::build_student_row($user, $relevant, $totalquestions, $quiz, $context, $now, $showemail);
        }

        self::sort_student_rows($rows);
        $summary = self::build_summary($rows, count($students));

        $state = (object) [
            'quizid' => (int) $quiz->id,
            'cmid' => (int) $cm->id,
            'quizname' => format_string($quiz->name, true, ['context' => $context]),
            'updatedat' => $now,
            'totalstudents' => count($students),
            'summary' => $summary,
            'students' => $rows,
            'hasstudents' => count($students) > 0,
        ];

        return $state;
    }

    /**
     * Count question slots in the quiz.
     *
     * @param int $quizid Quiz id.
     * @return int
     */
    protected static function count_quiz_questions(int $quizid): int {
        global $DB;
        return (int) $DB->count_records('quiz_slots', ['quizid' => $quizid]);
    }

    /**
     * Pick the most relevant attempt for a user (unfinished wins, else latest finished).
     *
     * @param array $attempts Attempt records ordered newest first.
     * @return stdClass|null
     */
    protected static function pick_relevant_attempt(array $attempts): ?stdClass {
        $latestfinished = null;

        foreach ($attempts as $attempt) {
            if (in_array($attempt->state, [quiz_attempt::IN_PROGRESS, quiz_attempt::OVERDUE], true)) {
                return $attempt;
            }
            if ($attempt->state === quiz_attempt::FINISHED && $latestfinished === null) {
                $latestfinished = $attempt;
            }
        }

        return $latestfinished;
    }

    /**
     * Bootstrap presentation classes for a monitor status.
     *
     * @param string $status Monitor status constant.
     * @return array{badgeclass: string, progressbarclass: string, tileborderclass: string}
     */
    public static function get_status_presentation(string $status): array {
        switch ($status) {
            case self::STATUS_INPROGRESS:
                return [
                    'badgeclass' => 'badge-warning',
                    'progressbarclass' => 'bg-warning',
                    'tileborderclass' => 'border-warning',
                ];
            case self::STATUS_COMPLETED:
                return [
                    'badgeclass' => 'badge-success',
                    'progressbarclass' => 'bg-success',
                    'tileborderclass' => 'border-success',
                ];
            default:
                return [
                    'badgeclass' => 'badge-secondary',
                    'progressbarclass' => 'bg-secondary',
                    'tileborderclass' => 'border-secondary',
                ];
        }
    }

    /**
     * Compute progress bar fill percentage for a student row.
     *
     * @param string $status Monitor status.
     * @param int $answered Questions answered.
     * @param int $total Total questions in quiz.
     * @return int Percentage 0–100.
     */
    public static function compute_progress_percent(string $status, int $answered, int $total): int {
        if ($status === self::STATUS_COMPLETED) {
            return 100;
        }
        if ($status === self::STATUS_NOTSTARTED || $total <= 0) {
            return 0;
        }
        return (int) round($answered / $total * 100);
    }

    /**
     * Map a Moodle attempt state to monitor status.
     *
     * @param stdClass|null $attempt Relevant attempt or null.
     * @return string
     */
    protected static function map_status(?stdClass $attempt): string {
        if ($attempt === null) {
            return self::STATUS_NOTSTARTED;
        }
        if (in_array($attempt->state, [quiz_attempt::IN_PROGRESS, quiz_attempt::OVERDUE], true)) {
            return self::STATUS_INPROGRESS;
        }
        if ($attempt->state === quiz_attempt::FINISHED) {
            return self::STATUS_COMPLETED;
        }
        return self::STATUS_NOTSTARTED;
    }

    /**
     * Build a student row for the monitor table/API.
     *
     * @param stdClass $user User record.
     * @param stdClass|null $attempt Relevant attempt.
     * @param int $totalquestions Total quiz questions.
     * @param stdClass $quiz Quiz record.
     * @param context_module $context Module context.
     * @param int $now Current timestamp.
     * @param bool $showemail Whether email may be shown.
     * @return stdClass
     */
    protected static function build_student_row(
        stdClass $user,
        ?stdClass $attempt,
        int $totalquestions,
        stdClass $quiz,
        context_module $context,
        int $now,
        bool $showemail
    ): stdClass {
        $status = self::map_status($attempt);
        $statuslabel = self::status_label($status);

        $answered = 0;
        $timeremaining = null;
        $timeremainingdisplay = '';

        if ($attempt !== null && $status !== self::STATUS_NOTSTARTED) {
            try {
                $attemptobj = quiz_attempt::create((int) $attempt->id);
                $answered = self::count_answered_questions($attemptobj);
                if ($status === self::STATUS_INPROGRESS) {
                    $accessmanager = $attemptobj->get_access_manager($now);
                    $timeremaining = $accessmanager->get_time_left_display($attempt, $now);
                    if ($timeremaining !== false && $timeremaining >= 0) {
                        $timeremainingdisplay = self::format_duration((int) $timeremaining);
                    } else if ($timeremaining !== false && $timeremaining < 0) {
                        $timeremaining = 0;
                        $timeremainingdisplay = get_string('timeup', 'quiz_livequizmonitor');
                    }
                }
            } catch (\Exception $e) {
                debugging('Failed to load attempt ' . $attempt->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $progresstext = '';
        if ($totalquestions > 0) {
            $progresstext = get_string('progressanswered', 'quiz_livequizmonitor', (object) [
                'answered' => $answered,
                'total' => $totalquestions,
            ]);
        }

        $presentation = self::get_status_presentation($status);
        $progresspercent = self::compute_progress_percent($status, $answered, $totalquestions);

        return (object) [
            'userid' => (int) $user->id,
            'fullname' => fullname($user),
            'email' => $showemail ? $user->email : '',
            'showemail' => $showemail,
            'status' => $status,
            'statuslabel' => $statuslabel,
            'statusclass' => $presentation['badgeclass'],
            'progressbarclass' => $presentation['progressbarclass'],
            'progresspercent' => $progresspercent,
            'attemptid' => $attempt ? (int) $attempt->id : null,
            'progressanswered' => $answered,
            'progresstotal' => $totalquestions,
            'progresstext' => $progresstext,
            'timeremaining' => $timeremaining,
            'timeremainingdisplay' => $timeremainingdisplay,
            'hastimer' => $status === self::STATUS_INPROGRESS && $timeremaining !== null,
        ];
    }

    /**
     * Count answered questions on an attempt.
     *
     * @param quiz_attempt $attemptobj Attempt object.
     * @return int
     */
    protected static function count_answered_questions(quiz_attempt $attemptobj): int {
        $answered = 0;
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            switch ($qa->get_state_class(false)) {
                case 'answersaved':
                case 'complete':
                case 'gradedright':
                case 'gradedwrong':
                case 'gradedpartial':
                case 'mangrright':
                case 'mangrpartial':
                case 'mangrwrong':
                    $answered++;
                    break;
            }
        }
        return $answered;
    }

    /**
     * Localised label for monitor status.
     *
     * @param string $status Monitor status constant.
     * @return string
     */
    protected static function status_label(string $status): string {
        switch ($status) {
            case self::STATUS_INPROGRESS:
                return get_string('status:inprogress', 'quiz_livequizmonitor');
            case self::STATUS_COMPLETED:
                return get_string('status:completed', 'quiz_livequizmonitor');
            default:
                return get_string('status:notstarted', 'quiz_livequizmonitor');
        }
    }

    /**
     * Format seconds as MM:SS or HH:MM:SS.
     *
     * @param int $seconds Seconds.
     * @return string
     */
    public static function format_duration(int $seconds): string {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%02d:%02d', $minutes, $secs);
    }

    /**
     * Sort rows: in progress, not started, completed; then by fullname.
     *
     * @param array $rows Student rows (by reference).
     */
    protected static function sort_student_rows(array &$rows): void {
        $rank = [
            self::STATUS_INPROGRESS => 0,
            self::STATUS_NOTSTARTED => 1,
            self::STATUS_COMPLETED => 2,
        ];

        usort($rows, static function(stdClass $a, stdClass $b) use ($rank): int {
            $cmp = ($rank[$a->status] ?? 99) <=> ($rank[$b->status] ?? 99);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a->fullname, $b->fullname);
        });
    }

    /**
     * Build cohort summary counts and percentages.
     *
     * @param array $rows Student rows.
     * @param int $total Total students.
     * @return stdClass
     */
    protected static function build_summary(array $rows, int $total): stdClass {
        $counts = [
            self::STATUS_NOTSTARTED => 0,
            self::STATUS_INPROGRESS => 0,
            self::STATUS_COMPLETED => 0,
        ];

        foreach ($rows as $row) {
            if (isset($counts[$row->status])) {
                $counts[$row->status]++;
            }
        }

        $percent = static function(int $count) use ($total): int {
            if ($total === 0) {
                return 0;
            }
            return (int) round($count / $total * 100);
        };

        $buildbucket = static function(string $status, string $labelkey) use ($counts, $percent): stdClass {
            $presentation = self::get_status_presentation($status);
            return (object) [
                'count' => $counts[$status],
                'percent' => $percent($counts[$status]),
                'label' => get_string($labelkey, 'quiz_livequizmonitor'),
                'statusclass' => $presentation['tileborderclass'],
            ];
        };

        return (object) [
            'notstarted' => $buildbucket(self::STATUS_NOTSTARTED, 'summary:notstarted'),
            'inprogress' => $buildbucket(self::STATUS_INPROGRESS, 'summary:inprogress'),
            'completed' => $buildbucket(self::STATUS_COMPLETED, 'summary:completed'),
        ];
    }
}
