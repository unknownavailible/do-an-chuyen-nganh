<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Generator cho mod_kanban phục vụ PHPUnit.
 *
 * create_instance() của lớp cha (testing_module_generator) gọi add_moduleinfo(),
 * đi qua kanban_add_instance() nên 3 cột mặc định (To Do, In Progress, Done)
 * được tạo tự động, không cần làm gì thêm ở đây.
 */
class mod_kanban_generator extends testing_module_generator {

    /**
     * Tạo một card trong cột cho test.
     *
     * @param array|stdClass $record gồm kanbanid, columnid và các field của card.
     * @return stdClass bản ghi card vừa tạo.
     */
    public function create_card($record) {
        global $DB;

        $record = (array)$record;
        $defaults = [
            'groupid' => 0,
            'title' => 'Test card',
            'description' => '',
            'assigned_to' => 0,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        foreach ($defaults as $field => $value) {
            if (!array_key_exists($field, $record)) {
                $record[$field] = $value;
            }
        }
        if (empty($record['kanbanid']) || empty($record['columnid'])) {
            throw new coding_exception('mod_kanban_generator::create_card() requires kanbanid and columnid.');
        }
        $id = $DB->insert_record('kanban_cards', (object)$record);
        return $DB->get_record('kanban_cards', ['id' => $id], '*', MUST_EXIST);
    }
}
