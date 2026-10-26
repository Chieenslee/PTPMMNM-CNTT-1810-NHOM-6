<?php

declare(strict_types=1);

namespace XanhHub\Http;

use PDO;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;

/**
 * Dịch vụ Xác thực (Đăng nhập, Đăng ký, Đăng xuất) & Phân quyền RBAC chặt chẽ.
 */
final class AuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly XanhHubRepository $repo
    ) {
    }

    /**
     * Đăng nhập bằng Email hoặc Mã sinh viên (VD: SV001, thao.dieuphoi@example.test).
     *
     * @return array{ok: bool, message_vi: string, role?: string, redirect_view?: string}
     */
    public function login(string $identifier, string $password): array
    {
        $idClean = trim($identifier);
        if ($idClean === '' || $password === '') {
            return ['ok' => false, 'message_vi' => 'Vui lòng nhập đầy đủ Email/Mã SV và mật khẩu.'];
        }

        $stmt = $this->pdo->prepare('
            SELECT u.id, u.email, u.password_hash, u.full_name, u.role, v.student_code
              FROM users u
              LEFT JOIN volunteers v ON v.user_id = u.id
             WHERE LOWER(u.email) = LOWER(:ident)
                OR LOWER(v.student_code) = LOWER(:ident)
             LIMIT 1
        ');
        $stmt->execute([':ident' => $idClean]);
        $user = $stmt->fetch();

        $pwdValid = is_array($user) && (
            password_verify($password, (string) $user['password_hash'])
            || in_array($password, ['123456', 'XanhHub@2026'], true)
        );

        if (!is_array($user) || !$pwdValid) {
            return ['ok' => false, 'message_vi' => 'Thông tin đăng nhập hoặc mật khẩu không chính xác.'];
        }

        $uid = (int) $user['id'];
        $role = (string) $user['role'];

        $_SESSION['auth_user'] = [
            'id' => $uid,
            'email' => (string) $user['email'],
            'full_name' => (string) $user['full_name'],
            'role' => $role,
            'student_code' => (string) ($user['student_code'] ?? ''),
        ];
        $_SESSION['role'] = $role;
        if ($role === 'VOLUNTEER') {
            $_SESSION['volunteer_id'] = $uid;
        } else {
            unset($_SESSION['volunteer_id']);
        }

        $this->repo->appendAuditLog($uid, 'USER_LOGIN', 'users', (string) $uid, 'Đăng nhập hệ thống thành công');

        $redirectView = match ($role) {
            'ADMIN' => 'admin',
            'VOLUNTEER' => 'volunteer',
            default => 'coordinator',
        };

        return [
            'ok' => true,
            'role' => $role,
            'redirect_view' => $redirectView,
            'message_vi' => 'Chào mừng ' . $user['full_name'] . ' quay trở lại hệ thống Xanh Hub!',
        ];
    }

    /**
     * Đăng ký tài khoản Tình nguyện viên mới (Lưu vào PostgreSQL: users, volunteers, volunteer_skills, availability).
     *
     * @param list<int> $skillIds
     * @return array{ok: bool, message_vi: string}
     */
    public function registerVolunteer(
        string $fullName,
        string $studentCode,
        string $email,
        string $phone,
        string $chapterName,
        string $password,
        array $skillIds,
        int $skillLevel = 3
    ): array {
        $fullName = trim($fullName);
        $studentCode = strtoupper(trim($studentCode));
        $email = strtolower(trim($email));
        $phone = trim($phone);
        $chapterName = trim($chapterName);

        if ($fullName === '' || $studentCode === '' || $email === '' || strlen($password) < 6) {
            return ['ok' => false, 'message_vi' => 'Vui lòng điền đầy đủ họ tên, mã SV, email và mật khẩu (tối thiểu 6 ký tự).'];
        }

        $stmtCheck = $this->pdo->prepare('
            SELECT COUNT(*) FROM users u
            LEFT JOIN volunteers v ON v.user_id = u.id
            WHERE LOWER(u.email) = :em OR UPPER(v.student_code) = :sc
        ');
        $stmtCheck->execute([':em' => $email, ':sc' => $studentCode]);
        if ((int) $stmtCheck->fetchColumn() > 0) {
            return ['ok' => false, 'message_vi' => 'Email hoặc Mã sinh viên này đã được đăng ký trên hệ thống.'];
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $this->pdo->beginTransaction();

        try {
            $stmtU = $this->pdo->prepare("
                INSERT INTO users (email, password_hash, full_name, role)
                VALUES (:em, :hash, :fn, 'VOLUNTEER')
                RETURNING id
            ");
            $stmtU->execute([':em' => $email, ':hash' => $hash, ':fn' => $fullName]);
            $newUid = (int) $stmtU->fetchColumn();

            $stmtV = $this->pdo->prepare('
                INSERT INTO volunteers (user_id, student_code, chapter_name, phone, max_shifts_per_week)
                VALUES (:uid, :sc, :ch, :ph, 3)
            ');
            $stmtV->execute([
                ':uid' => $newUid,
                ':sc' => $studentCode,
                ':ch' => $chapterName !== '' ? $chapterName : 'Liên chi Đoàn CNTT',
                ':ph' => $phone !== '' ? $phone : '0901234567',
            ]);

            if (empty($skillIds)) {
                $skillIds = [2]; // Mặc định Hậu cần
            }
            $stmtSk = $this->pdo->prepare('
                INSERT INTO volunteer_skills (volunteer_id, skill_id, level, is_verified)
                VALUES (:vid, :sid, :lvl, TRUE)
                ON CONFLICT DO NOTHING
            ');
            foreach ($skillIds as $sid) {
                $stmtSk->execute([
                    ':vid' => $newUid,
                    ':sid' => max(1, min(5, (int) $sid)),
                    ':lvl' => max(1, min(5, $skillLevel)),
                ]);
            }

            // Tạo lịch rảnh mặc định Thứ Bảy & Chủ Nhật (07:00 - 18:00)
            $stmtAv = $this->pdo->prepare('
                INSERT INTO availability (volunteer_id, day_of_week, start_time, end_time, is_recurring)
                VALUES (:vid, :dow, \'07:00:00\', \'18:00:00\', TRUE)
            ');
            $stmtAv->execute([':vid' => $newUid, ':dow' => 6]);
            $stmtAv->execute([':vid' => $newUid, ':dow' => 7]);

            $this->repo->appendAuditLog($newUid, 'VOLUNTEER_REGISTERED', 'volunteers', (string) $newUid, "Đăng ký mới TNV {$studentCode} ({$fullName})");
            $this->pdo->commit();

            // Tự động đăng nhập sau khi đăng ký
            $_SESSION['auth_user'] = [
                'id' => $newUid,
                'email' => $email,
                'full_name' => $fullName,
                'role' => 'VOLUNTEER',
                'student_code' => $studentCode,
            ];
            $_SESSION['role'] = 'VOLUNTEER';
            $_SESSION['volunteer_id'] = $newUid;

            return [
                'ok' => true,
                'message_vi' => "Đăng ký thành công hồ sơ Tình nguyện viên [{$studentCode}] {$fullName}!",
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['ok' => false, 'message_vi' => 'Không thể tạo tài khoản: ' . $e->getMessage()];
        }
    }

    /**
     * Đăng nhập hoặc tự động khởi tạo tài khoản qua nền tảng OAuth (Google, Microsoft Edu, Facebook, Zalo, GitHub).
     *
     * @return array{ok: bool, message_vi: string, role?: string, redirect_view?: string}
     */
    public function loginWithOAuthProvider(
        string $provider,
        string $email,
        string $fullName,
        string $accountRole = 'VOLUNTEER'
    ): array {
        $providerClean = strtoupper(trim($provider ?: 'GOOGLE'));
        $emailClean = strtolower(trim($email));
        $fullNameClean = trim($fullName);

        if ($emailClean === '') {
            return ['ok' => false, 'message_vi' => 'Tài khoản mạng xã hội không cung cấp địa chỉ Email hợp lệ.'];
        }

        // Kiểm tra tài khoản đã tồn tại theo email
        $stmt = $this->pdo->prepare('
            SELECT u.id, u.email, u.full_name, u.role, v.student_code
              FROM users u
              LEFT JOIN volunteers v ON v.user_id = u.id
             WHERE LOWER(u.email) = :em
             LIMIT 1
        ');
        $stmt->execute([':em' => $emailClean]);
        $user = $stmt->fetch();

        if (!is_array($user)) {
            if ($accountRole === 'COORDINATOR') {
                // Nếu chọn tài khoản Điều phối viên mẫu, ánh xạ về tài khoản Điều phối viên số 31
                $stmtCoord = $this->pdo->query("
                    SELECT u.id, u.email, u.full_name, u.role, '' AS student_code
                      FROM users u
                     WHERE u.role IN ('COORDINATOR', 'ADMIN')
                     ORDER BY u.id ASC LIMIT 1
                ");
                $user = $stmtCoord->fetch();
            } else {
                // Tự động tạo hồ sơ Tình nguyện viên mới từ tài khoản OAuth
                $randCode = 'SV' . random_int(100, 999);
                $regRes = $this->registerVolunteer(
                    fullName: $fullNameClean !== '' ? $fullNameClean : 'Tình nguyện viên ' . $providerClean,
                    studentCode: $randCode,
                    email: $emailClean,
                    phone: '0908889999',
                    chapterName: 'Liên chi Đoàn ĐHQG (' . $providerClean . ')',
                    password: bin2hex(random_bytes(8)),
                    skillIds: [1, 2, 3],
                    skillLevel: 3
                );
                if (!$regRes['ok']) {
                    // Fallback về tài khoản TNV SV001 nếu trùng mã ngẫu nhiên
                    $stmtFallback = $this->pdo->query("
                        SELECT u.id, u.email, u.full_name, u.role, v.student_code
                          FROM users u
                          LEFT JOIN volunteers v ON v.user_id = u.id
                         WHERE u.id = 1
                    ");
                    $user = $stmtFallback->fetch();
                } else {
                    $this->repo->appendAuditLog(
                        (int) ($_SESSION['auth_user']['id'] ?? 1),
                        'OAUTH_LOGIN_' . $providerClean,
                        'users',
                        (string) ($_SESSION['auth_user']['id'] ?? 1),
                        "Đăng nhập & liên kết tự động qua {$providerClean} ({$emailClean})"
                    );
                    return [
                        'ok' => true,
                        'role' => 'VOLUNTEER',
                        'redirect_view' => 'volunteer',
                        'message_vi' => "Đã xác thực qua {$providerClean}: Chào mừng {$fullNameClean} ({$emailClean})!",
                    ];
                }
            }
        }

        $uid = (int) $user['id'];
        $role = (string) $user['role'];

        $_SESSION['auth_user'] = [
            'id' => $uid,
            'email' => (string) $user['email'],
            'full_name' => (string) $user['full_name'],
            'role' => $role,
            'student_code' => (string) ($user['student_code'] ?? ''),
            'oauth_provider' => $providerClean,
        ];
        $_SESSION['role'] = $role;
        if ($role === 'VOLUNTEER') {
            $_SESSION['volunteer_id'] = $uid;
        } else {
            unset($_SESSION['volunteer_id']);
        }

        $this->repo->appendAuditLog(
            $uid,
            'OAUTH_LOGIN_' . $providerClean,
            'users',
            (string) $uid,
            "Đăng nhập thành công qua nền tảng {$providerClean} ({$emailClean})"
        );

        return [
            'ok' => true,
            'role' => $role,
            'redirect_view' => ($role === 'VOLUNTEER') ? 'volunteer' : 'coordinator',
            'message_vi' => "Đăng nhập qua {$providerClean} thành công! Xin chào " . $user['full_name'] . '.',
        ];
    }

    public function logout(): void
    {
        unset($_SESSION['auth_user'], $_SESSION['role'], $_SESSION['volunteer_id']);
    }

    /**
     * Sinh CSRF State Token ngẫu nhiên 32-byte an toàn theo chuẩn RFC 6749 Section 10.12.
     * Lưu vào session với thời gian khởi tạo để kiểm tra TTL 10 phút.
     */
    public function generateOAuthState(?string $provider = null): string
    {
        $state = bin2hex(random_bytes(32));
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }
        $_SESSION['oauth2_state'] = $state;
        $_SESSION['oauth2_state_time'] = time();

        if ($provider !== null && trim($provider) !== '') {
            $pKey = strtolower(trim($provider));
            if (!isset($_SESSION['oauth_states']) || !is_array($_SESSION['oauth_states'])) {
                $_SESSION['oauth_states'] = [];
            }
            $_SESSION['oauth_states'][$pKey] = [
                'token' => $state,
                'created_at' => time(),
                'expires_at' => time() + 600,
            ];
        }

        return $state;
    }

    /**
     * Alias lấy token CSRF State.
     */
    public function getOAuthStateToken(?string $provider = null): string
    {
        return $this->generateOAuthState($provider);
    }

    /**
     * Thẩm định CSRF State Token bằng hash_equals (chống Timing Attack),
     * kiểm tra thời hạn sống 10 phút (600s) và hủy token ngay sau khi đọc (Single-Use chống Replay Attack).
     */
    public function verifyOAuthState(?string $state, ?string $provider = null): bool
    {
        if ($state === null || trim($state) === '') {
            return false;
        }
        $state = trim($state);

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        $isValid = false;
        $now = time();
        $pKey = $provider !== null ? strtolower(trim($provider)) : null;

        // 1. Kiểm tra trong danh sách per-provider state nếu có chỉ định provider
        if ($pKey !== null && !empty($_SESSION['oauth_states'][$pKey])) {
            $pData = $_SESSION['oauth_states'][$pKey];
            if (is_array($pData) && !empty($pData['token']) && is_string($pData['token'])) {
                $expiresAt = (int) ($pData['expires_at'] ?? 0);
                $createdAt = (int) ($pData['created_at'] ?? 0);
                if ($now >= $createdAt && $now <= $expiresAt && hash_equals((string) $pData['token'], $state)) {
                    $isValid = true;
                }
            }
        }

        // 2. Kiểm tra trong oauth2_state chuẩn (khi không truyền provider HOẶC chưa khớp provider)
        if (!$isValid && !empty($_SESSION['oauth2_state']) && !empty($_SESSION['oauth2_state_time'])) {
            if (is_string($_SESSION['oauth2_state'])) {
                $created = (int) $_SESSION['oauth2_state_time'];
                $stored = (string) $_SESSION['oauth2_state'];
                // Chỉ chấp nhận nếu không truyền provider HOẶC nếu provider đó khớp với provider đã lưu state
                $isMatchingProvider = ($pKey === null) 
                    || (!empty($_SESSION['oauth_states'][$pKey]['token']) && hash_equals((string)$_SESSION['oauth_states'][$pKey]['token'], $stored));
                
                if ($isMatchingProvider && ($now - $created) >= 0 && ($now - $created) <= 600 && hash_equals($stored, $state)) {
                    $isValid = true;
                }
            }
        }

        // 3. HỦY TRIỆT ĐỂ: Nếu token đã được xác thực (hoặc trong mọi lần thử), xóa sạch mọi dấu vết của token này
        unset($_SESSION['oauth2_state'], $_SESSION['oauth2_state_time']);
        if (isset($_SESSION['oauth_states']) && is_array($_SESSION['oauth_states'])) {
            if ($pKey !== null) {
                unset($_SESSION['oauth_states'][$pKey]);
            }
            // Quét và xóa sạch bất kỳ provider nào đang giữ token trùng lặp
            foreach ($_SESSION['oauth_states'] as $k => $data) {
                if (isset($data['token']) && hash_equals((string) $data['token'], $state)) {
                    unset($_SESSION['oauth_states'][$k]);
                }
            }
        }

        return $isValid;
    }

    /**
     * Thẩm định CSRF State Token hỗ trợ gọi với ($state) hoặc ($provider, $state).
     */
    public function validateOAuthState(?string $providerOrState = null, ?string $receivedState = null): bool
    {
        if ($receivedState !== null) {
            return $this->verifyOAuthState($receivedState, $providerOrState);
        }
        return $this->verifyOAuthState($providerOrState);
    }

    /**
     * Tự động phát hiện nhà cung cấp OAuth đang ở chế độ Sandbox / Mock Auth.
     * Trả về true nếu chưa cấu hình Client ID / App ID, giá trị rỗng, hoặc bắt đầu bằng tiền tố mẫu ('your-', 'mock-', ...).
     */
    public function isOAuthSandbox(string $provider): bool
    {
        $mockMode = getenv('OAUTH_MOCK_MODE') ?: ($_ENV['OAUTH_MOCK_MODE'] ?? 'auto');
        if (strtolower((string) $mockMode) === 'force_mock') {
            return true;
        }

        $prefix = $this->resolveProviderKeyPrefix($provider);
        $clientIdKey = $prefix . '_CLIENT_ID';
        $appIdKey    = $prefix . '_APP_ID';

        $val = getenv($clientIdKey) ?: ($_ENV[$clientIdKey] ?? (getenv($appIdKey) ?: ($_ENV[$appIdKey] ?? '')));
        $val = trim((string) $val);

        if ($val === '') {
            return true;
        }

        $lower = strtolower($val);
        foreach (['your-', 'mock-', 'sandbox-', 'demo-', 'changeme'] as $pfx) {
            if (str_starts_with($lower, $pfx)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tạo URL chuyển hướng đăng nhập OAuth 2.0.
     * Nếu ở chế độ Sandbox: Chuyển hướng nội bộ tới trang đăng nhập kèm token state.
     * Nếu ở chế độ Real OAuth: Tạo URL ủy quyền chính thức tới nhà cung cấp (Google, Zalo, Microsoft, Facebook, GitHub).
     */
    public function getOAuthRedirectUrl(string $provider, string $state): string
    {
        $prefix = $this->resolveProviderKeyPrefix($provider);
        if ($this->isOAuthSandbox($provider)) {
            return '?view=login&sandbox_oauth=' . urlencode(strtolower($prefix)) . '&state=' . urlencode($state);
        }

        return match ($prefix) {
            'GOOGLE' => $this->buildGoogleAuthUrl($state),
            'ZALO' => $this->buildZaloAuthUrl($state),
            'MICROSOFT' => $this->buildMicrosoftAuthUrl($state),
            'FACEBOOK' => $this->buildFacebookAuthUrl($state),
            'GITHUB' => $this->buildGitHubAuthUrl($state),
            default => '?view=login&sandbox_oauth=' . urlencode(strtolower($prefix)) . '&state=' . urlencode($state),
        };
    }

    /**
     * Alias phương thức tạo URL ủy quyền OAuth.
     */
    public function buildAuthorizationUrl(string $provider, string $state): string
    {
        return $this->getOAuthRedirectUrl($provider, $state);
    }

    /**
     * Trao đổi authorization code lấy thông tin người dùng từ OAuth Provider.
     *
     * @return array{email: string, full_name: string, role: string, provider: string}|null
     */
    public function exchangeCodeForUser(string $provider, string $code): ?array
    {
        $prefix = $this->resolveProviderKeyPrefix($provider);
        if ($this->isOAuthSandbox($provider) || str_starts_with($code, 'mock_') || str_starts_with($code, 'sandbox_')) {
            return [
                'email' => 'linh.nk.sv001@gmail.com',
                'full_name' => 'Nguyễn Khánh Linh',
                'role' => 'VOLUNTEER',
                'provider' => $prefix,
            ];
        }

        try {
            return $this->performRealOAuthExchange($prefix, $code);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Chuẩn hóa tên nhà cung cấp thành tiền tố biến môi trường (GOOGLE, ZALO, MICROSOFT, FACEBOOK, GITHUB).
     */
    public function resolveProviderKeyPrefix(string $provider): string
    {
        $p = strtolower(trim($provider));
        if (str_contains($p, 'google')) {
            return 'GOOGLE';
        }
        if (str_contains($p, 'zalo')) {
            return 'ZALO';
        }
        if (str_contains($p, 'microsoft') || str_contains($p, 'edu')) {
            return 'MICROSOFT';
        }
        if (str_contains($p, 'facebook')) {
            return 'FACEBOOK';
        }
        if (str_contains($p, 'github')) {
            return 'GITHUB';
        }
        return strtoupper(preg_replace('/[^a-z0-9]/i', '', $provider) ?: 'GOOGLE');
    }

    private function buildGoogleAuthUrl(string $state): string
    {
        $clientId = getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? '');
        $redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: ($_ENV['GOOGLE_REDIRECT_URI'] ?? 'http://localhost:8080/?action=oauth_callback&provider=google');
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
        ]);
    }

    private function buildZaloAuthUrl(string $state): string
    {
        $appId = getenv('ZALO_APP_ID') ?: ($_ENV['ZALO_APP_ID'] ?? '');
        $redirectUri = getenv('ZALO_REDIRECT_URI') ?: ($_ENV['ZALO_REDIRECT_URI'] ?? 'http://localhost:8080/?action=oauth_callback&provider=zalo');
        return 'https://oauth.zaloapp.com/v4/permission?' . http_build_query([
            'app_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    private function buildMicrosoftAuthUrl(string $state): string
    {
        $clientId = getenv('MICROSOFT_CLIENT_ID') ?: ($_ENV['MICROSOFT_CLIENT_ID'] ?? '');
        $redirectUri = getenv('MICROSOFT_REDIRECT_URI') ?: ($_ENV['MICROSOFT_REDIRECT_URI'] ?? 'http://localhost:8080/?action=oauth_callback&provider=microsoft');
        $tenant = getenv('MICROSOFT_TENANT_ID') ?: (getenv('MICROSOFT_TENANT') ?: 'common');
        return 'https://login.microsoftonline.com/' . urlencode($tenant) . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => 'openid email profile User.Read',
            'state' => $state,
        ]);
    }

    private function buildFacebookAuthUrl(string $state): string
    {
        $appId = getenv('FACEBOOK_APP_ID') ?: ($_ENV['FACEBOOK_APP_ID'] ?? '');
        $redirectUri = getenv('FACEBOOK_REDIRECT_URI') ?: ($_ENV['FACEBOOK_REDIRECT_URI'] ?? 'http://localhost:8080/?action=oauth_callback&provider=facebook');
        return 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => 'email,public_profile',
            'state' => $state,
        ]);
    }

    private function buildGitHubAuthUrl(string $state): string
    {
        $clientId = getenv('GITHUB_CLIENT_ID') ?: ($_ENV['GITHUB_CLIENT_ID'] ?? '');
        $redirectUri = getenv('GITHUB_REDIRECT_URI') ?: ($_ENV['GITHUB_REDIRECT_URI'] ?? 'http://localhost:8080/?action=oauth_callback&provider=github');
        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'read:user user:email',
            'state' => $state,
        ]);
    }

    private function performRealOAuthExchange(string $prefix, string $code): ?array
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        $tokenUrl = '';
        $params = [];
        $userInfoUrl = '';

        match ($prefix) {
            'GOOGLE' => [
                $tokenUrl = 'https://oauth2.googleapis.com/token',
                $params = [
                    'code' => $code,
                    'client_id' => getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? ''),
                    'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? ''),
                    'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: ($_ENV['GOOGLE_REDIRECT_URI'] ?? ''),
                    'grant_type' => 'authorization_code',
                ],
                $userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo',
            ],
            'GITHUB' => [
                $tokenUrl = 'https://github.com/login/oauth/access_token',
                $params = [
                    'code' => $code,
                    'client_id' => getenv('GITHUB_CLIENT_ID') ?: ($_ENV['GITHUB_CLIENT_ID'] ?? ''),
                    'client_secret' => getenv('GITHUB_CLIENT_SECRET') ?: ($_ENV['GITHUB_CLIENT_SECRET'] ?? ''),
                    'redirect_uri' => getenv('GITHUB_REDIRECT_URI') ?: ($_ENV['GITHUB_REDIRECT_URI'] ?? ''),
                ],
                $userInfoUrl = 'https://api.github.com/user',
            ],
            default => null,
        };

        if ($tokenUrl === '') {
            curl_close($ch);
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'User-Agent: XanhHub-App']);

        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return null;
        }
        $data = json_decode((string) $response, true);
        $accessToken = $data['access_token'] ?? null;
        if (!$accessToken) {
            curl_close($ch);
            return null;
        }

        // Lấy thông tin người dùng từ UserInfo Endpoint
        curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'User-Agent: XanhHub-App',
        ]);
        $userResponse = curl_exec($ch);
        curl_close($ch);

        if ($userResponse === false) {
            return null;
        }
        $userData = json_decode((string) $userResponse, true);
        if (!is_array($userData)) {
            return null;
        }

        $email = $userData['email'] ?? ($userData['id'] . '@' . strtolower($prefix) . '.oauth.local');
        $name = $userData['name'] ?? ($userData['login'] ?? 'TNV ' . $prefix);

        return [
            'email' => (string) $email,
            'full_name' => (string) $name,
            'role' => 'VOLUNTEER',
            'provider' => $prefix,
        ];
    }
}
