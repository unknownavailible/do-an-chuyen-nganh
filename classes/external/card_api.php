<?php
namespace mod_kanban\external;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");
require_once(__DIR__ . '/../../lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use context_module;
use stdClass;

class card_api extends \core_external\external_api {

    // 1. Di chuyển thẻ
    public static function move_card_parameters() {
        return new \core_external\external_function_parameters([
            'cardid' => new \core_external\external_value(PARAM_INT, 'ID của thẻ'),
            'targetcolumnid' => new \core_external\external_value(PARAM_INT, 'ID của cột đích'),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module ID')
        ]);
    }

    public static function move_card($cardid, $targetcolumnid, $cmid) {
        global $DB;
        $params = self::validate_parameters(self::move_card_parameters(), [
            'cardid' => $cardid,
            'targetcolumnid' => $targetcolumnid,
            'cmid' => $cmid
        ]);

        $cm = get_coursemodule_from_id('kanban', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kanban:managecards', $context);
        require_sesskey();

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');
        $targetcolumn = kanban_validate_and_get_column($params['targetcolumnid'], $kanban->id, $cm, 'mod/kanban:managecards');

        if ((int)$card->columnid !== (int)$params['targetcolumnid']) {
            kanban_check_wip_limit($targetcolumn, $kanban->id, $card->id);
        }

        $card->columnid = $params['targetcolumnid'];
        $card->timemodified = time();
        $DB->update_record('kanban_cards', $card);
        kanban_log_card_change($card->id, 'moved', 'Chuyen sang cot ' . $targetcolumn->title);
        kanban_notify_card_assignees($card, $cm, 'updated');

        return ['status' => true, 'message' => 'Di chuyển thẻ thành công'];
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
        require_sesskey();

        $kanban = $DB->get_record('kanban', ['id' => $params['kanbanid']], '*', MUST_EXIST);
        if ((int)$kanban->id !== (int)$cm->instance) {
            throw new \moodle_exception('invalidrecord');
        }

        $targetcolumn = kanban_validate_and_get_column($params['columnid'], $kanban->id, $cm, 'mod/kanban:managecards');
        $currentgroup = groups_get_activity_group($cm, true);
        if (!has_capability('moodle/site:accessallgroups', $context) && $currentgroup && !groups_is_member($currentgroup, $USER->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        if ($params['duedate'] > 0 && $params['duedate'] < (time() - 300)) {
            throw new \moodle_exception('error_duedate_past', 'mod_kanban', '', 'Hạn hoàn thành không thể ở trong quá khứ!');
        }

        kanban_check_wip_limit($targetcolumn, $kanban->id);

        $oldassigneeids = kanban_get_card_assignee_ids($card->id);
        $assigneeids = kanban_validate_assignee_list($cm, $params['assignees']);

        $card = new stdClass();
        $card->kanbanid = $params['kanbanid'];
        $card->columnid = $params['columnid'];
        $card->groupid = $currentgroup ? $currentgroup : 0;
        $card->title = $params['title'];
        $card->description = $params['description'];
        $card->duedate = $params['duedate'];
        $card->task_url = $params['submissionurl'];
        $card->assigned_to = !empty($assigneeids) ? (int) $assigneeids[0] : 0;
        $card->sortorder = 0;
        $card->timecreated = time();
        $card->timemodified = time();

        $cardid = $DB->insert_record('kanban_cards', $card);
        kanban_set_card_assignees($cardid, $assigneeids, $cm);
        kanban_log_card_change($cardid, 'created', 'Tao the');
        $card->id = $cardid;
        kanban_notify_card_assignees($card, $cm, 'assigned');

        return [
            'status' => true,
            'cardid' => $cardid,
            'message' => 'Tạo thẻ thành công'
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
        require_sesskey();

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');

        $title = trim((string) $params['title']);
        if ($title === '') {
            throw new \moodle_exception('required', 'mod_kanban', '', 'Tên công việc không được để trống');
        }

        if ($params['duedate'] > 0 && $params['duedate'] < (time() - 300)) {
            throw new \moodle_exception('error_duedate_past', 'mod_kanban', '', 'Hạn hoàn thành không thể ở trong quá khứ!');
        }

        $assigneeids = kanban_validate_assignee_list($cm, $params['assignees']);

        $card->title = $title;
        $card->description = $params['description'];
        $card->duedate = $params['duedate'];
        $card->task_url = $params['submissionurl'];
        $card->timemodified = time();
        $DB->update_record('kanban_cards', $card);

        kanban_set_card_assignees($card->id, $assigneeids, $cm);
        kanban_log_card_change($card->id, 'updated', 'Cap nhat the');
        if ($oldassigneeids !== $assigneeids) {
            kanban_notify_card_assignees($card, $cm, 'assigned');
        } else {
            kanban_notify_card_assignees($card, $cm, 'updated');
        }

        return ['status' => true, 'message' => 'Cập nhật thẻ thành công'];
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
        require_sesskey();

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');

        kanban_log_card_change($card->id, 'deleted', 'Xoa the');
        $DB->delete_records('kanban_cards', ['id' => $card->id]);

        return ['status' => true, 'message' => 'Xóa thẻ thành công'];
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
        require_sesskey();

        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:managecards');

        $fs = get_file_storage();
        $file = $fs->get_file_by_hash($params['filehash']);
        if (!$file || $file->get_itemid() !== (int)$card->id || $file->get_contextid() !== $context->id) {
            throw new \moodle_exception('filenotfound', 'error');
        }

        $file->delete();
        return ['status' => true, 'message' => 'File đã được xóa'];
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
        require_sesskey();
        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        $card = kanban_validate_and_get_card($params['cardid'], $cm, $kanban, 'mod/kanban:viewdashboard');

        if (!$DB->get_manager()->table_exists('kanban_card_comments')) {
            throw new \moodle_exception('modulerequiresupgrade', 'mod_kanban');
        }

        $record = (object)['cardid' => $card->id, 'userid' => $USER->id, 'comment' => trim($params['comment']), 'timecreated' => time()];
        if ($record->comment === '') {
            throw new \moodle_exception('commentrequired', 'mod_kanban');
        }
        $DB->insert_record('kanban_card_comments', $record);
        kanban_log_card_change($card->id, 'commented', 'Them binh luan cua giao vien');
        kanban_notify_card_assignees($card, $cm, 'updated');
        return ['status' => true, 'message' => 'Bình luận đã được lưu'];
    }

    public static function add_teacher_comment_returns() {
        return new \core_external\external_single_structure([
            'status' => new \core_external\external_value(PARAM_BOOL),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Thông báo', VALUE_DEFAULT, '')
        ]);
    }

}
