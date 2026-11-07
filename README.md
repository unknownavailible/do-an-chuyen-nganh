# Moodle Kanban

Activity module Kanban cho Moodle, hỗ trợ quản lý công việc học tập theo cột/card trong course. Dự án đi kèm block **Kanban Quick View (ButtonKB)** để xem nhanh task và deadline trên Dashboard.

## Chức năng chính

- Tạo activity Kanban với cột **To Do → In Progress → Done**.
- Tạo, sửa, xóa, phân công và kéo-thả card.
- Sắp xếp cột/card; kiểm tra WIP limit ở server.
- Phân quyền theo Moodle capability và group.
- Deadline, Assignment liên kết, comment, history, file đính kèm, event Moodle.
- Backup/restore, Privacy API, tiếng Việt/Anh.
- ButtonKB hiển thị số Kanban task, số course và deadline gần nhất.

## Môi trường đã xác nhận

| Thành phần | Phiên bản/môi trường |
|---|---|
| Moodle | 4.3.12 |
| Plugin | `mod_kanban` v0.1.0 (`2026091700`) |
| PHP | 8.2.35 |
| Database | MariaDB 10.11.19 |
| UI test | Boost, Chrome + Selenium |
| Triển khai | Docker Compose |

Kanban yêu cầu Moodle 4.1 trở lên. Database nghiệm thu là MariaDB; PostgreSQL ngoài phạm vi đồ án.

## Cài nhanh bằng Docker

```powershell
git clone https://github.com/unknownavailible/docker_do_an_chuyen_nganh.git
cd docker_do_an_chuyen_nganh
Copy-Item .env.example .env
docker compose up -d --build
```

Mở `http://localhost:8080`, hoàn thành Moodle installer, rồi vào:

```text
Site administration → Notifications
```

để Moodle cài/cập nhật Kanban và ButtonKB.

```powershell
docker compose ps
docker compose logs cron
```

Cron hợp lệ có dòng `Cron run completed correctly`.

## Cài plugin vào Moodle có sẵn

1. Sao chép plugin này vào `moodle/mod/kanban`.
2. Sao chép ButtonKB vào `moodle/blocks/buttonkb`.
3. Đăng nhập Administrator → **Site administration → Notifications**.
4. Chạy upgrade nếu Moodle yêu cầu, sau đó purge cache.

## Sử dụng nhanh

### Giảng viên

1. Mở course → bật **Edit mode**.
2. Chọn **Add an activity or resource → Kanban**.
3. Mở board, thêm cột hoặc bấm **Add Card**.
4. Chọn assignee, deadline, file/URL nếu cần.
5. Di chuyển card giữa cột; hệ thống kiểm tra WIP/group ở server.

### Sinh viên

Sinh viên xem/thao tác card thuộc group được phép khi role có `mod/kanban:managecards`.

### ButtonKB

Mở **Dashboard**, bấm nút lịch nổi **Kanban Quick View** ở mép phải. Block chỉ dùng tại Dashboard/trang chủ, không đặt trong course.

## Kiểm thử

```powershell
docker compose exec moodle php admin/tool/phpunit/cli/init.php
docker compose exec moodle php vendor/bin/phpunit --testsuite mod_kanban_testsuite
```

Kết quả đã xác nhận:

```text
OK (20 tests, 100 assertions)
```

```powershell
docker compose --profile test up -d selenium
docker compose exec moodle php admin/tool/behat/cli/init.php
docker compose exec moodle php admin/tool/behat/cli/run.php --profile=chrome --tags=@mod_kanban
```

Kết quả đã xác nhận:

```text
4 scenarios (4 passed)
22 steps (22 passed)
```

## Cấu trúc rút gọn

```text
mod/kanban/
├─ classes/       # API, events, privacy, scheduled task
├─ db/            # XMLDB, services, capabilities, upgrade
├─ backup/        # Backup/restore
├─ tests/         # PHPUnit, Behat, generators
├─ amd/           # JavaScript source/build
├─ templates/     # Mustache
├─ docs/          # Tài liệu chi tiết
├─ lib.php
├─ mod_form.php
├─ view.php
└─ version.php
```

## Tài liệu chi tiết

| Tài liệu | Nội dung |
|---|---|
| [SRS.md](docs/SRS.md) | Đặc tả yêu cầu, Use Case, ERD. |
| [KE_HOACH_KIEM_THU_KANBAN.md](docs/KE_HOACH_KIEM_THU_KANBAN.md) | Test case, smoke test, bug report. |
| [BAO_CAO_KY_THUAT_KANBAN.md](docs/BAO_CAO_KY_THUAT_KANBAN.md) | CSDL, bảo mật, UI/UX, Docker, hiệu năng. |
| [HUONG_DAN_SU_DUNG_KANBAN.md](docs/HUONG_DAN_SU_DUNG_KANBAN.md) | Hướng dẫn Admin, Teacher, Student. |
| [HUONG_DAN_CAI_DAT.md](docs/HUONG_DAN_CAI_DAT.md) | Cài Moodle từ máy mới bằng Docker. |

## Lưu ý phát triển

- Dùng Moodle `$DB`, XMLDB, File API, Groups API và capability API.
- Không hard-code tiền tố database `mdl_`.
- Luôn validate ở server; không tin dữ liệu chỉ vì UI đã kiểm tra.
- Khi đổi schema/capability, tăng `$plugin->version` và chạy Moodle upgrade.
- Không commit `.env`, `config.php`, `moodledata`, database dump thật hoặc mật khẩu.

## License

GPL v3 hoặc mới hơn, theo chuẩn plugin Moodle.
