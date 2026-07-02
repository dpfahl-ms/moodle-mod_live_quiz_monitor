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
 * Extends quiz attempt time for in-progress students via user overrides.
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
use core\message\message;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use moodle_exception;
use stdClass;

/**
 * Applies relative time extensions through core quiz user overrides.
 */
class extend_time_manager {
    /** @var int[] Allowed extension durations in minutes. */
    public const ALLOWED_MINUTES = [5, 10, 15, 30];

    /** @var string Individual extend scope. */
    public const SCOPE_INDIVIDUAL = 'individual';

    /** @var string Bulk extend scope. */
    public const SCOPE_BULK = 'bulk';

    /**
     * Whether the current user may extend quiz time on this monitor.
     *
     * @param context_module $context Module context.
     * @return bool
     */
    public static function user_can_extend(context_module $context): bool {
        return has_capability('quiz/livequizmonitor:view', $context)
            && has_capability('mod/quiz:manageoverrides', $context);
    }

    /**
     * Extend quiz time for one or all in-progress students.
     *
     * @param stdClass $course Course record.
     * @param cm_info|stdClass $cm Course module.
     * @param stdClass $quiz Quiz record.
     * @param int $groupid Group filter for cohort resolution.
     * @param int $minutes Minutes to add.
     * @param string $scope individual|bulk
     * @param int $userid Target user for individual scope.
     * @return stdClass Outcome payload.
     */
    public static function extend_quiz_time(
        stdClass $course,
        cm_info|stdClass $cm,
        stdClass $quiz,
        int $groupid,
        int $minutes,
        string $scope,
        int $userid = 0
    ): stdClass {
        global $USER;

        $context = context_module::instance($cm->id);
        if (!self::user_can_extend($context)) {
            throw new moodle_exception(
                'nopermissions',
                'error',
                '',
                get_string('extend:errornopermission', 'quiz_livequizmonitor')
            );
        }

        if (!in_array($minutes, self::ALLOWED_MINUTES, true)) {
            throw new moodle_exception('invalidminutes', 'quiz_livequizmonitor');
        }

        if ($scope === self::SCOPE_INDIVIDUAL) {
            if ($userid <= 0) {
                throw new moodle_exception('missinguserid', 'quiz_livequizmonitor');
            }
            $userids = [$userid];
        } else if ($scope === self::SCOPE_BULK) {
            $userids = self::get_inprogress_user_ids($course, $cm, $quiz, $groupid);
        } else {
            throw new moodle_exception('invalidscope', 'quiz_livequizmonitor');
        }

        $outcome = (object) [
            'extendedcount' => 0,
            'minutes' => $minutes,
            'scope' => $scope,
            'usernames' => [],
            'warnings' => [],
        ];

        foreach ($userids as $targetuserid) {
            try {
                $username = self::extend_user($course, $cm, $quiz, $targetuserid, $minutes, $USER->id);
                $outcome->extendedcount++;
                $outcome->usernames[] = $username;
            } catch (moodle_exception $e) {
                $outcome->warnings[] = $e->getMessage();
            }
        }

        if ($scope === self::SCOPE_BULK && $outcome->extendedcount === 0 && empty($userids)) {
            $outcome->warnings[] = get_string('extend:errornoinprogress', 'quiz_livequizmonitor');
        }

        return $outcome;
    }

    /**
     * Resolve user ids with in-progress monitor status from the obliged cohort.
     *
     * @param stdClass $course Course record.
     * @param cm_info|stdClass $cm Course module.
     * @param stdClass $quiz Quiz record.
     * @param int $groupid Group filter.
     * @return int[]
     */
    public static function get_inprogress_user_ids(
        stdClass $course,
        cm_info|stdClass $cm,
        stdClass $quiz,
        int $groupid
    ): array {
        $state = monitor_manager::get_state($course, $cm, $quiz, $groupid);
        $userids = [];
        foreach ($state->students as $row) {
            if ($row->status === monitor_manager::STATUS_INPROGRESS) {
                $userids[] = (int) $row->userid;
            }
        }
        return $userids;
    }

    /**
     * Extend one user's active attempt.
     *
     * @param stdClass $course Course record.
     * @param cm_info|stdClass $cm Course module.
     * @param stdClass $quiz Quiz record.
     * @param int $userid Target user id.
     * @param int $minutes Minutes to add.
     * @param int $actorid User performing the extension.
     * @return string Extended user's full name.
     */
    public static function extend_user(
        stdClass $course,
        cm_info|stdClass $cm,
        stdClass $quiz,
        int $userid,
        int $minutes,
        int $actorid
    ): string {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $attempt = self::get_inprogress_attempt((int) $quiz->id, $userid);
        if ($attempt === null) {
            throw new moodle_exception('noattempttoextend', 'quiz_livequizmonitor', '', fullname($user));
        }

        $attemptobj = quiz_attempt::create((int) $attempt->id);
        $now = time();
        $accessmanager = $attemptobj->get_access_manager($now);
        $endtime = $accessmanager->get_end_time($attempt);
        if ($endtime === false) {
            throw new moodle_exception('noextendablelimit', 'quiz_livequizmonitor', '', fullname($user));
        }

        $extra = $minutes * 60;
        $quizsettings = quiz_settings::create((int) $quiz->id, $userid);
        $effectivequiz = $quizsettings->get_quiz();

        $override = $DB->get_record('quiz_overrides', [
            'quiz' => $quiz->id,
            'userid' => $userid,
        ]);

        if (!empty($effectivequiz->timelimit)) {
            $newtimelimit = max(0, ($endtime - $attempt->timestart) + $extra);
            if ($override) {
                $override->timelimit = $newtimelimit;
                $DB->update_record('quiz_overrides', $override);
            } else {
                $override = (object) [
                    'quiz' => (int) $quiz->id,
                    'userid' => $userid,
                    'timelimit' => $newtimelimit,
                ];
                $override->id = $DB->insert_record('quiz_overrides', $override);
            }
        } else if (!empty($effectivequiz->timeclose)) {
            $newtimeclose = $endtime + $extra;
            if ($override) {
                $override->timeclose = $newtimeclose;
                $DB->update_record('quiz_overrides', $override);
            } else {
                $override = (object) [
                    'quiz' => (int) $quiz->id,
                    'userid' => $userid,
                    'timeclose' => $newtimeclose,
                ];
                $override->id = $DB->insert_record('quiz_overrides', $override);
            }
        } else {
            throw new moodle_exception('noextendablelimit', 'quiz_livequizmonitor', '', fullname($user));
        }

        self::purge_override_cache((int) $quiz->id, $userid);

        $quizforupdate = clone $quiz;
        $quizforupdate->cmid = $cm->id;
        quiz_update_events($quizforupdate, $override);

        self::notify_student($user, $quiz, $minutes, $actorid);

        return fullname($user);
    }

    /**
     * Get the active in-progress attempt for a user.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return stdClass|null
     */
    protected static function get_inprogress_attempt(int $quizid, int $userid): ?stdClass {
        global $DB;

        $attempts = $DB->get_records_select(
            'quiz_attempts',
            'quiz = :quizid AND userid = :userid AND preview = 0',
            ['quizid' => $quizid, 'userid' => $userid],
            'timestart DESC'
        );

        $relevant = monitor_manager::pick_relevant_attempt($attempts);
        if ($relevant === null) {
            return null;
        }
        if (!monitor_manager::is_active_attempt_state($relevant->state)) {
            return null;
        }
        return $relevant;
    }

    /**
     * Purge quiz override cache for a user.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     */
    protected static function purge_override_cache(int $quizid, int $userid): void {
        if (class_exists(\mod_quiz\local\quiz_overrides_cache_manager::class)) {
            \mod_quiz\local\quiz_overrides_cache_manager::purge_for_user($quizid, $userid);
            return;
        }

        if (class_exists(\cache::class)) {
            \cache::make('mod_quiz', 'overrides')->delete("{$quizid}_u_{$userid}");
        }
    }

    /**
     * Notify a student that extra time was granted.
     *
     * @param stdClass $user Student user record.
     * @param stdClass $quiz Quiz record.
     * @param int $minutes Minutes added.
     * @param int $actorid Teacher user id.
     */
    protected static function notify_student(stdClass $user, stdClass $quiz, int $minutes, int $actorid): void {
        $eventdata = new message();
        $eventdata->component = 'quiz_livequizmonitor';
        $eventdata->name = 'timeextended';
        $eventdata->userfrom = \core_user::get_user($actorid);
        $eventdata->userto = $user;
        $eventdata->subject = get_string('message:timeextendedsubject', 'quiz_livequizmonitor', format_string($quiz->name));
        $eventdata->fullmessage = get_string('message:timeextendedbody', 'quiz_livequizmonitor', (object) [
            'quizname' => format_string($quiz->name),
            'minutes' => $minutes,
        ]);
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->smallmessage = get_string('message:timeextendedsmall', 'quiz_livequizmonitor', $minutes);
        $eventdata->notification = 1;

        message_send($eventdata);
    }
}
