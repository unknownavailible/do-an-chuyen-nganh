# Hướng dẫn thao tác kỹ thuật Backup/Restore Kanban

Tài liệu này dành cho quản trị viên Moodle hoặc người vận hành máy chủ. Các
lệnh bên dưới sử dụng đường dẫn XAMPP trên Windows của môi trường hiện tại.

## 1. Kiểm tra PHP và Moodle

PHP cần có các extension mà Moodle yêu cầu:

- `zip`
- `gd`
- `intl`
- `sodium`

Trong `E:\xampp\php\php.ini`, kiểm tra các dòng:

```ini
extension=gd
extension=intl
extension=sodium
extension=zip
max_input_vars = 5000
```

Sau khi chỉnh `php.ini`, khởi động lại Apache nếu thao tác qua web. Kiểm tra
extension bằng PowerShell:

```powershell
E:\xampp\php\php.exe -m
```

Kiểm tra riêng `max_input_vars`:

```powershell
E:\xampp\php\php.exe -r "echo ini_get('max_input_vars'), PHP_EOL;"
```

## 2. Áp dụng nâng cấp plugin

Sau khi thay đổi mã nguồn hoặc tăng version plugin, chạy:

```powershell
E:\xampp\php\php.exe `
  E:\xampp\htdocs\moodle\admin\cli\upgrade.php `
  --non-interactive
```

Kết quả cần có thông báo thành công cho `mod_kanban`.

Version hiện tại của thay đổi backup/restore là `2026091203`. Savepoint tương
ứng nằm trong:

```text
mod/kanban/db/upgrade.php
```

## 3. Tạo backup bằng CLI

Tạo thư mục lưu backup:

```powershell
New-Item -ItemType Directory `
  -Path E:\xampp\htdocs\moodle\local\kanban-backup `
  -Force
```

Backup course theo ID:

```powershell
E:\xampp\php\php.exe `
  E:\xampp\htdocs\moodle\admin\cli\backup.php `
  --courseid=3 `
  --destination=E:\xampp\htdocs\moodle\local\kanban-backup
```

Hoặc backup theo shortname:

```powershell
E:\xampp\php\php.exe `
  E:\xampp\htdocs\moodle\admin\cli\backup.php `
  --courseshortname=kanbanlms1 `
  --destination=E:\xampp\htdocs\moodle\local\kanban-backup
```

Moodle tạo file có dạng:

```text
backup-moodle2-course-<courseid>-<shortname>-<timestamp>.mbz
```

## 4. Restore bằng CLI

Restore file backup vào category Moodle:

```powershell
E:\xampp\php\php.exe `
  E:\xampp\htdocs\moodle\admin\cli\restore_backup.php `
  --file=E:\xampp\htdocs\moodle\local\kanban-backup\backup-file.mbz `
  --categoryid=1
```

Thêm `--showdebugging` khi cần xem lỗi chi tiết:

```powershell
E:\xampp\php\php.exe `
  E:\xampp\htdocs\moodle\admin\cli\restore_backup.php `
  --file=E:\xampp\htdocs\moodle\local\kanban-backup\backup-file.mbz `
  --categoryid=1 `
  --showdebugging
```

Khi thành công, CLI hiển thị course ID mới, ví dụ:

```text
Restored course ID: 8
```

## 5. Xác minh dữ liệu sau restore

Kiểm tra tối thiểu các bảng:

```text
kanban
kanban_columns
kanban_cards
kanban_card_assignees
kanban_members
kanban_card_comments
kanban_card_history
```

Đối chiếu:

- Số Kanban trong course gốc và course mới.
- Số column của từng Kanban.
- Số card của từng Kanban.
- `columnid` của card có trỏ đến column mới.
- `kanbanid` của column/card có trỏ đến Kanban mới.
- `cardid` của assignee/comment/history có trỏ đến card mới.
- File trong filearea `mod_kanban/card_attachments` có tồn tại trong context
  module mới.

Không nên kiểm tra bằng cách so sánh ID tuyệt đối, vì restore đúng sẽ tạo ID
mới. Hãy so sánh quan hệ và nội dung.

## 6. Các file backup/restore của plugin

- `backup/moodle2/backup_kanban_activity_task.class.php`
- `backup/moodle2/backup_kanban_stepslib.php`
- `backup/moodle2/restore_kanban_activity_task.class.php`
- `backup/moodle2/restore_kanban_stepslib.php`

`lib.php` khai báo:

```php
case FEATURE_BACKUP_MOODLE2: return true;
```

File đính kèm card sử dụng:

```text
component: mod_kanban
filearea: card_attachments
itemid: card ID
```

## 7. Xử lý lỗi thường gặp

### Moodle báo thiếu extension PHP

Kiểm tra `E:\xampp\php\php.ini`, bỏ dấu `;` trước extension tương ứng, sau đó
khởi động lại Apache và chạy lại lệnh upgrade.

### Restore báo `unknown_context_mapping`

Kiểm tra structure step có gọi:

```php
return $this->prepare_activity_structure($paths);
```

Card cần được lưu mapping với `set_mapping(..., true)` để file attachment và
các bản ghi con có thể dùng mapping card.

### `cardid` bị NULL khi restore history/comment/assignee

Các bản ghi con phải map card bằng ID cũ:

```php
$data->cardid = $this->get_mappingid('kanban_card', $data->cardid);
```

Không dùng `get_new_parentid('kanban_card')` cho các node con nằm trong card,
vì parent stack có thể không còn là card tại thời điểm xử lý.

### File đính kèm không xuất hiện

Kiểm tra cả hai phía:

- Backup step có `annotate_files('mod_kanban', 'card_attachments', 'id')`.
- Restore step có `add_related_files('mod_kanban', 'card_attachments',
  'kanban_card')`.
- Card restore đã tạo mapping với item name `kanban_card`.

## 8. Quy trình khuyến nghị trước khi triển khai production

1. Backup database Moodle.
2. Backup thư mục `moodledata`.
3. Backup course bằng Moodle.
4. Restore vào course thử nghiệm.
5. Kiểm tra card, column, user/group và file.
6. Chỉ sau khi xác minh thành công mới triển khai hoặc nâng cấp production.
7. Purge Moodle caches sau khi deploy:
   **Site administration > Development > Purge caches**.
