-- ============================================================================
-- XANH HUB - MIGRATION 003: QUẢN LÝ BẢN TIN & TRUYỀN THÔNG ÁO XANH
-- ============================================================================

CREATE TABLE IF NOT EXISTS news (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    tag VARCHAR(100) NOT NULL DEFAULT 'Bản tin',
    summary TEXT NOT NULL,
    content TEXT,
    image_url VARCHAR(500) NOT NULL DEFAULT 'assets/images/campaign-d1.jpg',
    activity_id INT REFERENCES activities(id) ON DELETE SET NULL,
    author_id INT REFERENCES users(id) ON DELETE SET NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'PUBLISHED',
    published_at DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_news_status_published ON news (status, published_at DESC);

-- Chèn 4 bản tin mẫu mặc định nếu bảng chưa có dữ liệu
INSERT INTO news (id, tag, published_at, title, summary, content, image_url, activity_id, status)
VALUES
(
    1,
    'Tiếp Sức Mùa Thi',
    '2026-06-25',
    'Đội hình Sơ cứu Y tế & Tiếp sức Sĩ tử tại Cụm thi ĐH Bách Khoa TP.HCM',
    '30 tình nguyện viên có chứng chỉ sơ cấp cứu và hậu cần được phân bổ đều vào 12 ca trực không trùng lịch thi cá nhân, hỗ trợ kịp thời hơn 2.000 thí sinh.',
    'Chiến dịch Tiếp Sức Mùa Thi năm 2026 tại Cụm thi Trường Đại học Bách Khoa - ĐHQG TP.HCM đã diễn ra thành công tốt đẹp. Với sự tham gia của 30 chiến sĩ tình nguyện đạt chuẩn kỹ năng Sơ cứu Y tế và Hậu cần, đội hình đã túc trực tại tất cả các điểm thi, hỗ trợ phát nước uống, đồ ăn nhẹ và xử lý nhanh các tình huống sức khỏe cho phụ huynh và sĩ tử.',
    'assets/images/campaign-d2.jpg',
    2,
    'PUBLISHED'
),
(
    2,
    'Chủ Nhật Xanh',
    '2026-06-14',
    'Ra quân Cải tạo Cảnh quan Kênh Nhiêu Lộc & Trồng mới 500 Cây xanh Đô thị',
    'Chiến dịch cuối tuần thu hút đông đảo đoàn viên các Liên chi Đoàn tham gia làm sạch tuyến kênh và hướng dẫn người dân phân loại rác tại nguồn.',
    'Hưởng ứng Ngày Chủ Nhật Xanh toàn quốc, hơn 150 đoàn viên, thanh niên đã đồng loạt ra quân nạo vét rác thải ven bờ kênh Nhiêu Lộc - Thị Nghè, trồng mới 500 cây bàng Đài Loan và tuyên truyền nếp sống văn minh đô thị đến các hộ dân lân cận.',
    'assets/images/campaign-d1.jpg',
    1,
    'PUBLISHED'
),
(
    3,
    'Mùa Hè Xanh',
    '2026-06-18',
    'Ứng dụng Điểm danh QR Nhanh tại Hiện trường & Cấp Chứng nhận Tình nguyện Số',
    'Mỗi ca trực tại hiện trường đều được xác thực bằng mã QR động chống gian lận, giúp ghi nhận chính xác số giờ cống hiến để xét danh hiệu Sinh viên 5 Tốt.',
    'Hệ thống Xanh Hub ứng dụng công nghệ mã QR xoay vòng 30 giây bảo mật HMAC, cho phép tình nguyện viên điểm danh chính xác tại điểm trực và tự động đồng bộ giờ cống hiến vào hồ sơ rèn luyện cá nhân, cấp Giấy chứng nhận điện tử có chữ ký số xác thực.',
    'assets/images/campaign-d3.jpg',
    3,
    'PUBLISHED'
),
(
    4,
    'Gương mặt Áo Xanh',
    '2026-06-20',
    'Câu chuyện của Linh & Phúc: Khi Kỹ năng Chuyên môn Được Đặt Đúng Vị trí',
    'Nhờ cơ chế điều phối thông minh ưu tiên giữ người có kỹ năng Sơ cứu Y tế hiếm cho các chốt trọng điểm, mọi ca trực đều đủ định biên 100%.',
    'Thuật toán xếp ca thông minh Kuhn-Munkres kết hợp định biên kỹ năng đã chứng minh hiệu quả vượt trội trong việc phân công chiến sĩ Nguyễn Khánh Linh và Trần Hoàng Phúc vào các chốt sơ cấp cứu trọng điểm, đảm bảo mọi tình huống khẩn cấp đều có nhân lực chuyên môn túc trực.',
    'assets/images/hero-volunteer.jpg',
    3,
    'PUBLISHED'
)
ON CONFLICT (id) DO NOTHING;

SELECT setval('news_id_seq', (SELECT COALESCE(MAX(id), 1) FROM news));
