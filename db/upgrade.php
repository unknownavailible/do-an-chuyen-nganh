<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_kanban_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026082800) {
        $table = new xmldb_table('kanban_card_comments');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('cardid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('cardid_idx', XMLDB_INDEX_NOTUNIQUE, ['cardid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('kanban_card_history');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('cardid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('action', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL);
        $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('cardid_time_idx', XMLDB_INDEX_NOTUNIQUE, ['cardid', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026082800, 'kanban');
    }

    if ($oldversion < 2026090600) {
        $table = new xmldb_table('kanban_cards');
        $field = new xmldb_field('priority');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026090600, 'kanban');
    }

    if ($oldversion < 2026090603) {
        $table = new xmldb_table('kanban');
        $field = new xmldb_field('creatorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'course');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026090603, 'kanban');
    }

    if ($oldversion < 2026091202) {
        $table = new xmldb_table('kanban_card_assignees');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('cardid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('card_user_unique', XMLDB_INDEX_UNIQUE, ['cardid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026091202, 'kanban');
    }

    if ($oldversion < 2026091203) {
        // Backup/restore support is implemented without a schema change.
        upgrade_mod_savepoint(true, 2026091203, 'kanban');
    }

    if ($oldversion < 2026091601) {
        $table = new xmldb_table('kanban_columns');
        $field = new xmldb_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null, 'title');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026091601, 'kanban');
    }

    return true;
}
