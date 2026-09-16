<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Thêm một bài tập Kanban mới vào khóa học.
 * Tự động tạo 3 cột mặc định: To Do, In Progress, Done.
 *
 * @param stdClass $kanban Dữ liệu gửi từ mod_form
 * @param mod_kanban_mod_form $mform
 * @return int ID của bản ghi kanban vừa tạo
 */
function kanban_add_instance($kanban, $mform = null) {
    global $DB, $USER;

    $kanban->creatorid = $USER->id;
    $kanban->assignmentid = kanban_validate_linked_assignment(
        isset($kanban->assignmentid) ? (int)$kanban->assignmentid : 0,
        isset($kanban->course) ? (int)$kanban->course : 0
    );
    $kanban->timecreated = time();
    $kanban->timemodified = time();

    // 1. Lưu bản ghi chính vào bảng mdl_kanban
    $kanbanid = $DB->insert_record('kanban', $kanban);

    // 2. Tự động khởi tạo 3 cột mặc định cho bảng Kanban này
    $defaultcolumns = [
        [
            'title' => get_string('column_todo', 'mod_kanban'),
            'color' => '#f1f2f4',
            'sortorder' => 0,
            'wip_limit' => 0
        ],
        [
            'title' => get_string('column_inprogress', 'mod_kanban'),
            'color' => '#e9f2ff',
            'sortorder' => 1,
            'wip_limit' => 3 // Ví dụ giới hạn 3 việc đang làm
        ],
        [
            'title' => get_string('column_done', 'mod_kanban'),
            'color' => '#e3fcef',
            'sortorder' => 2,
            'wip_limit' => 0
        ]
    ];

    foreach ($defaultcolumns as $col) {
        $columnrecord = new stdClass();
        $columnrecord->kanbanid = $kanbanid;
        $columnrecord->title = $col['title'];
        $columnrecord->color = $col['color'];
        $columnrecord->sortorder = $col['sortorder'];
        $columnrecord->wip_limit = $col['wip_limit'];

        $DB->insert_record('kanban_columns', $columnrecord);
    }

    return $kanbanid;
}

/**
 * Cập nhật thông tin bài tập Kanban khi giảng viên chỉnh sửa.
 *
 * @param stdClass $kanban
 * @param mod_kanban_mod_form $mform
 * @return bool
 */
function kanban_update_instance($kanban, $mform = null) {
    global $DB;

    $kanban->timemodified = time();
    $kanban->id = $kanban->instance;
    $courseid = isset($kanban->course) ? (int)$kanban->course : 0;
    if (!$courseid && !empty($kanban->id)) {
        $courseid = (int)$DB->get_field('kanban', 'course', ['id' => $kanban->id]);
    }
    $kanban->assignmentid = kanban_validate_linked_assignment(
        isset($kanban->assignmentid) ? (int)$kanban->assignmentid : 0,
        $courseid
    );

    return $DB->update_record('kanban', $kanban);
}

/**
 * Kiểm tra assignment liên kết có thuộc cùng khóa học không.
 * Trả về 0 nếu không hợp lệ (không liên kết).
 *
 * @param int $assignmentid ID bản ghi trong bảng assign.
 * @param int $courseid ID khóa học của Kanban.
 * @return int Assignment ID hợp lệ hoặc 0.
 */
function kanban_validate_linked_assignment($assignmentid, $courseid) {
    global $DB;

    $assignmentid = (int)$assignmentid;
    $courseid = (int)$courseid;
    if ($assignmentid <= 0 || $courseid <= 0) {
        return 0;
    }
    $assign = $DB->get_record('assign', ['id' => $assignmentid, 'course' => $courseid]);
    return $assign ? $assignmentid : 0;
}

/**
 * Lấy thông tin assignment liên kết để hiển thị trên bảng Kanban.
 *
 * @param stdClass $kanban Bản ghi Kanban.
 * @return array|null Mảng [name, url, duedate] hoặc null khi không liên kết.
 */
function kanban_get_linked_assignment_info($kanban) {
    global $DB;

    if (empty($kanban->assignmentid)) {
        return null;
    }
    $assign = $DB->get_record('assign', ['id' => (int)$kanban->assignmentid, 'course' => $kanban->course]);
    if (!$assign) {
        return null;
    }
    $cm = get_coursemodule_from_instance('assign', $assign->id, $assign->course, false, IGNORE_MISSING);
    return [
        'name' => format_string($assign->name),
        'url' => $cm ? (new moodle_url('/mod/assign/view.php', ['id' => $cm->id]))->out() : null,
        'duedate' => !empty($assign->duedate) ? (int)$assign->duedate : 0,
    ];
}

/**
 * Xóa bài tập Kanban khỏi khóa học.
 * Tự động dọn dẹp sạch sẽ các cột và thẻ thuộc bài tập này.
 *
 * @param int $id ID của bản ghi kanban
 * @return bool
 */
function kanban_delete_instance($id) {
    global $DB;

    $kanban = $DB->get_record('kanban', ['id' => $id]);
    if (!$kanban) {
        return false;
    }

    $cm = get_coursemodule_from_instance('kanban', $id, $kanban->course);
    if ($cm) {
        $fs = get_file_storage();
        $context = context_module::instance($cm->id);
        $fs->delete_area_files($context->id, 'mod_kanban', 'intro', 0);
        $fs->delete_area_files($context->id, 'mod_kanban', 'card_attachments');
    }

    $cardids = $DB->get_fieldset_select('kanban_cards', 'id', 'kanbanid = :kanbanid', ['kanbanid' => $id]);
    if ($cardids) {
        [$insql, $inparams] = $DB->get_in_or_equal($cardids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('kanban_card_assignees', "cardid $insql", $inparams);
        $DB->delete_records_select('kanban_card_comments', "cardid $insql", $inparams);
        $DB->delete_records_select('kanban_card_history', "cardid $insql", $inparams);
    }

    $DB->delete_records('kanban_members', ['kanbanid' => $id]);
    $DB->delete_records('kanban_cards', ['kanbanid' => $id]);
    $DB->delete_records('kanban_columns', ['kanbanid' => $id]);
    $DB->delete_records('kanban', ['id' => $id]);

    return true;
}

/**
 * Khai báo các tính năng chuẩn của Moodle mà plugin này hỗ trợ.
 *
 * @param string $feature Tên tính năng (FEATURE_*)
 * @return mixed
 */
function kanban_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:                return true; // Hỗ trợ chia nhóm học tập
        case FEATURE_GROUPINGS:             return true;
        case FEATURE_MOD_INTRO:             return true; // Hỗ trợ hiển thị mô tả đề bài
        case FEATURE_SHOW_DESCRIPTION:      return true;
        case FEATURE_BACKUP_MOODLE2:        return true;
        default:                            return null;
    }
}

function kanban_user_has_group_access($cm, $groupid, $context = null) {
    global $USER;

    if (empty($groupid)) {
        return true;
    }

    if ($context === null) {
        $context = context_module::instance($cm->id);
    }

    if (has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    return groups_is_member($groupid, $USER->id);
}

function kanban_validate_and_get_card($cardid, $cm, $kanban, $requiredcapability = 'mod/kanban:managecards') {
    global $DB, $USER;

    if (!$cardid || !$cm || !$kanban) {
        throw new 
            moodle_exception('invalidrecord');
    }

    $course = get_course($cm->course);
    require_login($course, false, $cm);

    $context = context_module::instance($cm->id);
    require_capability($requiredcapability, $context);

    if ((int)$cm->instance !== (int)$kanban->id) {
        throw new 
            moodle_exception('invalidrecord');
    }

    $card = $DB->get_record('kanban_cards', ['id' => (int)$cardid], '*', MUST_EXIST);
    if ((int)$card->kanbanid !== (int)$kanban->id) {
        throw new 
            moodle_exception('invalidrecord');
    }

    if (!kanban_user_has_group_access($cm, $card->groupid, $context)) {
        throw new 
            moodle_exception('nopermissions', 'error');
    }

    return $card;
}

function kanban_validate_and_get_column($columnid, $kanbanid, $cm, $requiredcapability = 'mod/kanban:managecards') {
    global $DB;

    $course = get_course($cm->course);
    require_login($course, false, $cm);

    $context = context_module::instance($cm->id);
    require_capability($requiredcapability, $context);

    $column = $DB->get_record('kanban_columns', ['id' => (int)$columnid, 'kanbanid' => (int)$kanbanid], '*', MUST_EXIST);
    return $column;
}

function kanban_check_wip_limit($column, $kanbanid, $excludeid = 0) {
    global $DB;

    if (empty($column) || (int)$column->wip_limit <= 0) {
        return true;
    }

    $count = $DB->count_records('kanban_cards', ['kanbanid' => (int)$kanbanid, 'columnid' => (int)$column->id]);
    if ($excludeid) {
        $count = $DB->count_records_select('kanban_cards', 'kanbanid = :kanbanid AND columnid = :columnid AND id <> :excludeid', [
            'kanbanid' => (int)$kanbanid,
            'columnid' => (int)$column->id,
            'excludeid' => (int)$excludeid,
        ]);
    }

    if ($count >= (int)$column->wip_limit) {
        throw new \moodle_exception('errorwiplimit', 'mod_kanban', '', ['column' => format_string($column->title), 'limit' => $column->wip_limit]);
    }

    return true;
}

function kanban_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    if ($filearea !== 'card_attachments') {
        send_file_not_found();
    }

    require_login($course, false, $cm);

    $itemid = (int)array_shift($args);
    $card = $DB->get_record('kanban_cards', ['id' => $itemid], '*', MUST_EXIST);
    if (!kanban_user_has_group_access($cm, $card->groupid, $context) && !has_capability('moodle/site:accessallgroups', $context)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $filename = array_pop($args);
    if ($filename === null) {
        send_file_not_found();
    }
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = $fs->get_file($context->id, 'mod_kanban', 'card_attachments', $itemid, $filepath, $filename);
    if (!$file) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

function kanban_get_card_assignee_ids($cardid) {
    global $DB;

    $assigneeids = [];
    foreach ($DB->get_records('kanban_card_assignees', ['cardid' => (int) $cardid], 'id ASC') as $assignee) {
        $assigneeids[] = (int) $assignee->userid;
    }

    return array_values(array_unique($assigneeids));
}

function kanban_get_card_assignee_names($cardid) {
    global $DB;

    $assigneeids = kanban_get_card_assignee_ids($cardid);
    if (empty($assigneeids)) {
        return '';
    }

    $names = [];
    foreach ($assigneeids as $assigneeid) {
        $user = $DB->get_record('user', ['id' => $assigneeid], 'id, firstname, lastname');
        if ($user) {
            $names[] = fullname($user);
        }
    }

    return implode(', ', $names);
}

function kanban_validate_assignee_list($cm, $assigneeids = []) {
    global $DB, $USER;

    $context = context_module::instance($cm->id);
    $assigneeids = array_map('intval', array_filter((array) $assigneeids, function($assigneeid) {
        return (int) $assigneeid > 0;
    }));

    if (empty($assigneeids)) {
        return [];
    }

    if (has_capability('moodle/site:accessallgroups', $context)) {
        $allowed = [];
        foreach (get_enrolled_users($context, '', 0, 'u.id') as $user) {
            $allowed[] = (int) $user->id;
        }
    } else {
        $groupid = groups_get_activity_group($cm, true);
        $allowed = [];
        if ($groupid) {
            $members = groups_get_members($groupid, 'u.id', 'u.lastname, u.firstname');
            foreach ($members as $member) {
                $allowed[] = (int) $member->id;
            }
        } else {
            $allowed = [$DB->get_field('user', 'id', ['id' => $USER->id]) ? (int) $USER->id : 0];
        }
    }

    $allowed = array_unique(array_filter($allowed, function($userid) {
        return $userid > 0;
    }));

    foreach ($assigneeids as $assigneeid) {
        if (!in_array($assigneeid, $allowed, true)) {
            throw new moodle_exception('nopermissions', 'error');
        }
    }

    return array_values(array_unique($assigneeids));
}

function kanban_set_card_assignees($cardid, $assigneeids, $cm = null) {
    global $DB;

    $cardid = (int) $cardid;
    $assigneeids = array_values(array_unique(array_map('intval', array_filter((array) $assigneeids, function($assigneeid) {
        return (int) $assigneeid > 0;
    }))));

    $DB->delete_records('kanban_card_assignees', ['cardid' => $cardid]);

    foreach ($assigneeids as $assigneeid) {
        $DB->insert_record('kanban_card_assignees', (object) ['cardid' => $cardid, 'userid' => $assigneeid]);
    }

    if (!empty($assigneeids)) {
        $DB->set_field('kanban_cards', 'assigned_to', (int) $assigneeids[0], ['id' => $cardid]);
    } else {
        $DB->set_field('kanban_cards', 'assigned_to', 0, ['id' => $cardid]);
    }

    if ($cm) {
        $DB->set_field('kanban_cards', 'groupid', groups_get_activity_group($cm, true) ?: 0, ['id' => $cardid]);
    }

    return $assigneeids;
}

function kanban_get_group_members_for_assignee_selection($cm) {
    $context = context_module::instance($cm->id);
    $currentgroup = groups_get_activity_group($cm, true);

    if (!$currentgroup && !has_capability('moodle/site:accessallgroups', $context)) {
        return [];
    }

    if ($currentgroup) {
        $members = groups_get_members($currentgroup, 'u.id, u.firstname, u.lastname', 'u.lastname, u.firstname');
        $users = [];
        foreach ($members as $member) {
            $users[] = [
                'id' => (int) $member->id,
                'fullname' => fullname($member),
            ];
        }
        return $users;
    }

    $users = get_enrolled_users($context, '', 0, 'u.id, u.firstname, u.lastname');
    $result = [];
    foreach ($users as $user) {
        $result[] = [
            'id' => (int) $user->id,
            'fullname' => fullname($user),
        ];
    }
    return $result;
}

function kanban_is_done_column($column, $kanbanid) {
    global $DB;

    $lastcolumn = $DB->get_record_sql(
        'SELECT id FROM {kanban_columns} WHERE kanbanid = ? ORDER BY sortorder DESC, id DESC',
        [$kanbanid],
        IGNORE_MULTIPLE
    );
    return $lastcolumn && (int)$lastcolumn->id === (int)$column->id;
}

function kanban_notify_card_assignees($card, $cm, $event) {
    global $DB, $USER;

    if (!get_config('mod_kanban', 'enablenotifications')) {
        return;
    }

    $assigneeids = kanban_get_card_assignee_ids($card->id);
    if (empty($assigneeids)) {
        return;
    }

    $kanban = $DB->get_record('kanban', ['id' => $card->kanbanid], '*', MUST_EXIST);
    $context = context_module::instance($cm->id);
    $url = new moodle_url('/mod/kanban/view.php', ['id' => $cm->id]);
    $subjectkey = $event === 'deadline_warning' || $event === 'deadline_overdue'
        ? 'message_deadline_subject' : ($event === 'assigned'
            ? 'message_cardassigned_subject' : 'message_cardupdated_subject');
    $subject = get_string($subjectkey, 'mod_kanban');
    $message = format_string($card->title) . "\n\n" . format_string($kanban->name);
    if ($event === 'deadline_warning') {
        $message .= "\n" . get_string('deadline_warning_message', 'mod_kanban', userdate($card->duedate));
    } else if ($event === 'deadline_overdue') {
        $message .= "\n" . get_string('deadline_overdue_message', 'mod_kanban', userdate($card->duedate));
    } else {
        $message .= "\n" . get_string('card_updated_message', 'mod_kanban');
    }

    foreach ($assigneeids as $assigneeid) {
        if ((int)$assigneeid === (int)$USER->id && $event !== 'deadline_warning' && $event !== 'deadline_overdue') {
            continue;
        }
        $recipient = $DB->get_record('user', ['id' => $assigneeid, 'deleted' => 0]);
        if (!$recipient) {
            continue;
        }
        $notification = new \core\message\message();
        $notification->component = 'mod_kanban';
        $notification->name = $event === 'deadline_warning' || $event === 'deadline_overdue'
            ? 'deadline' : ($event === 'assigned' ? 'cardassigned' : 'cardupdated');
        $notification->userfrom = \core_user::get_noreply_user();
        $notification->userto = $recipient;
        $notification->subject = $subject;
        $notification->fullmessage = $message;
        $notification->fullmessageformat = FORMAT_PLAIN;
        $notification->smallmessage = $subject . ': ' . format_string($card->title);
        $notification->notification = 1;
        $notification->contexturl = $url->out(false);
        $notification->contexturlname = get_string('pluginname', 'mod_kanban');
        message_send($notification);
    }
}

function kanban_log_card_change($cardid, $action, $details = '') {
    global $DB, $USER;
    $record = new stdClass();
    $record->cardid = $cardid;
    $record->userid = $USER->id;
    $record->action = $action;
    $record->details = $details;
    $record->timecreated = time();
    $DB->insert_record('kanban_card_history', $record);
}