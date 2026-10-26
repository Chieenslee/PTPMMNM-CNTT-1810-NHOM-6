# Danh mục Tài nguyên & Thư viện Tái sử dụng (THIRD_PARTY.md)

Bảng dưới đây ghi rõ toàn bộ các thành phần, phông chữ và công cụ mã nguồn mở được sử dụng trong **Xanh Hub (Đề tài 6)** cùng phần mã do nhóm tự phát triển:

## 1. Phần Mã Nguồn Do Nhóm Tự Viết 100%
- **Tầng Thuật toán & Kiểm tra Ràng buộc (`src/Domain/`)**:
  - `XanhHubScheduler.php`: Thuật toán phân công 2 pha (`min_people` → `max_people`) × 3 bước (Lọc khả thi → Tham lam theo độ khan hiếm với hàm phạt dư kỹ năng `-w_k * skillWaste` → Chuỗi đổi chỗ tăng cặp Kuhn tối đa 4 bước).
  - `FifoUncheckedScheduler.php` (`B0`) & `FifoCheckedScheduler.php` (`B1`): Hai thuật toán cơ sở theo thứ tự đăng ký để đối chiếu công bằng theo KT2.
  - `ConstraintGuard.php`: Bộ kiểm tra đồng thời 5 ràng buộc (`H1..H5`), phân biệt chặn cứng (`H1..H3`) và cảnh báo mềm cho phép ghi đè kèm lý do (`H4..H5`).
  - `PlanComparator.php`: Bộ đo 4 nhóm chỉ số KT2 (Đủ theo đầu người, Đủ hợp lệ, Số vi phạm theo từng loại, Độ lệch chuẩn số ca).
  - `ReplacementRanker.php`: Bộ xếp hạng người thay thế có giải thích (`A3`).
- **Tầng Hạ tầng & Giao diện (`src/Infrastructure/`, `database/`, `public/`)**:
  - Lược đồ 22 bảng PostgreSQL, ràng buộc `EXCLUDE USING gist`, trigger khóa dòng `FOR UPDATE`, hàm chuỗi băm kiểm toán `append_audit_log()`.
  - `DatasetGenerator.php`: Bộ sinh dữ liệu mẫu tất định `D1`, `D2`, `D3`, `D4` và bài toán nhỏ `LINH_PHUC`.
  - `HybridAiService.php`: Bộ phân tích câu tiếng Việt theo quy tắc thuần PHP (`A1`) & giải thích phương án (`A4`), kết nối với mô hình trên Google Colab T4.
  - `QrCheckinService.php`, `IcsExporter.php`, `CsvSafeExporter.php`, và Sân khấu Canvas 6 cảnh (`public/assets/app.js`).

## 2. Nền tảng, Phông chữ và Mô hình Nguồn mở Sử dụng lại

| Thành phần | Phiên bản | Giấy phép | Mục đích sử dụng trong Xanh Hub |
| :--- | :--- | :--- | :--- |
| **PHP Runtime & PDO** | `>= 8.2` | PHP License v3.01 | Ngôn ngữ máy chủ và kết nối cơ sở dữ liệu tham số hóa |
| **PostgreSQL + `btree_gist` + `pgcrypto`** | `16 / 18` | PostgreSQL License (OSI) | Hệ quản trị CSDL, ràng buộc loại trừ khoảng thời gian `tstzrange` và hàm băm `SHA-256` |
| **Be Vietnam Pro, Barlow Condensed, JetBrains Mono** | Google Fonts | SIL Open Font License 1.1 | Phông chữ giao diện hỗ trợ đầy đủ tiếng Việt và hiển thị số liệu thẳng cột |
| **Qwen2.5-1.5B / 3B Instruct** (Trên Colab T4 tùy chọn) | `2.5` | Apache License 2.0 | Mô hình ngôn ngữ mở dùng trong Notebook huấn luyện QLoRA trên Google Colab T4 (`notebooks/xanhhub_ai_colab_t4.ipynb`) |
| **HuggingFace Transformers, PEFT, TRL** | Mới nhất | Apache License 2.0 | Thư viện huấn luyện LoRA trong Notebook Google Colab |
