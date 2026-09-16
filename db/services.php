<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    // 1. Service di chuyển thẻ khi kéo thả
    'mod_kanban_move_card' => [
        'classname'   => 'mod_kanban\external\card_api',
        'methodname'  => 'move_card',
        'description' => 'Di chuyển thẻ sang cột khác',
        'type'        => 'write',
        'ajax'        => true,
    ],
    // 2. Service tạo thẻ công việc mới
    'mod_kanban_create_card' => [
        'classname'   => 'mod_kanban\external\card_api',
        'methodname'  => 'create_card',
        'description' => 'Tạo một thẻ công việc mới',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'mod_kanban_update_card' => [
        'classname'   => 'mod_kanban\external\card_api',
        'methodname'  => 'update_card',
        'description' => 'Cập nhật thông tin một thẻ công việc',
        'type'        => 'write',
        'ajax'        => true,
    ],
    // 3. Service Xóa thẻ công việc (MỚI THÊM)
    'mod_kanban_delete_card' => [
        'classname'   => 'mod_kanban\external\card_api',
        'methodname'  => 'delete_card',
        'description' => 'Xóa một thẻ công việc',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'mod_kanban_get_card_activity' => [
        'classname' => 'mod_kanban\\external\\card_api',
        'methodname' => 'get_card_activity',
        'description' => 'Lay binh luan va lich su cua the',
        'type' => 'read',
        'ajax' => true,
    ],
'mod_kanban_add_teacher_comment' => [
        'classname' => 'mod_kanban\\external\\card_api',
        'methodname' => 'add_teacher_comment',
        'description' => 'Them binh luan cua giao vien vao the',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_kanban_get_card_files' => [
        'classname' => 'mod_kanban\\external\\card_api',
        'methodname' => 'get_card_files',
        'description' => 'Lấy danh sách file đính kèm của thẻ',
        'type' => 'read',
        'ajax' => true,
    ],
    'mod_kanban_delete_card_file' => [
        'classname' => 'mod_kanban\\external\\card_api',
        'methodname' => 'delete_card_file',
        'description' => 'Xóa file đính kèm khỏi thẻ',
        'type' => 'write',
        'ajax' => true,
    ],
];
