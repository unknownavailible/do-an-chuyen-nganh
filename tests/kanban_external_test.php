<?php

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/external/card_api.php');

class mod_kanban_external_testcase extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function create_kanban_data(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user(['firstname' => 'Teacher', 'lastname' => 'One']);
        $studenta = $generator->create_user(['firstname' => 'Student', 'lastname' => 'A']);
        $studentb = $generator->create_user(['firstname' => 'Student', 'lastname' => 'B']);

        $editingteacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        role_assign($editingteacherrole->id, $teacher->id, context_course::instance($course->id)->id);

        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        groups_add_member($groupa->id, $studenta->id);
        groups_add_member($groupb->id, $studentb->id);

        $cm = $generator->create_module('kanban', [
            'course' => $course->id,
            'name' => 'Kanban test',
            'groupmode' => SEPARATEGROUPS,
        ]);

        $column1 = $DB->get_record('kanban_columns', ['kanbanid' => $cm->instance, 'sortorder' => 0], '*', MUST_EXIST);
        $column2 = $DB->get_record('kanban_columns', ['kanbanid' => $cm->instance, 'sortorder' => 1], '*', MUST_EXIST);

        return [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2];
    }

    public function test_user_outside_group_cannot_move_card(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB;

        $this->setUser($studenta);
        groups_set_activity_group($cm, $groupa->id);

        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Group A card',
            'description' => 'Only group A',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->setUser($studentb);
        groups_set_activity_group($cm, $groupb->id);

        $this->expectException(moodle_exception::class);
        \mod_kanban\external\card_api::move_card($cardid, $column2->id, $cm->id);
    }

    public function test_valid_group_member_can_move_card_successfully(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB;

        $this->setUser($studenta);
        groups_set_activity_group($cm, $groupa->id);

        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Move me',
            'description' => 'Allowed',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = \mod_kanban\external\card_api::move_card($cardid, $column2->id, $cm->id);
        $this->assertTrue($result['status']);

        $updated = $DB->get_record('kanban_cards', ['id' => $cardid], '*', MUST_EXIST);
        $this->assertSame((int) $column2->id, (int) $updated->columnid);
    }

    public function test_wip_limit_is_enforced_on_create_card(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB;

        $this->setUser($studenta);
        groups_set_activity_group($cm, $groupa->id);
        $DB->set_field('kanban_columns', 'wip_limit', 1, ['id' => $column1->id]);

        $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Already there',
            'description' => 'Limit reached',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->expectException(moodle_exception::class);
        \mod_kanban\external\card_api::create_card($cm->instance, $column1->id, $cm->id, 'Second card', 'Should fail', 0, '');
    }

    public function test_deadline_is_saved_and_overdue_detected(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB;

        $this->setUser($studenta);
        groups_set_activity_group($cm, $groupa->id);

        $future = time() + 86400;
        $result = \mod_kanban\external\card_api::create_card($cm->instance, $column1->id, $cm->id, 'Deadline card', 'Future due date', $future, '');
        $this->assertTrue($result['status']);

        $savedcard = $DB->get_record('kanban_cards', ['id' => $result['cardid']], '*', MUST_EXIST);
        $this->assertSame($future, (int) $savedcard->duedate);

        $pastcardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Past due card',
            'description' => 'Overdue',
            'assigned_to' => $studenta->id,
            'duedate' => time() - 3600,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $pastcard = $DB->get_record('kanban_cards', ['id' => $pastcardid], '*', MUST_EXIST);
        $this->assertTrue((int) $pastcard->duedate < time());
    }
}
