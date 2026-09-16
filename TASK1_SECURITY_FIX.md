# Task 1 — Bảo mật & xác thực chuỗi cmid → kanban → card/column

## Mục tiêu
Rà soát và vá các lỗ hổng bảo mật trong các external API và AJAX endpoint của plugin `mod_kanban`, đặc biệt với các action create/move/delete/update card và đọc lịch sử/comment.

## Vấn đề phát hiện
Các function hiện tại ban đầu đang trust dữ liệu gửi từ client, thiếu các kiểm tra quan trọng:

1. `cmid` không được validate đúng module `kanban`.
2. `cardid` / `columnid` có thể được gửi giả, không kiểm tra thuộc `kanban` hiện tại.
3. Không kiểm tra quyền nhóm (`group membership`) trước khi thao tác với card.
4. Một số action ghi không gọi `require_sesskey()`.
5. Không kiểm tra `require_login($course, false, $cm)` đúng chuẩn Moodle.
6. Không chặn WIP limit ở phía server, nên có thể bypass qua UI/JS.
7. `FEATURE_BACKUP_MOODLE2` đã được khai báo sai trong `kanban_supports()`.

## File đã sửa
- `mod/kanban/lib.php`
- `mod/kanban/classes/external/card_api.php`
- `mod/kanban/upload_and_create_card.php`

## Chi tiết sửa

### 1) Thêm helper dùng chung trong `lib.php`
Đã thêm các helper:
- `kanban_user_has_group_access($cm, $groupid, $context = null)`
- `kanban_validate_and_get_card($cardid, $cm, $kanban, $requiredcapability = 'mod/kanban:managecards')`
- `kanban_validate_and_get_column($columnid, $kanbanid, $cm, $requiredcapability = 'mod/kanban:managecards')`
- `kanban_check_wip_limit($column, $kanbanid, $excludeid = 0)`

Chức năng:
- kiểm tra `group` hợp lệ
- xác thực `card` thực sự thuộc `kanban` hiện tại
- validate `column` thuộc board đúng
- từ chối request nếu vượt WIP limit ở server

### 2) Sửa `move_card`
Trước đó:
- chỉ lấy `cardid` và `targetcolumnid`
- không validate `cmid -> course module -> kanban`
- không kiểm tra group membership
- không bắt `sesskey`
- không kiểm tra `column` thuộc kanban này

Sau khi sửa:
- lấy `cm` bằng `get_coursemodule_from_id('kanban', $cmid, 0, false, MUST_EXIST)`
- gọi `require_login($course, false, $cm)`
- gọi `require_capability('mod/kanban:managecards', $context)`
- gọi `require_sesskey()`
- validate `card` và `column` bằng helper chia sẻ
- kiểm tra WIP limit trước khi cập nhật nếu chuyển sang cột đã đạt giới hạn

### 3) Sửa `create_card`
Trước đó:
- `kanbanid`, `columnid`, `cmid` chưa được kiểm tra chặt chẽ
- có thể tạo thẻ sai board/column
- không kiểm tra user có quyền trong group không
- không kiểm tra `sesskey`
- không kiểm tra WIP limit ở server

Sau khi sửa:
- `require_login` + `require_capability` + `require_sesskey`
- kiểm tra `kanbanid` phải là instance đang mở của cm
- kiểm tra `columnid` thuộc `kanbanid` này
- kiểm tra `groups_get_activity_group` và `groups_is_member` nếu chưa có `accessallgroups`
- gọi `kanban_check_wip_limit()` trước khi insert

### 4) Sửa `delete_card`
Trước đó:
- xóa thẻ theo `cardid` mà không kiểm tra card thuộc kanban tương ứng
- không validate `cmid`
- không require sesskey

Sau khi sửa:
- lấy `cm` và `kanban` đúng
- validate card qua helper
- xóa đúng bằng `card->id` sau khi chắc chắn card hợp lệ
- gọi `require_sesskey()`

### 5) Sửa các API đọc / comment
- `get_card_activity`
- `add_teacher_comment`

Đã bổ sung:
- `require_login($course, false, $cm)`
- validate `card` thuộc module đúng
- require đúng capability
- require `sesskey` cho action ghi

### 6) Sửa AJAX endpoint trực tiếp
File `upload_and_create_card.php` cũng được vá để đảm bảo:
- `cmid` phải hợp lệ
- `kanbanid` phải khớp với `cm->instance`
- `columnid` phải thuộc `kanban` này
- user thuộc group để thao tác
- `require_sesskey()` được giữ đúng chuẩn
- WIP limit được check ở server

### 7) Tắt hỗ trợ backup sai
Trong `kanban_supports()`:
- `FEATURE_BACKUP_MOODLE2` đã đổi từ `true` thành `false`

Lý do: plugin hiện chưa triển khai backup/restore đầy đủ nên không nên tuyên bố hỗ trợ tính năng mà chưa làm xong.

## Các lỗ hổng đã fix
1. ID giả từ client
2. Chạy trong module sai
3. Không kiểm tra quyền nhóm
4. Không chặn action ghi thiếu `sesskey`
5. Không validate row thực sự thuộc board
6. Không có server-side WIP enforcement
7. Khai báo tính năng backup không đúng

## Kết quả kiểm tra
Đã chạy PHP lint trên các file đã sửa:
- `mod/kanban/classes/external/card_api.php`
- `mod/kanban/upload_and_create_card.php`

Kết quả:
- Không có syntax error
- Chỉ có 1 warning không nghiêm trọng về `use context_module` trong `upload_and_create_card.php`, không ảnh hưởng điều kiện chạy chính của plugin.

## Ghi chú
Đây là patch phần bảo mật cốt lõi của Task 1, là nền tảng cho các task kế tiếp. Nếu cần, có thể tiếp tục làm Task 2 trong cùng định dạng và lộ trình đã xác định.
