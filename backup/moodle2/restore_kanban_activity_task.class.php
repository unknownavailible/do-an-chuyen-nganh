<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/kanban/backup/moodle2/restore_kanban_stepslib.php');

class restore_kanban_activity_task extends restore_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new restore_kanban_activity_structure_step('kanban_structure', 'kanban.xml'));
    }

    public static function define_decode_contents() {
        return [];
    }

    public static function define_decode_rules() {
        return [];
    }
}
