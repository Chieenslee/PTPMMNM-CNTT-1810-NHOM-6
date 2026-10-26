# Xanh Hub – Đánh giá và điều chỉnh kế hoạch v1.1

Oct 20, 2026 · @Chiến

## Kết luận nhanh

Kế hoạch đủ sâu để làm đề cương, nhưng chưa đủ chắc để vẽ giao diện: còn 5 vấn đề cần chốt trước khi mở Penpot.

1. **Phạm vi vượt sức ba người trong 10 tuần.** Mục 5 xếp 9 tiện ích P1 vào "tuần đầu của giai đoạn mở rộng", cộng 7 tính năng AI và cảnh 3D, trái với nguyên tắc chống tính năng rác ở mục 1. Cách sửa: chia ba bậc và có danh sách cắt (mục "Phạm vi theo bậc").
2. **Thuật toán được mô tả không khớp nhau.** Mục 2 nói "luồng chi phí tối thiểu", mục 7 là tham lam theo độ khan hiếm cộng chuỗi đổi chỗ; điểm số cặp còn phụ thuộc mô hình AI trong khi lõi phải chạy được khi tắt AI.
3. **Mô hình dữ liệu thiếu mảnh.** Bảng `assignments` không có vị trí (yêu cầu kỹ năng) dù thuật toán gán theo vị trí, và ràng buộc chống trùng giờ chưa dùng được đúng như mô tả.
4. **Chưa có quy tắc vòng đời.** Chưa rõ "áp dụng phương án" làm gì khi dữ liệu đã đổi, chạy lại thế nào khi đã có người xác nhận, hay hạn xác nhận 48 giờ xử lý ra sao khi ca bắt đầu sau 30 giờ. Giao diện cần đúng những trạng thái này.
5. **Bảng màu trượt chuẩn AA mà mục 10 đã hứa.** Chữ trắng trên `--xanh-ao` chỉ đạt 3,28:1 và trên `--xanh-la` chỉ 3,01:1, dưới ngưỡng 4,5:1.

Một điều mình chưa kiểm được: mục 2 khẳng định giữ nguyên toàn bộ yêu cầu của Đề tài 6, nhưng bản đề gốc không có trong phiên làm việc này nên cần một thành viên đối chiếu từng dòng.

## Điểm mạnh cần giữ nguyên

Sáu điểm sau là nền tốt của kế hoạch và không nên bị các chỉnh sửa bên dưới làm yếu đi.

- **Lõi chấm điểm đi trước.** Mốc 6/12 cho lõi, từ 20/12 chỉ sửa lỗi, và P2 là thứ bị cắt đầu tiên.
- **Chống vi phạm nhiều lớp.** Kiểm quyền ở máy chủ, bộ ràng buộc dùng chung, rồi ràng buộc ở PostgreSQL; mỗi lớp có phép thử riêng.
- **So sánh trung thực.** Ba phương án B0, B1, X trên cùng dữ liệu, cùng hạt, cam kết ghi kết quả thật kể cả khi không đẹp.
- **AI chỉ đề xuất.** Đầu ra ép theo JSON Schema, có bộ dự phòng, tắt được mà năm chức năng vẫn đủ.
- **Biết cái gì không làm.** Bài kiểm ba câu hỏi và danh sách "cố ý không làm" ở mục 5.
- **Ví dụ Linh – Phúc** ở mục 7 giải thích được ý đồ thuật toán chỉ trong vài câu khi vấn đáp.

Lịch 10 tuần khớp khi kiểm lại: 26/10/2026 là thứ Hai, 3/1/2027 là Chủ nhật (đúng 70 ngày), còn 6/12 và 20/12 là cuối tuần 6 và tuần 8.

## Lỗi và mâu thuẫn cần sửa

Có 9 điểm ở thuật toán, 6 ở dữ liệu và 8 ở vận hành và AI; các điểm T1–T9 và D1–D6 nên sửa trước khi viết mã `Domain` và migration.

### Thuật toán (mục 2, 4, 7)

| # | Vấn đề | Cách sửa đề xuất |
| --- | --- | --- |
| T1 | KT1 ghi "luồng chi phí tối thiểu cộng tìm kiếm cục bộ" nhưng mục 7 là tham lam theo độ khan hiếm cộng chuỗi đổi chỗ. Một người nhận nhiều ca không chồng giờ nên đây không phải ghép cặp hai phía thuần. | Sửa mục 2 thành "heuristic ba bước", ghi rõ không đảm bảo tối ưu. |
| T2 | Điểm cặp có P(vắng) từ mô hình A2, nhưng lõi phải đúng và lặp lại được khi tắt AI. | Đặt w\_a = 0 mặc định; chỉ bật khi AI bật và ghi vào cấu hình lưu của phương án. |
| T3 | `skillSurplus` mơ hồ: nếu thưởng người dư kỹ năng thì chuyên gia sơ cứu bị kéo vào ca không cần. | Đổi thành `skillWaste` (phạt phần dư cấp so với mức tối thiểu) hoặc bỏ khỏi bản đầu. |
| T4 | Chưa nói cách dùng số tối thiểu và tối đa của ca. | Hai pha: pha 1 lấp đủ tối thiểu theo độ khan; pha 2 thêm tới tối đa theo chế độ ưu tiên. |
| T5 | Chưa nói ứng viên là ai: chỉ người đã đăng ký ca đó hay mọi tình nguyện viên. | Thuật toán chỉ xét người đã đăng ký; người chưa đăng ký chỉ xuất hiện ở danh sách thay thế. |
| T6 | H1 gộp thời gian đệm di chuyển, nhưng đệm là tiện ích P2 và ràng buộc loại trừ ở CSDL không tính được đệm. | H1 = không chồng giờ (cứng, kiểm cả ở CSDL); đệm = cảnh báo mềm, mặc định 0 phút. |
| T7 | "Tỷ lệ ca đủ người" đếm theo số người, nhưng B0 bỏ qua kỹ năng nên có thể "đủ" giả. | Báo hai số cho mọi phương án: đủ theo đầu người và đủ hợp lệ (mọi vị trí kỹ năng đã lấp, không vi phạm). |
| T8 | Mục tiêu giảm 40% độ lệch số ca không nói so với B0 hay B1. | Ghi rõ: so với B1, ở chế độ Công bằng; B0 chỉ để cho thấy số vi phạm. |
| T9 | Sửa tay: đề chỉ yêu cầu chặn trùng lịch, thiếu kỹ năng, vượt số người; H4 và H5 chưa nói chặn hay cảnh báo. | H1–H3 chặn cứng; H4–H5 cảnh báo, điều phối viên được ghi đè kèm lý do và vào nhật ký. |

### Dữ liệu (mục 9, 12)

| # | Vấn đề | Cách sửa đề xuất |
| --- | --- | --- |
| D1 | `assignments` chỉ có kế hoạch, ca, người, trong khi thuật toán gán vào vị trí. | Thêm `requirement_id` để biết người đó lấp vị trí kỹ năng nào. |
| D2 | `EXCLUDE USING gist (volunteer_id WITH =, during WITH &&)` cần cột `during` nằm ngay trong `assignments`, cần extension `btree_gist`, và chỉ nên áp cho bản ghi có hiệu lực; nhiều phương án đề xuất cùng chứa một người sẽ chồng nhau. | Tách `plan_items` (đề xuất) khỏi `assignments` (có hiệu lực); chép `during` từ ca; ràng buộc kèm `WHERE` theo trạng thái. |
| D3 | Trình kích hoạt đếm số người tối đa không an toàn khi hai giao dịch chạy song song. | Khóa dòng ca (`FOR UPDATE`) trong trình kích hoạt trước khi đếm; giữ phép thử hai tiến trình ở mục 13. |
| D4 | Chuỗi mã băm của `audit_log` vỡ khi hai dòng được chèn cùng lúc. | Chèn qua một hàm có khóa tư vấn (advisory lock) để tuần tự hóa. |
| D5 | Thiếu bảng cho ngày bận đột xuất (CN1 nêu), điều phối viên theo hoạt động (Policy ở mục 12 cần), và cài đặt trọng số. | Thêm `availability_overrides`, `activity_coordinators`, `settings`. |
| D6 | H2 dựa vào cấp kỹ năng tự khai nên ràng buộc chỉ đúng bằng lời khai. | Thêm trạng thái tự khai / đã xác minh cho `volunteer_skills`; ca then chốt (sơ cứu) có thể chỉ nhận người đã xác minh. |

### Vận hành và AI (mục 5, 6, 8, 12)

| # | Vấn đề | Cách sửa đề xuất |
| --- | --- | --- |
| N1 | Chưa có luồng tạo tài khoản, xác minh email, quên mật khẩu, mời điều phối viên. | Thêm vào CN1 và vào danh sách màn hình. |
| N2 | Hạn xác nhận 48 giờ vô nghĩa khi ca bắt đầu sau chưa đến 48 giờ. | Hạn xác nhận = nhỏ hơn của 48 giờ và (giờ bắt đầu − 6 giờ). |
| N3 | Mã QR 30 giây vẫn có thể bị chuyển tiếp trong khoảng đó. | Thêm điều kiện: đã đăng nhập, đúng ca được phân công, trong khung 30 phút trước đến 30 phút sau giờ bắt đầu. |
| N4 | Trang xác thực chứng nhận công khai có thể bị dò mã. | Mã ngẫu nhiên từ 12 ký tự trở lên, giới hạn tốc độ cho trang này. |
| N5 | Dompdf cần phông Unicode nhúng mới hiển thị đúng tiếng Việt có dấu. | Nhúng Be Vietnam Pro và thêm phép thử xuất PDF có dấu. |
| N6 | SSE giữ một tiến trình php-fpm cho mỗi trình duyệt đang mở. | Bản đầu chỉ dùng polling 5 giây của htmx; SSE là P2 hoặc bỏ. |
| M1 | A2 huấn luyện trên dữ liệu giả sinh theo quy luật, nên mô hình chỉ học lại quy luật đó và AUC không có nghĩa. A3 thực chất là điểm có công thức, không phải mô hình học. | Gọi đúng tên: "điểm rủi ro vắng theo quy tắc" (P2) và "xếp hạng có giải thích" (A3). Chỉ A1 và A4 dùng mô hình ngôn ngữ. Nếu vẫn giữ Rubix ML, xác nhận thư viện có chỉ số AUC trước khi cam kết. |
| M2 | Chưa chọn mô hình ngôn ngữ cục bộ, chưa đo chất lượng tiếng Việt, và giấy phép mô hình chưa vào bảng nguồn. | Thử hai mô hình nhỏ trên 50 câu mẫu của A1, chọn một, ghi tên và giấy phép vào `THIRD_PARTY.md`. |

## Quy tắc cần chốt trước khi thiết kế

Ba nhóm quy tắc dưới đây quyết định giao diện phải có những trạng thái và thông báo nào, nên cần chốt trước khi vẽ khung.

### Áp dụng và chạy lại phương án

- Áp dụng phương án là một giao dịch duy nhất: kiểm lại toàn bộ ràng buộc trên dữ liệu hiện tại; nếu mã băm đầu vào khác ảnh chụp lúc chạy thì từ chối và yêu cầu chạy lại.
- Phương án chỉ có hiệu lực khi điều phối viên xác nhận; các phương án đề xuất khác của cùng hoạt động tự hết hạn.
- Chạy lại sau khi đã có người xác nhận: phân công đã xác nhận hoặc đã khóa là đầu vào cố định, thuật toán chỉ xếp phần còn lại.
- Ca đã chốt bị khóa; mở khóa cần lý do và được ghi nhật ký.

### Hạn chót và thời gian

| Việc | Quy tắc đề xuất |
| --- | --- |
| Rút đăng ký | Trước giờ bắt đầu 24 giờ (chỉnh được); sau đó dùng luồng báo vắng |
| Xác nhận tham gia | Nhỏ hơn của 48 giờ và (giờ bắt đầu − 6 giờ); quá hạn thì đánh dấu rủi ro và nhắc |
| Nhắc lịch | 24 giờ và 2 giờ trước ca (bậc 2) |
| Check-in | Từ 30 phút trước đến 30 phút sau giờ bắt đầu |
| Tuần cho H5 | Thứ Hai đến Chủ nhật, múi giờ Asia/Ho\_Chi\_Minh |
| Hai ca sát nhau | Kết thúc 10:00 và bắt đầu 10:00 không tính trùng (khoảng nửa mở) |

### Vòng đời một phân công

&#91;embedded content: vòng đời một phân công · 7 bước, 1 vòng lặp\]

Quá hạn xác nhận không đổi trạng thái mà chỉ gắn cờ rủi ro và nhắc; người thay bước vào chỗ trống đi lại vòng đời từ "Đã phân công".

### Danh mục mã lý do thống nhất

Một danh mục duy nhất dùng cho thuật toán, sửa tay, API, phép thử và thông báo trên giao diện; mỗi mã có sẵn câu tiếng Việt và tiếng Anh.

| Mã | Dùng ở | Mức | Ghi chú |
| --- | --- | --- | --- |
| `TIME_CONFLICT` | Thuật toán, sửa tay | Cứng | Có từ mục 7 |
| `NO_SKILL` | Thuật toán, sửa tay | Cứng | Có từ mục 7 |
| `CAPACITY_FULL` | Sửa tay, API | Cứng | Mới: ca đã đủ số người tối đa |
| `NO_AVAILABLE` | Thuật toán; sửa tay | Cứng trong thuật toán, mềm khi sửa tay | Ngoài lịch rảnh đã khai |
| `LOAD_LIMIT` | Thuật toán; sửa tay | Cứng trong thuật toán, mềm khi sửa tay | Vượt số ca tối đa mỗi tuần |
| `TRAVEL_BUFFER` | Cảnh báo | Mềm | Mới, bậc 2 |
| `SNAPSHOT_STALE` | Áp dụng phương án | Cứng | Mới: dữ liệu đầu vào đã đổi |
| `SHIFT_LOCKED` | Sửa tay | Cứng | Mới: ca đã chốt, cần mở khóa kèm lý do |

## Phạm vi theo bậc và danh sách cắt

Bản nộp nên gồm Bậc 0 và Bậc 1; Bậc 2 và Bậc 3 chỉ được mở khi bậc trước đã qua kiểm thử và CI xanh.

| Bậc | Nội dung | Hạn | Điều kiện để vào bậc này |
| --- | --- | --- | --- |
| 0. Lõi chấm điểm | CN1–CN5, KT1–KT2, bộ dữ liệu D1–D3, an toàn cơ bản (kiểm quyền máy chủ, CSRF, Argon2id, ràng buộc CSDL), 5 phép thử bắt buộc, Docker, README, giao diện làm việc (bảng ca kéo thả, phòng thuật toán) | 6/12 | Bắt đầu ngay |
| 1. Mở rộng giá trị cao | Sổ giờ và chứng nhận PDF có mã xác thực; check-in QR; xuất .ics; bảng công bằng và nhật ký kiểm toán; hàng chờ dự bị cùng xếp hạng người thay (A3); trợ lý tạo ca A1 (kèm bộ phân tích quy tắc dự phòng) và giải thích bằng lời A4 | 20/12 | Bậc 0 qua kiểm thử; mốc kiểm giữa chặng 29/11 đạt ít nhất 3/5 chức năng |
| 2. Mở rộng có điều kiện | Nhắc lịch, nhập CSV, đệm di chuyển, điểm rủi ro vắng, mốc giờ, đánh giá sau hoạt động và tóm tắt A6, TOTP, song ngữ | Sau 20/12, chỉ sửa lỗi nếu không kịp | Bậc 1 xong, CI xanh |
| 3. Điện ảnh | Trang chủ 6 cảnh trên một sân khấu chung (xem mục thiết kế trải nghiệm); sân khấu dùng Three.js hay canvas 2D do lát cắt dọc quyết định | Lát cắt dọc ở tuần 3; đủ 6 cảnh trước 20/12, không chặn lõi | Giao diện làm việc đã chốt và có bộ token chuyển động |

**Cắt khỏi bản nộp:** PWA check-in offline, A5 gợi ý hoạt động, A7 hỏi đáp dữ liệu (rủi ro chèn lệnh cao, lợi ích nhỏ), bản đồ nhiệt lịch rảnh 3D, SSE. Cảnh 2 sang cảnh 3 của trang chủ phải giữ vì nó chứng minh thuật toán chạy thật; nếu lát cắt dọc không đạt ngưỡng hiệu năng thì đổi sân khấu sang canvas 2D hoặc ảnh tĩnh chứ không bỏ câu chuyện.

Mốc 29/11 là cuối tuần 5. Nếu đến đó lõi chưa đủ 3 trong 5 nhóm chức năng, đóng băng mọi thứ ngoài Bậc 0.

## Phân công cân bằng lại

Bản gốc dồn 12 hạng mục cho vai B, 6 cho vai A và trên 10 cho vai C; sau khi cắt phạm vi, việc nên chia lại như sau.

| Vai | Bậc 0 (đến 6/12) | Bậc 1 (6/12 – 20/12) |
| --- | --- | --- |
| A. Lõi thuật toán | Bộ ràng buộc, thuật toán ba bước, B0 và B1, báo cáo so sánh, danh mục mã lý do, sinh dữ liệu D1–D4 kèm đáp án viết tay, kiểm thử theo thuộc tính | Xếp hạng người thay (A3), hàng chờ dự bị, bảng công bằng, sinh tệp .ics, câu giải thích mẫu cho A4 |
| B. Hệ thống và dữ liệu | Tạo tài khoản và đăng nhập, phân quyền, CSDL và migration, API, đăng ký và rút, áp dụng phương án, báo vắng, nhật ký kiểm toán, CI, Docker | QR HMAC phía máy chủ và check-in, duyệt chứng nhận, an toàn mở rộng (giới hạn tốc độ, xác thực công khai) |
| C. Giao diện và AI | Hệ thiết kế, toàn bộ màn hình bậc 0, bảng kéo thả, phòng thuật toán, Playwright, lát cắt dọc chuyển động (tuần 3) | A1 và viết lại câu A4 bằng mô hình ngôn ngữ, chứng nhận PDF và xuất danh sách, màn hình QR, các cảnh còn lại của trang chủ |

Mỗi mô-đun có người dự phòng: A và B dự phòng chéo cho nhau ở `Domain` và API, B và C dự phòng chéo ở xuất tệp và giao diện. Nếu nhóm chỉ có hai người, giữ nguyên quy tắc gộp B và C ở mục 15 và bỏ toàn bộ Bậc 2 và Bậc 3.

## Kiểm tra bảng màu

Bốn trong số các cặp màu chính của mục 10 không đạt 4,5:1 cho chữ thường, trong đó cặp quan trọng nhất là chữ trắng trên nút `--xanh-ao`.

Tỷ lệ tính theo công thức tương phản của WCAG 2 từ các mã màu trong kế hoạch; các mã này chưa phải màu chính thức nên cần tính lại sau khi lấy mẫu trên ảnh áo thật.

| Cặp màu | Tỷ lệ | Chữ thường (4,5:1) | Đề xuất |
| --- | --- | --- | --- |
| Chữ trắng trên `--xanh-ao` #1A94E4 | 3,28 | Không đạt | Nút chính dùng nền #0F6CBD (trắng 5,38:1); giữ #1A94E4 cho vùng lớn, biểu tượng và chữ lớn |
| `--xanh-ao` trên `--xanh-troi` | 2,97 | Không đạt | Không dùng làm màu chữ hay liên kết trên nền sáng; dùng `--xanh-muc` (10,37:1) hoặc #0F6CBD (4,87:1) |
| Chữ trắng trên `--xanh-la` #1FA971 | 3,01 | Không đạt | Dùng nền #0F7A4A (trắng 5,38:1) hoặc chữ `--xanh-bien` trên `--xanh-la` (5,40:1) |
| `--vang-sao` trên `--xanh-troi` | 1,36 | Không đạt | Chỉ dùng vàng trên nền tối (10,84:1 trên `--xanh-bien`) |
| Chữ `--do-co` trên `--xanh-troi` | 4,45 | Sát ngưỡng, thiếu 0,05 | Chữ cảnh báo trên nền sáng dùng #B91C14 (5,86:1); `--do-co` giữ cho viền và biểu tượng |
| Chữ trắng trên `--do-co` | 4,93 | Đạt | Giữ nguyên |
| `--xanh-muc` trên `--xanh-troi` | 10,37 | Đạt | Giữ nguyên |
| Chữ trắng trên `--xanh-bien` | 16,28 | Đạt | Giữ nguyên |
| `--xanh-ao` trên `--xanh-bien` | 4,96 | Đạt | Giữ nguyên |

Thêm một quy tắc: màu hợp lệ (xanh lá) và vi phạm (đỏ) khó phân biệt với người mù màu đỏ – lục, nên mỗi trạng thái cần kèm biểu tượng và chữ, đúng như dòng "hiện lý do bằng chữ" đã có ở mục 10.

## Thiết kế trải nghiệm và chuyển động

Trang chủ chỉ hấp dẫn khi nó là một câu chuyện cuộn liền mạch, không phải một chuỗi hiệu ứng riêng lẻ; mục 10 của kế hoạch liệt kê hiệu ứng (hạt, parallax, Ken Burns) nhưng chưa có hệ thống tạo ra sự liền mạch đó.

Bản v1.1 phía trên xếp phần điện ảnh xuống bậc thấp nhất mà không nói cách làm cho nó tốt. Nhận định đó thiếu: với một sản phẩm của Đoàn – Hội, cảm giác khi bước vào là thứ người xem nhớ, nên mục này thay thế cách nhìn cũ bằng một thiết kế có thể dựng, đo và cắt giảm có kiểm soát.

### Năm nguyên lý để chuyển cảnh mượt

1. **Một sân khấu cố định.** Một canvas nằm cố định phía sau toàn bộ trang chủ; các cảnh là những trạng thái khác nhau của cùng một nhóm hạt, nên chuyển cảnh là biến đổi chứ không phải cắt sang hình khác. Cuối cảnh trước phải là hình dạng đầu cảnh sau.
2. **Cuộn là đồng hồ.** Một timeline chính gắn với vị trí cuộn (scrub); người dùng quyết định tốc độ, không có đoạn tự chạy chiếm quyền cuộn.
3. **Một vòng lặp, một nhịp.** Cuộn mượt, timeline và canvas dùng chung một vòng lặp (ticker của GSAP điều khiển Lenis), vì hai bộ hẹn giờ riêng sẽ lệch nhau và gây giật. Thời lượng, easing và độ trễ so le lấy từ một bảng token chung.
4. **Câu chuyện có kịch tính.** Hỗn loạn, rồi trật tự, rồi bằng chứng, rồi lời mời; mỗi cảnh chỉ một ý và một câu chữ ngắn.
5. **Cùng ngôn ngữ ở trang làm việc.** Bảng điều phối dùng lại màu, easing và cách "người bay vào ô" của trang chủ, để từ cổng vào đến chỗ làm việc người dùng cảm thấy đó là một sản phẩm.

### Kiến trúc chuyển cảnh

&#91;embedded content: kiến trúc chuyển cảnh · 1 vòng lặp, 2 lớp hiển thị\]

Sân khấu và lớp chữ cùng đọc một timeline, nên cuộn nhanh hay chậm thì chữ và hạt vẫn khớp nhau; kết quả thuật toán thật là đầu vào của sân khấu, và giám sát khung hình bảo vệ độ mượt trên máy yếu.

## Storyboard trang chủ

Trang chủ có 6 cảnh trên một sân khấu chung, và khoảnh khắc đặc trưng là cảnh 2 sang cảnh 3: cùng một nhóm hạt chuyển từ kết quả của cách gán theo thứ tự đăng ký (B0) sang kết quả của Xanh Hub khi người dùng cuộn.

Khoảnh khắc này đáng làm nhất vì nó vừa tạo cảm xúc vừa chứng minh yêu cầu KT2, và nó đọc dữ liệu thật nên không phải hoạt ảnh giả.

| Cảnh | Chữ và nội dung | Chuyển động | Nối sang cảnh sau | Dữ liệu và dự phòng |
| --- | --- | --- | --- | --- |
| 1. Biển người | Chỉ tên sản phẩm; chấm sáng nở thành biển người áo xanh | Hạt nở ra, camera tiến chậm | Hạt co lại, mỗi hạt là một người trong bộ D3 | Không có WebGL: ảnh tĩnh |
| 2. Hỗn loạn | "Gán theo thứ tự đăng ký"; bộ đếm vi phạm tăng dần | Hạt bay vào ô ca theo thứ tự đăng ký; ca trùng giờ và thiếu kỹ năng viền đỏ | Giữ nguyên hạt, chỉ đổi vị trí | Kết quả B0 thật (JSON); dự phòng: ảnh chụp kết quả |
| 3. Trật tự | "Đúng người. Đúng ca. Đúng giờ."; bộ đếm vi phạm giảm về 0 | Hạt di chuyển sang vị trí của phương án X theo từng nhịp cuộn; ô đủ người sáng xanh lá | Camera lùi ra, hạt mờ dần nhường chỗ cho ảnh | Kết quả X thật (JSON) |
| 4. Con người | Ảnh thật, một câu trích ngắn | Ảnh nhuộm hai tông xanh, chữ chạy ngang, parallax hai lớp | Ảnh thu nhỏ thành thẻ số liệu | Ảnh theo mục 11 và `CREDITS.md` |
| 5. Con số | Ca đủ người, vi phạm bằng 0, độ lệch số ca | Đếm số khi cuộn tới, cột mọc lên | Số "0 vi phạm" nở thành nút | Số lấy từ báo cáo so sánh thật |
| 6. Lời mời | "Đăng ký ca đầu tiên" | Ngôi sao vàng quay nhẹ, nút sáng khi rê chuột | Kết thúc | Bản tĩnh khi giảm chuyển động |

Độ dài cuộn đề xuất khoảng 6 đến 7 màn hình; số này chỉ là điểm xuất phát và cần chỉnh khi thử. Trên điện thoại giảm số hạt và bỏ parallax, đo lại trên máy thật.

## Chuyển trang và vùng làm việc

Chuyển động ở trang làm việc phải ngắn và có nghĩa; chuyển trang dùng View Transitions của trình duyệt kèm đường lui là đổi trang tức thời.

- htmx bật View Transitions bằng `transition:true` trên `hx-swap`, hoặc bật cho mọi lần đổi nội dung bằng `htmx.config.globalViewTransitions` ([htmx](https://htmx.org/attributes/hx-swap)). Trang tài liệu đó không nêu hành vi ở trình duyệt chưa hỗ trợ, nên phải có phép thử riêng.
- Chuyển cảnh trong cùng một tài liệu chạy trên Chrome và Edge từ bản 111, Safari 18, Firefox 144. Chuyển cảnh giữa hai trang riêng biệt (cả hai trang khai báo `@view-transition` với `navigation: auto`) chạy trên Chrome và Edge 126, Safari 18.2, chưa có Firefox ([Chrome for Developers](https://developer.chrome.com/docs/web-platform/view-transitions)).
- Phần tử dùng chung giữa hai màn hình (thẻ hoạt động sang trang chi tiết, ô ca sang bảng) được đặt cùng `view-transition-name` để trình duyệt tự nội suy vị trí và kích thước.
- Mọi chuyển động phải có bản thay thế khi bật giảm chuyển động; riêng trạng thái vi phạm không được chỉ truyền bằng rung, mà phải kèm biểu tượng và chữ.

| Khoảnh khắc | Chuyển động | Thời lượng đề xuất | Để làm gì |
| --- | --- | --- | --- |
| Nhấc một tình nguyện viên | Nâng nhẹ kèm bóng đổ; ô hợp lệ sáng xanh | 120 ms | Báo đang cầm, chỉ chỗ thả được |
| Thả vào ô hợp lệ | Thẻ trượt vào vị trí rồi lún nhẹ | 200 ms | Xác nhận đã nhận |
| Thả vào ô vi phạm | Rung ngang hai lần, thẻ về chỗ cũ, hiện lý do bằng chữ | 250 ms | Từ chối rõ ràng, nêu mã lý do |
| Báo vắng và người thay | Ô trống sáng nhẹ, người thay bay vào | 250 ms | Cho thấy chỗ trống được lấp |
| Số thống kê đổi | Đếm lên hoặc xuống, viền ô vừa đổi sáng lên | 250 ms | Chỉ ra điều gì vừa thay đổi |
| Chuyển trang | Mờ chéo, phần tử dùng chung trượt sang vị trí mới | 200 – 250 ms | Giữ mạch định hướng |
| Chạy thuật toán | Thanh tiến độ, từng người bay vào ca, trễ so le 30 – 80 ms | 0,8 – 1,6 s tổng | Cho thấy thuật toán làm gì |
| Chờ dữ liệu | Khung xương (skeleton) thay vì vòng xoay | Theo thời gian tải | Giữ bố cục, tránh nhảy trang |

## Cách dựng thử và ngân sách hiệu năng

Penpot không thử được chuyển động, nên cảm giác "mượt" phải được kiểm bằng mã từ sớm, trên một lát cắt dọc nhỏ thay vì chờ đến cuối.

1. **Viết bảng thời gian (animatic) cho cảnh 1 đến 3:** mốc cuộn, thời lượng, easing, làm cùng lúc với khung giao diện ở Penpot.
2. **Dựng lát cắt dọc:** một sân khấu cố định cùng cảnh 2 sang cảnh 3, chạy bằng JSON mẫu của bộ D3 trong `tests/fixtures`, đổi sang kết quả thuật toán thật khi vai A xuất được. Đề xuất giới hạn 3 ngày làm việc, vào tuần 3.
3. **Đo trên ba thiết bị:** laptop, điện thoại tầm trung và một máy cũ. Nếu bản Three.js nặng, so sánh với canvas 2D và chọn bản đạt ngưỡng.
4. **Khóa token chuyển động** (thời lượng, easing, độ trễ so le), rồi mới áp cho các cảnh còn lại và cho trang làm việc.
5. **Cổng kết quả:** lát cắt không đạt ngưỡng dưới đây thì dùng canvas 2D hoặc ảnh tĩnh, không kéo dài thời gian.

| Chỉ số | Mục tiêu | Ghi chú |
| --- | --- | --- |
| Khung hình khi cuộn trang chủ | Từ 55 fps trên máy tầm trung | Tụt dưới 50 fps thì tự hạ độ phân giải (đã có ở mục 10) |
| Chữ đầu tiên hiện ra (LCP) | Tối đa 2,5 giây | Three.js tải lười, sau khi chữ đã hiện |
| Độ trễ tương tác (INP) | Tối đa 200 ms | Ngưỡng "tốt" của Core Web Vitals |
| Dịch chuyển bố cục (CLS) | Tối đa 0,1 | Ngưỡng "tốt" của Core Web Vitals |
| Lighthouse Accessibility | Từ 90 | Đã có ở mục 1 |
| Giảm chuyển động | Mọi cảnh có bản tĩnh | Lenis tự tắt cuộn mượt khi người dùng bật giảm chuyển động |

Lenis bọc cuộn gốc của trình duyệt nên liên kết neo, `sticky` và trình đọc màn hình vẫn dùng được; nó phải được điều khiển bằng đúng một vòng lặp, ở đây là ticker của GSAP ([Lenis trên GitHub](https://github.com/darkroomengineering/lenis)).

**Cố ý không làm:** hiệu ứng tự chạy chiếm quyền cuộn, chặn cuộn trên điện thoại (scroll-jacking), con trỏ tùy biến hay chuyển động dài trên trang làm việc, âm thanh tự phát, và mọi chuyển động chỉ để trang trí (đúng nguyên tắc đã ghi ở mục 10).

Nguồn, xem ngày 20/10/2026: [Chrome for Developers – View Transition API](https://developer.chrome.com/docs/web-platform/view-transitions), [htmx – hx-swap](https://htmx.org/attributes/hx-swap), [Lenis](https://github.com/darkroomengineering/lenis). Các con số về thời lượng, số ngày và ngưỡng fps là đề xuất của mình, cần thử lại trên máy thật.

## Gói chuẩn bị trước khi thiết kế website

Mục 10 mô tả phong cách nhưng chưa có danh sách màn hình, trạng thái và dữ liệu từng màn; bộ dưới đây là mức tối thiểu để vẽ khung trong Penpot mà không phải đoán.

### Màn hình cần thiết kế

| Bậc | Màn hình | Vai trò | Dữ liệu chính | Trạng thái phải vẽ |
| --- | --- | --- | --- | --- |
| 0 | Đăng nhập và đăng ký | Mọi vai | `users` | Sai mật khẩu, khóa 5 lần mỗi 10 phút, yêu cầu TOTP, quên mật khẩu |
| 0 | Bảng điều khiển tình nguyện viên | Tình nguyện viên | `registrations`, `assignments` | Chưa có ca, có ca cần xác nhận |
| 0 | Danh sách và chi tiết hoạt động | Tình nguyện viên | `activities`, `shifts` | Cảnh báo trùng giờ, ngoài lịch rảnh, quá hạn rút |
| 0 | Ca của tôi | Tình nguyện viên | `assignments` | Đề xuất, đã phân công, đã xác nhận, dự bị; rút và báo vắng |
| 0 | Hồ sơ và lịch rảnh | Tình nguyện viên | `volunteers`, `availability` | Lưới 7 ngày × 30 phút, ghi đè ngày bận |
| 0 | Tạo và sửa hoạt động, ca | Điều phối viên | `activities`, `shifts`, `shift_requirements` | Nháp, mở đăng ký, đã chốt |
| 0 | Bảng ca kéo thả | Điều phối viên | `assignments`, `volunteers` | Ô hợp lệ, ô vi phạm kèm mã lý do, ca bị khóa, hoàn tác |
| 0 | Phòng thuật toán | Điều phối viên | `plans`, `unassigned` | Đang chạy, kết quả, danh sách chưa bố trí, so sánh B0, B1, X |
| 0 | Báo vắng và thay người | Điều phối viên | `absence_reports`, `waitlist` | Hết ứng viên, người trùng giờ bị từ chối |
| 0 | Thống kê và xuất danh sách | Điều phối viên | Các bảng trên | Rỗng, đang xuất, xuất lỗi |
| 0 | Vai trò, trọng số, nhật ký | Quản trị Đoàn | `users`, `settings`, `audit_log` | Đổi vai trò cần xác nhận |
| 1 | Check-in QR (chiếu và quét) | Điều phối viên, tình nguyện viên | `checkins` | Mã hết hạn, sai ca, quét lần hai |
| 1 | Sổ giờ và chứng nhận | Tình nguyện viên, Quản trị Đoàn | `certificates` | Chờ duyệt, đã duyệt, tải PDF |
| 1 | Xác thực chứng nhận (công khai) | Người xác thực | `certificates` | Hợp lệ, không tồn tại, bị giới hạn tốc độ |
| 3 | Trang chủ 6 cảnh | Công khai | Kết quả thuật toán thật | Giảm chuyển động, không có WebGL |

### Chín việc cần có trước khi vẽ

1. **Ưu tiên thiết bị:** tình nguyện viên dùng điện thoại là chính; điều phối viên và Quản trị Đoàn dùng máy tính (bảng kéo thả cần từ 1024 px, trên điện thoại chỉ xem và báo vắng).
2. **Hai luồng demo ở mục 16 là hai prototype bắt buộc.** Vẽ theo luồng chứ không theo từng trang.
3. **Sáu trạng thái chung cho mọi màn hình:** rỗng, đang tải, lỗi, không đủ quyền (403), vi phạm ràng buộc, hết phiên.
4. **Dữ liệu mẫu thật cho thiết kế:** xuất một JSON nhỏ từ bộ D3 cho bảng ca và cảnh thuật toán, để giao diện và cảnh 3D không vẽ trên dữ liệu bịa.
5. **Bảng thông điệp theo mã lý do** (xem mục quy tắc) bằng cả hai ngôn ngữ, để giao diện lấy chữ từ một nơi.
6. **Hệ token sửa lại màu** theo mục kiểm tra bảng màu trước khi tạo component.
7. **Bộ component tối thiểu:** nút, ô ca, thẻ tình nguyện viên, huy hiệu trạng thái, thanh cảnh báo vi phạm, bảng so sánh B0 B1 X.
8. **Kiểm tiếp cận ngay từ khung xám:** thứ tự phím Tab của bảng kéo thả, và cách thay thế kéo thả bằng chọn và nút "Gán vào ca" cho người dùng bàn phím.
9. **Thứ tự vẽ:** token và component, rồi hai luồng demo ở khung xám, rồi áp màu và ảnh, song song là lát cắt dọc chuyển động (cảnh 2 và 3) để thử cảm giác sớm, rồi mới dựng trang chủ đủ 6 cảnh.

## Vệ sinh tài liệu

Bản kế hoạch còn 3 loại lỗi cần dọn trước khi nộp hoặc đưa lên kho mã.

- **Câu lọt từ cuộc trò chuyện.** Mục 10 có câu "xem tin nhắn bàn giao" và mục 11 có đoạn "Trong phiên làm việc này: mình đã tìm…"; kế hoạch phải tự đứng được, nên chuyển các ý đó thành ghi chú riêng hoặc bỏ.
- **Nội dung nhúng chưa nằm trong tệp.** Sơ đồ kiến trúc ba lớp (mục 8) và lộ trình 10 tuần (mục 15) chỉ hiện dạng chỗ giữ chỗ trong bản `.md`; xuất ra ảnh hoặc Mermaid để tệp nộp tự đủ.
- **Bảng mục 2 có dòng trống ở cuối.**

Lỗi chính tả và thuật ngữ cần thống nhất:

| Hiện tại | Nên sửa |
| --- | --- |
| mã bam, mã bam SHA-256 | mã băm |
| nhật kí | nhật ký (mục 3, thống nhất với phần còn lại) |
| Sắt ngày diễn ra (mục 7) | Sát ngày diễn ra |
| lng áo (mục 11) | lưng áo |
| Biểu quyền toàn cơ sở Đoàn (mục 3) | Quyền trên toàn cơ sở Đoàn |
| đường dẫn lập lời (mục 12) | đường dẫn lách thư mục |
| Đầu Content-Security-Policy (mục 12) | Tiêu đề (header) Content-Security-Policy |

Một việc nữa: ngày đầu tài liệu đang ghi "Oct 20, 2026"; nên đổi sang 20/10/2026 cho thống nhất với các mốc ngày trong mục 15.

## Việc cần làm tiếp theo

Một buổi họp nhóm khoảng 60 phút để chốt 8 quyết định dưới đây là đủ để mở khóa việc vẽ giao diện.

- [ ] Chốt phạm vi: bản nộp gồm Bậc 0 và Bậc 1, danh sách cắt như trên
- [ ] Thống nhất mô tả thuật toán: heuristic ba bước, xử lý T1 đến T9
- [ ] Chốt quy tắc áp dụng, chạy lại phương án và hạn chót
- [ ] Chốt danh mục mã lý do và thông điệp tiếng Việt, tiếng Anh
- [ ] Chốt H4 và H5 khi sửa tay: cảnh báo cho phép ghi đè hay chặn cứng
- [ ] Chốt ứng viên của thuật toán: chỉ người đã đăng ký
- [ ] Quyết định số phận của A2: hạ thành điểm rủi ro theo quy tắc ở bậc 2, hay giữ mô hình học và nói rõ giới hạn
- [ ] Chọn màu nút chính và màu trạng thái theo bảng kiểm tra tương phản

Sau buổi họp, thứ tự đề xuất là: cập nhật kế hoạch lên v1.1 (A sửa mục 7, B sửa mục 9, C sửa mục 10), một người đối chiếu mục 2 với đề gốc, rồi mở Penpot theo thứ tự ở mục gói chuẩn bị thiết kế.
