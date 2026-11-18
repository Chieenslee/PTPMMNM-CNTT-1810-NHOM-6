<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Service;

use PDO;

/**
 * Dịch vụ Bản đồ Địa danh Thực tế, Gợi ý Địa chỉ (Google Maps Places style)
 * & Reverse Geocoding cho Xanh Hub.
 *
 * Nguồn dữ liệu:
 * 1. Bảng CSDL PostgreSQL (`shifts`, `activities`) — 100% điểm trực hoạt động thực tế.
 * 2. Danh mục Địa danh Thực tế TP. Hà Nội & Lân cận (Gazetteer > 75 địa điểm chuẩn xác GPS).
 * 3. Hỗ trợ OpenStreetMap Nominatim Reverse Geocoding (kèm fallback ngoại tuyến tức thì).
 */
final class GeocodingService
{
    /**
     * Danh mục hơn 75 địa điểm thực tế tại TP. Hà Nội (Cơ sở Đoàn, Trường ĐH, THPT,
     * Bệnh viện, Bến xe, Nhà ga, Công viên, Hồ/Sông và Trung tâm các Quận/Huyện Hà Nội).
     *
     * @var list<array{name: string, address: string, district: string, category: string, lat: float, lng: float}>
     */
    public const VIETNAM_PLACES_GAZETTEER = [
        // --- CƠ SỞ ĐOÀN - HỘI & TRUNG TÂM THANH NIÊN HÀ NỘI ---
        ['name' => 'Thành Đoàn Hà Nội', 'address' => 'Số 14A Phan Chu Trinh, P. Phan Chu Trinh, Q. Hoàn Kiếm, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Cơ quan Đoàn', 'lat' => 21.0228, 'lng' => 105.8556],
        ['name' => 'Trung ương Đoàn TNCS Hồ Chí Minh', 'address' => 'Số 62 Bà Triệu, P. Hàng Bài, Q. Hoàn Kiếm, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Cơ quan Đoàn', 'lat' => 21.0218, 'lng' => 105.8495],
        ['name' => 'Cung Văn hóa Thể thao Thanh niên Hà Nội', 'address' => 'Số 1 Tăng Bạt Hổ, P. Phạm Đình Hổ, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Nhà Văn hóa', 'lat' => 21.0168, 'lng' => 105.8588],
        ['name' => 'Nhà Văn hóa Học sinh – Sinh viên Hà Nội', 'address' => 'Số 37 Trần Bình Trọng, P. Nguyễn Du, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Nhà Văn hóa', 'lat' => 21.0182, 'lng' => 105.8445],
        ['name' => 'Cung Thiếu nhi Hà Nội', 'address' => 'Số 36 Lý Thái Tổ, P. Lý Thái Tổ, Q. Hoàn Kiếm, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Trung tâm VH', 'lat' => 21.0282, 'lng' => 105.8550],
        ['name' => 'Cung Văn hóa Lao động Hữu nghị Việt Xô', 'address' => 'Số 91 Trần Hưng Đạo, P. Trần Hưng Đạo, Q. Hoàn Kiếm, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Trung tâm VH', 'lat' => 21.0235, 'lng' => 105.8438],

        // --- CÁC TRƯỜNG ĐẠI HỌC & THPT TẠI HÀ NỘI (ĐIỂM THI TIẾP SỨC MÙA THI) ---
        ['name' => 'Đại học Bách Khoa Hà Nội (HUST)', 'address' => 'Số 1 Đại Cồ Việt, P. Bách Khoa, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Trường Đại học', 'lat' => 21.0050, 'lng' => 105.8435],
        ['name' => 'Quảng trường Tạ Quang Bửu (ĐH Bách Khoa Hà Nội)', 'address' => 'Số 1 Đại Cồ Việt / Trần Đại Nghĩa, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Trường Đại học', 'lat' => 21.0058, 'lng' => 105.8442],
        ['name' => 'Đại học Kinh tế Quốc dân (NEU)', 'address' => '207 Giải Phóng, P. Đồng Tâm, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Trường Đại học', 'lat' => 20.9998, 'lng' => 105.8425],
        ['name' => 'Đại học Xây dựng Hà Nội (HUCE)', 'address' => '55 Giải Phóng, P. Đồng Tâm, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Trường Đại học', 'lat' => 21.0035, 'lng' => 105.8428],
        ['name' => 'Đại học Quốc gia Hà Nội (ĐHQGHN - Xuân Thủy)', 'address' => '144 Xuân Thủy, P. Dịch Vọng Hậu, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Trường Đại học', 'lat' => 21.0378, 'lng' => 105.7815],
        ['name' => 'Trường Đại học Công nghệ (UET - ĐHQGHN)', 'address' => 'Nhà E3, 144 Xuân Thủy, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Trường Đại học', 'lat' => 21.0382, 'lng' => 105.7827],
        ['name' => 'Đại học Ngoại ngữ (ULIS - ĐHQGHN)', 'address' => 'Số 2 Phạm Văn Đồng, P. Dịch Vọng Hậu, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Trường Đại học', 'lat' => 21.0394, 'lng' => 105.7810],
        ['name' => 'Đại học Khoa học Tự nhiên (HUS - ĐHQGHN)', 'address' => '334 Nguyễn Trãi, P. Thanh Xuân Trung, Q. Thanh Xuân, TP. Hà Nội', 'district' => 'Q. Thanh Xuân', 'category' => 'Trường Đại học', 'lat' => 20.9961, 'lng' => 105.8080],
        ['name' => 'Đại học Khoa học Xã hội & Nhân văn (USSH - ĐHQGHN)', 'address' => '336 Nguyễn Trãi, P. Thanh Xuân Trung, Q. Thanh Xuân, TP. Hà Nội', 'district' => 'Q. Thanh Xuân', 'category' => 'Trường Đại học', 'lat' => 20.9958, 'lng' => 105.8088],
        ['name' => 'Đại học Sư phạm Hà Nội (HNUE)', 'address' => '136 Xuân Thủy, P. Dịch Vọng Hậu, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Trường Đại học', 'lat' => 21.0368, 'lng' => 105.7832],
        ['name' => 'Học viện Báo chí và Tuyên truyền (AJC)', 'address' => '36 Xuân Thủy, P. Dịch Vọng Hậu, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Trường Đại học', 'lat' => 21.0367, 'lng' => 105.7885],
        ['name' => 'Đại học Thương mại (TMU)', 'address' => '79 Hồ Tùng Mậu, P. Mai Dịch, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Trường Đại học', 'lat' => 21.0369, 'lng' => 105.7745],
        ['name' => 'Đại học Ngoại thương Hà Nội (FTU)', 'address' => '91 Chùa Láng, P. Láng Thượng, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Trường Đại học', 'lat' => 21.0230, 'lng' => 105.8053],
        ['name' => 'Học viện Ngoại giao (DAV)', 'address' => '69 Chùa Láng, P. Láng Thượng, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Trường Đại học', 'lat' => 21.0238, 'lng' => 105.8045],
        ['name' => 'Đại học Giao thông Vận tải Hà Nội (UTC)', 'address' => 'Số 3 Cầu Giấy, P. Láng Thượng, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Trường Đại học', 'lat' => 21.0282, 'lng' => 105.8035],
        ['name' => 'Đại học Y Hà Nội (HMU)', 'address' => 'Số 1 Tôn Thất Tùng, P. Trung Tự, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Trường Đại học', 'lat' => 21.0033, 'lng' => 105.8304],
        ['name' => 'Đại học Thủy Lợi (TLU)', 'address' => '175 Tây Sơn, P. Trung Liệt, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Trường Đại học', 'lat' => 21.0072, 'lng' => 105.8248],
        ['name' => 'Học viện Ngân hàng (HVNH)', 'address' => '12 Chùa Bộc, P. Quang Trung, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Trường Đại học', 'lat' => 21.0085, 'lng' => 105.8280],
        ['name' => 'Học viện Công nghệ Bưu chính Viễn thông (PTIT)', 'address' => 'Km10 Đường Nguyễn Trãi, P. Mộ Lao, Q. Hà Đông, TP. Hà Nội', 'district' => 'Q. Hà Đông', 'category' => 'Trường Đại học', 'lat' => 20.9806, 'lng' => 105.7875],
        ['name' => 'Đại học Kiến trúc Hà Nội (HAU)', 'address' => 'Km10 Đường Nguyễn Trãi, P. Văn Quán, Q. Hà Đông, TP. Hà Nội', 'district' => 'Q. Hà Đông', 'category' => 'Trường Đại học', 'lat' => 20.9801, 'lng' => 105.7885],
        ['name' => 'Đại học Công nghiệp Hà Nội (HaUI)', 'address' => '298 Đường Cầu Diễn, P. Minh Khai, Q. Bắc Từ Liêm, TP. Hà Nội', 'district' => 'Q. Bắc Từ Liêm', 'category' => 'Trường Đại học', 'lat' => 21.0537, 'lng' => 105.7351],
        ['name' => 'Học viện Nông nghiệp Việt Nam (VNUA)', 'address' => 'Thị trấn Trâu Quỳ, Huyện Gia Lâm, TP. Hà Nội', 'district' => 'Huyện Gia Lâm', 'category' => 'Trường Đại học', 'lat' => 21.0042, 'lng' => 105.9318],
        ['name' => 'Đại học Luật Hà Nội (HLU)', 'address' => '87 Nguyễn Chí Thanh, P. Láng Hạ, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Trường Đại học', 'lat' => 21.0217, 'lng' => 105.8095],
        ['name' => 'Khu Đô thị ĐHQG Hà Nội tại Hòa Lạc', 'address' => 'Thạch Hòa, Huyện Thạch Thất, TP. Hà Nội', 'district' => 'Huyện Thạch Thất', 'category' => 'Khu Đại học', 'lat' => 21.0135, 'lng' => 105.5265],
        ['name' => 'THPT Chu Văn An Hà Nội', 'address' => 'Số 10 Thụy Khuê, P. Thụy Khuê, Q. Tây Hồ, TP. Hà Nội', 'district' => 'Q. Tây Hồ', 'category' => 'Điểm thi THPT', 'lat' => 21.0432, 'lng' => 105.8325],
        ['name' => 'THPT Chuyên Hà Nội – Amsterdam', 'address' => 'Số 1 Hoàng Minh Giám, P. Trung Hòa, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Điểm thi THPT', 'lat' => 21.0068, 'lng' => 105.7982],
        ['name' => 'THPT Việt Đức', 'address' => '47 Lý Thường Kiệt, P. Trần Hưng Đạo, Q. Hoàn Kiếm, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Điểm thi THPT', 'lat' => 21.0243, 'lng' => 105.8492],
        ['name' => 'THPT Kim Liên', 'address' => 'Số 1 Ngõ 4C Đặng Văn Ngữ, P. Trung Tự, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Điểm thi THPT', 'lat' => 21.0108, 'lng' => 105.8315],

        // --- BỆNH VIỆN & TRẠM Y TẾ HÀ NỘI ---
        ['name' => 'Bệnh viện Bạch Mai', 'address' => '78 Giải Phóng, P. Phương Mai, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Bệnh viện', 'lat' => 21.0028, 'lng' => 105.8405],
        ['name' => 'Bệnh viện Hữu nghị Việt Đức', 'address' => '40 Tràng Thi, P. Hàng Bông, Q. Hoàn Kiếm, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Bệnh viện', 'lat' => 21.0272, 'lng' => 105.8482],
        ['name' => 'Bệnh viện Đại học Y Hà Nội', 'address' => 'Số 1 Tôn Thất Tùng, P. Trung Tự, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Bệnh viện', 'lat' => 21.0021, 'lng' => 105.8295],
        ['name' => 'Bệnh viện Trung ương Quân đội 108', 'address' => 'Số 1 Trần Hưng Đạo, P. Bạch Đằng, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Bệnh viện', 'lat' => 21.0176, 'lng' => 105.8615],
        ['name' => 'Bệnh viện Nhi Trung ương', 'address' => '18/879 La Thành, P. Láng Thượng, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Bệnh viện', 'lat' => 21.0256, 'lng' => 105.8080],
        ['name' => 'Bệnh viện E Hà Nội', 'address' => '89 Trần Cung, P. Nghĩa Tân, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Bệnh viện', 'lat' => 21.0504, 'lng' => 105.7897],
        ['name' => 'Bệnh viện Đa khoa Xanh Pôn', 'address' => '12 Chu Văn An, P. Điện Biên, Q. Ba Đình, TP. Hà Nội', 'district' => 'Q. Ba Đình', 'category' => 'Bệnh viện', 'lat' => 21.0315, 'lng' => 105.8342],
        ['name' => 'Trạm Y tế Xã Yên Bài (Ba Vì)', 'address' => 'Xã Yên Bài, Huyện Ba Vì, TP. Hà Nội', 'district' => 'Huyện Ba Vì', 'category' => 'Trạm Y tế', 'lat' => 21.0450, 'lng' => 105.4580],

        // --- GIAO THÔNG, BẾN XE, NHÀ GA & SÂN BAY HÀ NỘI ---
        ['name' => 'Ga Hà Nội', 'address' => '120 Lê Duẩn, P. Cửa Nam, Q. Hoàn Kiếm / Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Nhà Ga', 'lat' => 21.0242, 'lng' => 105.8412],
        ['name' => 'Bến xe Mỹ Đình', 'address' => '20 Phạm Hùng, P. Mỹ Đình 2, Q. Nam Từ Liêm, TP. Hà Nội', 'district' => 'Q. Nam Từ Liêm', 'category' => 'Bến xe', 'lat' => 21.0285, 'lng' => 105.7782],
        ['name' => 'Bến xe Giáp Bát', 'address' => 'Km6 Đường Giải Phóng, P. Giáp Bát, Q. Hoàng Mai, TP. Hà Nội', 'district' => 'Q. Hoàng Mai', 'category' => 'Bến xe', 'lat' => 20.9802, 'lng' => 105.8415],
        ['name' => 'Bến xe Nước Ngầm', 'address' => 'Số 1 Ngọc Hồi, P. Hoàng Liệt, Q. Hoàng Mai, TP. Hà Nội', 'district' => 'Q. Hoàng Mai', 'category' => 'Bến xe', 'lat' => 20.9673, 'lng' => 105.8424],
        ['name' => 'Bến xe Gia Lâm', 'address' => 'Số 9 Ngô Gia Khảm, P. Ngọc Lâm, Q. Long Biên, TP. Hà Nội', 'district' => 'Q. Long Biên', 'category' => 'Bến xe', 'lat' => 21.0480, 'lng' => 105.8780],
        ['name' => 'Sân bay Quốc tế Nội Bài', 'address' => 'Xã Phú Minh, Huyện Sóc Sơn, TP. Hà Nội', 'district' => 'Huyện Sóc Sơn', 'category' => 'Sân bay', 'lat' => 21.2212, 'lng' => 105.8072],
        ['name' => 'Nút giao Ngã Tư Sở', 'address' => 'Tây Sơn – Nguyễn Trãi – Trường Chinh – Láng, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Giao thông', 'lat' => 21.0028, 'lng' => 105.8208],
        ['name' => 'Nút giao Cầu Giấy – Xuân Thủy', 'address' => 'Phường Dịch Vọng, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Giao thông', 'lat' => 21.0315, 'lng' => 105.7995],
        ['name' => 'Ga Metro Cát Linh', 'address' => 'Phố Cát Linh – Hào Nam, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Nhà Ga Metro', 'lat' => 21.0282, 'lng' => 105.8277],

        // --- CÔNG VIÊN, HỒ, TUYẾN SÔNG & ĐỊA ĐIỂM MÔI TRƯỜNG HÀ NỘI ---
        ['name' => 'Hồ Hoàn Kiếm / Không gian Đi bộ Hồ Gươm', 'address' => 'Đinh Tiên Hoàng, P. Tràng Tiền, Q. Hoàn Kiếm, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Trung tâm Hà Nội', 'lat' => 21.0285, 'lng' => 105.8542],
        ['name' => 'Quảng trường Ba Đình – Lăng Chủ tịch Hồ Chí Minh', 'address' => 'Số 2 Hùng Vương, P. Điện Biên, Q. Ba Đình, TP. Hà Nội', 'district' => 'Q. Ba Đình', 'category' => 'Di tích / Trung tâm', 'lat' => 21.0368, 'lng' => 105.8347],
        ['name' => 'Vườn hoa Lê Nin (Công viên Chi Lăng)', 'address' => 'Đường Điện Biên Phủ – Trần Phú, Q. Ba Đình, TP. Hà Nội', 'district' => 'Q. Ba Đình', 'category' => 'Công viên', 'lat' => 21.0318, 'lng' => 105.8405],
        ['name' => 'Công viên Thống Nhất', 'address' => 'Đường Trần Nhân Tông – Lê Duẩn, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Công viên', 'lat' => 21.0170, 'lng' => 105.8430],
        ['name' => 'Công viên Thủ Lệ (Vườn Bách thú Hà Nội)', 'address' => 'Đường Bưởi – Kim Mã, P. Ngọc Khánh, Q. Ba Đình, TP. Hà Nội', 'district' => 'Q. Ba Đình', 'category' => 'Công viên', 'lat' => 21.0308, 'lng' => 105.8065],
        ['name' => 'Công viên Cầu Giấy', 'address' => 'Đường Thành Thái, P. Dịch Vọng, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Công viên', 'lat' => 21.0288, 'lng' => 105.7908],
        ['name' => 'Công viên Nghĩa Đô', 'address' => 'Đường Nguyễn Văn Huyên, P. Dịch Vọng, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Công viên', 'lat' => 21.0410, 'lng' => 105.7965],
        ['name' => 'Công viên Hòa Bình', 'address' => 'Đường Phạm Văn Đồng, P. Xuân Đỉnh, Q. Bắc Từ Liêm, TP. Hà Nội', 'district' => 'Q. Bắc Từ Liêm', 'category' => 'Công viên', 'lat' => 21.0645, 'lng' => 105.7868],
        ['name' => 'Công viên Yên Sở', 'address' => 'Nút giao Pháp Vân – Cầu Giẽ, P. Yên Sở, Q. Hoàng Mai, TP. Hà Nội', 'district' => 'Q. Hoàng Mai', 'category' => 'Công viên', 'lat' => 20.9635, 'lng' => 105.8545],
        ['name' => 'Đường Thanh Niên – Hồ Tây & Hồ Trúc Bạch', 'address' => 'Đường Thanh Niên, P. Yên Phụ, Q. Tây Hồ, TP. Hà Nội', 'district' => 'Q. Tây Hồ', 'category' => 'Tuyến Hồ Xanh', 'lat' => 21.0465, 'lng' => 105.8368],
        ['name' => 'Cầu Cót – Tuyến Sông Tô Lịch', 'address' => 'Đường Láng, P. Láng Thượng, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Tuyến Sông Xanh', 'lat' => 21.0112, 'lng' => 105.8095],
        ['name' => 'Tuyến Bờ sông Kim Ngưu', 'address' => 'Đường Kim Ngưu – Minh Khai, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Tuyến Sông Xanh', 'lat' => 20.9950, 'lng' => 105.8622],
        ['name' => 'Hồ Văn Quán (KĐT Văn Quán)', 'address' => 'Phường Văn Quán, Q. Hà Đông, TP. Hà Nội', 'district' => 'Q. Hà Đông', 'category' => 'Tuyến Hồ Xanh', 'lat' => 20.9815, 'lng' => 105.7875],

        // --- ĐIỂM MÙA HÈ XANH NGOẠI THÀNH & CÁC QUẬN / HUYỆN HÀ NỘI ---
        ['name' => 'Điểm trường Xã Ba Vì (Vườn Quốc gia Ba Vì)', 'address' => 'Xã Ba Vì, Huyện Ba Vì, TP. Hà Nội', 'district' => 'Huyện Ba Vì', 'category' => 'Địa bàn Huyện', 'lat' => 21.0725, 'lng' => 105.4250],
        ['name' => 'Xã Minh Phú (Huyện Sóc Sơn)', 'address' => 'Xã Minh Phú, Huyện Sóc Sơn, TP. Hà Nội', 'district' => 'Huyện Sóc Sơn', 'category' => 'Địa bàn Huyện', 'lat' => 21.2580, 'lng' => 105.8490],
        ['name' => 'Khu Di tích Cổ Loa (Huyện Đông Anh)', 'address' => 'Xã Cổ Loa, Huyện Đông Anh, TP. Hà Nội', 'district' => 'Huyện Đông Anh', 'category' => 'Di tích / Huyện', 'lat' => 21.1125, 'lng' => 105.8735],
        ['name' => 'Quận Hoàn Kiếm – Trung tâm Thủ đô Hà Nội', 'address' => 'Phường Hàng Trống, Q. Hoàn Kiếm, TP. Hà Nội', 'district' => 'Q. Hoàn Kiếm', 'category' => 'Quận/Huyện', 'lat' => 21.0285, 'lng' => 105.8542],
        ['name' => 'Quận Ba Đình – Trung tâm Hành chính Quốc gia', 'address' => 'Phường Điện Biên, Q. Ba Đình, TP. Hà Nội', 'district' => 'Q. Ba Đình', 'category' => 'Quận/Huyện', 'lat' => 21.0340, 'lng' => 105.8340],
        ['name' => 'Quận Đống Đa – Thái Hà & Chùa Láng', 'address' => 'Phường Quang Trung, Q. Đống Đa, TP. Hà Nội', 'district' => 'Q. Đống Đa', 'category' => 'Quận/Huyện', 'lat' => 21.0150, 'lng' => 105.8260],
        ['name' => 'Quận Hai Bà Trưng – Bách Khoa & Đại Cồ Việt', 'address' => 'Phường Bách Khoa, Q. Hai Bà Trưng, TP. Hà Nội', 'district' => 'Q. Hai Bà Trưng', 'category' => 'Quận/Huyện', 'lat' => 21.0080, 'lng' => 105.8510],
        ['name' => 'Quận Cầu Giấy – Xuân Thủy & Dịch Vọng', 'address' => 'Phường Dịch Vọng, Q. Cầu Giấy, TP. Hà Nội', 'district' => 'Q. Cầu Giấy', 'category' => 'Quận/Huyện', 'lat' => 21.0330, 'lng' => 105.7930],
        ['name' => 'Quận Thanh Xuân – Nguyễn Trãi & Royal City', 'address' => 'Phường Thanh Xuân Trung, Q. Thanh Xuân, TP. Hà Nội', 'district' => 'Q. Thanh Xuân', 'category' => 'Quận/Huyện', 'lat' => 20.9965, 'lng' => 105.8065],
        ['name' => 'Quận Tây Hồ – Lạc Long Quân & Hồ Tây', 'address' => 'Phường Xuân La, Q. Tây Hồ, TP. Hà Nội', 'district' => 'Q. Tây Hồ', 'category' => 'Quận/Huyện', 'lat' => 21.0690, 'lng' => 105.8180],
        ['name' => 'Quận Hoàng Mai – Linh Đàm & Giải Phóng', 'address' => 'Phường Hoàng Liệt, Q. Hoàng Mai, TP. Hà Nội', 'district' => 'Q. Hoàng Mai', 'category' => 'Quận/Huyện', 'lat' => 20.9715, 'lng' => 105.8435],
        ['name' => 'Quận Hà Đông – Quang Trung & Văn Quán', 'address' => 'Phường Quang Trung, Q. Hà Đông, TP. Hà Nội', 'district' => 'Q. Hà Đông', 'category' => 'Quận/Huyện', 'lat' => 20.9710, 'lng' => 105.7780],
        ['name' => 'Quận Nam Từ Liêm – Mỹ Đình & Mễ Trì', 'address' => 'Phường Mỹ Đình 1, Q. Nam Từ Liêm, TP. Hà Nội', 'district' => 'Q. Nam Từ Liêm', 'category' => 'Quận/Huyện', 'lat' => 21.0185, 'lng' => 105.7640],
        ['name' => 'Quận Bắc Từ Liêm – Cổ Nhuế & Cầu Diễn', 'address' => 'Phường Phúc Diễn, Q. Bắc Từ Liêm, TP. Hà Nội', 'district' => 'Q. Bắc Từ Liêm', 'category' => 'Quận/Huyện', 'lat' => 21.0510, 'lng' => 105.7600],
        ['name' => 'Quận Long Biên – Ngọc Lâm & Việt Hưng', 'address' => 'Phường Việt Hưng, Q. Long Biên, TP. Hà Nội', 'district' => 'Q. Long Biên', 'category' => 'Quận/Huyện', 'lat' => 21.0450, 'lng' => 105.8950],
    ];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /**
     * Chuẩn hóa chuỗi tiếng Việt về dạng không dấu chữ thường để tìm kiếm chính xác.
     */
    public static function normalizeVietnamese(string $str): string
    {
        $s = mb_strtolower(trim($str), 'UTF-8');
        $map = [
            'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
            'đ' => 'd',
        ];
        $s = strtr($s, $map);
        return (string) preg_replace('/\s+/', ' ', $s);
    }

    /**
     * Nạp danh sách địa điểm hợp nhất từ PostgreSQL (`activities`, `shifts`) + Gazetteer TP. Hà Nội.
     *
     * @return list<array{id: string, main_text: string, secondary_text: string, full_address: string, district: string, category: string, lat: float, lng: float, source: string}>
     */
    private function getAllMergedPlaces(): array
    {
        $places = [];
        $seenKeys = [];

        // 1. Nạp điểm trực thực tế từ PostgreSQL
        if ($this->pdo !== null) {
            try {
                $stmtShifts = $this->pdo->query("
                    SELECT s.id, s.code, s.title, s.location_name, s.lat, s.lng, a.code AS act_code
                      FROM shifts s
                      JOIN activities a ON a.id = s.activity_id
                     WHERE s.location_name IS NOT NULL AND TRIM(s.location_name) <> ''
                     ORDER BY s.id ASC
                ");
                foreach ($stmtShifts->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $sid = (int) $row['id'];
                    $lat = (float) $row['lat'];
                    $lng = (float) $row['lng'];
                    $loc = trim((string) $row['location_name']);
                    if (isset(GisService::CANONICAL_LOCATIONS[$sid])) {
                        $canon = GisService::CANONICAL_LOCATIONS[$sid];
                        if (abs($lat - GisService::DEFAULT_CENTER_LAT) < 0.0001 && abs($lng - GisService::DEFAULT_CENTER_LNG) < 0.0001) {
                            $lat = $canon['lat'];
                            $lng = $canon['lng'];
                        }
                    }
                    $key = self::normalizeVietnamese($loc);
                    if (!isset($seenKeys[$key])) {
                        $seenKeys[$key] = true;
                        $parts = array_map('trim', explode(',', $loc, 2));
                        $places[] = [
                            'id' => 'shift-' . $sid,
                            'main_text' => $parts[0] !== '' ? $parts[0] : $loc,
                            'secondary_text' => ($parts[1] ?? '') !== '' ? $parts[1] : ($row['act_code'] . ' · ' . $row['code'] . ' - ' . $row['title']),
                            'full_address' => $loc,
                            'district' => 'TP. Hà Nội',
                            'category' => 'Điểm trực CSDL (' . $row['code'] . ')',
                            'lat' => $lat,
                            'lng' => $lng,
                            'source' => 'DATABASE',
                        ];
                    }
                }
            } catch (\Throwable) {
            }
        }

        // 2. Bổ sung Gazetteer chuẩn > 75 địa danh TP. Hà Nội
        foreach (self::VIETNAM_PLACES_GAZETTEER as $idx => $g) {
            $key = self::normalizeVietnamese($g['name'] . ' ' . $g['address']);
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;
            $places[] = [
                'id' => 'gaz-' . ($idx + 1),
                'main_text' => $g['name'],
                'secondary_text' => $g['address'],
                'full_address' => $g['name'] . ', ' . $g['address'],
                'district' => $g['district'],
                'category' => $g['category'],
                'lat' => $g['lat'],
                'lng' => $g['lng'],
                'source' => 'GAZETTEER',
            ];
        }

        return $places;
    }

    /**
     * Gợi ý địa chỉ theo chuẩn Google Maps Places Autocomplete (không dấu & có dấu).
     *
     * @return list<array{id: string, main_text: string, secondary_text: string, full_address: string, category: string, lat: float, lng: float, distance_km: ?float, source: string}>
     */
    public function autocomplete(string $query, ?float $refLat = null, ?float $refLng = null, int $limit = 8): array
    {
        $all = $this->getAllMergedPlaces();
        $qNorm = self::normalizeVietnamese($query);
        $tokens = $qNorm !== '' ? array_values(array_filter(explode(' ', $qNorm), static fn ($t) => $t !== '')) : [];

        $scored = [];
        foreach ($all as $place) {
            $haystack = self::normalizeVietnamese($place['main_text'] . ' ' . $place['secondary_text'] . ' ' . $place['district'] . ' ' . $place['category']);
            $mainNorm = self::normalizeVietnamese($place['main_text']);

            if ($tokens === []) {
                $score = ($place['source'] === 'GAZETTEER') ? 50 : 40;
            } else {
                $allMatch = true;
                foreach ($tokens as $tok) {
                    if (!str_contains($haystack, $tok)) {
                        $allMatch = false;
                        break;
                    }
                }
                if (!$allMatch) {
                    continue;
                }
                $score = 10;
                if (str_starts_with($mainNorm, $qNorm)) {
                    $score += 100;
                } elseif (str_contains($mainNorm, $qNorm)) {
                    $score += 70;
                } elseif (str_contains($haystack, $qNorm)) {
                    $score += 40;
                }
            }

            $distKm = ($refLat !== null && $refLng !== null)
                ? GisService::haversineDistanceKm($refLat, $refLng, $place['lat'], $place['lng'])
                : null;

            $place['distance_km'] = $distKm;
            $place['_score'] = $score;
            $scored[] = $place;
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['_score'] !== $b['_score']) {
                return $b['_score'] <=> $a['_score'];
            }
            if ($a['distance_km'] !== null && $b['distance_km'] !== null && $a['distance_km'] !== $b['distance_km']) {
                return $a['distance_km'] <=> $b['distance_km'];
            }
            return strcmp($a['main_text'], $b['main_text']);
        });

        $results = array_slice($scored, 0, $limit);
        foreach ($results as &$r) {
            unset($r['_score']);
        }
        return $results;
    }

    /**
     * Phân giải ngược tọa độ GPS (Lat, Lng) thành tên đường & địa chỉ thực tế.
     *
     * @return array{ok: bool, full_address: string, place_name: string, district: string, lat: float, lng: float, distance_m: int, source: string}
     */
    public function reverseGeocode(float $lat, float $lng, bool $allowRemoteNominatim = true): array
    {
        $all = $this->getAllMergedPlaces();
        $nearest = null;
        $minKm = 999999.0;

        foreach ($all as $place) {
            $d = GisService::haversineDistanceKm($lat, $lng, $place['lat'], $place['lng']);
            if ($d < $minKm) {
                $minKm = $d;
                $nearest = $place;
            }
        }

        $distanceMeters = (int) round($minKm * 1000);

        // Nếu đứng rất gần một địa danh chuẩn (< 150m), dùng luôn địa danh thực tế chuẩn xác
        if ($nearest !== null && $distanceMeters <= 150) {
            return [
                'ok' => true,
                'full_address' => $nearest['full_address'],
                'place_name' => $nearest['main_text'],
                'district' => $nearest['district'],
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'distance_m' => $distanceMeters,
                'source' => $nearest['source'],
            ];
        }

        // Thử phân giải đường phố chi tiết qua OpenStreetMap Nominatim (timeout ngắn 1.2s)
        if ($allowRemoteNominatim && function_exists('curl_init')) {
            $nom = $this->queryNominatimReverse($lat, $lng);
            if ($nom !== null) {
                return [
                    'ok' => true,
                    'full_address' => $nom['full_address'],
                    'place_name' => $nom['place_name'],
                    'district' => $nom['district'],
                    'lat' => round($lat, 6),
                    'lng' => round($lng, 6),
                    'distance_m' => $distanceMeters,
                    'source' => 'OPENSTREETMAP_NOMINATIM',
                ];
            }
        }

        if ($nearest !== null) {
            $approxKm = round($minKm, 2);
            $label = $minKm <= 1.0
                ? "Gần {$nearest['main_text']} ({$distanceMeters}m) — {$nearest['district']}, TP. Hà Nội"
                : "Khu vực {$nearest['district']} (cách {$nearest['main_text']} {$approxKm} km), TP. Hà Nội";
            return [
                'ok' => true,
                'full_address' => $label,
                'place_name' => $nearest['main_text'],
                'district' => $nearest['district'],
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'distance_m' => $distanceMeters,
                'source' => 'GAZETTEER_FALLBACK',
            ];
        }

        return [
            'ok' => true,
            'full_address' => sprintf('%.4f° N, %.4f° E · TP. Hà Nội', $lat, $lng),
            'place_name' => 'TP. Hà Nội',
            'district' => 'TP. Hà Nội',
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
            'distance_m' => 0,
            'source' => 'COORDS',
        ];
    }

    /**
     * Phân giải chuỗi địa chỉ người dùng nhập tay thành tọa độ (lat, lng) gần đúng nhất.
     *
     * @return array{lat: float, lng: float, matched_address: string}|null
     */
    public function resolveAddressCoordinates(string $address): ?array
    {
        $clean = trim($address);
        if ($clean === '') {
            return null;
        }
        $matches = $this->autocomplete($clean, null, null, 1);
        if (!empty($matches)) {
            return [
                'lat' => $matches[0]['lat'],
                'lng' => $matches[0]['lng'],
                'matched_address' => $matches[0]['full_address'],
            ];
        }
        return null;
    }

    /**
     * @return array{full_address: string, place_name: string, district: string}|null
     */
    private function queryNominatimReverse(float $lat, float $lng): ?array
    {
        $url = sprintf(
            'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=%.6f&lon=%.6f&zoom=17&accept-language=vi',
            $lat,
            $lng
        );
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 800);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1200);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: XanhHub-Volunteer-System/1.0',
            'Accept: application/json',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            return null;
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || empty($data['display_name'])) {
            return null;
        }

        $addr = is_array($data['address'] ?? null) ? $data['address'] : [];
        $parts = array_values(array_filter([
            $addr['amenity'] ?? $addr['building'] ?? null,
            trim((string) (($addr['house_number'] ?? '') . ' ' . ($addr['road'] ?? ''))),
            $addr['suburb'] ?? $addr['quarter'] ?? $addr['neighbourhood'] ?? null,
            $addr['city_district'] ?? $addr['county'] ?? $addr['town'] ?? null,
            $addr['city'] ?? $addr['state'] ?? null,
        ], static fn ($v) => is_string($v) && trim($v) !== ''));

        $fullAddr = !empty($parts) ? implode(', ', $parts) : (string) $data['display_name'];
        $placeName = (string) ($parts[0] ?? 'Vị trí GPS');
        $district = (string) ($addr['city_district'] ?? $addr['county'] ?? $addr['town'] ?? 'TP. Hà Nội');

        return [
            'full_address' => $fullAddr,
            'place_name' => $placeName,
            'district' => $district,
        ];
    }
}
