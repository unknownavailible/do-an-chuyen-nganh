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

namespace mod_kanban\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for Kanban scheduled deadline notifications.
 *
 * @package    mod_kanban
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deadline_notifications_test extends \advanced_testcase {

    /**
     * Create a board and a card due within 48 hours.
     *
     * @return array
     */
    private function create_due_card(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $generator->enrol_user($student->id, $course->id, $studentrole->id);
        $module = $generator->create_module('kanban', [
            'course' => $course->id,
            'name' => 'Deadline test board',
        ]);
        $cm = get_coursemodule_from_instance('kanban', $module->id, $course->id, false, MUST_EXIST);
        $column = $DB->get_record('kanban_columns', [
            'kanbanid' => $module->id,
            'sortorder' => 0,
        ], '*', MUST_EXIST);
        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $module->id,
            'columnid' => $column->id,
            'groupid' => 0,
            'title' => 'Deadline reminder test',
            'description' => '',
            'assigned_to' => 0,
            'duedate' => time() + HOURSECS,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $card = $DB->get_record('kanban_cards', ['id' => $cardid], '*', MUST_EXIST);

        return [$student, $module, $cm, $card];
    }

    /**
     * Disabling notifications prevents the task from sending or recording reminders.
     */
    public function test_disabled_notifications_do_not_send_or_log(): void {
        global $DB;

        $this->resetAfterTest();
        [$student, $module, $cm, $card] = $this->create_due_card();
        $DB->insert_record('kanban_card_assignees', (object)[
            'cardid' => $card->id,
            'userid' => $student->id,
        ]);
        set_config('enablenotifications', 0, 'mod_kanban');
        $sink = $this->redirectMessages();

        (new deadline_notifications())->execute();

        $this->assertCount(0, $sink->get_messages());
        $this->assertFalse($DB->record_exists('kanban_card_history', [
            'cardid' => $card->id,
            'action' => 'deadline_warning',
        ]));
    }

    /**
     * Missing recipients do not consume the reminder; a successful send is deduplicated.
     */
    public function test_deadline_reminder_retries_after_assignment_and_is_not_resent(): void {
        global $DB;

        $this->resetAfterTest();
        [$student, $module, $cm, $card] = $this->create_due_card();
        set_config('enablenotifications', 1, 'mod_kanban');
        $sink = $this->redirectMessages();
        $task = new deadline_notifications();

        $task->execute();

        $this->assertCount(0, $sink->get_messages());
        $this->assertFalse($DB->record_exists('kanban_card_history', [
            'cardid' => $card->id,
            'action' => 'deadline_warning',
        ]));

        $DB->insert_record('kanban_card_assignees', (object)[
            'cardid' => $card->id,
            'userid' => $student->id,
        ]);
        $task->execute();

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame((int)$student->id, (int)$messages[0]->useridto);
        $this->assertTrue($DB->record_exists('kanban_card_history', [
            'cardid' => $card->id,
            'action' => 'deadline_warning',
        ]));

        $task->execute();

        $this->assertCount(1, $sink->get_messages());
        $this->assertSame(1, $DB->count_records('kanban_card_history', [
            'cardid' => $card->id,
            'action' => 'deadline_warning',
        ]));
    }
}
