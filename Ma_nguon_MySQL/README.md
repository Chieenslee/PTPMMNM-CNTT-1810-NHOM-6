# Lab 05 — Lưu, truy vấn và thống kê đăng ký với MySQL

Đây là lựa chọn MySQL của Lab 05. Dùng PHP >= 8.1, MySQL >= 8.0.16, MySQLi và mysqlnd trên Ubuntu/WSL. Không cần cài PostgreSQL để làm bản này.

Đọc [hướng dẫn MySQL đầy đủ](../HUONG_DAN_MYSQL.md) để cài công cụ, tạo tài khoản/CSDL riêng và học từng bước. Bản PostgreSQL vẫn nằm trong `../Ma_nguon`.

Mở terminal Ubuntu/WSL tại thư mục chứa `lab.sh`, sau khi tạo CSDL và tài khoản theo hướng dẫn:

```bash
bash lab.sh db-config
bash lab.sh check
bash lab.sh sql
```

Client SQL hỏi lại mật khẩu. Trong client:

```sql
source database/schema.sql;
SELECT id, title FROM events ORDER BY id;
exit
```

Trở về Bash:

```bash
php demo-db.php
bash lab.sh run
```

Mở http://127.0.0.1:8105/register.php. Giữ terminal máy chủ mở, Ctrl+C để dừng. Chỉ chạy một phiên bản lab trên cổng 8105 tại một thời điểm. Dùng terminal thứ hai ở cùng thư mục để kiểm thử:

```bash
bash lab.sh test
bash lab.sh extend
```

`test` cần `TESTS=6 FAILED=0`, `TESTS=7 FAILED=0` và `TESTS=9 FAILED=0`; `extend` cần `TESTS=4 FAILED=0` sau khi sinh viên tự hoàn thiện TODO trong `src/RegistrationStats.php`. `NOT_RUN` là chưa kiểm thử được. Không sửa test để bỏ qua lỗi.

Ghi rõ lựa chọn MySQL và phiên bản PHP/MySQL trong bài nộp. Giữ mã nguồn, schema, dữ liệu mẫu và test; không đưa `.local`, mật khẩu hoặc dữ liệu cá nhân thật vào Git/ZIP. Không dùng cấu hình hoặc schema PostgreSQL cho bản này.

Giao diện, form, CSRF, Session/Cookie và khu UI demo giống bản PostgreSQL. Các trang `ui-demo.php`/`ticket-preview.php` dùng dữ liệu hư cấu, không ghi CSDL và không chứng minh đăng ký thật. Mẫu chưa kiểm soát giới hạn chỗ khi nhiều request đăng ký đồng thời.
