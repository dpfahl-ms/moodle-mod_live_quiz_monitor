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
 * English language strings for quiz_livequizmonitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['emptycohort'] = 'No eligible students were found for this quiz.';
$string['extend:addtime'] = 'Add time';
$string['extend:bulklabel'] = 'Extend time';
$string['extend:confirm'] = 'Confirm — add {$a} min';
$string['extend:errornoinprogress'] = 'No students are currently in progress.';
$string['extend:errornopermission'] = 'You do not have permission to extend quiz time.';
$string['extend:mineach'] = '+{$a} min each';
$string['extend:modalbodybulk'] = 'Add time to all {$a->count} students currently taking the quiz. The quiz close time stays the same.';
$string['extend:modalbodyindividual'] = 'Grant {$a->name} extra time to finish their attempt. They will be notified instantly.';
$string['extend:modaltitle'] = 'Extend quiz time';
$string['extend:newdeadlinebulk'] = 'New deadline for {$a->count} students';
$string['extend:newdeadlineindividual'] = 'New deadline for this student';
$string['extend:rowaction'] = 'Extend time';
$string['extend:successbulk'] = 'Added {$a->minutes} minutes for {$a->count} students';
$string['extend:successindividual'] = 'Added {$a->minutes} minutes for {$a->name}';
$string['filter:all'] = 'All';
$string['filter:clear'] = 'Clear filters';
$string['filter:empty'] = 'No students match the current filters.';
$string['filter:searchplaceholder'] = 'Search students…';
$string['filter:toolbarlabel'] = 'Filter students by status';
$string['invalidminutes'] = 'Invalid extension duration.';
$string['invalidscope'] = 'Invalid extend scope.';
$string['lastupdated'] = 'Last updated: {$a}';
$string['liveindicator'] = 'Live';
$string['livequizmonitor:view'] = 'View the live quiz monitor report';
$string['livequizmonitor'] = 'Live Monitor';
$string['livequizmonitorreport'] = 'Live Monitor';
$string['message:timeextendedbody'] = 'Your teacher added {$a->minutes} minutes to your attempt for the quiz "{$a->quizname}".';
$string['message:timeextendedsmall'] = '+{$a} min added to your quiz attempt';
$string['message:timeextendedsubject'] = 'Extra time granted for {$a}';
$string['messageprovider:timeextended'] = 'Quiz time extended notification';
$string['missinguserid'] = 'A student must be selected for individual extend.';
$string['noattempttoextend'] = 'No in-progress attempt to extend for {$a}.';
$string['noextendablelimit'] = 'Cannot extend time for {$a} — no time limit applies.';
$string['pluginname'] = 'Live quiz monitor';
$string['privacy:metadata'] = 'The live quiz monitor report plugin does not store any personal data.';
$string['progressanswered'] = '{$a->answered} of {$a->total} answered';
$string['settings:pollinterval'] = 'Poll interval';
$string['settings:pollinterval_desc'] = 'How often the live monitor page refreshes data from the server (seconds).';
$string['staleindicator'] = 'Updates paused — showing last known data';
$string['status:completed'] = 'Completed';
$string['status:inprogress'] = 'In progress';
$string['status:notstarted'] = 'Not started';
$string['summary:completed'] = 'Completed';
$string['summary:inprogress'] = 'In progress';
$string['summary:notstarted'] = 'Not started';
$string['table:actions'] = 'Actions';
$string['table:email'] = 'Email';
$string['table:progress'] = 'Progress';
$string['table:status'] = 'Status';
$string['table:student'] = 'Student';
$string['table:timeremaining'] = 'Time remaining';
$string['timeup'] = 'Time up';
