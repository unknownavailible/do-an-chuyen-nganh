-- =====================================================================
-- 1. BẢNG KANBAN (Tương đương cấu hình mở rộng của Courses)
-- Gắn kết trực tiếp với bảng lõi mdl_course của Moodle
-- =====================================================================
CREATE TABLE mdl_kanban (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    course BIGINT(10) NOT NULL DEFAULT 0 COMMENT 'ID môn học (Nối với mdl_course)',
    name VARCHAR(255) NOT NULL COMMENT 'Tên bảng Kanban / Tên bài tập',
    intro TEXT DEFAULT NULL COMMENT 'Mô tả, Đề cương (Syllabus_url, Document_url có thể lưu ở đây)',
    introformat SMALLINT(4) NOT NULL DEFAULT 0,
    timecreated BIGINT(10) NOT NULL DEFAULT 0,
    timemodified BIGINT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY course_idx (course)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Bảng chính lưu thông tin Kanban của môn học';

-- =====================================================================
-- 2. BẢNG CỘT KANBAN (Columns) - [BỔ SUNG ĐỂ KANBAN HOẠT ĐỘNG]
-- Quản lý các cột Todo, Doing, Done
-- =====================================================================
CREATE TABLE mdl_kanban_columns (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    kanbanid BIGINT(10) NOT NULL COMMENT 'Thuộc bảng Kanban nào',
    title VARCHAR(100) NOT NULL COMMENT 'Tên cột (Todo, Doing, Done)',
    color VARCHAR(20) DEFAULT '#f4f5f7' COMMENT 'Màu sắc cột',
    sortorder BIGINT(5) NOT NULL DEFAULT 0 COMMENT 'Thứ tự hiển thị cột',
    wip_limit BIGINT(5) NOT NULL DEFAULT 0 COMMENT 'Giới hạn công việc',
    PRIMARY KEY (id),
    KEY kanbanid_idx (kanbanid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Lưu các cột trạng thái';

-- =====================================================================
-- 3. BẢNG THẺ CÔNG VIỆC (Tasks / Cards) - [ĐÃ GỘP YÊU CẦU CỦA BẠN]
-- =====================================================================
CREATE TABLE mdl_kanban_cards (
    id BIGINT(10) NOT NULL AUTO_INCREMENT COMMENT 'task_id',
    kanbanid BIGINT(10) NOT NULL COMMENT 'Thuộc Kanban/Môn học nào (course_id)',
    columnid BIGINT(10) NOT NULL COMMENT 'ID của cột trạng thái (Tương đương Status: Todo/Doing/Done)',
    groupid BIGINT(10) NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL COMMENT 'Tên task',
    description TEXT DEFAULT NULL COMMENT 'Mô tả task',
    assigned_to BIGINT(10) DEFAULT 0 COMMENT 'Người thực hiện (student_id)',
    duedate BIGINT(10) DEFAULT 0 COMMENT 'Hạn chót (due_date dạng Timestamp)',
    priority VARCHAR(20) NOT NULL DEFAULT 'medium' COMMENT 'Mức độ ưu tiên',
    sortorder BIGINT(5) NOT NULL DEFAULT 0 COMMENT 'Thứ tự hiển thị (display_order)',
    time_allocated BIGINT(10) DEFAULT 0 COMMENT 'Thời gian dự kiến (phút)',
    time_spent BIGINT(10) DEFAULT 0 COMMENT 'Thời gian thực tế đã làm (phút)',
    task_url VARCHAR(255) DEFAULT NULL COMMENT 'Link chi tiết task bên ngoài',
    timecreated BIGINT(10) NOT NULL DEFAULT 0,
    timemodified BIGINT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY kanbanid_idx (kanbanid),
    KEY columnid_idx (columnid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Bảng cốt lõi lưu trữ Công việc (Tasks)';

-- =====================================================================
-- 4. BẢNG THÀNH VIÊN & ĐÁNH GIÁ (Course_Members)
-- =====================================================================
CREATE TABLE mdl_kanban_members (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    kanbanid BIGINT(10) NOT NULL COMMENT 'Thuộc Kanban/Môn học nào',
    userid BIGINT(10) NOT NULL COMMENT 'ID Sinh viên (student_id)',
    groupid BIGINT(10) DEFAULT 0,
    student_role VARCHAR(100) DEFAULT NULL COMMENT 'Vai trò của SV trong nhóm (Trưởng nhóm, Thành viên...)',
    self_evaluation TEXT DEFAULT NULL COMMENT 'Đánh giá cá nhân của SV',
    is_evaluated TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'SV đã đánh giá chưa? (0/1)',
    PRIMARY KEY (id),
    KEY kanbanid_idx (kanbanid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Bảng lưu vai trò và đánh giá cá nhân của sinh viên';

-- =====================================================================
-- 5. BẢNG BÌNH LUẬN & LỊCH SỬ (BỔ SUNG ĐỂ GIẢNG VIÊN TƯƠNG TÁC)
-- =====================================================================
CREATE TABLE mdl_kanban_card_comments (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    cardid BIGINT(10) NOT NULL COMMENT 'ID của task',
    userid BIGINT(10) NOT NULL COMMENT 'ID Giảng viên/Sinh viên bình luận',
    comment TEXT NOT NULL COMMENT 'Nội dung bình luận',
    timecreated BIGINT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mdl_kanban_card_history (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    cardid BIGINT(10) NOT NULL COMMENT 'ID của task',
    userid BIGINT(10) NOT NULL COMMENT 'Người thực hiện hành động',
    action VARCHAR(30) NOT NULL COMMENT 'Hành động (VD: moved, created)',
    details TEXT DEFAULT NULL,
    timecreated BIGINT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;