<?php
namespace mod_kanban\external;

defined('MOODLE_INTERNAL') || die();

// Không require lib/externallib.php ở đây: file đó cấm include trực tiếp khi
// chạy PHPUnit (coding_exception) và lớp core_external\external_api đã được
// Moodle autoload. Chỉ cần lib.php của plugin cho các hàm kanban_*.
require_once(__DIR__ . '/../../lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use context_module;
use stdClass;

class card_api extends \core_external\external_api {

    // 1. Di chuyển thẻ (kèm vị trí newposition trong cột đích)
    public static function move_card_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID của thẻ'),
            'targetcolumnid' => new \core_external\external_value(PARAM_INT, 'ID của cột đích'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID'),
            'newposition' => new \core_external\external_value(PARAM_INT, 'Vị trí mới trong cột đích (0-based)', VALUE_DEFAULT, -1)
        ]);
    }

    public static function move_card($cardid, $targetcolumnid, $cmid, $newposition = -1) {
        global $DB;
        $params = self::validate_parameters(self::move_card_parameters(), [
            'cardid' => $cardid,
            'targetcolumnid' => $targetcolumnid,
            'cmid' => $cmid,
            'newposition' => $newposition
        ]);

        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:managecards', $context);
        // Sesskey do framework (lib/ajax/service.php) kiểm tra; không gọi ở đây để WS token hoạt động.

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');
        $targetcolumn = kanban_validate_and_get_column($params['targetcolumnid'], $kanban->id, $cm, 'mod/kanban:managecards');

        if ((int)$card->columnid !== (int)$params['targetcolumnid']) {
            kanban_check_wip_limit($targetcolumn, $kanban->id, $card->id);
        }

        $oldcolumnid = (int)$card->columnid;
        $card->columnid = $params['targetcolumnid'];
        $card->timemodified = time();
        $DB->update_record('kanban_cards', $card);

        // Sắp xếp lại sortorder trong cột đích (cùng group để khớp hiển thị đã lọc).
        $siblings = $DB->get_records('kanban_cards', [
            'kanbanid' => $kanban->id,
            'columnid' => $targetcolumn->id,
            'groupid' => (int)$card->groupid,
        ], 'sortorder ASC, id ASC');
        unset($siblings[$card->id]);
        $ordered = array_values($siblings);
        $position = (int)$params['newposition'];
        if ($position < 0 || $position > count($ordered)) {
            $position = count($ordered);
        }
        array_splice($ordered, $position, 0, [$card]);
        $order = 0;
        foreach ($ordered as $sibling) {
            $DB->set_field('kanban_cards', 'sortorder', $order, ['id' => $sibling->id]);
            $order++;
        }
        // Dồn lại thứ tự cột cũ khi chuyển cột.
        if ($oldcolumnid !== (int)$targetcolumn->id) {
            $oldsiblings = $DB->get_records('kanban_cards', [
                'kanbanid' => $kanban->id,
                'columnid' => $oldcolumnid,
                'groupid' => (int)$card->groupid,
            ], 'sortorder ASC, id ASC');
            $order = 0;
            foreach ($oldsiblings as $oldsibling) {
                $DB->set_field('kanban_cards', 'sortorder', $order, ['id' => $oldsibling->id]);
                $order++;
            }
        }

        \mod_kanban\event\card_moved::create([
            'objectid' => $card->id,
            'context' => $context,
            'other' => [
                'kanbanid' => $kanban->id,
                'fromcolumnid' => $oldcolumnid,
                'tocolumnid' => (int)$targetcolumn->id,
                'newposition' => $position,
            ],
        ])->trigger();
        kanban_log_card_change($card->id, 'moved', get_string('history_moved', 'mod_kanban', format_string($targetcolumn->title)));
        kanban_notify_card_assignees($card, $cm, 'updated');

        return ['status' => true, 'message' => get_string('msg_card_moved', 'mod_kanban')];
    }

    public static function move_card_returns() {
        return new \core_external\external_single_structure([
            'status' => new \core_external\external_value(PARAM_BOOL, 'Kết quả'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Thông báo')
        ]);
    }

    // 2. Tạo thẻ mới kèm Hạn chót (duedate)
    public static function create_card_parameters() {
        return new \core_external\external_function_parameters([
            'kanbanid' => new \core_external\external_value(PARAM_INT, 'Kanban ID'),
            'columnid' => new \core_external\external_value(PARAM_INT, 'Column ID'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID'),
            'title' => new \core_external\external_value(PARAM_TEXT, 'Tiêu đề công việc'),
            'description' => new \core_external\external_value(PARAM_RAW, 'Mô tả chi tiết', VALUE_DEFAULT, ''),
            'duedate' => new \core_external\external_value(PARAM_INT, 'Hạn chót timestamp', VALUE_DEFAULT, 0),
            'submissionurl' => new external_value(PARAM_URL, 'Submission URL', VALUE_DEFAULT, ''),
            'assignees' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_INT, 'ID thành viên được giao'),
                'Danh sách người được giao',
                VALUE_DEFAULT,
                []
            )
        ]);
    }

    public static function create_card($kanbanid, $columnid, $cmid, $title, $description, $duedate, $submissionurl = '', $assignees = []) {
        global $DB, $USER;

        $params = self::validate_parameters(self::create_card_parameters(), [
            'kanbanid' => $kanbanid,
            'columnid' => $columnid,
            'cmid' => $cmid,
            'title' => $title,
            'description' => $description,
            'duedate' => $duedate,
            'submissionurl' => $submissionurl,
            'assignees' => $assignees
        ]);

        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:managecards', $context);
        // Sesskey do framework (lib/ajax/service.php) kiểm tra; không gọi ở đây để WS token hoạt động.

        $kanban = $DB->get_record('kanban', ['id' => $params['kanbanid']], '*', MUST_EXIST);
        if ((int)$kanban->id !== (int)$cm->instance) {
            throw new \moodle_exception('invalidrecord');
        }

        $targetcolumn = kanban_validate_and_get_column($params['columnid'], $kanban->id, $cm, 'mod/kanban:managecards');
        $currentgroup = groups_get_activity_group($cm, true);
        if (!has_capability('moodle/site:accessallgroups', $context) && $currentgroup && !groups_is_member($currentgroup, $USER->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $title = trim((string)$params['title']);
        if ($title === '') {
            throw new \moodle_exception('required', 'mod_kanban');
        }

        if ($params['duedate'] > 0 && $params['duedate'] < (time() - 300)) {
            throw new \moodle_exception('error_duedate_past', 'mod_kanban');
        }

        kanban_check_wip_limit($targetcolumn, $kanban->id);

        $assigneeids = kanban_validate_assignee_list($cm, $params['assignees']);

        $card = new stdClass();
        $card->kanbanid = $params['kanbanid'];
        $card->columnid = $params['columnid'];
        $card->groupid = $currentgroup ? $currentgroup : 0;
        $card->title = $title;
        $card->description = $params['description'];
        $card->duedate = $params['duedate'];
        $card->task_url = $params['submissionurl'];
        $card->assigned_to = !empty($assigneeids) ? (int) $assigneeids[0] : 0;
        $maxorder = $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {kanban_cards} WHERE kanbanid = :kanbanid AND columnid = :columnid AND groupid = :groupid',
            ['kanbanid' => $kanban->id, 'columnid' => $targetcolumn->id, 'groupid' => $currentgroup ? $currentgroup : 0]
        );
        $card->sortorder = ($maxorder === null || $maxorder === false) ? 0 : ((int)$maxorder + 1);
        $card->timecreated = time();
        $card->timemodified = time();

        $cardid = $DB->insert_record('kanban_cards', $card);
        kanban_set_card_assignees($cardid, $assigneeids);
        \mod_kanban\event\card_created::create([
            'objectid' => $cardid,
            'context' => $context,
            'other' => ['kanbanid' => $kanban->id, 'columnid' => (int)$targetcolumn->id],
        ])->trigger();
        kanban_log_card_change($cardid, 'created', get_string('history_created', 'mod_kanban'));
        $card->id = $cardid;
        kanban_notify_card_assignees($card, $cm, 'assigned');

        return [
            'status' => true,
            'cardid' => $cardid,
            'message' => get_string('msg_card_created', 'mod_kanban')
        ];
    }

    public static function create_card_returns() {
        return new \core_external\external_single_structure([
            'status' => new \core_external\external_value(PARAM_BOOL, 'Kết quả'),
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID thẻ vừa tạo'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Thông báo')
        ]);
    }

    public static function update_card_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID thẻ cần cập nhật'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID'),
            'title' => new \core_external\external_value(PARAM_TEXT, 'Tiêu đề mới', VALUE_DEFAULT, ''),
            'description' => new \core_external\external_value(PARAM_RAW, 'Mô tả mới', VALUE_DEFAULT, ''),
            'duedate' => new \core_external\external_value(PARAM_INT, 'Hạn hoàn thành mới', VALUE_DEFAULT, 0),
            'submissionurl' => new \core_external\external_value(PARAM_URL, 'URL nộp bài mới', VALUE_DEFAULT, ''),
            'assignees' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_INT, 'ID thành viên được giao'),
                'Danh sách người được giao mới',
                VALUE_DEFAULT,
                []
            )
        ]);
    }

    public static function update_card($cardid, $cmid, $title = '', $description = '', $duedate = 0, $submissionurl = '', $assignees = []) {
        global $DB, $USER;

        $params = self::validate_parameters(self::update_card_parameters(), [
            'cardid' => $cardid,
            'cmid' => $cmid,
            'title' => $title,
            'description' => $description,
            'duedate' => $duedate,
            'submissionurl' => $submissionurl,
            'assignees' => $assignees,
        ]);

        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:managecards', $context);
        // Sesskey do framework (lib/ajax/service.php) kiểm tra; không gọi ở đây để WS token hoạt động.

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');

        $title = trim((string) $params['title']);
        if ($title === '') {
            throw new \moodle_exception('required', 'mod_kanban');
        }

        if ($params['duedate'] > 0 && $params['duedate'] < (time() - 300)) {
            throw new \moodle_exception('error_duedate_past', 'mod_kanban');
        }

        $assigneeids = kanban_validate_assignee_list($cm, $params['assignees']);
        $oldassigneeids = kanban_get_card_assignee_ids($card->id);

        $card->title = $title;
        $card->description = $params['description'];
        $card->duedate = $params['duedate'];
        $card->task_url = $params['submissionurl'];
        $card->timemodified = time();
        $DB->update_record('kanban_cards', $card);

        kanban_set_card_assignees($card->id, $assigneeids);
        \mod_kanban\event\card_updated::create([
            'objectid' => $card->id,
            'context' => $context,
            'other' => ['kanbanid' => $kanban->id],
        ])->trigger();
        kanban_log_card_change($card->id, 'updated', get_string('history_updated', 'mod_kanban'));
        if ($oldassigneeids !== $assigneeids) {
            kanban_notify_card_assignees($card, $cm, 'assigned');
        } else {
            kanban_notify_card_assignees($card, $cm, 'updated');
        }

        return ['status' => true, 'message' => get_string('msg_card_updated', 'mod_kanban')];
    }

    public static function update_card_returns() {
        return new \core_external\external_single_structure([
            'status' => new \core_external\external_value(PARAM_BOOL, 'Kết quả'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Thông báo')
        ]);
    }

    // 3. Xóa thẻ
    public static function delete_card_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID thẻ cần xóa'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID')
        ]);
    }

    public static function delete_card($cardid, $cmid) {
        global $DB;
        $params = self::validate_parameters(self::delete_card_parameters(), [
            'cardid' => $cardid,
            'cmid' => $cmid
        ]);

        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:managecards', $context);
        // Sesskey do framework (lib/ajax/service.php) kiểm tra; không gọi ở đây để WS token hoạt động.

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');

        kanban_log_card_change($card->id, 'deleted', get_string('history_deleted', 'mod_kanban'));
        $deletedcardid = (int)$card->id;
        // Dọn dữ liệu con + file đính kèm để không orphan (như kanban_delete_instance()).
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_kanban', 'card_attachments', $deletedcardid);
        $DB->delete_records('kanban_card_assignees', ['cardid' => $deletedcardid]);
        $DB->delete_records('kanban_card_comments', ['cardid' => $deletedcardid]);
        $DB->delete_records('kanban_card_history', ['cardid' => $deletedcardid]);
        $DB->delete_records('kanban_cards', ['id' => $deletedcardid]);
        \mod_kanban\event\card_deleted::create([
            'objectid' => $deletedcardid,
            'context' => $context,
            'other' => ['kanbanid' => $kanban->id],
        ])->trigger();

        return ['status' => true, 'message' => get_string('msg_card_deleted', 'mod_kanban')];
    }

    public static function delete_card_returns() {
        return new \core_external\external_single_structure([
            'status' => new \core_external\external_value(PARAM_BOOL, 'Kết quả'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Thông báo')
        ]);
    }

    public static function get_card_files_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID của thẻ'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID')
        ]);
    }

    public static function get_card_files($cardid, $cmid) {
        global $DB;
        $params = self::validate_parameters(self::get_card_files_parameters(), compact('cardid', 'cmid'));
        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:view', $context);

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:view');

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_kanban', 'card_attachments', $card->id, 'filename', false);

        $result = [];
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $result[] = [
                'id' => $file->get_id(),
                'filename' => $file->get_filename(),
                'size' => $file->get_filesize(),
                'hash' => $file->get_pathnamehash(),
                'url' => \moodle_url::make_pluginfile_url(
                    $context->id,
                    'mod_kanban',
                    'card_attachments',
                    $card->id,
                    '/',
                    $file->get_filename(),
                    false
                )->out(false),
            ];
        }

        return ['files' => $result];
    }

    public static function get_card_files_returns() {
        return new \core_external\external_single_structure([
            'files' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'id' => new \core_external\external_value(PARAM_TEXT, 'File ID'),
                    'filename' => new \core_external\external_value(PARAM_TEXT, 'Tên file'),
                    'size' => new \core_external\external_value(PARAM_INT, 'Kích thước file'),
                    'hash' => new \core_external\external_value(PARAM_TEXT, 'Hash file'),
                    'url' => new \core_external\external_value(PARAM_URL, 'URL file'),
                ])
            )
        ]);
    }

    public static function delete_card_file_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID của thẻ'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID'),
            'filehash' => new \core_external\external_value(PARAM_TEXT, 'Hash file cần xóa')
        ]);
    }

    public static function delete_card_file($cardid, $cmid, $filehash) {
        global $DB;
        $params = self::validate_parameters(self::delete_card_file_parameters(), compact('cardid', 'cmid', 'filehash'));
        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:managecards', $context);
        // Sesskey do framework (lib/ajax/service.php) kiểm tra; không gọi ở đây để WS token hoạt động.

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');

        $fs = get_file_storage();
        $file = $fs->get_file_by_hash($params['filehash']);
        if (!$file || $file->get_itemid() !== (int)$card->id || $file->get_contextid() !== $context->id) {
            throw new \moodle_exception('filenotfound', 'error');
        }

        $file->delete();
        return ['status' => true, 'message' => get_string('msg_file_deleted', 'mod_kanban')];
    }

    public static function delete_card_file_returns() {
        return new \core_external\external_single_structure([
            'status' => new \core_external\external_value(PARAM_BOOL, 'Kết quả'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Thông báo')
        ]);
    }

    public static function get_card_activity_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID cua the'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID')
        ]);
    }

    public static function get_card_activity($cardid, $cmid) {
        global $DB;
        $params = self::validate_parameters(self::get_card_activity_parameters(), compact('cardid', 'cmid'));
        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:view', $context);
        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:view');
        $canviewhistory = has_capability('mod/kanban:viewdashboard', $context);

        $dbman = $DB->get_manager();
        $commentsenabled = $dbman->table_exists('kanban_card_comments');
        $historyenabled = $dbman->table_exists('kanban_card_history');

        $comments = [];
        if ($commentsenabled) {
            foreach ($DB->get_records('kanban_card_comments', ['cardid' => $card->id], 'timecreated ASC') as $comment) {
                $user = $DB->get_record('user', ['id' => $comment->userid], 'id,firstname,lastname');
                $author = $user ? fullname($user) : get_string('unknownuser', 'moodle');
                $comments[] = ['author' => $author, 'comment' => format_text($comment->comment, FORMAT_PLAIN),
                    'timecreated' => userdate($comment->timecreated)];
            }
        }

        $history = [];
        if ($historyenabled && $canviewhistory) {
            foreach ($DB->get_records('kanban_card_history', ['cardid' => $card->id], 'timecreated DESC') as $entry) {
                $user = $DB->get_record('user', ['id' => $entry->userid], 'id,firstname,lastname');
                $author = $user ? fullname($user) : get_string('unknownuser', 'moodle');
                $details = $entry->details;
                if ($entry->action === 'moved' && preg_match('/cot\s+(\d+)/i', (string)$details, $matches)) {
                    $column = $DB->get_record('kanban_columns', [
                        'id' => (int)$matches[1],
                        'kanbanid' => $card->kanbanid
                    ]);
                    if ($column) {
                        $details = 'Chuyen sang cot ' . $column->title;
                    }
                }
                $history[] = ['author' => $author, 'action' => $entry->action, 'details' => $details,
                    'timecreated' => userdate($entry->timecreated)];
            }
        }

        return ['comments' => $comments, 'history' => $history];
    }

    public static function get_card_activity_returns() {
        $comment = new \core_external\external_single_structure([
            'author' => new \core_external\external_value(PARAM_TEXT), 'comment' => new \core_external\external_value(PARAM_TEXT),
            'timecreated' => new \core_external\external_value(PARAM_TEXT)
        ]);
        $history = new \core_external\external_single_structure([
            'author' => new \core_external\external_value(PARAM_TEXT), 'action' => new \core_external\external_value(PARAM_TEXT),
            'details' => new \core_external\external_value(PARAM_TEXT), 'timecreated' => new \core_external\external_value(PARAM_TEXT)
        ]);
        return new \core_external\external_single_structure([
            'comments' => new \core_external\external_multiple_structure($comment),
            'history' => new \core_external\external_multiple_structure($history)
        ]);
    }

    public static function add_teacher_comment_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID cua the'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID'),
            'comment' => new \core_external\external_value(PARAM_TEXT, 'Noi dung binh luan')
        ]);
    }

    public static function add_teacher_comment($cardid, $cmid, $comment) {
        global $DB, $USER;
        $params = self::validate_parameters(self::add_teacher_comment_parameters(), compact('cardid', 'cmid', 'comment'));
        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:viewdashboard', $context);
        // Sesskey do framework (lib/ajax/service.php) kiểm tra; không gọi ở đây để WS token hoạt động.
        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:viewdashboard');

        if (!$DB->get_manager()->table_exists('kanban_card_comments')) {
            throw new \moodle_exception('modulerequiresupgrade', 'mod_kanban');
        }

        $record = (object)['cardid' => $card->id, 'userid' => $USER->id, 'comment' => trim($params['comment']), 'timecreated' => time()];
        if ($record->comment === '') {
            throw new \moodle_exception('commentrequired', 'mod_kanban');
        }
        $commentid = $DB->insert_record('kanban_card_comments', $record);
        \mod_kanban\event\comment_created::create([
            'objectid' => $commentid,
            'context' => $context,
            'other' => ['cardid' => $card->id, 'kanbanid' => $kanban->id],
        ])->trigger();
        kanban_log_card_change($card->id, 'commented', get_string('history_commented_teacher', 'mod_kanban'));
        kanban_notify_card_assignees($card, $cm, 'updated');
        return ['status' => true, 'message' => get_string('msg_comment_saved', 'mod_kanban')];
    }

    public static function add_teacher_comment_returns() {
        return new \core_external\external_single_structure([
            'status' => new \core_external\external_value(PARAM_BOOL),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Thông báo', VALUE_DEFAULT, '')
        ]);
    }

    public static function add_comment_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID cua the'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID'),
            'comment' => new \core_external\external_value(PARAM_TEXT, 'Noi dung binh luan')
        ]);
    }

    /**
     * Bình luận chung cho mọi thành viên có quyền quản lý card (kể cả sinh viên).
     */
    public static function add_comment($cardid, $cmid, $comment) {
        global $DB, $USER;
        $params = self::validate_parameters(self::add_comment_parameters(), compact('cardid', 'cmid', 'comment'));
        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:managecards', $context);
        // Sesskey do framework (lib/ajax/service.php) kiểm tra; không gọi ở đây để WS token hoạt động.
        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');

        if (!$DB->get_manager()->table_exists('kanban_card_comments')) {
            throw new \moodle_exception('modulerequiresupgrade', 'mod_kanban');
        }

        $record = (object)['cardid' => $card->id, 'userid' => $USER->id, 'comment' => trim($params['comment']), 'timecreated' => time()];
        if ($record->comment === '') {
            throw new \moodle_exception('commentrequired', 'mod_kanban');
        }
        $commentid = $DB->insert_record('kanban_card_comments', $record);
        \mod_kanban\event\comment_created::create([
            'objectid' => $commentid,
            'context' => $context,
            'other' => ['cardid' => $card->id, 'kanbanid' => $kanban->id],
        ])->trigger();
        kanban_log_card_change($card->id, 'commented', get_string('history_commented', 'mod_kanban'));
        kanban_notify_card_assignees($card, $cm, 'updated');
        return ['status' => true, 'message' => get_string('msg_comment_saved', 'mod_kanban')];
    }

    public static function add_comment_returns() {
        return new \core_external\external_single_structure([
            'status' => new \core_external\external_value(PARAM_BOOL),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Thông báo', VALUE_DEFAULT, '')
        ]);
    }

}
