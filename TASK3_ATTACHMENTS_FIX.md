# Task 3 — File đính kèm qua Moodle File API

## Mục tiêu
Hoàn thiện tính năng file đính kèm cho card trong plugin `mod_kanban` theo đúng chuẩn Moodle File API, bao gồm:
- upload/ghi file đúng cách
- kiểm soát quyền truy cập file theo group/module
- api trả danh sách file đính kèm
- api xóa file đính kèm
- hiển thị file trên giao diện board/card detail

## File đã sửa
- `mod/kanban/lib.php`
- `mod/kanban/classes/external/card_api.php`
- `mod/kanban/db/services.php`
- `mod/kanban/templates/board.mustache`
- `mod/kanban/amd/src/board.js`

## Vấn đề ban đầu
Plugin đã có một số nhánh xử lý file nhưng chưa hoàn thiện theo Moodle File API:

1. File upload không được quản lý theo hệ thống file chuẩn của Moodle.
2. Chưa có `kanban_pluginfile()` để phục vụ file từ browser.
3. Không có API trả danh sách attachment cho 1 card.
4. Không có API xóa file đính kèm đúng cách.
5. Không kiểm tra quyền nhóm trước khi xem file.
6. Giao diện chưa render attachment và chưa có nút xóa file.

## Sửa như thế nào

### 1) Thêm hook `kanban_pluginfile()` trong `lib.php`
Đã bổ sung hàm:
- `kanban_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = [])`

Chức năng:
- kiểm tra `$context->contextlevel == CONTEXT_MODULE`
- kiểm tra `$filearea === 'card_attachments'`
- gọi `require_login($course, false, $cm)`
- kiểm tra quyền xem file theo nhóm hoặc `accessallgroups`
- lấy file bằng `get_file_storage()`
- dùng `send_stored_file()` để phục vụ file

Điểm quan trọng: không đọc file bằng đường dẫn thủ công; tất cả đều đi qua Moodle File API.

### 2) Thêm API lấy danh sách file đính kèm
Trong `card_api.php` đã thêm:
- `get_card_files_parameters()`
- `get_card_files($cardid, $cmid)`
- `get_card_files_returns()`

Logic:
- validate `cmid`
- kiểm tra `cardid` đúng thuộc kanban hiện tại
- kiểm tra quyền xem `mod/kanban:view`
- lấy file bằng `$fs->get_area_files($context->id, 'mod_kanban', 'card_attachments', $card->id, 'filename', false)`
- trả về mỗi file gồm:
  - `filename`
  - `size`
  - `hash`
  - `url` bằng `moodle_url::make_pluginfile_url()`

### 3) Thêm API xóa file đính kèm
Trong `card_api.php` đã thêm:
- `delete_card_file_parameters()`
- `delete_card_file($cardid, $cmid, $filehash)`
- `delete_card_file_returns()`

Logic:
- validate `cardid`, `cmid`, `filehash`
- require `mod/kanban:managecards`
- require `sesskey`
- validate card thuộc board hiện tại
- lấy file qua `$fs->get_file_by_hash($filehash)`
- kiểm tra file thuộc đúng card/context
- xóa bằng `$file->delete()`

### 4) Đăng ký service trong `db/services.php`
Đã thêm 2 service:
- `mod_kanban_get_card_files`
- `mod_kanban_delete_card_file`

### 5) Cập nhật giao diện hiển thị attachment
Trong `templates/board.mustache`:
- thêm khối `detail-card-attachments`
- hiển thị danh sách file và nút tải xuống/xóa

Trong `amd/src/board.js`:
- khi mở modal chi tiết card, gọi `mod_kanban_get_card_files`
- render danh sách `<a>` download file
- render nút xóa file nếu người dùng có quyền
- xử lý xóa file bằng AJAX

## Bảo mật đã tăng cường
Task 3 đã bổ sung các kiểm tra sau:
- `require_login($course, false, $cm)`
- `require_capability('mod/kanban:view', ...)` hoặc `managecards`
- kiểm tra nhóm của card trước khi xem/xóa file
- kiểm tra `file` thuộc đúng `context` và đúng `itemid` (card)
- chỉ xóa đúng file cần xóa, không xóa cả area
- dùng `sesskey` cho thao tác xóa

## Kết luận
Task 3 đã hoàn thiện phần file attachment theo chuẩn Moodle File API, đồng bộ với hệ thống bảo mật và UI hiện có của plugin. Các file đính kèm bây giờ có thể:
- được xem/download đúng quyền
- được liệt kê cho card tương ứng
- được xóa an toàn khi có quyền
- được hiển thị trong detail modal

## Xác minh syntax
Đã chạy PHP lint cho các file chính sau:
- `mod/kanban/lib.php`
- `mod/kanban/classes/external/card_api.php`
- `mod/kanban/db/services.php`

Kết quả:
- No syntax errors detected in mod/kanban/lib.php
- No syntax errors detected in mod/kanban/classes/external/card_api.php
- No syntax errors detected in mod/kanban/db/services.php
