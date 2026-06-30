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
use quiz_livequizmonitor\local\manager\monitor_manager;
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
        $canextend = !empty($state->canextend);
        $inprogresscount = (int) ($state->inprogresscount ?? $state->summary->inprogress->count);

        $students = [];
        foreach ($state->students as $row) {
            $student = (array) $row;
            $student['extendactionenabled'] = $canextend && $row->status === monitor_manager::STATUS_INPROGRESS;
            $student['canextend'] = $canextend;
            $student['notelabel'] = !empty($row->hasnote)
                ? get_string('notes:editlabel', 'quiz_livequizmonitor')
                : get_string('notes:addlabel', 'quiz_livequizmonitor');
            $students[] = $student;
        }

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
            'students' => $students,
            'canextend' => $canextend,
            'inprogresscount' => $inprogresscount,
            'bulkextenddisabled' => $inprogresscount === 0,
            'bulkextendlabel' => get_string('extend:bulklabel', 'quiz_livequizmonitor'),
            'extendrowlabel' => get_string('extend:rowaction', 'quiz_livequizmonitor'),
            'notesaddlabel' => get_string('notes:addlabel', 'quiz_livequizmonitor'),
            'noteseditlabel' => get_string('notes:editlabel', 'quiz_livequizmonitor'),
            'actionsmenulabel' => get_string('actions'),
            'tableheaders' => [
                'status' => get_string('table:status', 'quiz_livequizmonitor'),
                'student' => get_string('table:student', 'quiz_livequizmonitor'),
                'email' => get_string('table:email', 'quiz_livequizmonitor'),
                'progress' => get_string('table:progress', 'quiz_livequizmonitor'),
                'timeremaining' => get_string('table:timeremaining', 'quiz_livequizmonitor'),
                'actions' => get_string('table:actions', 'quiz_livequizmonitor'),
            ],
            'showemailcolumn' => !empty($state->students) && !empty($state->students[0]->showemail),
            'showactionscolumn' => (bool) $state->hasstudents,
            'filter' => $this->export_filter_context($state),
            'filterempty' => get_string('filter:empty', 'quiz_livequizmonitor'),
        ];
    }

    /**
     * Build filter toolbar template context.
     *
     * @param stdClass $state Monitor state from monitor_manager.
     * @return array Template context for filter partial.
     */
    protected function export_filter_context(stdClass $state): array {
        $summary = $state->summary;

        return [
            'searchplaceholder' => get_string('filter:searchplaceholder', 'quiz_livequizmonitor'),
            'clearlabel' => get_string('filter:clear', 'quiz_livequizmonitor'),
            'chipsgrouplabel' => get_string('filter:toolbarlabel', 'quiz_livequizmonitor'),
            'chips' => [
                [
                    'status' => 'all',
                    'label' => get_string('filter:all', 'quiz_livequizmonitor'),
                    'count' => $state->totalstudents,
                    'active' => true,
                ],
                [
                    'status' => 'notstarted',
                    'label' => get_string('status:notstarted', 'quiz_livequizmonitor'),
                    'count' => $summary->notstarted->count,
                    'active' => false,
                ],
                [
                    'status' => 'inprogress',
                    'label' => get_string('status:inprogress', 'quiz_livequizmonitor'),
                    'count' => $summary->inprogress->count,
                    'active' => false,
                ],
                [
                    'status' => 'completed',
                    'label' => get_string('status:completed', 'quiz_livequizmonitor'),
                    'count' => $summary->completed->count,
                    'active' => false,
                ],
            ],
        ];
    }
}
