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
 * Entity for a student supervision note.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\entity;

defined('MOODLE_INTERNAL') || die();

/**
 * Maps to {quiz_livequizmonitor_notes}.
 */
class student_note {
    /** @var int|null */
    public ?int $id = null;

    /** @var int */
    public int $quizid;

    /** @var int */
    public int $userid;

    /** @var string */
    public string $content = '';

    /** @var int */
    public int $timemodified = 0;

    /** @var int */
    public int $usermodified = 0;

    /**
     * Create from a database record.
     *
     * @param \stdClass $record Database row.
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        $note = new self();
        $note->id = (int) $record->id;
        $note->quizid = (int) $record->quizid;
        $note->userid = (int) $record->userid;
        $note->content = (string) $record->content;
        $note->timemodified = (int) $record->timemodified;
        $note->usermodified = (int) $record->usermodified;
        return $note;
    }
}
