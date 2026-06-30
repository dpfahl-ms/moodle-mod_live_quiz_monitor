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
 * Privacy provider for quiz_livequizmonitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for student supervision notes.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Describe stored personal data.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('quiz_livequizmonitor_notes', [
            'userid' => 'privacy:metadata:notes:userid',
            'content' => 'privacy:metadata:notes:content',
            'timemodified' => 'privacy:metadata:notes:timemodified',
            'usermodified' => 'privacy:metadata:notes:usermodified',
        ], 'privacy:metadata:notes');

        return $collection;
    }

    /**
     * Find contexts containing user data.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modulelevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quiz_livequizmonitor_notes} n ON n.quizid = q.id
                 WHERE n.userid = :userid OR n.usermodified = :usermodified2";

        $params = [
            'modulelevel' => CONTEXT_MODULE,
            'modname' => 'quiz',
            'userid' => $userid,
            'usermodified2' => $userid,
        ];

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Export user data for a context.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('quiz', $context->instanceid);
            $records = $DB->get_records('quiz_livequizmonitor_notes', [
                'quizid' => $cm->instance,
                'userid' => $userid,
            ]);

            if (!$records) {
                continue;
            }

            $export = [];
            foreach ($records as $record) {
                $export[] = (object) [
                    'content' => $record->content,
                    'timemodified' => transform::datetime($record->timemodified),
                    'usermodified' => transform::user($record->usermodified),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'quiz_livequizmonitor')],
                (object) ['notes' => $export]
            );
        }
    }

    /**
     * Delete all data for all users in a context.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $DB->delete_records('quiz_livequizmonitor_notes', ['quizid' => $cm->instance]);
    }

    /**
     * Delete data for one user in a context.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('quiz', $context->instanceid);
            $DB->delete_records('quiz_livequizmonitor_notes', [
                'quizid' => $cm->instance,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Find users with data in a context.
     *
     * @param userlist $userlist User list.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $sql = "SELECT userid AS userid
                  FROM {quiz_livequizmonitor_notes}
                 WHERE quizid = :quizid
                UNION
                SELECT usermodified AS userid
                  FROM {quiz_livequizmonitor_notes}
                 WHERE quizid = :quizid2";

        $userlist->add_from_sql($sql, ['quizid' => $cm->instance, 'quizid2' => $cm->instance]);
    }

    /**
     * Delete data for users in a context.
     *
     * @param approved_userlist $userlist Approved user list.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid);
        $quizid = $cm->instance;

        foreach ($userlist->get_userids() as $userid) {
            $DB->delete_records('quiz_livequizmonitor_notes', [
                'quizid' => $quizid,
                'userid' => $userid,
            ]);
        }
    }
}
