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

    // 2. Tự động khởi tạo 3 cột mặc định cho bảng Kanban này.
    // Tất cả cột đều không giới hạn số lượng thẻ (WIP limit đã bị tắt).
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
            'wip_limit' => 0
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
 * Get read-only submission and grade information for the linked assignment.
 *
 * This helper only reads existing submissions and grades. In particular, it
 * avoids the Assignment APIs' default behaviour of creating missing records.
 *
 * @param stdClass $kanban Kanban instance.
 * @param stdClass $cm Kanban course module.
 * @param int $groupid Optional group selected in the Kanban dashboard.
 * @return array|null Assignment progress data, or null when no assignment is linked.
 */
function kanban_get_linked_assignment_progress($kanban, $cm, $groupid = 0) {
    global $CFG, $DB;

    $info = kanban_get_linked_assignment_info($kanban);
    if (!$info || empty($info['url'])) {
        return null;
    }

    $assignmentrecord = $DB->get_record('assign', [
        'id' => (int)$kanban->assignmentid,
        'course' => (int)$kanban->course,
    ], '*', MUST_EXIST);
    $assignmentcmrecord = get_coursemodule_from_instance(
        'assign',
        $assignmentrecord->id,
        $assignmentrecord->course,
        false,
        IGNORE_MISSING
    );
    if (!$assignmentcmrecord) {
        return null;
    }

    $assignmentcm = get_fast_modinfo($assignmentrecord->course)->get_cm($assignmentcmrecord->id);
    $assignmentcontext = context_module::instance($assignmentcm->id);
    if (!$assignmentcm->uservisible) {
        return [
            'name' => $info['name'],
            'url' => $info['url'],
            'state' => 'unavailable',
            'rows' => [],
            'showgrades' => false,
        ];
    }

    require_once($CFG->dirroot . '/mod/assign/locallib.php');
    $assignment = new \assign($assignmentcontext, $assignmentcm, get_course($assignmentrecord->course));

    $groupid = (int)$groupid;
    $separategroups = groups_get_activity_groupmode($assignmentcm) == SEPARATEGROUPS;
    $accessallgroups = has_capability('moodle/site:accessallgroups', $assignmentcontext);
    if (!$groupid && $separategroups && !$accessallgroups) {
        $groupid = (int)groups_get_activity_group($assignmentcm, true);
    }
    if ($separategroups && !$accessallgroups && !$groupid) {
        return [
            'name' => $info['name'],
            'url' => $info['url'],
            'state' => 'selectgroup',
            'groupselectorcmid' => $assignmentcm->id,
            'rows' => [],
            'showgrades' => false,
        ];
    }
    if ($groupid && !$accessallgroups) {
        $allowedgroups = groups_get_activity_allowed_groups($assignmentcm);
        if (!array_key_exists($groupid, $allowedgroups)) {
            return [
                'name' => $info['name'],
                'url' => $info['url'],
                'state' => 'unavailable',
                'rows' => [],
                'showgrades' => false,
            ];
        }
    }
    if (!$assignment->can_view_grades($groupid ?: null)) {
        return [
            'name' => $info['name'],
            'url' => $info['url'],
            'state' => 'nopermission',
            'rows' => [],
            'showgrades' => false,
        ];
    }

    if (!empty($assignmentrecord->blindmarking) &&
            !has_capability('mod/assign:viewblinddetails', $assignmentcontext)) {
        return [
            'name' => $info['name'],
            'url' => $info['url'],
            'state' => 'blindmarking',
            'rows' => [],
            'showgrades' => false,
        ];
    }

    $participants = get_enrolled_users(
        $assignmentcontext,
        'mod/assign:submit',
        $groupid,
        'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
        'u.lastname, u.firstname'
    );
    $showgrades = $assignment->can_view_grades($groupid ?: null);
    $rows = [];
    foreach ($participants as $participant) {
        if (!empty($assignmentrecord->teamsubmission)) {
            $submissiongroup = $assignment->get_submission_group($participant->id);
            $submission = $submissiongroup ?
                $assignment->get_group_submission($participant->id, $submissiongroup->id, false) : false;
        } else {
            $submission = $assignment->get_user_submission($participant->id, false);
        }

        $status = $submission && !empty($submission->status) ? $submission->status : '';
        $statuskey = 'submissionstatus_' . $status;
        if (!get_string_manager()->string_exists($statuskey, 'assign')) {
            $statuskey = 'submissionstatus_';
        }

        $gradevalue = null;
        $hasgrade = false;
        if ($showgrades && $submission) {
            // Passing an attempt number prevents get_user_grade() creating absent submissions.
            $grade = $assignment->get_user_grade($participant->id, false, (int)$submission->attemptnumber);
            if ($grade && $grade->grade !== null && (float)$grade->grade >= 0) {
                $gradeitem = $assignment->get_grade_item();
                if ($gradeitem) {
                    $gradevalue = grade_format_gradevalue($grade->grade, $gradeitem);
                    $hasgrade = ($gradevalue !== '');
                }
            }
        }

        $rows[] = [
            'name' => fullname($participant),
            'status' => get_string($statuskey, 'assign'),
            'grade' => $gradevalue,
            'hasgrade' => $hasgrade,
            'url' => $info['url'],
        ];
    }

    return [
        'name' => $info['name'],
        'url' => $info['url'],
        'state' => 'ready',
        'rows' => $rows,
        'showgrades' => $showgrades,
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

/**
 * Prevent Kanban participants from using more than one course group.
 *
 * Group membership itself remains managed by Moodle; this only restricts
 * access to group-enabled Kanban activities.
 *
 * @param stdClass $cm Course module.
 * @return void
 */
function kanban_require_single_group_membership($cm) {
    global $DB, $USER;

    if (empty($cm->groupmode) || isguestuser()) {
        return;
    }

    $context = context_module::instance($cm->id);
    $coursecontext = context_course::instance($cm->course);
    if (has_capability('moodle/site:accessallgroups', $context) ||
            has_capability('moodle/course:managegroups', $coursecontext)) {
        return;
    }

    $groupcount = $DB->count_records_sql(
        'SELECT COUNT(DISTINCT gm.groupid)
           FROM {groups_members} gm
           JOIN {groups} g ON g.id = gm.groupid
          WHERE gm.userid = :userid AND g.courseid = :courseid',
        ['userid' => $USER->id, 'courseid' => $cm->course]
    );
    if ($groupcount > 1) {
        throw new moodle_exception('error_multiple_groups', 'mod_kanban');
    }
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
    kanban_require_single_group_membership($cm);

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
    kanban_require_single_group_membership($cm);

    $column = $DB->get_record('kanban_columns', ['id' => (int)$columnid, 'kanbanid' => (int)$kanbanid], '*', MUST_EXIST);
    return $column;
}

function kanban_check_wip_limit($column, $kanbanid, $groupid = 0) {
    global $DB;

    if (empty($column->wip_limit)) {
        return true;
    }

    return $DB->count_records('kanban_cards', [
        'kanbanid' => (int)$kanbanid,
        'columnid' => (int)$column->id,
        'groupid' => (int)$groupid,
    ]) < (int)$column->wip_limit;
}

/**
 * Save uploaded card attachments to the module file area.
 *
 * @param int $cardid Card ID used as the file area item ID.
 * @param stdClass $cm Course module.
 * @param array $uploadedfiles PHP uploaded files structure.
 * @return int Number of saved files.
 */
function kanban_save_card_attachments($cardid, $cm, array $uploadedfiles) {
    global $USER;

    $context = context_module::instance($cm->id);
    $uploadgroups = [];
    foreach ($uploadedfiles as $fieldname => $filedata) {
        if (stripos($fieldname, 'attach') !== false) {
            $uploadgroups[] = $filedata;
        }
    }

    $fs = get_file_storage();
    $filesaved = 0;
    foreach ($uploadgroups as $attachments) {
        if (empty($attachments['name'])) {
            continue;
        }

        $names = is_array($attachments['name']) ? $attachments['name'] : [$attachments['name']];
        $tmpnames = is_array($attachments['tmp_name'] ?? null) ? $attachments['tmp_name'] : [$attachments['tmp_name'] ?? ''];
        $errors = is_array($attachments['error'] ?? null) ? $attachments['error'] : [$attachments['error'] ?? UPLOAD_ERR_NO_FILE];

        foreach ($names as $index => $filename) {
            if (empty($tmpnames[$index]) || !isset($errors[$index]) ||
                    (int)$errors[$index] !== UPLOAD_ERR_OK) {
                continue;
            }

            $cleanfilename = clean_param($filename, PARAM_FILE);
            if ($cleanfilename === '') {
                continue;
            }

            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'mod_kanban',
                'filearea' => 'card_attachments',
                'itemid' => (int)$cardid,
                'userid' => (int)$USER->id,
                'filepath' => '/',
                'filename' => $cleanfilename,
            ], $tmpnames[$index]);
            $filesaved++;
        }
    }

    return $filesaved;
}

function kanban_get_accessible_card_attachment($course, $cm, $context, $filearea, $args) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    if ($filearea !== 'card_attachments') {
        return false;
    }

    require_login($course, false, $cm);
    kanban_require_single_group_membership($cm);

    $itemid = (int)array_shift($args);
    $card = $DB->get_record('kanban_cards', ['id' => $itemid], '*', MUST_EXIST);
    if (!kanban_user_has_group_access($cm, $card->groupid, $context) && !has_capability('moodle/site:accessallgroups', $context)) {
        return false;
    }

    $filename = array_pop($args);
    if ($filename === null) {
        return false;
    }
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    return get_file_storage()->get_file(
        $context->id,
        'mod_kanban',
        'card_attachments',
        $itemid,
        $filepath,
        $filename
    );
}

function kanban_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    $file = kanban_get_accessible_card_attachment($course, $cm, $context, $filearea, $args);
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
        $user = $DB->get_record(
            'user',
            ['id' => $assigneeid],
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
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

/**
 * Đồng bộ danh sách assignee của card. Chỉ chạm assignees/assigned_to,
 * KHÔNG đổi groupid (group được chốt lúc tạo card) để tránh card nhảy nhóm
 * ngầm khi user có accessallgroups sửa card.
 */
function kanban_set_card_assignees($cardid, $assigneeids) {
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

    return $assigneeids;
}

function kanban_get_group_members_for_assignee_selection($cm) {
    $context = context_module::instance($cm->id);
    $currentgroup = groups_get_activity_group($cm, true);

    if (!$currentgroup && !has_capability('moodle/site:accessallgroups', $context)) {
        return [];
    }

    if ($currentgroup) {
        $members = groups_get_members(
            $currentgroup,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
            'u.lastname, u.firstname'
        );
        $users = [];
        foreach ($members as $member) {
            $users[] = [
                'id' => (int) $member->id,
                'fullname' => fullname($member),
            ];
        }
        return $users;
    }

    $users = get_enrolled_users(
        $context,
        '',
        0,
        'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename'
    );
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

/**
 * Get the default workflow columns by their ordering.
 *
 * @param int $kanbanid Kanban instance ID.
 * @return array Column IDs keyed by todo, inprogress and done.
 */
function kanban_get_workflow_column_ids($kanbanid) {
    global $DB;

    $columns = array_values($DB->get_records('kanban_columns', ['kanbanid' => (int)$kanbanid], 'sortorder ASC, id ASC'));
    if (count($columns) < 3) {
        return [];
    }

    return [
        'todo' => (int)$columns[0]->id,
        'inprogress' => (int)$columns[1]->id,
        'done' => (int)$columns[count($columns) - 1]->id,
    ];
}

/**
 * Send a Moodle notification to enrolled members of the card's group.
 *
 * A card without a group notifies all users enrolled in the activity.
 *
 * @param stdClass $kanban Kanban instance.
 * @param stdClass $cm Course module.
 * @param int $groupid Group ID, or 0 for all enrolled activity users.
 * @param string $provider Message provider name.
 * @param string $subject Notification subject.
 * @param string $messagebody Plain-text notification body.
 * @return int Number of notifications accepted by Moodle's messaging API.
 */
function kanban_notify_group_members($kanban, $cm, $groupid, $provider, $subject, $messagebody) {
    if (!get_config('mod_kanban', 'enablenotifications')) {
        return 0;
    }

    $context = context_module::instance($cm->id);
    // Full record: message_send() requires auth/suspended/deleted/emailstop on userto.
    $recipients = get_enrolled_users($context, '', (int)$groupid);
    $sent = 0;
    $url = new moodle_url('/mod/kanban/view.php', ['id' => $cm->id]);

    foreach ($recipients as $recipient) {
        $notification = new \core\message\message();
        $notification->component = 'mod_kanban';
        $notification->name = $provider;
        $notification->userfrom = \core_user::get_noreply_user();
        $notification->userto = $recipient;
        $notification->subject = $subject;
        $notification->fullmessage = $messagebody . "\n\n" . format_string($kanban->name);
        $notification->fullmessageformat = FORMAT_PLAIN;
        $notification->smallmessage = $subject;
        $notification->notification = 1;
        $notification->contexturl = $url->out(false);
        $notification->contexturlname = get_string('pluginname', 'mod_kanban');

        if (message_send($notification) !== false) {
            $sent++;
        }
    }

    return $sent;
}

/**
 * Notify the group immediately when a card is created in the first workflow column.
 *
 * @param stdClass $card Created card.
 * @param stdClass $cm Course module.
 * @return int Number of notifications accepted by Moodle's messaging API.
 */
function kanban_notify_group_new_todo_card($card, $cm) {
    global $DB;

    $columnids = kanban_get_workflow_column_ids($card->kanbanid);
    if (empty($columnids) || (int)$card->columnid !== $columnids['todo']) {
        return 0;
    }

    $kanban = $DB->get_record('kanban', ['id' => (int)$card->kanbanid], '*', MUST_EXIST);
    return kanban_notify_group_members(
        $kanban,
        $cm,
        (int)$card->groupid,
        'cardassigned',
        get_string('message_todo_new_subject', 'mod_kanban'),
        get_string('message_todo_new', 'mod_kanban', format_string($card->title))
    );
}

function kanban_notify_card_assignees($card, $cm, $event) {
    global $DB, $USER;

    if (!get_config('mod_kanban', 'enablenotifications')) {
        return 0;
    }

    $assigneeids = kanban_get_card_assignee_ids($card->id);
    if (empty($assigneeids)) {
        return 0;
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

    $sent = 0;
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
        if (message_send($notification) !== false) {
            $sent++;
        }
    }

    return $sent;
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
