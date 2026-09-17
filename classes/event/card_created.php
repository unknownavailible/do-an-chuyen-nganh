<?php
namespace mod_kanban\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event khi má»™t tháº» Kanban Ä‘Æ°á»£c táº¡o.
 */
class card_created extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'kanban_cards';
    }

    public static function get_name() {
        return get_string('eventcardcreated', 'mod_kanban');
    }

    public function get_description() {
        return "The user with id '{$this->userid}' created the card with id '{$this->objectid}' " .
            "in column '{$this->other['columnid']}' of the Kanban with id '{$this->other['kanbanid']}'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/kanban/view.php', ['id' => $this->contextinstanceid]);
    }

    public static function get_objectid_mapping() {
        return ['db' => 'kanban_cards', 'restore' => 'kanban_card'];
    }
}
