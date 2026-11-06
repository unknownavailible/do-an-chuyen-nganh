<?php

defined('MOODLE_INTERNAL') || die();

class mod_kanban_external_testcase extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function create_kanban_data(): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/lib/enrollib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user(['firstname' => 'Teacher', 'lastname' => 'One']);
        $studenta = $generator->create_user(['firstname' => 'Student', 'lastname' => 'A']);
        $studentb = $generator->create_user(['firstname' => 'Student', 'lastname' => 'B']);

        $editingteacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        // Enrol (không chỉ role_assign) để require_login() không redirect sang trang enrol.
        $manual = enrol_get_plugin('manual');
        $instance = null;
        foreach (enrol_get_instances($course->id, true) as $enrolinstance) {
            if ($enrolinstance->enrol === 'manual') {
                $instance = $enrolinstance;
                break;
            }
        }
        if (!$instance) {
            $manual->add_instance($course);
            foreach (enrol_get_instances($course->id, true) as $enrolinstance) {
                if ($enrolinstance->enrol === 'manual') {
                    $instance = $enrolinstance;
                    break;
                }
            }
        }
        $manual->enrol_user($instance, $teacher->id, $editingteacherrole->id);
        $manual->enrol_user($instance, $studenta->id, $studentrole->id);
        $manual->enrol_user($instance, $studentb->id, $studentrole->id);

        $groupa = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        groups_add_member($groupa->id, $studenta->id);
        groups_add_member($groupb->id, $studentb->id);

        $cmrecord = $generator->create_module('kanban', [
            'course' => $course->id,
            'name' => 'Kanban test',
            'groupmode' => SEPARATEGROUPS,
        ]);
        // create_module() trả về bản ghi activity (có ->cmid), không phải course module.
        // Lấy course module thật để có ->id (cmid) và ->instance (kanbanid).
        $cm = get_coursemodule_from_instance('kanban', $cmrecord->id, $course->id, false, MUST_EXIST);

        $column1 = $DB->get_record('kanban_columns', ['kanbanid' => $cm->instance, 'sortorder' => 0], '*', MUST_EXIST);
        $column2 = $DB->get_record('kanban_columns', ['kanbanid' => $cm->instance, 'sortorder' => 1], '*', MUST_EXIST);

        return [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2];
    }

    public function test_user_outside_group_cannot_move_card(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;

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
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupb->id;

        $this->expectException(moodle_exception::class);
        \mod_kanban\external\card_api::move_card($cardid, $column2->id, $cm->id);
    }

    public function test_valid_group_member_can_move_card_successfully(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;

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

    public function test_student_in_multiple_course_groups_cannot_create_kanban_card(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $SESSION;

        groups_add_member($groupb->id, $studenta->id);
        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_multiple_groups', 'mod_kanban'));
        \mod_kanban\external\card_api::create_card(
            $cm->instance,
            $column1->id,
            $cm->id,
            'Blocked card',
            'Student belongs to two course groups',
            0,
            ''
        );
    }

    public function test_wip_limit_is_enforced_on_create_card(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;
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
        $this->assertTrue(kanban_check_wip_limit($column1, $cm->instance, $groupb->id));

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorwiplimit', 'mod_kanban', (object)[
            'column' => $column1->title,
            'limit' => 1,
        ]));
        \mod_kanban\external\card_api::create_card($cm->instance, $column1->id, $cm->id, 'Second card', 'Should fail', 0, '');
    }

    public function test_wip_limit_is_enforced_when_moving_card(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;
        $DB->set_field('kanban_columns', 'wip_limit', 1, ['id' => $column2->id]);
        $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column2->id,
            'groupid' => $groupa->id,
            'title' => 'Already at limit',
            'description' => '',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Move me',
            'description' => '',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorwiplimit', 'mod_kanban', (object)[
            'column' => $column2->title,
            'limit' => 1,
        ]));
        \mod_kanban\external\card_api::move_card($cardid, $column2->id, $cm->id);
    }

    public function test_moving_card_within_column_updates_contiguous_order(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;

        $cardids = [];
        foreach (['First', 'Second', 'Third'] as $index => $title) {
            $cardids[] = $DB->insert_record('kanban_cards', (object)[
                'kanbanid' => $cm->instance,
                'columnid' => $column1->id,
                'groupid' => $groupa->id,
                'title' => $title,
                'description' => '',
                'assigned_to' => $studenta->id,
                'duedate' => 0,
                'sortorder' => $index,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        \mod_kanban\external\card_api::move_card($cardids[2], $column1->id, $cm->id, 0);

        $cards = $DB->get_records('kanban_cards', [
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
        ], 'sortorder ASC');
        $this->assertSame([$cardids[2], $cardids[0], $cardids[1]], array_keys($cards));
        $this->assertSame([0, 1, 2], array_map(
            static function($card) {
                return (int)$card->sortorder;
            },
            array_values($cards)
        ));
    }

    public function test_moving_card_between_columns_inserts_at_position_and_compacts_source(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;

        $sourceids = [];
        foreach (['Source first', 'Moving card', 'Source last'] as $index => $title) {
            $sourceids[] = $DB->insert_record('kanban_cards', (object)[
                'kanbanid' => $cm->instance,
                'columnid' => $column1->id,
                'groupid' => $groupa->id,
                'title' => $title,
                'description' => '',
                'assigned_to' => $studenta->id,
                'duedate' => 0,
                'sortorder' => $index,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        $destinationids = [];
        foreach (['Destination first', 'Destination last'] as $index => $title) {
            $destinationids[] = $DB->insert_record('kanban_cards', (object)[
                'kanbanid' => $cm->instance,
                'columnid' => $column2->id,
                'groupid' => $groupa->id,
                'title' => $title,
                'description' => '',
                'assigned_to' => $studenta->id,
                'duedate' => 0,
                'sortorder' => $index,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        \mod_kanban\external\card_api::move_card($sourceids[1], $column2->id, $cm->id, 1);

        $sourcecards = $DB->get_records('kanban_cards', [
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
        ], 'sortorder ASC');
        $destinationcards = $DB->get_records('kanban_cards', [
            'kanbanid' => $cm->instance,
            'columnid' => $column2->id,
            'groupid' => $groupa->id,
        ], 'sortorder ASC');
        $this->assertSame([$sourceids[0], $sourceids[2]], array_keys($sourcecards));
        $this->assertSame([$destinationids[0], $sourceids[1], $destinationids[1]], array_keys($destinationcards));
        $this->assertSame([0, 1], array_map(
            static function($card) {
                return (int)$card->sortorder;
            },
            array_values($sourcecards)
        ));
        $this->assertSame([0, 1, 2], array_map(
            static function($card) {
                return (int)$card->sortorder;
            },
            array_values($destinationcards)
        ));
    }

    public function test_card_files_are_listed_and_deleted_for_authorized_group_member(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;
        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Card with attachment',
            'description' => '',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $context = \context_module::instance($cm->id);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'itemid' => $cardid,
            'userid' => $studenta->id,
            'filepath' => '/',
            'filename' => 'attachment.txt',
        ], 'Attachment content');

        $result = \mod_kanban\external\card_api::get_card_files($cardid, $cm->id);
        $this->assertCount(1, $result['files']);
        $this->assertSame('attachment.txt', $result['files'][0]['filename']);
        $this->assertSame($file->get_pathnamehash(), $result['files'][0]['hash']);

        $delete = \mod_kanban\external\card_api::delete_card_file(
            $cardid,
            $cm->id,
            $file->get_pathnamehash()
        );
        $this->assertTrue($delete['status']);
        $this->assertFalse(get_file_storage()->file_exists(
            $context->id,
            'mod_kanban',
            'card_attachments',
            $cardid,
            '/',
            'attachment.txt'
        ));
    }

    public function test_uploaded_attachments_are_saved_and_resolved_for_download(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION, $COURSE, $CFG;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;
        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Uploaded attachment',
            'description' => '',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $filepath = tempnam($CFG->tempdir, 'kanban-upload-');
        $content = 'Attachment uploaded for Kanban card.';
        file_put_contents($filepath, $content);

        try {
            $saved = kanban_save_card_attachments($cardid, $cm, [
                'attachments' => [
                    'name' => ['uploaded.txt', 'second.txt', 'rejected.txt'],
                    'tmp_name' => [$filepath, $filepath, $filepath],
                    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_PARTIAL],
                ],
            ]);

            $this->assertSame(2, $saved);
            $context = \context_module::instance($cm->id);
            $file = get_file_storage()->get_file(
                $context->id,
                'mod_kanban',
                'card_attachments',
                $cardid,
                '/',
                'uploaded.txt'
            );
            $this->assertNotFalse($file);
            $this->assertSame($content, $file->get_content());

            $downloadfile = kanban_get_accessible_card_attachment(
                $course,
                $cm,
                $context,
                'card_attachments',
                [$cardid, 'uploaded.txt']
            );
            $this->assertNotFalse($downloadfile);
            $this->assertSame($content, $downloadfile->get_content());

            $secondfile = get_file_storage()->get_file(
                $context->id,
                'mod_kanban',
                'card_attachments',
                $cardid,
                '/',
                'second.txt'
            );
            $this->assertNotFalse($secondfile);
            $this->assertSame($content, $secondfile->get_content());
        } finally {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    public function test_pluginfile_denies_attachment_download_to_another_group(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;
        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Private attachment',
            'description' => '',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $context = \context_module::instance($cm->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'itemid' => $cardid,
            'userid' => $studenta->id,
            'filepath' => '/',
            'filename' => 'private-download.txt',
        ], 'Private content');

        $this->setUser($studentb);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupb->id;
        $downloadfile = kanban_get_accessible_card_attachment(
            $course,
            $cm,
            $context,
            'card_attachments',
            [$cardid, 'private-download.txt']
        );
        $this->assertFalse($downloadfile);
    }

    public function test_card_files_are_not_listed_to_a_different_group(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;
        $cardid = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Private attachment',
            'description' => '',
            'assigned_to' => $studenta->id,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $context = \context_module::instance($cm->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_kanban',
            'filearea' => 'card_attachments',
            'itemid' => $cardid,
            'userid' => $studenta->id,
            'filepath' => '/',
            'filename' => 'private.txt',
        ], 'Private content');

        $this->setUser($studentb);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupb->id;
        $this->expectException(moodle_exception::class);
        \mod_kanban\external\card_api::get_card_files($cardid, $cm->id);
    }

    public function test_column_can_be_created_updated_reordered_and_deleted(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB;

        $this->setUser($teacher);
        $created = \mod_kanban\external\column_api::create_column(
            $cm->instance,
            $cm->id,
            'Review',
            'Ready for review',
            '#123456',
            4
        );
        $this->assertTrue($created['status']);
        $newcolumnid = $created['columnid'];
        $newcolumn = $DB->get_record('kanban_columns', ['id' => $newcolumnid], '*', MUST_EXIST);
        $this->assertSame('Review', $newcolumn->title);
        $this->assertSame(3, (int)$newcolumn->sortorder);

        $updated = \mod_kanban\external\column_api::update_column(
            $newcolumnid,
            $cm->id,
            'In Review',
            'Peer review',
            '#abcdef',
            6
        );
        $this->assertTrue($updated['status']);
        $newcolumn = $DB->get_record('kanban_columns', ['id' => $newcolumnid], '*', MUST_EXIST);
        $this->assertSame('In Review', $newcolumn->title);
        $this->assertSame('Peer review', $newcolumn->description);
        $this->assertSame('#abcdef', $newcolumn->color);
        $this->assertSame(6, (int)$newcolumn->wip_limit);

        $initialcolumnids = array_map('intval', array_keys($DB->get_records(
            'kanban_columns',
            ['kanbanid' => $cm->instance],
            'sortorder ASC'
        )));
        $initialcolumnids = array_values(array_filter($initialcolumnids, static function($columnid) use ($newcolumnid) {
            return $columnid !== (int)$newcolumnid;
        }));
        $orderedids = array_merge([$newcolumnid], array_reverse($initialcolumnids));
        $reordered = \mod_kanban\external\column_api::reorder_columns($cm->instance, $cm->id, $orderedids);
        $this->assertTrue($reordered['status']);
        foreach ($orderedids as $position => $columnid) {
            $this->assertSame($position, (int)$DB->get_field('kanban_columns', 'sortorder', ['id' => $columnid]));
        }

        $deleted = \mod_kanban\external\column_api::delete_column($newcolumnid, $cm->id);
        $this->assertTrue($deleted['status']);
        $this->assertFalse($DB->record_exists('kanban_columns', ['id' => $newcolumnid]));
        $remaining = $DB->get_records('kanban_columns', ['kanbanid' => $cm->instance], 'sortorder ASC');
        $this->assertSame(array_reverse($initialcolumnids), array_map('intval', array_keys($remaining)));
        $this->assertSame(range(0, count($initialcolumnids) - 1), array_map(
            static function($column) {
                return (int)$column->sortorder;
            },
            array_values($remaining)
        ));
    }

    public function test_nonempty_column_cannot_be_deleted(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB;

        $this->setUser($teacher);
        $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $cm->instance,
            'columnid' => $column1->id,
            'groupid' => $groupa->id,
            'title' => 'Card in nonempty column',
            'description' => '',
            'assigned_to' => 0,
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorcolumnnotempty', 'mod_kanban', $column1->title));
        \mod_kanban\external\column_api::delete_column($column1->id, $cm->id);
    }

    public function test_deadline_is_saved_and_overdue_detected(): void {
        [$course, $teacher, $studenta, $studentb, $groupa, $groupb, $cm, $column1, $column2] = $this->create_kanban_data();
        global $DB, $SESSION;

        $this->setUser($studenta);
        $SESSION->activegroup[$course->id][SEPARATEGROUPS][0] = $groupa->id;

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
