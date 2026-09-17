# Hướng dẫn kiểm thử plugin Kanban

## 1. Môi trường và tài khoản test

- Moodle: `http://localhost/moodle` (Moodle 4.3, MariaDB, XAMPP).
- Khởi động khi máy mới bật (MySQL trước, Apache sau):

```powershell
Start-Process -FilePath "E:\xampp\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=E:\xampp\mysql\bin\my.ini" -WindowStyle Hidden
Start-Process -FilePath "E:\xampp\apache\bin\httpd.exe" -WindowStyle Hidden
```

- Course test: **Kanban QA** (`shortname=kanbanqa`), board **Kanban QA Board**
  (chế độ nhóm `SEPARATEGROUPS`, Group A / Group B).
- Tài khoản (mật khẩu chung `Test@12345`):

| Username | Vai trò | Nhóm |
|---|---|---|
| `teacher1` | Giảng viên (editingteacher) | — (xem được tất cả) |
| `hocsinh1` | Sinh viên | Group A |
| `hocsinh2` | Sinh viên | Group A |
| `hocsinh3` | Sinh viên | Group B |

## 2. PHPUnit chạy thế nào (giải thích ngắn)

1. `admin/tool/phpunit/cli/init.php` (chạy 1 lần): tải thư viện test qua
   composer, tạo database test riêng (bảng tiền tố `phpu_`, thư mục
   `moodledata_phpunit`). Database thật **không bị đụng tới**.
2. Mỗi test class kế thừa `advanced_testcase`: `setUp()` gọi
   `resetAfterTest()` để sau mỗi test tự rollback database về trắng.
3. `getDataGenerator()->create_course()/create_user()/create_module('kanban')`
   tạo dữ liệu giả. Lưu ý: `create_module()` trả về **bản ghi activity**
   (có `->cmid`), muốn course module thật phải gọi thêm
   `get_coursemodule_from_instance()`.
4. `setUser($user)` giả đăng nhập; gọi trực tiếp
   `\mod_kanban\external\card_api::move_card(...)` rồi `assert...`.
5. Test chạy trên database `phpu_`, xong rollback nên không để lại rác.

Cần trong `config.php` (đã cấu hình sẵn trên máy này):

```php
$CFG->phpunit_dataroot = 'D:\do an chuyen nganh\server\moodledata_phpunit';
$CFG->phpunit_prefix = 'phpu_';
```

## 3. Chạy test

```powershell
$env:Path += ";E:\xampp\php"
cd E:\xampp\htdocs\moodle
E:\xampp\php\php.exe vendor\bin\phpunit --testsuite mod_kanban_testsuite
```

Kết quả đúng: `OK (4 tests, 7 assertions)`.

Chạy 1 test: thêm `--filter test_valid_group_member_can_move_card_successfully`.

## 4. Bộ probe quét động (16 điểm P0 → P2)

Script `qa_probe.php` gọi trực tiếp API với tư cách từng user trên course QA:

- P0 bảo mật: C1 cross-kanban, C2 ngoài group, C3 student gọi teacher-comment,
  C9 URL `javascript:`.
- Chức năng: C4 WIP, C5 deadline quá khứ, C6/C7 title rỗng, C8 orphan khi xóa,
  C10 sortorder, C13 groupid bị đổi ngầm, C14 history ẩn với student,
  C15 xóa cột, C16 files.
- Thông báo: C11 notify khi gán, C12 task deadline chống spam.

Chạy: `E:\xampp\php\php.exe <đường-dẫn>\qa_probe.php` (tự dọn card `QATEST*`).

Kết quả hiện tại (sau fix, chạy lại probe): **16/16 PASS**.

## 5. Bug đã tìm ra và đã sửa (có bước tái hiện)

**B1 — Xóa card để lại orphan (đã sửa).**
`card_api::delete_card()` chỉ xóa `kanban_cards`. Đã thêm xóa `assignees/
comments/history` + file `card_attachments` (theo mẫu
`kanban_delete_instance()`).

**B2 — GV sửa card làm rơi group về 0 (đã sửa).**
`kanban_set_card_assignees()` luôn ghi đè `groupid` theo group hiện tại của
người gọi. Đã bỏ khối ghi đè: `groupid` chốt lúc tạo card, hàm chỉ đồng bộ
assignees/`assigned_to`.

**B3 — Tạo card cho title rỗng (đã sửa).**
Đã thêm check `required` vào `create_card()` và `upload_and_create_card.php`,
đồng nhất với `update_card()`.

## 6. Checklist click tay trên trình duyệt (probe không phủ được)

1. Đăng nhập `hocsinh1` → board QA: chỉ thấy card Group A; chuyển dropdown
   group sang B → không thấy gì (SEPARATEGROUPS).
2. Kéo thả card sang cột khác → tải lại trang, thứ tự giữ nguyên.
3. Tạo card kèm file → mở chi tiết, tải file xuống được.
4. Đăng nhập `teacher1` → Dashboard: 4 KPI, 2 biểu đồ, bảng đóng góp, bảng
   cảnh báo; thêm teacher-comment → SV thấy được.
5. Thử WIP: đặt WIP = 1 cho cột In Progress → tạo card thứ 2 báo lỗi WIP.
6. Backup course Kanban QA → restore sang course mới → đối chiếu số
   column/card/comment/file (ID mới là bình thường, quan hệ phải đúng).
