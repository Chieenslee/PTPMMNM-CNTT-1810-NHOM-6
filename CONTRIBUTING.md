# Hướng dẫn Đóng góp Mã nguồn mở (CONTRIBUTING.md)

Cảm ơn bạn đã tham gia đóng góp cho **Xanh Hub – Hệ thống tổ chức hoạt động và phân công tình nguyện viên (Đề tài 6)**.

## 1. Quy tắc Kiến trúc 3 Lớp
- **`src/Domain/`**: Chứa thuần túy logic nghiệp vụ, thuật toán phân công (`XanhHubScheduler`, `FifoUncheckedScheduler`, `FifoCheckedScheduler`), bộ kiểm tra ràng buộc (`ConstraintGuard`), bộ so sánh (`PlanComparator`) và danh mục 8 mã lý do (`ReasonCode`). **Tuyệt đối không gọi `PDO`, `$_POST`, hay `$_SESSION` trong `src/Domain/`.**
- **`src/Infrastructure/`**: Chứa kết nối cơ sở dữ liệu PostgreSQL (`Database`, `XanhHubRepository`), bộ sinh dữ liệu mẫu (`DatasetGenerator`), dịch vụ AI lai (`HybridAiService`), QR HMAC 30 giây (`QrCheckinService`) và xuất tệp (`IcsExporter`, `CsvSafeExporter`).
- **`public/`**: Giao diện người dùng, bảng ca kéo thả, sân khấu Canvas 6 cảnh và điểm vào HTTP.

## 2. Quy trình Nhánh, Issue và Pull Request
1. Tạo Issue mô tả rõ yêu cầu hoặc các bước tái hiện lỗi.
2. Tạo nhánh từ `main` theo quy ước: `feat/issue-XX-mo-ta` hoặc `fix/issue-XX-mo-ta`.
3. Nếu sửa lỗi, bắt buộc viết thêm một phép thử tái hiện lỗi trong `tests/` và ghi nhận vào `docs/BUGS.md`.
4. Chạy toàn bộ bộ kiểm thử tự động trước khi mở Pull Request:
   ```bash
   php bin/run-tests.php
   ```
5. Mọi Pull Request phải được ít nhất 1 thành viên khác trong nhóm review trước khi gộp vào `main`.

## 3. Quy tắc An toàn & Dữ liệu
- Chỉ sử dụng dữ liệu giả (`@example.test`, số điện thoại `0900000xxx`).
- Không đưa tệp `.env` hoặc khóa bí mật thật lên kho mã Git.
