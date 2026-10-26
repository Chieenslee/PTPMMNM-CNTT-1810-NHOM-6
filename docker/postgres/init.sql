-- ============================================================================
-- XANH HUB (ĐỀ TÀI 6) - LƯỢC ĐỒ CƠ SỞ DỮ LIỆU CHUẨN v1.1 (PostgreSQL 16/18)
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Dọn sạch bảng cũ khi chạy lại migration khởi tạo
DROP TABLE IF EXISTS audit_log CASCADE;
DROP TABLE IF EXISTS settings CASCADE;
DROP TABLE IF EXISTS certificates CASCADE;
DROP TABLE IF EXISTS checkins CASCADE;
DROP TABLE IF EXISTS waitlist CASCADE;
DROP TABLE IF EXISTS absence_reports CASCADE;
DROP TABLE IF EXISTS assignments CASCADE;
DROP TABLE IF EXISTS unassigned CASCADE;
DROP TABLE IF EXISTS plan_items CASCADE;
DROP TABLE IF EXISTS plans CASCADE;
DROP TABLE IF EXISTS dataset_snapshots CASCADE;
DROP TABLE IF EXISTS registrations CASCADE;
DROP TABLE IF EXISTS shift_requirements CASCADE;
DROP TABLE IF EXISTS shifts CASCADE;
DROP TABLE IF EXISTS activity_coordinators CASCADE;
DROP TABLE IF EXISTS activities CASCADE;
DROP TABLE IF EXISTS availability_overrides CASCADE;
DROP TABLE IF EXISTS availability CASCADE;
DROP TABLE IF EXISTS volunteer_skills CASCADE;
DROP TABLE IF EXISTS skills CASCADE;
DROP TABLE IF EXISTS volunteers CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ============================================================================
-- NHÓM 1: NGƯỜI DÙNG, HỒ SƠ, KỸ NĂNG & LỊCH RẢNH
-- ============================================================================

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role VARCHAR(32) NOT NULL CHECK (role IN ('VOLUNTEER', 'COORDINATOR', 'ADMIN')),
    totp_secret VARCHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE volunteers (
    user_id INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    student_code VARCHAR(40) NOT NULL UNIQUE,
    chapter_name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    max_shifts_per_week INT NOT NULL DEFAULT 4 CHECK (max_shifts_per_week BETWEEN 1 AND 14),
    lat DOUBLE PRECISION NOT NULL DEFAULT 10.7769,
    lng DOUBLE PRECISION NOT NULL DEFAULT 106.7009
);

CREATE TABLE skills (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description TEXT NOT NULL DEFAULT ''
);

CREATE TABLE volunteer_skills (
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    skill_id INT NOT NULL REFERENCES skills(id) ON DELETE CASCADE,
    level INT NOT NULL CHECK (level BETWEEN 1 AND 5),
    is_verified BOOLEAN NOT NULL DEFAULT TRUE,
    verified_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    PRIMARY KEY (volunteer_id, skill_id)
);

CREATE TABLE availability (
    id SERIAL PRIMARY KEY,
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    day_of_week INT NOT NULL CHECK (day_of_week BETWEEN 1 AND 7), -- 1: Thứ Hai .. 7: Chủ Nhật
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_recurring BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (end_time > start_time)
);

CREATE TABLE availability_overrides (
    id SERIAL PRIMARY KEY,
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    override_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_available BOOLEAN NOT NULL DEFAULT FALSE,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    CHECK (end_time > start_time)
);

-- ============================================================================
-- NHÓM 2: HOẠT ĐỘNG, CA TRỰC & YÊU CẦU KỸ NĂNG
-- ============================================================================

CREATE TABLE activities (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    location_name VARCHAR(200) NOT NULL,
    lat DOUBLE PRECISION NOT NULL DEFAULT 10.7769,
    lng DOUBLE PRECISION NOT NULL DEFAULT 106.7009,
    status VARCHAR(32) NOT NULL DEFAULT 'OPEN' CHECK (status IN ('DRAFT', 'OPEN', 'LOCKED', 'COMPLETED')),
    withdraw_deadline_hours INT NOT NULL DEFAULT 24 CHECK (withdraw_deadline_hours >= 0),
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE activity_coordinators (
    activity_id INT NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    PRIMARY KEY (activity_id, user_id)
);

CREATE TABLE shifts (
    id SERIAL PRIMARY KEY,
    activity_id INT NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
    code VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    start_time TIMESTAMPTZ NOT NULL,
    end_time TIMESTAMPTZ NOT NULL,
    during TSTZRANGE NOT NULL,
    min_people INT NOT NULL CHECK (min_people >= 1),
    max_people INT NOT NULL CHECK (max_people >= min_people),
    priority INT NOT NULL DEFAULT 3 CHECK (priority BETWEEN 1 AND 5),
    location_name VARCHAR(200) NOT NULL DEFAULT '',
    lat DOUBLE PRECISION NOT NULL DEFAULT 10.7769,
    lng DOUBLE PRECISION NOT NULL DEFAULT 106.7009,
    is_locked BOOLEAN NOT NULL DEFAULT FALSE,
    unlock_reason TEXT NULL,
    CHECK (end_time > start_time),
    UNIQUE (activity_id, code)
);

CREATE OR REPLACE FUNCTION trg_fn_sync_shift_during()
RETURNS TRIGGER AS $$
BEGIN
    NEW.during := tstzrange(NEW.start_time, NEW.end_time, '[)');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_sync_shift_during
BEFORE INSERT OR UPDATE OF start_time, end_time ON shifts
FOR EACH ROW EXECUTE FUNCTION trg_fn_sync_shift_during();

CREATE TABLE shift_requirements (
    id SERIAL PRIMARY KEY,
    shift_id INT NOT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    skill_id INT NOT NULL REFERENCES skills(id) ON DELETE RESTRICT,
    min_level INT NOT NULL DEFAULT 1 CHECK (min_level BETWEEN 1 AND 5),
    required_count INT NOT NULL DEFAULT 1 CHECK (required_count >= 1),
    require_verified BOOLEAN NOT NULL DEFAULT FALSE
);

-- ============================================================================
-- NHÓM 3: ĐĂNG KÝ, SNAPSHOT, PHƯƠNG ÁN ĐỀ XUẤT & PHÂN CÔNG CHÍNH THỨC
-- ============================================================================

CREATE TABLE registrations (
    id SERIAL PRIMARY KEY,
    activity_id INT NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
    shift_id INT NOT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    preference_rank INT NOT NULL DEFAULT 1 CHECK (preference_rank BETWEEN 1 AND 3),
    note VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'WITHDRAWN')),
    registered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (shift_id, volunteer_id)
);

CREATE TABLE dataset_snapshots (
    id SERIAL PRIMARY KEY,
    activity_id INT NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
    dataset_code VARCHAR(50) NOT NULL DEFAULT 'CUSTOM',
    sha256_hash CHAR(64) NOT NULL,
    payload_json JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE plans (
    id SERIAL PRIMARY KEY,
    activity_id INT NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
    snapshot_id INT NOT NULL REFERENCES dataset_snapshots(id) ON DELETE CASCADE,
    algorithm_code VARCHAR(32) NOT NULL CHECK (algorithm_code IN ('B0', 'B1', 'X')),
    priority_mode VARCHAR(32) NOT NULL DEFAULT 'MAX_COVERAGE',
    config_json JSONB NOT NULL,
    random_seed INT NOT NULL DEFAULT 202606,
    metrics_json JSONB NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PROPOSED' CHECK (status IN ('PROPOSED', 'APPLIED', 'SUPERSEDED')),
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Bảng plan_items lưu đề xuất của từng phương án (cho phép lưu cả vi phạm của B0 để báo cáo KT2)
CREATE TABLE plan_items (
    id SERIAL PRIMARY KEY,
    plan_id INT NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
    shift_id INT NOT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    requirement_id INT NULL REFERENCES shift_requirements(id) ON DELETE SET NULL,
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    score DOUBLE PRECISION NOT NULL DEFAULT 0,
    has_violation BOOLEAN NOT NULL DEFAULT FALSE,
    violation_codes_json JSONB NOT NULL DEFAULT '[]'::jsonb
);

CREATE TABLE unassigned (
    id SERIAL PRIMARY KEY,
    plan_id INT NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
    item_type VARCHAR(20) NOT NULL CHECK (item_type IN ('SLOT', 'VOLUNTEER')),
    shift_id INT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    requirement_id INT NULL REFERENCES shift_requirements(id) ON DELETE SET NULL,
    volunteer_id INT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    missing_count INT NOT NULL DEFAULT 1,
    reason_code VARCHAR(40) NOT NULL,
    explanation_text TEXT NOT NULL DEFAULT ''
);

-- Bảng assignments lưu phân công chính thức có hiệu lực, bảo vệ cứng bởi EXCLUDE USING gist
CREATE TABLE assignments (
    id SERIAL PRIMARY KEY,
    activity_id INT NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
    plan_id INT NULL REFERENCES plans(id) ON DELETE SET NULL,
    shift_id INT NOT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    requirement_id INT NULL REFERENCES shift_requirements(id) ON DELETE SET NULL,
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    during TSTZRANGE NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ASSIGNED'
        CHECK (status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'ABSENT_REPORTED', 'REPLACED', 'CHECKED_IN')),
    override_reason TEXT NULL,
    assigned_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    confirmed_at TIMESTAMPTZ NULL,
    CONSTRAINT excl_volunteer_no_overlapping_active_shifts
        EXCLUDE USING gist (volunteer_id WITH =, during WITH &&)
        WHERE (status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN'))
);

-- Trigger tự động chép khoảng thời gian từ ca và kiểm tra khóa dòng FOR UPDATE + giới hạn max_people (D2, D3)
CREATE OR REPLACE FUNCTION trg_fn_guard_assignment_capacity()
RETURNS TRIGGER AS $$
DECLARE
    v_shift RECORD;
    v_active_count INT;
BEGIN
    SELECT id, activity_id, start_time, end_time, during, max_people, is_locked
      INTO v_shift
      FROM shifts
     WHERE id = NEW.shift_id
       FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'SHIFT_NOT_FOUND: Ca trực không tồn tại (shift_id=%)', NEW.shift_id;
    END IF;

    NEW.activity_id := v_shift.activity_id;
    NEW.during := v_shift.during;

    IF NEW.status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN') THEN
        SELECT COUNT(*)
          INTO v_active_count
          FROM assignments
         WHERE shift_id = NEW.shift_id
           AND status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN')
           AND (TG_OP = 'INSERT' OR id <> NEW.id);

        IF v_active_count >= v_shift.max_people THEN
            RAISE EXCEPTION 'CAPACITY_FULL: Ca trực đã đạt số người tối đa (% / %)', v_active_count, v_shift.max_people
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_check_shift_capacity
BEFORE INSERT OR UPDATE ON assignments
FOR EACH ROW EXECUTE FUNCTION trg_fn_guard_assignment_capacity();

-- ============================================================================
-- NHÓM 4: VẬN HÀNH, CHECK-IN QR, CHỨNG NHẬN & NHẬT KÝ KIỂM TOÁN BẤT BIẾN
-- ============================================================================

CREATE TABLE absence_reports (
    id SERIAL PRIMARY KEY,
    assignment_id INT NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    reason TEXT NOT NULL,
    reported_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    replacement_assignment_id INT NULL REFERENCES assignments(id) ON DELETE SET NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN', 'REPLACED', 'CLOSED'))
);

CREATE TABLE waitlist (
    id SERIAL PRIMARY KEY,
    shift_id INT NOT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    preference_rank INT NOT NULL DEFAULT 1 CHECK (preference_rank BETWEEN 1 AND 3),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (shift_id, volunteer_id)
);

CREATE TABLE checkins (
    id SERIAL PRIMARY KEY,
    assignment_id INT NOT NULL UNIQUE REFERENCES assignments(id) ON DELETE CASCADE,
    checked_in_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    method VARCHAR(32) NOT NULL DEFAULT 'QR_HMAC' CHECK (method IN ('QR_HMAC', 'MANUAL_AUDIT')),
    verified_token_hash VARCHAR(128) NOT NULL
);

CREATE TABLE certificates (
    id SERIAL PRIMARY KEY,
    certificate_code VARCHAR(32) NOT NULL UNIQUE CHECK (char_length(certificate_code) >= 12),
    volunteer_id INT NOT NULL REFERENCES volunteers(user_id) ON DELETE CASCADE,
    activity_id INT NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
    total_hours NUMERIC(6, 2) NOT NULL CHECK (total_hours > 0),
    issued_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    issued_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE settings (
    key VARCHAR(100) PRIMARY KEY,
    value_json JSONB NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    actor_user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id VARCHAR(60) NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    payload_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    prev_hash CHAR(64) NOT NULL,
    row_hash CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Chặn sửa/xóa trên bảng audit_log
CREATE OR REPLACE FUNCTION trg_fn_immutable_audit_log()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'AUDIT_LOG_IMMUTABLE: Nhật ký kiểm toán chỉ cho phép chèn thêm, cấm sửa hoặc xóa';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_protect_audit_log
BEFORE UPDATE OR DELETE ON audit_log
FOR EACH ROW EXECUTE FUNCTION trg_fn_immutable_audit_log();

-- Hàm chèn audit_log có khóa tư vấn giao dịch pg_advisory_xact_lock để bảo toàn chuỗi băm (D4)
CREATE OR REPLACE FUNCTION append_audit_log(
    p_actor_user_id INT,
    p_action VARCHAR,
    p_entity_type VARCHAR,
    p_entity_id VARCHAR,
    p_reason TEXT,
    p_payload JSONB
) RETURNS BIGINT AS $$
DECLARE
    v_prev_hash CHAR(64);
    v_row_hash CHAR(64);
    v_now TIMESTAMPTZ := clock_timestamp();
    v_id BIGINT;
BEGIN
    PERFORM pg_advisory_xact_lock(202606);

    SELECT row_hash INTO v_prev_hash
      FROM audit_log
     ORDER BY id DESC
     LIMIT 1;

    IF v_prev_hash IS NULL THEN
        v_prev_hash := repeat('0', 64);
    END IF;

    v_row_hash := encode(
        digest(
            v_prev_hash || '|' ||
            COALESCE(p_actor_user_id::text, '0') || '|' ||
            p_action || '|' ||
            p_entity_type || '|' ||
            p_entity_id || '|' ||
            COALESCE(p_reason, '') || '|' ||
            COALESCE(p_payload::text, '{}') || '|' ||
            extract(epoch from v_now)::text,
            'sha256'
        ),
        'hex'
    );

    INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, reason, payload_json, prev_hash, row_hash, created_at)
    VALUES (p_actor_user_id, p_action, p_entity_type, p_entity_id, COALESCE(p_reason, ''), COALESCE(p_payload, '{}'::jsonb), v_prev_hash, v_row_hash, v_now)
    RETURNING id INTO v_id;

    RETURN v_id;
END;
$$ LANGUAGE plpgsql;

-- ============================================================================
-- NHÓM 7: PHÂN HỆ ĐÁNH GIÁ 360°, HUY HIỆU SỐ & BẢNG VÀNG THI ĐUA (GAMIFICATION)
-- ============================================================================

CREATE TABLE IF NOT EXISTS evaluations (
    id SERIAL PRIMARY KEY,
    assignment_id INT NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
    shift_id INT NOT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    evaluator_user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_user_id INT NULL REFERENCES users(id) ON DELETE CASCADE,
    evaluation_type VARCHAR(32) NOT NULL CHECK (evaluation_type IN ('COORDINATOR_TO_VOLUNTEER', 'VOLUNTEER_TO_SHIFT')),
    criteria_diligence INT NULL CHECK (criteria_diligence BETWEEN 1 AND 5),
    criteria_competence INT NULL CHECK (criteria_competence BETWEEN 1 AND 5),
    criteria_demeanor INT NULL CHECK (criteria_demeanor BETWEEN 1 AND 5),
    criteria_logistics INT NULL CHECK (criteria_logistics BETWEEN 1 AND 5),
    criteria_safety INT NULL CHECK (criteria_safety BETWEEN 1 AND 5),
    criteria_support INT NULL CHECK (criteria_support BETWEEN 1 AND 5),
    average_score NUMERIC(3, 2) NOT NULL CHECK (average_score BETWEEN 1.0 AND 5.0),
    feedback_text TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (assignment_id, evaluation_type)
);

CREATE INDEX IF NOT EXISTS idx_evaluations_assignment ON evaluations(assignment_id);
CREATE INDEX IF NOT EXISTS idx_evaluations_target_user ON evaluations(target_user_id);
CREATE INDEX IF NOT EXISTS idx_evaluations_shift ON evaluations(shift_id);

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

INSERT INTO badges (code, name, description, icon, badge_color, category) VALUES
('ROOKIE_VOLUNTEER', 'Chiến sĩ Tân binh', 'Hoàn thành ca trực đầu tiên trong chiến dịch', 'military_tech', '#10B981', 'MERIT'),
('FIRST_AID_HERO', 'Đôi tay Vàng Sơ cứu', 'Đạt >= 2 ca chuyên môn Sơ cứu Y tế với đánh giá >= 4.5 sao', 'medical_services', '#EF4444', 'SKILL'),
('ENVIRONMENT_CHAMPION', 'Kiện tướng Môi trường', 'Hoàn thành >= 3 ca Chủ Nhật Xanh làm sạch kênh và trồng cây', 'eco', '#059669', 'CAMPAIGN'),
('PERSEVERANT_SOLDIER', 'Chiến sĩ Bền bỉ', 'Điểm danh đúng giờ 100% các ca đã đăng ký mà không vắng mặt', 'verified', '#0284C7', 'DILIGENCE'),
('INSPIRING_AMBASSADOR', 'Đại sứ Lan tỏa', 'Đạt điểm đánh giá tác phong tối đa 5 sao từ Điều phối viên', 'campaign', '#8B5CF6', 'LEADERSHIP'),
('VOLUNTEER_LEADER', 'Thủ lĩnh Tình nguyện', 'Cống hiến >= 25 giờ và XP thuộc Top 10 toàn hệ thống', 'trophy', '#F59E0B', 'HONOR')
ON CONFLICT (code) DO NOTHING;

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
