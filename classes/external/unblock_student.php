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
 * External API to unblock a student quiz attempt from live monitor.
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
use mod_quiz\quiz_attempt;
use quiz_livequizmonitor\local\manager\monitor_manager;
use quiz_livequizmonitor\local\manager\onesession_manager;

/**
 * Unblocks a student attempt locked by quizaccess_onesession.
 */
class unblock_student extends external_api {

    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'userid' => new external_value(PARAM_INT, 'Target student user id'),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id to unblock'),
        ]);
    }

    /**
     * Execute unblock for one student attempt.
     *
     * @param int $cmid Course module id.
     * @param int $userid Student user id.
     * @param int $attemptid Attempt id.
     * @return array
     */
    public static function execute(int $cmid, int $userid, int $attemptid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('quiz/livequizmonitor:view', $context);

        if (!onesession_manager::is_active_for_quiz((int) $quiz->id, $quiz)) {
            throw new \moodle_exception('onesession:notactive', 'quiz_livequizmonitor');
        }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        if ((int) $attempt->userid !== (int) $params['userid'] || (int) $attempt->quiz !== (int) $quiz->id) {
            throw new \invalid_parameter_exception('Invalid attempt for student');
        }
        if (!in_array($attempt->state, [quiz_attempt::IN_PROGRESS, quiz_attempt::OVERDUE], true)) {
            throw new \moodle_exception('onesession:errnotinprogress', 'quiz_livequizmonitor');
        }

        onesession_manager::unblock_attempt((int) $params['attemptid'], $context);

        return ['success' => true];
    }

    /**
     * Return structure description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether unblock succeeded'),
        ]);
    }
}
