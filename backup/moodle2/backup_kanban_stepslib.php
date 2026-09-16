<?php

defined('MOODLE_INTERNAL') || die();

class backup_kanban_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $kanban = new backup_nested_element('kanban', ['id'], [
            'course', 'creatorid', 'name', 'intro', 'introformat', 'timecreated', 'timemodified'
        ]);

        $columns = new backup_nested_element('columns');
        $column = new backup_nested_element('column', ['id'], [
            'kanbanid', 'title', 'color', 'sortorder', 'wip_limit'
        ]);

        $cards = new backup_nested_element('cards');
        $card = new backup_nested_element('card', ['id'], [
            'kanbanid', 'columnid', 'groupid', 'title', 'description', 'assigned_to',
            'duedate', 'sortorder', 'time_allocated', 'time_spent', 'task_url',
            'timecreated', 'timemodified'
        ]);

        $assignees = new backup_nested_element('assignees');
        $assignee = new backup_nested_element('assignee', ['id'], ['cardid', 'userid']);

        $members = new backup_nested_element('members');
        $member = new backup_nested_element('member', ['id'], [
            'kanbanid', 'userid', 'groupid', 'student_role', 'self_evaluation', 'is_evaluated'
        ]);

        $comments = new backup_nested_element('comments');
        $comment = new backup_nested_element('comment', ['id'], [
            'cardid', 'userid', 'comment', 'timecreated'
        ]);

        $history = new backup_nested_element('history');
        $historyentry = new backup_nested_element('entry', ['id'], [
            'cardid', 'userid', 'action', 'details', 'timecreated'
        ]);

        $kanban->add_child($columns);
        $columns->add_child($column);
        $kanban->add_child($cards);
        $cards->add_child($card);
        $card->add_child($assignees);
        $assignees->add_child($assignee);
        $kanban->add_child($members);
        $members->add_child($member);
        $card->add_child($comments);
        $comments->add_child($comment);
        $card->add_child($history);
        $history->add_child($historyentry);

        $column->set_source_table('kanban_columns', ['kanbanid' => backup::VAR_PARENTID]);
        $card->set_source_table('kanban_cards', ['kanbanid' => backup::VAR_PARENTID]);
        $assignee->set_source_table('kanban_card_assignees', ['cardid' => backup::VAR_PARENTID]);
        $member->set_source_table('kanban_members', ['kanbanid' => backup::VAR_PARENTID]);
        $comment->set_source_table('kanban_card_comments', ['cardid' => backup::VAR_PARENTID]);
        $historyentry->set_source_table('kanban_card_history', ['cardid' => backup::VAR_PARENTID]);
        $kanban->set_source_table('kanban', ['id' => backup::VAR_ACTIVITYID]);

        $kanban->annotate_files('mod_kanban', 'intro', null);
        $card->annotate_files('mod_kanban', 'card_attachments', 'id');

        return $this->prepare_activity_structure($kanban);
    }
}
