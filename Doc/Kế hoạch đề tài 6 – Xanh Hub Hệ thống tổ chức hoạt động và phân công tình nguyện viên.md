# Kế hoạch đề tài 6 – Xanh Hub: Hệ thống tổ chức hoạt động và phân công tình nguyện viên

Oct 20, 2026 · @Chiến Lê

## 1. Tổng quan

Xanh Hub là hệ thống web mã nguồn mở giúp Đoàn – Hội tổ chức hoạt động tình nguyện: nhận đăng ký, tự đề xuất phân công theo kỹ năng, thời gian và số người mỗi ca, thay người khi có báo vắng, rồi cấp chứng nhận giờ tình nguyện. Khẩu hiệu của sản phẩm: **đúng người, đúng ca, đúng giờ**.

Kế hoạch giữ nguyên toàn bộ yêu cầu của Đề tài 6 trong tài liệu môn Phát triển phần mềm mã nguồn mở, rồi làm vượt ở ba hướng: thuật toán và AI, tiện ích dùng được thật, và trải nghiệm giao diện mang màu áo xanh Đoàn.

**Mục tiêu đo được**

- Đạt 5/5 nhóm chức năng, 2/2 yêu cầu kỹ thuật và 5/5 phép thử bắt buộc; viết thêm ít nhất 25 phép thử tự động.
- Phương án của nhóm so với cách gán theo thứ tự đăng ký trên cùng dữ liệu: 0 vi phạm, tỷ lệ ca đủ người cao hơn, độ lệch số ca giữa các tình nguyện viên thấp hơn (mục tiêu nội bộ: giảm từ 40% trở lên).
- Người chấm cài và chạy lại bằng một lệnh `docker compose up`, có dữ liệu mẫu sẵn.
- Giao diện đạt Lighthouse Accessibility từ 90, tôn trọng chế độ giảm chuyển động và chạy được trên điện thoại.

**Nguyên tắc chống "tính năng rác"**

Mỗi tiện ích thêm ngoài đề bài phải trả lời được ba câu hỏi: ai dùng, dùng vào lúc nào, kiểm thử bằng cách nào. Thiếu một trong ba thì bỏ. Mọi tiện ích còn lại nằm ở mục 5 kèm lý do tồn tại.

**Giới hạn phạm vi** (đúng theo đề): không tìm phương án tối ưu tuyệt đối cho mọi trường hợp, không kết nối lịch bên ngoài (chỉ xuất tệp .ics để người dùng tự nhập), chỉ dùng dữ liệu giả, không bắt buộc dịch vụ trả phí.

## 2. Đối chiếu với đề bài và phần làm vượt

Mỗi dòng dưới đây nêu yêu cầu gốc của Đề tài 6, cách Xanh Hub đáp ứng đủ để được điểm tối đa, và phần làm thêm. Phần làm thêm chỉ được xây sau khi phần bắt buộc đã qua kiểm thử.

| Yêu cầu của đề | Xanh Hub đáp ứng thế nào | Làm vượt thêm |
| --- | --- | --- |
| CN1. Quản lý hồ sơ, kỹ năng, lịch rảnh, hoạt động, số người mỗi ca | CRUD đầy đủ, lịch rảnh theo khung 30 phút, mỗi ca khai báo kỹ năng bắt buộc và số người tối thiểu, tối đa | Nhập CSV, kỹ năng có cấp độ 1–5, lịch rảnh lặp theo tuần, nhân bản hoạt động, mẫu hoạt động |
| CN2. Đăng ký, rút đăng ký, xem ca được phân công | Trang cá nhân, đăng ký nhiều ca, rút trước hạn chót | Xuất lịch cá nhân .ics, nhắc lịch, hàng chờ dự bị khi ca đầy |
| CN3. Đề xuất phân công theo điều kiện và ưu tiên, chỉ rõ người/ca chưa bố trí được | Thuật toán xét đồng thời kỹ năng, thời gian, số người; báo lý do từng trường hợp chưa bố trí | Ba chế độ ưu tiên (đủ người, công bằng, theo nguyện vọng), giải thích bằng AI, mô phỏng "nếu thì" |
| CN4. Điều phối viên sửa phân công, vẫn kiểm tra trùng lịch, thiếu kỹ năng, vượt số người | Một bộ kiểm tra ràng buộc dùng chung cho thuật toán và sửa tay, chạy ở máy chủ | Kéo thả trên bảng ca, báo vi phạm ngay khi kéo, hoàn tác/làm lại, nhật ký kiểm toán |
| CN5. Xác nhận tham gia, thay người vắng, thống kê đủ/thiếu, xuất danh sách | Quy trình báo vắng, danh sách ứng viên thay thế đã lọc điều kiện, báo cáo và xuất CSV/PDF | Check-in bằng mã QR xoay vòng, sổ giờ tình nguyện, chứng nhận PDF có mã xác thực |
| KT1. Thuật toán xét đồng thời kỹ năng, thời gian, số người/ca | Bài toán gán có ràng buộc: luồng chi phí tối thiểu cộng tìm kiếm cục bộ | Giải thích thuật toán bằng sơ đồ và ví dụ số nhỏ trong tài liệu |
| KT2. So sánh với cách gán theo thứ tự đăng ký: tỷ lệ ca đủ, số vi phạm, chênh lệch số ca | Trang báo cáo chạy cả hai trên cùng dữ liệu, lưu cấu hình từng thuật toán | Biểu đồ so sánh, bộ benchmark 300 tình nguyện viên, hạt ngẫu nhiên cố định để chạy lại |
| Dữ liệu: 30 tình nguyện viên, 12 ca, 5 kỹ năng, 3 bộ dữ liệu | Đúng 3 bộ: đủ người, thiếu người, nhiều trùng lịch | Bộ thứ 4 lớn (300 tình nguyện viên, 60 ca, 12 kỹ năng) sinh từ script và hạt cố định |
| An toàn (0,5 + 0,5) | Phiên bản dữ liệu đầu vào gắn vào mỗi phương án lưu; mọi thay đổi lịch kiểm quyền ở máy chủ | Mã hóa mật khẩu Argon2id, CSRF, giới hạn tốc độ, nhật ký kiểm toán không sửa được |
| Kiểm thử (1,5) | 5 phép thử mục 6 của đề, có đáp án; kiểm thử tự động và tích hợp; hồi quy | Kiểm thử theo thuộc tính ngẫu nhiên, CI chạy mỗi lần đẩy mã, báo cáo độ phủ |
| Mã nguồn mở (1,5) | LICENSE, README, CONTRIBUTING, ghi nguồn, issue, review, Release | Docker Compose, dữ liệu mẫu tự nạp, issue template, CHANGELOG |
| Đóng góp cá nhân (1,0) | Phân mô-đun rõ cho 3 người, có commit và PR minh chứng | Bảng "ai làm gì" sinh từ lịch sử Git để phục vụ vấn đáp |
|  |  |  |

**Năm phép thử bắt buộc của đề và nơi kiểm:**

1. Một người không bị phân vào hai ca trùng giờ: `ConflictTimeTest` (xem mục 13).
2. Ca có yêu cầu kỹ năng không nhận người thiếu kỹ năng: `SkillRequiredTest`.
3. Sửa phân công vượt số người của ca bị từ chối: `CapacityGuardTest` (cả qua API trực tiếp).
4. Khi thiếu người, báo rõ ca chưa đủ và không tạo người giả: `UnderstaffedReportTest`.
5. Báo cáo so sánh cùng dữ liệu và lưu cấu hình từng thuật toán: `CompareReportTest`.

## 3. Người dùng và vai trò

Đề bài nêu hai nhóm người dùng là tình nguyện viên và người điều phối. Xanh Hub giữ hai vai trò đó làm trọng tâm và thêm hai vai trò phụ để hệ thống dùng được ngoài thực tế. Quyền được kiểm tra ở máy chủ theo vai trò và theo từng hoạt động.

| Vai trò | Ai là người này | Được làm | Không được làm |
| --- | --- | --- | --- |
| Tình nguyện viên | Đoàn viên, sinh viên, thanh niên đăng ký tham gia | Sửa hồ sơ, kỹ năng, lịch rảnh của mình; đăng ký, rút; xác nhận; báo vắng; check-in; xem giờ và chứng nhận | Xem hồ sơ người khác; sửa phân công |
| Điều phối viên | Ban tổ chức một hoạt động cụ thể | Tạo ca, chạy đề xuất phân công, sửa bằng kéo thả, chọn người thay, xuất danh sách, xem thống kê hoạt động của mình | Sửa hoạt động của ban khác; xóa nhật ký |
| Quản trị Đoàn (cán bộ Đoàn – Hội) | Biểu quyền toàn cơ sở Đoàn | Cấp vai trò, duyệt chứng nhận, xem thống kê toàn cơ sở, cấu hình kỹ năng và trọng số thuật toán | Sửa nhật kí kiểm toán |
| Người xác thực (không đăng nhập) | Nhà trường, đơn vị nhận chứng nhận | Nhập mã trên chứng nhận để kiểm tra thật giả, chỉ thấy tên và số giờ | Xem bất kỳ dữ liệu cá nhân nào khác |

Bốn nhân vật mẫu dùng khi viết kịch bản demo (toàn bộ là dữ liệu giả): **Linh**, sinh viên năm hai, biết sơ cứu, rảnh tối thứ Bảy; **Phúc**, đoàn viên, có bằng lái xe, hay bận sáng; **Thảo**, điều phối viên của chi đoàn; **cô Hương**, cán bộ Đoàn xã duyệt chứng nhận.

## 4. Chức năng chi tiết năm nhóm bắt buộc

Mỗi nhóm dưới đây liệt kê hành vi cụ thể để đạt mức 0,6 điểm "đủ hành vi và được tích hợp". Chữ "tích hợp" nghĩa là dữ liệu chạy xuyên từ nhóm này sang nhóm khác, không chỉ có màn hình.

### CN1. Quản lý hồ sơ, kỹ năng, lịch rảnh, hoạt động, ca

- Hồ sơ tình nguyện viên: họ tên, mã sinh viên hoặc mã đoàn viên, chi đoàn, liên hệ, ảnh đại diện, số ca tối đa mỗi tuần (để không quá tải).
- Kỹ năng: danh mục 5 kỹ năng gốc (sơ cứu, lái xe, dạy học, truyền thông, hậu cần) có thể thêm; mỗi người gắn kỹ năng kèm cấp 1–5 và minh chứng tùy chọn (giấy chứng nhận).
- Lịch rảnh: lưới 7 ngày × khung 30 phút, tô chọn bằng kéo chuột; lặp theo tuần; ngày bận đột xuất để ghi đè.
- Hoạt động: tên, địa điểm, mô tả, thời gian, ảnh bìa, trạng thái (nháp, mở đăng ký, đã chốt, diễn ra, đã xong).
- Ca: giờ bắt đầu/kết thúc, kỹ năng bắt buộc kèm cấp tối thiểu, số người tối thiểu và tối đa, độ ưu tiên của ca (ca then chốt được lấp trước).

### CN2. Đăng ký, rút đăng ký, xem ca

- Đăng ký theo hoạt động hoặc theo ca, chọn nguyện vọng 1–3 và ghi chú.
- Hệ thống cảnh báo ngay nếu ca trùng giờ với ca đã đăng ký hoặc nằm ngoài lịch rảnh (cảnh báo, không chặn, vì người dùng có thể đổi lịch).
- Rút đăng ký trước hạn chót (mặc định 24 giờ, chỉnh được); sau hạn chót phải dùng luồng báo vắng.
- Trang "Ca của tôi": danh sách và lịch tuần, trạng thái từng ca (đề xuất, đã phân công, đã xác nhận, dự bị).

### CN3. Đề xuất phân công

- Người điều phối bấm "Đề xuất phân công", chọn một trong ba chế độ ưu tiên (mục 7).
- Kết quả là một **phương án** được lưu cùng cấu hình và phiên bản dữ liệu đầu vào; chưa có hiệu lực cho đến khi điều phối xác nhận.
- Danh sách **chưa bố trí được** tách riêng: ca nào thiếu bao nhiêu người và vì sao (thiếu kỹ năng, không ai rảnh, trùng giờ); tình nguyện viên nào chưa được gán và vì sao.
- Tuyệt đối không tạo người giả, không hạ yêu cầu kỹ năng để "lấp" ca.

### CN4. Điều phối viên sửa phân công

- Bảng ca × người, kéo thả một tình nguyện viên từ danh sách sang ô ca.
- Mỗi lần thả, máy chủ chạy lại bộ kiểm tra: trùng lịch, thiếu kỹ năng, vượt số người. Vi phạm thì từ chối và nêu đúng lý do; giao diện chỉ gợi ý, không phải nơi quyết định.
- Hoàn tác, làm lại, và so sánh "trước – sau" khi sửa.
- Khóa ca đã chốt: sửa phải mở khóa kèm lý do và được ghi nhật ký.

### CN5. Xác nhận, thay người, thống kê, xuất danh sách

- Tình nguyện viên xác nhận trong 48 giờ; quá hạn mà chưa phản hồi thì hệ thống đánh dấu rủi ro và nhắc.
- Báo vắng: chọn lý do, hệ thống tự mở chỗ trống, lọc danh sách người thay đúng kỹ năng, không trùng giờ, còn đủ hạn mức ca; điều phối chọn hoặc để hệ thống mời lần lượt.
- Thống kê theo hoạt động: ca đủ, ca thiếu, tỷ lệ lấp đầy, số ca mỗi người, tỷ lệ vắng.
- Xuất danh sách tham gia theo ca/hoạt động: CSV, Excel, PDF có ảnh và chỗ ký.

## 5. Tiện ích mở rộng dùng được, không làm rác

Mỗi tiện ích dưới đây đã qua bài kiểm ba câu hỏi ở mục 1: có người dùng cụ thể, có lúc dùng cụ thể, có cách kiểm thử. Cột "Ưu tiên" cho biết làm trước hay sau: P1 làm trong tuần đầu của giai đoạn mở rộng, P2 làm nếu còn thời gian.

| Tiện ích | Ai dùng, lúc nào | Cách kiểm thử | Ưu tiên |
| --- | --- | --- | --- |
| Check-in bằng QR xoay vòng 30 giây | Điều phối viên chiếu mã tại điểm tập kết; tình nguyện viên quét bằng điện thoại. Chống điểm danh hộ. | Mã hết hạn không nhận; mã của ca khác không nhận; quét hai lần chỉ tính một | P1 |
| Sổ giờ tình nguyện và chứng nhận PDF có mã xác thực | Sinh viên cần giờ công tác xã hội hoặc điểm rèn luyện cuối kỳ; cán bộ Đoàn duyệt. | Số giờ bằng tổng ca đã check-in; mã xác thực sai trả “không tồn tại” | P1 |
| Xuất lịch cá nhân .ics | Tình nguyện viên nhập vào lịch điện thoại sau khi được phân công. | Tệp phân tích được, múi giờ đúng, đổi ca thì tệp mới cập nhật | P1 |
| Hàng chờ dự bị tự động | Khi ca đầy mà có người rút, người dự bị đúng điều kiện được mời trước. | Thứ tự dự bị đúng quy tắc; người dự bị thiếu kỹ năng không được mời | P1 |
| Nhắc lịch (email, thông báo trình duyệt) trước 24 giờ và 2 giờ | Tình nguyện viên tránh quên ca; giảm vắng. | Tác vụ hẹn giờ chạy trên dữ liệu mẫu, đồng hồ giả trong kiểm thử | P1 |
| Nhập CSV có báo lỗi từng dòng | Ban tổ chức nạp danh sách có sẵn từ Excel. | Tệp sai định dạng, thiếu cột, trùng mã đều báo đúng dòng | P1 |
| Thời gian đệm di chuyển giữa hai ca liền nhau | Người điều phối xếp hai ca ở hai địa điểm khác nhau; thuật toán tính khoảng cách đường chim bay rồi quy đổi ra phút. | Hai ca cách 15 phút nhưng cách 12 km bị báo cảnh báo | P2 |
| Bảng công bằng ca | Điều phối viên thấy ai đang gánh nhiều ca hơn trung bình để chia lại. | Chỉ số độ lệch chuẩn số ca tính đúng trên dữ liệu mẫu | P1 |
| Nhật ký kiểm toán | Quản trị Đoàn tra ai sửa gì, lúc nào, vì sao. | Mỗi thao tác sửa đều có dòng nhật ký; không có API xóa nhật ký | P1 |
| PWA chạy offline cho check-in | Khi điểm tập kết sóng kém, điều phối viên vẫn điểm danh; dữ liệu đồng bộ khi có mạng. | Ngắt mạng, điểm danh, bật mạng, không mất và không trùng bản ghi | P2 |
| Đánh giá sau hoạt động (3 câu) | Ban tổ chức cải thiện lần sau; AI tóm tắt ý kiến. | Mỗi tình nguyện viên chỉ đánh giá ca mình đã tham gia | P2 |
| Mốc giờ tình nguyện (10, 50, 100 giờ) | Đoàn xã có cơ sở khen thưởng; tình nguyện viên thấy tiến độ. | Mốc chỉ tính từ giờ đã check-in | P2 |
| Song ngữ Việt – Anh, chế độ tối, hỗ trợ bàn phím và đọc màn hình | Sinh viên quốc tế và người dùng cần hỗ trợ tiếp cận. | Lighthouse Accessibility từ 90; mọi chuỗi nằm trong tệp dịch | P1 |

**Các thứ nhóm cố ý không làm**, vì không ai dùng trong bối cảnh này hoặc có sẵn công cụ tốt hơn: mạng xã hội nội bộ, chat nhóm (dùng Zalo sẵn có), thanh toán, blockchain cho chứng nhận, xếp hạng tình nguyện viên theo điểm (gây cạnh tranh sai mục đích).

## 6. Tích hợp AI

AI trong Xanh Hub chỉ đề xuất, không tự quyết định. Thuật toán phân công và bộ kiểm tra ràng buộc chạy bằng mã xác định, nhóm giải thích được từng dòng. Điều này giữ đúng trọng tâm chấm điểm của đề (thuật toán phải do nhóm viết, giải thích và kiểm thử được) và giúp hệ thống vẫn đủ chức năng khi tắt AI.

### Bảy tính năng AI

| # | Tính năng | Làm gì | Kỹ thuật | Đo chất lượng |
| --- | --- | --- | --- | --- |
| A1 | Trợ lý tạo ca bằng tiếng Việt tự nhiên | Điều phối viên gõ "Cần 6 người dựng lều sáng thứ Bảy 7h–11h, 2 người biết sơ cứu"; hệ thống sinh biểu mẫu ca để xem lại rồi xác nhận | LLM chạy cục bộ qua Ollama (mô hình mở) hoặc API; đầu ra ép theo JSON Schema; dự phòng bằng bộ phân tích quy tắc cho mẫu câu phổ biến | 50 câu mẫu có đáp án; mục tiêu đúng trên 90% trường; sai thì người sửa được |
| A2 | Dự đoán khả năng vắng | Đánh dấu tình nguyện viên có nguy cơ vắng cao để chọn dự bị sớm hơn, nhắc sớm hơn | Hồi quy logistic (thư viện Rubix ML cho PHP) với đặc trưng: tỷ lệ vắng trước đây, khoảng cách, ca sáng sớm, số ca trong tuần, độ trễ xác nhận | AUC trên tập kiểm tra giữ riêng; hiển thị 3 đặc trưng ảnh hưởng nhất cho mỗi dự đoán |
| A3 | Xếp hạng người thay thế | Khi có báo vắng, gợi ý 5 người thay, kèm điểm và lý do | Điểm có thể giải thích: kỹ năng, khoảng cách, tải hiện tại, xác suất vắng (A2), lịch sử tham gia việc tương tự; đã lọc người vi phạm ràng buộc trước khi xếp hạng | Kiểm thử: không bao giờ gợi ý người trùng lịch hoặc thiếu kỹ năng |
| A4 | Giải thích phương án bằng lời | "Ca 3 còn thiếu 1 người biết sơ cứu vì chỉ có 2 người có kỹ năng này và cả hai đã có ca trùng giờ" | Mẫu câu xác định từ dữ liệu; LLM chỉ được viết lại cho mượt, không được thêm số liệu | So khớp số liệu trong câu với bảng kết quả; lệch thì dùng câu mẫu gốc |
| A5 | Gợi ý hoạt động cho tình nguyện viên | Trang chủ gợi ý 3 hoạt động hợp kỹ năng và lịch rảnh | Độ tương đồng cosine giữa vectơ kỹ năng/sở thích và vectơ yêu cầu hoạt động | Tỷ lệ gợi ý trùng với đăng ký thực trên dữ liệu mẫu |
| A6 | Tóm tắt ý kiến sau hoạt động | Gộp phản hồi thành 3 điểm tốt, 3 điểm cần sửa | LLM cục bộ; luôn kèm liên kết ngược đến phản hồi gốc | Người đọc kiểm 10 bản tóm tắt mẫu |
| A7 | Hỏi đáp về dữ liệu của chính mình | "Tuần này tôi có ca nào?" "Ca nào còn thiếu người?" | LLM chỉ được gọi một danh sách hàm đã duyệt sẵn (truy vấn có tham số), không sinh SQL tự do; quyền theo đúng người hỏi | Thử tấn công chèn lệnh: hỏi dữ liệu người khác phải bị từ chối |

### Rào chắn an toàn cho AI

- AI không ghi trực tiếp vào lịch. Mọi đề xuất đi qua bộ kiểm tra ràng buộc rồi người phụ trách bấm xác nhận.
- Tắt công tắc AI trong cài đặt thì hệ thống vẫn đủ năm chức năng; phần AI chỉ là lớp thêm.
- Ưu tiên mô hình chạy cục bộ để không phải trả phí và không gửi dữ liệu ra ngoài. Nếu dùng API ngoài, chỉ gửi dữ liệu đã bỏ tên và liên hệ.
- Mọi đầu ra của mô hình ngôn ngữ được ép theo JSON Schema và kiểm hợp lệ trước khi dùng.
- Chống chèn lệnh: nội dung do người dùng nhập luôn được đánh dấu là dữ liệu, không đưa vào phần hướng dẫn của mô hình.
- Ghi rõ trên giao diện đâu là gợi ý của AI, kèm nút "vì sao?".
- Dữ liệu huấn luyện A2 là dữ liệu giả sinh theo quy luật đã công bố; README nói rõ mô hình chỉ để minh họa, chưa dùng cho quyết định thật.

**Yêu cầu duyệt:** toàn bộ AI trên được viết bằng PHP (Rubix ML cho A2, A5) và gọi Ollama qua HTTP (A1, A4, A6, A7) nên không vượt khỏi yêu cầu "PHP và PostgreSQL/MySQL" của đề. Ollama là dịch vụ phụ trợ chạy riêng, vẫn nên hỏi giảng viên khi nộp đề cương.

## 7. Thuật toán phân công và cách so sánh

Thuật toán nhóm tự viết xếp tình nguyện viên vào ca bằng ba bước: lọc khả thi, gán theo độ khan hiếm, rồi cải thiện bằng chuỗi đổi chỗ. Đây là phần chấm "Yêu cầu kỹ thuật 1" và "2" nên được viết thuần PHP, không gọi AI.

### Bài toán

Cho tập tình nguyện viên V và tập ca S. Mỗi ca s có khoảng thời gian, danh sách vị trí (mỗi vị trí đòi một kỹ năng và cấp tối thiểu), số người tối thiểu, tối đa và độ ưu tiên. Mỗi tình nguyện viên v có lịch rảnh, kỹ năng, nguyện vọng và số ca tối đa mỗi tuần.

**Ràng buộc cứng** (vi phạm là không được phép, không đánh đổi):

1. H1. Không được gán hai ca trùng giờ, tính cả thời gian đệm di chuyển giữa hai địa điểm.
2. H2. Người được gán vào vị trí phải có kỹ năng đó đạt cấp tối thiểu.
3. H3. Số người trong ca không vượt mức tối đa.
4. H4. Ca nằm trong lịch rảnh đã khai.
5. H5. Số ca mỗi tuần không vượt mức tối đa của người đó.

**Mục tiêu mềm** (cân bằng bằng trọng số): lấp đầy nhiều vị trí nhất, ưu tiên ca quan trọng, theo nguyện vọng, chia đều số ca, giảm đi lại, tránh người có nguy cơ vắng cao ở ca then chốt.

### Điểm của một cặp (người, ca)

```latex
\text{score}(v,s)=w_{p}\,\text{pri}_s + w_{n}\,\text{pref}_{v,s} + w_{k}\,\text{skillSurplus}_{v,s} - w_{l}\,\text{load}_v - w_{d}\,\text{dist}_{v,s} - w_{a}\,P(\text{absent}_v)
```

Ba chế độ là ba bộ trọng số được lưu sẵn:

| Chế độ | Dùng khi | Trọng số nổi bật |
| --- | --- | --- |
| Đủ người nhất | Sắt ngày diễn ra, cần lấp kín ca | wₚ và wₖ cao, wₗ thấp |
| Công bằng | Hoạt động dài ngày, nhiều người tự nguyện | wₗ cao, phạt người đang gánh nhiều |
| Theo nguyện vọng | Giữ chân người tham gia | wₙ cao |

### Quy trình ba bước

1. **Lọc khả thi.** Dựng ma trận người × vị trí chỉ chứa cặp thỏa H2, H4. Nhờ đó đến bước sau, không bao giờ thử gán một cặp vô lý.
2. **Gán theo độ khan hiếm.** Sắp ca theo tỉ lệ (số ứng viên khả thi) / (số người cần), ca khan nhất xử lý trước. Trong mỗi ca, chọn người điểm cao nhất mà vẫn thỏa H1, H3, H5; hòa điểm thì xét mã tăng dần để kết quả lặp lại được.
3. **Chuỗi đổi chỗ.** Với mỗi vị trí còn thiếu, tìm người khả thi nhưng đang ở ca trùng giờ; nếu ca đó có người khác thay được thì chuyển, lặp theo đường tăng (cùng ý với ghép cặp Kuhn), tối đa bốn bước để khống chế thời gian chạy. Kết thúc khi không còn chuỗi nào cải thiện được.

Vị trí nào vẫn thiếu sau bước 3 được ghi vào danh sách **chưa bố trí** kèm mã lý do: `NO_SKILL`, `NO_AVAILABLE`, `TIME_CONFLICT`, `LOAD_LIMIT`. Tình nguyện viên không được gán cũng có mã lý do tương ứng.

**Ví dụ số nhỏ cho phần giải thích trước thầy cô.** Ca A (sơ cứu, 08:00–11:00, cần 1 người) và ca B (hậu cần, 09:00–12:00, cần 1 người). Linh biết cả sơ cứu và hậu cần; Phúc chỉ biết hậu cần. Nếu Linh đăng ký ca B trước, cách gán theo thứ tự đăng ký cho Linh vào B, ca A thiếu người vì Linh đã bận. Thuật toán của nhóm thấy A chỉ có 1 ứng viên nên xử lý trước, gán Linh vào A rồi gán Phúc vào B: cả hai ca đủ người.

### Cách so sánh với cách gán theo thứ tự đăng ký

Trên cùng dữ liệu và cùng hạt ngẫu nhiên, hệ thống chạy ba phương án:

| Phương án | Mô tả | Vì sao có |
| --- | --- | --- |
| B0. Theo thứ tự đăng ký thuần | Duyệt đơn theo thời gian, gán nếu ca chưa đầy, không kiểm tra kỹ năng và trùng giờ | Đúng cách đề yêu cầu so sánh; cho thấy số vi phạm |
| B1. Theo thứ tự đăng ký có kiểm tra | Như B0 nhưng bỏ đơn vi phạm | Để so sánh công bằng: không chỉ đấu với "người rơm" |
| X. Thuật toán Xanh Hub | Ba bước ở trên, chạy ở ba chế độ | Phương án của nhóm |

Bốn số đo báo cáo: tỷ lệ ca đủ người (ca đạt mức tối thiểu chia tổng ca), tỷ lệ vị trí đã lấp, số vi phạm chia theo loại (trùng giờ, thiếu kỹ năng, vượt số người), và chênh lệch số ca (độ lệch chuẩn cùng hiệu số lớn nhất trừ nhỏ nhất). Mỗi lần chạy lưu cấu hình JSON (chế độ, trọng số, hạt, phiên bản mã) và mã bam SHA-256 của dữ liệu đầu vào, để người chấm chạy lại ra cùng kết quả.

Mục tiêu phải đạt trên ba bộ dữ liệu bắt buộc: B0 có vi phạm (nếu không có, bộ dữ liệu chưa đủ khó và phải sửa); B1 không vi phạm; X không vi phạm và tỷ lệ ca đủ người không thấp hơn B1. Kết quả thật sẽ ghi nguyên vào báo cáo, kể cả khi không đẹp.

## 8. Công nghệ và kiến trúc

Xanh Hub giữ đúng nền PHP và PostgreSQL mà đề yêu cầu, tách ba lớp rõ ràng (giao diện, xử lý, lưu trữ) và dùng các công nghệ hiện đại ở phía trình duyệt. Cột "Lý do" giải thích vì sao chọn, để mỗi thành viên trả lời được khi vấn đáp.

&#91;embedded content: kiến trúc ba lớp · 8 thành phần\]

Yêu cầu PHP và PostgreSQL giữ nguyên; AI và hàng đợi là hai thành phần tùy chọn ở bên ngoài.

| Thành phần | Công nghệ | Lý do |
| --- | --- | --- |
| Ngôn ngữ máy chủ | PHP 8.3 với kiểu dữ liệu chặt | Đúng yêu cầu đề; PHPStan mức 8 bắt lỗi kiểu |
| Khung máy chủ | Slim 4 và PHP-DI | Khung mỏng, mã nhóm tự viết chiếm phần lớn, dễ phân biệt "mã tự viết" và "thư viện" như đề yêu cầu |
| Cơ sở dữ liệu | PostgreSQL 16 | Ràng buộc loại trừ trên khoảng thời gian (`EXCLUDE USING gist`) chặn trùng giờ ở tầng dữ liệu, thêm một lớp chắc chắn ngoài mã PHP |
| Truy cập dữ liệu | PDO và lớp Repository tự viết | Không để ORM che mất câu lệnh; mọi tham số đều ràng buộc |
| Chống đua tranh | Giao dịch có `SELECT ... FOR UPDATE` trên ca | Hai điều phối viên cùng thả người vào ca còn 1 chỗ: chỉ một người thành công |
| Hàng đợi công việc | Bảng jobs trong PostgreSQL và tiến trình nền chạy bằng cron | Gửi nhắc lịch, chạy thuật toán lớn; không cần thêm Redis |
| Cập nhật trực tiếp | Server-Sent Events, dự phòng polling 5 giây | Bảng ca đổi khi người khác sửa; đơn giản hơn WebSocket |
| Giao diện | Twig, htmx, Alpine.js, Vite | Trang dựng từ máy chủ, tương tác nhẹ, không cần SPA nặng |
| Chuyển động | GSAP, ScrollTrigger, Lenis | Điều khiển đồng hồ chuyển động, cuộn mượt |
| Đồ họa 3D | Three.js (tải lười) | Cảnh biển người áo xanh ở trang chủ và bản đồ nhiệt lịch rảnh 3D |
| Biểu đồ, bản đồ, kéo thả | Chart.js, Leaflet + OpenStreetMap, SortableJS | Thư viện mở, đã dùng rộng rãi |
| Đăng nhập | Phiên đăng nhập, Argon2id, CSRF, TOTP 2 bước cho điều phối viên | An toàn theo đề; TOTP dùng được không cần SMS |
| QR, PDF, Excel | endroid/qr-code, Dompdf, PhpSpreadsheet | Các thư viện PHP mã nguồn mở, ghi rõ trong bảng nguồn tái sử dụng |
| AI | Rubix ML, Ollama qua HTTP | Xem mục 6 |
| Kiểm thử | PHPUnit, Playwright, PHPStan, GitHub Actions | Xem mục 13 |
| Đóng gói | Docker Compose: nginx, php-fpm, postgres, mailpit, ollama (tùy chọn) | Chạy lại bằng một lệnh |

**Ba lớp mã nguồn** (đáp ứng "tách phần xử lý khỏi giao diện và lưu trữ"): thư mục `src/Http` chỉ nhận yêu cầu và trả kết quả; `src/Domain` chứa thuật toán và bộ kiểm tra ràng buộc và **không được** gọi `PDO` hay `$_POST`; `src/Infrastructure` là nơi duy nhất nói chuyện với cơ sở dữ liệu, thư viện ngoài, AI. Một quy tắc PHPStan tự viết báo lỗi nếu Domain nhập phần Infrastructure.

## 9. Thiết kế dữ liệu

Cơ sở dữ liệu có 20 bảng, chia thành bốn nhóm. Điểm đáng chú ý là mỗi phương án phân công gắn với một ảnh chụp dữ liệu đầu vào (có mã bam), đáp ứng tiêu chí "phương án đã lưu giữ đúng người, ca, kỹ năng và phiên bản dữ liệu đầu vào" của rubric.

| Nhóm | Bảng | Cột chính và ràng buộc |
| --- | --- | --- |
| Người dùng | `users` | email duy nhất, mật khẩu Argon2id, vai trò, bật TOTP |
|  | `volunteers` | hồ sơ, chi đoàn, `max_shifts_per_week` kiểm 1–14 |
|  | `skills`, `volunteer_skills` | kỹ năng và cấp 1–5; khóa chính kép (tình nguyện viên, kỹ năng) |
|  | `availability` | thứ, giờ bắt đầu, giờ kết thúc, cờ lặp; kiểm giờ kết thúc lớn hơn giờ bắt đầu |
| Hoạt động | `activities` | tên, địa điểm có tọa độ, trạng thái, người phụ trách |
|  | `shifts` | `during` kiểu `tstzrange`; `min_people` nhỏ hơn hoặc bằng `max_people` |
|  | `shift_requirements` | ca, kỹ năng, cấp tối thiểu, số người |
| Đăng ký và phân công | `registrations` | tình nguyện viên, ca, nguyện vọng 1–3, thời điểm đăng ký (dùng cho cách gán theo thứ tự) |
|  | `dataset_snapshots` | mã bam SHA-256 và nội dung đầu vào đã đóng băng |
|  | `plans` | thuật toán, cấu hình JSON, hạt, snapshot, số đo, trạng thái đề xuất / đã áp dụng |
|  | `assignments` | kế hoạch, ca, người, trạng thái; ràng buộc loại trừ chặn một người có hai phân công đang hiệu lực trùng khoảng giờ |
|  | `unassigned` | vị trí hoặc người chưa bố trí kèm mã lý do |
| Vận hành | `absence_reports`, `waitlist` | lý do vắng, người thay, thứ tự dự bị |
|  | `checkins` | phân công, thời điểm quét, cách quét; duy nhất theo phân công |
|  | `certificates` | mã xác thực duy nhất, số giờ, người duyệt |
|  | `audit_log`, `jobs`, `notifications` | nhật ký chỉ chèn thêm, hàng đợi, thông báo |

**Ba điều cơ sở dữ liệu tự bảo vệ, kể cả khi mã PHP có lỗi:**

1. Ràng buộc `EXCLUDE USING gist (volunteer_id WITH =, during WITH &&)` trên `assignments` đang hiệu lực: không thể lưu hai ca trùng giờ cho cùng một người.
2. Trình kích hoạt kiểm số người tối đa của ca trước khi chèn phân công.
3. Vai trò cơ sở dữ liệu của ứng dụng bị thu hồi quyền `UPDATE` và `DELETE` trên `audit_log`; mỗi dòng chứa mã bam của dòng trước, sửa một dòng là gãy chuỗi và bị phát hiện.

**Dữ liệu mẫu** (toàn bộ là dữ liệu giả, sinh bằng script với hạt cố định):

| Bộ | Tình nguyện viên | Ca | Kỹ năng | Đặc điểm |
| --- | --- | --- | --- | --- |
| D1. Đủ người | 30 | 12 | 5 | Mọi ca đều có thể lấp đầy |
| D2. Thiếu người | 30 | 12 | 5 | 3 ca cần kỹ năng hiếm, không đủ ứng viên |
| D3. Trùng lịch nhiều | 30 | 12 | 5 | Nhiều ca chồng giờ, nhiều người đăng ký cả ca chồng |
| D4. Lớn (benchmark) | 300 | 60 | 12 | Kiểm độ trễ và sự khác biệt giữa các thuật toán |

## 10. Thiết kế giao diện và chuyển động

Nguyên tắc chủ đạo: **điện ảnh ở cổng vào, nhanh gọn ở chỗ làm việc.** Trang chủ và trang giới thiệu hoạt động là một thước phim cuộn được; bảng điều phối, nơi người dùng làm việc mỗi ngày, chỉ dùng chuyển động ngắn (120–250 ms) để báo trạng thái. Chuyển động đẹp mà chậm ở nơi cần thao tác nhanh sẽ thành rào cản.

Một bản demo chạy được của phần này đi kèm tài liệu (xem tin nhắn bàn giao): cảnh mở đầu 3D, cuộn mượt, bảng phân công kéo thả có kiểm tra ràng buộc thật, thuật toán chạy có hình ảnh và báo vắng thay người.

### Bảng màu lấy từ áo Đoàn

Màu chủ đạo là xanh dương tươi của áo thanh niên tình nguyện. Mã màu bên dưới là giá trị nhóm chọn, **chưa phải mã màu chính thức** (không có bảng màu công bố để dùng), sẽ được hiệu chỉnh bằng cách lấy mẫu trên ảnh áo thật ở mục 11.

| Token | Giá trị | Dùng cho |
| --- | --- | --- |
| `--xanh-ao` | `#1A94E4` | Màu thương hiệu, nút chính, trạng thái đã phân công |
| `--xanh-bien` | `#04213D` | Nền tối của cảnh điện ảnh |
| `--xanh-muc` | `#0A3A6B` | Tiêu đề, viền, nền thẻ tối |
| `--xanh-troi` | `#EAF5FD` | Nền sáng của bảng làm việc |
| `--vang-sao` | `#FFCD00` | Điểm nhấn rất ít: ngôi sao, mốc giờ, nút nổi bật |
| `--do-co` | `#DA251D` | Chỉ dùng cho vi phạm và cảnh báo, không dùng trang trí |
| `--xanh-la` | `#1FA971` | Trạng thái đủ người, đã xác nhận |

Quy tắc dùng màu: xanh áo chiếm phần lớn; vàng và đỏ chỉ xuất hiện ở nơi có ý nghĩa. Không dùng cờ đỏ sao vàng hoặc huy hiệu Đoàn làm họa tiết nền. Biểu tượng của Đoàn chỉ dùng khi được phép từ Đoàn trường, và ghi rõ ở README. Mọi cặp màu chữ nền đạt tương phản WCAG AA (4,5:1).

### Chữ

- Tiêu đề lớn và con số: Barlow Condensed (hỗ trợ đầy đủ dấu tiếng Việt), đậm, hơi nén, gợi chữ khẩu hiệu trên biểu ngữ.
- Nội dung: Be Vietnam Pro (thiết kế dành riêng cho tiếng Việt, dễ đọc chữ nhỏ).
- Số liệu và mã: JetBrains Mono, số thẳng hàng.
- Tải phông từ Google Fonts với `font-display: swap` và phông dự phòng hệ thống.

### Kịch bản thước phim trang chủ

Trang chủ được viết như một kịch bản phim ngắn: mỗi cảnh có một ý, một chuyển động chủ đạo, và chuyển cảnh theo vị trí cuộn (không tự chạy mất quyền điều khiển).

| Cảnh | Nội dung | Chuyển động và kỹ thuật |
| --- | --- | --- |
| 1. Mở đầu | Màn xanh biển, một chấm sáng nở thành hàng nghìn hạt bật lên, kết lại thành biển người áo xanh | Three.js, InstancedMesh, vài nghìn hạt điều khiển bằng shader; camera tiến chậm |
| 2. Khẩu hiệu | "Đúng người. Đúng ca. Đúng giờ." hiện từng chữ, làm nổi đúng người, đúng ca, đúng giờ | GSAP SplitText, mặt nạ cắt theo dòng, easing `expo.out` |
| 3. Con người | Ảnh thật trên khung rộng, chữ chạy ngang, trích câu trên hình | Ken Burns chậm, parallax hai lớp, nhuộm hai tông xanh, hạt phim nhẹ |
| 4. Thuật toán | Từng tình nguyện viên bay vào đúng ca, dây nối hiện rồi tắt, ô đủ người sáng xanh lá | Canvas hoặc Three.js; dữ liệu lấy từ kết quả thuật toán thật, không phải hoạt ảnh giả |
| 5. Con số | Số ca đủ người, số vi phạm bằng 0, độ lệch số ca giảm so với cách gán theo thứ tự | Đếm số theo cuộn, biểu đồ cột mọc lên |
| 6. Kêu gọi | Nút "Đăng ký ca đầu tiên" | Ngôi sao vàng quay nhẹ, nút phát sáng khi rê chuột |

### Nguyên tắc chuyển động

- Mỗi chuyển động phải dẫn mắt, báo trạng thái hoặc kể chuyện. Không có chuyển động chỉ để "cho đẹp".
- Cảnh lớn 0,8–1,6 giây với easing có gia tốc rõ; thao tác nhỏ 120–250 ms; độ trễ so le (stagger) 30–80 ms giữa các phần tử.
- Chỉ chuyển động thuộc tính `transform` và `opacity` để giữ 60 khung hình mỗi giây.
- Tôn trọng `prefers-reduced-motion`: tắt cảnh 3D, tắt cuộn mượt, thay bằng hiện dần.
- 3D tải lười, tự giảm độ phân giải khi khung hình tụt dưới 50 fps, dừng vẽ khi tab ẩn; không hỗ trợ WebGL thì hiện ảnh tĩnh.
- Chuyển động trong vùng làm việc: kéo thả có bóng đổ và ô hợp lệ sáng xanh, ô vi phạm rung nhẹ và hiện lý do bằng chữ, thuật toán chạy có thanh tiến độ, người thay bay vào chỗ trống khi báo vắng.

### Chuỗi công cụ thiết kế

| Mục đích | Công cụ | Ghi chú |
| --- | --- | --- |
| Vẽ khung giao diện, hệ token | Penpot (mã nguồn mở) | Hợp với môn học mã nguồn mở; xuất token ra CSS; Figma cũng được nếu nhóm quen |
| Chuyển động 2D, cuộn | GSAP, ScrollTrigger, Lenis | GSAP miễn phí nhưng giấy phép riêng, không phải MIT; ghi rõ ở bảng nguồn. Nếu giảng viên yêu cầu thuần OSI thì thay bằng Motion (MIT) hoặc anime.js (MIT) |
| 3D | Three.js dựng hình bằng mã | Không cần mô hình nặng; nếu cần nhân vật chi tiết thì dựng bằng Blender (mã nguồn mở) và xuất glTF |
| Xử lý ảnh | Squoosh hoặc ImageMagick | Nén sang WebP/AVIF, tạo nhiều cỡ `srcset`, ảnh xem trước mờ |
| Kiểm hiệu năng và tiếp cận | Lighthouse, axe | Mục tiêu trong mục 1 |

### Sơ đồ trang

Trang chủ → Đăng nhập → **Tình nguyện viên**: Bảng điều khiển, Hoạt động, Ca của tôi, Hồ sơ và lịch rảnh, Sổ giờ và chứng nhận · **Điều phối viên**: Bảng ca (kéo thả), Phòng thuật toán (chạy và so sánh), Báo vắng và thay người, Check-in, Thống kê và xuất danh sách · **Quản trị Đoàn**: Vai trò, Duyệt chứng nhận, Nhật ký, Cấu hình trọng số · **Công khai**: Xác thực chứng nhận.

## 11. Ảnh thật: danh mục, nguồn và giấy phép

Sản phẩm cần khoảng 24 ảnh thật về thanh niên tình nguyện. Phần khó không phải tìm ảnh mà là dùng được hợp pháp: ảnh trên báo và trang tin hầu hết có bản quyền, và một kho mã nguồn mở công khai không được chứa chúng nếu chưa được phép. Vì vậy danh mục dưới đây ghi luôn nguồn nên dùng.

**Thứ tự ưu tiên nguồn ảnh:**

1. Ảnh do chính nhóm hoặc Đoàn trường chụp tại hoạt động thật, có sự đồng ý bằng văn bản hoặc tin nhắn. Đây là nguồn tốt nhất vì đúng áo, đúng bối cảnh và không vướng bản quyền.
2. Ảnh đã được cấp phép tự do trên Wikimedia Commons (giấy phép CC BY, CC BY-SA hoặc phạm vi công cộng), Unsplash, Pexels. Kiểm tra thẻ giấy phép từng ảnh, ghi tác giả, nguồn, giấy phép vào `assets/photos/CREDITS.md`.
3. Ảnh trên báo (Tuổi Trẻ, Thanh Niên, Tiền Phong, cổng thông tin của Trung ương Đoàn): chỉ dùng khi xin được phép từ tòa soạn hoặc tác giả. Nếu chưa có phép, chỉ liên kết tới bài gốc và không đưa tệp ảnh vào kho mã.
4. Quyền hình ảnh của người trong ảnh: theo Điều 32 Bộ luật Dân sự 2015, dùng hình ảnh của người khác cần có sự đồng ý. Ảnh có trẻ em hoặc khuôn mặt rõ nên làm mờ khi chưa có đồng ý.

**Danh mục ảnh cần có (shot list):**

| Chủ đề ảnh | Số ảnh | Dùng ở đâu | Gợi ý tìm |
| --- | --- | --- | --- |
| Lễ ra quân, biển áo xanh nhìn từ trên cao | 3 | Cảnh 1 và nền trang chủ | "lễ ra quân chiến dịch thanh niên tình nguyện hè" |
| Mùa hè xanh: dạy học, xây cầu, làm đường vùng cao | 4 | Cảnh 3, trang chi tiết hoạt động | "mùa hè xanh sinh viên tình nguyện vùng cao" |
| Tiếp sức mùa thi: hướng dẫn thí sinh, trạm nước, phát áo mưa | 3 | Hoạt động mẫu và trang Ca của tôi | "tiếp sức mùa thi áo xanh" |
| Hiến máu tình nguyện, người hướng dẫn áo xanh | 2 | Kỹ năng sơ cứu, hậu cần | "hiến máu tình nguyện thanh niên" |
| Chủ nhật xanh, dọn rác, trồng cây | 4 | Hoạt động mẫu, bộ dữ liệu D1 | "chủ nhật xanh thanh niên dọn vệ sinh" |
| Xuân tình nguyện: gói bánh, tặng quà Tết | 2 | Hoạt động mẫu | "xuân tình nguyện tặng quà Tết" |
| Cận cảnh áo xanh, huy hiệu, lng áo in chữ | 3 | Lấy mẫu màu, nền phụ | Ảnh tự chụp áo của Chi đoàn là tốt nhất |
| Hậu trường: dựng lều, điểm danh, họp phân công | 3 | Trang Điều phối, Check-in | Ảnh tự chụp tại buổi họp của nhóm |

**Trong phiên làm việc này:** mình đã tìm và hiển thị ảnh thật trong khung chat cho bạn tham khảo hướng chụp và bố cục. Những ảnh đó thuộc các tòa soạn nên chưa đưa vào sản phẩm. Môi trường làm việc của mình cũng không tải được tệp ảnh từ các trang đó về, nên bản demo dùng các "khung ảnh" đã dựng sẵn để bạn thả ảnh thật vào.

## 12. An toàn và dữ liệu

Quy tắc gốc theo đề: mọi kiểm tra quyền và mọi thay đổi lịch diễn ra ở máy chủ. Giao diện chỉ để gợi ý, không được tin. Bảng dưới đây gắn từng rủi ro với biện pháp và cách chứng minh.

| Rủi ro | Biện pháp | Cách kiểm chứng |
| --- | --- | --- |
| Tình nguyện viên gửi yêu cầu sửa phân công trực tiếp | Mỗi route đi qua middleware vai trò, rồi lớp Policy kiểm đối tượng cụ thể (điều phối viên đúng hoạt động mới được sửa) | Kiểm thử gửi `PUT /api/assignments/{id}` bằng tài khoản tình nguyện viên, mong đợi 403 |
| Xem hoặc sửa dữ liệu của người khác bằng cách đổi số trong đường dẫn | Truy vấn luôn kèm điều kiện chủ sở hữu | Kiểm thử đổi `id` sang bản ghi của người khác, mong đợi 404 |
| Thả người vượt số lượng khi hai người cùng thao tác | Giao dịch khóa dòng ca và trình kích hoạt ở cơ sở dữ liệu | Kiểm thử hai tiến trình cùng gán vào ca còn 1 chỗ |
| CSRF | Mã CSRF theo phiên, cookie `SameSite=Lax`, `HttpOnly`, `Secure` | Gửi biểu mẫu thiếu mã, mong đợi 419 |
| SQL injection | PDO câu lệnh tham số hóa, không nối chuỗi | Quét mã bằng PHPStan quy tắc cấm nối chuỗi SQL; thử chuỗi `' OR 1=1 --` |
| XSS | Twig tự thoát ký tự, đầu Content-Security-Policy chặt | Thử đặt tên hồ sơ là thẻ script, trang không chạy |
| Tải ảnh đại diện độc hại, đường dẫn lập lời (`../`) | Kiểm loại tệp thật, giới hạn dung lượng, mã hóa lại ảnh bằng GD, đặt tên ngẫu nhiên, lưu ngoài thư mục web | Thử tệp `.php` đổi đuôi `.jpg`, tên tệp chứa `../` |
| Đoán mật khẩu | Argon2id, giới hạn 5 lần sai mỗi 10 phút, 2 bước TOTP cho điều phối viên | Thử đăng nhập sai liên tiếp |
| Giả mạo check-in bằng ảnh chụp mã QR | Mã ký HMAC, đổi mỗi 30 giây, gắn đúng ca | Thử mã cũ sau 31 giây |
| Chèn công thức khi xuất Excel/CSV | Thêm dấu nháy đơn trước ô bắt đầu bằng `=`, `+`, `-`, `@` | Thử tên tình nguyện viên là `=HYPERLINK(...)` |
| Lộ mật khẩu hoặc khóa lên Git | `.env` nằm trong `.gitignore`, chỉ có `.env.example`; CI chạy gitleaks | CI đỏ nếu có chuỗi giống khóa bí mật |
| Sửa nhật ký để che dấu vết | Nhật ký chỉ chèn thêm, chuỗi mã bam | Thử sửa một dòng, lệnh kiểm chuỗi báo vỡ |

**Dữ liệu cá nhân.** Bài nộp chỉ chứa dữ liệu giả: tên sinh bằng bộ sinh tên Việt, email miền `@example.test`, số điện thoại dạng `0900 000 xxx`. Mỗi người dùng có nút xuất và xóa dữ liệu cá nhân của mình để sẵn sàng khi triển khai thật. Chứng nhận công khai chỉ hiển thị họ tên và số giờ, không hiển thị email, số điện thoại hay mã sinh viên.

## 13. Kế hoạch kiểm thử

Phần kiểm thử ăn 1,5 điểm gồm ba phần: có ca kiểm và đáp án, có kiểm thử tự động và tích hợp, có sửa lỗi kèm hồi quy. Kế hoạch dưới đây đáp ứng từng phần.

### Năm phép thử bắt buộc của đề

Mỗi phép thử có dữ liệu vào cố định trong `tests/fixtures` và đáp án mong đợi viết tay trong `tests/expected` (không sinh đáp án từ chính thuật toán đang thử).

| Phép thử | Bộ dữ liệu | Đáp án mong đợi |
| --- | --- | --- |
| 1. Không phân một người vào hai ca trùng giờ | D3 (nhiều trùng lịch) | Không người nào có hai phân công giao nhau; thử sửa tay vào ca trùng bị từ chối mã `TIME_CONFLICT`; chèn thẳng vào cơ sở dữ liệu bị ràng buộc loại trừ chặn |
| 2. Ca đòi kỹ năng không nhận người thiếu kỹ năng | D1, D2 | Mọi người ở ca có yêu cầu đều có kỹ năng đó đạt cấp tối thiểu; sửa tay bị từ chối mã `NO_SKILL` |
| 3. Sửa vượt số người của ca bị từ chối | D1 | Ca đầy, thêm người thứ `max+1` trả lỗi; thử cả qua giao diện và qua gọi API trực tiếp; thử hai tiến trình đồng thời |
| 4. Thiếu người thì báo rõ, không tạo người giả | D2 | Danh sách ca chưa đủ trùng danh sách kỳ vọng (3 ca); số người được phân không vượt 30; mọi mã người đều tồn tại trong bảng `volunteers` |
| 5. Báo cáo so sánh dùng cùng dữ liệu, lưu cấu hình | D1, D2, D3 | Ba phương án cùng mã bam dữ liệu đầu vào; cấu hình JSON có đủ chế độ, trọng số, hạt; chạy lại cho kết quả giống hệt |

### Kiểm thử thêm

- **Theo thuộc tính ngẫu nhiên (property-based).** Sinh 1.000 bộ dữ liệu ngẫu nhiên từ 1.000 hạt khác nhau, chạy thuật toán, kiểm những điều luôn đúng: không vi phạm H1–H5, không người nào trên mức tải, cùng dữ liệu và cùng hạt cho cùng kết quả. Hạt làm thử thất bại được in ra để chạy lại.
- **Biên.** Ca 0 người, ca một người, người không có lịch rảnh, người không có kỹ năng nào, hai ca sát nhau (kết thúc 10:00 và bắt đầu 10:00 không tính trùng), ca qua nửa đêm.
- **Luồng thay người.** Báo vắng sau hạn chót, người thay bị trùng giờ, không còn ứng viên, báo vắng hai lần.
- **AI.** 50 câu tạo ca có đáp án, 10 câu tấn công chèn lệnh, kiểm tính xác thực của câu giải thích.
- **Giao diện.** Playwright chạy hai tình huống demo từ đầu đến cuối trên Chromium; chụp ảnh để so sánh hồi quy giao diện ở ba độ rộng màn hình.
- **Hiệu năng.** Bộ dữ liệu D4 (300 người, 60 ca) chạy xong dưới 2 giây trên máy học thông thường.

### Sửa lỗi và hồi quy

Mỗi lỗi được ghi thành issue có các bước tái hiện. Trước khi sửa, viết một phép thử tái hiện lỗi và xác nhận nó đỏ; sửa xong phải xanh, và phép thử đó ở lại vĩnh viễn trong bộ kiểm thử. Tên nhánh và commit gắn số issue. Nhóm giữ bảng "lỗi đã sửa" trong `docs/BUGS.md` để đưa ra khi vấn đáp.

### Quy trình tự động (CI)

Mỗi lần đẩy mã lên GitHub, GitHub Actions chạy theo thứ tự: kiểm phong cách mã, PHPStan, PHPUnit trên PostgreSQL thật, Playwright, gitleaks. Nhánh `main` chỉ nhận gộp khi CI xanh và có ít nhất một người review. Mục tiêu độ phủ dòng lệnh của `src/Domain` từ 85%.

## 14. Mã nguồn mở và bàn giao

Phần này được 1,5 điểm nên chỉ việc làm đúng và đủ. Bảng dưới đây là danh sách kiểm tra trước ngày nộp.

| Tệp hoặc việc | Nội dung cần có |
| --- | --- |
| `LICENSE` | Giấy phép MIT cho mã nhóm tự viết. Ghi riêng giấy phép của ảnh và phông chữ |
| `README.md` | Tên đề tài, thành viên, phạm vi chức năng, cài đặt bằng `docker compose up`, nạp dữ liệu mẫu, chạy kiểm thử, hai luồng demo, phần nhóm tự viết và thư viện dùng lại |
| `CONTRIBUTING.md` | Quy ước nhánh, mẫu commit, cách mở issue và pull request, quy trình review |
| `THIRD_PARTY.md` | Bảng nguồn tái sử dụng: tên, phiên bản, giấy phép, dùng ở đâu (Slim, Twig, Rubix ML, GSAP, Three.js, ảnh, phông chữ) |
| `docs/` | Kiến trúc, thuật toán, dữ liệu, `BUGS.md`, hướng dẫn người dùng |
| `.github/` | Mẫu issue, mẫu pull request, workflow CI |
| Issue và review | Ít nhất 30 issue đã đóng, mỗi pull request có review từ người khác trong nhóm; nhóm review chéo lẫn nhau |
| Release | Thẻ `btl-nop-cuoi-ky`, tải gói mã nguồn đặt tên `BTL_NhomXX.zip`, giải nén trên máy sạch và cài theo README, ghi lại kết quả |
| Bộ dữ liệu chấm kín | Nộp riêng qua kênh giảng viên chỉ định; bộ công khai D1–D4 đủ để chạy lại ví dụ |
| Thông tin nhạy cảm | Không đưa mật khẩu hay tệp `.local` lên Git; chạy `gitleaks` trước khi tạo Release |

**Đóng góp cá nhân.** Mỗi thành viên phụ trách một mô-đun (mục 15) và có một trang "tôi đã làm gì" trong `docs/`, dẫn đến commit và pull request cụ thể. Trước vấn đáp, mỗi người phải tự sửa được một yêu cầu nhỏ trong mô-đun của mình (ví dụ đổi trọng số một chế độ, thêm một lý do chưa bố trí).

## 15. Lộ trình và phân công nhóm

Lộ trình kéo dài 10 tuần, từ 26/10/2026 đến 3/1/2027. Đây là giả định của mình vì hạn nộp do giảng viên công bố; khi có ngày thật, dời cả lộ trình theo mốc Release. Nguyên tắc sắp xếp: **lõi chấm điểm chạy được trước**, tiện ích và giao diện điện ảnh xây sau trên nền đã ổn định.

&#91;embedded content: lộ trình 10 tuần · 7 giai đoạn, 4 mốc\]

Lõi chấm điểm (CN1–5, KT1–2) chạy được vào 6/12. Từ 20/12 chỉ sửa lỗi và hoàn thiện, không thêm tính năng mới.

### Phân công ba thành viên

Nếu nhóm chỉ có hai người, gộp vai B và C, cắt tiện ích P2 và cảnh 4 của trang chủ.

| Vai | Phụ trách | Minh chứng khi vấn đáp |
| --- | --- | --- |
| A. Lõi thuật toán | `src/Domain`: bộ kiểm tra ràng buộc, thuật toán ba bước, hai phương án so sánh, mô hình dự đoán vắng (A2, A3), kiểm thử theo thuộc tính | Giải thích được từng bước thuật toán, sửa được trọng số hoặc thêm một lý do chưa bố trí tại chỗ |
| B. Hệ thống và dữ liệu | Đăng nhập và phân quyền, cơ sở dữ liệu và ràng buộc, API, đăng ký, báo vắng, QR, chứng nhận, .ics, nhắc lịch, nhật ký kiểm toán, CI, Docker | Chứng minh quyền ở máy chủ bằng kiểm thử, thêm một vai trò hoặc một bảng mới |
| C. Giao diện và AI | Thiết kế, chuyển động, 3D, ảnh, bảng kéo thả, biểu đồ, trợ lý AI (A1, A4–A7), kiểm thử Playwright | Giải thích cách cảnh 3D đọc dữ liệu thật, sửa được một chuyển động và một câu lệnh AI |

## 16. Kịch bản demo và bảo vệ

Buổi bảo vệ dự kiến 10 phút trình diễn và vấn đáp riêng từng người. Hai tình huống dưới đây bám đúng mục 4 của đề và dùng bộ dữ liệu D3 (nhiều trùng lịch) để thấy ràng buộc hoạt động.

### Tình huống 1. Tạo hoạt động → đăng ký → đề xuất → xác nhận (khoảng 5 phút)

1. Thảo (điều phối viên) tạo hoạt động "Tiếp sức mùa thi" với 12 ca; ca sơ cứu đặt yêu cầu kỹ năng sơ cứu cấp 2. Hoặc gõ một câu cho trợ lý AI tạo ca (A1) rồi sửa lại.
2. Linh (tình nguyện viên) cập nhật kỹ năng sơ cứu và lịch rảnh, đăng ký hai ca chồng giờ; hệ thống cảnh báo trùng giờ.
3. 30 tình nguyện viên mẫu đã đăng ký sẵn. Thảo bấm "Đề xuất phân công" ở chế độ Đủ người nhất; cảnh thuật toán chạy, mỗi tình nguyện viên bay vào ca.
4. Màn hình kết quả nêu số ca đủ, danh sách chưa bố trí kèm lý do, và bảng so sánh với B0 và B1.
5. Thảo kéo Linh sang ca trùng giờ: bị từ chối kèm lý do. Kéo một người vào ca đã đầy: bị từ chối.
6. Thảo xác nhận phương án; Linh nhận thông báo, bấm xác nhận, xuất lịch .ics.

### Tình huống 2. Báo vắng → chọn người thay → kiểm tra → cập nhật thống kê (khoảng 4 phút)

1. Phúc báo vắng ca 5 vì ốm. Chỗ của Phúc chuyển sang trạng thái trống, ca 5 hiện thiếu 1 người.
2. Hệ thống đưa danh sách người thay đã lọc điều kiện, xếp hạng kèm điểm và lý do (A3).
3. Thảo thử chọn một người trùng giờ từ tab "Tất cả tình nguyện viên": bị từ chối. Chọn người hợp lệ: người đó bay vào chỗ trống.
4. Bảng thống kê cập nhật ngay: ca 5 đủ người trở lại, tỷ lệ vắng tăng, độ lệch số ca được tính lại.
5. Điểm danh bằng QR xoay vòng; xuất danh sách tham gia PDF; cấp chứng nhận và xác thực bằng mã.

### Câu hỏi dự đoán khi vấn đáp

| Câu hỏi | Trả lời ngắn | Người trả lời |
| --- | --- | --- |
| Vì sao xử lý ca khan hiếm trước? | Để ca ít ứng viên không bị người đông ứng viên chiếm mất; ví dụ Linh ở mục 7 | A |
| Thuật toán có tìm được phương án tối ưu không? | Không đảm bảo; đề không yêu cầu. Nhóm đo bằng bốn số và so với hai cách gán theo thứ tự | A |
| Nếu tắt AI thì sao? | Năm chức năng chính vẫn đủ; AI chỉ là gợi ý | C |
| Hai điều phối viên cùng thả người vào ca còn 1 chỗ? | Khóa dòng trong giao dịch, người thứ hai nhận lỗi; có kiểm thử hai tiến trình | B |
| Làm sao biết cảnh 3D không phải hoạt ảnh giả? | Nó đọc đúng JSON kết quả thuật toán, thay dữ liệu thì cảnh đổi | C |
| Dữ liệu huấn luyện dự đoán vắng lấy từ đâu? | Dữ liệu giả sinh theo quy luật đã công bố; chỉ để minh họa, ghi ở README | A |

## 17. Rủi ro và tự chấm rubric

Rủi ro lớn nhất là làm quá nhiều phần phụ trong khi lõi chấm điểm chưa chắc. Đề nói rõ không lấy giao diện đẹp hay báo cáo dài để bù phần xử lý còn thiếu, nên lộ trình ở mục 15 đã đặt lõi lên trước.

| Rủi ro | Mức | Cách xử lý |
| --- | --- | --- |
| Phần mở rộng lấn thời gian của lõi | Cao | Khóa lõi vào 6/12; tiện ích P2 là thứ bị cắt đầu tiên |
| Thuật toán không hơn cách gán theo thứ tự có kiểm tra (B1) trên một bộ dữ liệu | Trung bình | Báo cáo đúng kết quả; phân tích vì sao; thêm bộ dữ liệu khó hơn. Không chỉnh dữ liệu cho đẹp kết quả |
| Mô hình ngôn ngữ chạy cục bộ chậm hoặc máy chấm không có GPU | Trung bình | AI tắt được, có bộ phân tích quy tắc dự phòng cho A1; A4 dùng câu mẫu khi không có mô hình |
| Cảnh 3D nặng, máy yếu giật | Trung bình | Tự giảm độ phân giải, có ảnh tĩnh dự phòng, tôn trọng giảm chuyển động |
| Ảnh vi phạm bản quyền hoặc quyền hình ảnh | Cao | Theo thứ tự ưu tiên nguồn ảnh ở mục 11; `CREDITS.md` bắt buộc; CI kiểm mỗi ảnh có dòng ghi nguồn |
| Thư viện chuyển động không thuần giấy phép OSI | Thấp | Ghi ở `THIRD_PARTY.md`; phương án thay bằng Motion hoặc anime.js |
| Một thành viên nghỉ hoặc lệch tiến độ | Trung bình | Mỗi mô-đun có người dự phòng, review chéo; họp đứng 15 phút mỗi tuần |
| Dữ liệu mẫu không đủ "thiếu người" thật sự | Thấp | Viết đáp án tay: biết trước 3 ca không thể đủ, kiểm tra đúng 3 ca đó |

### Tự chấm theo rubric của đề

Bảng dưới đây là mục tiêu và bằng chứng cần nộp, không phải cam kết điểm số. Điểm thật do giảng viên chấm.

| Tiêu chí | Điểm tối đa | Bằng chứng nhóm sẽ chuẩn bị |
| --- | --- | --- |
| Năm chức năng | 3,0 | Hai luồng demo chạy trọn; ảnh chụp từng chức năng; dữ liệu chạy xuyên từ CN1 đến CN5 |
| Kỹ thuật 1 (thuật toán xét đồng thời) | 1,0 | Mã `Scheduler`, tài liệu mục 7, kiểm thử H1–H5, ví dụ Linh – Phúc |
| Kỹ thuật 2 (so sánh với thứ tự đăng ký) | 1,0 | Trang báo cáo, bốn số đo, cấu hình JSON đã lưu trên D1–D3 |
| Dữ liệu và an toàn | 1,0 | Mã bam dữ liệu đầu vào gắn mỗi phương án; kiểm thử 403 khi gửi yêu cầu sửa trực tiếp |
| Kiểm thử, sửa lỗi | 1,5 | Năm phép thử mục 13, CI xanh, `docs/BUGS.md` có phép thử hồi quy |
| Mã nguồn mở, bàn giao | 1,5 | `LICENSE`, `THIRD_PARTY.md`, issue, review, Release cài lại được trên máy sạch |
| Đóng góp cá nhân | 1,0 | Trang "tôi đã làm gì" của từng người, lịch sử Git, sửa được một yêu cầu nhỏ khi vấn đáp |
