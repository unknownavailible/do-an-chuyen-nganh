# Task 2 — Phân công task cho thành viên nhóm

## Mục tiêu
Thêm cơ chế phân công công việc cho thành viên trong nhóm, đảm bảo chỉ chọn người thuộc nhóm hoặc người được phép theo quyền Moodle, đồng thời lưu được nhiều người được giao trên cùng một card.

## Vấn đề hiện tại
Trước khi sửa:
- Card chỉ có trường `assigned_to` duy nhất, nên không thể gán nhiều người trên cùng một task.
- Không có giao diện để chọn thành viên từ nhóm.
- Không có validation rõ ràng khi user chọn assignee không thuộc nhóm hiện tại.
- Mặc định giao cho người tạo card mà không cho phép chọn rõ ràng.
- UI board chưa hiển thị tên người được giao.

## File đã sửa
- `mod/kanban/lib.php`
- `mod/kanban/classes/external/card_api.php`
- `mod/kanban/view.php`
- `mod/kanban/templates/board.mustache`
- `mod/kanban/amd/src/board.js`
- `mod/kanban/db/install.xml`
- `mod/kanban/db/upgrade.php`

## Chi tiết thay đổi

### 1) Thêm bảng lưu nhiều assignee
Tạo bảng mới:
- `mdl_kanban_card_assignees`
- cột: `id`, `cardid`, `userid`
- unique index: `(cardid, userid)`

Mục đích:
- một card có thể gán cho nhiều người
- tránh trùng lặp assignee trên cùng card

### 2) Thêm helper chuẩn hóa phân công
Trong `lib.php` đã bổ sung:
- `kanban_get_card_assignee_ids($cardid)`
- `kanban_get_card_assignee_names($cardid)`
- `kanban_validate_assignee_list($cm, $assigneeids = [])`
- `kanban_set_card_assignees($cardid, $assigneeids, $cm = null)`

Chức năng:
- lấy danh sách người được giao
- chuẩn hóa dữ liệu đầu vào
- kiểm tra assignee có thuộc nhóm / quyền hiện tại hay không
- đồng bộ dữ liệu vào bảng mới
- cập nhật trường `assigned_to` để tương thích với code cũ

### 3) Sửa API tạo card
`create_card()` hiện hỗ trợ thêm tham số:
- `assignees` => danh sách user id

Logic mới:
- validate `assignees` bằng `kanban_validate_assignee_list()`
- lưu record vào `kanban_card_assignees`
- nếu có assignee, `assigned_to` lấy phần tử đầu tiên làm người mặc định kiểu cũ
- nếu không chọn người nào thì `assigned_to = 0`

### 4) Bổ sung dữ liệu hiển thị trên board
Trong `view.php`:
- mỗi card khi render đều truy xuất `assignee_names`
- hiển thị tên người được giao ngay dưới mô tả thẻ

Trong `board.mustache`:
- thêm block hiển thị `Người được giao`
- hiển thị `Chưa phân công` nếu không có ai

### 5) Bổ sung form tạo thẻ
Trong modal `Thêm công việc mới`:
- thêm select `card-assignee-input`
- cho phép chọn nhiều user rõ ràng
- giữ Ctrl/Cmd để chọn nhiều người cùng lúc

### 6) Cập nhật JS
`amd/src/board.js`:
- reset lựa chọn assignee khi mở modal mới
- đọc các assignee đã chọn từ `select[multiple]`
- gửi `assignees` trong payload AJAX
- hiển thị assignee trong modal chi tiết

## Bảo mật & quyền
Đã áp dụng các kiểm tra sau:
- chỉ chấp nhận user thuộc nhóm hiện tại hoặc có `accessallgroups`
- không cho phép chọn assignee ngoài nhóm cho hoạt động theo nhóm
- giữ nguyên `require_login`, `require_capability`, `require_sesskey`

## Kết quả mong muốn
- Admin/teacher có thể phân công nhiều người cho một task.
- Board hiển thị tên người được giao trực quan.
- Dữ liệu được lưu dưới model nhiều-nhiều mà không vi phạm kiểm soát nhóm.
- Tương thích với phần code cũ bằng cách vẫn giữ `assigned_to`.

## Lưu ý triển khai
Khi nâng cấp DB trên môi trường Moodle thật, cần chạy upgrade task Moodle để tạo bảng `kanban_card_assignees` nếu chưa có.
