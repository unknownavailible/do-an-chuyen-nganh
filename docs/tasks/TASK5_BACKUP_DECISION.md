# Task 5 - Backup/Restore

## Quyết định

Đã chọn phương án A: triển khai backup/restore Moodle 2 đầy đủ.

## Phạm vi dữ liệu

Bộ backup/restore trong `backup/moodle2/` bao gồm:

- Cấu hình activity Kanban và phần giới thiệu.
- Columns, WIP limit và thứ tự cột.
- Cards, deadline, mô tả, nhóm, thứ tự và các trường thời gian.
- Card assignees, Kanban members, comments và history.
- File đính kèm trong filearea `card_attachments`.

Restore tạo ID mới cho activity, columns và cards; các quan hệ nội bộ được map
lại. User và group được map qua mapping của Moodle. Nếu user/group không tồn tại
trong bản restore, dữ liệu liên quan được bỏ qua hoặc chuyển về 0 phù hợp.

## Thay đổi

- `kanban_supports()` trả về `true` cho `FEATURE_BACKUP_MOODLE2`.
- Thêm backup task và structure step.
- Thêm restore task và structure step.
- Bump version lên `2026091203` và thêm savepoint nâng cấp.
- Chuẩn hóa upload card dùng filearea `card_attachments`.
- Khi xóa activity, dọn cả dữ liệu phụ và file đính kèm.

## Kiểm tra

Đã chạy PHP lint cho toàn bộ file PHP thay đổi; tất cả đều không có lỗi cú
pháp. Cần chạy backup một course có Kanban rồi restore vào course khác trên
Moodle thực tế để xác nhận dữ liệu và file sau restore.
