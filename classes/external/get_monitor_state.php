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
 * External API for polling live monitor state.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use external_multiple_structure;
use quiz_livequizmonitor\local\manager\monitor_manager;

/**
 * Returns monitor state for AJAX polling.
 */
class get_monitor_state extends external_api {

    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'groupid' => new external_value(PARAM_INT, 'Group id filter', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute the external function.
     *
     * @param int $cmid Course module id.
     * @param int $groupid Group id.
     * @return array
     */
    public static function execute(int $cmid, int $groupid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'groupid' => $groupid,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('quiz/livequizmonitor:view', $context);

        $state = monitor_manager::get_state($course, $cm, $quiz, (int) $params['groupid']);

        return self::export_state($state);
    }

    /**
     * Return structure description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::state_structure();
    }

    /**
     * External structure for monitor state.
     *
     * @return external_single_structure
     */
    protected static function state_structure(): external_single_structure {
        $statuscount = new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Count'),
            'percent' => new external_value(PARAM_INT, 'Percent'),
            'label' => new external_value(PARAM_TEXT, 'Label'),
            'statusclass' => new external_value(PARAM_TEXT, 'CSS class'),
        ]);

        $student = new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'User id'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'email' => new external_value(PARAM_TEXT, 'Email'),
            'showemail' => new external_value(PARAM_BOOL, 'Show email'),
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Status label'),
            'statusclass' => new external_value(PARAM_TEXT, 'Status CSS class'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_OPTIONAL),
            'progressanswered' => new external_value(PARAM_INT, 'Answered count'),
            'progresstotal' => new external_value(PARAM_INT, 'Total questions'),
            'progresstext' => new external_value(PARAM_TEXT, 'Progress text'),
            'timeremaining' => new external_value(PARAM_INT, 'Seconds remaining', VALUE_OPTIONAL),
            'timeremainingdisplay' => new external_value(PARAM_TEXT, 'Formatted time remaining'),
            'hastimer' => new external_value(PARAM_BOOL, 'Has countdown timer'),
        ]);

        return new external_single_structure([
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'cmid' => new external_value(PARAM_INT, 'CM id'),
            'quizname' => new external_value(PARAM_TEXT, 'Quiz name'),
            'updatedat' => new external_value(PARAM_INT, 'Updated timestamp'),
            'totalstudents' => new external_value(PARAM_INT, 'Total students'),
            'hasstudents' => new external_value(PARAM_BOOL, 'Has students'),
            'summary' => new external_single_structure([
                'notstarted' => $statuscount,
                'inprogress' => $statuscount,
                'completed' => $statuscount,
            ]),
            'students' => new external_multiple_structure($student),
        ]);
    }

    /**
     * Convert manager state object to plain array for external API.
     *
     * @param \stdClass $state Monitor state.
     * @return array
     */
    protected static function export_state(\stdClass $state): array {
        $students = [];
        foreach ($state->students as $row) {
            $entry = [
                'userid' => $row->userid,
                'fullname' => $row->fullname,
                'email' => $row->email,
                'showemail' => (bool) $row->showemail,
                'status' => $row->status,
                'statuslabel' => $row->statuslabel,
                'statusclass' => $row->statusclass,
                'progressanswered' => $row->progressanswered,
                'progresstotal' => $row->progresstotal,
                'progresstext' => $row->progresstext,
                'timeremainingdisplay' => $row->timeremainingdisplay,
                'hastimer' => (bool) $row->hastimer,
            ];
            if ($row->attemptid !== null) {
                $entry['attemptid'] = $row->attemptid;
            }
            if ($row->timeremaining !== null) {
                $entry['timeremaining'] = (int) $row->timeremaining;
            }
            $students[] = $entry;
        }

        $summary = $state->summary;
        return [
            'quizid' => $state->quizid,
            'cmid' => $state->cmid,
            'quizname' => $state->quizname,
            'updatedat' => $state->updatedat,
            'totalstudents' => $state->totalstudents,
            'hasstudents' => (bool) $state->hasstudents,
            'summary' => [
                'notstarted' => (array) $summary->notstarted,
                'inprogress' => (array) $summary->inprogress,
                'completed' => (array) $summary->completed,
            ],
            'students' => $students,
        ];
    }
}
