# Lab 05 — Lưu, truy vấn và thống kê đăng ký với postgresql

Mã mẫu cho phần hướng dẫn và mã khởi đầu cho bài tự làm.

## Mở bài

Mở terminal Ubuntu/WSL tại thư mục chứa `lab.sh`. Dùng PHP từ 8.1 và Git. Hướng dẫn chi tiết, sơ đồ, đoạn mã cần sửa, dữ liệu đối chiếu và rubric nằm trong tài liệu của bài lab.

```bash
bash lab.sh check
bash lab.sh run
```

Mở http://127.0.0.1:8105. Giữ terminal máy chủ hoạt động; dùng terminal thứ hai để kiểm thử. Nhấn Ctrl+C để dừng.

Lab05 cần PostgreSQL và pgsql. Thực hiện mục [Chuẩn bị riêng cho Lab 05](../HUONG_DAN.md), chạy `bash lab.sh db-config`, kiểm tra kết nối và tạo schema trước khi chạy Web hoặc test.

## Kiểm tra bài

```bash
bash lab.sh test
bash lab.sh extend
```

`test` kiểm tra phần mẫu; `extend` kiểm tra phần tự làm. Các vị trí TODO là nhiệm vụ cần hoàn thiện; không sửa test để bỏ qua lỗi.

Kết quả mở rộng cần đạt: `TESTS=4 FAILED=0`. Có luồng Form lưu đăng ký thật, chống trùng theo sự kiện–email, tra cứu danh sách và thống kê cả sự kiện chưa có người đăng ký.

## Tệp nộp

Giữ mã nguồn, dữ liệu mẫu và kiểm thử. Ghi lại lệnh chạy trong README. Không đưa `.local`, mật khẩu hoặc dữ liệu cá nhân thật vào kho mã và gói nộp. Nộp đúng commit theo yêu cầu của bài; nếu dùng Actions, đính kèm lần chạy tương ứng.
