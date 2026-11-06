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

1. Cấu hình `$CFG->phpunit_dataroot` trỏ tới thư mục riêng và
   `$CFG->phpunit_prefix` dùng tiền tố database riêng (tối đa 10 ký tự, không
   trùng với tiền tố đang dùng). `admin/tool/phpunit/cli/init.php` khởi tạo
   môi trường test; không xóa hoặc tái sử dụng các bảng có tiền tố khác.
2. Mỗi test class kế thừa `advanced_testcase`: `setUp()` gọi
   `resetAfterTest()` để sau mỗi test tự rollback database về trắng.
3. `getDataGenerator()->create_course()/create_user()/create_module('kanban')`
   tạo dữ liệu giả. Lưu ý: `create_module()` trả về **bản ghi activity**
   (có `->cmid`), muốn course module thật phải gọi thêm
   `get_coursemodule_from_instance()`.
4. `setUser($user)` giả đăng nhập; gọi trực tiếp
   `\mod_kanban\external\card_api::move_card(...)` rồi `assert...`.
5. Test chạy trên database theo tiền tố PHPUnit đã cấu hình; `resetAfterTest()`
   dọn dữ liệu fixture sau mỗi test.

Cấu hình trong `config.php` của môi trường phát triển (không phải cấu hình
của plugin khi cài đặt):

```php
$CFG->phpunit_dataroot = 'D:\\servers\\server\\moodledata_phpunit';
$CFG->phpunit_prefix = 'kt26_';
```

## 3. Chạy test

```powershell
$env:Path += ";D:\servers\server\php"
cd D:\servers\server\moodle
D:\servers\server\php\php.exe vendor\bin\phpunit --testsuite mod_kanban_testsuite
```

Đã xác nhận trên Moodle 4.3.12/PHP 8.2.4/MariaDB 10.4.28:
20 tests, 100 assertions, PASS. Suite bao gồm CRUD/sắp xếp cột, thứ tự card,
upload nhiều file vào card, tra cứu file tải xuống theo quyền group, và API
liệt kê/xóa file.

Kiểm thử tải file trên giao diện Moodle:

1. Tạo card mới và chọn hai file nhỏ; lưu card, mở chi tiết và tải từng file.
   Xác nhận nội dung tải xuống đúng.
2. Với cột đã đạt WIP limit, thử tạo card kèm file; xác nhận request bị từ chối
   và không tạo card/file mới.
3. Đăng nhập bằng thành viên group khác, thử mở trực tiếp URL file của card
   riêng tư; xác nhận Moodle trả về không tìm thấy file.

PHPUnit kiểm tra lưu file và quyết định quyền truy cập; cần thực hiện checklist
trên trình duyệt để xác nhận đầy đủ HTTP multipart và phản hồi tải file.

Chạy 1 test: thêm `--filter test_valid_group_member_can_move_card_successfully`.
Test WIP: dùng `--filter test_wip_limit` để chạy kiểm tra giới hạn khi tạo
và khi di chuyển card.
Test Privacy API: chạy PHPUnit cho
`mod/kanban/tests/privacy/provider_test.php` để kiểm tra metadata, context,
export và xóa dữ liệu theo user.
Test Assignment: chạy PHPUnit cho `mod/kanban/tests/linked_assignment_test.php`.
Test scheduled notification: chạy PHPUnit cho
`mod/kanban/tests/task/deadline_notifications_test.php`; xác nhận tắt thông báo
không gửi/ghi history, không có người nhận thì sẽ thử lại, và đã gửi thành công
thì lần cron sau không gửi trùng.

## 4. Bộ probe quét động (16 điểm P0 → P2)

Script `qa_probe.php` gọi trực tiếp API với tư cách từng user trên course QA:

- P0 bảo mật: C1 cross-kanban, C2 ngoài group, C3 student gọi teacher-comment,
  C9 URL `javascript:`.
- Chức năng: C4 WIP, C5 deadline quá khứ, C6/C7 title rỗng, C8 orphan khi xóa,
  C10 sortorder, C13 groupid bị đổi ngầm, C14 history ẩn với student,
  C15 xóa cột, C16 files.
- Thông báo: C11 notify khi gán, C12 task deadline chống spam.

Chạy: `E:\xampp\php\php.exe <đường-dẫn>\qa_probe.php` (tự dọn card `QATEST*`).

Kết quả probe đã ghi nhận trước thay đổi WIP hiện tại: **16/16 PASS**.
Phải chạy lại PHPUnit và probe sau thay đổi trước khi dùng kết quả để nghiệm thu.

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
   cảnh báo; nếu Kanban liên kết Assignment, kiểm tra trạng thái nộp/điểm chỉ
   hiển thị với người có quyền xem trong Assignment. Đối chiếu Assignment gốc
   để đảm bảo dashboard không tạo hoặc sửa submission/grade.
5. Thử WIP: đặt WIP = 1 cho cột In Progress → tạo card thứ 2 hoặc chuyển
   card khác vào cột đó đều phải báo lỗi WIP.
6. Backup course Kanban QA → restore sang course mới → đối chiếu số
   column/card/comment/file (ID mới là bình thường, quan hệ phải đúng).
7. Thêm một sinh viên vào hai nhóm trong cùng khóa học → sinh viên bị chặn
   khi mở hoặc thao tác Kanban; sau khi giảng viên bỏ thành viên khỏi một nhóm,
   Kanban hoạt động lại bình thường.

## 8. Kiểm thử Behat

Feature `tests/behat/mod_kanban.feature` có 4 kịch bản: thành viên đúng group
di chuyển card, thành viên group khác bị chặn, WIP limit chặn tạo card vượt
giới hạn ở server, và giáo viên tạo board rồi kiểm tra quyền giữa hai group.
Các step tương ứng được định nghĩa trong
`tests/behat/behat_mod_kanban.php`. Feature được gắn `@javascript` vì các step
dùng browser JavaScript và Moodle AJAX.

Kết quả hiện tại:

- PHP lint cho `behat_mod_kanban.php`: PASS.
- Behat: **PASS**, Moodle 4.3.12 / PHP 8.2.4 / MariaDB 10.4.28 / Edge
  156.0.4314.58: **4 scenarios, 22 steps passed**.
- Test site riêng đã được khởi tạo với prefix `bh26_` và dataroot
  `D:\servers\server\moodledata_behat`; site chính `mdl_` và PHPUnit `kt26_`
  không bị dùng làm test prefix.
- EdgeDriver 156.0.4314.58 và Selenium Server 4.52.0 được tải từ nguồn chính
  thức và dùng để chạy trình duyệt. Trong lúc khởi tạo, Moodle báo cảnh báo
  OpenSSL cho module `mod_lti`; việc cài acceptance test site vẫn hoàn tất và
  các scenario Kanban đều PASS.

Cấu hình Behat hiện dùng trong `config.php`:

```php
$CFG->behat_wwwroot = 'http://127.0.0.1';
$CFG->behat_prefix = 'bh26_';
$CFG->behat_dataroot = 'D:\\servers\\server\\moodledata_behat';
$CFG->behat_profiles = [
    'edge' => [
        'browser' => 'MicrosoftEdge',
        'wd_host' => 'http://127.0.0.1:4444/wd/hub',
    ],
];
```

Để chạy lại trên máy này, khởi chạy Selenium Server ở một cửa sổ PowerShell:

```powershell
$env:Path = "D:\servers\server\behat-tools;$env:Path"
& "C:\Program Files\Eclipse Adoptium\jdk-21.0.13.9-hotspot\bin\java.exe" `
  -jar "D:\servers\server\behat-tools\selenium-server-4.52.0.jar" `
  standalone --host 127.0.0.1 --port 4444
```

Chờ Selenium báo sẵn sàng, rồi từ thư mục gốc Moodle chạy:

   ```powershell
   D:\servers\server\php\php.exe admin\tool\behat\cli\run.php --profile=edge --feature="D:\servers\server\moodle\mod\kanban\tests\behat\mod_kanban.feature"
   ```

Nếu cần tái tạo acceptance test site, giữ nguyên prefix/dataroot riêng ở trên
rồi chạy `D:\servers\server\php\php.exe admin\tool\behat\cli\init.php
--disable-composer`. Không đổi sang prefix/dataroot của site chính hoặc PHPUnit.
