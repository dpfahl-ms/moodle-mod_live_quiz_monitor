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
 * Shared helpers for separate-groups authorization tests.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\tests\traits;

use context_course;

/**
 * Trait for group-scoped PHPUnit fixtures.
 */
trait group_scope_test_trait {

    /**
     * Prohibit access-all-groups so teachers only see their own groups.
     *
     * @param int $courseid Course id.
     */
    protected function prohibit_access_all_groups(int $courseid): void {
        global $DB;

        $context = context_course::instance($courseid);
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $roleid, $context->id, true);
    }

    /**
     * Set the active activity group for the current user session.
     *
     * @param \stdClass $cm Course module.
     * @param int $groupid Group id.
     */
    protected function set_activity_group(\stdClass $cm, int $groupid): void {
        global $SESSION;

        $course = get_course($cm->course);
        $groupmode = groups_get_activity_groupmode($cm, $course);
        $context = \context_module::instance($cm->id);
        if (has_capability('moodle/site:accessallgroups', $context)) {
            $groupmode = 'aag';
        }

        if (!isset($SESSION->activegroup)) {
            $SESSION->activegroup = [];
        }
        if (!isset($SESSION->activegroup[$cm->course])) {
            $SESSION->activegroup[$cm->course] = [];
        }
        if (!isset($SESSION->activegroup[$cm->course][$groupmode])) {
            $SESSION->activegroup[$cm->course][$groupmode] = [];
        }

        $SESSION->activegroup[$cm->course][$groupmode][$cm->groupingid] = $groupid;
    }

    /**
     * Create a separate-groups course with teacher in Group A and students split across A/B.
     *
     * @return array<string, mixed>
     */
    protected function create_separate_groups_fixture(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $teacher = $generator->create_user();
        $studenta = $generator->create_user();
        $studentb = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($studenta->id, $course->id, 'student');
        $generator->enrol_user($studentb->id, $course->id, 'student');

        $this->prohibit_access_all_groups((int) $course->id);

        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        groups_add_member($groupa, $teacher);
        groups_add_member($groupa, $studenta);
        groups_add_member($groupb, $studentb);

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'groupmode' => SEPARATEGROUPS,
            'grade' => 100,
            'sumgrades' => 1,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => $context->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        return [
            'course' => $course,
            'quiz' => $quiz,
            'cm' => $cm,
            'context' => $context,
            'teacher' => $teacher,
            'studenta' => $studenta,
            'studentb' => $studentb,
            'groupa' => $groupa,
            'groupb' => $groupb,
        ];
    }
}
