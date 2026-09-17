<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings->add(new admin_setting_configcheckbox(
        'mod_kanban/enablenotifications',
        get_string('enablenotifications', 'mod_kanban'),
        get_string('enablenotifications_desc', 'mod_kanban'),
        1
    ));
}
