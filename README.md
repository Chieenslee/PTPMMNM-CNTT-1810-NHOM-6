# Lab05 — Bài thực hành dành cho sinh viên

Nhánh này chỉ chứa một bài lab. Dùng Ubuntu/WSL, PHP từ 8.1 và Git.

**Trước khi chạy:** làm mục Chuẩn bị riêng cho Lab 05 trong [HUONG_DAN.md](HUONG_DAN.md): cài PostgreSQL và PHP pgsql, tạo CSDL riêng, chạy bash lab.sh db-config rồi tạo schema.

## Tải bài

```bash
git clone --single-branch --branch lab5 https://github.com/FIT-DNU/Open-Source-Software-Development.git lab5
cd lab5/Ma_nguon
```

## Chạy bài

```bash
bash lab.sh check
bash lab.sh run
```

Mở http://127.0.0.1:8105. Giữ terminal mở; Ctrl+C để dừng.

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
