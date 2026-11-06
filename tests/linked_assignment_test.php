<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the read-only Assignment integration.
 */
final class mod_kanban_linked_assignment_testcase extends advanced_testcase {

    /**
     * Verify the dashboard reads current submission and grade data without creating records.
     */
    public function test_progress_reads_submission_and_grade_without_writing(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user(['firstname' => 'Student', 'lastname' => 'Submitted']);
        $studentwithoutsubmission = $generator->create_user(['firstname' => 'Student', 'lastname' => 'Pending']);
        $editingteacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $generator->enrol_user($teacher->id, $course->id, $editingteacherrole->id);
        $generator->enrol_user($student->id, $course->id, $studentrole->id);
        $generator->enrol_user($studentwithoutsubmission->id, $course->id, $studentrole->id);

        $assign = $generator->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'name' => 'Linked assignment',
            'grade' => 100,
        ]);
        $assignmentcm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);
        $kanban = $generator->create_module('kanban', [
            'course' => $course->id,
            'name' => 'Linked Kanban',
            'assignmentid' => $assign->id,
        ]);
        $kanbancm = get_coursemodule_from_instance('kanban', $kanban->id, $course->id, false, MUST_EXIST);

        $DB->insert_record('assign_submission', (object)[
            'assignment' => $assign->id,
            'userid' => $student->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'status' => 'submitted',
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);
        $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign->id,
            'userid' => $student->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'grader' => $teacher->id,
            'grade' => 85,
            'attemptnumber' => 0,
        ]);

        $this->setUser($teacher);
        $beforecount = $DB->count_records('assign_submission', ['assignment' => $assign->id]);
        $beforegradecount = $DB->count_records('assign_grades', ['assignment' => $assign->id]);
        $progress = kanban_get_linked_assignment_progress($kanban, $kanbancm);

        $this->assertSame('ready', $progress['state']);
        $this->assertTrue($progress['showgrades']);
        $this->assertCount(2, $progress['rows']);
        $rowsbyname = [];
        foreach ($progress['rows'] as $row) {
            $rowsbyname[$row['name']] = $row;
        }
        $this->assertSame(get_string('submissionstatus_submitted', 'assign'), $rowsbyname[fullname($student)]['status']);
        $this->assertTrue($rowsbyname[fullname($student)]['hasgrade']);
        $this->assertNotEmpty($rowsbyname[fullname($student)]['grade']);
        $this->assertSame(get_string('submissionstatus_', 'assign'),
            $rowsbyname[fullname($studentwithoutsubmission)]['status']);
        $this->assertFalse($rowsbyname[fullname($studentwithoutsubmission)]['hasgrade']);

        $this->assertSame($beforecount, $DB->count_records('assign_submission', ['assignment' => $assign->id]));
        $this->assertSame($beforegradecount, $DB->count_records('assign_grades', ['assignment' => $assign->id]));
        $this->assertFalse($DB->record_exists('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $studentwithoutsubmission->id,
        ]));

        $this->setUser($student);
        $restrictedprogress = kanban_get_linked_assignment_progress($kanban, $kanbancm);
        $this->assertSame('nopermission', $restrictedprogress['state']);
        $this->assertSame([], $restrictedprogress['rows']);
    }
}
