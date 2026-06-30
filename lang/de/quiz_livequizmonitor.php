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
 * German language strings for quiz_livequizmonitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['emptycohort'] = 'Für dieses Quiz wurden keine berechtigten Teilnehmenden gefunden.';
$string['extend:addtime'] = 'Zeit hinzufügen';
$string['extend:bulklabel'] = 'Zeit verlängern';
$string['extend:confirm'] = 'Bestätigen — {$a} Min. hinzufügen';
$string['extend:errornoinprogress'] = 'Derzeit sind keine Teilnehmenden in Bearbeitung.';
$string['extend:errornopermission'] = 'Sie haben keine Berechtigung, die Quiz-Zeit zu verlängern.';
$string['extend:mineach'] = '+{$a} Min. je Person';
$string['extend:modalbodybulk'] = 'Allen {$a->count} Teilnehmenden, die das Quiz gerade bearbeiten, wird Zeit hinzugefügt. Die Quiz-Schließzeit bleibt unverändert.';
$string['extend:modalbodyindividual'] = '{$a->name} erhält zusätzliche Zeit zum Abschluss des Versuchs. Die Person wird sofort benachrichtigt.';
$string['extend:modaltitle'] = 'Quiz-Zeit verlängern';
$string['extend:newdeadlinebulk'] = 'Neue Frist für {$a->count} Teilnehmende';
$string['extend:newdeadlineindividual'] = 'Neue Frist für diese Person';
$string['extend:rowaction'] = 'Zeit verlängern';
$string['extend:successbulk'] = '{$a->minutes} Minuten für {$a->count} Teilnehmende hinzugefügt';
$string['extend:successindividual'] = '{$a->minutes} Minuten für {$a->name} hinzugefügt';
$string['filter:all'] = 'Alle';
$string['filter:clear'] = 'Filter zurücksetzen';
$string['filter:empty'] = 'Keine Teilnehmenden entsprechen den aktuellen Filtern.';
$string['filter:searchplaceholder'] = 'Teilnehmende suchen…';
$string['filter:toolbarlabel'] = 'Teilnehmende nach Status filtern';
$string['invalidminutes'] = 'Ungültige Verlängerungsdauer.';
$string['invalidscope'] = 'Ungültiger Verlängerungsumfang.';
$string['lastupdated'] = 'Zuletzt aktualisiert: {$a}';
$string['liveindicator'] = 'Live';
$string['livequizmonitor:view'] = 'Live-Quiz-Monitor-Bericht anzeigen';
$string['livequizmonitor'] = 'Live-Monitor';
$string['livequizmonitorreport'] = 'Live-Monitor';
$string['message:timeextendedbody'] = 'Ihre Lehrperson hat {$a->minutes} Minuten zu Ihrem Versuch für das Quiz „{$a->quizname}“ hinzugefügt.';
$string['message:timeextendedsmall'] = '+{$a} Min. zu Ihrem Quiz-Versuch hinzugefügt';
$string['message:timeextendedsubject'] = 'Zusätzliche Zeit für {$a}';
$string['messageprovider:timeextended'] = 'Benachrichtigung über verlängerte Quiz-Zeit';
$string['missinguserid'] = 'Für die Einzelverlängerung muss eine Person ausgewählt werden.';
$string['noattempttoextend'] = 'Kein laufender Versuch zum Verlängern für {$a}.';
$string['noextendablelimit'] = 'Zeit kann für {$a} nicht verlängert werden — es gilt kein Zeitlimit.';
$string['pluginname'] = 'Live-Quiz-Monitor';
$string['privacy:metadata'] = 'Das Live-Quiz-Monitor-Berichtsplugin speichert keine personenbezogenen Daten.';
$string['progressanswered'] = '{$a->answered} von {$a->total} beantwortet';
$string['settings:pollinterval'] = 'Aktualisierungsintervall';
$string['settings:pollinterval_desc'] = 'Wie oft die Live-Monitor-Seite Daten vom Server abruft (Sekunden).';
$string['staleindicator'] = 'Aktualisierung pausiert — letzte bekannte Daten werden angezeigt';
$string['status:completed'] = 'Abgeschlossen';
$string['status:inprogress'] = 'In Bearbeitung';
$string['status:notstarted'] = 'Nicht begonnen';
$string['summary:completed'] = 'Abgeschlossen';
$string['summary:inprogress'] = 'In Bearbeitung';
$string['summary:notstarted'] = 'Nicht begonnen';
$string['table:actions'] = 'Aktionen';
$string['table:email'] = 'E-Mail';
$string['table:progress'] = 'Fortschritt';
$string['table:status'] = 'Status';
$string['table:student'] = 'Teilnehmende';
$string['table:timeremaining'] = 'Verbleibende Zeit';
$string['timeup'] = 'Zeit abgelaufen';
