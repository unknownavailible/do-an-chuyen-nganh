<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

header('Content-Type: application/json; charset=utf-8');

$kanbanid = optional_param('kanbanid', 0, PARAM_INT);
$columnid = optional_param('columnid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$title = optional_param('title', '', PARAM_TEXT);
$description = optional_param('description', '', PARAM_RAW);
$duedate = optional_param('duedate', 0, PARAM_INT);
$submissionurl = optional_param('submissionurl', '', PARAM_URL);
$assignees = optional_param_array('assignees', [], PARAM_INT);

if (!$cmid) {
    echo json_encode(['status' => false, 'message' => 'Missing cmid']);
    exit;
}

$cm = get_coursemodule_from_id('kanban', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/kanban:managecards', $context);
require_sesskey();

if (!$kanbanid || (int)$kanbanid !== (int)$cm->instance) {
    echo json_encode(['status' => false, 'message' => 'Invalid kanban instance']);
    exit;
}

$kanban = $DB->get_record('kanban', ['id' => $kanbanid], '*', MUST_EXIST);
$column = $DB->get_record('kanban_columns', ['id' => $columnid, 'kanbanid' => $kanban->id], '*', MUST_EXIST);
$currentgroup = groups_get_activity_group($cm, true);
if (!has_capability('moodle/site:accessallgroups', $context) && $currentgroup && !groups_is_member($currentgroup, $USER->id)) {
    echo json_encode(['status' => false, 'message' => 'You are not a member of the allowed group']);
    exit;
}

// Validate duedate
if ($duedate > 0 && $duedate < (time() - 300)) {
    echo json_encode(['status' => false, 'message' => get_string('error_duedate_past', 'mod_kanban')]);
    exit;
}

// Validate title (đồng nhất với update_card: không cho rỗng).
$title = trim((string)$title);
if ($title === '') {
    echo json_encode(['status' => false, 'message' => get_string('required', 'mod_kanban')]);
    exit;
}

kanban_check_wip_limit($column, $kanban->id);

global $DB, $USER;

$card = new stdClass();
$card->kanbanid = $kanbanid;
$card->columnid = $columnid;
$card->groupid = $currentgroup ?: 0;
$card->title = $title;
$card->description = $description;
$card->duedate = $duedate;
$card->task_url = $submissionurl;
$assigneeids = kanban_validate_assignee_list($cm, $assignees);
$card->assigned_to = !empty($assigneeids) ? (int)$assigneeids[0] : 0;
$maxorder = $DB->get_field_sql(
    'SELECT MAX(sortorder) FROM {kanban_cards} WHERE kanbanid = :kanbanid AND columnid = :columnid AND groupid = :groupid',
    ['kanbanid' => $kanban->id, 'columnid' => $column->id, 'groupid' => $currentgroup ? $currentgroup : 0]
);
$card->sortorder = ($maxorder === null || $maxorder === false) ? 0 : ((int)$maxorder + 1);
$card->timecreated = time();
$card->timemodified = time();

$cardid = $DB->insert_record('kanban_cards', $card);
kanban_set_card_assignees($cardid, $assigneeids);
\mod_kanban\event\card_created::create([
    'objectid' => $cardid,
    'context' => $context,
    'other' => ['kanbanid' => $kanban->id, 'columnid' => (int)$column->id],
])->trigger();
kanban_log_card_change($cardid, 'created', get_string('history_created', 'mod_kanban'));
$card->id = $cardid;
kanban_notify_card_assignees($card, $cm, 'assigned');

// Save uploaded files into file storage under itemid = cardid.
// PHP can expose multiple uploaded files as either "attachments" or "attachments[]" depending on how the request was built.
$fs = get_file_storage();
$filesaved = 0;
$uploadgroups = [];
foreach ($_FILES as $fieldname => $filedata) {
    if (stripos($fieldname, 'attach') !== false || $fieldname === 'attachments' || $fieldname === 'attachments[]') {
        $uploadgroups[] = $filedata;
    }
}

if (empty($uploadgroups) && !empty($_FILES['attachments'])) {
    $uploadgroups[] = $_FILES['attachments'];
}
if (empty($uploadgroups) && !empty($_FILES['attachments[]'])) {
    $uploadgroups[] = $_FILES['attachments[]'];
}

foreach ($uploadgroups as $attachments) {
    if (empty($attachments['name'])) {
        continue;
    }

    $names = is_array($attachments['name']) ? $attachments['name'] : [$attachments['name']];
    $tmpnames = is_array($attachments['tmp_name']) ? $attachments['tmp_name'] : [$attachments['tmp_name']];
    $errors = is_array($attachments['error']) ? $attachments['error'] : [$attachments['error']];

    foreach ($names as $index => $filename) {
        if (!isset($tmpnames[$index]) || empty($tmpnames[$index])) {
            continue;
        }
        if (!isset($errors[$index]) || (int)$errors[$index] !== UPLOAD_ERR_OK) {
            continue;
        }

        $cleanfilename = clean_param($filename, PARAM_FILE);
        if ($cleanfilename === '') {
            continue;
        }

        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'itemid' => $cardid,
            'filepath' => '/',
            'filename' => $cleanfilename,
        ];

        $fs->create_file_from_pathname($filerecord, $tmpnames[$index]);
        $filesaved++;
    }
}

echo json_encode(['status' => true, 'cardid' => $cardid, 'files' => $filesaved, 'message' => get_string('msg_card_created', 'mod_kanban')]);
exit;
