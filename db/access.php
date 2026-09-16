<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Quyền thêm bài tập Kanban vào khóa học (Dành cho Giảng viên & Quản trị viên)
    'mod/kanban:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],

    // Quyền xem bảng Kanban (Giảng viên và Sinh viên đều được xem)
    'mod/kanban:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'student' => CAP_ALLOW,
        ],
    ],

    // Quyền tạo thẻ, chỉnh sửa và kéo thả thẻ công việc
    'mod/kanban:managecards' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // Quyền tạo, sửa, xóa và sắp xếp các cột Kanban.
    'mod/kanban:managecolumns' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    ],

    // Quyền xem Dashboard thống kê tiến độ nhóm (Dành cho Giảng viên)
    'mod/kanban:viewdashboard' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
