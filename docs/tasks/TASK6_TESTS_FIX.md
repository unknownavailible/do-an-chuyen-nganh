# Task 6 — PHPUnit / Behat tests

## Mục tiêu
Viết bộ test cho plugin `mod_kanban` nhằm kiểm tra các trường hợp rủi ro chính đã được xử lý trong các task trước:
- quyền truy cập theo group
- move card trong group hợp lệ / không hợp lệ
- WIP limit ở server
- lưu và đọc deadline

## File đã tạo
- `mod/kanban/tests/kanban_external_test.php`
- `mod/kanban/tests/behat/mod_kanban.feature`

## Nội dung PHPUnit
File `tests/kanban_external_test.php` bao gồm các test chính:

1. `test_user_outside_group_cannot_move_card`
   - user ở group khác không thể move card của group A
   - kỳ vọng exception `moodle_exception`

2. `test_valid_group_member_can_move_card_successfully`
   - user trong cùng group có quyền move card
   - dữ liệu trong DB phải cập nhật đúng column mới

3. `test_wip_limit_is_enforced_on_create_card`
   - khi column đã đạt `wip_limit`, create card phải bị server từ chối
   - không phụ thuộc UI/JS

4. `test_deadline_is_saved_and_overdue_detected`
   - lưu deadline đúng
   - card quá hạn có giá trị `duedate < time()` và thực thể được tạo đúng

## Nội dung Behat
File `tests/behat/mod_kanban.feature` mô tả các kịch bản sau:
- giáo viên tạo activity kanban
- tạo 2 group khác nhau
- gán student vào mỗi group
- student group A thao tác thành công trên card của mình
- student group B bị từ chối khi thao tác card group A
- WIP limit bị server chặn

## Phương pháp xây dựng test
- Dùng generator Moodle chuẩn cho course, user, group, activity
- Không hardcode ID
- Sử dụng `advanced_testcase` cho PHPUnit
- Dùng `behat` feature như template cho kịch bản giao diện và quyền nhóm

## Xác minh syntax
Đã chạy PHP lint cho file PHPUnit mới:
- `No syntax errors detected in mod/kanban/tests/kanban_external_test.php`

## Ghi chú
Đây là bộ test quan trọng để kiểm tra logic bảo mật và server-side validation của plugin. Tuy nhiên, để chạy đầy đủ trong môi trường Moodle thật cần phải bootstrap đủ môi trường PHPUnit/Behat của project Moodle, không chỉ syntax check đơn thuần.
