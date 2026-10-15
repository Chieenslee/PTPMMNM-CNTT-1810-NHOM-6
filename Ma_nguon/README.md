# Lab 02 — Xây dựng module PHP kiểm tra điều kiện đăng ký

**Nhóm 06 — Lớp CNTT 1810**  
**Đề tài 6: Xây dựng hệ thống tổ chức hoạt động và phân công tình nguyện viên**

### Thành viên nhóm:
| STT | Họ và tên | Mã sinh viên | Vai trò |
| :---: | :--- | :---: | :--- |
| 1 | **Lê Trọng Chiến** | 1871020089 | Nhóm trưởng |
| 2 | **Nguyễn Hữu Dũng** | 1871020173 | Thành viên |
| 3 | **Đặng Việt Anh** | 1871020683 | Thành viên |

---

Module nghiệp vụ kiểm tra điều kiện tiếp nhận đăng ký tình nguyện viên tham gia hoạt động (`CapacityPolicy` và `RegistrationPolicy`). Xử lý số lượng đăng ký so với sức chứa/chỉ tiêu, danh sách chờ (waiting list) và trạng thái đóng/mở của hoạt động.

## Mở bài

Mở terminal Ubuntu/WSL tại thư mục chứa `lab.sh`. Dùng PHP từ 8.1 và Git. Hướng dẫn chi tiết, sơ đồ, đoạn mã cần sửa, dữ liệu đối chiếu và rubric nằm trong tài liệu của bài lab.

```bash
bash lab.sh check
bash lab.sh run
```

Chương trình in kết quả ngay trong terminal.

Bài này không cần PostgreSQL, Composer hoặc framework.

## Kiểm tra bài

```bash
bash lab.sh test
bash lab.sh extend
```

`test` kiểm tra phần mẫu; `extend` kiểm tra phần tự làm. Các vị trí TODO là nhiệm vụ cần hoàn thiện; không sửa test để bỏ qua lỗi.

Kết quả mở rộng cần đạt: `TESTS=8 FAILED=0`. Có lớp kiểm tra sức chứa, chương trình gọi lớp và lớp mở rộng trả confirmed, waiting hoặc rejected theo quy tắc đã công bố.

## Tệp nộp

Giữ mã nguồn, dữ liệu mẫu và kiểm thử. Ghi lại lệnh chạy trong README. Không đưa `.local`, mật khẩu hoặc dữ liệu cá nhân thật vào kho mã và gói nộp. Nộp đúng commit theo yêu cầu của bài; nếu dùng Actions, đính kèm lần chạy tương ứng.
