<?php

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\mod_kanban\task\deadline_notifications',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
