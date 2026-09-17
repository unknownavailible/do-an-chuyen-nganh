<?php
namespace mod_kanban\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event khi cÃ³ bÃ¬nh luáº­n má»›i trÃªn tháº» Kanban.
 */
class comment_created extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'kanban_card_comments';
    }

    public static function get_name() {
        return get_string('eventcommentcreated', 'mod_kanban');
    }

    public function get_description() {
        return "The user with id '{$this->userid}' added a comment with id '{$this->objectid}' " .
            "to the card with id '{$this->other['cardid']}'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/kanban/view.php', ['id' => $this->contextinstanceid]);
    }

    public static function get_objectid_mapping() {
        return ['db' => 'kanban_card_comments', 'restore' => self::NOT_MAPPED];
    }
}
