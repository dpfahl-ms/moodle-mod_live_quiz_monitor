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
 * Live quiz monitor report.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use quiz_livequizmonitor\local\manager\monitor_manager;
use quiz_livequizmonitor\output\monitor_renderer;

/**
 * Live monitor quiz report.
 */
class quiz_livequizmonitor_report extends mod_quiz\local\reports\report_base {

    /**
     * Display the live monitor report.
     *
     * @param stdClass $quiz Quiz record.
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @return bool
     */
    public function display($quiz, $cm, $course): bool {
        global $OUTPUT, $PAGE;

        $context = context_module::instance($cm->id);
        require_capability('quiz/livequizmonitor:view', $context);

        $groupid = groups_get_activity_group($cm, true) ?: 0;
        $pollinterval = (int) get_config('quiz_livequizmonitor', 'pollinterval');
        if ($pollinterval <= 0) {
            $pollinterval = 5;
        }
        $pollinterval = max(3, min(30, $pollinterval));

        $PAGE->requires->css('/mod/quiz/report/livequizmonitor/styles.css?v=' . get_config('quiz_livequizmonitor', 'version'));

        $this->print_header_and_tabs($cm, $course, $quiz, 'livequizmonitor');

        $state = monitor_manager::get_state($course, $cm, $quiz, $groupid);

        /** @var monitor_renderer $renderer */
        $renderer = $PAGE->get_renderer('quiz_livequizmonitor', 'monitor');
        $templatecontext = $renderer->export_for_template($state, $pollinterval, $groupid);

        echo $OUTPUT->render_from_template('quiz_livequizmonitor/monitor_page', $templatecontext);

        return true;
    }
}
