<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Form cấu hình khi tạo/chỉnh sửa hoạt động Kanban.
 */
class mod_kanban_mod_form extends moodleform_mod {

    /**
     * Định nghĩa các trường dữ liệu trên Form.
     */
    public function definition() {
        $mform = $this->_form;

        // 1. Tên bài tập Kanban
        $mform->addElement('text', 'name', get_string('kanbanname', 'mod_kanban'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // 2. Phần mô tả / Yêu cầu đề bài (Tích hợp sẵn bộ soạn thảo rich text của Moodle)
        $this->standard_intro_elements();

        // 2b. Liên kết bài tập (mod_assign) trong cùng khóa học.
        $assignoptions = [0 => get_string('nolinkedassignment', 'mod_kanban')];
        $courseid = !empty($this->_course->id) ? $this->_course->id : 0;
        if ($courseid && class_exists('cm_info')) {
            try {
                $modinfo = get_fast_modinfo($courseid);
                foreach ($modinfo->get_instances_of('assign') as $cm) {
                    $assignoptions[$cm->instance] = format_string($cm->name);
                }
            } catch (Exception $e) {
                // Giữ lại lựa chọn mặc định nếu chưa lấy được danh sách assignment.
            }
        }
        $mform->addElement('select', 'assignmentid', get_string('linkedassignment', 'mod_kanban'), $assignoptions);
        $mform->setType('assignmentid', PARAM_INT);
        $mform->setDefault('assignmentid', 0);
        $mform->addHelpButton('assignmentid', 'linkedassignment', 'mod_kanban');

        // 3. Các cài đặt chung của Moodle (Nhóm sinh viên, Ẩn/Hiện, Điều kiện hoàn thành...)
        $this->standard_coursemodule_elements();

        // 4. Nút bấm Lưu và Hủy
        $this->add_action_buttons();
    }
}