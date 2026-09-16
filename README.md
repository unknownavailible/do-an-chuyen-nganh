# Moodle Kanban

Module Kanban cho Moodle, hỗ trợ quản lý công việc theo cột, phân nhóm sinh viên, hạn hoàn thành, bình luận giáo viên và theo dõi tiến độ.

## 1. Mục tiêu dự án

- Cho phép giảng viên tạo hoạt động Kanban trong khóa học.
- Cho phép học viên quản lý công việc của nhóm bằng các thẻ Kanban.
- Hỗ trợ kéo thả thẻ giữa các cột.
- Theo dõi hạn hoàn thành và lịch sử thay đổi.
- Cung cấp dashboard tiến độ cho giảng viên.
- Chạy tương thích với database được Moodle hỗ trợ, đặc biệt là MariaDB và PostgreSQL.

## 1.1. Hướng dẫn giảng viên: hoạt động được tạo và vận hành như thế nào

### A. Tạo hoạt động Kanban trong một khóa học

Giảng viên thực hiện theo luồng Moodle chuẩn:

1. Mở một khóa học và bật chế độ chỉnh sửa.
2. Chọn **Thêm hoạt động hoặc tài nguyên**.
3. Chọn **Kanban**.
4. Nhập tên hoạt động và phần mô tả/yêu cầu bài tập.
5. Cấu hình chế độ nhóm, group hoặc grouping theo nhu cầu của khóa học.
6. Lưu hoạt động.

Form tạo hoạt động nằm trong `mod_form.php`. Form này kế thừa
`moodleform_mod`, vì vậy các trường tên, mô tả, group mode, visibility và
completion được Moodle xử lý theo chuẩn chung. Khi Moodle lưu activity, hàm
`kanban_add_instance()` trong `lib.php` tạo bản ghi Kanban và ba column mặc
định:

```text
To Do -> In Progress -> Done
```

Mỗi hoạt động Kanban được gắn với một course module (`cmid`). `cmid` là cầu nối
giữa giao diện Moodle, course, context quyền và bản ghi trong bảng `kanban`.

### B. Các thành phần tạo nên một hoạt động

Một activity Kanban gồm các lớp sau:

| Thành phần | Vai trò |
|---|---|
| Course module | Định danh activity trong khóa học, dùng để kiểm tra đăng nhập và quyền |
| Kanban instance | Lưu tên, mô tả, course, người tạo và thời gian |
| Column | Lưu trạng thái, màu, thứ tự và WIP limit |
| Card | Lưu công việc, mô tả, group, deadline, URL và thứ tự |
| Card assignees | Liên kết nhiều-nhiều giữa card và user được giao |
| Group | Giới hạn phạm vi card mà sinh viên được xem và thao tác |
| Comment | Phản hồi của giáo viên trên card |
| History | Nhật ký tạo, di chuyển, cập nhật, xóa hoặc bình luận |
| File area | Lưu file đính kèm bằng Moodle File API |
| Dashboard | Tổng hợp số card, hoàn thành, quá hạn và đóng góp |

Các thành phần dữ liệu này được định nghĩa bằng XMLDB trong `db/install.xml`.
Moodle tự thêm tiền tố bảng, vì vậy code dùng tên như `kanban_cards` thay vì
ghi cứng `mdl_kanban_cards`.

### C. Luồng hiển thị bảng Kanban

Khi người dùng mở activity, Moodle gọi `view.php?id=<cmid>`:

1. `get_coursemodule_from_id()` xác định activity và course.
2. `require_login()` kiểm tra người dùng đã đăng nhập và được vào course.
3. `context_module::instance()` tạo context của activity.
4. `require_capability('mod/kanban:view', $context)` kiểm tra quyền xem.
5. PHP đọc columns và cards thuộc đúng Kanban.
6. Group hiện tại được lấy bằng `groups_get_activity_group()`, sau đó card
   ngoài group không được đưa vào dữ liệu hiển thị.
7. PHP tính trạng thái deadline: bình thường, sắp đến hạn trong 48 giờ hoặc
   quá hạn.
8. `board.mustache` render dữ liệu thành HTML.
9. `js_call_amd('mod_kanban/board', 'init', ...)` khởi tạo JavaScript kéo thả
   và các modal.

Vì vậy, JavaScript chỉ điều khiển trải nghiệm giao diện; quyền và dữ liệu
cuối cùng vẫn phải được kiểm tra lại ở PHP server.

### D. Luồng tạo và di chuyển card

Khi người dùng tạo card:

1. Giảng viên/sinh viên bấm **Thêm công việc** ở một column.
2. Modal nhận title, description, deadline, assignee và URL nếu có.
3. `board.js` gọi Moodle AJAX service `mod_kanban_create_card`.
4. `card_api::create_card()` kiểm tra `cmid`, đăng nhập, context, capability,
   sesskey, Kanban, column, group và assignee.
5. Server kiểm tra deadline không nằm trong quá khứ và WIP limit chưa đạt.
6. Card được lưu vào `kanban_cards`.
7. Assignee được lưu vào `kanban_card_assignees`.
8. History được ghi vào `kanban_card_history`.
9. Giao diện tải lại để cập nhật số card và trạng thái column.

Khi kéo card sang column khác, JavaScript gọi `mod_kanban_move_card`. Server
kiểm tra card và column có cùng Kanban, người dùng có quyền với group, sau đó
kiểm tra WIP limit trước khi cập nhật `columnid`.

### E. Luồng xem chi tiết, comment và file

Khi bấm **Xem chi tiết**, `board.js` gọi:

- `mod_kanban_get_card_activity` để lấy comment và history.
- `mod_kanban_get_card_files` để lấy danh sách file.

Giáo viên có quyền phù hợp có thể thêm comment qua
`mod_kanban_add_teacher_comment`. File được phục vụ qua
`kanban_pluginfile()` trong `lib.php`, với các giá trị:

```text
component = mod_kanban
filearea  = card_attachments
itemid    = card ID
```

Plugin không đọc file trực tiếp từ đường dẫn hệ điều hành. Moodle File API
kiểm soát context, đường dẫn và quyền truy cập trước khi gửi file.

### F. Vai trò của giảng viên và sinh viên

- **Giảng viên**: tạo activity, xem dashboard, xem history, thêm comment và
  quản lý card theo capability được cấp.
- **Sinh viên**: xem board và thao tác card trong group được phép.
- **Manager/Admin**: phụ thuộc capability thực tế trong Moodle.

Mỗi request đều phải kiểm tra ở server. Việc ẩn nút trên giao diện không được
coi là cơ chế bảo mật.

## 1.2. Các gói thư viện và nguồn sử dụng

Plugin chủ yếu sử dụng API có sẵn của Moodle; không tải framework PHP riêng và
không tự tạo thư viện xác thực hoặc database. Các thành phần chính gồm:

| Gói/API | Nguồn | Cách hoạt động |
|---|---|---|
| Moodle Plugin API | Moodle core, thư mục `lib/` và `course/` | Tạo activity, course module, context, capability, login và lifecycle |
| Moodle DB API/XMLDB | Moodle core | Truy vấn `$DB`, tạo schema portable cho MariaDB/PostgreSQL |
| Moodle External API | Moodle core `lib/externallib.php` | Khai báo parameter, validate dữ liệu và cung cấp AJAX/web service |
| Moodle Groups API | Moodle core | Lấy group activity, kiểm tra membership và access all groups |
| Moodle File API | Moodle core | Lưu, đọc, tạo URL, xóa và backup file attachment |
| Moodle Backup API | Moodle core `backup/` | Tạo XML backup, mapping ID và restore sang context/course mới |
| Mustache renderer | Moodle core | Render `templates/board.mustache` từ dữ liệu PHP |
| Moodle AMD loader | Moodle core | Nạp module `mod_kanban/board` qua `js_call_amd()` |
| `core/ajax` | Moodle core AMD module | Gửi lời gọi external service từ trình duyệt |
| `core/notification` | Moodle core AMD module | Hiển thị lỗi request theo chuẩn Moodle |
| Bootstrap classes | Theme/Moodle | Bố cục, modal, badge, button, grid và responsive UI |
| Font Awesome | Moodle/theme | Icon trên card, deadline, dashboard và thao tác |

### Nguồn dependency JavaScript build

Các công cụ build không được tải trong plugin riêng mà được khai báo ở
`package.json` của Moodle root. Moodle dùng npm để cài các dev dependency như:

- `grunt`: task runner.
- `rollup`: đóng gói AMD.
- `rollup-plugin-terser`: minify JavaScript.
- `eslint`: kiểm tra coding style JavaScript.
- `@babel/*`: hỗ trợ xử lý cú pháp JavaScript khi cần.

Quy trình build là:

```text
amd/src/board.js
        |
        v
Grunt + Rollup + Terser
        |
        +--> amd/build/board.min.js
        +--> amd/build/board.min.js.map
```

Node.js/npm chỉ phục vụ giai đoạn phát triển và build, không phải dependency
runtime của Moodle khi người dùng mở activity. Khi triển khai, Moodle tải file
AMD đã build và cache nó.

### Nguồn CSS và giao diện

Giao diện sử dụng:

- `style.php` của plugin cho CSS riêng.
- HTML từ `board.mustache`.
- Bootstrap và icon do theme Moodle cung cấp.
- JavaScript AMD để xử lý kéo thả, modal và AJAX.

Do đó, plugin không cần jQuery riêng hoặc Bootstrap riêng. Việc dùng các API
Moodle giúp activity phù hợp hơn với theme và cơ chế cache của Moodle.

## 2. Trạng thái hiện tại

Plugin Kanban đã đi qua các task chính của dự án và hiện đang ở trạng thái sẵn sàng để triển khai trong Moodle với các chức năng cốt lõi và các kiểm soát bảo mật quan trọng đã được bổ sung.

### 2.1. Các task chính đã hoàn thành

- Task 1: Bảo mật & xác thực chuỗi `cmid -> kanban -> card/column`.
  - Validate module, course module, group access, card/column ownership, WIP limit và `sesskey`.
  - Chặn các request giả mạo từ client và tắt hỗ trợ backup sai.
- Task 2: Phân công task cho thành viên nhóm.
  - Hỗ trợ nhiều assignee trên cùng 1 card.
  - Validate assignee theo nhóm/current user và đồng bộ bảng `kanban_card_assignees`.
- Task 3: File đính kèm theo Moodle File API.
  - Upload, list, delete và download attachment an toàn theo quyền và group.
- Task 4: Cập nhật card và kiểm soát server-side.
  - API `update_card()` với validation nghiêm ngặt cho title, description, deadline, URL và assignee.
- Task 5: Quyết định backup/restore.
  - Chọn phương án triển khai Backup/Restore Moodle 2 đầy đủ và bật lại support tương ứng.
- Task 6: PHPUnit/Behat tests.
  - Bao gồm test cho access control, move card, WIP limit và deadline.
- Task 7: AMD source và build đồng bộ.
  - Build lại `amd/build/board.min.js` và source map từ `amd/src/board.js`.

Tài liệu chi tiết từng task được lưu trong các file:

- `TASK1_SECURITY_FIX.md`
- `TASK2_ASSIGNMENT_FIX.md`
- `TASK3_ATTACHMENTS_FIX.md`
- `TASK4_CARD_UPDATE_FIX.md`
- `TASK5_BACKUP_DECISION.md`
- `TASK6_TESTS_FIX.md`
- `TASK7_AMD_BUILD.md`

### 2.2. Các chức năng đã triển khai

- Hoạt động Moodle Kanban và form tạo hoạt động.
- Ba cột mặc định: To Do, In Progress và Done.
- Lưu và hiển thị tên người tạo bảng Kanban.
- Bảng Kanban, cột, card, bình luận và lịch sử.
- Tạo card bằng AJAX.
- Tạo card kèm tệp đính kèm qua endpoint upload trên chuẩn Moodle File API.
- Kéo thả card giữa các cột.
- Xóa card.
- Tự động tải lại bảng sau khi tạo, di chuyển hoặc xóa card để cập nhật số lượng.
- Hạn hoàn thành.
- Hiển thị card quá hạn hoặc sắp đến hạn.
- Bình luận của giáo viên.
- Lịch sử thay đổi chỉ hiển thị cho giáo viên có quyền xem dashboard.
- Hiển thị tên cột trạng thái thay cho ID card trong chi tiết công việc.
- Dashboard thống kê cơ bản.
- Phân quyền xem bảng, quản lý card và xem dashboard.
- Kiểm tra `cmid`, context, capability, sesskey và quan hệ card/column với đúng Kanban.
- Giới hạn truy cập card theo group, hỗ trợ quyền `moodle/site:accessallgroups`.
- Cập nhật card gồm tiêu đề, mô tả, deadline, URL nộp bài và assignee.
- Kiểm tra WIP limit ở server khi tạo, di chuyển hoặc cập nhật card.
- Moodle File API cho filearea `card_attachments`, gồm xem danh sách, tải và xóa từng file.
- Backup/restore Moodle 2 cho Kanban, column, card, assignee, member, comment,
  history và file đính kèm.
- PHPUnit external test cho group access, move card, WIP limit và deadline.
- Behat feature cho các kịch bản group access và WIP limit.
- AMD `board.js` đã được build thành `board.min.js` và `board.min.js.map`.
- Có tài liệu backup/restore cho người dùng và quản trị viên.

### 2.3. Các hạng mục còn có thể cải thiện

- Quản lý cột qua external API riêng (tạo, sửa, xóa, đổi thứ tự) chưa được triển khai.
- Sắp xếp vị trí card bằng `newposition` chưa được hỗ trợ đầy đủ.
- Notification Moodle và scheduled task cảnh báo deadline tự động chưa có.
- Một số chuỗi trong dashboard và external API vẫn còn hard-code, cần chuyển hết
  sang language string.
- Dashboard hiện mới có thống kê cơ bản; chưa có biểu đồ và bộ lọc nâng cao.
- Một số lỗi coding style/ESLint trong `amd/src/board.js` vẫn cần xử lý.
- Cần chạy đầy đủ PHPUnit/Behat trên môi trường CI hoặc Moodle test hoàn chỉnh.

## 3. Kiến trúc thư mục

```text
mod/kanban/
├── amd/
│   ├── src/board.js              # JavaScript nguồn cho bảng Kanban
│   └── build/
│       ├── board.min.js          # JavaScript đã minify
│       └── board.min.js.map      # Source map
├── classes/external/
│   └── card_api.php              # External functions cho card
├── db/
│   ├── access.php                # Capability
│   ├── install.xml               # Schema XMLDB
│   ├── services.php              # Đăng ký web service/AJAX
│   └── upgrade.php               # Migration database
├── lang/
│   ├── en/kanban.php             # Language strings tiếng Anh
│   └── vi/kanban.php             # Language strings tiếng Việt
├── templates/board.mustache      # Giao diện bảng
├── backup/moodle2/               # Backup/restore Moodle 2
├── tests/
│   ├── kanban_external_test.php  # PHPUnit external/API tests
│   └── behat/mod_kanban.feature  # Behat scenarios
├── BACKUP_RESTORE_USER_GUIDE.md  # Hướng dẫn backup/restore giao diện
├── BACKUP_RESTORE_ADMIN_GUIDE.md # Hướng dẫn backup/restore kỹ thuật
├── TASK7_AMD_BUILD.md            # Hướng dẫn build AMD
├── dashboard.php                 # Dashboard giảng viên
├── lib.php                       # Vòng đời activity và helper
├── mod_form.php                  # Form tạo/sửa activity
├── upload_and_create_card.php    # Endpoint upload card hiện tại
├── version.php                   # Phiên bản plugin
├── view.php                      # Trang bảng Kanban
└── style.php                     # CSS của module
```

## 4. Database và Moodle XMLDB

Module dùng XMLDB của Moodle, không dùng SQL phụ thuộc riêng vào MariaDB hoặc PostgreSQL.

Schema được định nghĩa trong `db/install.xml`. Moodle sẽ chuyển schema này thành database phù hợp với hệ thống đang chạy.

Các bảng chính:

```text
{prefix}kanban
{prefix}kanban_columns
{prefix}kanban_cards
{prefix}kanban_card_assignees
{prefix}kanban_members
{prefix}kanban_card_comments
{prefix}kanban_card_history
```

Bảng dữ liệu được tổ chức như sau:

| Bảng | Vai trò | Dữ liệu tiêu biểu |
|---|---|---|
| `{prefix}kanban` | Lưu một hoạt động Kanban thuộc khóa học | Tên bảng, khóa học, người tạo, mô tả |
| `{prefix}kanban_columns` | Lưu các cột trạng thái của một bảng Kanban | To Do, In Progress, Done, màu sắc, thứ tự, WIP limit |
| `{prefix}kanban_cards` | Lưu từng công việc/thẻ | Tiêu đề, mô tả, hạn hoàn thành, cột hiện tại, nhóm, người tạo, `task_url` |
| `{prefix}kanban_card_assignees` | Quan hệ nhiều-nhiều giữa card và user | Danh sách người được giao |
| `{prefix}kanban_members` | Thành viên và thông tin nhóm của Kanban | User, group, vai trò, tự đánh giá |
| `{prefix}kanban_card_comments` | Lưu bình luận hoặc phản hồi của giảng viên | Card, người bình luận, nội dung, thời gian |
| `{prefix}kanban_card_history` | Lưu lịch sử thao tác với card | Tạo, di chuyển, xóa, bình luận và thời gian |

`task_url` trong bảng `{prefix}kanban_cards` lưu đường link đính kèm của công việc.
Khi người dùng mở **Xem chi tiết**, `view.php` đọc giá trị này và giao diện hiển thị
liên kết để mở trong tab mới.

> Khi thuyết trình có thể mô tả: Plugin Kanban dùng chung database của Moodle, nhưng
> có các bảng riêng để lưu bảng Kanban, cột trạng thái, công việc, bình luận và lịch sử.
> Vì vậy dữ liệu được lưu lâu dài và có thể truy vấn, thống kê trên dashboard.

Bảng `{prefix}kanban` có trường `creatorid` để lưu ID người dùng Moodle đã tạo
bảng. Tên người tạo được đọc từ bảng `{prefix}user` và hiển thị trên trang
Kanban. Các bảng Kanban cũ chưa có người tạo sẽ hiển thị `Không xác định`.

Trong code PHP phải dùng tên không có tiền tố:

```php
$DB->get_records('kanban_cards', ['kanbanid' => $kanbanid]);
```

Moodle tự thêm tiền tố, ví dụ `mdl_kanban_cards`.

Module không sử dụng đồng thời MariaDB và PostgreSQL. Một Moodle instance dùng database chính được cấu hình trong `config.php`. Code XMLDB và `$DB` giúp module có thể chạy trên cả hai hệ quản trị.

## 5. Vai trò và quyền hạn

Moodle quản lý tài khoản học viên và giáo viên ở cấp hệ thống. Module không tạo bảng tài khoản riêng.

Các capability chính:

- `mod/kanban:addinstance`: thêm hoạt động Kanban vào khóa học.
- `mod/kanban:view`: xem bảng Kanban.
- `mod/kanban:managecards`: tạo, di chuyển và xóa card.
- `mod/kanban:viewdashboard`: xem dashboard và thêm bình luận giáo viên.

Lịch sử thay đổi được lưu trong `{prefix}kanban_card_history` nhưng chỉ được
trả về giao diện cho người có capability `mod/kanban:viewdashboard`.

Vai trò đề xuất:

| Vai trò | Xem bảng | Quản lý card | Xem dashboard |
|---|---:|---:|---:|
| Học viên | Có | Có trong phạm vi nhóm | Không |
| Giáo viên | Có | Có | Có |
| Quản trị viên/Manager | Theo capability Moodle | Theo capability Moodle | Có |

Sau khi thay đổi capability hoặc schema, chạy nâng cấp Moodle để cập nhật plugin.
Migration thêm trường `creatorid` nằm trong `db/upgrade.php`. Có thể chạy nâng
cấp bằng giao diện quản trị hoặc CLI:

```powershell
php admin/cli/upgrade.php
```

## 6. API và bảo mật đã triển khai

Các AJAX external functions được đăng ký trong `db/services.php`:

- `mod_kanban_create_card`
- `mod_kanban_update_card`
- `mod_kanban_move_card`
- `mod_kanban_delete_card`
- `mod_kanban_get_card_activity`
- `mod_kanban_add_teacher_comment`
- `mod_kanban_get_card_files`
- `mod_kanban_delete_card_file`

Các thao tác ghi yêu cầu đăng nhập, capability phù hợp và sesskey. Card và
column được truy vấn kèm `kanbanid`; dữ liệu group được kiểm tra ở server.

File card được phục vụ qua `kanban_pluginfile()` trong `lib.php`, không đọc
trực tiếp từ filesystem.

## 7. Backup và restore

Plugin đã bật `FEATURE_BACKUP_MOODLE2` và có các file:

```text
backup/moodle2/backup_kanban_activity_task.class.php
backup/moodle2/backup_kanban_stepslib.php
backup/moodle2/restore_kanban_activity_task.class.php
backup/moodle2/restore_kanban_stepslib.php
```

Backup/restore bao gồm activity, columns, cards, assignees, members, comments,
history và file `card_attachments`. Khi restore, Moodle map lại ID Kanban,
column, card, user và group.

Đã kiểm thử bằng Moodle CLI: backup course có Kanban và restore sang course mới
thành công; số lượng Kanban, column, card và history được đối chiếu khớp.
Hướng dẫn chi tiết nằm trong `BACKUP_RESTORE_USER_GUIDE.md` và
`BACKUP_RESTORE_ADMIN_GUIDE.md`.

## 8. Kiểm thử và build

PHPUnit hiện có các kiểm thử cho:

- User ngoài group không thể di chuyển card.
- User đúng group có thể di chuyển card.
- WIP limit được chặn ở server khi tạo card.
- Deadline được lưu và nhận diện quá hạn.

Behat feature mô tả các kịch bản giáo viên, group A/group B và WIP limit. Cần
chạy Behat trong môi trường Moodle đã cấu hình đầy đủ để xác nhận giao diện.

Sau khi sửa `amd/src/board.js`, build lại bằng Grunt Moodle:

```powershell
grunt amd --root=mod/kanban
```

Build tạo:
W
```text
amd/build/board.min.js
amd/build/board.min.js.map
```

Chi tiết build và cảnh báo ESLint hiện tại nằm trong `TASK7_AMD_BUILD.md`.

## 9. Hạng mục còn lại

- Bổ sung external API quản lý column: tạo, sửa, xóa và đổi thứ tự.
- Hỗ trợ `newposition` để sắp xếp card ổn định trong cùng column.
- Bổ sung notification Moodle và scheduled task cảnh báo deadline.
- Chuyển các chuỗi hard-code còn lại sang language pack.
- Mở rộng dashboard với thống kê theo column, bộ lọc và biểu đồ.
- Sửa các cảnh báo ESLint hiện có trong `amd/src/board.js`.
- Chạy đầy đủ PHPUnit/Behat trên môi trường kiểm thử tự động.
- Bổ sung kiểm thử tương thích trên MariaDB và PostgreSQL nếu cần nghiệm thu.

## 10. Cài đặt và nâng cấp

1. Sao chép thư mục module vào:

```text
moodle/mod/kanban
```

2. Đăng nhập Moodle bằng tài khoản quản trị.
3. Mở trang quản trị để Moodle phát hiện plugin mới.
4. Chạy nâng cấp qua giao diện hoặc CLI:

```powershell
php admin/cli/upgrade.php
```

5. Xóa cache Moodle sau khi thay đổi JavaScript, template hoặc language string:

```powershell
php admin/cli/purge_caches.php
```

6. Khi thay đổi schema, tăng `$plugin->version` trong `version.php` và thêm migration tương ứng vào `db/upgrade.php`.

## 11. Build JavaScript

Sau khi sửa `amd/src/board.js`, cần build lại file:

```text
amd/build/board.min.js
```

Không nên chỉ sửa file build vì thay đổi sẽ bị mất khi build lại module.

## 12. Kiểm thử thủ công

- Tạo một khóa học và thêm hoạt động Kanban.
- Tạo nhóm sinh viên và gán thành viên.
- Đăng nhập bằng tài khoản giáo viên.
- Kiểm tra tạo card, kéo thả, xóa card và dashboard.
- Đăng nhập bằng tài khoản học viên.
- Kiểm tra chỉ xem được dữ liệu thuộc nhóm hiện tại.
- Kiểm tra ngày quá hạn.
- Kiểm tra bình luận giáo viên.
- Kiểm tra dữ liệu hoạt động trên database được cấu hình trong `config.php`.
- Kiểm tra lỗi khi request không hợp lệ hoặc thiếu capability.

## 13. Tiêu chí nghiệm thu

Dự án được xem là sẵn sàng khi:

- Không thể truy cập hoặc sửa card của Kanban khác.
- Quyền học viên và giáo viên hoạt động đúng.
- Dữ liệu nhóm không bị lộ giữa các nhóm.
- Tạo, sửa, di chuyển và xóa card hoạt động ổn định.
- WIP limit được kiểm tra ở server, không chỉ ở giao diện.
- Tệp đính kèm được bảo vệ qua Moodle File API.
- Xóa activity không để lại dữ liệu liên quan.
- Backup/restore khôi phục đầy đủ dữ liệu.
- Schema hoạt động trên MariaDB và PostgreSQL.
- Có test cho các luồng quan trọng.

## 14. Lưu ý phát triển

- Dùng `$DB` thay vì viết SQL riêng cho một database.
- Dùng XMLDB cho mọi thay đổi schema.
- Không hard-code tiền tố `mdl_`.
- Không tạo bảng user/student/teacher riêng; dùng user, role và enrolment của Moodle.
- Luôn kiểm tra context và capability ở server.
- Không tin dữ liệu chỉ vì đã được kiểm tra ở JavaScript.
- Không commit mật khẩu database hoặc thông tin kết nối vào source code.
