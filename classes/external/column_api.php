<?php

namespace mod_kanban\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;
use moodle_exception;
use stdClass;

class column_api extends external_api {

    public static function create_column_parameters() {
        return new external_function_parameters([
            'kanbanid' => new external_value(PARAM_INT, 'Kanban ID'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'title' => new external_value(PARAM_TEXT, 'Column title'),
            'description' => new external_value(PARAM_RAW, 'Column description', VALUE_DEFAULT, ''),
            'color' => new external_value(PARAM_RAW_TRIMMED, 'Column color', VALUE_DEFAULT, '#f4f5f7'),
            'wip_limit' => new external_value(PARAM_INT, 'WIP limit', VALUE_DEFAULT, 0),
        ]);
    }

    public static function create_column($kanbanid, $cmid, $title, $description = '', $color = '#f4f5f7', $wip_limit = 0) {
        global $DB;
        $params = self::validate_parameters(self::create_column_parameters(), compact(
            'kanbanid', 'cmid', 'title', 'description', 'color', 'wip_limit'
        ));
        [$cm, $kanban] = self::validate_context_and_kanban($params['cmid'], $params['kanbanid']);
        self::validate_column_values($params['title'], $params['color'], $params['wip_limit']);

        $maxorder = $DB->get_field_sql('SELECT MAX(sortorder) FROM {kanban_columns} WHERE kanbanid = ?', [$kanban->id]);
        $column = (object)[
            'kanbanid' => $kanban->id,
            'title' => $params['title'],
            'description' => $params['description'],
            'color' => $params['color'],
            'sortorder' => ((int)$maxorder) + 1,
            'wip_limit' => $params['wip_limit'],
        ];
        return ['status' => true, 'columnid' => $DB->insert_record('kanban_columns', $column)];
    }

    public static function create_column_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Result'),
            'columnid' => new external_value(PARAM_INT, 'Created column ID'),
        ]);
    }

    public static function update_column_parameters() {
        return new external_function_parameters([
            'columnid' => new external_value(PARAM_INT, 'Column ID'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'title' => new external_value(PARAM_TEXT, 'Column title'),
            'description' => new external_value(PARAM_RAW, 'Column description', VALUE_DEFAULT, ''),
            'color' => new external_value(PARAM_RAW_TRIMMED, 'Column color', VALUE_DEFAULT, '#f4f5f7'),
            'wip_limit' => new external_value(PARAM_INT, 'WIP limit', VALUE_DEFAULT, 0),
        ]);
    }

    public static function update_column($columnid, $cmid, $title, $description = '', $color = '#f4f5f7', $wip_limit = 0) {
        global $DB;
        $params = self::validate_parameters(self::update_column_parameters(), compact(
            'columnid', 'cmid', 'title', 'description', 'color', 'wip_limit'
        ));
        [$cm, $kanban] = self::validate_context_and_kanban($params['cmid']);
        self::validate_column_values($params['title'], $params['color'], $params['wip_limit']);
        $column = $DB->get_record('kanban_columns', [
            'id' => $params['columnid'], 'kanbanid' => $kanban->id
        ], '*', MUST_EXIST);
        $column->title = $params['title'];
        $column->description = $params['description'];
        $column->color = $params['color'];
        $column->wip_limit = $params['wip_limit'];
        $DB->update_record('kanban_columns', $column);
        return ['status' => true];
    }

    public static function update_column_returns() {
        return new external_single_structure(['status' => new external_value(PARAM_BOOL, 'Result')]);
    }

    public static function delete_column_parameters() {
        return new external_function_parameters([
            'columnid' => new external_value(PARAM_INT, 'Column ID'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    public static function delete_column($columnid, $cmid) {
        global $DB;
        $params = self::validate_parameters(self::delete_column_parameters(), compact('columnid', 'cmid'));
        [$cm, $kanban] = self::validate_context_and_kanban($params['cmid']);
        $column = $DB->get_record('kanban_columns', [
            'id' => $params['columnid'], 'kanbanid' => $kanban->id
        ], '*', MUST_EXIST);
        if ($DB->record_exists('kanban_cards', ['columnid' => $column->id])) {
            throw new moodle_exception('errorcolumnnotempty', 'mod_kanban', '', $column->title);
        }
        if ($DB->count_records('kanban_columns', ['kanbanid' => $kanban->id]) <= 1) {
            throw new moodle_exception('cannotdeleteallcolumns', 'mod_kanban');
        }
        $DB->delete_records('kanban_columns', ['id' => $column->id]);
        self::normalize_sortorder($kanban->id);
        return ['status' => true];
    }

    public static function delete_column_returns() {
        return new external_single_structure(['status' => new external_value(PARAM_BOOL, 'Result')]);
    }

    public static function reorder_columns_parameters() {
        return new external_function_parameters([
            'kanbanid' => new external_value(PARAM_INT, 'Kanban ID'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'columnids' => new external_multiple_structure(new external_value(PARAM_INT, 'Column ID')),
        ]);
    }

    public static function reorder_columns($kanbanid, $cmid, $columnids) {
        global $DB;
        $params = self::validate_parameters(self::reorder_columns_parameters(), compact('kanbanid', 'cmid', 'columnids'));
        [$cm, $kanban] = self::validate_context_and_kanban($params['cmid'], $params['kanbanid']);
        $columns = $DB->get_records('kanban_columns', ['kanbanid' => $kanban->id], '', 'id');
        $ids = array_map('intval', $params['columnids']);
        sort($ids);
        $existing = array_map('intval', array_keys($columns));
        sort($existing);
        if ($ids !== $existing) {
            throw new moodle_exception('invalidrecord');
        }
        foreach (array_values($params['columnids']) as $position => $columnid) {
            $DB->set_field('kanban_columns', 'sortorder', $position, ['id' => (int)$columnid]);
        }
        return ['status' => true];
    }

    public static function reorder_columns_returns() {
        return new external_single_structure(['status' => new external_value(PARAM_BOOL, 'Result')]);
    }

    private static function validate_context_and_kanban($cmid, $kanbanid = 0) {
        global $DB;
        $cm = get_coursemodule_from_id('kanban', $cmid, 0, false, MUST_EXIST);
        require_login(get_course($cm->course), false, $cm);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        if (!has_capability('mod/kanban:managecolumns', $context) &&
                !has_capability('mod/kanban:viewdashboard', $context)) {
            throw new moodle_exception('nopermissions', 'error');
        }
        require_sesskey();
        $kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($kanbanid && (int)$kanbanid !== (int)$kanban->id) {
            throw new moodle_exception('invalidrecord');
        }
        return [$cm, $kanban];
    }

    private static function validate_column_values($title, $color, $wiplimit) {
        if (trim($title) === '' || strlen($title) > 100) {
            throw new moodle_exception('invalidrecord');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new moodle_exception('errorinvalidcolumncolor', 'mod_kanban');
        }
        if ((int)$wiplimit < 0) {
            throw new moodle_exception('errorinvalidwiplimit', 'mod_kanban');
        }
    }

    private static function normalize_sortorder($kanbanid) {
        global $DB;
        $columns = $DB->get_records('kanban_columns', ['kanbanid' => $kanbanid], 'sortorder ASC, id ASC');
        foreach (array_values($columns) as $position => $column) {
            $DB->set_field('kanban_columns', 'sortorder', $position, ['id' => $column->id]);
        }
    }
}
