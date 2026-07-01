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
 * Supervision scope validation for Live Monitor external endpoints.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

defined('MOODLE_INTERNAL') || die();

use cm_info;
use context_module;
use moodle_exception;
use stdClass;

/**
 * Shared group and cohort checks for monitor supervision actions.
 */
class supervision_scope_manager {

    /**
     * Ensure the current user may request monitor data for the given activity group.
     *
     * @param int $groupid Requested group id (0 = session default, not validated here).
     * @param cm_info|stdClass $cm Course module.
     * @return void
     * @throws moodle_exception When the group is not visible to the current user.
     */
    public static function validate_group_access(int $groupid, cm_info|stdClass $cm): void {
        if ($groupid <= 0) {
            return;
        }

        $allowedgroups = groups_get_activity_allowed_groups($cm);
        if (!isset($allowedgroups[$groupid])) {
            throw new moodle_exception('error:groupnotvisible', 'quiz_livequizmonitor');
        }
    }

    /**
     * Check whether a user is in the obliged cohort visible under the group filter.
     *
     * @param int $userid Target student user id.
     * @param context_module $context Module context.
     * @param int $groupid Active group id (0 resolves to activity default).
     * @param cm_info|stdClass $cm Course module.
     * @return bool
     */
    public static function is_user_in_cohort(
        int $userid,
        context_module $context,
        int $groupid,
        cm_info|stdClass $cm
    ): bool {
        if ($groupid <= 0) {
            $groupid = groups_get_activity_group($cm, true) ?: 0;
        }

        $students = get_enrolled_users($context, 'mod/quiz:attempt', $groupid, 'u.id');
        return isset($students[$userid]);
    }
}
