# Kế hoạch kiểm thử plugin Moodle Kanban

| Thuộc tính | Giá trị |
|---|---|
| Plugin | `mod_kanban` v0.1.0 (`2026091700`) |
| Loại plugin | Moodle Activity module |
| Moodle | 4.3.12 (Build 20250414) |
| Runtime đã xác nhận | PHP 8.2.35, MariaDB 10.11.19, Docker/WSL2 |
| Giao diện đã xác nhận | Boost, Chrome + Selenium Chrome |
| Phạm vi database | MariaDB; PostgreSQL ngoài phạm vi nghiệm thu |
| Cập nhật | 07/11/2026 |

## 1. Mục tiêu và nguyên tắc

Mục tiêu là xác nhận Kanban hoạt động đúng, an toàn và dễ dùng cho Administrator/Manager, Teacher, Non-editing teacher, Student và Guest. Tất cả thao tác thay đổi phải được kiểm tra ở server bằng login, context, capability, group và validation dữ liệu; không chấp nhận chỉ kiểm tra bằng việc ẩn nút trên UI.

Ký hiệu kết quả trong bảng: **A** = phù hợp tự động hóa, **M** = kiểm thử thủ công, **N/A** = không thuộc chức năng hiện tại.

## 2. Bảng test case

| ID | Nhóm | Tiền điều kiện | Các bước thực hiện | Dữ liệu test | Kết quả mong đợi | Ưu tiên |
|---|---|---|---|---|---|---|
| TC-INS-01 | Cài đặt | Moodle sạch, admin | Cài `mod_kanban`, mở Notifications | ZIP/thư mục plugin hợp lệ | Plugin được nhận, schema tạo thành công, không có PHP exception. **M** | Cao |
| TC-INS-02 | Nâng cấp | Bản Kanban cũ có data | Tăng version, chạy Notifications/CLI upgrade | Bản mới có migration | Upgrade hoàn tất; card/cột/assignee cũ vẫn đọc được. **M** | Cao |
| TC-INS-03 | Gỡ plugin | Site test, có activity | Uninstall plugin theo Moodle | Dữ liệu test | Moodle cảnh báo đúng; bảng/dữ liệu plugin bị xử lý theo lifecycle Moodle. Không chạy trên site thật. **M** | Trung bình |
| TC-INS-04 | Cấu hình | Admin | Kiểm tra plugin settings và capability | Giá trị mặc định | Settings hiển thị, language string không bị thiếu. **M** | Trung bình |
| TC-ACT-01 | Activity | Teacher có course edit | Add activity/resource → Kanban → lưu | Tên hợp lệ | Tạo activity và ba cột To Do/In Progress/Done. **M** | Cao |
| TC-ACT-02 | Activity | Có Kanban | Sửa tên/mô tả/Assignment liên kết | Assignment cùng course | Thay đổi được lưu; assignment hiển thị chỉ đọc. **M** | Trung bình |
| TC-ACT-03 | Activity | Có Kanban | Ẩn activity rồi đăng nhập Student | Activity hidden | Student không thấy/truy cập trái phép được. **M** | Cao |
| TC-ACT-04 | Activity | Có Kanban/card/file | Xóa activity từ course | Dữ liệu Kanban | Activity biến mất; không còn dữ liệu mồ côi. **M** | Cao |
| TC-COL-01 | Cột | Teacher có `managecolumns` | Thêm cột | Tên, màu hex, WIP=2 | Cột tạo đúng thứ tự/màu/WIP. **A/M** | Cao |
| TC-COL-02 | Cột | Có cột | Sửa tên, màu, WIP | WIP 0, 1, số dương | WIP=0 nghĩa không giới hạn; giá trị hợp lệ được lưu. **A/M** | Cao |
| TC-COL-03 | Cột | Có ≥3 cột | Đổi thứ tự cột | Kéo/thao tác điều hướng | Thứ tự được lưu sau refresh. **A/M** | Trung bình |
| TC-COL-04 | Cột | Cột rỗng | Xóa cột | Cột rỗng | Xóa thành công, trừ cột cuối cùng. **A/M** | Trung bình |
| TC-COL-05 | Cột | Cột có card | Xóa cột | Cột có 1 card | Server từ chối, card không mất. **A** | Cao |
| TC-CARD-01 | Card | User có `managecards` | Tạo card | Title, description, future deadline | Card hiện đúng cột; history/event được ghi. **A/M** | Cao |
| TC-CARD-02 | Card | Có card | Sửa title, mô tả, deadline, URL | Unicode/tiếng Việt | Giá trị mới còn sau refresh. **A/M** | Cao |
| TC-CARD-03 | Card | Có card | Gán 1 rồi nhiều assignee trong group | 1-3 user hợp lệ | Tất cả assignee được lưu/hiển thị; user ngoài group bị chặn. **A/M** | Cao |
| TC-CARD-04 | Card | Có card | Xóa card | Card có comment/file | Card, assignee, comment, history và file liên quan được dọn đúng. **A/M** | Cao |
| TC-CARD-05 | Di chuyển | Có 2 cột/card | Kéo card sang cột khác | Card hợp lệ | Cột đích/sortorder lưu sau refresh. **A/Behat** | Cao |
| TC-CARD-06 | Sắp xếp | Một cột ≥3 card | Kéo card trong cùng cột | Card A/B/C | Sortorder đúng và ổn định sau refresh. **A** | Cao |
| TC-WIP-01 | WIP | Cột WIP=1, có 1 card | Tạo card thứ hai | Title hợp lệ | Server từ chối, số card vẫn là 1. **A/Behat** | Cao |
| TC-WIP-02 | WIP | Cột đích WIP=1, đã đầy | Di chuyển card vào cột đích | Card từ cột khác | Server từ chối, card ở cột nguồn. **A/Behat** | Cao |
| TC-COM-01 | Comment/history | Teacher/card hợp lệ | Thêm comment, mở chi tiết | Text tiếng Việt | Comment và history có author/thời gian chính xác. **M** | Trung bình |
| TC-FILE-01 | Attachment | User có quyền card | Upload/tải file | PDF/PNG nhỏ | File lưu qua Moodle File API và tải được bởi user đúng quyền. **A/M** | Cao |
| TC-FILE-02 | Attachment | Card group A | User group B mở URL file trực tiếp | URL file của group A | Server trả access denied/không gửi file. **A** | Cao |
| TC-GRP-01 | Group | Separate groups A/B | Student A xem board | Card group A/B | A chỉ thấy/thao tác card A. **Behat** | Cao |
| TC-GRP-02 | Group | Separate groups A/B | Student B di chuyển card A bằng UI/AJAX | Card group A | Server từ chối; không thay đổi card. **Behat** | Cao |
| TC-ROLE-01 | Capability | Teacher | Tạo/sửa/xóa cột/card | Các thao tác quản lý | Chỉ thực hiện theo capability đã cấp. **M** | Cao |
| TC-ROLE-02 | Capability | Non-editing teacher | Mở board và gọi AJAX quản lý | API card/cột | Không quản lý được nếu capability không cho phép. **M** | Cao |
| TC-ROLE-03 | Capability | Student | Tạo/di chuyển card trong group | Card nhóm mình | Chỉ thao tác được nếu có `managecards`. **M** | Cao |
| TC-ROLE-04 | Capability | Guest | Mở `view.php?id=<cmid>` và API trực tiếp | CMID thật | Require login/guest restriction hoạt động; không lộ data. **M** | Cao |
| TC-SEC-01 | Truy cập trực tiếp | User không có quyền | Sửa cardid/columnid/CMID trên request | ID Kanban khác | Server trả lỗi quyền/validation; không sửa bản ghi. **A/M** | Cao |
| TC-SEC-02 | CSRF | User đăng nhập | Gửi request đổi dữ liệu thiếu sesskey | AJAX giả lập | Request bị từ chối. **M** | Cao |
| TC-SEC-03 | XSS | Teacher | Nhập script vào title/description/comment | `<script>alert(1)</script>` | Nội dung được escape/sanitize; script không chạy. **M** | Cao |
| TC-SEC-04 | SQL injection | User hợp lệ | Nhập chuỗi SQL vào trường text/ID request | `' OR 1=1 --` | Không có lỗi SQL, không lộ/sửa dữ liệu khác. **M** | Cao |
| TC-INT-01 | Assignment | Có Assignment cùng course | Liên kết Assignment rồi mở Kanban | Assignment có deadline | Hiện tên/link/hạn; Kanban không thay submission/grade/feedback. **A/M** | Trung bình |
| TC-INT-02 | Completion/grade | Course có completion/gradebook | Kiểm tra activity settings và gradebook | N/A nếu không hỗ trợ | Hoạt động theo Moodle core; gradebook là **N/A** nếu Kanban không khai báo grading. **M** | Thấp |
| TC-INT-03 | Logs/events | Có thao tác card | Mở Reports → Logs | Create/update/move/delete | Event hiện user, thời gian, context, object đúng. **M** | Trung bình |
| TC-INT-04 | Backup/restore | Course có data đầy đủ | Backup course, restore sang course mới | Card/file/comment/group | Dữ liệu và mapping được khôi phục; file tải được. **M** | Cao |
| TC-INT-05 | Privacy | Có dữ liệu user | Export/delete data qua Privacy API | User có card/comment | Dữ liệu được xuất/xóa theo Moodle Privacy API. **A/M** | Trung bình |
| TC-INT-06 | Ngôn ngữ | Có `en`, `vi` | Đổi language rồi mở board/modal | Vietnamese/English | Chuỗi server hiển thị đúng; không có `[[stringid]]`. **M** | Trung bình |
| TC-NOT-01 | Notification | Cron Docker chạy, deadline sắp đến | Tạo card/Assignment có deadline, chạy cron | Hạn trong ngưỡng | Notification nội bộ Moodle được tạo một lần theo rule. **M** | Trung bình |
| TC-UI-01 | Responsive | Chrome DevTools/thiết bị thật | Mở board tại 1366px, 768px, 375px | Board có nhiều cột | Không che nút quan trọng; có scroll/bố cục đọc được. **M** | Trung bình |
| TC-UI-02 | Accessibility | Board có keyboard | Tab qua nút/modal; kiểm tra focus/aria | Keyboard only | Focus rõ, modal đóng được, label không thiếu. **M** | Trung bình |
| TC-UI-03 | Empty/error/loading | Board rỗng/lỗi API | Mở board rỗng, ngắt mạng khi AJAX | Không có card/mất mạng | Có empty/error feedback; UI không kẹt loading. **M** | Trung bình |
| TC-PERF-01 | Hiệu năng | Site test tách biệt | Tạo 20 cột, 500 card; đo tải board | Dataset lớn | Board/API vẫn phản hồi mục tiêu NFR-01; không timeout. **M** | Trung bình |
| TC-STAB-01 | Đồng thời | 2 user cùng quyền | Cùng di chuyển/sửa một card | 2 browser sessions | Không tạo record hỏng; trạng thái cuối có thể giải thích bằng thứ tự request. **M** | Cao |
| TC-OPS-01 | Log/debug | Developer mode test | Thao tác toàn bộ luồng | Browser console/PHP logs | Không có JS error, PHP warning/fatal hay deprecated mới. **M** | Trung bình |

## 3. Test biên và tiêu cực

1. Title rỗng, chỉ khoảng trắng, 1 ký tự, dài hơn giới hạn database/UI.
2. Description/Comment có tiếng Việt có dấu, emoji, HTML, script, SQL-like string, URL rất dài.
3. Deadline ở quá khứ, đúng thời điểm hiện tại, múi giờ khác, deadline sát mốc 48 giờ.
4. WIP: `-1`, `0`, `1`, giá trị rất lớn, chuỗi chữ, số thập phân.
5. Card/cột không thuộc Kanban hiện tại; card ID đã xóa; column ID đã xóa.
6. User không enrol, guest, student group khác, user thuộc nhiều group khi plugin yêu cầu một group.
7. Upload file rỗng, file vượt giới hạn Moodle, tên Unicode, phần mở rộng giả, upload bị ngắt giữa chừng.
8. Refresh/trở lại trang khi AJAX create/move đang chạy; double-click Create; hai request move cùng card.
9. Mất mạng, Selenium/browser reload, session hết hạn, sesskey cũ.
10. 500 card, 20 cột, nhiều attachment; nhiều user mở/cập nhật đồng thời.

## 4. Smoke test 15 phút sau mỗi cập nhật

1. `docker compose ps`: `moodle`, `db`, `cron` phải Up; DB healthy.
2. Đăng nhập admin, mở một course Kanban; board và cột hiển thị.
3. Tạo một card test, gán cho chính user, refresh; card còn tồn tại.
4. Di chuyển card sang cột khác, refresh; vị trí vẫn đúng.
5. Đặt WIP=1, thử tạo card thứ hai; phải bị chặn.
6. Đăng nhập student group khác, xác nhận không thấy/thao tác card group kia.
7. Mở Dashboard, ButtonKB phải mở và số task đúng.
8. Chạy `docker compose exec moodle php vendor/bin/phpunit --testsuite mod_kanban_testsuite` khi thay đổi PHP/API.
9. Khi thay đổi JS/UI, chạy Behat `--profile=chrome --tags=@mod_kanban`.

## 5. Mẫu bug report

```markdown
## [BUG-YYYY-NNN] Tiêu đề ngắn, mô tả đúng lỗi

- **Môi trường:** Moodle 4.3.12; Kanban 2026091700; PHP 8.2.35; MariaDB 10.11.19; Chrome <version>; Boost.
- **Role/User:** Student group B / Teacher / Admin.
- **Tiền điều kiện:** Nêu course, Kanban, group, card và WIP liên quan.
- **Các bước tái hiện:**
  1. ...
  2. ...
  3. ...
- **Kết quả thực tế:** ...
- **Kết quả mong đợi:** ...
- **Mức độ nghiêm trọng:** Blocker / Critical / Major / Minor / Trivial.
- **Ưu tiên:** Cao / Trung bình / Thấp.
- **Bằng chứng:** Ảnh/video, browser console, request/response đã che dữ liệu nhạy cảm, log Moodle.
- **Tái hiện:** Always / Intermittent / Once.
```

## 6. Gợi ý tự động hóa

### 6.1. PHPUnit — ưu tiên API, quyền và dữ liệu

Nên giữ/tăng test cho tạo card, move/WIP, group authorization, file access, cleanup card, Privacy API và scheduled task. Suite hiện có đã xác nhận **20 tests, 100 assertions PASS**.

```php
public function test_wip_limit_rejects_second_card(): void {
    $this->setUser($this->student);
    // Tạo cột WIP=1 và một card đầu tiên.
    $this->expectException(\moodle_exception::class);
    \mod_kanban\external\card_api::create_card(
        $this->kanbanid, $this->columnid, $this->cmid, 'Card thứ hai', '', 0, ''
    );
}
```

### 6.2. Behat — ưu tiên hành vi UI/AJAX và role/group

Nên giữ test `@mod_kanban @javascript` cho group A/B, move card và WIP. Suite hiện có đã xác nhận **4 scenarios, 22 steps PASS**.

```gherkin
@mod @mod_kanban @javascript
Scenario: Student group B cannot move a group A card
  Given I am logged in as a student in group "Group B"
  And a card exists in "To Do" for group "Group A"
  When I try to move that card
  Then I should be denied access
```

### 6.3. Playwright/Cypress — chỉ dùng bổ sung E2E

Không thay thế PHPUnit/Behat Moodle. Dùng Playwright/Cypress khi cần kiểm tra breakpoint responsive, keyboard navigation, console error hoặc flow upload trên browser thật. Ví dụ tối thiểu Playwright:

```ts
await page.goto('/mod/kanban/view.php?id=<cmid>');
await expect(page.getByRole('heading', {name: 'To Do'})).toBeVisible();
await page.getByRole('button', {name: /Add Card/i}).click();
await page.getByLabel(/Task name/i).fill('E2E task');
await page.getByRole('button', {name: /Create task/i}).click();
await expect(page.getByText('E2E task')).toBeVisible();
```

## 7. Kết quả đã xác nhận và phần còn lại

| Hạng mục | Trạng thái |
|---|---|
| PHPUnit Kanban | PASS — 20 tests, 100 assertions. |
| Behat Kanban Chrome/Selenium | PASS — 4 scenarios, 22 steps. |
| ButtonKB Dashboard | PASS — widget hiển thị số Kanban task thật. |
| Upload/download HTTP, cron deadline thực tế, backup/restore UI, Privacy UI, performance 500 card, responsive/mobile | Cần thực hiện theo test case trên. |
