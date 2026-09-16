<?php

defined('MOODLE_INTERNAL') || die();

class restore_kanban_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('kanban', '/activity/kanban');
        $paths[] = new restore_path_element('column', '/activity/kanban/columns/column');
        $paths[] = new restore_path_element('card', '/activity/kanban/cards/card');
        $paths[] = new restore_path_element('assignee', '/activity/kanban/cards/card/assignees/assignee');
        $paths[] = new restore_path_element('member', '/activity/kanban/members/member');
        $paths[] = new restore_path_element('comment', '/activity/kanban/cards/card/comments/comment');
        $paths[] = new restore_path_element('history', '/activity/kanban/cards/card/history/entry');
        return $this->prepare_activity_structure($paths);
    }

    protected function process_kanban($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->creatorid = $this->map_userid($data->creatorid);
        if (!empty($data->assignmentid)) {
            $assign = $DB->get_record('assign', ['id' => (int)$data->assignmentid, 'course' => $data->course]);
            $data->assignmentid = $assign ? (int)$assign->id : 0;
        } else {
            $data->assignmentid = 0;
        }
        $newid = $DB->insert_record('kanban', $data);
        $this->apply_activity_instance($newid);
    }

    protected function process_column($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->kanbanid = $this->get_new_parentid('kanban');
        $newid = $DB->insert_record('kanban_columns', $data);
        $this->set_mapping('kanban_column', $oldid, $newid);
    }

    protected function process_card($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->kanbanid = $this->get_new_parentid('kanban');
        $data->columnid = $this->get_mappingid('kanban_column', $data->columnid);
        $data->groupid = $this->map_groupid($data->groupid);
        $data->assigned_to = $this->map_userid($data->assigned_to);
        $newid = $DB->insert_record('kanban_cards', $data);
        $this->set_mapping('kanban_card', $oldid, $newid, true);
    }

    protected function process_assignee($data) {
        global $DB;

        $data = (object)$data;
        $data->cardid = $this->get_mappingid('kanban_card', $data->cardid);
        $data->userid = $this->map_userid($data->userid);
        if ($data->cardid && $data->userid) {
            $DB->insert_record('kanban_card_assignees', $data);
        }
    }

    protected function process_member($data) {
        global $DB;

        $data = (object)$data;
        $data->kanbanid = $this->get_new_parentid('kanban');
        $data->userid = $this->map_userid($data->userid);
        $data->groupid = $this->map_groupid($data->groupid);
        if ($data->userid) {
            $DB->insert_record('kanban_members', $data);
        }
    }

    protected function process_comment($data) {
        global $DB;

        $data = (object)$data;
        $data->cardid = $this->get_mappingid('kanban_card', $data->cardid);
        $data->userid = $this->map_userid($data->userid);
        if ($data->cardid && $data->userid) {
            $DB->insert_record('kanban_card_comments', $data);
        }
    }

    protected function process_history($data) {
        global $DB;

        $data = (object)$data;
        $data->cardid = $this->get_mappingid('kanban_card', $data->cardid);
        $data->userid = $this->map_userid($data->userid);
        if ($data->cardid && $data->userid) {
            $DB->insert_record('kanban_card_history', $data);
        }
    }

    protected function map_userid($oldid) {
        if (empty($oldid)) {
            return 0;
        }
        $newid = $this->get_mappingid('user', $oldid);
        return $newid ? $newid : 0;
    }

    protected function map_groupid($oldid) {
        if (empty($oldid)) {
            return 0;
        }
        $newid = $this->get_mappingid('group', $oldid);
        return $newid ? $newid : 0;
    }

    protected function after_execute() {
        $this->add_related_files('mod_kanban', 'intro', null);
        $this->add_related_files('mod_kanban', 'card_attachments', 'kanban_card');
    }
}
