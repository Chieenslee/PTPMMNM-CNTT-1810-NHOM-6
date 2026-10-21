# LAB 05 — LƯU, TRUY VẤN VÀ THỐNG KÊ ĐĂNG KÝ VỚI MYSQL

Sinh viên chọn **một** trong hai hệ quản trị: PostgreSQL hoặc MySQL. Bản MySQL dùng `Ma_nguon_MySQL`; bản PostgreSQL tiếp tục dùng `Ma_nguon` và tài liệu `HUONG_DAN.md`/`Huong_dan.docx` hiện có. Không cần làm cả hai bản. Hai bản có cùng mục tiêu, bài tự làm, rubric 10 điểm và yêu cầu nộp bài.

## I. Mục tiêu và cấu trúc bài

Sau bài này, sinh viên giải thích được khóa chính, khóa ngoại và ràng buộc duy nhất; dùng PHP và truy vấn tham số để lưu/đọc đăng ký; phân biệt đăng ký trùng với lỗi CSDL; kiểm chứng dữ liệu lưu bền vững; tự viết truy vấn thống kê bằng `LEFT JOIN`.

Luồng thực hành: cài công cụ → tạo CSDL riêng → cấu hình → tạo bảng → chạy demo → thử Web → chạy test → hoàn thiện thống kê → nộp bài.

Session giữ bản nháp; nút hoàn tất mới ghi vào MySQL. `CREATED` là đã tạo một hàng; `DUPLICATE` là cặp sự kiện–email đã có. Lỗi kết nối, khóa ngoại hoặc lỗi ghi không được chuyển thành `DUPLICATE`. `data/events.php` đọc sự kiện và đếm đăng ký từ CSDL; thiếu kết nối hoặc schema thì trang trả HTTP 503. Số chỗ hiển thị chỉ phản ánh thời điểm đọc; mẫu chưa xử lý giới hạn suất cuối khi nhiều người đăng ký đồng thời. Khu `ui-demo.php` dùng dữ liệu hư cấu riêng, không ghi CSDL.

| Thành phần trong Ma_nguon_MySQL | Vai trò |
| --- | --- |
| `database/schema.sql` | Hai bảng InnoDB, ràng buộc và ba sự kiện mẫu |
| `src/Database.php` | Kết nối MySQLi, đọc cấu hình riêng, thực thi SQL tham số |
| `src/RegistrationRepository.php` | Lưu đăng ký và đọc danh sách |
| `demo-db.php` | Thử lưu/đọc trong terminal |
| `public/complete.php` | Ghi dữ liệu sau xác nhận bản nháp |
| `src/RegistrationStats.php` | TODO thống kê dành cho sinh viên |
| `tests/integration.php` | Kiểm thử lưu/trùng/danh sách, rollback dữ liệu thử |

## II. Chuẩn bị MySQL trên Ubuntu hoặc WSL

Dùng PHP **8.1 trở lên**, MySQL **8.0.16 trở lên**, extension `mysqli` và `mysqlnd`. Các phiên bản MySQL cũ hơn có thể không thực thi `CHECK`. Bài này dùng MySQL, chưa xác nhận tương thích MariaDB. Chỉ thao tác trên máy và CSDL thực hành riêng; nếu lớp cấp sẵn kết nối thì dùng thông tin giảng viên cấp.

Mở Ubuntu/WSL, vào `Lab05/Ma_nguon_MySQL` (nếu clone nhánh `lab5`, vào `lab5/Ma_nguon_MySQL`). Kiểm tra `pwd` và `ls`: thư mục hiện tại phải chứa `lab.sh`.

```bash
sudo apt update
sudo apt install php-cli git unzip mysql-server mysql-client php-mysql
sudo service mysql start
php -v
mysql --version
php -m | grep -E 'mysqli|mysqlnd'
```

Nếu có nhiều phiên bản PHP, cài extension khớp `php -v`. Ví dụ `php8.1-mysql` dành cho PHP 8.1. `mysql --version` chỉ xác nhận client; kiểm tra phiên bản server bằng SQL bên dưới.

### Tạo tài khoản và CSDL riêng

```bash
sudo mysql
```

Trong MySQL, kiểm tra tên trước khi tạo:

```sql
SELECT VERSION();
SELECT User, Host FROM mysql.user WHERE User = 'communitypass_mysql_v5';
SHOW DATABASES LIKE 'communitypass_mysql_v5';
exit
```

Chỉ khi hai tên chưa tồn tại, mở lại client quản trị với lịch sử lệnh tắt:

```bash
sudo env MYSQL_HISTFILE=/dev/null mysql
```

```sql
CREATE DATABASE communitypass_mysql_v5
  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER 'communitypass_mysql_v5'@'127.0.0.1'
  IDENTIFIED BY 'THAY_BANG_MAT_KHAU_RIENG';
GRANT SELECT, INSERT, UPDATE, CREATE, REFERENCES
  ON communitypass_mysql_v5.*
  TO 'communitypass_mysql_v5'@'127.0.0.1';
exit
```

Thay chuỗi minh họa bằng mật khẩu riêng ngay trong client quản trị cục bộ; không chép mật khẩu thật vào báo cáo, mã nguồn hoặc ảnh nộp. Không dùng `root` trong ứng dụng. Tài khoản chỉ có quyền trong CSDL lab; `UPDATE` phục vụ thao tác nạp dữ liệu mẫu lặp lại. Nếu tên đã tồn tại, hỏi giảng viên hoặc chọn tên riêng rồi dùng đúng tên ở bước cấu hình; không xóa dữ liệu cũ. Host `127.0.0.1` dùng TCP và cần khớp tài khoản được cấp.

### Cấu hình và kiểm tra kết nối PHP

Tại `Ma_nguon_MySQL`:

```bash
bash lab.sh db-config
bash lab.sh check
bash lab.sh sql
```

Nhập host `127.0.0.1`, cổng `3306`, tên CSDL và tài khoản vừa tạo. Mật khẩu được nhập ẩn trong terminal; chỉ lưu `.local/db.json` sau khi kết nối thành công. `check` xác nhận công cụ, phiên bản server và kết nối; chưa xác nhận bảng đã có hoặc bài đã hoàn thành.

Lệnh `sql` mở MySQL client và hỏi lại mật khẩu. Không truyền mật khẩu qua đối số dòng lệnh. Trong client:

```sql
SELECT DATABASE(), CURRENT_USER(), VERSION();
exit
```

Không đưa `.local` vào Git, ZIP bài nộp hoặc gửi cho người khác. Cấu hình MySQL khác cấu hình PostgreSQL; không sao chép `db.json` giữa hai bản. Nếu đổi cấu hình, dừng rồi khởi động lại máy chủ PHP.

## III. Thực hành có hướng dẫn

### Bước 1. Đọc và tạo bảng

Mở `database/schema.sql` của **Ma_nguon_MySQL**. `events.id` là khóa chính; `registrations.event_id` là khóa ngoại; `UNIQUE(event_id,email)` chống trùng theo cặp. Bảng dùng InnoDB để có khóa ngoại và rollback. `utf8mb4` lưu tiếng Việt; collation nhị phân tránh việc hai email khác dấu bị coi như nhau. Email vẫn được chuẩn hóa chữ thường trong PHP và có `CHECK` ở CSDL.

```bash
bash lab.sh sql
```

Trong MySQL client:

```sql
source database/schema.sql;
SHOW TABLES;
SHOW CREATE TABLE registrations;
SELECT id, title FROM events ORDER BY id;
SELECT COUNT(*) FROM registrations;
exit
```

Phải có hai bảng, ba sự kiện và các ràng buộc. CSDL mới có 0 đăng ký; nếu đã chạy demo thì có thể khác. Chạy lại schema giữ dữ liệu hiện có, không tự cập nhật cấu trúc bảng cũ. Không dùng schema PostgreSQL (`bigserial`, `timestamptz`, `ON CONFLICT`) cho MySQL.

### Bước 2. Đọc kết nối và truy vấn tham số

Mở `src/Database.php`. `mysqlConfig()` đọc cấu hình; `connectMysql()` mở TCP, đặt `utf8mb4`, múi giờ UTC và chế độ SQL nghiêm ngặt; `db()` trả kết nối `mysqli`. Khi lỗi, chương trình trả thông báo chung, không in mật khẩu hoặc câu lỗi chứa dữ liệu cá nhân.

Hàm `queryParams()` dùng hai bước quan trọng:

```php
$statement = $connection->prepare($sql);
$statement->execute($params);
```

Đây là đoạn trích để đọc, không thay toàn bộ tệp. Với PHP 8.1, `execute($params)` nhận mảng giá trị; `?` tương ứng lần lượt với phần tử trong mảng. Không ghép email, tên hoặc ID vào chuỗi SQL. Truy vấn đọc dùng `get_result()->fetch_all(MYSQLI_ASSOC)`; cần `mysqlnd`.

### Bước 3. Đọc lớp lưu và truy vấn đăng ký

Mở `src/RegistrationRepository.php`. Giữ phần kiểm tra tên/email và chuẩn hóa email. Phương thức `save()` thực hiện câu lệnh:

```sql
INSERT INTO registrations (event_id,name,email) VALUES (?,?,?)
```

Ba giá trị truyền riêng là `[$eventId, $name, $email]`. Chèn thành công trả `CREATED`. Hàm hỗ trợ chuyển lỗi MySQL thành lỗi chung nhưng giữ mã số; `save()` chỉ chuyển mã **1062** thành `DUPLICATE`. Trong schema đã cấp, INSERT này không truyền khóa chính tự tăng nên lỗi trùng đến từ cặp sự kiện–email. Lỗi khác được ném tiếp để giao diện giữ nháp.

Không dùng `INSERT IGNORE` vì có thể che các lỗi dữ liệu khác. Không dùng `SELECT` trước `INSERT` làm biện pháp chống trùng duy nhất: hai request có thể cùng vượt qua SELECT. Ràng buộc UNIQUE ở CSDL mới bảo vệ được cặp khóa.

`listForEvent()` dùng `WHERE event_id=? ORDER BY id`; kết quả là danh sách ổn định, sự kiện không có đăng ký trả `[]`.

### Bước 4. Thử demo và kiểm chứng SQL

```bash
php demo-db.php
php demo-db.php
bash lab.sh sql
```

Với CSDL mới: lần đầu `CREATED`, lần hai `DUPLICATE`; tên `O'Neil Demo` phải giữ đúng dấu nháy. Nếu demo đã có, cả hai lần có thể là `DUPLICATE`.

```sql
SELECT id,event_id,name,email FROM registrations ORDER BY id;
SELECT event_id,email,COUNT(*) AS total
FROM registrations GROUP BY event_id,email;
exit
```

Cặp sự kiện 1 và `demo@example.test` phải chỉ có một hàng.

### Bước 5. Nối và kiểm tra luồng Web

Đọc `public/complete.php`: POST → kiểm tra CSRF và hạn bản nháp → gọi repository → chỉ xóa nháp khi lưu có kết quả → chuyển hướng. Khi lỗi CSDL, trả HTTP 503 và giữ nháp để thử lại. Giữ nguyên route, tên trường, Session/Cookie, token, xử lý POST và redirect của bản mẫu.

```bash
bash lab.sh run
```

Mở `http://127.0.0.1:8105/register.php`. Chọn sự kiện, nhập tên/email giả lập khác demo, xác nhận rồi hoàn tất. Lần đầu phải `CREATED`; lặp cùng email và sự kiện phải `DUPLICATE`. Đổi sự kiện hoặc email có thể tạo hàng mới. Dùng SQL kiểm tra số hàng.

Giữ terminal máy chủ mở; dùng terminal Ubuntu/WSL thứ hai ở cùng thư mục để chạy SQL/test. Dừng bằng Ctrl+C, chạy lại rồi SELECT để chứng minh bản ghi vẫn tồn tại. Hai phiên bản dùng chung cổng 8105: dừng bản đang chạy trước khi đổi hệ quản trị. Chỉ chạy ứng dụng cục bộ với dữ liệu giả lập.

### Bước 6. Chạy kiểm thử

```bash
bash lab.sh test
```

Kết quả yêu cầu: `TESTS=6 FAILED=0` cho Session, `TESTS=7 FAILED=0` cho tích hợp CSDL và `TESTS=9 FAILED=0` cho ràng buộc MySQL. Bộ tích hợp tạo sự kiện riêng, thử tạo mới, email trùng sau chuẩn hóa, dấu nháy, dữ liệu chứa HTML và danh sách rỗng. Bộ ràng buộc kiểm tra khóa ngoại, CHECK, lỗi không bị coi là trùng và tên tiếng Việt chứa chuỗi SQL vẫn được lưu nguyên văn. Toàn bộ dữ liệu thử nằm trong transaction rồi rollback. AUTO_INCREMENT có thể có khoảng trống sau rollback; đây là bình thường.

`NOT_RUN` hoặc mã thoát 2 nghĩa là chưa thực hiện được kiểm thử, không phải PASS. Không sửa test để bỏ qua lỗi. Lệnh `check` không thay cho `test`.

## IV. Bài tự làm và cách chấm

Hoàn thiện **chỉ TODO** trong `src/RegistrationStats.php`, giữ kiểu kết nối `mysqli`. Hàm nhận ID sự kiện và trả:

```php
['event_id' => /* số nguyên */, 'title' => /* chuỗi */, 'total' => /* số nguyên */]
```

ID không tồn tại trả `[]`; sự kiện có thật nhưng chưa có đăng ký phải có `total=0`. Dùng `LEFT JOIN`, đếm khóa đăng ký thay vì `COUNT(*)`, lọc bằng `?`, GROUP BY các cột không tổng hợp và ép các giá trị số về `int`. Không ghép ID vào SQL và không biến lỗi truy vấn thành số 0. Tự viết câu SQL, không thay bộ kiểm thử.

```bash
bash lab.sh extend
bash lab.sh test
```

Mục tiêu của `extend`: `TESTS=4 FAILED=0`. Bản khởi đầu còn TODO nên các ca mở rộng chưa đạt là bình thường. Phần nâng cao tự chọn: phân tích giới hạn chỗ khi nhiều request đến đồng thời.

Áp dụng nguyên rubric 10 điểm ở mục VIII của [hướng dẫn PostgreSQL](HUONG_DAN.md#viii-rubric-lab--10-điểm): mẫu có hướng dẫn 2 điểm, truy vấn thống kê 1,5, kết quả trả về 1,5, nhất quán dữ liệu 1, kiểm thử/xử lý lỗi 2, mã nguồn/bàn giao 1, giải thích cá nhân 1. Không trừ điểm vì chọn MySQL và không yêu cầu chạy thêm PostgreSQL.

## V. Lỗi thường gặp

| Hiện tượng | Cách xử lý |
| --- | --- |
| Thiếu mysql/mysqli/mysqlnd | Kiểm tra client và `php -m`; cài extension đúng phiên bản PHP |
| Access denied hoặc lỗi kết nối | Kiểm tra dịch vụ, host TCP, cổng, tài khoản/host và quyền trên CSDL lab; chạy lại db-config |
| `CAN_MYSQL_8_0_16_TRO_LEN` | Kiểm tra `SELECT VERSION()`; dùng MySQL đủ phiên bản, không thay bằng MariaDB cho bài này |
| Không có bảng | Vào đúng thư mục MySQL, kiểm tra `SELECT DATABASE()` rồi chạy `source database/schema.sql;` |
| Lỗi cú pháp `$1`/`ON CONFLICT` | Đang dùng mã hoặc schema PostgreSQL; chuyển về Ma_nguon_MySQL |
| `DUPLICATE` ngay lần đầu | Truy vấn kiểm tra dữ liệu demo đã tồn tại; không xóa CSDL để ép kết quả |
| Lỗi khóa ngoại hoặc CHECK | Kiểm tra sự kiện có thật và dữ liệu đầu vào; không đổi lỗi thành DUPLICATE |
| Port 8105 đang dùng | Ctrl+C ở máy chủ PHP cũ trước khi chạy bản khác |
| `NOT_RUN` | Sửa môi trường và chạy lại; chưa được kết luận đạt |

## VI. Bảng kiểm và nộp bài

- [ ] Ghi rõ lựa chọn MySQL, phiên bản PHP và MySQL trong README bài nộp.
- [ ] Tạo CSDL lab riêng, nạp đúng schema, thấy ba sự kiện.
- [ ] Lưu mới CREATED, trùng DUPLICATE, tên chứa dấu nháy giữ nguyên.
- [ ] Có SELECT chứng minh số hàng và dữ liệu còn sau khi khởi động lại PHP.
- [ ] Thống kê giữ sự kiện chưa có đăng ký; ID thiếu trả mảng rỗng.
- [ ] Chạy check/test/extend, lưu kết quả thực tế và giải thích ca chưa đạt.
- [ ] Nộp thư mục Ma_nguon_MySQL, commit cụ thể và thông tin nhóm theo yêu cầu giảng viên; loại bỏ .local và dữ liệu cá nhân thật.

## Tài liệu tham khảo

- [PHP MySQLi prepared statements](https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php)
- [PHP mysqli_stmt::execute và mảng tham số từ PHP 8.1](https://www.php.net/manual/en/mysqli-stmt.execute.php)
- [MySQL CHECK constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html)
- [MySQL foreign keys](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html)
