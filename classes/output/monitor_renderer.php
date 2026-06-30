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
 * Renderer for the live quiz monitor report.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\output;

defined('MOODLE_INTERNAL') || die();

use plugin_renderer_base;
use stdClass;

/**
 * Output renderer for monitor page templates.
 */
class monitor_renderer extends plugin_renderer_base {

    /**
     * Prepare template context from monitor state.
     *
     * @param stdClass $state Monitor state from monitor_manager.
     * @param int $pollinterval Poll interval in seconds.
     * @param int $groupid Active group id.
     * @return array Template context.
     */
    public function export_for_template(stdClass $state, int $pollinterval, int $groupid): array {
        $updated = userdate($state->updatedat, get_string('strftimetime', 'langconfig'));

        return [
            'cmid' => $state->cmid,
            'quizname' => $state->quizname,
            'totalstudents' => $state->totalstudents,
            'hasstudents' => (bool) $state->hasstudents,
            'updatedat' => $state->updatedat,
            'updatedattext' => get_string('lastupdated', 'quiz_livequizmonitor', $updated),
            'lastupdatedprefix' => get_string('lastupdated', 'quiz_livequizmonitor', ''),
            'liveindicator' => get_string('liveindicator', 'quiz_livequizmonitor'),
            'staleindicator' => get_string('staleindicator', 'quiz_livequizmonitor'),
            'emptycohort' => get_string('emptycohort', 'quiz_livequizmonitor'),
            'pollinterval' => $pollinterval,
            'groupid' => $groupid,
            'summary' => (array) $state->summary,
            'students' => array_map(static function(stdClass $row): array {
                return (array) $row;
            }, $state->students),
            'tableheaders' => [
                'status' => get_string('table:status', 'quiz_livequizmonitor'),
                'student' => get_string('table:student', 'quiz_livequizmonitor'),
                'email' => get_string('table:email', 'quiz_livequizmonitor'),
                'progress' => get_string('table:progress', 'quiz_livequizmonitor'),
                'timeremaining' => get_string('table:timeremaining', 'quiz_livequizmonitor'),
            ],
            'showemailcolumn' => !empty($state->students) && !empty($state->students[0]->showemail),
        ];
    }
}
