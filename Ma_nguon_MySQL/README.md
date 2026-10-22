# Lab 05 — Lưu, truy vấn và thống kê đăng ký với MySQL

**Nhóm 06 — Lớp CNTT 1810**  
**Đề tài 6: Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên**

### Thành viên nhóm:
| STT | Họ và tên | Mã sinh viên | Vai trò |
| :---: | :--- | :---: | :--- |
| 1 | **Lê Trọng Chiến** | 1871020089 | Nhóm trưởng |
| 2 | **Nguyễn Hữu Dũng** | 1871020173 | Thành viên |
| 3 | **Đặng Việt Anh** | 1871020683 | Thành viên |

---

Module lưu trữ, truy vấn và thống kê dữ liệu đăng ký tham gia hoạt động tình nguyện sử dụng cơ sở dữ liệu MySQL (MySQLi prepared statements). Chống trùng đăng ký theo sự kiện – email (Unique constraint), lưu dữ liệu an toàn bằng truy vấn có tham số (`queryParams`), và thống kê tổng số tình nguyện viên đã đăng ký cho từng hoạt động bằng truy vấn `LEFT JOIN` (`registrationStats`).

## Mở bài

Mở terminal Ubuntu/WSL tại thư mục chứa `lab.sh`. Dùng PHP từ 8.1 và Git. Hướng dẫn chi tiết, sơ đồ, đoạn mã cần sửa, dữ liệu đối chiếu và rubric nằm trong tài liệu của bài lab.

```bash
bash lab.sh check
bash lab.sh run
```

Mở http://127.0.0.1:8105. Giữ terminal máy chủ hoạt động; dùng terminal thứ hai để kiểm thử. Nhấn Ctrl+C để dừng.

Lab05 bản MySQL cần MySQL 8.0.16+ và extension `mysqli`. Chạy `bash lab.sh db-config`, kiểm tra kết nối và tạo schema trước khi chạy Web hoặc test.

## Kiểm tra bài

```bash
bash lab.sh test
bash lab.sh extend
```

`test` cần `TESTS=6 FAILED=0`, `TESTS=7 FAILED=0` và `TESTS=9 FAILED=0`; `extend` cần `TESTS=4 FAILED=0` sau khi hoàn thiện `src/RegistrationStats.php`.

## Tệp nộp

Giữ mã nguồn, dữ liệu mẫu và kiểm thử. Ghi lại lệnh chạy trong README. Không đưa `.local`, mật khẩu hoặc dữ liệu cá nhân thật vào kho mã và gói nộp.
