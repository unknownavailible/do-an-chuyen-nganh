<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('kanban', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
$creator = !empty($kanban->creatorid) ? $DB->get_record('user', ['id' => $kanban->creatorid]) : false;

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/kanban:view', $context);

$PAGE->set_url('/mod/kanban/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($kanban->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$currentgroup = groups_get_activity_group($cm, true);
$columns = $DB->get_records('kanban_columns', ['kanbanid' => $kanban->id], 'sortorder ASC');

// Cột cuối cùng (thường là Done)
$donecol = end($columns);
$donecolid = $donecol ? $donecol->id : 0;

$column_data = [];
foreach ($columns as $col) {
    $params = ['kanbanid' => $kanban->id, 'columnid' => $col->id];
    if ($currentgroup) {
        $params['groupid'] = $currentgroup;
    }
    
    $cards = $DB->get_records('kanban_cards', $params, 'sortorder ASC');
    $comments_table_exists = $DB->get_manager()->table_exists('kanban_card_comments');
    $card_list = [];
    foreach ($cards as $card) {
        // Kiểm tra trễ hạn và sắp đến hạn
        $is_done = ($card->columnid == $donecolid);
        $is_overdue = (!$is_done && $card->duedate > 0 && $card->duedate < time());
        $is_due_soon = (!$is_done && !$is_overdue && $card->duedate > 0 && $card->duedate <= (time() + 86400 * 2)); // trong 48h
        $has_feedback = $comments_table_exists && $DB->count_records('kanban_card_comments', ['cardid' => $card->id]) > 0;
        $assigneenames = kanban_get_card_assignee_names($card->id);
        $assigneeids = kanban_get_card_assignee_ids($card->id);

        $card_list[] = [
            'id' => $card->id,
            'column_title' => format_string($col->title),
            'title' => format_string($card->title),
            'title_raw' => $card->title,
            'description' => format_text($card->description, FORMAT_MOODLE),
            'description_raw' => $card->description ?? '',
            'assignee_names' => $assigneenames,
            'assignee_ids' => implode(',', $assigneeids),
            'duedate_raw' => (int)($card->duedate ?? 0),
            'duedate_formatted' => $card->duedate ? userdate($card->duedate, '%d/%m/%Y %H:%M') : null,
            'is_overdue' => $is_overdue,
            'is_due_soon' => $is_due_soon,
            'has_feedback' => $has_feedback,
            'submissionurl' => !empty($card->task_url) ? $card->task_url : (!empty($card->submissionurl) ? $card->submissionurl : ''),
        ];
    }

    $column_data[] = [
        'id' => $col->id,
        'title' => format_string($col->title),
        'color' => $col->color,
        'description' => format_text($col->description ?? '', FORMAT_PLAIN),
        'card_count' => count($card_list),
        'wip_limit' => $col->wip_limit,
        'has_cards' => !empty($card_list),
        'cards' => $card_list
    ];
}

$linkedassignment = kanban_get_linked_assignment_info($kanban);
$assignmentcontext = null;
if ($linkedassignment) {
    $assignmentcontext = [
        'name' => $linkedassignment['name'],
        'url' => $linkedassignment['url'],
        'duedate_formatted' => $linkedassignment['duedate']
            ? userdate($linkedassignment['duedate'], '%d/%m/%Y %H:%M') : null,
    ];
}

$templatecontext = [
    'cmid' => $cm->id,
    'kanbanid' => $kanban->id,
    'kanbanname' => format_string($kanban->name),
    'linkedassignment' => $assignmentcontext,
    'creatorname' => $creator ? fullname($creator) : get_string('unknowncreator', 'mod_kanban'),
    'intro' => format_module_intro('kanban', $kanban, $cm->id),
    'can_manage' => has_capability('mod/kanban:managecards', $context),
    'can_manage_columns' => has_capability('mod/kanban:managecolumns', $context) ||
        has_capability('mod/kanban:viewdashboard', $context),
    'can_view_dashboard' => has_capability('mod/kanban:viewdashboard', $context),
    'dashboard_url' => (new moodle_url('/mod/kanban/dashboard.php', ['id' => $cm->id]))->out(),
    'assignee_members' => kanban_get_group_members_for_assignee_selection($cm),
    'columns' => $column_data
];

$PAGE->requires->js_call_amd('mod_kanban/board', 'init', [$cm->id, $kanban->id]);

echo $OUTPUT->header();
groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/kanban/view.php?id=' . $cm->id);
echo $OUTPUT->render_from_template('mod_kanban/board', $templatecontext);
echo $OUTPUT->footer();