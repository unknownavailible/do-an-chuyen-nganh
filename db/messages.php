<?php

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'cardassigned' => [
        'capability' => 'mod/kanban:view',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
    'cardupdated' => [
        'capability' => 'mod/kanban:view',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
    'deadline' => [
        'capability' => 'mod/kanban:view',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
];
