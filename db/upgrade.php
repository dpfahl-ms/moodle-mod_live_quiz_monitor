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
 * Upgrade script for the live quiz monitor report.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the live quiz monitor plugin.
 *
 * @param int $oldversion Previously installed version.
 * @return bool
 */
function xmldb_quiz_livequizmonitor_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2025040101) {
        if (!$DB->record_exists('quiz_reports', ['name' => 'livequizmonitor'])) {
            $record = new stdClass();
            $record->name = 'livequizmonitor';
            $record->displayorder = 9000;
            $record->capability = 'quiz/livequizmonitor:view';
            $DB->insert_record('quiz_reports', $record);
        }

        upgrade_plugin_savepoint(true, 2025040101, 'quiz', 'livequizmonitor');
    }

    if ($oldversion < 2025040102) {
        // Ensure report registration and capability are present after install/upgrade.
        if (!$DB->record_exists('quiz_reports', ['name' => 'livequizmonitor'])) {
            $record = new stdClass();
            $record->name = 'livequizmonitor';
            $record->displayorder = 9000;
            $record->capability = 'quiz/livequizmonitor:view';
            $DB->insert_record('quiz_reports', $record);
        } else {
            $record = $DB->get_record('quiz_reports', ['name' => 'livequizmonitor']);
            if ($record->capability !== 'quiz/livequizmonitor:view') {
                $record->capability = 'quiz/livequizmonitor:view';
                $DB->update_record('quiz_reports', $record);
            }
        }

        upgrade_plugin_savepoint(true, 2025040102, 'quiz', 'livequizmonitor');
    }

    if ($oldversion < 2025040103) {
        // Rename report plugin folder: underscores are stripped from mode= by PARAM_ALPHA in mod/quiz/report.php.
        $oldname = 'live_quiz_monitor';
        $newname = 'livequizmonitor';

        if ($DB->record_exists('quiz_reports', ['name' => $oldname])) {
            if (!$DB->record_exists('quiz_reports', ['name' => $newname])) {
                $record = $DB->get_record('quiz_reports', ['name' => $oldname]);
                $record->name = $newname;
                $record->capability = 'quiz/livequizmonitor:view';
                $DB->update_record('quiz_reports', $record);
            } else {
                $DB->delete_records('quiz_reports', ['name' => $oldname]);
            }
        }

        // Migrate admin settings from old component name if present.
        $oldpoll = $DB->get_record('config_plugins', [
            'plugin' => 'quiz_live_quiz_monitor',
            'name' => 'pollinterval',
        ]);
        if ($oldpoll && !$DB->record_exists('config_plugins', [
            'plugin' => 'quiz_livequizmonitor',
            'name' => 'pollinterval',
        ])) {
            $oldpoll->plugin = 'quiz_livequizmonitor';
            $DB->update_record('config_plugins', $oldpoll);
        }

        upgrade_plugin_savepoint(true, 2025040103, 'quiz', 'livequizmonitor');
    }

    return true;
}
