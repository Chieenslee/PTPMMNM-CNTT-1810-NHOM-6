-- ============================================================================
-- MIGRATION 002: PHÂN HỆ ĐÁNH GIÁ 360°, HUY HIỆU SỐ & BẢNG VÀNG THI ĐUA (GAMIFICATION)
-- Xanh Hub - Đề tài 6
-- ============================================================================

-- Bảng 1: evaluations - Đánh giá 2 chiều sau ca trực (ĐPV -> TNV & TNV -> Ca trực)
CREATE TABLE IF NOT EXISTS evaluations (
    id SERIAL PRIMARY KEY,
    assignment_id INT NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
    shift_id INT NOT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    evaluator_user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_user_id INT NULL REFERENCES users(id) ON DELETE CASCADE, -- NULL khi TNV đánh giá ca trực
    evaluation_type VARCHAR(32) NOT NULL CHECK (evaluation_type IN ('COORDINATOR_TO_VOLUNTEER', 'VOLUNTEER_TO_SHIFT')),

    -- Tiêu chí ĐPV -> TNV (1-5 sao):
    criteria_diligence INT NULL CHECK (criteria_diligence BETWEEN 1 AND 5),   -- Chuyên cần
    criteria_competence INT NULL CHECK (criteria_competence BETWEEN 1 AND 5), -- Chuyên môn
    criteria_demeanor INT NULL CHECK (criteria_demeanor BETWEEN 1 AND 5),     -- Tác phong

    -- Tiêu chí TNV -> Ca trực (1-5 sao):
    criteria_logistics INT NULL CHECK (criteria_logistics BETWEEN 1 AND 5),   -- Công tác hậu cần
    criteria_safety INT NULL CHECK (criteria_safety BETWEEN 1 AND 5),         -- Mức độ an toàn
    criteria_support INT NULL CHECK (criteria_support BETWEEN 1 AND 5),       -- Sự hỗ trợ Ban chỉ huy

    average_score NUMERIC(3, 2) NOT NULL CHECK (average_score BETWEEN 1.0 AND 5.0),
    feedback_text TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (assignment_id, evaluation_type)
);

CREATE INDEX IF NOT EXISTS idx_evaluations_assignment ON evaluations(assignment_id);
CREATE INDEX IF NOT EXISTS idx_evaluations_target_user ON evaluations(target_user_id);
CREATE INDEX IF NOT EXISTS idx_evaluations_shift ON evaluations(shift_id);

-- Bảng 2: badges - Danh mục Bộ 6 Huy hiệu Số danh dự Đoàn Thanh niên
CREATE TABLE IF NOT EXISTS badges (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    icon VARCHAR(60) NOT NULL,
    badge_color VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'MERIT',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Khởi tạo danh mục 6 Huy hiệu Số danh dự Đoàn Thanh niên
INSERT INTO badges (code, name, description, icon, badge_color, category) VALUES
('ROOKIE_VOLUNTEER', 'Chiến sĩ Tân binh', 'Hoàn thành ca trực đầu tiên trong chiến dịch', 'military_tech', '#10B981', 'MERIT'),
('FIRST_AID_HERO', 'Đôi tay Vàng Sơ cứu', 'Đạt >= 2 ca chuyên môn Sơ cứu Y tế với đánh giá >= 4.5 sao', 'medical_services', '#EF4444', 'SKILL'),
('ENVIRONMENT_CHAMPION', 'Kiện tướng Môi trường', 'Hoàn thành >= 3 ca Chủ Nhật Xanh làm sạch kênh và trồng cây', 'eco', '#059669', 'CAMPAIGN'),
('PERSEVERANT_SOLDIER', 'Chiến sĩ Bền bỉ', 'Điểm danh đúng giờ 100% các ca đã đăng ký mà không vắng mặt', 'verified', '#0284C7', 'DILIGENCE'),
('INSPIRING_AMBASSADOR', 'Đại sứ Lan tỏa', 'Đạt điểm đánh giá tác phong tối đa 5 sao từ Điều phối viên', 'campaign', '#8B5CF6', 'LEADERSHIP'),
('VOLUNTEER_LEADER', 'Thủ lĩnh Tình nguyện', 'Cống hiến >= 25 giờ và XP thuộc Top 10 toàn hệ thống', 'trophy', '#F59E0B', 'HONOR')
ON CONFLICT (code) DO NOTHING;

-- Bảng 3: volunteer_badges - Huy hiệu Tình nguyện viên đạt được
CREATE TABLE IF NOT EXISTS volunteer_badges (
    id SERIAL PRIMARY KEY,
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    badge_id INT NOT NULL REFERENCES badges(id) ON DELETE CASCADE,
    unlocked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    evidence_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (volunteer_id, badge_id)
);

CREATE INDEX IF NOT EXISTS idx_volunteer_badges_vid ON volunteer_badges(volunteer_id);
CREATE INDEX IF NOT EXISTS idx_volunteer_badges_bid ON volunteer_badges(badge_id);
