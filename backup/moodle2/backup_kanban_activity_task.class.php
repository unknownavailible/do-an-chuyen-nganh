<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/kanban/backup/moodle2/backup_kanban_stepslib.php');

class backup_kanban_activity_task extends backup_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new backup_kanban_activity_structure_step('kanban_structure', 'kanban.xml'));
    }

    static public function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot . '/mod/kanban', '#');
        return preg_replace("#($base)/view\.php\?id=(\d+)#", '$1/view.php?id=$2', $content);
    }
}
