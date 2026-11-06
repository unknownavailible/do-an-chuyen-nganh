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
 * Privacy provider tests.
 *
 * @package    mod_kanban
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_kanban\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the Kanban privacy provider.
 */
final class provider_test extends \core_privacy\tests\provider_testcase {

    /**
     * Create a board and its module context.
     *
     * @return array
     */
    private function create_board(): array {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $creator = $generator->create_user();
        $this->setUser($creator);
        $module = $generator->create_module('kanban', [
            'course' => $course->id,
            'name' => 'Privacy test board',
        ]);
        $cm = get_coursemodule_from_instance('kanban', $module->id, $course->id, false, MUST_EXIST);
        $column = $DB->get_record('kanban_columns', [
            'kanbanid' => $module->id,
            'sortorder' => 0,
        ], '*', MUST_EXIST);
        $otheruser = $generator->create_user();

        return [$creator, $otheruser, $module, $cm, $column];
    }

    /**
     * The provider describes personal data across Kanban tables.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_kanban'));
        $tables = $collection->get_collection();
        $this->assertCount(7, $tables);
        $fieldsbytable = [];
        foreach ($tables as $table) {
            $fieldsbytable[$table->get_name()] = $table->get_privacy_fields();
        }
        $this->assertArrayHasKey('creatorid', $fieldsbytable['kanban']);
        $this->assertArrayHasKey('self_evaluation', $fieldsbytable['kanban_members']);
        $this->assertArrayHasKey('assigned_to', $fieldsbytable['kanban_cards']);
        $this->assertArrayHasKey('comment', $fieldsbytable['kanban_card_comments']);
        $this->assertArrayHasKey('details', $fieldsbytable['kanban_card_history']);
    }

    /**
     * Personal data is discoverable and exportable from the activity context.
     */
    public function test_contexts_userlist_and_export(): void {
        global $DB;

        [$creator, $otheruser, $module, $cm, $column] = $this->create_board();
        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $module->id,
            'columnid' => $column->id,
            'groupid' => 0,
            'title' => 'Assigned task',
            'description' => 'Task details',
            'assigned_to' => $otheruser->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('kanban_card_comments', (object)[
            'cardid' => $cardid,
            'userid' => $otheruser->id,
            'comment' => 'Personal comment',
            'timecreated' => time(),
        ]);
        $context = \context_module::instance($cm->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'itemid' => $cardid,
            'userid' => $otheruser->id,
            'filepath' => '/',
            'filename' => 'personal.txt',
        ], 'Personal attachment');

        $contexts = provider::get_contexts_for_userid($otheruser->id);
        $this->assertCount(1, $contexts);
        $this->assertSame($context->id, $contexts->current()->id);
        $creatorcontexts = provider::get_contexts_for_userid($creator->id);
        $this->assertCount(1, $creatorcontexts);

        $userlist = new userlist($context, 'mod_kanban');
        provider::get_users_in_context($userlist);
        $this->assertContains((int)$creator->id, $userlist->get_userids());
        $this->assertContains((int)$otheruser->id, $userlist->get_userids());

        $this->export_context_data_for_user($otheruser->id, $context, 'mod_kanban');
        $writer = \core_privacy\local\request\writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Deleting one user preserves the shared card and other users' data.
     */
    public function test_delete_data_for_user_preserves_other_users_and_shared_content(): void {
        global $DB;

        [$creator, $otheruser, $module, $cm, $column] = $this->create_board();
        $thirduser = $this->getDataGenerator()->create_user();
        $DB->insert_record('kanban_members', (object)[
            'kanbanid' => $module->id,
            'userid' => $otheruser->id,
            'groupid' => 0,
            'student_role' => 'Member',
            'self_evaluation' => 'Personal evaluation',
            'is_evaluated' => 0,
        ]);
        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $module->id,
            'columnid' => $column->id,
            'groupid' => 0,
            'title' => 'Shared task',
            'description' => 'Keep this shared content',
            'assigned_to' => $otheruser->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('kanban_card_assignees', (object)[
            'cardid' => $cardid,
            'userid' => $otheruser->id,
        ]);
        $DB->insert_record('kanban_card_assignees', (object)[
            'cardid' => $cardid,
            'userid' => $thirduser->id,
        ]);
        $DB->insert_record('kanban_card_comments', (object)[
            'cardid' => $cardid,
            'userid' => $otheruser->id,
            'comment' => 'Delete this comment',
            'timecreated' => time(),
        ]);
        $DB->insert_record('kanban_card_comments', (object)[
            'cardid' => $cardid,
            'userid' => $thirduser->id,
            'comment' => 'Keep the other comment',
            'timecreated' => time(),
        ]);
        $DB->insert_record('kanban_card_history', (object)[
            'cardid' => $cardid,
            'userid' => $otheruser->id,
            'action' => 'updated',
            'details' => 'Delete this history entry',
            'timecreated' => time(),
        ]);
        $DB->insert_record('kanban_card_history', (object)[
            'cardid' => $cardid,
            'userid' => $thirduser->id,
            'action' => 'updated',
            'details' => 'Keep the other history entry',
            'timecreated' => time(),
        ]);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'itemid' => $cardid,
            'userid' => $otheruser->id,
            'filepath' => '/',
            'filename' => 'personal.txt',
        ], 'Personal attachment');
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'itemid' => $cardid,
            'userid' => $thirduser->id,
            'filepath' => '/',
            'filename' => 'other-user.txt',
        ], 'Other user attachment');
        $contextlist = new approved_contextlist($otheruser, 'mod_kanban', [$context->id]);

        provider::delete_data_for_user($contextlist);

        $card = $DB->get_record('kanban_cards', ['id' => $cardid], '*', MUST_EXIST);
        $this->assertSame('Shared task', $card->title);
        $this->assertSame(0, (int)$card->assigned_to);
        $this->assertFalse($DB->record_exists('kanban_members', [
            'kanbanid' => $module->id,
            'userid' => $otheruser->id,
        ]));
        $this->assertFalse($DB->record_exists('kanban_card_assignees', [
            'cardid' => $cardid,
            'userid' => $otheruser->id,
        ]));
        $this->assertTrue($DB->record_exists('kanban_card_assignees', [
            'cardid' => $cardid,
            'userid' => $thirduser->id,
        ]));
        $this->assertFalse($DB->record_exists('kanban_card_comments', [
            'cardid' => $cardid,
            'userid' => $otheruser->id,
        ]));
        $this->assertTrue($DB->record_exists('kanban_card_comments', [
            'cardid' => $cardid,
            'userid' => $thirduser->id,
        ]));
        $this->assertFalse($DB->record_exists('kanban_card_history', [
            'cardid' => $cardid,
            'userid' => $otheruser->id,
        ]));
        $this->assertTrue($DB->record_exists('kanban_card_history', [
            'cardid' => $cardid,
            'userid' => $thirduser->id,
        ]));
        $this->assertFalse($fs->get_file(
            $context->id,
            'mod_kanban',
            'card_attachments',
            $cardid,
            '/',
            'personal.txt'
        ));
        $this->assertNotFalse($fs->get_file(
            $context->id,
            'mod_kanban',
            'card_attachments',
            $cardid,
            '/',
            'other-user.txt'
        ));
        $this->assertFalse($DB->record_exists('files', [
            'contextid' => $context->id,
            'component' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'userid' => $otheruser->id,
        ]));

        provider::delete_data_for_user(new approved_contextlist($creator, 'mod_kanban', [$context->id]));
        $board = $DB->get_record('kanban', ['id' => $module->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$board->creatorid);
        $this->assertSame('Shared task', $DB->get_field('kanban_cards', 'title', ['id' => $cardid]));
    }
}
