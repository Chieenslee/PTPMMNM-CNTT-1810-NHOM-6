# Xanh Hub – Hệ thống tổ chức hoạt động và phân công tình nguyện viên (Đề tài 6)

> **Học phần:** Phát triển phần mềm mã nguồn mở  
> **Đề tài 6:** Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên  
> **Khẩu hiệu sản phẩm:** *Đúng người. Đúng ca. Đúng giờ.*  
> **Giấy phép:** MIT License (`LICENSE`)

---

## 1. Giới thiệu & Thành viên Nhóm (3 Vai trò)

**Xanh Hub** là hệ thống web mã nguồn mở giúp các cơ sở Đoàn – Hội tổ chức hoạt động tình nguyện, tiếp nhận đăng ký, tự động đề xuất phân công xét đồng thời kỹ năng, thời gian và số người mỗi ca, hỗ trợ kéo thả sửa phân công có kiểm tra ràng buộc, thay người khi báo vắng, điểm danh bằng mã QR xoay vòng 30 giây và cấp chứng nhận giờ tình nguyện.

| Vai trò | Phụ trách mô-đun chính | Minh chứng vấn đáp |
| :--- | :--- | :--- |
| **Vai A – Lõi Thuật toán & Kiểm thử** | `src/Domain/`: `XanhHubScheduler` (2 pha × 3 bước), `FifoUncheckedScheduler` (`B0`), `FifoCheckedScheduler` (`B1`), `ConstraintGuard`, `PlanComparator`, `ReplacementRanker` (`A3`), `DatasetGenerator` (`D1–D4`), 5 phép thử bắt buộc (`tests/`) | Giải thích thuật toán 3 bước, bài toán nhỏ Linh – Phúc, sửa trực tiếp trọng số hoặc thêm ràng buộc mới |
| **Vai B – Hệ thống, CSDL & Bảo mật** | `database/migrations/001_init_schema.sql`, `src/Infrastructure/Persistence/`: 22 bảng PostgreSQL, `EXCLUDE USING gist`, trigger khóa dòng `FOR UPDATE`, chuỗi mã băm kiểm toán `audit_log`, `QrCheckinService`, `IcsExporter`, `CsvSafeExporter`, Docker | Chứng minh PostgreSQL tự chặn trùng giờ và vượt số người; giải thích cơ chế chống đua tranh và chuỗi mã băm kiểm toán |
| **Vai C – Giao diện, Tương tác & AI Colab T4** | `public/`, `src/Infrastructure/Ai/HybridAiService.php`, `notebooks/xanhhub_ai_colab_t4.ipynb`: Hệ token màu WCAG AA, Bảng ca kéo thả, Phòng thuật toán, Sân khấu Canvas 6 cảnh đọc JSON thật của bộ `D3`, Notebook huấn luyện AI trên Google Colab T4 (`A1`, `A4`) | Giải thích cách sân khấu Canvas biến đổi từ `B0` sang `X` dựa trên JSON thật; trình diễn Notebook Colab T4 và cơ chế tự động dự phòng thuần PHP |

---

## 2. Phạm vi Chức năng & Yêu cầu Kỹ thuật Đã Hoàn thành

1. **Năm nhóm chức năng bắt buộc (CN1 – CN5):**
   - **CN1:** Quản lý hồ sơ tình nguyện viên, 5 kỹ năng (cấp 1–5, cờ xác minh `D6`), lịch rảnh theo tuần, ngày bận đột xuất, hoạt động và ca trực (`min_people`, `max_people`, vị trí yêu cầu kỹ năng).
   - **CN2:** Tình nguyện viên đăng ký theo ca (nguyện vọng 1–3) hoặc đăng ký nhanh mọi ca hợp lịch rảnh, rút đăng ký trước hạn chót, xem trang *"Ca của tôi"* và xuất lịch `.ics`.
   - **CN3:** Đề xuất phân công theo thuật toán heuristic 2 pha (`min_people` → `max_people`) × 3 bước (Lọc khả thi → Độ khan hiếm → Chuỗi đổi chỗ Kuhn tối đa 4 bước) với 3 chế độ ưu tiên (`MAX_COVERAGE`, `FAIRNESS`, `PREFERENCE`); chỉ rõ danh sách ca/người chưa bố trí được kèm 8 mã lý do chuẩn hóa (`ReasonCode`).
   - **CN4:** Điều phối viên kéo thả sửa phân công trên bảng ca (hoặc chọn bằng bàn phím); máy chủ chạy `ConstraintGuard` chặn cứng `TIME_CONFLICT` (`H1`), `NO_SKILL` (`H2`), `CAPACITY_FULL` (`H3`), và cảnh báo mềm cho phép ghi đè kèm lý do kiểm toán đối với `NO_AVAILABLE` (`H4`), `LOAD_LIMIT` (`H5`).
   - **CN5:** Xác nhận tham gia, báo vắng và xếp hạng ứng viên thay thế có giải thích (`A3`), điểm danh bằng mã QR HMAC xoay vòng 30 giây, thống kê ca đủ/thiếu người, xuất danh sách CSV an toàn và tra cứu chứng nhận công khai.
2. **Hai yêu cầu kỹ thuật trọng tâm (KT1 & KT2):**
   - **KT1:** Thuật toán xét đồng thời kỹ năng, thời gian và số người mỗi ca; sử dụng hàm điểm phạt dư kỹ năng (`-w_k * skillWaste`) để giữ người có kỹ năng hiếm cho ca khó.
   - **KT2:** Đối chiếu trực tiếp trên cùng `dataset_snapshot` (cùng mã băm `SHA-256`) giữa **`B0` (Thứ tự đăng ký thuần)**, **`B1` (Thứ tự đăng ký có kiểm tra)** và **`X` (Xanh Hub)**:

| Bộ dữ liệu | Phương án | Đủ theo đầu người | Đủ hợp lệ (T7) | Số vi phạm (H1–H5) | Độ lệch chuẩn tải ($\sigma$) |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **D1 (Đủ người – 30 TNV, 12 ca)** | `B0` / `B1` / **`X (FAIRNESS)`** | 100% / 100% / **100%** | 16.67% / 100% / **100%** | 19 / 0 / **0** | 2.3872 / 1.4716 / **0.4819 (-67.3%)** |
| **D2 (Thiếu người – 3 ca hiếm)** | `B0` / `B1` / **`X (MAX_COVERAGE)`** | 100% / 75% / **75%** | 33.33% / 75% / **75% (9/12 ca)** | 22 / 0 / **0** | 2.0934 / 1.3437 / **0.8919** |
| **D3 (Trùng lịch nhiều)** | `B0` / `B1` / **`X (MAX_COVERAGE)`** | 100% / 50% / **100%** | 0% / 50% / **100% (12/12 ca)** | 35 / 0 / **0** | 1.6326 / 0.9991 / **0.9244** |

---

## 3. Hướng dẫn Cài đặt, Khởi tạo Dữ liệu Mẫu & Chạy Ứng dụng

### Cách 1: Chạy trực tiếp trên máy (PHP 8.2+ & PostgreSQL 16/18 – Siêu nhẹ)

```powershell
# 1. Tạo cơ sở dữ liệu xanhhub trên PostgreSQL (nếu chưa có)
"D:\PostgreSQL\bin\psql.exe" -h 127.0.0.1 -U postgres -c "CREATE DATABASE xanhhub ENCODING 'UTF8';"

# 2. Chạy migration tạo 22 bảng và nạp dữ liệu mẫu D1, D2, D3, D4
"C:\xampp\php\php.exe" bin/seed.php

# 3. Chạy bộ 21 ca kiểm thử tự động (bao gồm 5 phép thử bắt buộc mục 6 của đề)
"C:\xampp\php\php.exe" bin/run-tests.php

# 4. Khởi động máy chủ Web tại cổng 8080
"C:\xampp\php\php.exe" -S 127.0.0.1:8080 -t public
```
Sau đó mở trình duyệt tại địa chỉ: **`http://127.0.0.1:8080`**

### Cách 2: Chạy bằng một lệnh Docker Compose

```bash
docker compose up --build
```

---

## 4. Hai Tình huống Sử dụng (Kịch bản Demo Bảo vệ)

1. **Tình huống 1 (Tạo hoạt động → Đăng ký lịch, kỹ năng → Đề xuất phân công → Xác nhận):**
   - Mở `http://127.0.0.1:8080/?view=coordinator&activity_id=3` (vai trò Điều phối viên Thảo).
   - Dùng **Trợ lý AI (A1)** gõ câu tiếng Việt để tạo thử một ca trực mới, hoặc chuyển sang tab **Cổng Tình Nguyện Viên** (`/?view=volunteer&activity_id=3&volunteer_id=1` – Nguyễn Thùy Linh) để đăng ký ca và xem cảnh báo.
   - Quay lại **Phòng Thuật Toán**, bấm **"▶ Chạy Đề Xuất & So Sánh B0/B1/X"**: quan sát bảng đối chiếu KT2, lời giải thích `A4`, danh sách `unassigned` và thử kéo một tình nguyện viên thả vào ca trùng giờ hoặc ca đã đầy để thấy máy chủ từ chối kèm mã lý do chuẩn (`TIME_CONFLICT`, `NO_SKILL`, `CAPACITY_FULL`).
2. **Tình huống 2 (Báo vắng → Chọn người thay → Kiểm tra điều kiện → Cập nhật thống kê):**
   - Trên **Bảng Ca Kéo Thả**, bấm nút **"Báo vắng"** cạnh một tình nguyện viên trong ca.
   - Hệ thống mở bảng **Xếp hạng 5 ứng viên thay thế hợp lệ (`A3`)** kèm điểm số và lý do giải thích.
   - Bấm **"✓ Chọn thay thế"** để hoàn tất thay người, kiểm tra thống kê cập nhật ngay và xem dòng kiểm toán mới trong tab **4. Chứng Nhận & Nhật Ký Kiểm Toán** (`/?view=verify`).

---

## 5. Cấu trúc Thư mục & Tài liệu Thiết kế

- [`Doc/Kế hoạch đề tài 6 – Xanh Hub v1.1.md`](Doc/Kế%20hoạch%20đề%20tài%206%20–%20Xanh%20Hub%20v1.1.md): Tài liệu đặc tả thiết kế hoàn chỉnh phiên bản v1.1.
- [`docs/BUGS.md`](docs/BUGS.md): Nhật ký lỗi đã phát hiện, sửa chữa và phép thử hồi quy.
- [`THIRD_PARTY.md`](THIRD_PARTY.md): Bảng phân định rõ mã nguồn nhóm tự viết và tài nguyên tái sử dụng.
- [`notebooks/xanhhub_ai_colab_t4.ipynb`](notebooks/xanhhub_ai_colab_t4.ipynb): Notebook huấn luyện mô hình ngôn ngữ trên Google Colab GPU T4 + Google Drive.
