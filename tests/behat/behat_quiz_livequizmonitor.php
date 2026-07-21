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
 * Behat step definitions for quiz_livequizmonitor.
 *
 * @package   quiz_livequizmonitor
 * @category  test
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL used, this file may be required by behat before including /config.php.
require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

use Moodle\BehatExtension\Exception\SkippedException;

/**
 * Live quiz monitor Behat steps.
 *
 * @package   quiz_livequizmonitor
 * @category  test
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_quiz_livequizmonitor extends behat_base {
    /**
     * Open the live monitor report for a quiz by activity name.
     *
     * @When /^I am on the live monitor report for "(?P<quizname>[^"]*)"$/
     * @param string $quizname Quiz activity name.
     */
    public function i_am_on_the_live_monitor_report_for(string $quizname): void {
        global $DB;

        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $url = new \moodle_url('/mod/quiz/report.php', [
            'id' => $cm->id,
            'mode' => 'livequizmonitor',
        ]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Require quizaccess_onesession or skip scenario.
     */
    private function require_onesession_plugin(): void {
        if (!\quiz_livequizmonitor\local\manager\onesession_manager::is_plugin_installed()) {
            throw new SkippedException('quizaccess_onesession is not installed');
        }
    }

    /**
     * Enable onesession concurrent session rule for a quiz.
     *
     * @Given /^onesession concurrent session rule is enabled for quiz "(?P<quizname>[^"]*)"$/
     * @param string $quizname Quiz activity name.
     */
    public function onesession_concurrent_session_rule_is_enabled_for_quiz(string $quizname): void {
        global $DB;

        $this->require_onesession_plugin();

        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $record = $DB->get_record('quizaccess_onesession', ['quizid' => $quiz->id]);
        if ($record) {
            $record->enabled = 1;
            $DB->update_record('quizaccess_onesession', $record);
        } else {
            $DB->insert_record('quizaccess_onesession', (object) [
                'quizid' => $quiz->id,
                'enabled' => 1,
            ]);
        }
    }

    /**
     * Seed attempt_blocked event for the student's current in-progress attempt.
     *
     * @Given /^the student "(?P<username>[^"]*)" is blocked by onesession on quiz "(?P<quizname>[^"]*)"$/
     * @param string $username Student username.
     * @param string $quizname Quiz activity name.
     */
    public function the_student_is_blocked_by_onesession_on_quiz(string $username, string $quizname): void {
        global $DB;

        $this->require_onesession_plugin();

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $attempt = $DB->get_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'state' => \mod_quiz\quiz_attempt::IN_PROGRESS,
        ], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);

        // Calling quizaccess_onesession\event\attempt_blocked->trigger() here
        // doesn't add a database row so we fake it.
        $data = (object) [
            'eventname' => '\quizaccess_onesession\event\attempt_blocked',
            'component' => 'quizaccess_onesession',
            'action' => 'blocked',
            'target' => 'attempt',
            'objecttable' => 'quiz_attempts',
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'crud' => 'r',
            'edulevel' => 2,
            'contextid' => $context->id,
            'contextlevel' => 70,
            'contextinstanceid' => $context->instanceid,
            'userid' => $attempt->userid,
            'courseid' => $cm->course,
            'timecreated' => time(),
            'other' => json_encode(['quizid' => $quiz->id]),
        ];
        $DB->insert_record('logstore_standard_log', $data);
    }

    /**
     * Unenrol a user from a course by shortname (manual enrol plugin).
     *
     * @When /^I unenrol user "(?P<username>[^"]*)" from course "(?P<shortname>[^"]*)"$/
     * @param string $username Student username.
     * @param string $shortname Course shortname.
     */
    public function unenrol_user_from_course(string $username, string $shortname): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        $plugin->unenrol_user($instance, $user->id);
    }
}
