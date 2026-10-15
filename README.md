# Phát triển phần mềm mã nguồn mở (Open Source Software Development)

**Nhóm 6 — Đề tài 6: Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên (Volunteer Management System)**

- **Học phần:** Phát triển phần mềm mã nguồn mở — Trường Đại học Đồng Nai (DNU)
- **Nhóm thực hiện:** Nhóm 6
- **Môi trường yêu cầu:** Linux (Ubuntu / WSL), PHP >= 8.1, Git

---

## 📌 Cấu trúc nhánh (Branch) tương tự FIT-DNU

Mỗi bài Lab được quản lý trên một nhánh riêng biệt độc lập (Standalone branch). Bạn có thể chuyển nhánh trên GitHub hoặc clone trực tiếp từng bài:

| Bài thực hành | Tên nhánh | Nội dung chính | Trạng thái |
|---|---|---|---|
| **Lab 01** | [`lab1`](https://github.com/Chieenslee/hotsummerdrop/tree/lab1) | Vận hành dự án trên Linux và viết Shell Script (`check-env.sh`, `scan-source.sh`) | ✅ 100% PASS (`SHELL_CASES=4 FAILED=0`) |
| **Lab 02** | [`lab2`](https://github.com/Chieenslee/hotsummerdrop/tree/lab2) | Xây dựng module PHP kiểm tra điều kiện đăng ký (`CapacityPolicy`, `RegistrationPolicy`) | ✅ 100% PASS (`TESTS=8 FAILED=0`) |
| **Lab 03** | [`lab3`](https://github.com/Chieenslee/hotsummerdrop/tree/lab3) | Hiển thị sự kiện, bộ lọc đa tiêu chí (AND) & xử lý Form POST an toàn | ✅ 100% PASS (`TESTS=8 FAILED=0`) |

---

## 🚀 Hướng dẫn Clone và Chạy từng bài Lab

### 1. Lab 01
```bash
git clone --single-branch --branch lab1 https://github.com/Chieenslee/hotsummerdrop.git lab1
cd lab1/Ma_nguon
bash lab.sh check    # Kiểm tra môi trường
bash lab.sh test     # Kiểm tra phần mẫu (CHECK_ENV=PASS)
bash lab.sh extend   # Kiểm tra phần mở rộng (SHELL_CASES=4 FAILED=0)
bash lab.sh run      # Chạy ứng dụng tại http://127.0.0.1:8101
```

### 2. Lab 02
```bash
git clone --single-branch --branch lab2 https://github.com/Chieenslee/hotsummerdrop.git lab2
cd lab2/Ma_nguon
bash lab.sh check    # Kiểm tra môi trường
bash lab.sh run      # Chạy thử demo trong terminal
bash lab.sh test     # Kiểm tra phần mẫu (TESTS=5 FAILED=0)
bash lab.sh extend   # Kiểm tra phần mở rộng (TESTS=8 FAILED=0)
```

### 3. Lab 03
```bash
git clone --single-branch --branch lab3 https://github.com/Chieenslee/hotsummerdrop.git lab3
cd lab3/Ma_nguon
bash lab.sh check    # Kiểm tra môi trường
bash lab.sh test     # Kiểm tra phần mẫu (TESTS=7 FAILED=0)
bash lab.sh extend   # Kiểm tra phần mở rộng (TESTS=8 FAILED=0)
bash lab.sh run      # Chạy ứng dụng tại http://127.0.0.1:8103
```
