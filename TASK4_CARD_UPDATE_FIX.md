# Task 4 — Cập nhật card và kiểm soát server-side

## Mục tiêu
Bổ sung API cập nhật thẻ, đảm bảo mọi thay đổi về tiêu đề, mô tả, hạn chót, URL và phân công đều được validate đúng trên server, không phụ thuộc vào UI/JS.

## Vấn đề cần giải quyết
Trước khi sửa:
- Không có external API để chỉnh sửa card.
- Mã client có thể cập nhật thẻ bằng cách gửi payload tùy ý nhưng không có server-side validation đầy đủ.
- `duedate` và `submissionurl` có thể bị thay đổi sai kiểu hoặc quá hạn.
- `assignees` không được validate theo group/current user.
- Card có thể được cập nhật mà không lưu lịch sử thay đổi rõ ràng.

## File đã xử lý
- `mod/kanban/classes/external/card_api.php`
- `mod/kanban/db/services.php`

## Chi tiết sửa

### 1) Thêm `update_card()` trong external API
Đã thêm hàm:
- `update_card_parameters()`
- `update_card()`
- `update_card_returns()`

Hàm này cho phép cập nhật:
- tiêu đề
- mô tả
- hạn chót
- link nộp bài
- danh sách assignee

### 2) Kiểm soát quyền và dữ liệu
Mỗi request update đều thực hiện:
- `require_login($course, false, $cm)`
- `require_capability('mod/kanban:managecards', $context)`
- `require_sesskey()`
- validate `cardid` và `cmid`
- validate `card` thuộc `kanban` tương ứng

### 3) Kiểm tra dữ liệu đầu vào
- tiêu đề không được để trống
- `duedate` nếu có phải không ở quá khứ
- URL được kiểm tra kiểu `PARAM_URL`
- assignee được kiểm tra theo group hoặc quyền `accessallgroups`

### 4) Đồng bộ assignee
Khi cập nhật card, hệ thống gọi:
- `kanban_validate_assignee_list()`
- `kanban_set_card_assignees()`

Điều này đảm bảo dữ liệu assignee đồng bộ với bảng mới `kanban_card_assignees`.

### 5) Ghi lịch sử
Sau khi cập nhật thành công, gọi:
- `kanban_log_card_change($card->id, 'updated', 'Cap nhat the');`

## Kết quả
Task 4 đã bổ sung một API cập nhật card có kiểm soát chặt chẽ ở server và bảo vệ logic business quan trọng như WIP, quyền nhóm và deadline.
