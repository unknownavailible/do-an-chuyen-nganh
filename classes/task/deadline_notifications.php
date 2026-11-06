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
        $this->send_existing_deadline_notifications($now);
        $this->send_group_progress_notifications($now);
    }

    /**
     * Preserve the existing 48-hour and overdue notifications for assigned users.
     *
     * @param int $now Current timestamp.
     * @return void
     */
    private function send_existing_deadline_notifications($now) {
        global $DB;

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

            $sent = kanban_notify_card_assignees(
                $card,
                $cm,
                $overdue ? 'deadline_overdue' : 'deadline_warning'
            );
            if (!$sent) {
                continue;
            }
            kanban_log_card_change(
                $card->id,
                $action,
                get_string($overdue ? 'history_deadline_overdue' : 'history_deadline_warning', 'mod_kanban')
            );
        }
    }

    /**
     * Send one group notification for cards reaching a progress or urgent-deadline threshold.
     *
     * @param int $now Current timestamp.
     * @return void
     */
    private function send_group_progress_notifications($now) {
        global $DB;

        $midpointgroups = [];
        $urgentgroups = [];
        $cards = $DB->get_records_select('kanban_cards', 'duedate > :now', ['now' => $now], 'duedate ASC');
        $columnidsbykanban = [];

        foreach ($cards as $card) {
            $kanbanid = (int)$card->kanbanid;
            if (!array_key_exists($kanbanid, $columnidsbykanban)) {
                $columnidsbykanban[$kanbanid] = kanban_get_workflow_column_ids($kanbanid);
            }
            $columnids = $columnidsbykanban[$kanbanid];
            $created = (int)$card->timecreated;
            $duedate = (int)$card->duedate;
            $duration = $duedate - $created;
            if (empty($columnids) || $created <= 0 || $duration <= 0) {
                continue;
            }

            $groupid = (int)$card->groupid;
            $groupkey = $kanbanid . ':' . $groupid;
            if ((int)$card->columnid === $columnids['inprogress'] &&
                    $now >= $created + (int)ceil($duration / 2) &&
                    !$DB->record_exists('kanban_card_history', [
                        'cardid' => $card->id,
                        'action' => 'progress_midpoint_group',
                    ])) {
                if (!isset($midpointgroups[$groupkey])) {
                    $midpointgroups[$groupkey] = [
                        'kanbanid' => $kanbanid,
                        'groupid' => $groupid,
                        'cards' => [],
                    ];
                }
                $midpointgroups[$groupkey]['cards'][] = $card;
            }

            $remaining = $duedate - $now;
            if ($remaining > 0 && $remaining <= $duration * 0.1 &&
                    !$DB->record_exists('kanban_card_history', [
                        'cardid' => $card->id,
                        'action' => 'deadline_urgent_group',
                    ])) {
                if (!isset($urgentgroups[$groupkey])) {
                    $urgentgroups[$groupkey] = [
                        'kanbanid' => $kanbanid,
                        'groupid' => $groupid,
                        'cards' => [],
                        'remaining' => $remaining,
                    ];
                }
                $urgentgroups[$groupkey]['cards'][] = $card;
                $urgentgroups[$groupkey]['remaining'] = min($urgentgroups[$groupkey]['remaining'], $remaining);
            }
        }

        foreach ($midpointgroups as $group) {
            $columnids = $columnidsbykanban[$group['kanbanid']];
            $progresscards = $DB->get_records('kanban_cards', [
                'kanbanid' => $group['kanbanid'],
                'groupid' => $group['groupid'],
                'columnid' => $columnids['inprogress'],
            ], 'duedate ASC, id ASC');
            if (empty($progresscards)) {
                continue;
            }

            $names = array_map(function($card) {
                return format_string($card->title);
            }, $progresscards);
            $this->notify_group_and_log(
                $group,
                'deadline',
                get_string('message_progress_midpoint_subject', 'mod_kanban'),
                get_string('message_progress_midpoint', 'mod_kanban', implode(', ', $names)),
                'progress_midpoint_group'
            );
        }

        foreach ($urgentgroups as $group) {
            $columnids = $columnidsbykanban[$group['kanbanid']];
            $pendingcards = $DB->get_records_select(
                'kanban_cards',
                'kanbanid = :kanbanid AND groupid = :groupid AND (columnid = :todoid OR columnid = :progressid)',
                [
                    'kanbanid' => $group['kanbanid'],
                    'groupid' => $group['groupid'],
                    'todoid' => $columnids['todo'],
                    'progressid' => $columnids['inprogress'],
                ],
                'duedate ASC, id ASC'
            );
            if (empty($pendingcards)) {
                continue;
            }

            $names = array_map(function($card) {
                return format_string($card->title);
            }, $pendingcards);
            $messageargs = (object)[
                'time' => format_time($group['remaining']),
                'cards' => implode(', ', $names),
            ];
            $this->notify_group_and_log(
                $group,
                'deadline',
                get_string('message_remaining_deadline_subject', 'mod_kanban'),
                get_string('message_remaining_deadline', 'mod_kanban', $messageargs),
                'deadline_urgent_group'
            );
        }
    }

    /**
     * Send a message and record the notification action on each threshold card.
     *
     * @param array $group Group and threshold-card data.
     * @param string $provider Moodle message provider.
     * @param string $subject Notification subject.
     * @param string $messagebody Plain-text message.
     * @param string $action History action used for deduplication.
     * @return void
     */
    private function notify_group_and_log($group, $provider, $subject, $messagebody, $action) {
        global $DB;

        $kanban = $DB->get_record('kanban', ['id' => $group['kanbanid']]);
        if (!$kanban) {
            return;
        }
        $cm = get_coursemodule_from_instance('kanban', $kanban->id, $kanban->course);
        if (!$cm) {
            return;
        }

        $sent = kanban_notify_group_members(
            $kanban,
            $cm,
            $group['groupid'],
            $provider,
            $subject,
            $messagebody
        );
        if (!$sent) {
            return;
        }

        foreach ($group['cards'] as $card) {
            kanban_log_card_change($card->id, $action, $subject);
        }
    }
}
