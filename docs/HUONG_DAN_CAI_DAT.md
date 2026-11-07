# Hướng dẫn cài đặt Moodle Kanban bằng Docker

## 1. Mục đích

Tài liệu hướng dẫn cài Moodle kèm **Kanban** và **Kanban Quick View (ButtonKB)** trên máy mới. Không cần cài XAMPP, PHP hoặc MariaDB riêng; cần Docker Desktop, Git và Internet ở lần build đầu.

## 2. Điều kiện trước khi cài

| Thành phần | Yêu cầu |
|---|---|
| Docker | Docker Desktop/Docker Engine đang chạy. |
| Git | Có lệnh `git`. |
| Cổng | `8080` và `4444` chưa bị ứng dụng khác chiếm. |
| Mạng | Có Internet để tải Moodle/image ở lần đầu. |

Kiểm tra nhanh:

```powershell
docker --version
docker compose version
git --version
```

## 3. Clone repository

```powershell
git clone https://github.com/unknownavailible/docker_do_an_chuyen_nganh.git
cd docker_do_an_chuyen_nganh
```

Repository có Dockerfile tự tải Moodle 4.3.12 và chép plugin vào:

```text
/var/www/html/mod/kanban
/var/www/html/blocks/buttonkb
```

## 4. Tạo `.env`

```powershell
Copy-Item .env.example .env
```

Mở `.env`, thay password mẫu bằng mật khẩu riêng:

```text
MOODLE_HTTP_PORT=8080
MOODLE_WWWROOT=http://localhost:8080
MOODLE_DB_NAME=moodle
MOODLE_DB_USER=moodle
MOODLE_DB_PASSWORD=mat-khau-db-rieng
MARIADB_ROOT_PASSWORD=mat-khau-root-rieng
SELENIUM_PORT=4444
```

Không commit `.env` lên GitHub.

## 5. Build và khởi động

```powershell
docker compose up -d --build
docker compose ps
```

Kết quả cần có:

| Service | Trạng thái |
|---|---|
| `moodle` | Up, cổng `8080:80` |
| `db` | Up (healthy) |
| `cron` | Up |

## 6. Cài Moodle lần đầu

1. Mở `http://localhost:8080`.
2. Hoàn thành Moodle installer và tạo Administrator.
3. Đăng nhập Administrator.
4. Mở **Site administration → Notifications**.
5. Bấm **Continue** để Moodle cài/cập nhật Kanban và ButtonKB.

Sau đó tạo course, bật Edit mode và thêm activity **Kanban**.

## 7. Kiểm tra Kanban, ButtonKB và cron

### Kanban

1. Mở course → bật **Edit mode**.
2. Chọn **Add an activity or resource → Kanban**.
3. Lưu activity và mở board.

### ButtonKB

1. Mở **Dashboard**.
2. Bấm nút lịch nổi **Kanban Quick View** ở mép phải.
3. Kiểm tra số task, số course và deadline.

ButtonKB chỉ xuất hiện ở Dashboard/trang chủ, không đặt trong course.

### Cron

```powershell
docker compose logs cron
```

Log đúng có dòng:

```text
Cron run completed correctly
```

Cron xử lý notification nội bộ Moodle. Email/mobile push không thuộc phạm vi hiện tại.

## 8. Chạy test tự động

### PHPUnit

```powershell
docker compose exec moodle php admin/tool/phpunit/cli/init.php
docker compose exec moodle php vendor/bin/phpunit --testsuite mod_kanban_testsuite
```

Kết quả tham chiếu:

```text
OK (20 tests, 100 assertions)
```

### Behat

```powershell
docker compose --profile test up -d selenium
docker compose exec moodle php admin/tool/behat/cli/init.php
docker compose exec moodle php admin/tool/behat/cli/run.php --profile=chrome --tags=@mod_kanban
```

Kết quả tham chiếu:

```text
4 scenarios (4 passed)
22 steps (22 passed)
```

## 9. Dừng, khởi động lại và cập nhật

### Dừng môi trường

```powershell
docker compose down
```

Lệnh này vẫn giữ database và file upload trong Docker volumes.

### Khởi động lại

```powershell
docker compose up -d
```

### Cập nhật từ GitHub

```powershell
git pull origin main
docker compose up -d --build --force-recreate
```

Sau khi cập nhật plugin, mở **Site administration → Notifications** nếu Moodle yêu cầu upgrade.

> Không dùng `docker compose down -v` nếu chưa muốn xóa vĩnh viễn database, user, course và file upload.

## 10. Xử lý lỗi thường gặp

| Lỗi | Cách xử lý |
|---|---|
| Không mở được `localhost:8080` | Chạy `docker compose ps`; kiểm tra cổng 8080 hoặc đổi `MOODLE_HTTP_PORT`. |
| Database không healthy | Không đổi password `.env` sau khi database volume đã tạo; khôi phục `.env` cũ hoặc reset volume khi được phép. |
| Không thấy plugin | Chạy `docker compose up -d --build`; mở Notifications. |
| Không thấy ButtonKB | Mở Dashboard, purge cache và đăng nhập lại. |
| Behat không kết nối Selenium | Khởi động Selenium và dùng `--profile=chrome`. |
| Không có notification | Kiểm tra deadline, setting notification và `docker compose logs cron`. |

## 11. Những file cần/không cần chia sẻ

Đưa lên GitHub: `Dockerfile`, `docker-compose.yml`, `docker/`, `plugins/`, `.env.example`, README và tài liệu.

Không đưa lên GitHub: `.env`, `config.php`, `moodledata/`, database dump chứa dữ liệu thật, `node_modules/`, `.git/` và log.

## 12. Tài liệu liên quan

- `README-Docker.md`: tổng quan Docker.
- `plugins/mod_kanban/docs/HUONG_DAN_SU_DUNG_KANBAN.md`: hướng dẫn sử dụng.
- `plugins/mod_kanban/docs/SRS.md`: đặc tả yêu cầu.
- `plugins/mod_kanban/docs/KE_HOACH_KIEM_THU_KANBAN.md`: kế hoạch kiểm thử.
