# Hướng dẫn sử dụng Backup/Restore Kanban

Tài liệu này hướng dẫn giáo viên và quản trị viên sao lưu, khôi phục hoạt động
Kanban bằng giao diện Moodle.

## 1. Điều kiện cần thiết

- Người thao tác có quyền backup hoặc restore trong Moodle.
- Plugin `mod_kanban` đã được cài đặt và Moodle đã hoàn tất nâng cấp plugin.
- Hoạt động Kanban đã được tạo trong course.
- Nếu cần sao lưu file đính kèm, file phải được thêm vào card thông qua filearea
  `card_attachments`.

## 2. Tạo backup cho một course

1. Đăng nhập Moodle bằng tài khoản có quyền giáo viên hoặc quản trị viên.
2. Mở course chứa hoạt động Kanban.
3. Chọn **More** hoặc **Thêm** trong thanh điều hướng của course.
4. Chọn **Course reuse**.
5. Chọn **Backup**.
6. Ở bước **Initial settings**, giữ lựa chọn backup hoạt động và dữ liệu người
   dùng theo nhu cầu.
7. Ở bước **Schema settings**, kiểm tra hoạt động Kanban cần sao lưu.
8. Nhấn **Next** qua các bước còn lại.
9. Đặt tên file backup dễ nhận biết, ví dụ:
   `kanban-course-2026-09-12.mbz`.
10. Nhấn **Perform backup**.
11. Chờ Moodle báo backup hoàn tất.

File backup Moodle có đuôi `.mbz`. Nên tải file về máy và lưu ở nơi an toàn.

## 3. Dữ liệu Kanban được sao lưu

Backup bao gồm:

- Tên, mô tả và thông tin activity Kanban.
- Các column, màu sắc, thứ tự column và WIP limit.
- Card, mô tả, deadline, thứ tự, group và URL nộp bài.
- Danh sách assignee của card.
- Thành viên Kanban.
- Bình luận và lịch sử thay đổi card.
- File đính kèm của card.

Khi restore sang course khác, Moodle tạo ID mới và tự lập lại quan hệ giữa
Kanban, column, card và file.

## 4. Restore vào một course mới

1. Mở course đích hoặc mở danh mục chứa course mới.
2. Chọn **More** > **Course reuse** > **Restore**.
3. Chọn file `.mbz` từ:
   - **User private backup area**, hoặc
   - **Upload a backup file**.
4. Nhấn **Restore**.
5. Kiểm tra phần **Backup details**.
6. Chọn **Restore into this course** nếu muốn nhập vào course hiện tại, hoặc
   **Restore as a new course** để tạo course mới.
7. Chọn category nếu tạo course mới.
8. Giữ các activity Kanban được chọn.
9. Nhấn **Next** và kiểm tra schema.
10. Nhấn **Perform restore**.
11. Chờ Moodle hoàn tất và mở course sau khi restore.

## 5. Kiểm tra sau khi restore

Mở từng hoạt động Kanban và kiểm tra:

- Tên và mô tả Kanban.
- Số lượng column.
- Thứ tự column và WIP limit.
- Số lượng card.
- Card có nằm đúng column hay không.
- Deadline và nội dung card.
- Group và assignee.
- Bình luận và lịch sử.
- File đính kèm có thể tải xuống.

Nếu restore sang course khác, group và user phải tồn tại hoặc được Moodle map
được trong course đích. User/group không thể map sẽ không được khôi phục đầy đủ.

## 6. Lưu ý an toàn

- Không ghi đè course thật khi chỉ muốn kiểm thử; hãy chọn **Restore as a new
  course**.
- Luôn giữ lại file `.mbz` gốc cho đến khi kiểm tra xong course restore.
- Không xóa course gốc trước khi xác nhận dữ liệu restore đầy đủ.
- Backup course có thể chứa dữ liệu người dùng; chỉ lưu trữ và chia sẻ file
  `.mbz` theo chính sách bảo mật của đơn vị.
- Sau khi cập nhật plugin, nên chạy **Purge all caches** trong
  **Site administration > Development > Purge caches** nếu giao diện hoặc
  JavaScript chưa cập nhật.
