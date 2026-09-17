<?php
namespace mod_kanban\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event khi má»™t tháº» Kanban Ä‘Æ°á»£c di chuyá»ƒn/sáº¯p xáº¿p láº¡i.
 */
class card_moved extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'kanban_cards';
    }

    public static function get_name() {
        return get_string('eventcardmoved', 'mod_kanban');
    }

    public function get_description() {
        return "The user with id '{$this->userid}' moved the card with id '{$this->objectid}' " .
            "from column '{$this->other['fromcolumnid']}' to column '{$this->other['tocolumnid']}' " .
            "at position '{$this->other['newposition']}'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/kanban/view.php', ['id' => $this->contextinstanceid]);
    }

    public static function get_objectid_mapping() {
        return ['db' => 'kanban_cards', 'restore' => 'kanban_card'];
    }
}
