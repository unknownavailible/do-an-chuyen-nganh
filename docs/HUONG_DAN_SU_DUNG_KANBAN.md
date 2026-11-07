# Hướng dẫn sử dụng Moodle Kanban và Kanban Quick View

## 1. Mục đích

Tài liệu hướng dẫn sử dụng activity **Kanban** trong course Moodle và block **Kanban Quick View** (ButtonKB) trên Dashboard. Kanban dùng để phân chia, theo dõi và cập nhật công việc theo các cột trạng thái.

## 2. Vai trò người dùng

| Vai trò | Có thể làm gì |
|---|---|
| Administrator/Manager | Cài plugin, cấp quyền, kiểm tra Notifications, thêm ButtonKB. |
| Teacher | Tạo Kanban, quản lý cột/card, phân công, theo dõi tiến độ. |
| Student | Xem và thao tác card trong group khi được cấp quyền. |
| Guest | Không dùng được Kanban cần đăng nhập. |

## 3. Cài đặt plugin (Administrator)

1. Đặt Kanban vào `moodle/mod/kanban`.
2. Đặt ButtonKB vào `moodle/blocks/buttonkb`.
3. Đăng nhập bằng Administrator.
4. Mở **Site administration → Notifications**.
5. Bấm **Continue** để Moodle tạo/cập nhật database plugin.
6. Nếu ButtonKB không hiển thị ngay, chạy purge cache hoặc đăng xuất/đăng nhập lại.

> Khi dùng Docker repository, hai plugin đã nằm trong thư mục `plugins/` và Dockerfile tự chép chúng vào đúng vị trí khi build.

## 4. Tạo hoạt động Kanban (Teacher)

1. Mở course cần quản lý công việc.
2. Bật **Edit mode**.
3. Chọn **Add an activity or resource**.
4. Chọn **Kanban**.
5. Nhập tên bảng, ví dụ `Bảng công việc nhóm 1`.
6. Nhập mô tả/yêu cầu bài tập nếu cần.
7. Chọn Assignment liên kết nếu muốn hiển thị tên/link/hạn nộp Assignment trong Kanban.
8. Thiết lập group mode theo cách tổ chức nhóm của course.
9. Bấm **Save and display**.

Kanban mới tạo có ba cột mặc định:

```text
To Do → In Progress → Done
```

## 5. Quản lý cột (Teacher)

### 5.1. Thêm cột

1. Mở board Kanban.
2. Bấm **Add column**.
3. Nhập tên cột, chọn màu và WIP limit nếu cần.
4. Lưu thay đổi.

### 5.2. Sửa hoặc sắp xếp cột

- Bấm biểu tượng sửa trên tiêu đề cột để sửa tên, màu hoặc WIP limit.
- Dùng nút mũi tên/trình điều khiển trên cột để thay đổi vị trí.
- Thứ tự được lưu sau khi tải lại trang.

### 5.3. Xóa cột

1. Di chuyển hoặc xóa toàn bộ card trong cột.
2. Bấm biểu tượng thùng rác.
3. Xác nhận xóa.

Không thể xóa cột còn card hoặc xóa cột cuối cùng của board.

## 6. Quản lý card công việc

### 6.1. Tạo card

1. Trong cột mong muốn, bấm **Add Card**.
2. Nhập **Task name** (bắt buộc).
3. Nhập Description nếu cần.
4. Chọn Due date nếu công việc có hạn hoàn thành.
5. Tick người thực hiện ở mục **Assign to**.
6. Có thể nhập Attachment URL hoặc chọn file đính kèm.
7. Bấm **Create task**.

### 6.2. Chỉnh sửa và xóa card

- Bấm biểu tượng sửa trên card để thay đổi thông tin hoặc assignee.
- Bấm biểu tượng thùng rác để xóa card.
- Khi xóa card, dữ liệu liên quan như assignee/comment/history/file được xử lý theo lifecycle plugin.

### 6.3. Di chuyển card

Kéo card sang cột khác hoặc dùng thao tác giao diện có sẵn. Hệ thống sẽ kiểm tra quyền group và WIP limit ở server trước khi lưu.

### 6.4. WIP limit

WIP limit là số card tối đa trong một cột cho group đang xét:

- `0`: không giới hạn.
- `1` trở lên: không thể tạo/di chuyển card nếu cột đã đủ số lượng.

Nếu vượt WIP, hệ thống hiển thị lỗi và không thay đổi card.

## 7. Làm việc theo nhóm và phân quyền

1. Teacher tạo group và thêm student trong course bằng công cụ Groups của Moodle.
2. Cấu hình group mode cho activity Kanban.
3. Student chỉ xem/thao tác card thuộc group được phép.
4. User group khác không thể truy cập card/file bằng URL hoặc AJAX trực tiếp.
5. Teacher/Manager chỉ có thể quản lý cột/card khi được cấp capability phù hợp.

> Không thêm một student vào nhiều group của cùng activity nếu quy trình Kanban yêu cầu mỗi người chỉ thuộc một group; plugin sẽ từ chối thao tác trong trường hợp không rõ group hiện tại.

## 8. Comment, history và file

### 8.1. Xem chi tiết

Bấm **View details** trên card để xem mô tả, assignee, deadline, comment, history và file.

### 8.2. Comment

Người có quyền quản lý card có thể thêm comment để phản hồi hoặc hướng dẫn. Comment được lưu kèm người viết và thời gian.

### 8.3. File đính kèm

- Chọn file khi tạo/sửa card.
- Moodle quản lý file bằng File API.
- Chỉ user có quyền card và group hợp lệ mới tải/xóa file được.

## 9. Assignment, deadline và notification

Nếu Kanban liên kết với Moodle Assignment cùng course:

1. Board hiển thị thông tin Assignment theo quyền Moodle.
2. Student nộp bài tại trang Assignment, không nộp qua Kanban.
3. Kanban chỉ đọc tên/link/hạn nộp; không sửa điểm, feedback hoặc submission.
4. Notification deadline dùng **thông báo nội bộ Moodle**; không gửi email/mobile trong phạm vi hiện tại.

Với Docker, kiểm tra cron bằng:

```powershell
docker compose logs cron
```

Log hợp lệ có dòng `Cron run completed correctly`.

## 10. Dùng Kanban Quick View (ButtonKB)

ButtonKB chỉ hiển thị trên **Dashboard** hoặc **Site home**, không đặt trong trang course.

1. Đăng nhập Moodle.
2. Mở **Dashboard**.
3. Bấm nút lịch nổi **Kanban Quick View** ở mép phải màn hình.
4. Panel hiển thị:
   - Số Kanban tasks user có quyền thấy.
   - Số course user đã tham gia.
   - Deadline Assignment gần nhất của Kanban liên kết.
   - Link **View all my courses**.
5. Bấm **Close** để đóng panel.

Nếu Admin cần thêm block vào Dashboard:

1. Bật **Edit mode** tại Dashboard.
2. Chọn **Add a block**.
3. Chọn **Kanban quick view**.

## 11. Backup và restore

### Backup

1. Mở course.
2. Vào **More → Course reuse → Backup**.
3. Chọn activity Kanban và các nội dung cần backup.
4. Hoàn tất wizard, tải file `.mbz` nếu cần.

### Restore

1. Vào **More → Course reuse → Restore** ở course đích.
2. Upload/chọn file backup.
3. Chọn Restore into this course hoặc course mới.
4. Kiểm tra lại cột, card, assignee, comment, history và attachment sau restore.

## 12. Xử lý sự cố thường gặp

| Vấn đề | Cách xử lý |
|---|---|
| Không thấy Kanban khi thêm activity | Kiểm tra plugin đã cài qua Notifications và user có quyền thêm activity. |
| Không tạo/di chuyển được card | Kiểm tra capability, group hiện tại, WIP limit và session đăng nhập. |
| Không thấy ButtonKB | Mở Dashboard, kiểm tra plugin/capability, purge cache và đăng nhập lại. |
| ButtonKB hiển thị 0 task | Tạo card và gán card cho user; kiểm tra user có enrol/group phù hợp. |
| Không có notification deadline | Kiểm tra deadline, setting notification và `docker compose logs cron`. |
| File không tải được | Kiểm tra quyền user/group và File API; không truy cập bằng đường dẫn filesystem. |
| Sau sửa code không thấy thay đổi | Tăng version plugin khi cần upgrade, chạy Notifications và purge cache; với Docker chạy lại `docker compose up -d --build`. |

## 13. Kiểm thử sau khi sử dụng/cập nhật

```powershell
docker compose exec moodle php vendor/bin/phpunit --testsuite mod_kanban_testsuite
docker compose --profile test up -d selenium
docker compose exec moodle php admin/tool/behat/cli/run.php --profile=chrome --tags=@mod_kanban
```

Kết quả đã xác nhận trong môi trường hiện tại:

```text
PHPUnit: 20 tests, 100 assertions — OK
Behat:   4 scenarios, 22 steps — passed
```
