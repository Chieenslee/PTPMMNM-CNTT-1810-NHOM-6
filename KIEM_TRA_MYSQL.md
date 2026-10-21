# Kết quả kiểm tra bản MySQL Lab 05

Ngày kiểm tra: 21/10/2026. Phạm vi: kiểm thử cục bộ trước khi xuất bản bản `Ma_nguon_MySQL` lên nhánh `lab5`.

Môi trường: Ubuntu/WSL, PHP 8.1.2, MySQL 8.0.46, MySQLi/mysqlnd. MySQL và extension được chạy từ bộ gói giải nén tạm; không thay cấu hình PHP hoặc dịch vụ CSDL đang cài. Dữ liệu và cấu hình kiểm thử nằm trong bản sao tạm, không nằm trong mã bàn giao.

| Kiểm tra thực sự đã chạy | Kết quả |
| --- | --- |
| Cú pháp toàn bộ PHP bản MySQL | PASS |
| `bash -n lab.sh` | PASS |
| Schema với đúng quyền tài khoản trong hướng dẫn; chạy schema hai lần | PASS, vẫn có ba sự kiện mẫu |
| `db-config` và `sql` trong terminal tương tác | PASS, nhập mật khẩu ẩn; cấu hình đọc lại đúng |
| `bash lab.sh check` | PASS trên môi trường thử |
| `bash lab.sh test` | PASS: lần lượt 6/6, 7/7 và 9/9 |
| Dữ liệu tích hợp và ràng buộc được rollback | PASS, không còn hàng thử |
| Demo hai lần | PASS, CREATED rồi DUPLICATE |
| Luồng HTTP form → xác nhận → hoàn tất, có Session và CSRF | PASS, CREATED rồi DUPLICATE; SQL chỉ có một hàng cho cặp khóa |
| Dừng PHP rồi kiểm tra hàng đã lưu bằng SQL | PASS |
| Bộ mở rộng trên bản sinh viên | Chưa đạt đúng dự kiến: 4 ca, 3 ca lỗi vì TODO còn nguyên |
| Hợp đồng thống kê trên bản sao tạm có triển khai để đối chiếu | PASS 4/4; không đưa đáp án vào mã sinh viên |
| Hash toàn bộ mã PostgreSQL trước/sau | PASS, 51 tệp không đổi |

Chưa kiểm tra: giao diện trực quan trong trình duyệt, mọi phiên bản MySQL/PHP khác, MariaDB, cài mới toàn bộ Ubuntu và PostgreSQL tích hợp trong lần thay đổi này. Bản Word PostgreSQL hiện có được giữ nguyên; hướng dẫn MySQL mới nằm trong `HUONG_DAN_MYSQL.md`. Đây là bằng chứng kiểm thử cục bộ, không thay cho kết quả CI hoặc kiểm tra trên máy sinh viên.
