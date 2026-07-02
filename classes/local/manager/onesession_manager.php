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
 * Soft integration with quizaccess_onesession for live monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

defined('MOODLE_INTERNAL') || die();

use context_module;
use core_plugin_manager;
use mod_quiz\quiz_attempt;
use moodle_exception;
use stdClass;

/**
 * Detect onesession availability, blocked attempts, and perform unblock.
 */
class onesession_manager {
    /** @var string Event name for a blocked concurrent session attempt. */
    public const EVENT_ATTEMPT_BLOCKED = '\\quizaccess_onesession\\event\\attempt_blocked';

    /** @var string Event name for teacher unlock of a blocked attempt. */
    public const EVENT_ATTEMPT_UNLOCKED = '\\quizaccess_onesession\\event\\attempt_unlocked';

    /**
     * Whether the onesession access rule plugin is installed.
     *
     * @return bool
     */
    public static function is_plugin_installed(): bool {
        return core_plugin_manager::instance()->get_plugin_info('quizaccess_onesession') !== null;
    }

    /**
     * Whether onesession is active for a quiz (plugin installed and rule enabled).
     *
     * @param int $quizid Quiz id.
     * @param stdClass|null $quiz Optional quiz record with onesessionenabled field.
     * @return bool
     */
    public static function is_active_for_quiz(int $quizid, ?stdClass $quiz = null): bool {
        global $DB;

        if (!self::is_plugin_installed()) {
            return false;
        }

        if ($quiz !== null && property_exists($quiz, 'onesessionenabled')) {
            return !empty($quiz->onesessionenabled);
        }

        return $DB->record_exists('quizaccess_onesession', ['quizid' => $quizid, 'enabled' => 1]);
    }

    /**
     * Whether the viewer may unblock quiz attempts in this context.
     *
     * @param context_module $context Quiz module context.
     * @return bool
     */
    public static function user_can_unblock(context_module $context): bool {
        if (!self::is_plugin_installed()) {
            return false;
        }
        return has_capability('quizaccess/onesession:unlockattempt', $context);
    }

    /**
     * Map attempt ids to blocked state from standard log events.
     *
     * Blocked when the latest onesession event for the attempt is attempt_blocked.
     *
     * @param int[] $attemptids In-progress attempt ids.
     * @return array<int, bool> attemptid => isblocked
     */
    public static function get_blocked_map(array $attemptids): array {
        global $DB;

        $attemptids = array_values(array_unique(array_filter(array_map('intval', $attemptids))));
        $map = array_fill_keys($attemptids, false);

        if (empty($attemptids) || !self::is_plugin_installed()) {
            return $map;
        }

        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'attempt');
        $params['eventblocked'] = self::EVENT_ATTEMPT_BLOCKED;
        $params['eventunlocked'] = self::EVENT_ATTEMPT_UNLOCKED;

        $sql = "SELECT objectid, eventname, timecreated
                  FROM {logstore_standard_log}
                 WHERE objectid $insql
                   AND eventname IN (:eventblocked, :eventunlocked)
              ORDER BY timecreated DESC, id DESC";

        $latest = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $record) {
            $attemptid = (int) $record->objectid;
            if (!isset($latest[$attemptid])) {
                $latest[$attemptid] = $record->eventname;
            }
        }
        $rs->close();

        foreach ($latest as $attemptid => $eventname) {
            if ($eventname === self::EVENT_ATTEMPT_BLOCKED) {
                $map[$attemptid] = true;
            }
        }

        return $map;
    }

    /**
     * Unblock a quiz attempt (delete session lock and log unlock event).
     *
     * @param int $attemptid Attempt id.
     * @param context_module $context Quiz module context.
     * @return void
     */
    public static function unblock_attempt(int $attemptid, context_module $context): void {
        global $DB;

        if (!self::is_plugin_installed()) {
            throw new moodle_exception('onesession:notactive', 'quiz_livequizmonitor');
        }

        require_capability('quizaccess/onesession:unlockattempt', $context);

        $attemptobj = quiz_attempt::create($attemptid);
        $quizobj = $attemptobj->get_quizobj();

        if (!monitor_manager::is_active_attempt_state($attemptobj->get_state())) {
            throw new moodle_exception('onesession:errnotinprogress', 'quiz_livequizmonitor');
        }
        if ($attemptobj->is_preview()) {
            throw new moodle_exception('onesession:errnotinprogress', 'quiz_livequizmonitor');
        }
        if (!self::is_active_for_quiz((int) $quizobj->get_quizid(), $quizobj->get_quiz())) {
            throw new moodle_exception('onesession:notactive', 'quiz_livequizmonitor');
        }

        $DB->delete_records('quizaccess_onesession_sess', ['attemptid' => $attemptid]);

        if (class_exists('\quizaccess_onesession\event\attempt_unlocked')) {
            $params = [
                'objectid' => $attemptobj->get_attemptid(),
                'relateduserid' => $attemptobj->get_userid(),
                'courseid' => $attemptobj->get_courseid(),
                'context' => $context,
                'other' => [
                    'quizid' => $attemptobj->get_quizid(),
                ],
            ];
            $event = \quizaccess_onesession\event\attempt_unlocked::create($params);
            $event->trigger();
        }
    }
}
