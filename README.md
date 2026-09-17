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
5. Chọn bài tập liên quan (Assignment) trong cùng khóa học nếu muốn gắn bảng
   với một `mod_assign` cụ thể; để `Không liên kết` khi quản lý công việc nội bộ.
   Tên, link và hạn nộp của assignment được hiển thị trên bảng (`view.php`
   qua `kanban_get_linked_assignment_info()`), ID lưu trong `kanban.assignmentid`
   và được validate cùng khóa học khi tạo/cập nhật/restore.
   Phạm vi hiện tại: chỉ liên kết hiển thị (tên/link/duedate), **không** đọc
   submission, điểm hay feedback từ `mod_assign` và **không** tự cập nhật
   trạng thái card khi có bài nộp.
6. Cấu hình chế độ nhóm, group hoặc grouping theo nhu cầu của khóa học.
7. Lưu hoạt động.

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
| History | Nhật ký tạo, di chuyển, cập nhật, xóa hoặc bình luận (bảng riêng, chỉ dashboard xem) |
| Moodle Logs | Event chuẩn `classes/event` (created/updated/moved/deleted/comment) xem ở Reports > Logs |
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

#### Phân công người thực hiện

- Khi tạo card, người dùng tick chọn một hoặc nhiều thành viên trong danh
  sách checkbox **Phân công cho** (khung cuộn `.kanban-assignee-list`, gọn
  theo số người, không cần giữ Ctrl như select cũ).
- Danh sách lựa chọn được giới hạn theo group hiện tại của activity. Nếu chưa
  chọn group, chưa thêm thành viên vào group hoặc người dùng không có quyền
  `moodle/site:accessallgroups`, danh sách có thể hiển thị
  **Không có thành viên để phân công**.
- Một card có thể có nhiều assignee. Quan hệ này được lưu trong
  `kanban_card_assignees`; trường `assigned_to` của card giữ lại người đầu tiên
  để tương thích với dữ liệu cũ.
- Việc thay đổi danh sách assignee sau khi tạo đã được hỗ trợ ở tầng API
  `mod_kanban_update_card` (kiểm tra lại theo group ở server) và ở giao diện
  modal sửa card (checkbox preselect theo assignee hiện tại).

Khi kéo card sang column khác, JavaScript gọi `mod_kanban_move_card`. Server
kiểm tra card và column có cùng Kanban, người dùng có quyền với group, sau đó
kiểm tra WIP limit trước khi cập nhật `columnid`.

Việc phân công không thay đổi quyền xem bảng. Người được giao vẫn phải được
enrol vào khóa học và phải có `mod/kanban:view`; quyền `mod/kanban:managecards`
mới quyết định người đó có thể tạo hoặc thao tác card.

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

- **Giảng viên**: tạo activity, xem dashboard, xem history, thêm comment,
  quản lý card và quản lý cột (`mod/kanban:managecolumns`) theo capability
  được cấp.
- **Sinh viên**: xem board và thao tác card trong group được phép
  (`mod/kanban:managecards`); không quản lý cột.
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

## 1.3. Yêu cầu hệ thống

| Thành phần | Yêu cầu |
|---|---|
| Moodle | 4.1+ (`$plugin->requires = 2022112800` trong `version.php`) |
| Plugin | `mod_kanban` `v0.1.0`, `maturity = ALPHA` |
| PHP ext | `zip`, `gd`, `intl`, `sodium` (Moodle yêu cầu; xem ADMIN guide §1) |
| Database | MariaDB hoặc PostgreSQL qua `$DB`/XMLDB, cấu hình trong `config.php` (một instance một DB chính, không dùng song song) |
| Cron | Bắt buộc để chạy scheduled task `deadline_notifications` (mỗi 15 phút); kiểm tra `php admin/cli/cron.php` nếu không thấy cảnh báo deadline |

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
- Liên kết Assignment (`kanban.assignmentid`): chọn `mod_assign` cùng khóa học
  trong `mod_form.php`, hiển thị banner tên/link/hạn nộp, backup/restore giữ
  liên kết khi assignment tồn tại ở khóa học đích.
- Ba cột mặc định: To Do, In Progress và Done.
- Lưu và hiển thị tên người tạo bảng Kanban.
- Bảng Kanban, cột, card, bình luận và lịch sử.
- Tạo card bằng AJAX.
- Tạo card kèm tệp đính kèm qua endpoint upload trên chuẩn Moodle File API.
- Kéo thả card giữa các cột và sắp xếp vị trí trong cùng cột
  (`mod_kanban_move_card` nhận `newposition`, `sortorder` lưu thứ tự,
  card mới append cuối cột).
- Bình luận chung cho mọi thành viên có `managecards`
  (`mod_kanban_add_comment`); API giáo viên cũ giữ lại để tương thích.
- Xóa card.
- Tự động tải lại bảng sau khi tạo, di chuyển hoặc xóa card để cập nhật số lượng.
- Hạn hoàn thành.
- Hiển thị card quá hạn hoặc sắp đến hạn.
- Bình luận của giáo viên.
- Lịch sử thay đổi chỉ hiển thị cho giáo viên có quyền xem dashboard.
- Hiển thị tên cột trạng thái thay cho ID card trong chi tiết công việc.
- Dashboard giảng viên (`dashboard.php`, quyền `mod/kanban:viewdashboard`).
  Xem chi tiết ở §2.2.1 bên dưới.
- Tạo card kèm file đính kèm qua endpoint upload trên chuẩn Moodle File API
  (`upload_and_create_card.php`). Xem chi tiết ở §2.2.2 bên dưới.
- Phân quyền xem bảng, quản lý card và xem dashboard.
- Kiểm tra `cmid`, context, capability, sesskey và quan hệ card/column với đúng Kanban.
- Giới hạn truy cập card theo group, hỗ trợ quyền `moodle/site:accessallgroups`.
- Cập nhật card gồm tiêu đề, mô tả, deadline, URL nộp bài và assignee
  (API `mod_kanban_update_card` đã đầy đủ, UI sửa assignee còn đơn giản).
- Quản lý cột: tạo, sửa tên/mô tả/màu/WIP limit, xóa cột rỗng, đổi thứ tự
  qua 4 API `mod_kanban_create_column`, `mod_kanban_update_column`,
  `mod_kanban_delete_column`, `mod_kanban_reorder_columns`.
- Notification và scheduled task deadline (3 providers + task 15 phút).
  Xem chi tiết ở §2.2.4 bên dưới.
- Kiểm tra WIP limit ở server khi tạo, di chuyển hoặc cập nhật card.
- Moodle File API cho filearea `card_attachments`, gồm xem danh sách, tải và xóa từng file.
- Backup/restore Moodle 2 cho Kanban, column, card, assignee, member, comment,
  history và file đính kèm.
- PHPUnit external test cho group access, move card, WIP limit và deadline.
- Behat feature cho các kịch bản group access và WIP limit.
- AMD `board.js` đã được build thành `board.min.js` và `board.min.js.map`.
- Có tài liệu backup/restore cho người dùng và quản trị viên (xem §7 bên dưới).
- Xóa activity dọn sạch dữ liệu liên quan qua `kanban_delete_instance()`.
  Xem chi tiết ở §2.2.3 bên dưới.

### 2.2.1. Dashboard giảng viên (`dashboard.php`)

URL `dashboard.php?id=<cmid>`, yêu cầu `require_login()` và
`require_capability('mod/kanban:viewdashboard')`.

- Bộ lọc group: dùng `groups_get_activity_group()` và
  `groups_print_activity_menu()`. Mọi số liệu đổi theo group đang chọn.
- Quy ước hoàn thành: card nằm ở cột cuối cùng theo `sortorder DESC`
  được tính là Done (`kanban_is_done_column()` logic tương đương).
- 4 KPI: tổng card (`dashboard_kpi_total`), tỷ lệ hoàn thành %
  (`dashboard_kpi_progress`), quá hạn `duedate < now`
  (`dashboard_kpi_overdue`), sắp đến hạn trong 48 giờ
  (`dashboard_kpi_duesoon`).
- 2 biểu đồ Moodle core (`core\chart_bar`, `core\chart_pie` doughnut):
  phân bố card theo cột (`dashboard_column_dist`) và tỷ lệ hoàn thành
  (`dashboard_progress_pie` vs `dashboard_remaining`). Chỉ render khi core
  hỗ trợ chart và `totalcards > 0`.
- Bảng đóng góp cá nhân (`dashboard_member_title`): đếm qua bảng
  `kanban_card_assignees` nên đúng với multi-assignee. Các cột:
  thành viên, số task, hoàn thành, trễ hạn, tỷ lệ đóng góp %, thanh tiến độ.
  Card chưa phân công gom vào `dashboard_unassigned`. Sắp xếp giảm dần theo
  tổng task.
- Bảng cảnh báo (`dashboard_overdue_title`): card chưa done và
  `duedate <= now + 48h`, sắp xếp theo duedate, badge đỏ (quá hạn) / vàng
  (sắp đến hạn), hiển thị tên cột, assignee (`kanban_get_card_assignee_names()`)
  và hạn `userdate()`.
- Giới hạn hiện tại: chưa lọc theo thành viên, khoảng thời gian hay trạng thái
  tùy ý; chưa xuất CSV/báo cáo. Đây là hướng mở rộng P2 trong báo cáo.

### 2.2.2. Tạo card kèm file (`upload_and_create_card.php`)

Endpoint JSON `POST upload_and_create_card.php` cho form tạo card có file:

- Tham số: `cmid*`, `kanbanid*`, `columnid*`, `title`, `description`
  (`PARAM_RAW`), `duedate` (`PARAM_INT`), `submissionurl` (`PARAM_URL`),
  `assignees[]` (`PARAM_INT`).
- Kiểm tra: `require_login()`, `require_capability('mod/kanban:managecards')`,
  `require_sesskey()`, `kanbanid == cm->instance`, column thuộc đúng Kanban,
  membership group (`moodle/site:accessallgroups` hoặc `groups_is_member()`).
- Validate: deadline quá khứ (`duedate < time() - 300`) trả về
  `error_duedate_past`; `kanban_check_wip_limit()`; `kanban_validate_assignee_list()`;
  `sortorder = MAX(sortorder) + 1` theo `(kanbanid, columnid, groupid)`.
- Sau insert: `kanban_set_card_assignees()`, trigger event `card_created`,
  `kanban_log_card_change('created')`, `kanban_notify_card_assignees('assigned')`.
- File: quét `$_FILES` có field chứa `attach` hoặc `attachments` /
  `attachments[]`, chỉ nhận `UPLOAD_ERR_OK`, làm sạch tên bằng
  `clean_param(PARAM_FILE)`, lưu bằng `create_file_from_pathname()` vào
  `mod_kanban / card_attachments / itemid = cardid`. Response:
  `{status, cardid, files, message}`.
- Giới hạn: không kiểm tra MIME/size riêng ở plugin (dựa vào giới hạn Moodle);
  file lỗi bị bỏ qua thay vì fail cả card.

### 2.2.3. Xóa activity (`kanban_delete_instance()` trong `lib.php`)

Khi GV xóa hoạt động Kanban, `kanban_delete_instance($id)` dọn:

1. File `intro` và `card_attachments` qua `$fs->delete_area_files()`.
2. `kanban_card_assignees`, `kanban_card_comments`, `kanban_card_history`
   theo danh sách `cardids` (`get_in_or_equal()`).
3. `kanban_members`, `kanban_cards`, `kanban_columns`, `kanban`.

Vì restore tạo ID mới, khi đối chiếu sau restore phải so quan hệ/nội dung
(`kanbanid`, `columnid`, `cardid`), không so ID tuyệt đối.

### 2.2.4. Thông báo và scheduled task deadline

Khai báo trong `db/messages.php` (3 providers, mặc định `popup` + `email`,
capability `mod/kanban:view`):

| Provider | Khi nào gửi | Subject string |
|---|---|---|
| `cardassigned` | Card được tạo/cập nhật assignee (`kanban_notify_card_assignees($card, $cm, 'assigned')`) | `message_cardassigned_subject` |
| `cardupdated` | Card được sửa, di chuyển, bình luận (`... , 'updated'`) | `message_cardupdated_subject` |
| `deadline` | Sắp đến hạn (`deadline_warning`) hoặc quá hạn (`deadline_overdue`) | `message_deadline_subject` |

Logic trong `lib.php:kanban_notify_card_assignees()`:

- Chỉ gửi khi `get_config('mod_kanban', 'enablenotifications')` bật.
- Chỉ gửi cho user trong `kanban_card_assignees`, bỏ qua bản ghi đã xóa
  (`deleted = 0`).
- Với `assigned`/`updated`: bỏ qua chính người thao tác (`$USER->id`); với
  deadline vẫn gửi cho chính assignee.
- Nội dung: tên card + tên Kanban + câu deadline (`deadline_warning_message` /
  `deadline_overdue_message` với `userdate(duedate)`) hoặc
  `card_updated_message`. Link về `view.php?id=<cmid>`, gửi từ noreply user.

Scheduled task `mod_kanban\task\deadline_notifications` (`db/tasks.php`,
`minute = */15`):

- Bỏ qua nếu `enablenotifications` tắt.
- Quét `kanban_cards` có `duedate <= now + 48h`, bỏ card đã nằm ở cột Done
  (cột cuối `sortorder DESC`), bỏ card đã có history `deadline_warning` /
  `deadline_overdue` (chống spam: mỗi card một lần cho mỗi trạng thái).
- Gọi notify + `kanban_log_card_change()` tương ứng.

Bật/tắt: **Site administration > Plugins > Activity modules > Kanban >
Enable Kanban notifications** (`settings.php` → `mod_kanban/enablenotifications`,
mặc định bật). Cron Moodle phải chạy (`php admin/cli/cron.php`); nếu tắt
notification hoặc cron dừng thì không có cảnh báo nào được gửi.

### 2.3. Các hạng mục còn có thể cải thiện

Chi tiết đầy đủ xem [§9](#9-hạng-mục-còn-lại) bên dưới. Tóm tắt:

- Chuỗi JavaScript trong `amd/src/board.js` (confirm/prompt/thông báo lỗi client)
  vẫn còn hard-code; có thể truyền qua `js_call_amd` hoặc `core/str` khi cần
  đa ngôn ngữ đầy đủ ở client.
- Dashboard có thể bổ sung bộ lọc theo cột/khoảng thời gian nếu cần.

## 3. Kiến trúc thư mục

```text
mod/kanban/
├── amd/
│   ├── src/board.js              # JavaScript nguồn cho bảng Kanban
│   └── build/
│       ├── board.min.js          # JavaScript đã minify
│       └── board.min.js.map      # Source map
├── classes/
│   ├── external/
│   │   ├── card_api.php          # External functions cho card
│   │   └── column_api.php        # External functions quản lý column
│   ├── task/
│   │   └── deadline_notifications.php  # Scheduled task cảnh báo deadline
│   └── event/
│       ├── card_created.php        # Log Moodle khi tạo thẻ
│       ├── card_updated.php        # Log Moodle khi cập nhật thẻ
│       ├── card_moved.php          # Log Moodle khi di chuyển/sắp xếp thẻ
│       ├── card_deleted.php        # Log Moodle khi xóa thẻ
│       └── comment_created.php     # Log Moodle khi bình luận
├── db/
│   ├── access.php                # Capability
│   ├── install.xml               # Schema XMLDB
│   ├── services.php              # Đăng ký web service/AJAX (13 functions)
│   ├── messages.php              # Khai báo notification providers
│   ├── tasks.php                 # Khai báo scheduled task (mỗi 15 phút)
│   └── upgrade.php               # Migration database
├── lang/
│   ├── en/kanban.php             # Language strings tiếng Anh
│   └── vi/kanban.php             # Language strings tiếng Việt
├── templates/board.mustache      # Giao diện bảng
├── backup/moodle2/               # Backup/restore Moodle 2
├── tests/
│   ├── kanban_external_test.php  # PHPUnit external/API tests
│   └── behat/mod_kanban.feature  # Behat scenarios
├── docs/csdl_thamkhao.sql        # SQL tham khảo, không dùng để cài đặt
├── pix/icon.svg                  # Icon activity
├── mod_form.php                  # Form tạo/sửa activity (gồm assignment selector)
├── version.php                   # component mod_kanban, requires Moodle 4.1+, release v0.1.0
├── BACKUP_RESTORE_USER_GUIDE.md  # Hướng dẫn backup/restore bằng UI cho GV (xem §7)
├── BACKUP_RESTORE_ADMIN_GUIDE.md # Hướng dẫn CLI/kỹ thuật cho admin (xem §7)
├── TASK7_AMD_BUILD.md            # Hướng dẫn build AMD
├── dashboard.php                 # Dashboard giảng viên
├── lib.php                       # Vòng đời activity, helper, notify, pluginfile
├── mod_form.php                  # Form tạo/sửa activity
├── settings.php                  # Cài đặt admin (enablenotifications)
├── upload_and_create_card.php    # Endpoint upload kèm tạo card (File API)
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

### Hỗ trợ và giới hạn (`kanban_supports()` trong `lib.php`)

Hỗ trợ (`return true`): `FEATURE_GROUPS`, `FEATURE_GROUPINGS`,
`FEATURE_MOD_INTRO`, `FEATURE_SHOW_DESCRIPTION`, `FEATURE_BACKUP_MOODLE2`.

Chưa hỗ trợ: completion/grade nâng cao, calendar, search, và chưa có Privacy
provider (`classes/privacy/` không tồn tại). Dữ liệu user nằm ở
`kanban_cards.assigned_to`, `kanban_card_assignees`, `kanban_card_comments`,
`kanban_card_history`, `kanban_members` — cần bổ sung privacy API nếu nghiệm
thu GDPR.

## 5. Vai trò và quyền hạn

Moodle quản lý tài khoản học viên và giáo viên ở cấp hệ thống. Module không tạo bảng tài khoản riêng.

Các capability chính (`db/access.php`):

- `mod/kanban:addinstance`: thêm hoạt động Kanban vào khóa học.
- `mod/kanban:view`: xem bảng Kanban.
- `mod/kanban:managecards`: tạo, di chuyển và xóa card.
- `mod/kanban:managecolumns`: tạo, sửa, xóa và sắp xếp cột
  (teacher/editingteacher/manager; student không có).
- `mod/kanban:viewdashboard`: xem dashboard và thêm bình luận giáo viên.

Lịch sử thay đổi được lưu trong `{prefix}kanban_card_history` nhưng chỉ được
trả về giao diện cho người có capability `mod/kanban:viewdashboard`.
Bình luận hiện được thiết kế theo mô hình giáo viên phản hồi trên card: học
viên có thể xem bình luận của giáo viên nhưng không có quyền gửi bình luận qua
`mod_kanban_add_teacher_comment`.

Vai trò đề xuất:

| Vai trò | Xem bảng | Quản lý card | Quản lý cột | Xem dashboard |
|---|---:|---:|---:|---:|
| Học viên | Có | Có trong phạm vi nhóm | Không | Không |
| Giáo viên | Có | Có | Có | Có |
| Quản trị viên/Manager | Theo capability Moodle | Theo capability Moodle | Theo capability Moodle | Có |

Sau khi thay đổi capability hoặc schema, chạy nâng cấp Moodle để cập nhật plugin.
Migration thêm trường `creatorid` nằm trong `db/upgrade.php`. Có thể chạy nâng
cấp bằng giao diện quản trị hoặc CLI:

```powershell
php admin/cli/upgrade.php
```

## 6. API và bảo mật đã triển khai

Các AJAX external functions được đăng ký trong `db/services.php` (tổng 13 functions):

Card (`classes/external/card_api.php`):

- `mod_kanban_create_card`
- `mod_kanban_update_card`
- `mod_kanban_move_card`
- `mod_kanban_delete_card`
- `mod_kanban_get_card_activity`
- `mod_kanban_add_comment` (bình luận chung cho mọi thành viên có `managecards`)
- `mod_kanban_add_teacher_comment` (giữ tương thích, yêu cầu `viewdashboard`)
- `mod_kanban_get_card_files`
- `mod_kanban_delete_card_file`

Column (`classes/external/column_api.php`):

- `mod_kanban_create_column`
- `mod_kanban_update_column`
- `mod_kanban_delete_column`
- `mod_kanban_reorder_columns`

Các thao tác ghi yêu cầu đăng nhập và capability phù hợp. Sesskey được
framework kiểm tra trong `lib/ajax/service.php` (`call_external_function`),
không gọi `require_sesskey()` riêng trong từng external function để các cuộc
gọi web service bằng token vẫn hoạt động. Card và
column được truy vấn kèm `kanbanid`; dữ liệu group được kiểm tra ở server.
Riêng 4 API cột (`create/update/delete/reorder`) yêu cầu
`mod/kanban:managecolumns` (giảng viên/manager), không dùng `managecards`.

File card được phục vụ qua `kanban_pluginfile()` trong `lib.php`, không đọc
trực tiếp từ filesystem.

### 6.1. Events và Logs (`classes/event/`)

| Event | Kích hoạt khi | CRUD |
|---|---|---|
| `card_created` | Tạo card (AJAX hoặc upload kèm file) | c |
| `card_updated` | Sửa title/desc/deadline/URL/assignee | u |
| `card_moved` | Kéo sang cột khác hoặc sắp xếp lại (`fromcolumnid → tocolumnid`, `newposition`) | u |
| `card_deleted` | Xóa card | d |
| `comment_created` | Thêm bình luận (chung hoặc GV) | c |

Xem ở **Course > Reports > Logs**, `objecttable = kanban_cards`, URL trỏ về
`view.php?id=<cmid>`. Riêng history chi tiết (`kanban_card_history`) chỉ trả
về cho người có `mod/kanban:viewdashboard`.

### 6.2. Lỗi thường gặp và troubleshooting

| Thông báo | Nguyên nhân | Cách xử lý |
|---|---|---|
| `errorwiplimit` (kèm tên cột + limit) | Cột đã đủ WIP khi tạo/move/update card | Chuyển card sang cột khác hoặc tăng WIP / để 0 = không giới hạn |
| `error_duedate_past` | Deadline trong quá khứ (`duedate < time() - 300`) | Chọn lại hạn tương lai |
| `nopermissions` | Thiếu capability, ngoài group, assignee ngoài group | Kiểm tra enrol, group membership, `accessallgroups` |
| `invalidrecord` / `Invalid kanban instance` | `cmid`/`kanbanid`/`columnid`/`cardid` không cùng Kanban | Không sửa ID trên URL/AJAX; tải lại board |
| `errorinvalidcolumncolor` / `errorinvalidwiplimit` | Màu cột sai định dạng, WIP âm | Nhập hex hợp lệ, WIP số nguyên ≥ 0 |
| `errorcolumnnotempty` / `cannotdeleteallcolumns` | Xóa cột còn card / xóa cột cuối cùng | Dời/xóa hết card trước; giữ ≥ 1 cột |
| File lỗi bị bỏ qua, `files = 0` | `UPLOAD_ERR_*`, tên rỗng sau `PARAM_FILE` | Kiểm tra size/type theo giới hạn Moodle, đổi tên file |
| Không nhận notification deadline | `enablenotifications` tắt hoặc cron dừng | Bật setting (xem §2.2.4), chạy `php admin/cli/cron.php` |

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

- Người dùng (GV): xem [BACKUP_RESTORE_USER_GUIDE.md](./BACKUP_RESTORE_USER_GUIDE.md)
  — backup/restore bằng giao diện Course reuse, checklist sau restore.
- Quản trị viên: xem [BACKUP_RESTORE_ADMIN_GUIDE.md](./BACKUP_RESTORE_ADMIN_GUIDE.md)
  — yêu cầu PHP ext (`zip`, `gd`, `intl`, `sodium`), lệnh
  `admin/cli/upgrade.php`, `admin/cli/backup.php --courseid`,
  `admin/cli/restore_backup.php --categoryid`, và troubleshooting
  (`unknown_context_mapping`, `cardid` NULL, file attachment missing).

## 8. Kiểm thử và build

Chạy trên Moodle 4.3 + MariaDB: `OK (4 tests, 7 assertions)`.

- User ngoài group không thể di chuyển card.
- User đúng group có thể di chuyển card.
- WIP limit được chặn ở server khi tạo card.
- Deadline được lưu và nhận diện quá hạn.

Cách chạy và viết test xem `docs/HUONG_DAN_KIEM_THU.md`.

Behat feature mô tả các kịch bản giáo viên, group A/group B và WIP limit. Cần
chạy Behat trong môi trường Moodle đã cấu hình đầy đủ để xác nhận giao diện.

Sau khi sửa `amd/src/board.js`, build lại theo §11 bên dưới.
Chi tiết build và cảnh báo ESLint hiện tại nằm trong `TASK7_AMD_BUILD.md`.

## 9. Hạng mục còn lại

- Tinh chỉnh notification: chống spam scheduled task, kiểm thử bật/tắt
  `enablenotifications` trong `settings.php`.
- Đa ngôn ngữ client: chuyển chuỗi JavaScript trong `board.js` sang `core/str`
  nếu cần (server/template đã dùng language pack).
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

### 10.1. Đóng gói plugin

```powershell
cd moodle/mod
Compress-Archive -Path kanban -DestinationPath mod_kanban-v0.1.0.zip
```

Chỉ đóng gói thư mục `kanban` (đã gồm `amd/build`, lang, backup, docs).
Không đóng gói `.git`, `node_modules` (nếu có). Cài bằng cách giải nén zip
vào `moodle/mod/kanban` rồi chạy nâng cấp như §10.

## 11. Build JavaScript

Sau khi sửa `amd/src/board.js`, build lại bằng Grunt Moodle:

```powershell
grunt amd --root=mod/kanban
```

Build tạo:

```text
amd/build/board.min.js
amd/build/board.min.js.map
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

## 15. Tài liệu tham khảo

- Moodle Developer Resources - https://moodledev.io/docs
- Moodle Plugin Types - https://moodledev.io/docs/5.0/apis/plugintypes
- Moodle Coding Style - https://moodledev.io/general/development/policies/codingstyle
- Moodle Testing - https://moodledev.io/general/development/tools

## 16. Phiên bản, License và Changelog

- `version.php`: `component = mod_kanban`, `version = 2026091700`,
  `requires = 2022112800` (Moodle 4.1+), `maturity = ALPHA`,
  `release = v0.1.0`.
- License: GPL v3 (chuẩn plugin Moodle).
- Changelog:
  - `2026091700`: fix QA — `delete_card` dọn assignees/comments/history/file;
    `kanban_set_card_assignees()` không đổi `groupid`; `create_card` +
    upload chặn title rỗng; bỏ `require_sesskey()` trong external functions
    (framework đã kiểm tra, WS token hoạt động); sửa test (enrol, cm thật,
    `$SESSION->activegroup`). Probe 16/16 PASS, PHPUnit 4/4 PASS.
- Changelog:
  - `v0.1.0`: Task 1-7 (bảo mật cmid/kanban/card, multi-assignee,
    File API, `update_card`, backup/restore Moodle 2, PHPUnit/Behat,
    AMD build), notification `cardassigned/cardupdated/deadline` +
    scheduled task `deadline_notifications` 15 phút, quản lý cột
    (tạo/sửa/xóa/sắp xếp), `sortorder` card, liên kết Assignment
    (tên/link/hạn nộp).
