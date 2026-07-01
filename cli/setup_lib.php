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
 * Shared question setup helpers for CLI bootstrap scripts.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\question_version_status;

/**
 * Create a question category in the given context.
 *
 * @param int $contextid
 * @return stdClass
 */
function setup_create_question_category(int $contextid): stdClass {
    global $DB;

    $record = new stdClass();
    $record->name = 'Demo questions';
    $record->contextid = $contextid;
    $record->info = '';
    $record->infoformat = FORMAT_HTML;
    $record->stamp = make_unique_id_code();
    $record->parent = question_get_top_category($contextid, true)->id;
    $record->sortorder = 999;
    $record->id = $DB->insert_record('question_categories', $record);

    return $record;
}

/**
 * Save a question from form data without PHPUnit dependencies.
 *
 * @param string $qtype
 * @param int $categoryid
 * @param stdClass $form
 * @return stdClass
 */
function setup_save_question(string $qtype, int $categoryid, stdClass $form): stdClass {
    $form->category = $categoryid;
    $form->status = $form->status ?? question_version_status::QUESTION_STATUS_READY;

    $question = new stdClass();
    $question->category = $categoryid;
    $question->qtype = $qtype;
    $question->createdby = get_admin()->id;
    $question->modifiedby = get_admin()->id;

    return question_bank::get_qtype($qtype)->save_question($question, $form);
}

/**
 * Create demo questions and attach them to a quiz.
 *
 * @param stdClass $quiz Quiz record.
 * @param int $contextid Module context id.
 * @return int Number of questions added.
 */
function setup_add_demo_questions(stdClass $quiz, int $contextid): int {
    global $DB;

    $category = setup_create_question_category($contextid);
    $feedback = ['text' => 'Well done.', 'format' => FORMAT_HTML];
    $incorrect = ['text' => 'Incorrect.', 'format' => FORMAT_HTML];

    $definitions = [
        ['type' => 'shortanswer', 'name' => 'Name an amphibian', 'text' => 'Name an amphibian: __________'],
        ['type' => 'truefalse', 'name' => '2 + 2 equals 4', 'text' => '2 + 2 equals 4.'],
        ['type' => 'multichoice', 'name' => 'Pick the mammal', 'text' => 'Pick the mammal.'],
        ['type' => 'shortanswer', 'name' => 'Name a reptile', 'text' => 'Name a reptile: __________'],
        ['type' => 'truefalse', 'name' => 'The sky is blue', 'text' => 'The sky is blue.'],
        ['type' => 'multichoice', 'name' => 'Pick a prime number', 'text' => 'Which is a prime number?'],
        ['type' => 'shortanswer', 'name' => 'Capital of France', 'text' => 'Capital of France: __________'],
        ['type' => 'truefalse', 'name' => 'Water freezes at 0°C', 'text' => 'Water freezes at 0 degrees Celsius.'],
        ['type' => 'multichoice', 'name' => 'Pick a colour', 'text' => 'Which is a primary colour?'],
        ['type' => 'shortanswer', 'name' => 'Name a bird', 'text' => 'Name a bird: __________'],
    ];

    $slot = 0;
    $sumgrades = 0.0;

    foreach ($definitions as $definition) {
        if ($definition['type'] === 'shortanswer') {
            $question = setup_save_question('shortanswer', $category->id, (object) [
                'name' => $definition['name'],
                'questiontext' => ['text' => $definition['text'], 'format' => FORMAT_HTML],
                'generalfeedback' => ['text' => 'Any reasonable answer counts for the demo.', 'format' => FORMAT_HTML],
                'defaultmark' => 1.0,
                'usecase' => false,
                'answer' => ['frog', 'toad', 'paris', 'eagle', '*'],
                'fraction' => ['1.0', '0.8', '1.0', '1.0', '0.0'],
                'feedback' => [
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
                ],
            ]);
        } else if ($definition['type'] === 'truefalse') {
            $question = setup_save_question('truefalse', $category->id, (object) [
                'name' => $definition['name'],
                'questiontext' => ['text' => $definition['text'], 'format' => FORMAT_HTML],
                'generalfeedback' => ['text' => 'True or false.', 'format' => FORMAT_HTML],
                'defaultmark' => 1.0,
                'correctanswer' => '1',
                'feedbacktrue' => $feedback,
                'feedbackfalse' => $incorrect,
            ]);
        } else {
            $question = setup_save_question('multichoice', $category->id, (object) [
                'name' => $definition['name'],
                'questiontext' => ['text' => $definition['text'], 'format' => FORMAT_HTML],
                'generalfeedback' => ['text' => 'Pick one option.', 'format' => FORMAT_HTML],
                'defaultmark' => 1.0,
                'noanswers' => 4,
                'numhints' => 0,
                'penalty' => 0.3333333,
                'shuffleanswers' => 1,
                'answernumbering' => '123',
                'showstandardinstruction' => 0,
                'single' => '1',
                'correctfeedback' => $feedback,
                'partiallycorrectfeedback' => $feedback,
                'incorrectfeedback' => $incorrect,
                'shownumcorrect' => 1,
                'fraction' => ['1.0', '0.0', '0.0', '0.0'],
                'answer' => [
                    ['text' => 'One', 'format' => FORMAT_PLAIN],
                    ['text' => 'Two', 'format' => FORMAT_PLAIN],
                    ['text' => 'Three', 'format' => FORMAT_PLAIN],
                    ['text' => 'Four', 'format' => FORMAT_PLAIN],
                ],
                'feedback' => [
                    ['text' => 'Correct.', 'format' => FORMAT_HTML],
                    ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
                    ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
                    ['text' => 'Incorrect.', 'format' => FORMAT_HTML],
                ],
            ]);
        }

        quiz_add_quiz_question($question->id, $quiz, $slot, 1.0);
        $sumgrades += 1.0;
        $slot++;
    }

    $quiz->sumgrades = $sumgrades;
    $quiz->grade = 10;
    $DB->update_record('quiz', $quiz);

    return $slot;
}
