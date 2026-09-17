<?php

namespace mod_kanban\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

class deadline_notifications extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_deadline_notifications', 'mod_kanban');
    }

    public function execute() {
        global $DB;

        if (!get_config('mod_kanban', 'enablenotifications')) {
            return;
        }

        $now = time();
        $cards = $DB->get_records_select(
            'kanban_cards',
            'duedate > 0 AND duedate <= :limit',
            ['limit' => $now + (48 * HOURSECS)],
            'duedate ASC'
        );

        foreach ($cards as $card) {
            $column = $DB->get_record('kanban_columns', [
                'id' => $card->columnid,
                'kanbanid' => $card->kanbanid,
            ]);
            if (!$column || kanban_is_done_column($column, $card->kanbanid)) {
                continue;
            }

            $overdue = $card->duedate < $now;
            $action = $overdue ? 'deadline_overdue' : 'deadline_warning';
            if ($DB->record_exists('kanban_card_history', [
                'cardid' => $card->id,
                'action' => $action,
            ])) {
                continue;
            }

            $kanban = $DB->get_record('kanban', ['id' => $card->kanbanid]);
            if (!$kanban) {
                continue;
            }
            $cm = get_coursemodule_from_instance('kanban', $kanban->id, $kanban->course);
            if (!$cm) {
                continue;
            }

            kanban_notify_card_assignees($card, $cm, $overdue ? 'deadline_overdue' : 'deadline_warning');
            kanban_log_card_change($card->id, $action, get_string($overdue ? 'history_deadline_overdue' : 'history_deadline_warning', 'mod_kanban'));
        }
    }
}
