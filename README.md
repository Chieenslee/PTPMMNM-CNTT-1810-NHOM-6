# Lab05 — Bài thực hành dành cho sinh viên

Nhánh này chỉ chứa một bài lab. Dùng Ubuntu/WSL, PHP từ 8.1 và Git.

**Trước khi chạy:** làm mục Chuẩn bị riêng cho Lab 05 trong [HUONG_DAN.md](HUONG_DAN.md): cài PostgreSQL và PHP pgsql, tạo CSDL riêng, chạy bash lab.sh db-config rồi tạo schema.

## Chuẩn bị trên Ubuntu/WSL

```bash
sudo apt update
sudo apt install php-cli git unzip
php -v
git --version
```

PHP cần từ 8.1 trở lên. Các lệnh bên dưới chạy trong Ubuntu/WSL. Khi tải nhiều bài, trở về thư mục cha chứa các thư mục lab trước mỗi lệnh clone; không clone bài tiếp theo bên trong `Ma_nguon` của bài trước.

## Tải bài

```bash
git clone --single-branch --branch lab5 https://github.com/FIT-DNU/Open-Source-Software-Development.git lab5
cd lab5/Ma_nguon
```

## Cấu hình PostgreSQL trước lần chạy đầu

Tại `lab5/Ma_nguon`, cài và khởi động PostgreSQL:

```bash
sudo apt install postgresql postgresql-client php-pgsql
sudo service postgresql start
```

Làm mục **Chuẩn bị riêng cho Lab 05** trong [HUONG_DAN.md](HUONG_DAN.md) để tạo tài khoản và CSDL lab riêng, hoặc dùng thông tin giảng viên cấp. Không xóa CSDL đã tồn tại. Sau đó:

```bash
bash lab.sh db-config
bash lab.sh check
bash lab.sh sql
```

Nhập thông tin kết nối theo lời nhắc. Trong cửa sổ PostgreSQL vừa mở:

```sql
\i database/schema.sql
SELECT id, title FROM events ORDER BY id;
\q
```

Cần có 3 sự kiện mẫu. Trở về Bash rồi làm mục Chạy bài bên dưới. Nếu khởi động lại WSL và PostgreSQL chưa chạy, dùng `sudo service postgresql start`.

## Chạy bài

```bash
bash lab.sh check
bash lab.sh run
```

Mở [danh sách sự kiện](http://127.0.0.1:8105/) hoặc [form đăng ký](http://127.0.0.1:8105/register.php). Giữ terminal mở; Ctrl+C để dừng.

## Kiểm tra

Mở terminal thứ hai tại Ma_nguon, hoặc dừng máy chủ trước:

```bash
bash lab.sh test
bash lab.sh extend
```

test kiểm tra phần mẫu; extend kiểm tra phần tự làm. Sinh viên cần hoàn thiện TODO; extend có thể chưa đạt ở bản khởi đầu.

[Hướng dẫn đầy đủ](HUONG_DAN.md) · [Hướng dẫn mã nguồn](Ma_nguon/README.md)

Không đưa .local, mật khẩu hoặc dữ liệu cá nhân thật vào bài nộp.

## Các bài khác

[Lab01](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab1) · [Lab02](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab2) · [Lab03](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab3) · [Lab04](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab4) · [Lab05](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab5)

Kết quả yêu cầu của phần mẫu: `TESTS=6 FAILED=0 và TESTS=7 FAILED=0`. `check` chỉ kiểm tra môi trường. `extend` ở bản khởi đầu còn ca chưa đạt vì TODO; không sửa test để bỏ qua lỗi.
