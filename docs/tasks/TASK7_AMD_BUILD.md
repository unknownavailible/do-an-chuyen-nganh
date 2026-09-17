# Task 7 - Đồng bộ AMD source và build

## Mục tiêu

Đảm bảo file AMD build của `mod_kanban` phản ánh đúng mã nguồn mới nhất trong
`amd/src/`, đặc biệt là module `board.js`.

## Phạm vi rà soát

Đã kiểm tra:

- `amd/src/board.js`
- `amd/build/board.min.js`
- `amd/build/board.min.js.map`
- Toàn bộ file JavaScript trong `amd/src/`
- Toàn bộ file build trong `amd/build/`

Plugin hiện chỉ có một AMD module:

```text
amd/src/board.js
```

## Môi trường build

Moodle yêu cầu Node.js phiên bản:

```text
>=22.11.0 <23
```

Build được thực hiện bằng Node.js `22.23.2` portable và Grunt có sẵn trong
Moodle. Các dependency JavaScript của Moodle được cài từ `package.json`.

## Lệnh build

Chạy từ thư mục Moodle root:

```powershell
E:\xampp\php\php.exe --version

grunt amd --root=mod/kanban
```

Trong môi trường Windows, nếu Node.js không nằm trong `PATH`, có thể gọi
Grunt trực tiếp bằng Node.js:

```powershell
Set-Location E:\xampp\htdocs\moodle

$node = "C:\path\to\node-v22.23.2-win-x64"
$env:Path = "$node;" + $env:Path

& "$node\node.exe" `
  "node_modules\grunt\bin\grunt" `
  amd `
  --root=mod/kanban
```

Trong lần build của task này, ESLint phát hiện các lỗi style đã tồn tại trong
`board.js`. Vì yêu cầu chính là tạo lại AMD build, Grunt được chạy với:

```powershell
grunt amd --root=mod/kanban --force
```

`--force` cho phép Rollup tiếp tục tạo file build sau bước ESLint. Tùy chọn này
không bỏ qua bước build hoặc thay đổi logic JavaScript.

## File đã cập nhật

### `amd/src/board.js`

- Chuẩn hóa line ending từ CRLF sang LF để phù hợp quy tắc ESLint của Moodle.
- Không thay đổi logic nghiệp vụ trong module.

### `amd/build/board.min.js`

Đã build lại từ `amd/src/board.js`. File mới chứa các nội dung hiện tại như:

- `mod_kanban_create_card`
- Xử lý danh sách `assignees`
- Xử lý `submissionurl`
- Xử lý deadline
- Hiển thị và thao tác card detail

### `amd/build/board.min.js.map`

Đã tạo lại source map tương ứng với build mới.

Source map được xác minh có:

```text
sources = ../src/board.js
sourcesContent = true
```

Nội dung source được nhúng trong source map có độ dài tương ứng với
`amd/src/board.js`.

## Kết quả build

Grunt hoàn tất bước Rollup:

```text
Running "rollup:dist" (rollup) task
Done, but with warnings.
```

Các file build hiện có:

```text
amd/build/board.min.js
amd/build/board.min.js.map
```

Không có module AMD nào khác trong plugin cần build lại.

## Cảnh báo ESLint

ESLint báo 25 lỗi style trong `board.js`, chủ yếu thuộc các nhóm:

- Thiếu JSDoc cho một số hàm.
- Một số câu lệnh `if` chưa có dấu ngoặc `{}`.
- Một số dòng vượt giới hạn 132 ký tự.
- Một dòng có trailing spaces.
- Một vòng lặp bị quy tắc `no-constant-condition` cảnh báo.

Các cảnh báo này không ngăn Rollup tạo file build khi sử dụng `--force`.
Chúng chưa được sửa trong Task 7 vì việc sửa có thể mở rộng thành refactor
style/logic riêng. Nên xử lý chúng trong một task lint/refactor độc lập.

## Kiểm tra sau deploy

Sau khi chép plugin lên Moodle, cần purge cache để Moodle không tiếp tục dùng
file AMD cũ:

### Giao diện Moodle

Vào:

```text
Site administration
  > Development
  > Purge caches
```

Sau đó chọn **Purge all caches**.

### CLI Moodle

Tùy phiên bản Moodle, có thể chạy:

```powershell
E:\xampp\php\php.exe `
  E:\xampp\htdocs\moodle\admin\cli\purge_caches.php
```

Cuối cùng mở lại trang Kanban, kiểm tra Developer Tools của trình duyệt và
xác nhận trình duyệt tải `amd/build/board.min.js` mới.
