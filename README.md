# PHÁT TRIỂN PHẦN MỀM MÃ NGUỒN MỞ

## NHÓM 06 - CNTT 1810

**Trường:** Đại học Đại Nam

**Môn học:** Phát triển phần mềm mã nguồn mở

**Đề tài:** Đề tài 6: Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên

---

## 1. Thành viên nhóm

| STT | Họ và tên | Mã sinh viên | Vai trò |
| :---: | :--- | :---: | :--- |
| 1 | **Lê Trọng Chiến** | 1871020089 | Nhóm trưởng |
| 2 | **Nguyễn Hữu Dũng** | 1871020173 | Thành viên |
| 3 | **Đặng Việt Anh** | 1871020683 | Thành viên |

---

## 2. Danh sách bài thực hành

| Bài Lab | Nội dung | Trạng thái | Nhánh Git |
| :---: | :--- | :---: | :--- |
| **Lab01** | Linux và Shell Script (`check-env.sh`, `scan-source.sh`) | ✅ Hoàn thành | [lab1](https://github.com/Chieenslee/Nhom-6-Open-Source-Software-Development/tree/lab1) |
| **Lab02** | Xây dựng Module PHP kiểm tra điều kiện đăng ký | ✅ Hoàn thành | [lab2](https://github.com/Chieenslee/Nhom-6-Open-Source-Software-Development/tree/lab2) |
| **Lab03** | Hiển thị sự kiện, lọc đa tiêu chí và xử lý Form POST | ✅ Hoàn thành | [lab3](https://github.com/Chieenslee/Nhom-6-Open-Source-Software-Development/tree/lab3) |
| **Lab04** | Quản lý Session và Cookie | 🔄 Đang chuẩn bị | lab4 |
| **Lab05** | Cơ sở dữ liệu PostgreSQL | 🔄 Đang chuẩn bị | lab5 |

---

## 3. Công nghệ sử dụng

- **Hệ điều hành:** Linux (Ubuntu / WSL 2)
- **Ngôn ngữ:** PHP 8.1+ (PHP 8.5 CLI)
- **Công cụ:** Git, GitHub, Visual Studio Code
- **Môi trường & Script:** Bash Shell Scripting
- **Giao diện Web:** HTML5, CSS3 (Vanilla CSS, Material 3 Tokens)
- **Cơ sở dữ liệu:** PostgreSQL (Lab 05)

---

## 4. Hướng dẫn xem và chạy bài Lab

Mỗi bài Lab được lưu trữ trên một nhánh Git độc lập theo chuẩn quy cách của bộ môn:

- `main`: Nhánh chính, chứa thông tin thành viên nhóm và tổng quan đề tài.
- `lab1`: Bài thực hành 01 — Vận hành dự án Linux & Shell Script (`CHECK_ENV=PASS`, `SHELL_CASES=4 FAILED=0`).
- `lab2`: Bài thực hành 02 — Lập trình PHP cơ bản & Module nghiệp vụ (`TESTS=5 FAILED=0`, `TESTS=8 FAILED=0`).
- `lab3`: Bài thực hành 03 — Hiển thị sự kiện, bộ lọc đa tiêu chí & biểu mẫu (`TESTS=7 FAILED=0`, `TESTS=8 FAILED=0`).
- `lab4`: Bài thực hành 04 — Session & Cookie.
- `lab5`: Bài thực hành 05 — PostgreSQL.

### Lệnh Clone và Chạy từng bài Lab:

```bash
# Clone Lab 01
git clone --single-branch --branch lab1 https://github.com/Chieenslee/Nhom-6-Open-Source-Software-Development.git lab1
cd lab1/Ma_nguon
bash lab.sh check && bash lab.sh test && bash lab.sh extend

# Clone Lab 02
git clone --single-branch --branch lab2 https://github.com/Chieenslee/Nhom-6-Open-Source-Software-Development.git lab2
cd lab2/Ma_nguon
bash lab.sh check && bash lab.sh test && bash lab.sh extend

# Clone Lab 03
git clone --single-branch --branch lab3 https://github.com/Chieenslee/Nhom-6-Open-Source-Software-Development.git lab3
cd lab3/Ma_nguon
bash lab.sh check && bash lab.sh test && bash lab.sh extend
```

---

## 5. Repository bài tập gốc

[https://github.com/FIT-DNU/Open-Source-Software-Development](https://github.com/FIT-DNU/Open-Source-Software-Development)
