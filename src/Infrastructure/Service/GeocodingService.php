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
 * 2. Danh mục Địa danh Thực tế TP.HCM & Lân cận (Gazetteer > 90 địa điểm chuẩn xác GPS).
 * 3. Hỗ trợ OpenStreetMap Nominatim Reverse Geocoding (kèm fallback ngoại tuyến tức thì).
 */
final class GeocodingService
{
    /**
     * Danh mục hơn 90 địa điểm thực tế tại TP.HCM (Trường ĐH, Bệnh viện, Bến xe, Metro,
     * Công viên, Kênh rạch, Cơ sở Đoàn & Trung tâm 22 Quận/Huyện/TP. Thủ Đức).
     *
     * @var list<array{name: string, address: string, district: string, category: string, lat: float, lng: float}>
     */
    public const VIETNAM_PLACES_GAZETTEER = [
        // --- CƠ SỞ ĐOÀN - HỘI & TRUNG TÂM THANH NIÊN ---
        ['name' => 'Thành Đoàn TP. Hồ Chí Minh', 'address' => 'Số 1 Phạm Ngọc Thạch, P. Bến Nghé, Q. 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Cơ quan Đoàn', 'lat' => 10.7817, 'lng' => 106.6976],
        ['name' => 'Nhà Văn hóa Thanh niên TP.HCM', 'address' => 'Số 4 Phạm Ngọc Thạch, P. Bến Nghé, Q. 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Nhà Văn hóa', 'lat' => 10.7801, 'lng' => 106.6975],
        ['name' => 'Trụ sở Thành Đoàn (145 Pasteur)', 'address' => '145 Pasteur, P. Võ Thị Sáu, Q. 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Cơ quan Đoàn', 'lat' => 10.7820, 'lng' => 106.6940],
        ['name' => 'Nhà Văn hóa Sinh viên TP.HCM (Cơ sở ĐHQG)', 'address' => 'Quảng trường Sáng Tạo, Khu Đô thị ĐHQG, P. Đông Hòa, TP. Dĩ An / TP. Thủ Đức', 'district' => 'TP. Thủ Đức', 'category' => 'Nhà Văn hóa', 'lat' => 10.8752, 'lng' => 106.8007],
        ['name' => 'Nhà Văn hóa Sinh viên TP.HCM (CS Quận 3)', 'address' => '643 Điện Biên Phủ, P. 1, Q. 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Nhà Văn hóa', 'lat' => 10.7689, 'lng' => 106.6789],
        ['name' => 'Cung Văn hóa Lao động TP.HCM', 'address' => '55B Nguyễn Thị Minh Khai, P. Bến Thành, Q. 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Trung tâm VH', 'lat' => 10.7747, 'lng' => 106.6915],
        ['name' => 'Nhà Thiếu nhi TP. Hồ Chí Minh', 'address' => '36 Lê Quý Đôn, P. Võ Thị Sáu, Q. 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Trung tâm VH', 'lat' => 10.7809, 'lng' => 106.6910],

        // --- CÁC TRƯỜNG ĐẠI HỌC & THPT (ĐIỂM THI TIẾP SỨC MÙA THI) ---
        ['name' => 'Đại học Bách Khoa TP.HCM (Cơ sở 1)', 'address' => '268 Lý Thường Kiệt, P. 14, Q. 10, TP. Hồ Chí Minh', 'district' => 'Quận 10', 'category' => 'Trường Đại học', 'lat' => 10.7725, 'lng' => 106.6578],
        ['name' => 'Đại học Bách Khoa TP.HCM (Cơ sở 2 - Thủ Đức)', 'address' => 'Khu Đô thị ĐHQG TP.HCM, P. Linh Trung, TP. Thủ Đức', 'district' => 'TP. Thủ Đức', 'category' => 'Trường Đại học', 'lat' => 10.8805, 'lng' => 106.8062],
        ['name' => 'Đại học Khoa học Tự nhiên TP.HCM (CS1)', 'address' => '227 Nguyễn Văn Cừ, P. 4, Q. 5, TP. Hồ Chí Minh', 'district' => 'Quận 5', 'category' => 'Trường Đại học', 'lat' => 10.7629, 'lng' => 106.6823],
        ['name' => 'Đại học Khoa học Tự nhiên (Cơ sở Linh Trung)', 'address' => 'Khu Đô thị ĐHQG TP.HCM, P. Linh Trung, TP. Thủ Đức', 'district' => 'TP. Thủ Đức', 'category' => 'Trường Đại học', 'lat' => 10.8757, 'lng' => 106.7991],
        ['name' => 'Đại học Công nghệ Thông tin (UIT - ĐHQG)', 'address' => 'Khu phố 6, P. Linh Trung, TP. Thủ Đức, TP. Hồ Chí Minh', 'district' => 'TP. Thủ Đức', 'category' => 'Trường Đại học', 'lat' => 10.8700, 'lng' => 106.8030],
        ['name' => 'Đại học Kinh tế - Luật (UEL - ĐHQG)', 'address' => '669 Quốc lộ 1, Khu phố 3, P. Linh Xuân, TP. Thủ Đức', 'district' => 'TP. Thủ Đức', 'category' => 'Trường Đại học', 'lat' => 10.8707, 'lng' => 106.7782],
        ['name' => 'Đại học Khoa học Xã hội & Nhân văn (CS Quận 1)', 'address' => '10-12 Đinh Tiên Hoàng, P. Bến Nghé, Q. 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Trường Đại học', 'lat' => 10.7859, 'lng' => 106.7024],
        ['name' => 'Đại học Khoa học Xã hội & Nhân văn (CS Linh Trung)', 'address' => 'Khu phố 6, P. Linh Trung, TP. Thủ Đức, TP. Hồ Chí Minh', 'district' => 'TP. Thủ Đức', 'category' => 'Trường Đại học', 'lat' => 10.8732, 'lng' => 106.8022],
        ['name' => 'Đại học Quốc tế (IU - ĐHQG TP.HCM)', 'address' => 'Khu phố 6, P. Linh Trung, TP. Thủ Đức, TP. Hồ Chí Minh', 'district' => 'TP. Thủ Đức', 'category' => 'Trường Đại học', 'lat' => 10.8775, 'lng' => 106.8016],
        ['name' => 'Đại học Sư phạm TP.HCM', 'address' => '280 An Dương Vương, P. 4, Q. 5, TP. Hồ Chí Minh', 'district' => 'Quận 5', 'category' => 'Trường Đại học', 'lat' => 10.7601, 'lng' => 106.6835],
        ['name' => 'Đại học Kinh tế TP.HCM (UEH - Cơ sở A)', 'address' => '59C Nguyễn Đình Chiểu, P. Võ Thị Sáu, Q. 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Trường Đại học', 'lat' => 10.7828, 'lng' => 106.6958],
        ['name' => 'Đại học Y Dược TP.HCM', 'address' => '217 Hồng Bàng, P. 11, Q. 5, TP. Hồ Chí Minh', 'district' => 'Quận 5', 'category' => 'Trường Đại học', 'lat' => 10.7556, 'lng' => 106.6632],
        ['name' => 'Đại học Nông Lâm TP.HCM', 'address' => 'Khu phố 6, P. Linh Trung, TP. Thủ Đức, TP. Hồ Chí Minh', 'district' => 'TP. Thủ Đức', 'category' => 'Trường Đại học', 'lat' => 10.8700, 'lng' => 106.7917],
        ['name' => 'Đại học Sư phạm Kỹ thuật TP.HCM', 'address' => '01 Võ Văn Ngân, P. Linh Chiểu, TP. Thủ Đức, TP. Hồ Chí Minh', 'district' => 'TP. Thủ Đức', 'category' => 'Trường Đại học', 'lat' => 10.8512, 'lng' => 106.7720],
        ['name' => 'Đại học Tôn Đức Thắng (TDTU)', 'address' => '19 Nguyễn Hữu Thọ, P. Tân Phong, Q. 7, TP. Hồ Chí Minh', 'district' => 'Quận 7', 'category' => 'Trường Đại học', 'lat' => 10.7326, 'lng' => 106.6997],
        ['name' => 'Đại học Công nghiệp TP.HCM (IUH)', 'address' => '12 Nguyễn Văn Bảo, P. 4, Q. Gò Vấp, TP. Hồ Chí Minh', 'district' => 'Q. Gò Vấp', 'category' => 'Trường Đại học', 'lat' => 10.8222, 'lng' => 106.6875],
        ['name' => 'Đại học Sài Gòn (SGU)', 'address' => '273 An Dương Vương, P. 3, Q. 5, TP. Hồ Chí Minh', 'district' => 'Quận 5', 'category' => 'Trường Đại học', 'lat' => 10.7599, 'lng' => 106.6822],
        ['name' => 'Đại học Luật TP.HCM (CS Nguyễn Tất Thành)', 'address' => '02 Nguyễn Tất Thành, P. 13, Q. 4, TP. Hồ Chí Minh', 'district' => 'Quận 4', 'category' => 'Trường Đại học', 'lat' => 10.7676, 'lng' => 106.7064],
        ['name' => 'Đại học Ngoại thương (CS2 TP.HCM)', 'address' => '15 Đường D5, P. 25, Q. Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Trường Đại học', 'lat' => 10.8065, 'lng' => 106.7124],
        ['name' => 'Đại học Giao thông Vận tải TP.HCM', 'address' => '02 Võ Oanh, P. 25, Q. Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Trường Đại học', 'lat' => 10.8045, 'lng' => 106.7169],
        ['name' => 'Đại học Công nghệ TP.HCM (HUTECH)', 'address' => '475A Điện Biên Phủ, P. 25, Q. Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Trường Đại học', 'lat' => 10.8017, 'lng' => 106.7145],
        ['name' => 'Đại học Văn Lang (CS3 Bình Thạnh)', 'address' => '69/68 Đặng Thùy Trâm, P. 13, Q. Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Trường Đại học', 'lat' => 10.8276, 'lng' => 106.6999],
        ['name' => 'THPT Chuyên Lê Hồng Phong', 'address' => '235 Nguyễn Văn Cừ, P. 4, Q. 5, TP. Hồ Chí Minh', 'district' => 'Quận 5', 'category' => 'Điểm thi THPT', 'lat' => 10.7640, 'lng' => 106.6820],
        ['name' => 'THPT Marie Curie', 'address' => '159 Nam Kỳ Khởi Nghĩa, P. Võ Thị Sáu, Q. 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Điểm thi THPT', 'lat' => 10.7812, 'lng' => 106.6905],
        ['name' => 'THPT Nguyễn Thị Minh Khai', 'address' => '275 Điện Biên Phủ, P. Võ Thị Sáu, Q. 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Điểm thi THPT', 'lat' => 10.7766, 'lng' => 106.6872],
        ['name' => 'THPT Gia Định', 'address' => '44 Võ Oanh, P. 25, Q. Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Điểm thi THPT', 'lat' => 10.8058, 'lng' => 106.7176],

        // --- BỆNH VIỆN & TRẠM Y TẾ ---
        ['name' => 'Bệnh viện Chợ Rẫy', 'address' => '201B Nguyễn Chí Thanh, P. 12, Q. 5, TP. Hồ Chí Minh', 'district' => 'Quận 5', 'category' => 'Bệnh viện', 'lat' => 10.7578, 'lng' => 106.6595],
        ['name' => 'Bệnh viện Nhân dân Gia Định', 'address' => '01 Nơ Trang Long, P. 7, Q. Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Bệnh viện', 'lat' => 10.8038, 'lng' => 106.6952],
        ['name' => 'Bệnh viện Đại học Y Dược TP.HCM', 'address' => '215 Hồng Bàng, P. 11, Q. 5, TP. Hồ Chí Minh', 'district' => 'Quận 5', 'category' => 'Bệnh viện', 'lat' => 10.7554, 'lng' => 106.6645],
        ['name' => 'Bệnh viện Nhân dân 115', 'address' => '527 Sư Vạn Hạnh, P. 12, Q. 10, TP. Hồ Chí Minh', 'district' => 'Quận 10', 'category' => 'Bệnh viện', 'lat' => 10.7711, 'lng' => 106.6656],
        ['name' => 'Bệnh viện Nhi Đồng 1', 'address' => '341 Sư Vạn Hạnh, P. 10, Q. 10, TP. Hồ Chí Minh', 'district' => 'Quận 10', 'category' => 'Bệnh viện', 'lat' => 10.7689, 'lng' => 106.6703],
        ['name' => 'Bệnh viện Từ Dũ', 'address' => '284 Cống Quỳnh, P. Phạm Ngũ Lão, Q. 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Bệnh viện', 'lat' => 10.7664, 'lng' => 106.6838],
        ['name' => 'Bệnh viện Quân y 175', 'address' => '786 Nguyễn Kiệm, P. 3, Q. Gò Vấp, TP. Hồ Chí Minh', 'district' => 'Q. Gò Vấp', 'category' => 'Bệnh viện', 'lat' => 10.8180, 'lng' => 106.6787],
        ['name' => 'Trạm Y tế Xã Lý Nhơn (Cần Giờ)', 'address' => 'Đường Lý Nhơn, Xã Lý Nhơn, Huyện Cần Giờ, TP. Hồ Chí Minh', 'district' => 'Huyện Cần Giờ', 'category' => 'Trạm Y tế', 'lat' => 10.4550, 'lng' => 106.8520],

        // --- GIAO THÔNG, BẾN XE, NHÀ GA & METRO ---
        ['name' => 'Quảng trường Bến Thành / Ga Metro Bến Thành', 'address' => 'Quảng trường Quách Thị Trang, P. Bến Thành, Q. 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Giao thông / Trung tâm', 'lat' => 10.7720, 'lng' => 106.6983],
        ['name' => 'Ga Sài Gòn', 'address' => '01 Nguyễn Thông, P. 9, Q. 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Nhà Ga', 'lat' => 10.7818, 'lng' => 106.6782],
        ['name' => 'Bến xe Miền Tây', 'address' => '395 Kinh Dương Vương, P. An Lạc, Q. Bình Tân, TP. Hồ Chí Minh', 'district' => 'Q. Bình Tân', 'category' => 'Bến xe', 'lat' => 10.7435, 'lng' => 106.6178],
        ['name' => 'Bến xe Miền Đông Mới', 'address' => '501 Hoàng Hữu Nam, P. Long Bình, TP. Thủ Đức, TP. Hồ Chí Minh', 'district' => 'TP. Thủ Đức', 'category' => 'Bến xe', 'lat' => 10.8785, 'lng' => 106.8180],
        ['name' => 'Bến xe Miền Đông Cũ', 'address' => '292 Đinh Bộ Lĩnh, P. 26, Q. Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Bến xe', 'lat' => 10.8136, 'lng' => 106.7116],
        ['name' => 'Sân bay Quốc tế Tân Sơn Nhất', 'address' => 'Đường Trường Sơn, P. 2, Q. Tân Bình, TP. Hồ Chí Minh', 'district' => 'Q. Tân Bình', 'category' => 'Sân bay', 'lat' => 10.8185, 'lng' => 106.6588],
        ['name' => 'Vòng xoay Dân Chủ', 'address' => 'Nút giao 3 Tháng 2 – Cách Mạng Tháng 8, P. 14, Q. 10 / Q. 3', 'district' => 'Quận 3', 'category' => 'Giao thông', 'lat' => 10.7782, 'lng' => 106.6795],
        ['name' => 'Ngã tư Hàng Xanh', 'address' => 'Điện Biên Phủ – Xô Viết Nghệ Tĩnh, P. 15, Q. Bình Thạnh', 'district' => 'Q. Bình Thạnh', 'category' => 'Giao thông', 'lat' => 10.8012, 'lng' => 106.7113],
        ['name' => 'Ngã tư Thủ Đức', 'address' => 'Xa lộ Hà Nội – Võ Văn Ngân, P. Hiệp Phú, TP. Thủ Đức', 'district' => 'TP. Thủ Đức', 'category' => 'Giao thông', 'lat' => 10.8492, 'lng' => 106.7745],

        // --- CÔNG VIÊN, KÊNH RẠCH & ĐỊA ĐIỂM MÔI TRƯỜNG ---
        ['name' => 'Phố đi bộ Nguyễn Huệ / UBND TP.HCM', 'address' => '86 Lê Thánh Tôn / Đường Nguyễn Huệ, P. Bến Nghé, Q. 1, TP.HCM', 'district' => 'Quận 1', 'category' => 'Công trình Thanh niên', 'lat' => 10.7769, 'lng' => 106.7009],
        ['name' => 'Công viên Bến Bạch Đằng', 'address' => 'Đường Tôn Đức Thắng, P. Bến Nghé, Q. 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Công viên', 'lat' => 10.7740, 'lng' => 106.7069],
        ['name' => 'Công viên Lê Văn Tám', 'address' => 'Đường Hai Bà Trưng – Võ Thị Sáu, P. Đa Kao, Q. 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Công viên', 'lat' => 10.7887, 'lng' => 106.6946],
        ['name' => 'Công viên Tao Đàn', 'address' => '55C Nguyễn Thị Minh Khai / Trương Định, P. Bến Thành, Q. 1', 'district' => 'Quận 1', 'category' => 'Công viên', 'lat' => 10.7745, 'lng' => 106.6922],
        ['name' => 'Công viên 23 Tháng 9', 'address' => 'Đường Phạm Ngũ Lão – Lê Lai, P. Phạm Ngũ Lão, Q. 1', 'district' => 'Quận 1', 'category' => 'Công viên', 'lat' => 10.7695, 'lng' => 106.6930],
        ['name' => 'Công viên Gia Định', 'address' => 'Đường Hoàng Minh Giám, P. 9, Q. Phú Nhuận / Q. Gò Vấp', 'district' => 'Q. Phú Nhuận', 'category' => 'Công viên', 'lat' => 10.8164, 'lng' => 106.6744],
        ['name' => 'Công viên Hoàng Văn Thụ', 'address' => 'Đường Phan Đình Giót – Hoàng Văn Thụ, P. 2, Q. Tân Bình', 'district' => 'Q. Tân Bình', 'category' => 'Công viên', 'lat' => 10.7997, 'lng' => 106.6622],
        ['name' => 'Công viên Lịch sử Văn hóa Dân tộc', 'address' => 'Đường Nguyễn Xiển, P. Long Bình, TP. Thủ Đức, TP. Hồ Chí Minh', 'district' => 'TP. Thủ Đức', 'category' => 'Công viên', 'lat' => 10.8805, 'lng' => 106.8375],
        ['name' => 'Công viên Văn hóa Đầm Sen', 'address' => '03 Hòa Bình, P. 3, Q. 11, TP. Hồ Chí Minh', 'district' => 'Quận 11', 'category' => 'Công viên', 'lat' => 10.7685, 'lng' => 106.6362],
        ['name' => 'Kênh Nhiêu Lộc – Cầu Điện Biên Phủ', 'address' => 'Đường Hoàng Sa – Trường Sa, Cầu Điện Biên Phủ, Q. Bình Thạnh', 'district' => 'Q. Bình Thạnh', 'category' => 'Tuyến Kênh Xanh', 'lat' => 10.7932, 'lng' => 106.6978],
        ['name' => 'Tuyến đường Hoàng Sa – Trường Sa', 'address' => 'Bờ kênh Nhiêu Lộc, Phường 14, Quận 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Tuyến Kênh Xanh', 'lat' => 10.7875, 'lng' => 106.6850],
        ['name' => 'Bến Vân Đồn – Tuyến Kênh Tàu Hủ', 'address' => 'Đường Bến Vân Đồn, P. 1, Quận 4, TP. Hồ Chí Minh', 'district' => 'Quận 4', 'category' => 'Tuyến Kênh Xanh', 'lat' => 10.7612, 'lng' => 106.7025],
        ['name' => 'Bờ kè Bán đảo Thanh Đa', 'address' => 'Đường Bình Quới, Phường 27, Q. Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Tuyến Kênh Xanh', 'lat' => 10.8235, 'lng' => 106.7210],
        ['name' => 'Bờ kè Kênh Tẻ – Trần Xuân Soạn', 'address' => 'Đường Trần Xuân Soạn, P. Tân Hưng, Quận 7, TP. Hồ Chí Minh', 'district' => 'Quận 7', 'category' => 'Tuyến Kênh Xanh', 'lat' => 10.7512, 'lng' => 106.7085],
        ['name' => 'Tuyến Kênh Tham Lương – Bến Cát', 'address' => 'Phường Đông Hưng Thuận, Quận 12, TP. Hồ Chí Minh', 'district' => 'Quận 12', 'category' => 'Tuyến Kênh Xanh', 'lat' => 10.8290, 'lng' => 106.6325],
        ['name' => 'Tuyến Kênh Rạch Xuyên Tâm', 'address' => 'Phường 15, Quận Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Tuyến Kênh Xanh', 'lat' => 10.8055, 'lng' => 106.6980],

        // --- ĐIỂM MÙA HÈ XANH NGOẠI THÀNH & CÁC QUẬN HUYỆN ---
        ['name' => 'Khu Di tích Chiến khu Rừng Sác Cần Giờ', 'address' => 'Xã Long Hòa, Huyện Cần Giờ, TP. Hồ Chí Minh', 'district' => 'Huyện Cần Giờ', 'category' => 'Chiến khu / Di tích', 'lat' => 10.5120, 'lng' => 106.8850],
        ['name' => 'Điểm trường Xã Tam Thôn Hiệp (Cần Giờ)', 'address' => 'Đường Tam Thôn Hiệp, Xã Tam Thôn Hiệp, Huyện Cần Giờ, TP.HCM', 'district' => 'Huyện Cần Giờ', 'category' => 'Địa bàn Huyện', 'lat' => 10.5510, 'lng' => 106.8812],
        ['name' => 'Thị trấn Cần Thạnh (Huyện Cần Giờ)', 'address' => 'Đường Duyên Hải, Thị trấn Cần Thạnh, Huyện Cần Giờ, TP. Hồ Chí Minh', 'district' => 'Huyện Cần Giờ', 'category' => 'Địa bàn Huyện', 'lat' => 10.4114, 'lng' => 106.9547],
        ['name' => 'Xã Bà Điểm (Huyện Hóc Môn)', 'address' => 'Đường Phan Văn Hớn, Xã Bà Điểm, Huyện Hóc Môn, TP. Hồ Chí Minh', 'district' => 'Huyện Hóc Môn', 'category' => 'Địa bàn Huyện', 'lat' => 10.8385, 'lng' => 106.6025],
        ['name' => 'Khu Di tích Địa đạo Củ Chi', 'address' => 'Ấp Phú Hiệp, Xã Phú Mỹ Hưng, Huyện Củ Chi, TP. Hồ Chí Minh', 'district' => 'Huyện Củ Chi', 'category' => 'Địa bàn Huyện', 'lat' => 11.1422, 'lng' => 106.4622],
        ['name' => 'Thị trấn Tân Túc (Huyện Bình Chánh)', 'address' => 'Quốc lộ 1A, Thị trấn Tân Túc, Huyện Bình Chánh, TP. Hồ Chí Minh', 'district' => 'Huyện Bình Chánh', 'category' => 'Địa bàn Huyện', 'lat' => 10.6922, 'lng' => 106.5914],
        ['name' => 'Trung tâm Huyện Nhà Bè', 'address' => 'Đường Huỳnh Tấn Phát, Thị trấn Nhà Bè, Huyện Nhà Bè, TP.HCM', 'district' => 'Huyện Nhà Bè', 'category' => 'Địa bàn Huyện', 'lat' => 10.6889, 'lng' => 106.7433],

        // --- TRUNG TÂM CÁC QUẬN / THÀNH PHỐ THỦ ĐỨC ---
        ['name' => 'Quận 1 – Trung tâm TP. Hồ Chí Minh', 'address' => 'Phường Bến Nghé, Quận 1, TP. Hồ Chí Minh', 'district' => 'Quận 1', 'category' => 'Quận/Huyện', 'lat' => 10.7756, 'lng' => 106.7019],
        ['name' => 'Quận 3 – Trung tâm Thành Đoàn', 'address' => 'Phường Võ Thị Sáu, Quận 3, TP. Hồ Chí Minh', 'district' => 'Quận 3', 'category' => 'Quận/Huyện', 'lat' => 10.7843, 'lng' => 106.6844],
        ['name' => 'Quận 4 – Khánh Hội', 'address' => 'Phường 13, Quận 4, TP. Hồ Chí Minh', 'district' => 'Quận 4', 'category' => 'Quận/Huyện', 'lat' => 10.7578, 'lng' => 106.7013],
        ['name' => 'Quận 5 – Khu vực Đại học Sư phạm & KHTN', 'address' => 'Đường An Dương Vương – Nguyễn Văn Cừ, Quận 5, TP. Hồ Chí Minh', 'district' => 'Quận 5', 'category' => 'Quận/Huyện', 'lat' => 10.7540, 'lng' => 106.6634],
        ['name' => 'Quận 6 – Bình Tây', 'address' => 'Phường 2, Quận 6, TP. Hồ Chí Minh', 'district' => 'Quận 6', 'category' => 'Quận/Huyện', 'lat' => 10.7480, 'lng' => 106.6352],
        ['name' => 'Quận 7 – Phú Mỹ Hưng & Kênh Tẻ', 'address' => 'Phường Tân Phú, Quận 7, TP. Hồ Chí Minh', 'district' => 'Quận 7', 'category' => 'Quận/Huyện', 'lat' => 10.7340, 'lng' => 106.7218],
        ['name' => 'Quận 8 – Chánh Hưng', 'address' => 'Phường 5, Quận 8, TP. Hồ Chí Minh', 'district' => 'Quận 8', 'category' => 'Quận/Huyện', 'lat' => 10.7240, 'lng' => 106.6286],
        ['name' => 'Quận 10 – Lý Thường Kiệt & Sư Vạn Hạnh', 'address' => 'Phường 14, Quận 10, TP. Hồ Chí Minh', 'district' => 'Quận 10', 'category' => 'Quận/Huyện', 'lat' => 10.7746, 'lng' => 106.6679],
        ['name' => 'Quận 11 – Lãnh Binh Thăng', 'address' => 'Phường 10, Quận 11, TP. Hồ Chí Minh', 'district' => 'Quận 11', 'category' => 'Quận/Huyện', 'lat' => 10.7629, 'lng' => 106.6500],
        ['name' => 'Quận 12 – Công viên Phần mềm Quang Trung', 'address' => 'Phường Tân Chánh Hiệp, Quận 12, TP. Hồ Chí Minh', 'district' => 'Quận 12', 'category' => 'Quận/Huyện', 'lat' => 10.8541, 'lng' => 106.6263],
        ['name' => 'Quận Bình Thạnh – Thanh Đa & Hàng Xanh', 'address' => 'Phường 14, Quận Bình Thạnh, TP. Hồ Chí Minh', 'district' => 'Q. Bình Thạnh', 'category' => 'Quận/Huyện', 'lat' => 10.8106, 'lng' => 106.7091],
        ['name' => 'Quận Gò Vấp – Quang Trung', 'address' => 'Phường 10, Quận Gò Vấp, TP. Hồ Chí Minh', 'district' => 'Q. Gò Vấp', 'category' => 'Quận/Huyện', 'lat' => 10.8387, 'lng' => 106.6653],
        ['name' => 'Quận Phú Nhuận – Phan Đăng Lưu', 'address' => 'Phường 5, Quận Phú Nhuận, TP. Hồ Chí Minh', 'district' => 'Q. Phú Nhuận', 'category' => 'Quận/Huyện', 'lat' => 10.7992, 'lng' => 106.6802],
        ['name' => 'Quận Tân Bình – Cộng Hòa & Hoàng Văn Thụ', 'address' => 'Phường 14, Quận Tân Bình, TP. Hồ Chí Minh', 'district' => 'Q. Tân Bình', 'category' => 'Quận/Huyện', 'lat' => 10.8015, 'lng' => 106.6526],
        ['name' => 'Quận Tân Phú – Khu vực Đầm Sen / Aeon', 'address' => 'Phường Hòa Thạnh, Quận Tân Phú, TP. Hồ Chí Minh', 'district' => 'Q. Tân Phú', 'category' => 'Quận/Huyện', 'lat' => 10.7900, 'lng' => 106.6282],
        ['name' => 'Quận Bình Tân – An Lạc & Tên Lửa', 'address' => 'Phường Bình Trị Đông B, Quận Bình Tân, TP. Hồ Chí Minh', 'district' => 'Q. Bình Tân', 'category' => 'Quận/Huyện', 'lat' => 10.7652, 'lng' => 106.6038],
        ['name' => 'Thành phố Thủ Đức – Khu Công nghệ Cao & ĐHQG', 'address' => 'Phường Bình Thọ / Linh Trung, TP. Thủ Đức, TP. Hồ Chí Minh', 'district' => 'TP. Thủ Đức', 'category' => 'Quận/Huyện', 'lat' => 10.8494, 'lng' => 106.7717],
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
     * Nạp danh sách địa điểm hợp nhất từ PostgreSQL (`activities`, `shifts`) + Gazetteer TP.HCM.
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
                            'district' => 'TP. Hồ Chí Minh',
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

        // 2. Bổ sung Gazetteer chuẩn > 80 địa danh TP.HCM
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
                ? "Gần {$nearest['main_text']} ({$distanceMeters}m) — {$nearest['district']}, TP. Hồ Chí Minh"
                : "Khu vực {$nearest['district']} (cách {$nearest['main_text']} {$approxKm} km), TP. Hồ Chí Minh";
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
            'full_address' => sprintf('%.4f° N, %.4f° E · TP. Hồ Chí Minh', $lat, $lng),
            'place_name' => 'TP. Hồ Chí Minh',
            'district' => 'TP. Hồ Chí Minh',
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
        $district = (string) ($addr['city_district'] ?? $addr['county'] ?? $addr['town'] ?? 'TP. Hồ Chí Minh');

        return [
            'full_address' => $fullAddr,
            'place_name' => $placeName,
            'district' => $district,
        ];
    }
}
