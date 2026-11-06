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
 * Privacy subsystem implementation for mod_kanban.
 *
 * @package    mod_kanban
 * @category   privacy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_kanban\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider for Kanban activity data.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $items Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $items) : collection {
        $items->add_database_table('kanban', [
            'creatorid' => 'privacy:metadata:kanban:creatorid',
        ], 'privacy:metadata:kanban');
        $items->add_database_table('kanban_members', [
            'userid' => 'privacy:metadata:kanban_members:userid',
            'groupid' => 'privacy:metadata:kanban_members:groupid',
            'student_role' => 'privacy:metadata:kanban_members:student_role',
            'self_evaluation' => 'privacy:metadata:kanban_members:self_evaluation',
            'is_evaluated' => 'privacy:metadata:kanban_members:is_evaluated',
        ], 'privacy:metadata:kanban_members');
        $items->add_database_table('kanban_cards', [
            'assigned_to' => 'privacy:metadata:kanban_cards:assigned_to',
        ], 'privacy:metadata:kanban_cards');
        $items->add_database_table('kanban_card_assignees', [
            'userid' => 'privacy:metadata:kanban_card_assignees:userid',
        ], 'privacy:metadata:kanban_card_assignees');
        $items->add_database_table('kanban_card_comments', [
            'userid' => 'privacy:metadata:kanban_card_comments:userid',
            'comment' => 'privacy:metadata:kanban_card_comments:comment',
            'timecreated' => 'privacy:metadata:kanban_card_comments:timecreated',
        ], 'privacy:metadata:kanban_card_comments');
        $items->add_database_table('kanban_card_history', [
            'userid' => 'privacy:metadata:kanban_card_history:userid',
            'action' => 'privacy:metadata:kanban_card_history:action',
            'details' => 'privacy:metadata:kanban_card_history:details',
            'timecreated' => 'privacy:metadata:kanban_card_history:timecreated',
        ], 'privacy:metadata:kanban_card_history');
        $items->add_subsystem_link('core_files', [], 'privacy:metadata:files');

        return $items;
    }

    /**
     * Return activity contexts containing personal data for the user.
     *
     * @param int $userid User ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid) : contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {kanban} k ON k.id = cm.instance
                 WHERE k.creatorid = :creatorid
                    OR EXISTS (SELECT 1 FROM {kanban_members} km
                                WHERE km.kanbanid = k.id AND km.userid = :memberid)
                    OR EXISTS (SELECT 1 FROM {kanban_cards} card
                                WHERE card.kanbanid = k.id AND card.assigned_to = :legacyassignee)
                    OR EXISTS (SELECT 1 FROM {kanban_card_assignees} assignee
                                JOIN {kanban_cards} card ON card.id = assignee.cardid
                                WHERE card.kanbanid = k.id AND assignee.userid = :assigneeid)
                    OR EXISTS (SELECT 1 FROM {kanban_card_comments} kcmnt
                                JOIN {kanban_cards} card ON card.id = kcmnt.cardid
                                WHERE card.kanbanid = k.id AND kcmnt.userid = :commentuserid)
                    OR EXISTS (SELECT 1 FROM {kanban_card_history} kh
                                JOIN {kanban_cards} card ON card.id = kh.cardid
                                WHERE card.kanbanid = k.id AND kh.userid = :historyuserid)
                    OR EXISTS (SELECT 1 FROM {files} f
                                WHERE f.contextid = c.id AND f.component = :filecomponent
                                  AND f.filearea = :filearea AND f.userid = :fileuserid
                                  AND f.filename <> :directoryfilename)";
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'kanban',
            'creatorid' => $userid,
            'memberid' => $userid,
            'legacyassignee' => $userid,
            'assigneeid' => $userid,
            'commentuserid' => $userid,
            'historyuserid' => $userid,
            'filecomponent' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'fileuserid' => $userid,
            'directoryfilename' => '.',
        ];
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Add users with personal data in this activity context to the user list.
     *
     * @param userlist $userlist User list.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('kanban', $context->instanceid);
        if (!$cm) {
            return;
        }

        $sql = "SELECT creatorid AS userid FROM {kanban} WHERE id = :kanbanid AND creatorid > 0
                UNION SELECT userid FROM {kanban_members} WHERE kanbanid = :memberkanbanid
                UNION SELECT assigned_to AS userid FROM {kanban_cards}
                       WHERE kanbanid = :cardkanbanid AND assigned_to > 0
                UNION SELECT assignee.userid FROM {kanban_card_assignees} assignee
                       JOIN {kanban_cards} card ON card.id = assignee.cardid
                       WHERE card.kanbanid = :assigneekanbanid
                UNION SELECT kcmnt.userid FROM {kanban_card_comments} kcmnt
                       JOIN {kanban_cards} card ON card.id = kcmnt.cardid
                       WHERE card.kanbanid = :commentkanbanid
                UNION SELECT kh.userid FROM {kanban_card_history} kh
                       JOIN {kanban_cards} card ON card.id = kh.cardid
                       WHERE card.kanbanid = :historykanbanid
                UNION SELECT userid FROM {files}
                       WHERE contextid = :filecontextid AND component = :filecomponent
                         AND filearea = :filearea AND filename <> :directoryfilename AND userid > 0";
        $params = [
            'kanbanid' => $cm->instance,
            'memberkanbanid' => $cm->instance,
            'cardkanbanid' => $cm->instance,
            'assigneekanbanid' => $cm->instance,
            'commentkanbanid' => $cm->instance,
            'historykanbanid' => $cm->instance,
            'filecontextid' => $context->id,
            'filecomponent' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'directoryfilename' => '.',
        ];
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export personal data for approved activity contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $user = $contextlist->get_user();
        foreach ($contextlist as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('kanban', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
            $data = helper::get_context_data($context, $user);
            writer::with_context($context)->export_data([], $data);

            $membership = $DB->get_records('kanban_members', [
                'kanbanid' => $kanban->id,
                'userid' => $user->id,
            ]);
            if ($kanban->creatorid == $user->id) {
                writer::with_context($context)->export_data(['Created board'], (object)[
                    'name' => $kanban->name,
                    'timecreated' => \core_privacy\local\request\transform::datetime($kanban->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($kanban->timemodified),
                ]);
            }
            if ($membership) {
                foreach ($membership as $membershipid => $record) {
                    unset($record->id, $record->kanbanid, $record->userid);
                    writer::with_context($context)->export_data(['Membership', (string)$membershipid], $record);
                }
            }

            $cards = $DB->get_records_select(
                'kanban_cards',
                'kanbanid = :kanbanid AND (assigned_to = :userid OR id IN
                    (SELECT cardid FROM {kanban_card_assignees} WHERE userid = :assigneeid))',
                ['kanbanid' => $kanban->id, 'userid' => $user->id, 'assigneeid' => $user->id]
            );
            foreach ($cards as $card) {
                $carddata = clone $card;
                unset($carddata->id, $carddata->kanbanid, $carddata->assigned_to);
                $carddata->duedate = $card->duedate ?
                    \core_privacy\local\request\transform::datetime($card->duedate) : null;
                $carddata->timecreated = \core_privacy\local\request\transform::datetime($card->timecreated);
                $carddata->timemodified = \core_privacy\local\request\transform::datetime($card->timemodified);
                writer::with_context($context)->export_data(['Assigned cards', (string)$card->id], $carddata);
            }

            $comments = $DB->get_records_sql(
                "SELECT kcmnt.*, card.title AS cardtitle
                   FROM {kanban_card_comments} kcmnt
                   JOIN {kanban_cards} card ON card.id = kcmnt.cardid
                  WHERE card.kanbanid = :kanbanid AND kcmnt.userid = :userid",
                ['kanbanid' => $kanban->id, 'userid' => $user->id]
            );
            foreach ($comments as $comment) {
                $commentdata = (object)[
                    'card' => $comment->cardtitle,
                    'comment' => $comment->comment,
                    'timecreated' => \core_privacy\local\request\transform::datetime($comment->timecreated),
                ];
                writer::with_context($context)->export_data(['Comments', (string)$comment->id], $commentdata);
            }

            $history = $DB->get_records_sql(
                "SELECT kh.*, card.title AS cardtitle
                   FROM {kanban_card_history} kh
                   JOIN {kanban_cards} card ON card.id = kh.cardid
                  WHERE card.kanbanid = :kanbanid AND kh.userid = :userid",
                ['kanbanid' => $kanban->id, 'userid' => $user->id]
            );
            foreach ($history as $entry) {
                $historydata = (object)[
                    'card' => $entry->cardtitle,
                    'action' => $entry->action,
                    'details' => $entry->details,
                    'timecreated' => \core_privacy\local\request\transform::datetime($entry->timecreated),
                ];
                writer::with_context($context)->export_data(['History', (string)$entry->id], $historydata);
            }

            $fs = get_file_storage();
            foreach ($fs->get_area_files($context->id, 'mod_kanban', 'card_attachments', false,
                    'itemid, filepath, filename', false) as $file) {
                if (!$file->is_directory() && (int)$file->get_userid() === (int)$user->id) {
                    writer::with_context($context)->export_file(
                        ['Card attachments', (string)$file->get_itemid()],
                        $file
                    );
                }
            }

            helper::export_context_files($context, $user);
        }
    }

    /**
     * Delete all plugin data in the specified activity context.
     *
     * @param \context $context Context to delete.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('kanban', $context->instanceid);
        if (!$cm) {
            return;
        }

        self::delete_data_for_kanban((int)$cm->instance);
        get_file_storage()->delete_area_files($context->id, 'mod_kanban', 'card_attachments');
        get_file_storage()->delete_area_files($context->id, 'mod_kanban', 'intro');
        helper::delete_data_for_all_users_in_context('mod_kanban', $context);
    }

    /**
     * Delete the specified user's data from approved activity contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (!$contextlist->count()) {
            return;
        }

        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('kanban', $context->instanceid);
            if (!$cm) {
                continue;
            }
            self::delete_user_data_from_kanban((int)$cm->instance, $context, $userid);
        }

        helper::delete_data_for_user($contextlist);
    }

    /**
     * Delete the approved users' data in one context.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('kanban', $context->instanceid);
        if (!$cm) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_data_from_kanban((int)$cm->instance, $context, (int)$userid);
        }
    }

    /**
     * Remove the personal data stored for an entire Kanban board.
     *
     * @param int $kanbanid Kanban instance ID.
     */
    private static function delete_data_for_kanban(int $kanbanid) {
        global $DB;

        $DB->delete_records_select('kanban_card_comments', 'cardid IN
            (SELECT id FROM {kanban_cards} WHERE kanbanid = :kanbanid)', ['kanbanid' => $kanbanid]);
        $DB->delete_records_select('kanban_card_history', 'cardid IN
            (SELECT id FROM {kanban_cards} WHERE kanbanid = :historykanbanid)', ['historykanbanid' => $kanbanid]);
        $DB->delete_records_select('kanban_card_assignees', 'cardid IN
            (SELECT id FROM {kanban_cards} WHERE kanbanid = :assigneekanbanid)', ['assigneekanbanid' => $kanbanid]);
        $DB->set_field('kanban_cards', 'assigned_to', 0, ['kanbanid' => $kanbanid]);
        $DB->delete_records('kanban_members', ['kanbanid' => $kanbanid]);
        $DB->set_field('kanban', 'creatorid', 0, ['id' => $kanbanid]);
    }

    /**
     * Remove one user's personal data while preserving shared Kanban content.
     *
     * @param int $kanbanid Kanban instance ID.
     * @param \context_module $context Activity context.
     * @param int $userid User ID.
     */
    private static function delete_user_data_from_kanban(int $kanbanid, \context_module $context, int $userid) {
        global $DB;

        $DB->set_field('kanban', 'creatorid', 0, ['id' => $kanbanid, 'creatorid' => $userid]);
        $DB->delete_records('kanban_members', ['kanbanid' => $kanbanid, 'userid' => $userid]);
        $DB->set_field('kanban_cards', 'assigned_to', 0, ['kanbanid' => $kanbanid, 'assigned_to' => $userid]);
        $DB->delete_records_select('kanban_card_assignees', 'userid = :userid AND cardid IN
            (SELECT id FROM {kanban_cards} WHERE kanbanid = :kanbanid)', [
                'userid' => $userid,
                'kanbanid' => $kanbanid,
            ]);
        $DB->delete_records_select('kanban_card_comments', 'userid = :userid AND cardid IN
            (SELECT id FROM {kanban_cards} WHERE kanbanid = :kanbanid)', [
                'userid' => $userid,
                'kanbanid' => $kanbanid,
            ]);
        $DB->delete_records_select('kanban_card_history', 'userid = :userid AND cardid IN
            (SELECT id FROM {kanban_cards} WHERE kanbanid = :kanbanid)', [
                'userid' => $userid,
                'kanbanid' => $kanbanid,
            ]);

        $files = $DB->get_records_select('files',
            'contextid = :contextid AND component = :component AND filearea = :filearea
                AND userid = :userid AND filename <> :directoryfilename',
            [
                'contextid' => $context->id,
                'component' => 'mod_kanban',
                'filearea' => 'card_attachments',
                'userid' => $userid,
                'directoryfilename' => '.',
            ],
            '',
            'id'
        );
        $fs = get_file_storage();
        foreach ($files as $filerecord) {
            $file = $fs->get_file_by_id($filerecord->id);
            if ($file) {
                $file->delete();
            }
        }
        $DB->set_field_select(
            'files',
            'userid',
            null,
            'contextid = :contextid AND component = :component AND filearea = :filearea
                AND userid = :userid AND filename = :directoryfilename',
            [
                'contextid' => $context->id,
                'component' => 'mod_kanban',
                'filearea' => 'card_attachments',
                'userid' => $userid,
                'directoryfilename' => '.',
            ]
        );
    }
}
