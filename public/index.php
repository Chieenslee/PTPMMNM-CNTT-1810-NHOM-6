<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Scheduling\FifoCheckedScheduler;
use XanhHub\Domain\Scheduling\FifoUncheckedScheduler;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Http\ApiController;
use XanhHub\Http\AuthService;
use XanhHub\Http\SvgQrGenerator;
use XanhHub\Http\UiIcons;
use XanhHub\Infrastructure\Ai\HybridAiService;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;
use XanhHub\Infrastructure\Persistence\Database;
use XanhHub\Infrastructure\Persistence\XanhHubRepository;
use XanhHub\Infrastructure\Service\AnalyticsService;
use XanhHub\Infrastructure\Service\CsvSafeExporter;
use XanhHub\Infrastructure\Service\GamificationService;
use XanhHub\Infrastructure\Service\GisService;
use XanhHub\Infrastructure\Service\IcsExporter;
use XanhHub\Infrastructure\Service\QrCheckinService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = Database::getConnection();
$repo = new XanhHubRepository($pdo);
$aiService = new HybridAiService();
$qrService = new QrCheckinService();
$auth = new AuthService($pdo, $repo);
$analyticsService = new AnalyticsService($pdo);
$gamificationService = new GamificationService($pdo);

function cleanCampaignTitle(string $rawTitle): string
{
    $cleaned = (string) preg_replace('/\s*\(Bộ\s+D\d+[^)]*\)/iu', '', trim($rawTitle));
    return $cleaned !== '' ? $cleaned : trim($rawTitle);
}

/**
 * Định dạng thời gian ca trực chuẩn tiếng Việt thân thiện, loại bỏ hoàn toàn chuỗi SQL timestamp thô.
 *
 * @param string|int $start
 * @param string|int $end
 * @return array{
 *   start_ts:int,
 *   end_ts:int,
 *   date_key:string,
 *   day_name:string,
 *   short_date:string,
 *   date_label:string,
 *   time_range:string,
 *   hours_label:string,
 *   session_code:string,
 *   session_label:string,
 *   session_badge:string,
 *   full_label:string
 * }
 */
function formatShiftTimeRangeVn(string|int $start, string|int $end): array
{
    $startTs = is_int($start) ? $start : ((int) strtotime((string) $start) ?: time());
    $endTs = is_int($end) ? $end : ((int) strtotime((string) $end) ?: ($startTs + 3600));

    $dowMap = [
        1 => 'Thứ Hai',
        2 => 'Thứ Ba',
        3 => 'Thứ Tư',
        4 => 'Thứ Năm',
        5 => 'Thứ Sáu',
        6 => 'Thứ Bảy',
        7 => 'Chủ Nhật',
    ];
    $dow = (int) date('N', $startTs);
    $dayName = $dowMap[$dow] ?? 'Ngày';
    $shortDate = date('d/m/Y', $startTs);
    $dateKey = date('Y-m-d', $startTs);
    $dateLabel = "{$dayName}, {$shortDate}";
    $timeRange = date('H:i', $startTs) . ' – ' . date('H:i', $endTs);

    $hours = max(0.5, round(($endTs - $startTs) / 3600, 1));
    $hoursLabel = (floor($hours) == $hours ? (int) $hours : $hours) . ' giờ';

    $startHour = (int) date('G', $startTs);
    if ($startHour < 12) {
        $sessionCode = 'MORNING';
        $sessionLabel = 'Buổi Sáng';
        $sessionBadge = 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:border-amber-800';
    } elseif ($startHour < 17) {
        $sessionCode = 'AFTERNOON';
        $sessionLabel = 'Buổi Chiều';
        $sessionBadge = 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:border-sky-800';
    } else {
        $sessionCode = 'EVENING';
        $sessionLabel = 'Buổi Tối';
        $sessionBadge = 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/50 dark:text-indigo-300 dark:border-indigo-800';
    }

    return [
        'start_ts' => $startTs,
        'end_ts' => $endTs,
        'date_key' => $dateKey,
        'day_name' => $dayName,
        'short_date' => $shortDate,
        'date_label' => $dateLabel,
        'time_range' => $timeRange,
        'hours_label' => $hoursLabel,
        'session_code' => $sessionCode,
        'session_label' => $sessionLabel,
        'session_badge' => $sessionBadge,
        'full_label' => "{$dateLabel} · {$timeRange} ({$hoursLabel})",
    ];
}

/**
 * Bộ chuyển đổi chiến dịch có tìm kiếm & cuộn thông minh (Scalable cho 100+ chiến dịch, không làm vỡ thanh công cụ).
 */
function renderScalableCampaignSwitcher(
    array $campaignMeta,
    int $activeId,
    string $urlTemplate,
    bool $includeAllOption = false,
    int $totalAllShifts = 0
): string {
    $totalCampaigns = count($campaignMeta);
    $activeItem = $campaignMeta[$activeId] ?? reset($campaignMeta);
    $activeTitle = $activeId === 0 && $includeAllOption
        ? "Tất cả chiến dịch ({$totalAllShifts} ca)"
        : (($activeItem['short_title'] ?? 'Chiến dịch') . ' (' . (int) ($activeItem['shifts_count'] ?? 0) . ' ca)');
    $activeCode = $activeId === 0 && $includeAllOption ? 'TẤT CẢ' : ($activeItem['code'] ?? 'CD');

    ob_start();
    ?>
    <div class="relative inline-block text-left campaign-scalable-switcher">
        <details class="group relative">
            <summary class="list-none cursor-pointer inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/80 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-100 transition-all select-none">
                <span class="material-symbols-outlined text-[16px] text-[#0057B8] dark:text-sky-400">campaign</span>
                <span class="px-1.5 py-0.5 rounded bg-[#0057B8] text-white font-mono text-[10px]"><?= h((string) $activeCode) ?></span>
                <span class="max-w-[190px] sm:max-w-[240px] truncate"><?= h((string) $activeTitle) ?></span>
                <span class="px-1.5 py-0.5 rounded-full bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-[10px] font-mono border border-slate-200 dark:border-slate-700" title="Tổng số chiến dịch trên hệ thống">
                    <?= $totalCampaigns ?> chiến dịch
                </span>
                <span class="material-symbols-outlined text-[16px] text-slate-400 group-open:rotate-180 transition-transform">expand_more</span>
            </summary>

            <div class="absolute right-0 mt-2 w-80 sm:w-96 rounded-2xl bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-700 shadow-2xl p-3 z-50">
                <div class="flex items-center justify-between mb-2 px-1">
                    <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Chuyển đổi nhanh (<?= $totalCampaigns ?> chiến dịch)
                    </span>
                    <span class="text-[10px] text-slate-400">Cuộn hoặc gõ để lọc</span>
                </div>
                <div class="relative mb-2.5">
                    <span class="material-symbols-outlined absolute left-2.5 top-2 text-slate-400 text-[16px]">search</span>
                    <input
                        type="search"
                        placeholder="Tìm tên, mã chiến dịch, địa bàn..."
                        class="campaign-quick-filter-input w-full bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl pl-8 pr-3 py-1.5 text-xs text-slate-800 dark:text-slate-100 focus:border-[#0057B8] focus:outline-none"
                    />
                </div>
                <div class="max-h-64 overflow-y-auto pr-1 space-y-1 campaign-quick-filter-list">
                    <?php if ($includeAllOption):
                        $allUrl = str_replace('{id}', '0', $urlTemplate);
                    ?>
                        <a
                            href="<?= h($allUrl) ?>"
                            data-campaign-search="tat ca chien dich all"
                            class="campaign-quick-filter-item flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-xs font-bold transition-colors <?= $activeId === 0 ? 'bg-[#0057B8] text-white shadow-xs' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?>"
                        >
                            <div class="truncate">
                                <span class="font-extrabold">Hiển thị Tất cả Chiến dịch</span>
                                <div class="text-[10px] <?= $activeId === 0 ? 'text-blue-100' : 'text-slate-400' ?>">Tổng hợp toàn bộ điểm trực trên hệ thống</div>
                            </div>
                            <span class="mono text-[10px] px-2 py-0.5 rounded-md shrink-0 <?= $activeId === 0 ? 'bg-white/20 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500' ?>"><?= $totalAllShifts ?> ca</span>
                        </a>
                    <?php endif; ?>

                    <?php foreach ($campaignMeta as $cId => $c):
                        $itemUrl = str_replace('{id}', (string) $cId, $urlTemplate);
                        $isCurrent = ($activeId === (int) $cId);
                        $searchBlob = mb_strtolower(($c['code'] ?? '') . ' ' . ($c['short_title'] ?? '') . ' ' . ($c['title'] ?? '') . ' ' . ($c['location'] ?? ''));
                    ?>
                        <a
                            href="<?= h($itemUrl) ?>"
                            data-campaign-search="<?= h($searchBlob) ?>"
                            class="campaign-quick-filter-item flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-xs transition-colors <?= $isCurrent ? 'bg-[#0057B8] text-white font-bold shadow-xs' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-semibold' ?>"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <span class="mono text-[10px] px-1.5 py-0.5 rounded <?= $isCurrent ? 'bg-white/20 text-white' : 'bg-slate-100 dark:bg-slate-800 text-[#0057B8] dark:text-sky-400' ?> font-bold shrink-0"><?= h((string) $c['code']) ?></span>
                                    <span class="truncate font-bold"><?= h((string) $c['short_title']) ?></span>
                                </div>
                                <div class="text-[10px] truncate mt-0.5 <?= $isCurrent ? 'text-blue-100' : 'text-slate-400' ?>">
                                    <?= h((string) ($c['location'] ?? '')) ?> &middot; <?= h((string) ($c['date_range'] ?? '')) ?>
                                </div>
                            </div>
                            <span class="mono text-[10px] px-2 py-0.5 rounded-md shrink-0 <?= $isCurrent ? 'bg-white/20 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500' ?>">
                                <?= (int) ($c['shifts_count'] ?? 0) ?> ca
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Danh sách chuyển chiến dịch gọn gàng trong Sidebar / Drawer (Có ô lọc nhanh + giới hạn chiều cao cuộn max-h-44 cho 100+ chiến dịch).
 */
function renderDrawerCampaignList(array $campaignMeta, int $activeId, string $urlTemplate): string
{
    ob_start();
    ?>
    <div class="campaign-scalable-switcher space-y-1.5">
        <div class="flex items-center justify-between px-2">
            <span class="text-[11px] font-bold uppercase text-slate-400">Chuyển Chiến dịch</span>
            <span class="mono text-[10px] text-slate-400"><?= count($campaignMeta) ?> hoạt động</span>
        </div>
        <?php if (count($campaignMeta) > 3): ?>
            <div class="relative px-1">
                <span class="material-symbols-outlined absolute left-3 top-2 text-slate-400 text-[15px]">search</span>
                <input
                    type="search"
                    placeholder="Lọc nhanh chiến dịch..."
                    class="campaign-quick-filter-input w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg pl-7 pr-2.5 py-1 text-[11px] text-slate-700 dark:text-slate-200 focus:border-[#0057B8] focus:outline-none"
                />
            </div>
        <?php endif; ?>
        <div class="max-h-44 overflow-y-auto pr-1 space-y-1 campaign-quick-filter-list">
            <?php foreach ($campaignMeta as $actId => $c):
                $itemUrl = str_replace('{id}', (string) $actId, $urlTemplate);
                $isCurrent = ($activeId === (int) $actId);
                $searchBlob = mb_strtolower(($c['code'] ?? '') . ' ' . ($c['short_title'] ?? '') . ' ' . ($c['title'] ?? ''));
            ?>
                <a
                    href="<?= h($itemUrl) ?>"
                    data-campaign-search="<?= h($searchBlob) ?>"
                    class="campaign-quick-filter-item flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-xs font-semibold transition-colors <?= $isCurrent ? 'bg-[#0057B8] text-white shadow-xs' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800' ?>"
                >
                    <span class="truncate"><?= h((string) $c['short_title']) ?></span>
                    <span class="mono text-[10px] px-1.5 py-0.5 rounded shrink-0 <?= $isCurrent ? 'bg-white/20 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500' ?>"><?= (int) ($c['shifts_count'] ?? 0) ?> ca</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

$campaignMeta = [
    1 => [
        'code' => 'D1',
        'title' => 'Chiến dịch Chủ Nhật Xanh — Làm sạch Kênh Nhiêu Lộc & Trồng cây đô thị',
        'short_title' => 'Chủ Nhật Xanh',
        'desc' => 'Ra quân cải tạo cảnh quan tuyến kênh Nhiêu Lộc – Thị Nghè, trồng mới 500 cây xanh đô thị và tuyên truyền phân loại rác tại nguồn.',
        'cover' => 'assets/images/campaign-d1.jpg',
        'location' => 'Quận 1, Quận 3 & Phú Nhuận, TP.HCM',
        'date_range' => '06/11/2026 – 08/11/2026',
        'tag' => 'Môi trường & Đô thị xanh',
        'volunteers_needed' => 30,
        'shifts_count' => 12,
    ],
    2 => [
        'code' => 'D2',
        'title' => 'Chiến dịch Mùa Hè Xanh Vùng Cao — Trực Y tế & Xây dựng Cộng đồng',
        'short_title' => 'Mùa Hè Xanh Vùng Cao',
        'desc' => 'Đội hình tình nguyện chuyên môn hỗ trợ trạm y tế xã, chăm sóc sức khỏe cộng đồng, chuyển đổi số và cải tạo điểm trường vùng sâu.',
        'cover' => 'assets/images/campaign-d2.jpg',
        'location' => 'Huyện Cần Giờ & Củ Chi, TP.HCM',
        'date_range' => '13/11/2026 – 15/11/2026',
        'tag' => 'Y tế & Phát triển Cộng đồng',
        'volunteers_needed' => 30,
        'shifts_count' => 12,
    ],
    3 => [
        'code' => 'D3',
        'title' => 'Chiến dịch Tiếp Sức Mùa Thi 2026 — Hỗ trợ Thí sinh & Điều tiết Giao thông',
        'short_title' => 'Tiếp Sức Mùa Thi 2026',
        'desc' => 'Chiến dịch cao điểm hỗ trợ thí sinh tại các cụm điểm thi đại học: Trực sơ cứu y tế, hậu cần nước uống, phân luồng giao thông và truyền thông.',
        'cover' => 'assets/images/campaign-d3.jpg',
        'location' => 'TP. Thủ Đức & Cụm ĐH Quốc gia TP.HCM',
        'date_range' => '20/11/2026 – 22/11/2026',
        'tag' => 'Tiếp sức Mùa thi Trọng điểm',
        'volunteers_needed' => 30,
        'shifts_count' => 12,
    ],
];

// Làm sạch hậu tố kịch bản kiểm thử "(Bộ D1...)" trong CSDL và đồng bộ $campaignMeta thực tế
try {
    $pdo->exec("UPDATE activities SET title = regexp_replace(title, '\\s*\\(Bộ\\s+D\\d+[^)]*\\)', '', 'gi') WHERE title LIKE '%(Bộ D%'");

    $dbActs = $pdo->query('
        SELECT a.id, a.code, a.title, a.description, a.location_name,
               COUNT(s.id) AS shifts_count,
               COALESCE(SUM(s.max_people), 0) AS total_max_people,
               MIN(s.start_time)::text AS min_start,
               MAX(s.end_time)::text AS max_end
          FROM activities a
          LEFT JOIN shifts s ON s.activity_id = a.id
         GROUP BY a.id, a.code, a.title, a.description, a.location_name
         ORDER BY a.id ASC
    ')->fetchAll();

    foreach ($dbActs as $actRow) {
        $aid = (int) $actRow['id'];
        $cleanTitle = cleanCampaignTitle((string) $actRow['title']);
        $startDate = !empty($actRow['min_start']) ? date('d/m/Y', strtotime($actRow['min_start'])) : '01/07/2026';
        $endDate = !empty($actRow['max_end']) ? date('d/m/Y', strtotime($actRow['max_end'])) : '15/07/2026';
        $dateRangeStr = ($startDate === $endDate) ? $startDate : "{$startDate} – {$endDate}";

        if (isset($campaignMeta[$aid])) {
            $campaignMeta[$aid]['shifts_count'] = (int) $actRow['shifts_count'];
            $campaignMeta[$aid]['date_range'] = $dateRangeStr;
            if ((int) $actRow['total_max_people'] > 0) {
                $campaignMeta[$aid]['volunteers_needed'] = (int) $actRow['total_max_people'];
            }
        } else {
            $coverIdx = (($aid - 1) % 3) + 1;
            $campaignMeta[$aid] = [
                'code' => (string) $actRow['code'],
                'title' => $cleanTitle,
                'short_title' => $cleanTitle,
                'desc' => (string) ($actRow['description'] !== '' ? $actRow['description'] : 'Chiến dịch tình nguyện thanh niên Xanh Hub.'),
                'cover' => "assets/images/campaign-d{$coverIdx}.jpg",
                'location' => (string) ($actRow['location_name'] !== '' ? $actRow['location_name'] : 'TP. Hồ Chí Minh'),
                'date_range' => $dateRangeStr,
                'tag' => 'Chiến dịch Hoạt động',
                'volunteers_needed' => max(5, (int) $actRow['total_max_people']),
                'shifts_count' => (int) $actRow['shifts_count'],
            ];
        }
    }
} catch (\Throwable) {
    // Dự phòng an toàn nếu CSDL chưa kết nối
}

$action = (string) ($_GET['action'] ?? '');
$flashMessage = $_SESSION['flash_msg'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// Khởi tạo OAuth State Token & Phát hiện chế độ Sandbox cho 5 nhà cung cấp
$isGoogleSandbox = $auth->isOAuthSandbox('google');
$isZaloSandbox = $auth->isOAuthSandbox('zalo');
$isMicrosoftSandbox = $auth->isOAuthSandbox('microsoft');
$isFacebookSandbox = $auth->isOAuthSandbox('facebook');
$isGithubSandbox = $auth->isOAuthSandbox('github');
$hasAnySandbox = $isGoogleSandbox || $isZaloSandbox || $isMicrosoftSandbox || $isFacebookSandbox || $isGithubSandbox;

$currentOAuthState = $_SESSION['oauth2_state'] ?? '';
if ($currentOAuthState === '' || empty($_SESSION['oauth2_state_time']) || (time() - (int)$_SESSION['oauth2_state_time'] > 540)) {
    $currentOAuthState = $auth->generateOAuthState();
}

// ============================================================================
// 1. XỬ LÝ ĐĂNG NHẬP, ĐĂNG KÝ, ĐĂNG XUẤT & PHÂN QUYỀN (RBAC THẬT)
// ============================================================================
if ($action === 'logout') {
    $auth->logout();
    $_SESSION['flash_msg'] = 'Đã đăng xuất khỏi hệ thống Xanh Hub.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ?view=home');
    exit;
}

if ($action === 'do_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = (string) ($_POST['identifier'] ?? '');
    $pass  = (string) ($_POST['password'] ?? '');
    $res = $auth->login($ident, $pass);
    if ($res['ok']) {
        $_SESSION['flash_msg'] = $res['message_vi'];
        $_SESSION['flash_type'] = 'success';
        $targetView = (string) ($_POST['redirect_view'] ?? '');
        if ($targetView === '' || ($res['role'] === 'VOLUNTEER' && $targetView === 'coordinator')) {
            $targetView = $res['redirect_view'] ?? 'coordinator';
        }
        header('Location: ?view=' . urlencode($targetView) . '&activity_id=3');
        exit;
    }
    $flashMessage = $res['message_vi'];
    $flashType = 'error';
    $_GET['view'] = 'login';
}

if ($action === 'oauth_redirect') {
    $provider = (string) ($_GET['provider'] ?? 'google');
    $state = $auth->generateOAuthState($provider);
    $redirectUrl = $auth->getOAuthRedirectUrl($provider, $state);
    header('Location: ' . $redirectUrl);
    exit;
}

if ($action === 'oauth_callback') {
    $provider = (string) ($_GET['provider'] ?? 'google');
    $state = (string) ($_GET['state'] ?? '');
    $code = (string) ($_GET['code'] ?? '');
    $isSandbox = ($_GET['sandbox'] ?? '') === '1' || $auth->isOAuthSandbox($provider);

    if (!$auth->verifyOAuthState($state, $provider)) {
        $_SESSION['flash_msg'] = 'Lỗi bảo mật CSRF: State token OAuth không hợp lệ hoặc phiên xác thực đã hết hạn (10 phút). Vui lòng thử lại.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ?view=login');
        exit;
    }

    if ($isSandbox) {
        $res = $auth->loginWithOAuthProvider($provider, 'linh.nk.sv001@gmail.com', 'Nguyễn Khánh Linh', 'VOLUNTEER');
    } else {
        $userInfo = $auth->exchangeCodeForUser($provider, $code);
        if ($userInfo === null) {
            $_SESSION['flash_msg'] = 'Không thể xác thực mã truy cập từ ' . htmlspecialchars($provider) . '. Vui lòng thử lại.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=login');
            exit;
        }
        $res = $auth->loginWithOAuthProvider($provider, $userInfo['email'], $userInfo['full_name'], $userInfo['role'] ?? 'VOLUNTEER');
    }

    if ($res['ok']) {
        $_SESSION['flash_msg'] = $res['message_vi'];
        $_SESSION['flash_type'] = 'success';
        $targetView = $res['redirect_view'] ?? 'volunteer';
        header('Location: ?view=' . urlencode($targetView) . '&activity_id=3');
        exit;
    }

    $_SESSION['flash_msg'] = $res['message_vi'];
    $_SESSION['flash_type'] = 'error';
    header('Location: ?view=login');
    exit;
}

if ($action === 'oauth_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? ($_POST['state'] ?? ($_POST['csrf_state'] ?? '')));
    $provider = (string) ($_POST['provider'] ?? 'GOOGLE');

    if (!$auth->verifyOAuthState($csrfToken, $provider)) {
        $_SESSION['flash_msg'] = 'Lỗi bảo mật CSRF: State token không hợp lệ hoặc phiên xác thực đã hết hạn (10 phút). Vui lòng tải lại trang và thử lại.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ?view=login');
        exit;
    }

    $email    = (string) ($_POST['email'] ?? 'linh.nk.sv001@gmail.com');
    $fullName = (string) ($_POST['full_name'] ?? 'Nguyễn Khánh Linh');
    $roleHint = (string) ($_POST['account_role'] ?? 'VOLUNTEER');

    $res = $auth->loginWithOAuthProvider($provider, $email, $fullName, $roleHint);
    if ($res['ok']) {
        $_SESSION['flash_msg'] = $res['message_vi'];
        $_SESSION['flash_type'] = 'success';
        $targetView = $res['redirect_view'] ?? 'volunteer';
        header('Location: ?view=' . urlencode($targetView) . '&activity_id=3');
        exit;
    }
    $flashMessage = $res['message_vi'];
    $flashType = 'error';
    $_GET['view'] = 'login';
}

if ($action === 'do_register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $skillIds = isset($_POST['skill_ids']) && is_array($_POST['skill_ids'])
        ? array_map('intval', $_POST['skill_ids'])
        : [2];

    $res = $auth->registerVolunteer(
        fullName: (string) ($_POST['full_name'] ?? ''),
        studentCode: (string) ($_POST['student_code'] ?? ''),
        email: (string) ($_POST['email'] ?? ''),
        phone: (string) ($_POST['phone'] ?? ''),
        chapterName: (string) ($_POST['chapter_name'] ?? ''),
        password: (string) ($_POST['password'] ?? ''),
        skillIds: $skillIds,
        skillLevel: (int) ($_POST['skill_level'] ?? 3)
    );

    if ($res['ok']) {
        $_SESSION['flash_msg'] = $res['message_vi'];
        $_SESSION['flash_type'] = 'success';
        header('Location: ?view=volunteer&activity_id=3');
        exit;
    }
    $flashMessage = $res['message_vi'];
    $flashType = 'error';
    $_GET['view'] = 'register';
}

$authUser = $_SESSION['auth_user'] ?? null;
$currentRole = is_array($authUser) ? (string) $authUser['role'] : (string) ($_SESSION['role'] ?? 'PUBLIC');
if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
    unset($_SESSION['volunteer_id']);
    $currentVolunteerId = null;
} elseif ($currentRole === 'VOLUNTEER') {
    $currentVolunteerId = is_array($authUser)
        ? (int) $authUser['id']
        : (isset($_SESSION['volunteer_id']) ? (int) $_SESSION['volunteer_id'] : 1);
} else {
    $currentVolunteerId = null;
}

$actorUserId = is_array($authUser) ? (int) $authUser['id'] : match ($currentRole) {
    'COORDINATOR' => 31,
    'ADMIN' => 32,
    'VOLUNTEER' => $currentVolunteerId ?? 1,
    default => null,
};

// ============================================================================
// 2. ĐỊNH TUYẾN CÁC ENDPOINT JSON API THỜI GIAN THỰC (ApiController)
// ============================================================================
if (str_starts_with($action, 'api_')) {
    $api = new ApiController($pdo, $repo, $aiService, $qrService, $gamificationService);
    $api->handle($action, $currentRole, $actorUserId, $currentVolunteerId);
    exit;
}

// ============================================================================
// 3. XUẤT TỆP LỊCH .ICS (RFC 5545) VÀ BẢNG PHÂN CÔNG .CSV AN TOÀN
// ============================================================================
if ($action === 'export_ics') {
    if ($currentRole === 'PUBLIC') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Lỗi 403: Vui lòng đăng nhập để tải lịch ca trực cá nhân (.ICS).';
        exit;
    }
    $actId = (int) ($_GET['activity_id'] ?? 3);
    $volId = ($currentRole === 'VOLUNTEER')
        ? (int) $currentVolunteerId
        : (int) ($_GET['vid'] ?? $currentVolunteerId);
    $ds = $repo->loadDatasetForActivity($actId);
    $vol = $ds->volunteersById[$volId] ?? null;
    $activeDecisions = $repo->loadActiveAssignmentDecisions($actId);

    $myShifts = [];
    foreach ($activeDecisions as $d) {
        if ($d->volunteerId === $volId && isset($ds->shiftsById[$d->shiftId])) {
            $myShifts[] = $ds->shiftsById[$d->shiftId];
        }
    }

    $icsContent = IcsExporter::exportVolunteerCalendar(
        $vol !== null ? $vol->fullName : 'Tình nguyện viên',
        'Chiến dịch Xanh Hub #' . $actId,
        $myShifts
    );

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="lich-tinh-nguyen-SV' . sprintf('%03d', $volId) . '.ics"');
    echo $icsContent;
    exit;
}

if ($action === 'export_csv') {
    if (!in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Lỗi 403: Chỉ Người phụ trách (Ban Chỉ huy) và Quản trị viên mới có quyền xuất danh sách phân công (.CSV).';
        exit;
    }
    $actId = (int) ($_GET['activity_id'] ?? 3);
    $csv = CsvSafeExporter::exportDetailedAssignments($pdo, $actId, $campaignMeta);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="danh-sach-phan-cong-diem-danh-gio-cong-hien-hoat-dong-' . $actId . '.csv"');
    echo $csv;
    exit;
}

if ($action === 'export_analytics_csv') {
    if (!in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Lỗi 403: Chỉ Người phụ trách (Ban Chỉ huy) và Quản trị viên mới có quyền xuất thống kê chiến dịch (.CSV).';
        exit;
    }
    $actId = (int) ($_GET['activity_id'] ?? 3);
    $reportData = $analyticsService->getCampaignFormalReport($actId);
    $csv = CsvSafeExporter::exportAnalyticsSummaryCsv($reportData);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tong-hop-so-lieu-tac-dong-xa-hoi-hoat-dong-' . $actId . '.csv"');
    echo $csv;
    exit;
}

// ============================================================================
// 4. XỬ LÝ BIỂU MẪU NGHIỆP VỤ POST
// ============================================================================
$customLabWeights = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['do_login', 'do_register', 'oauth_login'], true)) {
    if ($action === 'run_scheduler') {
        if (!in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
            $flashMessage = 'Lỗi 403: Chỉ Điều phối viên hoặc Quản trị Đoàn mới được chạy thuật toán phân công.';
            $flashType = 'error';
        } else {
            $actId = (int) ($_POST['activity_id'] ?? 3);
            $mode = (string) ($_POST['priority_mode'] ?? SchedulerWeights::MODE_MAX_COVERAGE);
            $baseW = SchedulerWeights::fromMode($mode, 202606);

            $weights = new SchedulerWeights(
                priorityMode: $mode,
                wPriority: isset($_POST['w_priority']) && $_POST['w_priority'] !== '' ? (float) $_POST['w_priority'] : $baseW->wPriority,
                wPreference: isset($_POST['w_preference']) && $_POST['w_preference'] !== '' ? (float) $_POST['w_preference'] : $baseW->wPreference,
                wSkillWaste: isset($_POST['w_skill_waste']) && $_POST['w_skill_waste'] !== '' ? (float) $_POST['w_skill_waste'] : $baseW->wSkillWaste,
                wLoad: isset($_POST['w_load']) && $_POST['w_load'] !== '' ? (float) $_POST['w_load'] : $baseW->wLoad,
                wAbsentRisk: 0.0,
                randomSeed: 202606
            );
            $customLabWeights = $weights;

            $ds = ($actId === 2) ? DatasetGenerator::createD2UnderstaffedDataset(2) : $repo->loadDatasetForActivity($actId);
            $planB0 = (new FifoUncheckedScheduler())->schedule($ds, 202606);
            $planB1 = (new FifoCheckedScheduler())->schedule($ds, 202606);
            $planX  = (new XanhHubScheduler())->schedule($ds, $weights);

            $repo->savePlanOutput($ds, $planB0, $actorUserId);
            $repo->savePlanOutput($ds, $planB1, $actorUserId);
            $planXId = $repo->savePlanOutput($ds, $planX, $actorUserId);

            if (!empty($_POST['apply_immediately'])) {
                $applyRes = $repo->applyPlanToAssignments($planXId, $actorUserId);
                $flashMessage = $applyRes['message_vi'];
                $flashType = $applyRes['ok'] ? 'success' : 'error';
            } else {
                $flashMessage = sprintf('Đã chạy thuật toán Xanh Hub (%s, Mã phương án #%d).', $mode, $planXId);
            }
        }
    } elseif ($action === 'create_shift_from_draft') {
        if (!in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
            $flashMessage = 'Lỗi 403: Không có quyền tạo ca trực.';
            $flashType = 'error';
        } else {
            $actId = (int) ($_POST['activity_id'] ?? 3);
            $code = 'CA-' . strtoupper(substr(md5((string) microtime(true)), 0, 4));
            $title = trim((string) ($_POST['title'] ?? 'Ca tình nguyện mới'));
            $zone = trim((string) ($_POST['zone'] ?? 'Khu vực trung tâm'));
            $dateYmd = trim((string) ($_POST['date_ymd'] ?? '2026-06-13'));
            $startHm = trim((string) ($_POST['start_hm'] ?? '07:30'));
            $endHm = trim((string) ($_POST['end_hm'] ?? '11:30'));
            $minP = max(1, (int) ($_POST['min_people'] ?? 2));
            $maxP = max($minP, (int) ($_POST['max_people'] ?? 3));
            $prio = max(1, min(5, (int) ($_POST['priority_weight'] ?? 2)));
            $skillCode = trim((string) ($_POST['skill_code'] ?? 'LOGISTICS'));
            $minLvl = max(1, min(3, (int) ($_POST['min_level'] ?? 2)));

            $stmtSk = $pdo->prepare('SELECT id FROM skills WHERE code = :c LIMIT 1');
            $stmtSk->execute([':c' => $skillCode]);
            $skillId = (int) ($stmtSk->fetchColumn() ?: 1);

            $startIso = "{$dateYmd}T{$startHm}:00+07:00";
            $endIso = "{$dateYmd}T{$endHm}:00+07:00";

            $stmtS = $pdo->prepare("
                INSERT INTO shifts (activity_id, code, title, start_time, end_time, during, min_people, max_people, priority, location_name)
                VALUES (:aid, :code, :title, :st::timestamptz, :et::timestamptz, tstzrange(:st::timestamptz, :et::timestamptz, '[)'), :minp, :maxp, :prio, :loc)
                RETURNING id
            ");
            $stmtS->execute([
                ':aid' => $actId,
                ':code' => $code,
                ':title' => $title,
                ':st' => $startIso,
                ':et' => $endIso,
                ':minp' => $minP,
                ':maxp' => $maxP,
                ':prio' => $prio,
                ':loc' => $zone,
            ]);
            $newShiftId = (int) $stmtS->fetchColumn();

            $pdo->prepare('
                INSERT INTO shift_requirements (shift_id, skill_id, min_level, required_count, require_verified)
                VALUES (:sid, :skid, :minl, :rcnt, FALSE)
            ')->execute([
                ':sid' => $newShiftId,
                ':skid' => $skillId,
                ':minl' => $minLvl,
                ':rcnt' => $maxP,
            ]);

            $repo->appendAuditLog($actorUserId, 'SHIFT_CREATED', 'shifts', (string) $newShiftId, 'Tạo ca trực mới', ['code' => $code, 'title' => $title]);
            $flashMessage = "Đã tạo ca trực mới [{$code}] {$title} thành công!";
        }
    } elseif ($action === 'volunteer_register_shift') {
        if ($currentRole === 'PUBLIC') {
            http_response_code(403);
            $flashType = 'error';
            $flashMessage = 'Lỗi 403: Vui lòng đăng nhập tài khoản Tình nguyện viên để đăng ký ca trực.';
        } else {
            $actId = (int) ($_POST['activity_id'] ?? 3);
            $shiftId = (int) ($_POST['shift_id'] ?? 0);
            $volId = ($currentRole === 'VOLUNTEER') ? (int) $currentVolunteerId : (int) ($_POST['volunteer_id'] ?? $currentVolunteerId);
            $pref = max(1, min(3, (int) ($_POST['preference_rank'] ?? 1)));

            $pdo->prepare("
                INSERT INTO registrations (activity_id, shift_id, volunteer_id, preference_rank, status, registered_at)
                VALUES (:aid, :sid, :vid, :pref, 'ACTIVE', NOW())
                ON CONFLICT (shift_id, volunteer_id)
                DO UPDATE SET preference_rank = EXCLUDED.preference_rank, status = 'ACTIVE', registered_at = NOW()
            ")->execute([':aid' => $actId, ':sid' => $shiftId, ':vid' => $volId, ':pref' => $pref]);
            $repo->appendAuditLog($actorUserId, 'REGISTRATION_CREATED', 'registrations', "{$shiftId}_{$volId}", "Tình nguyện viên #{$volId} đăng ký ca #{$shiftId} (Nguyện vọng {$pref})");
            $flashMessage = "Đã đăng ký nguyện vọng {$pref} cho ca trực #{$shiftId}!";
            $_GET['vtab'] = 'vtab-register';
        }
    } elseif ($action === 'volunteer_withdraw_registration') {
        if ($currentRole === 'PUBLIC') {
            http_response_code(403);
            $flashType = 'error';
            $flashMessage = 'Lỗi 403: Vui lòng đăng nhập để rút đăng ký ca trực.';
        } else {
            $actId = (int) ($_POST['activity_id'] ?? 3);
            $shiftId = (int) ($_POST['shift_id'] ?? 0);
            $volId = ($currentRole === 'VOLUNTEER') ? (int) $currentVolunteerId : (int) ($_POST['volunteer_id'] ?? $currentVolunteerId);
            $_GET['vtab'] = 'vtab-register';

            $stmtCheckShift = $pdo->prepare('SELECT code, title, is_locked FROM shifts WHERE id = :sid LIMIT 1');
            $stmtCheckShift->execute([':sid' => $shiftId]);
            $shiftInfo = $stmtCheckShift->fetch();

            if ($shiftInfo && !empty($shiftInfo['is_locked'])) {
                $flashType = 'error';
                $flashMessage = "Không thể rút đăng ký: Ca trực [{$shiftInfo['code']}] đã khóa chốt danh sách nhân sự!";
            } else {
                $pdo->prepare("
                    UPDATE registrations
                       SET status = 'WITHDRAWN'
                     WHERE shift_id = :sid AND volunteer_id = :vid
                ")->execute([':sid' => $shiftId, ':vid' => $volId]);

                // Nếu ca có phân công tạm thời (ASSIGNED) chưa xác nhận tham gia thì gỡ phân công tạm đó
                $pdo->prepare("
                    DELETE FROM assignments
                     WHERE shift_id = :sid AND volunteer_id = :vid AND status = 'ASSIGNED'
                ")->execute([':sid' => $shiftId, ':vid' => $volId]);

                $repo->appendAuditLog(
                    $actorUserId,
                    'REGISTRATION_WITHDRAWN',
                    'registrations',
                    "{$shiftId}_{$volId}",
                    "Tình nguyện viên #{$volId} rút đăng ký khỏi ca trực #{$shiftId}"
                );
                $flashMessage = "Đã rút đăng ký khỏi ca trực #{$shiftId} thành công!";
            }
        }
    } elseif ($action === 'volunteer_delete_availability') {
        if ($currentRole === 'PUBLIC') {
            http_response_code(403);
            $flashType = 'error';
            $flashMessage = 'Lỗi 403: Vui lòng đăng nhập để cập nhật lịch rảnh.';
        } else {
            $volId = ($currentRole === 'VOLUNTEER') ? (int) $currentVolunteerId : (int) ($_POST['volunteer_id'] ?? $currentVolunteerId);
            $overrideDate = trim((string) ($_POST['override_date'] ?? ''));
            $dow = (int) ($_POST['day_of_week'] ?? 0);
            $startHm = trim((string) ($_POST['start_hm'] ?? ''));
            $_GET['vtab'] = 'vtab-profile';

            if ($volId > 0 && $overrideDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $overrideDate)) {
                $pdo->prepare('DELETE FROM availability_overrides WHERE volunteer_id = :vid AND override_date = :od')
                    ->execute([':vid' => $volId, ':od' => $overrideDate]);
                $fmtD = date('d/m/Y', strtotime($overrideDate));
                $repo->appendAuditLog($actorUserId, 'AVAILABILITY_DATE_DELETED', 'availability_overrides', "{$volId}_{$overrideDate}", "Xóa lịch ngày cụ thể {$fmtD} của TNV #{$volId}");
                $flashMessage = "Đã xóa lịch ngày cụ thể {$fmtD} thành công!";
            } elseif ($volId > 0 && $dow >= 1 && $dow <= 7) {
                if ($startHm !== '') {
                    $pdo->prepare('DELETE FROM availability WHERE volunteer_id = :vid AND day_of_week = :dow AND start_time = :st::time')
                        ->execute([':vid' => $volId, ':dow' => $dow, ':st' => substr($startHm, 0, 5) . ':00']);
                } else {
                    $pdo->prepare('DELETE FROM availability WHERE volunteer_id = :vid AND day_of_week = :dow')
                        ->execute([':vid' => $volId, ':dow' => $dow]);
                }
                $repo->appendAuditLog($actorUserId, 'AVAILABILITY_DELETED', 'availability', "{$volId}_{$dow}", "Xóa khung lịch rảnh Thứ {$dow} của TNV #{$volId}");
                $flashMessage = "Đã xóa khung lịch rảnh thành công!";
            }
        }
    } elseif ($action === 'volunteer_save_availability') {
        if ($currentRole === 'PUBLIC') {
            http_response_code(403);
            $flashType = 'error';
            $flashMessage = 'Lỗi 403: Vui lòng đăng nhập để lưu lịch rảnh.';
        } else {
            $volId = ($currentRole === 'VOLUNTEER') ? (int) $currentVolunteerId : (int) ($_POST['volunteer_id'] ?? $currentVolunteerId);
            $_GET['vtab'] = 'vtab-profile';

            if ($volId > 0) {
                $mode = (string) ($_POST['avail_mode'] ?? '');
                $rawMatrix = $_POST['matrix_slots'] ?? ($_POST['slots'] ?? null);

                if ($mode === 'matrix' || is_array($rawMatrix)) {
                    $slotsArr = is_array($rawMatrix) ? $rawMatrix : [];
                    $byDay = [];
                    foreach ($slotsArr as $token) {
                        if (preg_match('/^([1-7])_(MORNING|AFTERNOON|EVENING)$/', (string) $token, $m)) {
                            $d = (int) $m[1];
                            $byDay[$d][$m[2]] = true;
                        }
                    }

                    $pdo->prepare('DELETE FROM availability WHERE volunteer_id = :vid')->execute([':vid' => $volId]);
                    $stmtInsAvail = $pdo->prepare('
                        INSERT INTO availability (volunteer_id, day_of_week, start_time, end_time, is_recurring)
                        VALUES (:vid, :dow, :st, :et, TRUE)
                    ');

                    $savedSlotsCount = 0;
                    for ($d = 1; $d <= 7; $d++) {
                        $morn = !empty($byDay[$d]['MORNING']);
                        $aft  = !empty($byDay[$d]['AFTERNOON']);
                        $eve  = !empty($byDay[$d]['EVENING']);

                        if ($morn && $aft && $eve) {
                            $stmtInsAvail->execute([':vid' => $volId, ':dow' => $d, ':st' => '06:30:00', ':et' => '22:00:00']);
                            $savedSlotsCount++;
                        } elseif ($morn && $aft) {
                            $stmtInsAvail->execute([':vid' => $volId, ':dow' => $d, ':st' => '06:30:00', ':et' => '18:00:00']);
                            $savedSlotsCount++;
                        } elseif ($aft && $eve) {
                            $stmtInsAvail->execute([':vid' => $volId, ':dow' => $d, ':st' => '12:30:00', ':et' => '22:00:00']);
                            $savedSlotsCount++;
                        } else {
                            if ($morn) {
                                $stmtInsAvail->execute([':vid' => $volId, ':dow' => $d, ':st' => '06:30:00', ':et' => '12:00:00']);
                                $savedSlotsCount++;
                            }
                            if ($aft) {
                                $stmtInsAvail->execute([':vid' => $volId, ':dow' => $d, ':st' => '12:30:00', ':et' => '18:00:00']);
                                $savedSlotsCount++;
                            }
                            if ($eve) {
                                $stmtInsAvail->execute([':vid' => $volId, ':dow' => $d, ':st' => '18:00:00', ':et' => '22:00:00']);
                                $savedSlotsCount++;
                            }
                        }
                    }

                    $repo->appendAuditLog($actorUserId, 'AVAILABILITY_MATRIX_SAVED', 'availability', (string) $volId, "Tình nguyện viên #{$volId} lưu bảng ma trận lịch rảnh 7 ngày ({$savedSlotsCount} khung giờ)");
                    $flashMessage = "Đã lưu bảng Lịch rảnh 7 ngày ({$savedSlotsCount} khung giờ) thành công!";
                } else {
                    $overrideDate = trim((string) ($_POST['override_date'] ?? ''));
                    $dayOfWeek = max(1, min(7, (int) ($_POST['day_of_week'] ?? 6)));
                    $startHm = substr(trim((string) ($_POST['start_hm'] ?? '07:00')), 0, 5);
                    $endHm = substr(trim((string) ($_POST['end_hm'] ?? '18:00')), 0, 5);
                    $isAvailable = !isset($_POST['is_available']) || (string) $_POST['is_available'] !== '0';

                    if ($endHm <= $startHm) {
                        $flashType = 'error';
                        $flashMessage = 'Giờ kết thúc phải lớn hơn giờ bắt đầu!';
                    } elseif ($overrideDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $overrideDate)) {
                        $pdo->prepare('DELETE FROM availability_overrides WHERE volunteer_id = :vid AND override_date = :od')
                            ->execute([':vid' => $volId, ':od' => $overrideDate]);
                        $pdo->prepare('
                            INSERT INTO availability_overrides (volunteer_id, override_date, start_time, end_time, is_available, reason)
                            VALUES (:vid, :od, :st, :et, :av, :rsn)
                        ')->execute([
                            ':vid' => $volId,
                            ':od' => $overrideDate,
                            ':st' => $startHm . ':00',
                            ':et' => $endHm . ':00',
                            ':av' => $isAvailable ? 'true' : 'false',
                            ':rsn' => $isAvailable ? 'Đăng ký ngày rảnh cụ thể' : 'Báo bận ngày cụ thể',
                        ]);
                        $fmtDate = date('d/m/Y', strtotime($overrideDate));
                        $repo->appendAuditLog($actorUserId, 'AVAILABILITY_DATE_SAVED', 'availability_overrides', "{$volId}_{$overrideDate}", "Lưu lịch ngày cụ thể {$fmtDate}: {$startHm}-{$endHm}");
                        $flashMessage = "Đã lưu lịch ngày cụ thể {$fmtDate} ({$startHm} – {$endHm}) thành công!";
                    } else {
                        $pdo->prepare('DELETE FROM availability WHERE volunteer_id = :vid AND day_of_week = :dow')
                            ->execute([':vid' => $volId, ':dow' => $dayOfWeek]);
                        $pdo->prepare('
                            INSERT INTO availability (volunteer_id, day_of_week, start_time, end_time, is_recurring)
                            VALUES (:vid, :dow, :st, :et, TRUE)
                        ')->execute([
                            ':vid' => $volId,
                            ':dow' => $dayOfWeek,
                            ':st' => $startHm . ':00',
                            ':et' => $endHm . ':00',
                        ]);
                        $repo->appendAuditLog($actorUserId, 'AVAILABILITY_UPDATED', 'availability', "{$volId}_{$dayOfWeek}", "Lưu lịch rảnh thứ {$dayOfWeek}: {$startHm}-{$endHm}");
                        $flashMessage = "Đã lưu khung lịch rảnh định kỳ ({$startHm} – {$endHm}) thành công!";
                    }
                }
            }
        }
    } elseif ($action === 'volunteer_save_skills') {
        if ($currentRole === 'PUBLIC') {
            http_response_code(403);
            $flashType = 'error';
            $flashMessage = 'Lỗi 403: Vui lòng đăng nhập để cập nhật kỹ năng.';
        } else {
            $volId = ($currentRole === 'VOLUNTEER') ? (int) $currentVolunteerId : (int) ($_POST['volunteer_id'] ?? $currentVolunteerId);
            $_GET['vtab'] = 'vtab-profile';
            $inputSkills = $_POST['skills'] ?? ($_POST['skill_level'] ?? []);

            if ($volId > 0) {
                $stmtUpsertSkill = $pdo->prepare('
                    INSERT INTO volunteer_skills (volunteer_id, skill_id, level, is_verified)
                    VALUES (:vid, :sid, :lvl, TRUE)
                    ON CONFLICT (volunteer_id, skill_id)
                    DO UPDATE SET level = EXCLUDED.level
                ');
                $stmtDeleteSkill = $pdo->prepare('DELETE FROM volunteer_skills WHERE volunteer_id = :vid AND skill_id = :sid');

                if (is_array($inputSkills)) {
                    foreach ($inputSkills as $sid => $lvl) {
                        $sidInt = (int) $sid;
                        $lvlInt = (int) $lvl;
                        if ($sidInt >= 1) {
                            if ($lvlInt <= 0) {
                                $stmtDeleteSkill->execute([':vid' => $volId, ':sid' => $sidInt]);
                            } else {
                                $stmtUpsertSkill->execute([
                                    ':vid' => $volId,
                                    ':sid' => $sidInt,
                                    ':lvl' => max(1, min(5, $lvlInt)),
                                ]);
                            }
                        }
                    }
                }

                // Hỗ trợ thêm "+ Kỹ năng khác" trực tiếp vào bảng skills & volunteer_skills
                $customSkillName = trim((string) ($_POST['custom_skill_name'] ?? ''));
                $customSkillLvl = max(1, min(5, (int) ($_POST['custom_skill_level'] ?? 3)));
                $addedCustomLabel = '';
                if ($customSkillName !== '') {
                    $stmtFindSk = $pdo->prepare('SELECT id, code, name FROM skills WHERE LOWER(name) = LOWER(:nm) LIMIT 1');
                    $stmtFindSk->execute([':nm' => $customSkillName]);
                    $existingSk = $stmtFindSk->fetch();

                    if (is_array($existingSk)) {
                        $customSkillId = (int) $existingSk['id'];
                    } else {
                        $customCode = 'OTHER_' . strtoupper(substr(md5(strtolower($customSkillName)), 0, 6));
                        $stmtCreateSk = $pdo->prepare('
                            INSERT INTO skills (code, name, description)
                            VALUES (:c, :nm, :desc)
                            ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name
                            RETURNING id
                        ');
                        $stmtCreateSk->execute([
                            ':c' => $customCode,
                            ':nm' => $customSkillName,
                            ':desc' => 'Kỹ năng bổ sung do Tình nguyện viên / Điều phối viên cập nhật',
                        ]);
                        $customSkillId = (int) $stmtCreateSk->fetchColumn();
                    }

                    if ($customSkillId > 0) {
                        $stmtUpsertSkill->execute([
                            ':vid' => $volId,
                            ':sid' => $customSkillId,
                            ':lvl' => $customSkillLvl,
                        ]);
                        $addedCustomLabel = " (đã bổ sung kỹ năng mới: {$customSkillName} — Cấp {$customSkillLvl})";
                    }
                }

                $repo->appendAuditLog($actorUserId, 'VOLUNTEER_SKILLS_UPDATED', 'volunteer_skills', (string) $volId, "Tình nguyện viên #{$volId} cập nhật danh sách kỹ năng chuyên môn{$addedCustomLabel}");
                $flashMessage = 'Đã lưu cập nhật Hồ sơ Kỹ năng Chuyên môn thành công!' . $addedCustomLabel;
            }
        }
    } elseif ($action === 'update_shift_capacity') {
        if (!in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
            http_response_code(403);
            $flashType = 'error';
            $flashMessage = 'Chỉ người điều phối hoạt động hoặc quản trị viên mới được phép sửa số người cần cho từng ca.';
        } else {
            $shiftId = (int) ($_POST['shift_id'] ?? 0);
            $minP = max(1, (int) ($_POST['min_people'] ?? 1));
            $maxP = max($minP, (int) ($_POST['max_people'] ?? $minP));
            $skillCode = trim((string) ($_POST['skill_code'] ?? ''));
            $minLvl = max(1, min(5, (int) ($_POST['min_level'] ?? 1)));

            $stmtCnt = $pdo->prepare("
                SELECT COUNT(*) FROM assignments
                 WHERE shift_id = :sid AND status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')
            ");
            $stmtCnt->execute([':sid' => $shiftId]);
            $currAssigned = (int) $stmtCnt->fetchColumn();

            if ($maxP < $currAssigned) {
                $flashType = 'error';
                $flashMessage = "Không thể giảm số người tối đa (Max = {$maxP}) xuống thấp hơn số tình nguyện viên hiện đang có trong ca ({$currAssigned} người).";
            } elseif ($shiftId > 0) {
                $pdo->prepare('UPDATE shifts SET min_people = :minp, max_people = :maxp WHERE id = :sid')
                    ->execute([':minp' => $minP, ':maxp' => $maxP, ':sid' => $shiftId]);

                if ($skillCode !== '') {
                    $stmtSk = $pdo->prepare('SELECT id FROM skills WHERE code = :c LIMIT 1');
                    $stmtSk->execute([':c' => $skillCode]);
                    $skId = (int) ($stmtSk->fetchColumn() ?: 0);
                    if ($skId > 0) {
                        $pdo->prepare('
                            UPDATE shift_requirements
                               SET skill_id = :skid, min_level = :minl, required_count = :rcnt
                             WHERE shift_id = :sid
                        ')->execute([
                            ':skid' => $skId,
                            ':minl' => $minLvl,
                            ':rcnt' => $minP,
                            ':sid' => $shiftId,
                        ]);
                    }
                } else {
                    $pdo->prepare('UPDATE shift_requirements SET required_count = :rcnt WHERE shift_id = :sid')
                        ->execute([':rcnt' => $minP, ':sid' => $shiftId]);
                }

                $repo->appendAuditLog(
                    $actorUserId,
                    'SHIFT_CAPACITY_UPDATED',
                    'shifts',
                    (string) $shiftId,
                    "Cập nhật định biên ca #{$shiftId}: Min={$minP}, Max={$maxP}",
                    ['min_people' => $minP, 'max_people' => $maxP, 'skill_code' => $skillCode, 'min_level' => $minLvl]
                );
                $flashMessage = "Đã cập nhật số người cần cho ca #{$shiftId} (Tối thiểu: {$minP}, Tối đa: {$maxP}) thành công!";
            }
        }
    } elseif ($action === 'volunteer_quick_register_all') {
        if ($currentRole === 'PUBLIC') {
            http_response_code(403);
            $flashType = 'error';
            $flashMessage = 'Lỗi 403: Vui lòng đăng nhập để đăng ký nhanh ca trực.';
        } else {
            $actId = (int) ($_POST['activity_id'] ?? 3);
            $volId = ($currentRole === 'VOLUNTEER') ? (int) $currentVolunteerId : (int) ($_POST['volunteer_id'] ?? $currentVolunteerId);
            $ds = $repo->loadDatasetForActivity($actId);
            $vol = $ds->volunteersById[$volId] ?? null;
            $added = 0;

            if ($vol !== null) {
                $stmt = $pdo->prepare("
                    INSERT INTO registrations (activity_id, shift_id, volunteer_id, preference_rank, status, registered_at)
                    VALUES (:aid, :sid, :vid, 1, 'ACTIVE', NOW())
                    ON CONFLICT (shift_id, volunteer_id) DO UPDATE SET status = 'ACTIVE'
                ");
                foreach ($ds->shifts as $s) {
                    if ($vol->isAvailableForShift($s)) {
                        $stmt->execute([':aid' => $actId, ':sid' => $s->id, ':vid' => $volId]);
                        $added++;
                    }
                }
            }
            $flashMessage = "Đã đăng ký nhanh {$added} ca phù hợp lịch rảnh của bạn!";
            $_GET['vtab'] = 'vtab-register';
        }
    } elseif ($action === 'volunteer_confirm_assignment') {
        if ($currentRole === 'PUBLIC') {
            http_response_code(403);
            $flashType = 'error';
            $flashMessage = 'Lỗi 403: Vui lòng đăng nhập để xác nhận tham gia ca trực.';
        } else {
            $assignId = (int) ($_POST['assignment_id'] ?? 0);
            if ($currentRole === 'VOLUNTEER') {
                $stmtConf = $pdo->prepare("UPDATE assignments SET status = 'CONFIRMED', confirmed_at = NOW() WHERE id = :id AND volunteer_id = :vid");
                $stmtConf->execute([':id' => $assignId, ':vid' => (int) $currentVolunteerId]);
                if ($stmtConf->rowCount() === 0) {
                    http_response_code(403);
                    $flashType = 'error';
                    $flashMessage = 'Lỗi 403: Bạn chỉ được phép xác nhận ca trực được phân công cho chính mình.';
                } else {
                    $repo->appendAuditLog($actorUserId, 'ASSIGNMENT_CONFIRMED', 'assignments', (string) $assignId, 'Tình nguyện viên xác nhận tham gia ca trực');
                    $flashMessage = "Đã xác nhận tham gia phân công #{$assignId}!";
                }
            } else {
                $pdo->prepare("UPDATE assignments SET status = 'CONFIRMED', confirmed_at = NOW() WHERE id = :id")->execute([':id' => $assignId]);
                $repo->appendAuditLog($actorUserId, 'ASSIGNMENT_CONFIRMED', 'assignments', (string) $assignId, 'Xác nhận tham gia ca trực');
                $flashMessage = "Đã xác nhận tham gia phân công #{$assignId}!";
            }
        }
    } elseif ($action === 'create_activity') {
        if (!in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><title>403 Forbidden — Xanh Hub</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>403 Forbidden</h1><p>Chỉ Ban Chỉ huy hoặc Quản trị Đoàn mới có quyền tạo hoạt động mới.</p><a href="?view=home">Quay lại Trang chủ</a></body></html>';
            exit;
        }

        $pdo->beginTransaction();
        try {
            $actCode = strtoupper(trim((string) ($_POST['activity_code'] ?? ($_POST['campaign_code'] ?? ($_POST['code'] ?? '')))));
            $actTitle = trim((string) ($_POST['title'] ?? ($_POST['campaign_title'] ?? '')));
            $actTag = trim((string) ($_POST['tag'] ?? ($_POST['campaign_tag'] ?? 'Môi trường & Đô thị')));
            $actLocation = trim((string) ($_POST['target_area'] ?? ($_POST['location_name'] ?? 'TP. Hồ Chí Minh')));
            $actDesc = trim((string) ($_POST['description'] ?? ($_POST['campaign_description'] ?? '')));
            $actLat = isset($_POST['lat']) && is_numeric($_POST['lat']) ? (float) $_POST['lat'] : (isset($_POST['campaign_lat']) && is_numeric($_POST['campaign_lat']) ? (float) $_POST['campaign_lat'] : 10.7769);
            $actLng = isset($_POST['lng']) && is_numeric($_POST['lng']) ? (float) $_POST['lng'] : (isset($_POST['campaign_lng']) && is_numeric($_POST['campaign_lng']) ? (float) $_POST['campaign_lng'] : 106.7009);

            if ($actCode === '' || $actTitle === '') {
                throw new \InvalidArgumentException('Mã chiến dịch và tên chiến dịch không được để trống.');
            }

            $stmtAct = $pdo->prepare('
                INSERT INTO activities (code, title, description, location_name, lat, lng, status, withdraw_deadline_hours, created_by)
                VALUES (:code, :title, :desc, :loc, :lat, :lng, \'OPEN\', 24, :uid)
                RETURNING id
            ');
            $stmtAct->execute([
                ':code' => $actCode,
                ':title' => $actTitle,
                ':desc' => $actDesc,
                ':loc' => $actLocation,
                ':lat' => $actLat,
                ':lng' => $actLng,
                ':uid' => $actorUserId,
            ]);
            $newActId = (int) $stmtAct->fetchColumn();

            $stmtCoord = $pdo->prepare('
                INSERT INTO activity_coordinators (activity_id, user_id)
                VALUES (:aid, :uid)
                ON CONFLICT DO NOTHING
            ');
            $stmtCoord->execute([':aid' => $newActId, ':uid' => $actorUserId]);

            $rawShifts = $_POST['shifts'] ?? [];
            if (!is_array($rawShifts) || empty($rawShifts)) {
                $rawShifts = [[
                    'code' => $_POST['shift_code'] ?? ($actCode . '-CA01'),
                    'title' => $_POST['shift_title'] ?? ('Ca ra quân ' . $actTitle),
                    'start_time' => $_POST['shift_start'] ?? date('Y-m-d 07:30:00+07:00', strtotime('+7 days')),
                    'end_time' => $_POST['shift_end'] ?? date('Y-m-d 11:30:00+07:00', strtotime('+7 days')),
                    'location_name' => $_POST['shift_location'] ?? $actLocation,
                    'lat' => $_POST['shift_lat'] ?? $actLat,
                    'lng' => $_POST['shift_lng'] ?? $actLng,
                    'min_people' => $_POST['shift_min_people'] ?? 2,
                    'max_people' => $_POST['shift_max_people'] ?? 5,
                    'priority' => $_POST['shift_priority'] ?? 3,
                    'skill_id' => $_POST['shift_skill_id'] ?? 1,
                    'min_level' => $_POST['shift_min_level'] ?? 1,
                ]];
            }

            $stmtShift = $pdo->prepare('
                INSERT INTO shifts (activity_id, code, title, start_time, end_time, during, min_people, max_people, priority, location_name, lat, lng)
                VALUES (:aid, :code, :title, :st::timestamptz, :et::timestamptz, tstzrange(:st::timestamptz, :et::timestamptz, \'[)\'), :minp, :maxp, :pri, :loc, :lat, :lng)
                RETURNING id
            ');
            $stmtReq = $pdo->prepare('
                INSERT INTO shift_requirements (shift_id, skill_id, min_level, required_count, require_verified)
                VALUES (:sid, :skid, :minl, :rcnt, FALSE)
            ');

            $shiftsCreatedCount = 0;
            foreach ($rawShifts as $s) {
                $sCode = strtoupper(trim((string) ($s['code'] ?? '')));
                $sTitle = trim((string) ($s['title'] ?? ($s['name'] ?? '')));
                if ($sCode === '' || $sTitle === '') {
                    continue;
                }
                $sStart = (string) ($s['start_time'] ?? date('Y-m-d 07:30:00+07:00', strtotime('+7 days')));
                $sEnd = (string) ($s['end_time'] ?? date('Y-m-d 11:30:00+07:00', strtotime('+7 days')));
                $sMinP = max(1, (int) ($s['min_people'] ?? 2));
                $sMaxP = max($sMinP, (int) ($s['max_people'] ?? 5));
                $sPri = max(1, min(5, (int) ($s['priority'] ?? 3)));
                $sLoc = (string) ($s['location_name'] ?? ($s['location'] ?? $actLocation));
                $sLat = isset($s['lat']) && is_numeric($s['lat']) ? (float) $s['lat'] : $actLat;
                $sLng = isset($s['lng']) && is_numeric($s['lng']) ? (float) $s['lng'] : $actLng;

                $stmtShift->execute([
                    ':aid' => $newActId,
                    ':code' => $sCode,
                    ':title' => $sTitle,
                    ':st' => $sStart,
                    ':et' => $sEnd,
                    ':minp' => $sMinP,
                    ':maxp' => $sMaxP,
                    ':pri' => $sPri,
                    ':loc' => $sLoc,
                    ':lat' => $sLat,
                    ':lng' => $sLng,
                ]);
                $shiftId = (int) $stmtShift->fetchColumn();
                $shiftsCreatedCount++;

                $skillId = (int) ($s['skill_id'] ?? 1);
                $minLvl = max(1, min(5, (int) ($s['min_level'] ?? 1)));
                $stmtReq->execute([
                    ':sid' => $shiftId,
                    ':skid' => $skillId,
                    ':minl' => $minLvl,
                    ':rcnt' => $sMinP,
                ]);
            }

            $repo->appendAuditLog(
                $actorUserId,
                'ACTIVITY_CREATE',
                'activities',
                (string) $newActId,
                'Khởi tạo hoạt động mới và ca trực ban đầu qua Wizard 2 bước',
                [
                    'activity_code' => $actCode,
                    'activity_title' => $actTitle,
                    'shifts_count' => $shiftsCreatedCount,
                    'target_area' => $actLocation,
                ]
            );

            $pdo->commit();

            $_SESSION['flash_msg'] = "Đã khởi tạo thành công chiến dịch '{$actTitle}' (#{$newActId}) kèm {$shiftsCreatedCount} ca trực!";
            $_SESSION['flash_type'] = 'success';
            header('Location: ?view=campaigns&activity_id=' . $newActId);
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_msg'] = 'Lỗi tạo hoạt động: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=coordinator');
            exit;
        }
    } elseif ($action === 'update_profile') {
        if (!is_array($authUser)) {
            $_SESSION['flash_msg'] = 'Vui lòng đăng nhập để thực hiện thao tác.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=login');
            exit;
        }

        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        if ($fullName === '') {
            $_SESSION['flash_msg'] = 'Họ và tên không được để trống.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=profile');
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtU = $pdo->prepare('UPDATE users SET full_name = :fn WHERE id = :uid');
            $stmtU->execute([':fn' => $fullName, ':uid' => $actorUserId]);

            if ($currentRole === 'VOLUNTEER') {
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $chapter = trim((string) ($_POST['chapter_name'] ?? ''));
                $maxShifts = max(1, min(14, (int) ($_POST['max_shifts_per_week'] ?? 4)));
                $lat = isset($_POST['lat']) && is_numeric($_POST['lat']) ? (float) $_POST['lat'] : 10.7769;
                $lng = isset($_POST['lng']) && is_numeric($_POST['lng']) ? (float) $_POST['lng'] : 106.7009;

                $stmtV = $pdo->prepare('
                    UPDATE volunteers
                       SET phone = :ph,
                           chapter_name = :ch,
                           max_shifts_per_week = :ms,
                           lat = :lat,
                           lng = :lng
                     WHERE user_id = :uid
                ');
                $stmtV->execute([
                    ':ph' => $phone,
                    ':ch' => $chapter,
                    ':ms' => $maxShifts,
                    ':lat' => $lat,
                    ':lng' => $lng,
                    ':uid' => $actorUserId,
                ]);

                if (isset($_POST['skills']) && is_array($_POST['skills'])) {
                    $stmtSk = $pdo->prepare('
                        INSERT INTO volunteer_skills (volunteer_id, skill_id, level, is_verified)
                        VALUES (:vid, :sid, :lvl, TRUE)
                        ON CONFLICT (volunteer_id, skill_id)
                        DO UPDATE SET level = EXCLUDED.level
                    ');
                    $stmtDelSk = $pdo->prepare('DELETE FROM volunteer_skills WHERE volunteer_id = :vid AND skill_id = :sid');
                    foreach ($_POST['skills'] as $sid => $lvl) {
                        $sidInt = (int) $sid;
                        $lvlInt = (int) $lvl;
                        if ($sidInt >= 1) {
                            if ($lvlInt <= 0) {
                                $stmtDelSk->execute([':vid' => $actorUserId, ':sid' => $sidInt]);
                            } else {
                                $stmtSk->execute([
                                    ':vid' => $actorUserId,
                                    ':sid' => $sidInt,
                                    ':lvl' => max(1, min(5, $lvlInt)),
                                ]);
                            }
                        }
                    }
                }

                // Hỗ trợ thêm "+ Kỹ năng khác" từ trang Hồ sơ cá nhân (?view=profile)
                $customSkillName = trim((string) ($_POST['custom_skill_name'] ?? ''));
                $customSkillLvl = max(1, min(5, (int) ($_POST['custom_skill_level'] ?? 3)));
                if ($customSkillName !== '') {
                    $stmtFindSk = $pdo->prepare('SELECT id FROM skills WHERE LOWER(name) = LOWER(:nm) LIMIT 1');
                    $stmtFindSk->execute([':nm' => $customSkillName]);
                    $customSkillId = (int) ($stmtFindSk->fetchColumn() ?: 0);

                    if ($customSkillId <= 0) {
                        $customCode = 'OTHER_' . strtoupper(substr(md5(strtolower($customSkillName)), 0, 6));
                        $stmtCreateSk = $pdo->prepare('
                            INSERT INTO skills (code, name, description)
                            VALUES (:c, :nm, :desc)
                            ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name
                            RETURNING id
                        ');
                        $stmtCreateSk->execute([
                            ':c' => $customCode,
                            ':nm' => $customSkillName,
                            ':desc' => 'Kỹ năng bổ sung do Tình nguyện viên cập nhật',
                        ]);
                        $customSkillId = (int) $stmtCreateSk->fetchColumn();
                    }

                    if ($customSkillId > 0) {
                        $pdo->prepare('
                            INSERT INTO volunteer_skills (volunteer_id, skill_id, level, is_verified)
                            VALUES (:vid, :sid, :lvl, TRUE)
                            ON CONFLICT (volunteer_id, skill_id)
                            DO UPDATE SET level = EXCLUDED.level
                        ')->execute([
                            ':vid' => $actorUserId,
                            ':sid' => $customSkillId,
                            ':lvl' => $customSkillLvl,
                        ]);
                    }
                }

                $repo->appendAuditLog(
                    $actorUserId,
                    'PROFILE_UPDATE',
                    'volunteers',
                    (string) $actorUserId,
                    'Tình nguyện viên cập nhật hồ sơ cá nhân và cấp độ kỹ năng'
                );
            } else {
                $repo->appendAuditLog(
                    $actorUserId,
                    'PROFILE_UPDATE',
                    'users',
                    (string) $actorUserId,
                    'Cán bộ Ban Chỉ huy cập nhật thông tin cá nhân'
                );
            }

            $pdo->commit();

            $_SESSION['auth_user']['full_name'] = $fullName;
            $_SESSION['flash_msg'] = 'Cập nhật thông tin hồ sơ thành công!';
            $_SESSION['flash_type'] = 'success';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_msg'] = 'Lỗi cập nhật hồ sơ: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }

        header('Location: ?view=profile');
        exit;
    } elseif ($action === 'admin_update_role') {
        if ($currentRole !== 'ADMIN' || !is_array($authUser)) {
            http_response_code(403);
            $_SESSION['flash_msg'] = 'Lỗi 403: Bạn không có quyền quản trị viên.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=home');
            exit;
        }

        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $newRole = trim((string) ($_POST['new_role'] ?? ''));

        if (!in_array($newRole, ['VOLUNTEER', 'COORDINATOR', 'ADMIN'], true)) {
            $_SESSION['flash_msg'] = 'Vai trò người dùng không hợp lệ.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=admin&tab=users#admin-tab-users');
            exit;
        }

        if ($targetUserId === $actorUserId && $newRole !== 'ADMIN') {
            $_SESSION['flash_msg'] = 'Không thể tự hạ quyền quản trị viên của chính mình.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=admin&tab=users#admin-tab-users');
            exit;
        }

        $stmtRole = $pdo->prepare('UPDATE users SET role = :r WHERE id = :uid');
        $stmtRole->execute([':r' => $newRole, ':uid' => $targetUserId]);

        $repo->appendAuditLog(
            $actorUserId,
            'ROLE_UPDATE',
            'users',
            (string) $targetUserId,
            "Cập nhật vai trò người dùng #{$targetUserId} thành {$newRole}"
        );

        $_SESSION['flash_msg'] = "Đã cập nhật vai trò người dùng #{$targetUserId} thành {$newRole}!";
        $_SESSION['flash_type'] = 'success';
        header('Location: ?view=admin&tab=users#admin-tab-users');
        exit;
    } elseif ($action === 'admin_assign_coordinator') {
        if ($currentRole !== 'ADMIN' || !is_array($authUser)) {
            http_response_code(403);
            $_SESSION['flash_msg'] = 'Lỗi 403: Bạn không có quyền quản trị viên.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=home');
            exit;
        }

        $actId = (int) ($_POST['activity_id'] ?? 0);
        $coordUid = (int) ($_POST['user_id'] ?? 0);
        $assignAction = trim((string) ($_POST['assign_action'] ?? 'add'));

        if ($actId <= 0 || $coordUid <= 0) {
            $_SESSION['flash_msg'] = 'Vui lòng chọn chiến dịch và cán bộ điều phối hợp lệ.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=admin&tab=users#admin-tab-users');
            exit;
        }

        if ($assignAction === 'remove') {
            $stmtDel = $pdo->prepare('DELETE FROM activity_coordinators WHERE activity_id = :aid AND user_id = :uid');
            $stmtDel->execute([':aid' => $actId, ':uid' => $coordUid]);
            $repo->appendAuditLog(
                $actorUserId,
                'COORDINATOR_REMOVE',
                'activity_coordinators',
                "{$actId}_{$coordUid}",
                "Gỡ quyền điều phối viên #{$coordUid} khỏi chiến dịch #{$actId}"
            );
            $_SESSION['flash_msg'] = 'Đã gỡ quyền điều phối viên khỏi chiến dịch!';
            $_SESSION['flash_type'] = 'info';
        } else {
            $stmtIns = $pdo->prepare('INSERT INTO activity_coordinators (activity_id, user_id) VALUES (:aid, :uid) ON CONFLICT DO NOTHING');
            $stmtIns->execute([':aid' => $actId, ':uid' => $coordUid]);
            $repo->appendAuditLog(
                $actorUserId,
                'COORDINATOR_ASSIGN',
                'activity_coordinators',
                "{$actId}_{$coordUid}",
                "Phân công cán bộ điều phối #{$coordUid} phụ trách chiến dịch #{$actId}"
            );
            $_SESSION['flash_msg'] = 'Đã phân công điều phối viên cho chiến dịch thành công!';
            $_SESSION['flash_type'] = 'success';
        }

        header('Location: ?view=admin&tab=users#admin-tab-users');
        exit;
    } elseif ($action === 'admin_verify_skill') {
        if ($currentRole !== 'ADMIN' || !is_array($authUser)) {
            http_response_code(403);
            $_SESSION['flash_msg'] = 'Lỗi 403: Bạn không có quyền quản trị viên.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=home');
            exit;
        }

        $volId = (int) ($_POST['volunteer_id'] ?? 0);
        $skillId = (int) ($_POST['skill_id'] ?? 0);
        $verified = !empty($_POST['verified']) && (int) $_POST['verified'] === 1;

        $stmtVer = $pdo->prepare('
            UPDATE volunteer_skills
               SET is_verified = :ver, verified_by = :vby
             WHERE volunteer_id = :vid AND skill_id = :sid
        ');
        $stmtVer->execute([
            ':ver' => $verified ? 'true' : 'false',
            ':vby' => $verified ? $actorUserId : null,
            ':vid' => $volId,
            ':sid' => $skillId,
        ]);

        $repo->appendAuditLog(
            $actorUserId,
            'SKILL_VERIFY',
            'volunteer_skills',
            "{$volId}_{$skillId}",
            $verified ? "Xác minh kỹ năng #{$skillId} cho TNV #{$volId}" : "Hủy xác minh kỹ năng #{$skillId} của TNV #{$volId}"
        );

        $_SESSION['flash_msg'] = $verified ? 'Đã xác minh kỹ năng chuyên môn thành công!' : 'Đã hủy xác minh kỹ năng!';
        $_SESSION['flash_type'] = 'success';
        header('Location: ?view=admin&tab=verification#admin-tab-verification');
        exit;
    } elseif ($action === 'admin_issue_certificate') {
        if ($currentRole !== 'ADMIN' || !is_array($authUser)) {
            http_response_code(403);
            $_SESSION['flash_msg'] = 'Lỗi 403: Bạn không có quyền quản trị viên.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=home');
            exit;
        }

        $volId = (int) ($_POST['volunteer_id'] ?? 0);
        $actId = (int) ($_POST['activity_id'] ?? 0);
        $hours = max(0.5, (float) ($_POST['total_hours'] ?? 20.0));

        if ($volId <= 0 || $actId <= 0) {
            $_SESSION['flash_msg'] = 'Vui lòng chọn tình nguyện viên và chiến dịch hợp lệ.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=admin&tab=verification#admin-tab-verification');
            exit;
        }

        $certCode = 'XH2026-SV' . str_pad((string) $volId, 3, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $stmtCert = $pdo->prepare('
            INSERT INTO certificates (certificate_code, volunteer_id, activity_id, total_hours, issued_by, issued_at)
            VALUES (:code, :vid, :aid, :hrs, :iby, NOW())
        ');
        $stmtCert->execute([
            ':code' => $certCode,
            ':vid' => $volId,
            ':aid' => $actId,
            ':hrs' => $hours,
            ':iby' => $actorUserId,
        ]);

        $repo->appendAuditLog(
            $actorUserId,
            'CERTIFICATE_ISSUE',
            'certificates',
            $certCode,
            "Cấp Giấy chứng nhận {$certCode} ({$hours}h) cho TNV #{$volId} tại chiến dịch #{$actId}"
        );

        $_SESSION['flash_msg'] = "Đã duyệt và cấp Giấy chứng nhận điện tử {$certCode} thành công!";
        $_SESSION['flash_type'] = 'success';
        header('Location: ?view=admin&tab=verification#admin-tab-verification');
        exit;
    } elseif ($action === 'admin_save_settings') {
        if ($currentRole !== 'ADMIN' || !is_array($authUser)) {
            http_response_code(403);
            $_SESSION['flash_msg'] = 'Lỗi 403: Bạn không có quyền quản trị viên.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?view=home');
            exit;
        }

        $withdrawHours = max(1, min(168, (int) ($_POST['withdraw_deadline_hours'] ?? 24)));
        $qrRotation = max(10, min(300, (int) ($_POST['qr_rotation_seconds'] ?? 30)));
        $prioMode = trim((string) ($_POST['priority_mode'] ?? 'MAX_COVERAGE'));
        if (!in_array($prioMode, ['MAX_COVERAGE', 'FAIRNESS', 'PREFERENCE', 'REPUTATION'], true)) {
            $prioMode = 'MAX_COVERAGE';
        }

        $weights = [
            'w_priority' => max(0.0, (float) ($_POST['w_priority'] ?? 5.0)),
            'w_preference' => max(0.0, (float) ($_POST['w_preference'] ?? 2.0)),
            'w_skill_waste' => max(0.0, (float) ($_POST['w_skill_waste'] ?? 3.0)),
            'w_load' => max(0.0, (float) ($_POST['w_load'] ?? 1.0)),
            'w_reputation' => max(0.0, (float) ($_POST['w_reputation'] ?? 1.5)),
        ];

        $settingsData = [
            'withdraw_deadline_hours' => $withdrawHours,
            'qr_rotation_seconds' => $qrRotation,
            'priority_mode' => $prioMode,
            'weights' => $weights,
            'updated_by' => $actorUserId,
            'updated_at' => date('c'),
        ];

        $stmtSet = $pdo->prepare('
            INSERT INTO settings (key, value_json, updated_at)
            VALUES (:k, :val, NOW())
            ON CONFLICT (key) DO UPDATE
            SET value_json = EXCLUDED.value_json, updated_at = NOW()
        ');
        $stmtSet->execute([
            ':k' => 'system_config',
            ':val' => json_encode($settingsData, JSON_UNESCAPED_UNICODE),
        ]);

        $repo->appendAuditLog(
            $actorUserId,
            'SETTINGS_UPDATE',
            'settings',
            'system_config',
            'Cập nhật cấu hình hệ thống và trọng số thuật toán Kuhn-Munkres'
        );

        $_SESSION['flash_msg'] = 'Đã lưu cấu hình hệ thống và tham số điều phối thành công!';
        $_SESSION['flash_type'] = 'success';
        header('Location: ?view=admin&tab=settings#admin-tab-settings');
        exit;
    }
}

// ============================================================================
// ROUTING GUARD & DANH SÁCH VIEW HỢP LỆ (Chuẩn REST & Chống Rơi Tự Do)
// ============================================================================
$validViews = [
    'home',
    'campaigns',
    'shift_detail',
    'news',
    'about',
    'volunteer',
    'coordinator',
    'profile',
    'analytics',
    'report',
    'verify',
    'map',
    'leaderboard',
    'login',
    'register',
    'admin',
];

$rawView = $_GET['view'] ?? 'home';
$requestedView = is_string($rawView) ? $rawView : 'invalid_view_type';
$is404 = false;

if (!in_array($requestedView, $validViews, true)) {
    $is404 = true;
    $view = '404';
    http_response_code(404);
} else {
    $view = $requestedView;
}

$rawActId = $_GET['activity_id'] ?? '3';
$reqActId = (is_string($rawActId) && ctype_digit($rawActId)) ? (int) $rawActId : 3;
$selectedActivityId = isset($campaignMeta[$reqActId]) ? $reqActId : (array_key_first($campaignMeta) ?? 3);

// Kiểm soát phân quyền RBAC trên các trang nghiệp vụ
if ($view === 'coordinator') {
    if ($currentRole === 'PUBLIC') {
        header('Location: ?view=login&redirect=coordinator');
        exit;
    }
    if ($currentRole === 'VOLUNTEER') {
        $_SESSION['flash_msg'] = 'Tài khoản Tình nguyện viên được chuyển về Cổng cá nhân.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ?view=volunteer&activity_id=' . $selectedActivityId);
        exit;
    }
}
if ($view === 'volunteer') {
    if ($currentRole === 'PUBLIC') {
        header('Location: ?view=login&redirect=volunteer');
        exit;
    }
    if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
        $_SESSION['flash_msg'] = 'Tài khoản Ban Chỉ huy / Điều phối viên được chuyển hướng về Bàn Điều phối.';
        $_SESSION['flash_type'] = 'info';
        header('Location: ?view=coordinator&activity_id=' . $selectedActivityId);
        exit;
    }
}
if (in_array($view, ['analytics', 'report'], true)) {
    if ($currentRole === 'PUBLIC' || !is_array($authUser)) {
        header('Location: ?view=login&redirect=' . urlencode($view));
        exit;
    }
    if ($currentRole === 'VOLUNTEER') {
        $_SESSION['flash_msg'] = 'Trang Thống kê BI & Báo cáo Tổng kết chỉ dành cho Người phụ trách (Ban Chỉ huy) và Quản trị viên.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ?view=volunteer&activity_id=' . $selectedActivityId);
        exit;
    }
}
if ($view === 'profile') {
    if ($currentRole === 'PUBLIC' || !is_array($authUser)) {
        header('Location: ?view=login&redirect=profile');
        exit;
    }
}
if ($view === 'admin') {
    if ($currentRole === 'PUBLIC' || !is_array($authUser)) {
        header('Location: ?view=login&redirect=admin');
        exit;
    }
    if ($currentRole !== 'ADMIN') {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><title>403 Forbidden — Cổng Quản trị Hệ thống</title><link rel="stylesheet" href="assets/style.css"></head><body class="bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-100 flex items-center justify-center min-h-screen p-4"><div class="max-w-md w-full bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-xl text-center border border-slate-200 dark:border-slate-700"><div class="w-16 h-16 bg-rose-100 dark:bg-rose-900/40 text-rose-600 dark:text-rose-400 rounded-full flex items-center justify-center mx-auto mb-4 font-black text-2xl">403</div><h1 class="text-2xl font-black mb-2">Truy cập bị từ chối</h1><p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Bạn không có quyền quản trị viên tối cao (ADMIN) để truy cập Cổng Quản trị Hệ thống Xanh Hub.</p><a href="?view=home" class="btn btn-primary inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-bold">Quay lại Trang chủ</a></div></body></html>';
        exit;
    }
}

$skillCodeById = [
    1 => 'FIRST_AID',
    2 => 'DRIVING',
    3 => 'TEACHING',
    4 => 'MEDIA',
    5 => 'LOGISTICS',
];
$skillNameVi = [
    'FIRST_AID'  => 'Sơ cứu Y tế',
    'DRIVING'    => 'Lái xe & Điều tiết',
    'TEACHING'   => 'Dạy học & Sinh hoạt',
    'MEDIA'      => 'Truyền thông & Ảnh',
    'LOGISTICS'  => 'Hậu cần & Dựng trại',
    'TRAFFIC'    => 'Điều tiết Giao thông',
    'IT_SUPPORT' => 'Hỗ trợ Kỹ thuật số',
];
$skillIconMap = [
    'FIRST_AID'  => 'medical_services',
    'DRIVING'    => 'traffic',
    'TEACHING'   => 'school',
    'MEDIA'      => 'photo_camera',
    'LOGISTICS'  => 'inventory_2',
    'TRAFFIC'    => 'traffic',
    'IT_SUPPORT' => 'computer',
];
try {
    $dbSkillsAll = $pdo->query('SELECT id, code, name FROM skills ORDER BY id ASC')->fetchAll();
    foreach ($dbSkillsAll as $dbsk) {
        $skId = (int) $dbsk['id'];
        $skCode = (string) $dbsk['code'];
        $skName = (string) $dbsk['name'];
        $skillCodeById[$skId] = $skCode;
        if (!isset($skillNameVi[$skCode])) {
            $skillNameVi[$skCode] = $skName;
        }
        if (!isset($skillIconMap[$skCode])) {
            $skillIconMap[$skCode] = 'workspace_premium';
        }
    }
} catch (\Throwable) {
}
$dayNameVi = [
    1 => 'Thứ 2',
    2 => 'Thứ 3',
    3 => 'Thứ 4',
    4 => 'Thứ 5',
    5 => 'Thứ 6',
    6 => 'Thứ 7',
    7 => 'Chủ Nhật',
];

function h(string|int|float|null $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
}

function icon(string $name, int $size = 16, string $class = ''): string
{
    return UiIcons::svg($name, $size, $class);
}

function avatarUrlFor(int $id, ?string $fullName = null): string
{
    static $nameCache = [];
    if ($fullName !== null && trim($fullName) !== '') {
        $nameCache[$id] = trim($fullName);
    } elseif (!isset($nameCache[$id])) {
        global $pdo;
        if (isset($pdo) && $pdo instanceof \PDO) {
            try {
                $stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $dbName = $stmt->fetchColumn();
                if ($dbName) {
                    $nameCache[$id] = (string) $dbName;
                }
            } catch (\Throwable) {
            }
        }
    }

    return \XanhHub\Http\MonogramAvatar::dataUriBase64($id, $nameCache[$id] ?? null);
}

$activeCampaign = $campaignMeta[$selectedActivityId] ?? $campaignMeta[3];

// Xử lý an toàn tham số và dữ liệu ca trực trước khi render thẻ <head>
$targetShift = null;
$shiftNotFound = false;
$rawShiftId = null;

if ($view === 'shift_detail') {
    $rawShiftId = $_GET['id'] ?? ($_GET['shift_id'] ?? null);
    if (!is_string($rawShiftId) || !preg_match('/^[1-9]\d{0,9}$/', $rawShiftId) || (int) $rawShiftId > 2147483647) {
        $shiftNotFound = true;
        http_response_code(404);
    } else {
        $reqShiftId = (int) $rawShiftId;
        $stmtFind = $pdo->prepare('SELECT id, activity_id FROM shifts WHERE id = :id LIMIT 1');
        $stmtFind->execute([':id' => $reqShiftId]);
        $foundRow = $stmtFind->fetch();

        if ($foundRow) {
            $shiftActId = (int) $foundRow['activity_id'];
            if ($shiftActId !== $selectedActivityId && isset($campaignMeta[$shiftActId])) {
                $selectedActivityId = $shiftActId;
                $activeCampaign = $campaignMeta[$selectedActivityId];
            }
            $detailDs = $repo->loadDatasetForActivity($selectedActivityId);
            $targetShift = $detailDs->shiftsById[$reqShiftId] ?? null;
        }

        if ($targetShift === null) {
            $shiftNotFound = true;
            http_response_code(404);
        }
    }
}

// Đồng bộ trạng thái active nav
if ($view === 'shift_detail') {
    $activeNav = 'campaigns';
} elseif ($view === '404') {
    $activeNav = '';
} elseif ($view === 'report') {
    $activeNav = 'analytics';
} else {
    $activeNav = $view;
}

// Pre-computation: Dynamic Head Metadata & SEO
$pageTitles = [
    'home'         => 'Trang chủ — Xanh Hub — Cổng Tổ chức Hoạt động & Phân công Tình nguyện viên',
    'campaigns'    => 'Chiến dịch Tình nguyện & Bộ lọc Ca trực — Xanh Hub',
    'shift_detail' => 'Chi tiết Ca trực — Xanh Hub',
    'news'         => 'Phim Tài liệu & Bản tin Tuổi trẻ — Xanh Hub',
    'about'        => 'Giới thiệu & Liên hệ — Xanh Hub',
    'volunteer'    => 'Cổng Tình nguyện viên Cá nhân — Xanh Hub',
    'coordinator'  => 'Bàn Điều phối Đoàn Thanh niên — Xanh Hub',
    'profile'      => 'Hồ sơ Cá nhân & Ban Chỉ huy — Xanh Hub',
    'analytics'    => 'Trung tâm Báo cáo Thống kê & Phân tích Tác động Xã hội — Xanh Hub',
    'report'       => 'Báo cáo Tổng kết Chiến dịch chuẩn Đoàn - Hội — Xanh Hub',
    'verify'       => 'Tra cứu Giấy Chứng nhận Tình nguyện viên — Xanh Hub',
    'map'          => 'Bản đồ Số GIS & Định vị Điểm Tình nguyện — Xanh Hub',
    'leaderboard'  => 'Bảng Vàng Thi Đua & Vinh Danh Đoàn Viên — Xanh Hub',
    'login'        => 'Đăng nhập — Xanh Hub',
    'register'     => 'Đăng ký Tình nguyện viên — Xanh Hub',
    'admin'        => 'Cổng Quản trị Hệ thống — Xanh Hub',
    '404'          => '404 — Không tìm thấy trang | Xanh Hub',
];

$pageDescriptions = [
    'home'         => 'Nền tảng tổ chức hoạt động và phân công tình nguyện viên thông minh, minh bạch giờ cống hiến cho Đoàn Thanh niên Việt Nam.',
    'campaigns'    => 'Khám phá và đăng ký tham gia các ca trực tình nguyện theo chuyên môn tại TP.HCM: Mùa Hè Xanh, Tiếp Sức Mùa Thi, Chủ Nhật Xanh.',
    'shift_detail' => 'Chi tiết ca trực tình nguyện, yêu cầu chuyên môn, phân công nhân sự và đăng ký trực tiếp trên Xanh Hub.',
    'news'         => 'Phim tài liệu thực địa và bản tin hoạt động thanh niên tình nguyện Việt Nam, ghi dấu những hành trình cống hiến.',
    'about'        => 'Giới thiệu về hệ sinh thái Xanh Hub, sứ mệnh tuổi trẻ Việt Nam và thông tin liên hệ Ban Chỉ huy các chiến dịch.',
    'volunteer'    => 'Cổng thông tin cá nhân dành cho tình nguyện viên: theo dõi lịch trực, điểm danh QR xoay vòng 30 giây và xuất lịch ICS.',
    'coordinator'  => 'Bàn điều phối Đoàn Thanh niên: phân công nhân sự tự động theo kỹ năng và lịch rảnh, kiểm soát xung đột và công bằng.',
    'profile'      => 'Quản lý thông tin định danh số, bộ huy hiệu cống hiến và cập nhật kỹ năng chuyên môn tình nguyện viên.',
    'analytics'    => 'Trung tâm phân tích số liệu cống hiến theo trường Đại học, tỷ lệ lấp đầy ca trực và cơ cấu kỹ năng chuyên môn chuẩn Đoàn Thanh niên.',
    'report'       => 'Báo cáo Tổng kết Chiến dịch Tình nguyện chuẩn Đoàn TNCS Hồ Chí Minh - Hội Liên hiệp Thanh niên Việt Nam kèm mã QR kiểm thực.',
    'verify'       => 'Tra cứu và xác thực tính hợp lệ của Giấy chứng nhận tình nguyện viên điện tử.',
    'map'          => 'Bản đồ số tương tác định vị 36 ca trực tình nguyện tại TP.HCM, lọc theo bán kính khoảng cách và ứng tuyển trực quan.',
    'leaderboard'  => 'Bảng Vàng Thi Đua Đoàn Thanh niên vinh danh Top 10 cá nhân tiêu biểu và các cơ sở Đoàn xuất sắc trong các chiến dịch tình nguyện Xanh Hub.',
    'login'        => 'Đăng nhập hệ thống Xanh Hub bằng tài khoản sinh viên hoặc liên kết Google, Zalo, Microsoft Edu, Facebook, GitHub.',
    'register'     => 'Đăng ký trở thành tình nguyện viên Xanh Hub, kết nối chuyên môn và thời gian cống hiến vì cộng đồng.',
    'admin'        => 'Cổng Quản trị Hệ thống Xanh Hub: Quản lý người dùng, phân quyền điều phối, xác minh kỹ năng, duyệt chứng nhận và sổ cái kiểm toán SHA-256.',
    '404'          => '404 — Trang bạn tìm kiếm không tồn tại hoặc đã được chuyển dời. Khám phá các chiến dịch tình nguyện trên Xanh Hub.',
];

if ($view === 'shift_detail') {
    if ($shiftNotFound) {
        $pageTitles['shift_detail'] = '404 — Không tìm thấy Ca trực | Xanh Hub';
        $pageDescriptions['shift_detail'] = 'Ca trực được yêu cầu không tồn tại hoặc đã đóng đăng ký.';
    } elseif ($targetShift !== null) {
        $pageTitles['shift_detail'] = 'Chi tiết Ca trực #' . $targetShift->id . ' [' . $targetShift->code . '] ' . $targetShift->title . ' — Xanh Hub';
        $pageDescriptions['shift_detail'] = 'Chi tiết ca trực ' . $targetShift->title . ' tại ' . $targetShift->locationName . '. Đăng ký tham gia cùng Đội hình Áo Xanh.';
    }
}

$currentPageTitle = $pageTitles[$view] ?? 'Xanh Hub — Cổng Tổ chức Hoạt động & Phân công Tình nguyện viên';
$currentPageDescription = $pageDescriptions[$view] ?? 'Cổng thông tin điều phối tình nguyện viên Thanh niên Việt Nam.';
$canonicalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000')
    . strtok($_SERVER['REQUEST_URI'] ?? ('?view=' . $view), '#');

$cssVersion = (string) (@filemtime(__DIR__ . '/assets/style.css') ?: time());
$jsVersion  = (string) (@filemtime(__DIR__ . '/assets/app.js') ?: time());
$isAuthPage = in_array($view, ['login', 'register'], true);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($currentPageTitle) ?></title>
    <meta name="page-title" content="<?= $currentPageTitle ?>">
    <meta name="description" content="<?= h($currentPageDescription) ?>">
    <link rel="canonical" href="<?= h($canonicalUrl) ?>">
    <meta property="og:title" content="<?= h($currentPageTitle) ?>">
    <meta property="og:description" content="<?= h($currentPageDescription) ?>">
    <meta property="og:url" content="<?= h($canonicalUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="vi_VN">
    <meta property="og:site_name" content="Xanh Hub">
    <meta name="theme-color" content="#0057B8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#0057B8',
                        'primary-dark': '#004494',
                        'primary-light': '#EBF3FE',
                        emerald: {
                            600: '#059669',
                            500: '#10B981'
                        }
                    },
                    fontFamily: {
                        sans: ['"Be Vietnam Pro"', 'Inter', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace']
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="assets/style.css?v=<?= h($cssVersion) ?>">
    <?php if ($view === 'map'): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <?php endif; ?>
</head>
<body class="min-h-screen flex flex-col bg-[#F8FAFC] dark:bg-[#0C0F14] text-slate-900 dark:text-slate-100 transition-colors duration-200 pb-16 md:pb-0">

<div id="toast-container" aria-live="polite"></div>

<?php if ($flashMessage !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.XanhHubUI) {
        window.XanhHubUI.toast(
            <?= json_encode($flashType === 'error' ? 'error' : ($flashType === 'warning' ? 'warning' : 'success')) ?>,
            <?= json_encode($flashType === 'error' ? 'Thông báo kiểm soát' : 'Hệ thống Xanh Hub') ?>,
            <?= json_encode($flashMessage, JSON_UNESCAPED_UNICODE) ?>
        );
    }
});
</script>
<?php endif; ?>

<?php if (!$isAuthPage): ?>
    <!-- ======================================================================
         HEADER CHUẨN KIẾN TRÚC TECHPRO (TOP SEARCH BAR + MEGA MENU DROPDOWNS)
         ====================================================================== -->
    <header class="bg-white/95 dark:bg-[#131822]/95 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 sticky top-0 z-50 transition-shadow duration-200 no-print" id="main-site-header">
        <div class="px-4 md:px-8 lg:px-10 py-3">
            <div class="max-w-[1440px] mx-auto flex items-center justify-between gap-3 lg:gap-4">
                <!-- Logo -->
                <a href="?view=home" class="flex items-center gap-2.5 shrink-0 whitespace-nowrap group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#0057B8] to-sky-500 flex items-center justify-center text-white shadow-md shadow-blue-600/25 group-hover:scale-105 transition-transform shrink-0">
                        <span class="material-symbols-outlined text-[22px] filled">volunteer_activism</span>
                    </div>
                    <div>
                        <div class="font-extrabold text-lg tracking-tight text-slate-900 dark:text-white leading-none flex items-center gap-1.5 whitespace-nowrap">
                            <span>XANH</span><span class="text-[#0057B8] dark:text-sky-400">HUB</span>
                        </div>
                        <div class="hidden sm:block text-[11px] font-medium text-slate-500 dark:text-slate-400 mt-0.5 truncate max-w-[185px]">Tình nguyện Thanh niên Việt Nam</div>
                    </div>
                </a>

                <!-- Smart Search Bar (Desktop) + Live Autocomplete Dropdown -->
                <div class="hidden md:block flex-1 min-w-[200px] max-w-xl relative">
                    <form id="header-smart-search" action="?view=campaigns" method="get" class="w-full relative">
                        <input type="hidden" name="view" value="campaigns">
                        <input
                            id="header-search-input"
                            name="q"
                            autocomplete="off"
                            value="<?= h((string) ($_GET['q'] ?? '')) ?>"
                            class="w-full bg-slate-100 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-full py-2 pl-4 pr-10 text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:border-[#0057B8] focus:ring-2 focus:ring-[#0057B8]/20 transition-all text-xs lg:text-sm"
                            placeholder="Tìm kiếm chiến dịch, ca trực, kỹ năng (Sơ cứu Y tế, Hậu cần)..."
                            type="text"
                        />
                        <button type="submit" class="material-symbols-outlined absolute right-3.5 top-2 text-slate-500 hover:text-[#0057B8] dark:hover:text-sky-400 cursor-pointer text-[20px]" aria-label="Tìm kiếm">
                            search
                        </button>
                    </form>

                    <!-- Live Search Suggestions Dropdown -->
                    <div id="search-live-dropdown" class="hidden absolute left-0 right-0 top-full mt-2 bg-white dark:bg-[#18202F] border border-slate-200 dark:border-slate-700 rounded-2xl shadow-2xl p-3 z-50 max-h-96 overflow-y-auto">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 px-3 py-1">Gợi ý Chiến dịch &amp; Ca trực</div>
                        <div id="search-live-results" class="flex flex-col gap-1"></div>
                    </div>
                </div>

                <!-- Right Actions -->
                <div class="flex items-center gap-2 shrink-0 whitespace-nowrap">
                    <!-- Quick Cinema Reel Button -->
                    <button
                        type="button"
                        data-open-modal="modal-cinema-video"
                        class="hidden xl:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-sky-50 dark:bg-sky-950/50 border border-sky-200 dark:border-sky-800 text-[#0057B8] dark:text-sky-300 hover:bg-[#0057B8] hover:text-white font-bold text-xs transition-all whitespace-nowrap shrink-0"
                        title="Xem Phim Tài liệu Hành trình Áo Xanh"
                    >
                        <span class="material-symbols-outlined text-[17px] filled">play_circle</span>
                        <span>Phim Áo Xanh</span>
                    </button>

                    <!-- BI Analytics Dashboard Quick Link (Chỉ dành cho Người phụ trách & Quản trị viên) -->
                    <?php if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
                    <a
                        href="?view=analytics&activity_id=<?= $selectedActivityId ?>"
                        class="hidden xl:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-50 dark:bg-sky-950/50 border border-blue-200 dark:border-sky-800 text-[#0057B8] dark:text-sky-300 hover:bg-[#0057B8] hover:text-white font-bold text-xs transition-all whitespace-nowrap shrink-0"
                        title="Trung tâm Phân tích BI & Báo cáo Đoàn"
                    >
                        <span class="material-symbols-outlined text-[17px]">analytics</span>
                        <span>Phân tích BI</span>
                    </a>
                    <?php endif; ?>

                    <!-- GIS Interactive Map Quick Link -->
                    <a
                        href="?view=map&activity_id=<?= $selectedActivityId ?>"
                        class="hidden lg:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full <?= $activeNav === 'map' ? 'bg-[#0057B8] text-white shadow-sm' : 'bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-600 hover:text-white' ?> font-bold text-xs transition-all whitespace-nowrap shrink-0"
                        title="Bản đồ Số GIS & Định vị Điểm Tình nguyện"
                    >
                        <span class="material-symbols-outlined text-[17px]">map</span>
                        <span>Bản đồ GIS</span>
                    </a>

                    <!-- Bảng Vàng Thi Đua Leaderboard Quick Link -->
                    <a
                        href="?view=leaderboard"
                        class="hidden lg:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full <?= $activeNav === 'leaderboard' ? 'bg-[#0057B8] text-white shadow-sm' : 'bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300 hover:bg-amber-600 hover:text-white' ?> font-bold text-xs transition-all whitespace-nowrap shrink-0"
                        title="Bảng Vàng Thi Đua & Top 10 Tình Nguyện Viên Xuất Sắc"
                    >
                        <span class="material-symbols-outlined text-[17px]">military_tech</span>
                        <span>Bảng Vàng</span>
                    </a>

                    <!-- Theme Switcher -->
                    <button
                        type="button"
                        id="btn-toggle-theme"
                        class="w-9 h-9 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-700 dark:text-slate-200 hover:text-[#0057B8] hover:border-[#0057B8] transition-colors shrink-0"
                        title="Đổi giao diện Sáng / Tối"
                    >
                        <span class="material-symbols-outlined text-[18px]" id="theme-icon-symbol">dark_mode</span>
                    </button>

                    <!-- Registered Shifts / Coordinator Quick Link -->
                    <?php if ($currentRole === 'VOLUNTEER'):
                        $stmtHeaderAssignCount = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE volunteer_id = :vid AND status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')");
                        $stmtHeaderAssignCount->execute([':vid' => $currentVolunteerId]);
                        $headerMyShiftCount = (int) $stmtHeaderAssignCount->fetchColumn();
                    ?>
                    <a
                        href="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-my-shifts&assign_filter=all"
                        class="hidden sm:flex w-9 h-9 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 items-center justify-center text-slate-700 dark:text-slate-200 hover:text-[#0057B8] hover:border-[#0057B8] transition-colors relative shrink-0"
                        title="Ca trực của tôi (<?= $headerMyShiftCount ?> ca đã giao)"
                    >
                        <span class="material-symbols-outlined text-[19px]">event_available</span>
                        <span class="absolute -top-1 -right-1 <?= $headerMyShiftCount > 0 ? 'bg-emerald-600' : 'bg-slate-400' ?> text-white text-[10px] font-bold h-4 w-4 rounded-full flex items-center justify-center"><?= $headerMyShiftCount ?></span>
                    </a>
                    <?php elseif (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
                    <a
                        href="?view=coordinator&activity_id=<?= $selectedActivityId ?>"
                        class="hidden sm:flex w-9 h-9 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 items-center justify-center text-slate-700 dark:text-slate-200 hover:text-emerald-600 hover:border-emerald-600 transition-colors relative shrink-0"
                        title="Bàn Điều phối Đoàn"
                    >
                        <span class="material-symbols-outlined text-[19px]">admin_panel_settings</span>
                    </a>
                    <?php if ($currentRole === 'ADMIN'): ?>
                    <a
                        href="?view=admin"
                        class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-md shadow-purple-600/30 transition-all whitespace-nowrap shrink-0"
                        title="Cổng Quản trị Hệ thống"
                    >
                        <span class="material-symbols-outlined text-[16px]">shield_person</span>
                        <span>Quản trị Hệ thống</span>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (is_array($authUser)): ?>
                        <div class="hidden sm:flex items-center gap-2 pl-2 border-l border-slate-200 dark:border-slate-700 shrink-0 whitespace-nowrap">
                            <a
                                href="?view=profile"
                                class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-blue-50 dark:bg-sky-950/60 border border-blue-200 dark:border-sky-800 text-[#0057B8] dark:text-sky-300 font-semibold text-xs hover:bg-blue-100 transition-colors whitespace-nowrap shrink-0 max-w-[230px]"
                                title="<?= h($authUser['full_name']) ?> — <?= $currentRole === 'VOLUNTEER' ? 'Hồ sơ Cá nhân & Kỹ năng' : 'Hồ sơ Ban Chỉ huy' ?>"
                            >
                                <img src="<?= h(avatarUrlFor($actorUserId ?? 1, $authUser['full_name'] ?? null)) ?>" alt="Avatar" class="w-6 h-6 rounded-full object-cover shrink-0">
                                <span class="truncate max-w-[165px]"><?= h($authUser['full_name']) ?></span>
                                <?php if (!empty($authUser['oauth_provider'])): ?>
                                    <span class="badge badge-valid text-[9px] px-1.5 py-0 shrink-0"><?= h($authUser['oauth_provider']) ?></span>
                                <?php endif; ?>
                            </a>
                            <a
                                href="?action=logout"
                                class="w-9 h-9 rounded-full bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center hover:bg-rose-100 transition-colors shrink-0"
                                title="Đăng xuất"
                            >
                                <span class="material-symbols-outlined text-[18px]">logout</span>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center gap-1.5 sm:gap-2 pl-1 shrink-0 whitespace-nowrap">
                            <a
                                href="?view=login"
                                class="px-3 sm:px-3.5 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:border-[#0057B8] hover:text-[#0057B8] font-semibold text-xs sm:text-sm transition-colors flex items-center gap-1.5 whitespace-nowrap shrink-0"
                            >
                                <span class="material-symbols-outlined text-[17px]">login</span>
                                <span>Đăng nhập</span>
                            </a>
                            <a
                                href="?view=register"
                                class="hidden sm:inline-flex px-3.5 py-2 rounded-lg bg-[#0057B8] hover:bg-[#004494] text-white font-semibold text-xs sm:text-sm shadow-sm shadow-blue-600/25 transition-all items-center gap-1.5 whitespace-nowrap shrink-0"
                            >
                                <span class="material-symbols-outlined text-[17px]">person_add</span>
                                <span>Đăng ký</span>
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Mobile Menu Toggle -->
                    <button
                        type="button"
                        id="btn-mobile-menu"
                        class="md:hidden w-9 h-9 sm:w-10 sm:h-10 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-700 dark:text-slate-200 shrink-0"
                        aria-label="Mở menu điều hướng"
                        aria-expanded="false"
                    >
                        <span class="material-symbols-outlined text-[20px] sm:text-[22px]">menu</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mega Menu Navigation Bar (Desktop) - Đầy đủ tất cả các trang -->
        <nav class="hidden md:block bg-slate-50/90 dark:bg-[#0F141D] border-t border-slate-200/80 dark:border-slate-800 px-6 lg:px-12" aria-label="Danh mục chiến dịch">
            <div class="max-w-7xl mx-auto flex items-center gap-5 lg:gap-6 text-sm font-semibold text-slate-700 dark:text-slate-300 overflow-x-auto no-scrollbar">
                <a href="?view=home" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'home' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                    <span class="material-symbols-outlined text-[18px]">home</span>
                    <span>Trang chủ</span>
                </a>

                <!-- Dropdown 1: Khám phá Chiến dịch & Lọc Ca trực -->
                <div class="relative group whitespace-nowrap shrink-0">
                    <a href="?view=campaigns" class="py-3 flex items-center gap-1 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'campaigns' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                        <span class="material-symbols-outlined text-[18px]">explore</span>
                        <span>Chiến dịch</span>
                        <span class="material-symbols-outlined text-[16px] group-hover:rotate-180 transition-transform duration-200">expand_more</span>
                    </a>
                    <div class="nav-mega-dropdown absolute top-full left-0 hidden group-hover:flex flex-col bg-white dark:bg-[#18202F] border border-slate-200 dark:border-slate-700 rounded-b-xl shadow-xl py-2 min-w-[280px] z-50">
                        <a href="?view=campaigns" class="px-4 py-2.5 text-[#0057B8] dark:text-sky-400 hover:bg-slate-50 dark:hover:bg-slate-800/70 transition-colors flex items-center justify-between text-xs font-extrabold border-b border-slate-100 dark:border-slate-800">
                            <span>Bộ lọc Tất cả Ca trực &amp; Chiến dịch</span>
                            <span class="material-symbols-outlined text-[16px]">tune</span>
                        </a>
                        <a href="?view=campaigns&activity_id=3" class="px-4 py-2.5 text-slate-700 dark:text-slate-200 hover:text-[#0057B8] hover:bg-slate-50 dark:hover:bg-slate-800/70 transition-colors flex items-center justify-between text-xs font-semibold">
                            <span>Chiến dịch Mùa Hè Xanh 2026</span>
                            <span class="badge badge-valid">Đang mở</span>
                        </a>
                        <a href="?view=campaigns&activity_id=2" class="px-4 py-2.5 text-slate-700 dark:text-slate-200 hover:text-[#0057B8] hover:bg-slate-50 dark:hover:bg-slate-800/70 transition-colors flex items-center justify-between text-xs font-semibold">
                            <span>Chiến dịch Tiếp Sức Mùa Thi</span>
                            <span class="badge badge-warning">Cần Y tế</span>
                        </a>
                        <a href="?view=campaigns&activity_id=1" class="px-4 py-2.5 text-slate-700 dark:text-slate-200 hover:text-[#0057B8] hover:bg-slate-50 dark:hover:bg-slate-800/70 transition-colors flex items-center justify-between text-xs font-semibold">
                            <span>Chiến dịch Chủ Nhật Xanh</span>
                            <span class="badge badge-info">Hàng tuần</span>
                        </a>
                    </div>
                </div>

                <!-- Dropdown 2: Nhóm Kỹ năng Chuyên môn -->
                <div class="relative group whitespace-nowrap shrink-0">
                    <a href="?view=campaigns" class="py-3 flex items-center gap-1 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors">
                        <span class="material-symbols-outlined text-[18px]">verified</span>
                        <span>Nhóm Kỹ năng</span>
                        <span class="material-symbols-outlined text-[16px] group-hover:rotate-180 transition-transform duration-200">expand_more</span>
                    </a>
                    <div class="nav-mega-dropdown absolute top-full left-0 hidden group-hover:flex flex-col bg-white dark:bg-[#18202F] border border-slate-200 dark:border-slate-700 rounded-b-xl shadow-xl py-2 min-w-[240px] z-50">
                        <?php foreach ($skillNameVi as $skCode => $skLabel): ?>
                            <a href="?view=campaigns&skill=<?= h($skCode) ?>" class="px-4 py-2 text-slate-700 dark:text-slate-200 hover:text-[#0057B8] hover:bg-slate-50 dark:hover:bg-slate-800/70 transition-colors flex items-center gap-2.5 text-xs font-semibold">
                                <span class="material-symbols-outlined text-[16px] text-[#0057B8] dark:text-sky-400"><?= h($skillIconMap[$skCode] ?? 'verified') ?></span>
                                <span><?= h($skLabel) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <a href="?view=map" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'map' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                    <span class="material-symbols-outlined text-[18px] text-emerald-600 dark:text-emerald-400">map</span>
                    <span>Bản đồ GIS</span>
                </a>

                <a href="?view=news" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'news' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                    <span class="material-symbols-outlined text-[18px] text-rose-500">movie</span>
                    <span>Bản tin &amp; Phim</span>
                </a>

                <a href="?view=about" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'about' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                    <span class="material-symbols-outlined text-[18px]">diversity_3</span>
                    <span>Giới thiệu</span>
                </a>

                <?php if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
                    <a href="?view=coordinator&activity_id=<?= $selectedActivityId ?>" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'coordinator' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                        <span class="material-symbols-outlined text-[18px]">admin_panel_settings</span>
                        <span>Bàn Điều phối</span>
                    </a>
                    <a href="?view=profile" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'profile' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                        <span class="material-symbols-outlined text-[18px]">badge</span>
                        <span>Hồ sơ Ban Chỉ huy</span>
                    </a>
                    <?php if ($currentRole === 'ADMIN'): ?>
                    <a href="?view=admin" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 text-purple-600 dark:text-purple-400 font-extrabold hover:text-purple-700 transition-colors <?= $activeNav === 'admin' ? 'border-b-2 border-purple-600' : '' ?>">
                        <span class="material-symbols-outlined text-[18px]">shield_person</span>
                        <span>Quản trị Hệ thống</span>
                    </a>
                    <?php endif; ?>
                <?php elseif ($currentRole === 'VOLUNTEER'): ?>
                    <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'volunteer' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                        <span class="material-symbols-outlined text-[18px]">badge</span>
                        <span>Cổng Tình nguyện viên</span>
                    </a>
                    <a href="?view=profile" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'profile' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                        <span class="material-symbols-outlined text-[18px]">person</span>
                        <span>Hồ sơ Cá nhân</span>
                    </a>
                <?php else: ?>
                    <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'volunteer' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                        <span class="material-symbols-outlined text-[18px]">badge</span>
                        <span>Tình nguyện viên</span>
                    </a>
                <?php endif; ?>

                <a href="?view=verify" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'verify' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                    <span class="material-symbols-outlined text-[18px]">workspace_premium</span>
                    <span>Chứng nhận</span>
                </a>

                <a href="?view=leaderboard" class="py-3 flex items-center gap-1.5 whitespace-nowrap shrink-0 hover:text-[#0057B8] dark:hover:text-sky-400 transition-colors <?= $activeNav === 'leaderboard' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : '' ?>">
                    <span class="material-symbols-outlined text-[18px] text-amber-500">military_tech</span>
                    <span>Bảng Vàng</span>
                </a>
            </div>
        </nav>

        <!-- Mobile Navigation Drawer -->
        <div id="mobile-nav-drawer" class="hidden md:hidden bg-white dark:bg-[#131822] border-t border-slate-200 dark:border-slate-800 px-4 py-3 space-y-1 shadow-xl">
            <a href="?view=home" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'home' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                <span class="material-symbols-outlined text-[19px] shrink-0">home</span>
                <span>Trang chủ</span>
            </a>
            <a href="?view=campaigns" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'campaigns' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                <span class="material-symbols-outlined text-[19px] shrink-0">explore</span>
                <span>Chiến dịch</span>
            </a>
            <a href="?view=map" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'map' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                <span class="material-symbols-outlined text-[19px] text-emerald-600 dark:text-emerald-400 shrink-0">map</span>
                <span>Bản đồ GIS</span>
            </a>
            <a href="?view=news" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'news' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                <span class="material-symbols-outlined text-[19px] text-rose-500 shrink-0">movie</span>
                <span>Bản tin &amp; Phim</span>
            </a>
            <a href="?view=about" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'about' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                <span class="material-symbols-outlined text-[19px] shrink-0">diversity_3</span>
                <span>Giới thiệu</span>
            </a>
            <?php if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
                <a href="?view=coordinator&activity_id=<?= $selectedActivityId ?>" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'coordinator' ? 'bg-emerald-50 dark:bg-emerald-950/70 text-emerald-600 dark:text-emerald-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span class="material-symbols-outlined text-[19px] shrink-0">admin_panel_settings</span>
                    <span>Bàn Điều phối</span>
                </a>
                <a href="?view=profile" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'profile' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span class="material-symbols-outlined text-[19px] shrink-0">badge</span>
                    <span>Hồ sơ Ban Chỉ huy</span>
                </a>
                <?php if ($currentRole === 'ADMIN'): ?>
                    <a href="?view=admin" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'admin' ? 'bg-purple-100 dark:bg-purple-950/70 text-purple-700 dark:text-purple-300' : 'text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-950/30' ?>">
                        <span class="material-symbols-outlined text-[19px] shrink-0">shield_person</span>
                        <span>Quản trị Hệ thống</span>
                    </a>
                <?php endif; ?>
            <?php elseif ($currentRole === 'VOLUNTEER'): ?>
                <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'volunteer' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span class="material-symbols-outlined text-[19px] shrink-0">badge</span>
                    <span>Cổng Tình nguyện viên</span>
                </a>
                <a href="?view=profile" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'profile' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span class="material-symbols-outlined text-[19px] shrink-0">person</span>
                    <span>Hồ sơ Cá nhân</span>
                </a>
            <?php else: ?>
                <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'volunteer' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                    <span class="material-symbols-outlined text-[19px] shrink-0">badge</span>
                    <span>Tình nguyện viên</span>
                </a>
            <?php endif; ?>
            <a href="?view=verify" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'verify' ? 'bg-blue-50 dark:bg-sky-950/70 text-[#0057B8] dark:text-sky-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                <span class="material-symbols-outlined text-[19px] shrink-0">workspace_premium</span>
                <span>Chứng nhận</span>
            </a>
            <a href="?view=leaderboard" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors <?= $activeNav === 'leaderboard' ? 'bg-amber-50 dark:bg-amber-950/70 text-amber-600 dark:text-amber-400' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                <span class="material-symbols-outlined text-[19px] text-amber-500 shrink-0">military_tech</span>
                <span>Bảng Vàng Thi Đua</span>
            </a>

            <!-- Mobile Auth Actions inside Drawer -->
            <div class="pt-2.5 mt-2 border-t border-slate-200 dark:border-slate-800 flex gap-2">
                <?php if (is_array($authUser)): ?>
                    <a href="?action=logout" class="flex-1 py-2 px-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 font-bold text-xs flex items-center justify-center gap-1.5 transition-colors">
                        <span class="material-symbols-outlined text-[18px]">logout</span>
                        <span>Đăng xuất (<?= h($authUser['full_name']) ?>)</span>
                    </a>
                <?php else: ?>
                    <a href="?view=login" class="flex-1 py-2 px-3 rounded-xl border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs flex items-center justify-center gap-1.5 hover:border-[#0057B8] transition-colors">
                        <span class="material-symbols-outlined text-[18px]">login</span>
                        <span>Đăng nhập</span>
                    </a>
                    <a href="?view=register" class="flex-1 py-2 px-3 rounded-xl bg-[#0057B8] text-white font-bold text-xs flex items-center justify-center gap-1.5 hover:bg-[#004494] transition-colors">
                        <span class="material-symbols-outlined text-[18px]">person_add</span>
                        <span>Đăng ký</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>
<?php endif; ?>

<?php if ($view === 'home'): ?>
    <?php
    $homeDs = $repo->loadDatasetForActivity($selectedActivityId);
    $stmtHomeAssign = $pdo->prepare("
        SELECT shift_id, COUNT(*) AS cnt
          FROM assignments
         WHERE activity_id = :aid
           AND status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN')
         GROUP BY shift_id
    ");
    $stmtHomeAssign->execute([':aid' => $selectedActivityId]);
    $homeCountByShift = [];
    foreach ($stmtHomeAssign->fetchAll() as $row) {
        $homeCountByShift[(int) $row['shift_id']] = (int) $row['cnt'];
    }
    $urgentShifts = array_slice($homeDs->shifts, 0, 4);
    ?>
    <!-- ======================================================================
         TRANG CHỦ CHUẨN KIẾN TRÚC TECHPRO (Home.jsx + CINEMATIC VIDEO REEL)
         ====================================================================== -->
    <main class="flex-1 page-enter">
        <!-- 1. HERO SLIDER KẾT HỢP PHIM ĐIỆN ẢNH H.264 & HẠT ÁNH SÁNG NGHỆ THUẬT -->
        <section class="relative bg-slate-950 h-[560px] overflow-hidden flex items-center group" id="hero-slider">
            <!-- Hạt ánh sáng Bokeh tương tác chuột -->
            <canvas id="hero-particles-canvas" class="absolute inset-0 w-full h-full pointer-events-none z-10 opacity-75"></canvas>

            <!-- Slide 0: Video Điện ảnh H.264 Thực tế + Poster Fallback -->
            <div class="hero-slide absolute inset-0 transition-opacity duration-1000 opacity-100 z-10" data-slide-index="0">
                <video
                    autoplay
                    loop
                    muted
                    playsinline
                    poster="assets/images/hero-volunteer.jpg"
                    class="absolute inset-0 w-full h-full object-cover opacity-75 scale-105"
                >
                    <source src="assets/video/xanhhub-cinematic.mp4" type="video/mp4">
                </video>
                <div class="absolute inset-0 bg-gradient-to-r from-slate-950/95 via-slate-950/70 to-slate-950/20"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-transparent to-transparent"></div>
            </div>

            <!-- Slide 1: Chiến dịch Tiếp Sức Mùa Thi (Ken Burns Motion) -->
            <div class="hero-slide absolute inset-0 transition-opacity duration-1000 opacity-0 z-0 pointer-events-none" data-slide-index="1">
                <img src="assets/images/campaign-d2.jpg" alt="Chiến dịch Tiếp Sức Mùa Thi" class="absolute inset-0 w-full h-full object-cover opacity-70 ken-burns-bg" />
                <div class="absolute inset-0 bg-gradient-to-r from-slate-950/95 via-slate-900/75 to-transparent"></div>
            </div>

            <!-- Slide 2: Chiến dịch Chủ Nhật Xanh (Ken Burns Motion) -->
            <div class="hero-slide absolute inset-0 transition-opacity duration-1000 opacity-0 z-0 pointer-events-none" data-slide-index="2">
                <img src="assets/images/campaign-d1.jpg" alt="Chiến dịch Chủ Nhật Xanh" class="absolute inset-0 w-full h-full object-cover opacity-70 ken-burns-bg" />
                <div class="absolute inset-0 bg-gradient-to-r from-slate-950/95 via-slate-900/75 to-transparent"></div>
            </div>

            <!-- Prev / Next Arrows -->
            <button type="button" id="hero-prev" class="hidden md:flex absolute left-5 top-1/2 -translate-y-1/2 z-30 w-11 h-11 rounded-full bg-white/10 hover:bg-white/25 backdrop-blur-md border border-white/25 text-white items-center justify-center opacity-0 group-hover:opacity-100 transition-all" aria-label="Slide trước">
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
            <button type="button" id="hero-next" class="hidden md:flex absolute right-5 top-1/2 -translate-y-1/2 z-30 w-11 h-11 rounded-full bg-white/10 hover:bg-white/25 backdrop-blur-md border border-white/25 text-white items-center justify-center opacity-0 group-hover:opacity-100 transition-all" aria-label="Slide tiếp">
                <span class="material-symbols-outlined">chevron_right</span>
            </button>

            <div class="relative z-20 w-full max-w-7xl mx-auto px-4 md:px-12">
                <!-- Nội dung Slide 0 -->
                <div class="hero-slide-caption max-w-2xl fade-in-up" data-caption-index="0">
                    <div class="inline-flex items-center gap-2.5 py-1.5 px-4 rounded-full bg-sky-500/20 text-sky-300 text-xs font-bold uppercase tracking-wider mb-4 border border-sky-400/30 backdrop-blur-md">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                        <span>Chiến dịch Tình nguyện Hè 2026 &middot; Phim Thực Địa</span>
                    </div>
                    <h1 class="text-4xl md:text-5xl font-extrabold text-white leading-tight mb-4 text-glow">
                        Khoác lên màu Áo Xanh — Trọn vẹn từng ca trực vì cộng đồng
                    </h1>
                    <p class="text-base md:text-lg text-slate-200 mb-8 leading-relaxed">
                        Kết nối sinh viên tình nguyện Việt Nam với 3 chiến dịch lớn. Tự động phân công đúng chuyên môn, bảo vệ lịch học cá nhân và cấp chứng nhận số minh bạch.
                    </p>
                    <div class="flex flex-wrap gap-3.5">
                        <a href="?view=register" class="bg-[#0057B8] hover:bg-[#004494] text-white px-6 py-3.5 rounded-xl font-bold text-sm shadow-lg shadow-blue-600/35 transition-all hover:-translate-y-0.5 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[20px]">person_add</span>
                            <span>Đăng ký Tình nguyện viên</span>
                        </a>
                        <button type="button" data-open-modal="modal-cinema-video" class="bg-white/15 hover:bg-white/25 backdrop-blur-md text-white border border-white/30 px-6 py-3.5 rounded-xl font-bold text-sm transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-[20px] text-sky-300 filled">play_circle</span>
                            <span>Xem Phim Hành trình (HD)</span>
                        </button>
                        <a href="?view=campaigns" class="bg-emerald-600/90 hover:bg-emerald-600 text-white px-5 py-3.5 rounded-xl font-bold text-sm transition-all flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[19px]">explore</span>
                            <span>Khám phá 12 Ca trực</span>
                        </a>
                    </div>
                </div>

                <!-- Nội dung Slide 1 -->
                <div class="hero-slide-caption max-w-2xl fade-in-up hidden" data-caption-index="1">
                    <span class="inline-flex items-center gap-2 py-1.5 px-4 rounded-full bg-emerald-500/20 text-emerald-300 text-xs font-bold uppercase tracking-wider mb-4 border border-emerald-400/30">
                        Tiếp Sức Mùa Thi 2026
                    </span>
                    <h1 class="text-4xl md:text-5xl font-extrabold text-white leading-tight mb-4 text-glow">
                        Tiếp sức sĩ tử — Trực chốt Sơ cứu Y tế &amp; Phân luồng Điểm thi
                    </h1>
                    <p class="text-base md:text-lg text-slate-200 mb-8 leading-relaxed">
                        Đồng hành cùng hàng nghìn thí sinh tại cụm điểm thi ĐH Bách Khoa &amp; Khoa học Tự nhiên. Đăng ký ca trực sáng hoặc chiều phù hợp lịch rảnh của bạn.
                    </p>
                    <div class="flex flex-wrap gap-3.5">
                        <a href="?view=campaigns&activity_id=2" class="bg-emerald-600 hover:bg-emerald-700 text-white px-7 py-3.5 rounded-xl font-bold text-sm shadow-lg transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-[20px]">medical_services</span>
                            <span>Tham gia Đội hình Tiếp sức</span>
                        </a>
                        <a href="?view=login" class="bg-white/15 hover:bg-white/25 backdrop-blur-md text-white border border-white/30 px-7 py-3.5 rounded-xl font-bold text-sm transition-all flex items-center gap-2">
                            <span>Đăng nhập Nhanh (Google / Edu)</span>
                        </a>
                    </div>
                </div>

                <!-- Nội dung Slide 2 -->
                <div class="hero-slide-caption max-w-2xl fade-in-up hidden" data-caption-index="2">
                    <span class="inline-flex items-center gap-2 py-1.5 px-4 rounded-full bg-amber-500/20 text-amber-300 text-xs font-bold uppercase tracking-wider mb-4 border border-amber-400/30">
                        Chủ Nhật Xanh Đô Thị
                    </span>
                    <h1 class="text-4xl md:text-5xl font-extrabold text-white leading-tight mb-4 text-glow">
                        Chung tay vì Thành phố Xanh — Trồng 500 cây mới cuối tuần
                    </h1>
                    <p class="text-base md:text-lg text-slate-200 mb-8 leading-relaxed">
                        Điểm danh nhanh bằng mã QR xoay vòng 30 giây ngay tại hiện trường và tích lũy giờ tình nguyện xét danh hiệu Sinh viên 5 Tốt.
                    </p>
                    <div class="flex flex-wrap gap-3.5">
                        <a href="?view=campaigns&activity_id=1" class="bg-[#0057B8] hover:bg-[#004494] text-white px-7 py-3.5 rounded-xl font-bold text-sm shadow-lg transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-[20px]">park</span>
                            <span>Đăng ký Chủ Nhật Xanh</span>
                        </a>
                        <a href="?view=verify" class="bg-white/15 hover:bg-white/25 backdrop-blur-md text-white border border-white/30 px-7 py-3.5 rounded-xl font-bold text-sm transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-[20px]">workspace_premium</span>
                            <span>Tra cứu Chứng nhận</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Slider Controls (Dots) -->
            <div class="absolute bottom-6 left-1/2 -translate-x-1/2 z-30 flex space-x-3">
                <button type="button" class="hero-dot h-2.5 w-8 rounded-full bg-sky-400 transition-all duration-300" data-slide-to="0" aria-label="Slide 1"></button>
                <button type="button" class="hero-dot h-2.5 w-2.5 rounded-full bg-white/40 hover:bg-white/70 transition-all duration-300" data-slide-to="1" aria-label="Slide 2"></button>
                <button type="button" class="hero-dot h-2.5 w-2.5 rounded-full bg-white/40 hover:bg-white/70 transition-all duration-300" data-slide-to="2" aria-label="Slide 3"></button>
            </div>
        </section>

        <!-- 2. USPs BAR (3 Cam kết chuẩn TechPro Home.jsx) -->
        <section class="bg-white dark:bg-[#131822] border-y border-slate-200 dark:border-slate-800 py-5 px-4 md:px-12">
            <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="flex items-center gap-3.5 text-slate-700 dark:text-slate-300 hover:text-[#0057B8] transition-colors">
                    <div class="w-11 h-11 rounded-xl bg-blue-50 dark:bg-sky-950/60 flex items-center justify-center text-[#0057B8] dark:text-sky-400 shrink-0">
                        <span class="material-symbols-outlined text-[24px]">verified_user</span>
                    </div>
                    <div>
                        <div class="font-bold text-sm">100% Đúng Chuyên môn &amp; Không Trùng lịch</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Tự động đối chiếu lịch rảnh 7 ngày và cấp độ kỹ năng</div>
                    </div>
                </div>
                <div class="hidden md:block w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
                <div class="flex items-center gap-3.5 text-slate-700 dark:text-slate-300 hover:text-[#0057B8] transition-colors">
                    <div class="w-11 h-11 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 flex items-center justify-center text-emerald-600 dark:text-emerald-400 shrink-0">
                        <span class="material-symbols-outlined text-[24px]">qr_code_scanner</span>
                    </div>
                    <div>
                        <div class="font-bold text-sm">Điểm danh QR 30 Giây tại Hiện trường</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Mã QR xoay vòng liên tục chống chụp màn hình điểm danh hộ</div>
                    </div>
                </div>
                <div class="hidden md:block w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
                <div class="flex items-center gap-3.5 text-slate-700 dark:text-slate-300 hover:text-[#0057B8] transition-colors">
                    <div class="w-11 h-11 rounded-xl bg-amber-50 dark:bg-amber-950/60 flex items-center justify-center text-amber-500 shrink-0">
                        <span class="material-symbols-outlined text-[24px]">workspace_premium</span>
                    </div>
                    <div>
                        <div class="font-bold text-sm">Chứng nhận Tình nguyện Điện tử Chính thức</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Tra cứu công khai bằng mã QR &amp; xuất lịch cá nhân .ICS</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 3. CA TRỰC ĐANG CẦN QUÂN GẤP + COUNTDOWN TIMER (3D Tilt Spotlight Cards) -->
        <section id="ca-truc-khan-cap" class="py-12 px-4 md:px-12 max-w-7xl mx-auto reveal-on-scroll">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-7 gap-4">
                <div class="flex flex-wrap items-center gap-4">
                    <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="material-symbols-outlined text-rose-500 text-[32px] animate-pulse">bolt</span>
                        <span>Ca trực Đang Cần Quân Gấp</span>
                    </h2>
                    <div class="flex items-center space-x-1.5 text-sm font-mono bg-rose-50 dark:bg-rose-950/50 py-1.5 px-4 rounded-lg border border-rose-200 dark:border-rose-800/60">
                        <span class="text-xs font-sans font-semibold text-slate-600 dark:text-slate-300 mr-1">Chốt danh sách sau:</span>
                        <span id="cd-hours" class="text-rose-600 dark:text-rose-400 font-bold">04</span><span class="text-slate-400">h</span><span>:</span>
                        <span id="cd-minutes" class="text-rose-600 dark:text-rose-400 font-bold">45</span><span class="text-slate-400">m</span><span>:</span>
                        <span id="cd-seconds" class="text-rose-600 dark:text-rose-400 font-bold">18</span><span class="text-slate-400">s</span>
                    </div>
                </div>
                <a href="?view=campaigns" class="text-[#0057B8] dark:text-sky-400 font-bold text-sm hover:underline flex items-center group">
                    <span>Mở Bộ lọc 12 Ca trực</span>
                    <span class="material-symbols-outlined text-[18px] ml-1 group-hover:translate-x-1 transition-transform">arrow_forward</span>
                </a>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php
                $urgentImages = [
                    'assets/images/campaign-d2.jpg',
                    'assets/images/campaign-d1.jpg',
                    'assets/images/campaign-d3.jpg',
                    'assets/images/hero-volunteer.jpg',
                ];
                foreach ($urgentShifts as $idx => $us):
                    $req = $us->requirements[0] ?? null;
                    $skCode = $req?->skillCode ?? 'LOGISTICS';
                    $skLabel = $skillNameVi[$skCode] ?? $skCode;
                    $curr = $homeCountByShift[$us->id] ?? 0;
                    $pct = min(95, max(35, (int) round(($curr / max(1, $us->maxPeople)) * 100)));
                    $remain = max(1, $us->maxPeople - $curr);
                    $imgSrc = $urgentImages[$idx % count($urgentImages)];
                ?>
                    <div data-tilt-card class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 hover:border-[#0057B8] transition-all group hover-lift flex flex-col justify-between h-full">
                        <div>
                            <a href="?view=shift_detail&shift_id=<?= $us->id ?>&activity_id=<?= $selectedActivityId ?>" class="block relative aspect-[4/3] bg-slate-100 dark:bg-slate-800 rounded-xl mb-4 overflow-hidden">
                                <span class="absolute top-2.5 left-2.5 bg-rose-600 text-white px-2.5 py-1 rounded-md text-[11px] font-bold z-20 shadow">
                                    Ưu tiên Mức <?= (int) $us->priority ?>/5
                                </span>
                                <span class="absolute top-2.5 right-2.5 bg-slate-900/80 backdrop-blur text-white px-2.5 py-1 rounded-md text-[11px] font-mono font-bold z-20">
                                    <?= h($us->code) ?>
                                </span>
                                <img src="<?= h($imgSrc) ?>" alt="<?= h($us->title) ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500" />
                            </a>

                            <div class="text-xs font-bold text-[#0057B8] dark:text-sky-400 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-[15px]"><?= h($skillIconMap[$skCode] ?? 'verified') ?></span>
                                <span><?= h($skLabel) ?> (Cấp &ge; <?= (int) ($req?->minLevel ?? 1) ?>)</span>
                            </div>

                            <a href="?view=shift_detail&shift_id=<?= $us->id ?>&activity_id=<?= $selectedActivityId ?>" class="block font-bold text-slate-900 dark:text-white text-base line-clamp-2 mb-2 group-hover:text-[#0057B8] transition-colors min-h-[3rem]">
                                <?= h($us->title) ?>
                            </a>

                            <div class="text-xs text-slate-500 dark:text-slate-400 space-y-1 mb-3">
                                <div class="flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[15px] shrink-0">schedule</span>
                                    <span class="font-mono"><?= date('d/m/Y · H:i', $us->startEpoch) ?> – <?= date('H:i', $us->endEpoch) ?></span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[15px] shrink-0">location_on</span>
                                    <span class="truncate"><?= h($us->locationName) ?></span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="mb-3.5">
                                <div class="flex justify-between text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    <span>Đã có: <?= $curr ?>/<?= $us->maxPeople ?> TNV</span>
                                    <span class="text-rose-600 dark:text-rose-400 font-bold">Cần thêm: <?= $remain ?> bạn</span>
                                </div>
                                <div class="h-2 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-[#0057B8] to-sky-400 rounded-full" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <a href="?view=shift_detail&shift_id=<?= $us->id ?>&activity_id=<?= $selectedActivityId ?>" class="py-2.5 px-3 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-xl font-bold text-xs text-center transition-colors truncate min-h-[38px] flex items-center justify-center">
                                    Xem chi tiết
                                </a>
                                <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>" class="py-2.5 px-3 bg-[#0057B8] hover:bg-[#004494] text-white rounded-xl font-bold text-xs text-center transition-colors flex justify-center items-center gap-1 truncate min-h-[38px]">
                                    <span>Ứng tuyển</span>
                                    <span class="material-symbols-outlined text-[15px] shrink-0">arrow_forward</span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- 4. KHÁM PHÁ THEO NHÓM KỸ NĂNG (6 Category Cards) -->
        <section id="nhom-ky-nang" class="bg-slate-100/80 dark:bg-[#101622] py-12 px-4 md:px-12 border-y border-slate-200 dark:border-slate-800 reveal-on-scroll">
            <div class="max-w-7xl mx-auto">
                <div class="text-center mb-8">
                    <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white mb-2">Đăng ký theo Nhóm Kỹ năng Chuyên môn</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Chọn đúng thế mạnh của bạn để hệ thống lọc ngay các ca trực phù hợp nhất</p>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <?php
                    $categoryCards = [
                        ['code' => 'FIRST_AID',  'icon' => 'medical_services',  'label' => 'Sơ cứu Y tế',      'sub' => 'Trực chốt sức khỏe'],
                        ['code' => 'LOGISTICS',  'icon' => 'inventory_2',       'label' => 'Hậu cần Tiếp sức', 'sub' => 'Phát nước & Vật tư'],
                        ['code' => 'MEDIA',      'icon' => 'photo_camera',      'label' => 'Truyền thông',     'sub' => 'Ảnh & Bản tin'],
                        ['code' => 'TRAFFIC',    'icon' => 'traffic',           'label' => 'Điều tiết GT',     'sub' => 'Phân luồng cổng thi'],
                        ['code' => 'IT_SUPPORT', 'icon' => 'computer',          'label' => 'Kỹ thuật số',      'sub' => 'Thiết bị & QR'],
                        ['code' => 'ALL',        'icon' => 'apps',              'label' => 'Tất cả Ca trực',   'sub' => 'Xem trọn bộ 12 ca'],
                    ];
                    foreach ($categoryCards as $cat):
                    ?>
                        <a
                            href="#danh-sach-ca-truc"
                            data-home-skill-filter="<?= h($cat['code']) ?>"
                            class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 hover:border-[#0057B8] rounded-2xl p-5 flex flex-col items-center justify-center text-center gap-2.5 transition-all duration-300 group hover:-translate-y-1.5 shadow-sm hover:shadow-md cursor-pointer"
                        >
                            <div class="w-14 h-14 rounded-full bg-blue-50 dark:bg-slate-800 flex items-center justify-center group-hover:bg-[#0057B8] transition-colors duration-300">
                                <span class="material-symbols-outlined text-[28px] text-[#0057B8] dark:text-sky-400 group-hover:text-white transition-colors duration-300"><?= h($cat['icon']) ?></span>
                            </div>
                            <div>
                                <div class="font-bold text-sm text-slate-900 dark:text-white group-hover:text-[#0057B8] dark:group-hover:text-sky-400 transition-colors"><?= h($cat['label']) ?></div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5"><?= h($cat['sub']) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- 4B. THƯỚC PHIM NGHỆ THUẬT: NHỊP ĐẬP ÁO XANH VIỆT NAM (INTERACTIVE CINEMA REEL) -->
        <section class="py-16 px-4 md:px-12 bg-slate-950 text-white relative overflow-hidden reveal-on-scroll">
            <div class="absolute -top-32 -left-32 w-96 h-96 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-emerald-500/15 rounded-full blur-3xl pointer-events-none"></div>

            <div class="max-w-7xl mx-auto relative z-10">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-8 gap-4">
                    <div>
                        <span class="inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-widest text-sky-400 mb-2">
                            <span class="material-symbols-outlined text-[18px] filled">videocam</span>
                            <span>Thước phim Tài liệu Nghệ thuật 2026</span>
                        </span>
                        <h2 class="text-2xl md:text-4xl font-extrabold text-white">Nhịp đập Áo Xanh — Sức trẻ Trên Từng Điểm Trực</h2>
                    </div>
                    <a href="?view=news" class="text-sky-400 hover:text-sky-300 font-bold text-sm flex items-center gap-1.5 group">
                        <span>Khám phá Thư viện Phim &amp; Bản tin</span>
                        <span class="material-symbols-outlined text-[18px] group-hover:translate-x-1 transition-transform">arrow_forward</span>
                    </a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
                    <!-- Khung Video Điện ảnh Tương tác -->
                    <div class="lg:col-span-8 relative rounded-3xl overflow-hidden border border-white/15 shadow-2xl bg-slate-900 group">
                        <video
                            id="home-cinema-video"
                            autoplay
                            loop
                            muted
                            playsinline
                            controls
                            poster="assets/images/hero-volunteer.jpg"
                            class="w-full aspect-video object-cover"
                        >
                            <source src="assets/video/xanhhub-cinematic.mp4" type="video/mp4">
                        </video>
                        <div class="p-4 bg-slate-900/95 border-t border-white/10 flex flex-wrap items-center justify-between gap-3 text-xs">
                            <div class="flex items-center gap-2 text-slate-300">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                <span id="cinema-current-chapter" class="font-bold">Cảnh 01: Ra quân Chiến dịch Mùa Hè Xanh</span>
                            </div>
                            <button type="button" data-open-modal="modal-cinema-video" class="px-3.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white font-bold flex items-center gap-1.5 transition-colors">
                                <span class="material-symbols-outlined text-[16px]">fullscreen</span>
                                <span>Xem Rạp Phim Toàn Màn Hình</span>
                            </button>
                        </div>
                    </div>

                    <!-- 4 Phân cảnh Chuyển Nhanh (Interactive Video Chapters) -->
                    <div class="lg:col-span-4 flex flex-col gap-3">
                        <div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">Chuyển nhanh phân cảnh trong phim:</div>
                        <?php
                        $videoChapters = [
                            ['sec' => 0,  'time' => '00:00', 'title' => 'Lễ Ra quân Mùa Hè Xanh',      'desc' => 'Đội hình sinh viên áo xanh chuẩn bị vật tư & trồng cây cộng đồng'],
                            ['sec' => 4,  'time' => '00:04', 'title' => 'Trực chốt Tiếp Sức Mùa Thi',  'desc' => 'Phát nước uống và sơ cấp cứu y tế tại cổng điểm thi ĐH Bách Khoa'],
                            ['sec' => 8,  'time' => '00:08', 'title' => 'Chủ Nhật Xanh Kênh Nhiêu Lộc', 'desc' => 'Cải tạo cảnh quan đô thị & trồng mới 500 cây xanh ven kênh'],
                            ['sec' => 12, 'time' => '00:12', 'title' => 'Lan tỏa Nụ cười Tình nguyện', 'desc' => 'Kết nối sức trẻ các Liên chi Đoàn và cấp chứng nhận số'],
                        ];
                        foreach ($videoChapters as $cIdx => $chap):
                        ?>
                            <button
                                type="button"
                                data-video-seek="<?= (int) $chap['sec'] ?>"
                                data-chapter-title="<?= h($chap['title']) ?>"
                                class="video-chapter-btn text-left p-4 rounded-2xl border transition-all flex items-start gap-3.5 <?= $cIdx === 0 ? 'bg-blue-600/20 border-sky-400/60' : 'bg-white/5 border-white/10 hover:bg-white/10 hover:border-white/25' ?>"
                            >
                                <span class="px-2.5 py-1 rounded-lg bg-sky-500/20 text-sky-300 font-mono font-bold text-xs shrink-0 mt-0.5"><?= h($chap['time']) ?></span>
                                <div>
                                    <div class="font-bold text-sm text-white"><?= h($chap['title']) ?></div>
                                    <div class="text-xs text-slate-400 mt-0.5 leading-relaxed"><?= h($chap['desc']) ?></div>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 4 Con số Ấn tượng (Animated Number Counters) -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-10 pt-8 border-t border-white/10">
                    <div class="p-5 rounded-2xl bg-white/5 border border-white/10 text-center">
                        <div class="text-3xl md:text-4xl font-extrabold text-sky-400 mono"><span data-count-up="1250">1,250</span>+</div>
                        <div class="text-xs text-slate-300 mt-1 font-semibold">Giờ Tình nguyện Tích lũy</div>
                    </div>
                    <div class="p-5 rounded-2xl bg-white/5 border border-white/10 text-center">
                        <div class="text-3xl md:text-4xl font-extrabold text-emerald-400 mono"><span data-count-up="100">100</span>%</div>
                        <div class="text-xs text-slate-300 mt-1 font-semibold">Ca trực Không Trùng lịch học</div>
                    </div>
                    <div class="p-5 rounded-2xl bg-white/5 border border-white/10 text-center">
                        <div class="text-3xl md:text-4xl font-extrabold text-amber-400 mono"><span data-count-up="36">36</span> Ca</div>
                        <div class="text-xs text-slate-300 mt-1 font-semibold">Trên 3 Chiến dịch Trọng điểm</div>
                    </div>
                    <div class="p-5 rounded-2xl bg-white/5 border border-white/10 text-center">
                        <div class="text-3xl md:text-4xl font-extrabold text-purple-400 mono"><span data-count-up="30">30</span>s</div>
                        <div class="text-xs text-slate-300 mt-1 font-semibold">Chu kỳ Xoay mã QR Hiện trường</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 5. DANH SÁCH 12 CA TRỰC ĐANG MỞ (Có Tabs chuyển đổi giống TechPro Our Products) -->
        <section id="danh-sach-ca-truc" class="py-14 px-4 md:px-12 max-w-7xl mx-auto reveal-on-scroll">
            <div class="flex flex-col items-center mb-8">
                <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white mb-4">Lịch Ca trực Đang Mở Đăng ký</h2>
                <div class="flex flex-wrap justify-center gap-2 border-b border-slate-200 dark:border-slate-800">
                    <button type="button" data-home-skill-filter="ALL" class="home-tab-btn px-5 py-2.5 font-bold text-sm text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8] transition-all">
                        Tất cả Ca trực (<?= count($homeDs->shifts) ?>)
                    </button>
                    <button type="button" data-home-skill-filter="FIRST_AID" class="home-tab-btn px-5 py-2.5 font-bold text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white border-b-2 border-transparent transition-all">
                        Sơ cứu Y tế
                    </button>
                    <button type="button" data-home-skill-filter="LOGISTICS" class="home-tab-btn px-5 py-2.5 font-bold text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white border-b-2 border-transparent transition-all">
                        Hậu cần Tiếp sức
                    </button>
                    <button type="button" data-home-skill-filter="MEDIA" class="home-tab-btn px-5 py-2.5 font-bold text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white border-b-2 border-transparent transition-all">
                        Truyền thông
                    </button>
                    <button type="button" data-home-skill-filter="TRAFFIC" class="home-tab-btn px-5 py-2.5 font-bold text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white border-b-2 border-transparent transition-all">
                        Điều tiết GT
                    </button>
                    <button type="button" data-home-skill-filter="IT_SUPPORT" class="home-tab-btn px-5 py-2.5 font-bold text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white border-b-2 border-transparent transition-all">
                        Kỹ thuật số
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6" id="home-open-shifts-grid">
                <?php foreach ($homeDs->shifts as $s):
                    $req = $s->requirements[0] ?? null;
                    $skCode = $req?->skillCode ?? 'LOGISTICS';
                    $skLabel = $skillNameVi[$skCode] ?? $skCode;
                    $currAssigned = $homeCountByShift[$s->id] ?? 0;
                    $isFull = $currAssigned >= $s->maxPeople;
                ?>
                    <article
                        data-tilt-card
                        class="open-shift-card bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-5 hover:border-[#0057B8] transition-all group hover-lift flex flex-col justify-between h-full"
                        data-shift-skill="<?= h($skCode) ?>"
                        data-shift-search="<?= h(mb_strtolower($s->code . ' ' . $s->title . ' ' . $s->locationName . ' ' . $skLabel)) ?>"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-3">
                                <span class="badge badge-info mono"><?= h($s->code) ?></span>
                                <span class="badge <?= $isFull ? 'badge-valid' : 'badge-subtle' ?>">
                                    <?= h($skLabel) ?> &middot; Cấp <?= (int) ($req?->minLevel ?? 1) ?>
                                </span>
                            </div>

                            <a href="?view=shift_detail&shift_id=<?= $s->id ?>&activity_id=<?= $selectedActivityId ?>" class="block font-bold text-slate-900 dark:text-white text-base mb-2 group-hover:text-[#0057B8] dark:group-hover:text-sky-400 transition-colors line-clamp-2 min-h-[3rem]">
                                <?= h($s->title) ?>
                            </a>

                            <div class="flex items-center gap-0.5 mb-3">
                                <?php for ($st = 1; $st <= 5; $st++): ?>
                                    <span class="material-symbols-outlined text-[15px] <?= $st <= $s->priority ? 'text-amber-400 filled' : 'text-slate-300 dark:text-slate-700' ?>">star</span>
                                <?php endfor; ?>
                                <span class="text-[11px] text-slate-500 ml-1.5">Độ ưu tiên (<?= (int) $s->priority ?>/5)</span>
                            </div>

                            <div class="text-xs text-slate-600 dark:text-slate-400 space-y-1.5 mb-4 bg-slate-50 dark:bg-slate-800/60 p-3 rounded-xl border border-slate-100 dark:border-slate-800">
                                <div class="flex items-center gap-1.5 font-semibold text-slate-800 dark:text-slate-200">
                                    <span class="material-symbols-outlined text-[15px] text-[#0057B8] dark:text-sky-400 shrink-0">calendar_today</span>
                                    <span class="mono truncate"><?= date('d/m/Y · H:i', $s->startEpoch) ?> – <?= date('H:i', $s->endEpoch) ?></span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[15px] text-emerald-600 shrink-0">location_on</span>
                                    <span class="truncate"><?= h($s->locationName) ?></span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="mb-3 pt-3 border-t border-slate-100 dark:border-slate-800">
                                <div class="flex justify-between items-center text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                                    <span>Định biên</span>
                                    <span class="font-extrabold text-xs text-[#0057B8] dark:text-sky-400 mono">
                                        <?= $currAssigned ?> / <?= $s->maxPeople ?> TNV
                                    </span>
                                </div>
                                <div class="h-1.5 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-[#0057B8] to-sky-400 rounded-full" style="width: <?= min(100, (int) round(($currAssigned / max(1, $s->maxPeople)) * 100)) ?>%"></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <a
                                    href="?view=shift_detail&shift_id=<?= $s->id ?>&activity_id=<?= $selectedActivityId ?>"
                                    class="py-2.5 px-3 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs text-center transition-colors truncate min-h-[38px] flex items-center justify-center"
                                >
                                    Xem chi tiết
                                </a>
                                <a
                                    href="?view=volunteer&activity_id=<?= $selectedActivityId ?>"
                                    class="py-2.5 px-3 rounded-xl bg-[#0057B8] hover:bg-[#004494] text-white font-bold text-xs text-center transition-colors flex items-center justify-center gap-1 truncate min-h-[38px]"
                                >
                                    <span>Ứng tuyển</span>
                                    <span class="material-symbols-outlined text-[15px] shrink-0">arrow_forward</span>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- 6. 3 CHIẾN DỊCH NỔI BẬT & BẢN TIN TUỔI TRẺ -->
        <section id="chien-dich-noi-bat" class="bg-slate-100/80 dark:bg-[#101622] py-14 px-4 md:px-12 border-t border-slate-200 dark:border-slate-800 reveal-on-scroll">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end mb-8 gap-4">
                    <div>
                        <span class="text-xs font-extrabold uppercase tracking-wider text-[#0057B8] dark:text-sky-400">Chiến dịch Tình nguyện 2026</span>
                        <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white mt-1">3 Chiến dịch Lớn Đang Tuyển Quân</h2>
                    </div>
                    <a href="?view=campaigns" class="text-[#0057B8] dark:text-sky-400 font-bold text-sm hover:underline flex items-center group">
                        <span>Xem toàn bộ Chiến dịch &amp; Bộ lọc</span>
                        <span class="material-symbols-outlined text-[18px] ml-1 group-hover:translate-x-1 transition-transform">arrow_forward</span>
                    </a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <?php foreach ($campaignMeta as $actId => $c): ?>
                        <article data-tilt-card class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden hover:border-[#0057B8] transition-all duration-300 group hover-lift flex flex-col justify-between h-full">
                            <div>
                                <div class="h-52 overflow-hidden relative">
                                    <img src="<?= h($c['cover']) ?>" alt="<?= h($c['title']) ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700 ease-out" />
                                    <span class="absolute top-3 left-3 bg-slate-900/80 backdrop-blur text-white text-[11px] font-bold px-3 py-1 rounded-full">
                                        <?= h($c['tag']) ?>
                                    </span>
                                </div>
                                <div class="p-6">
                                    <div class="flex items-center gap-2 mb-2.5 text-xs text-slate-500 dark:text-slate-400">
                                        <span class="font-bold text-[#0057B8] dark:text-sky-400 uppercase bg-blue-50 dark:bg-sky-950/60 px-2.5 py-0.5 rounded"><?= h($c['code']) ?></span>
                                        <span>&middot;</span>
                                        <span><?= h($c['date_range']) ?></span>
                                    </div>
                                    <h3 class="font-extrabold text-lg text-slate-900 dark:text-white mb-2 line-clamp-2 min-h-[3.5rem] group-hover:text-[#0057B8] dark:group-hover:text-sky-400 transition-colors">
                                        <?= h($c['title']) ?>
                                    </h3>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 line-clamp-2 mb-4 min-h-[2.5rem]">
                                        <?= h($c['desc']) ?>
                                    </p>
                                    <div class="text-xs text-slate-500 flex items-center gap-1.5 mb-5">
                                        <span class="material-symbols-outlined text-[16px] text-emerald-600 shrink-0">location_on</span>
                                        <span class="truncate"><?= h($c['location']) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="px-6 pb-6 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-3">
                                <a href="?view=campaigns&activity_id=<?= $actId ?>" class="flex-1 py-2.5 px-4 rounded-xl bg-[#0057B8] hover:bg-[#004494] text-white font-bold text-xs text-center transition-colors truncate min-h-[38px] flex items-center justify-center">
                                    Khám phá <?= (int) ($c['shifts_count'] ?? 12) ?> Ca trực
                                </a>
                                <a href="?view=campaigns&activity_id=<?= $actId ?>" class="py-2.5 px-4 rounded-xl border border-slate-300 dark:border-slate-700 hover:border-[#0057B8] text-slate-700 dark:text-slate-200 font-bold text-xs transition-colors truncate min-h-[38px] flex items-center justify-center">
                                    Xem chi tiết chiến dịch
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

<?php elseif ($isAuthPage): ?>
    <!-- ======================================================================
         TRANG ĐĂNG NHẬP & ĐĂNG KÝ CHUẨN KIẾN TRÚC TECHPRO (Login.jsx 50/50 SPLIT)
         HỖ TRỢ ĐĂNG NHẬP ĐA NỀN TẢNG: GOOGLE, MICROSOFT .EDU.VN, FACEBOOK, ZALO, GITHUB
         ====================================================================== -->
    <main class="flex w-full min-h-screen overflow-hidden page-enter bg-white dark:bg-[#0C0F14]" id="auth-split-container" data-initial-mode="<?= $view === 'register' ? 'register' : 'signin' ?>">
        <!-- Cột Trái: Form Đăng nhập / Tạo tài khoản + OAuth Đa nền tảng -->
        <div class="w-full lg:w-1/2 min-h-screen overflow-y-auto flex flex-col justify-between py-7 px-6 sm:px-12 lg:px-16 relative z-10 fade-in-up">
            <div class="w-full max-w-md mx-auto">
                <!-- Brand Logo + Quay lại Trang chủ -->
                <div class="flex items-center justify-between mb-6">
                    <a href="?view=home" class="flex items-center gap-2.5 group">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#0057B8] to-sky-500 flex items-center justify-center text-white shadow-md">
                            <span class="material-symbols-outlined text-[22px] filled">volunteer_activism</span>
                        </div>
                        <div>
                            <span class="font-extrabold text-xl tracking-tight text-slate-900 dark:text-white">XANH<span class="text-[#0057B8] dark:text-sky-400">HUB</span></span>
                            <span class="block text-[11px] text-slate-500">Cổng Tình nguyện Thanh niên</span>
                        </div>
                    </a>
                    <a href="?view=home" class="text-xs font-bold text-slate-500 hover:text-[#0057B8] flex items-center gap-1 transition-colors">
                        <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                        <span>Trang chủ</span>
                    </a>
                </div>

                <!-- Toggle Tabs (Đăng nhập | Tạo tài khoản) -->
                <div class="flex items-center gap-6 mb-6 border-b border-slate-200 dark:border-slate-800">
                    <button
                        type="button"
                        data-auth-tab="signin"
                        class="auth-mode-tab font-bold text-sm pb-3 transition-all duration-300 relative <?= $view === 'login' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : 'text-slate-400 hover:text-slate-700 border-b-2 border-transparent' ?>"
                    >
                        <span>Đăng nhập Hệ thống</span>
                    </button>
                    <button
                        type="button"
                        data-auth-tab="register"
                        class="auth-mode-tab font-bold text-sm pb-3 transition-all duration-300 relative <?= $view === 'register' ? 'text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]' : 'text-slate-400 hover:text-slate-700 border-b-2 border-transparent' ?>"
                    >
                        <span>Đăng ký Tình nguyện viên</span>
                    </button>
                </div>

                <!-- KHỐI ĐĂNG NHẬP ĐA NỀN TẢNG (GOOGLE, MICROSOFT .EDU.VN, FACEBOOK, ZALO, GITHUB) -->
                <div class="mb-5">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2.5">Đăng nhập nhanh qua nền tảng liên kết (OAuth 2.0)</div>

                    <?php if ($hasAnySandbox): ?>
                    <!-- Biểu ngữ cảnh báo Chế độ Sandbox Mô phỏng an toàn -->
                    <div class="sandbox-auth-banner p-3 mb-3.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs flex items-start gap-2.5 shadow-sm">
                        <span class="material-symbols-outlined text-[18px] text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5">verified_user</span>
                        <div class="leading-relaxed">
                            <strong class="font-bold text-amber-950 dark:text-amber-100">[Chế độ Sandbox / Thử nghiệm]:</strong> Hệ thống đang chạy ở chế độ mô phỏng OAuth cục bộ do chưa cấu hình Khóa API thực tế. Bạn có thể sử dụng các tài khoản mẫu bên dưới để kiểm thử tính năng phân công và điều phối tình nguyện viên mà không bị dừng ứng dụng.
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Nút Google Chính -->
                    <button
                        type="button"
                        data-oauth-provider="Google"
                        class="oauth-btn w-full mb-2.5 py-2.5 px-4 bg-white dark:bg-slate-800/90 hover:bg-slate-50 border border-slate-300 dark:border-slate-700 rounded-xl shadow-sm flex items-center justify-center gap-3 group relative"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" class="group-hover:scale-110 transition-transform">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        <span class="font-bold text-sm text-slate-800 dark:text-white">Tiếp tục bằng tài khoản Google</span>
                        <?php if ($isGoogleSandbox): ?>
                        <span class="badge badge-warning text-[10px] mono ml-auto font-bold">SANDBOX</span>
                        <?php endif; ?>
                    </button>

                    <!-- Lưới 4 nền tảng phổ biến: Microsoft (.edu.vn), Facebook, Zalo, GitHub -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <button type="button" data-oauth-provider="Microsoft Edu (.edu.vn)" class="oauth-btn py-2 px-2.5 text-xs flex items-center justify-between gap-1">
                            <span class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 23 23" width="16" height="16">
                                    <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/><path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
                                </svg>
                                <span>Edu.vn</span>
                            </span>
                            <?php if ($isMicrosoftSandbox): ?>
                            <span class="badge badge-warning text-[9px] mono font-bold">SANDBOX</span>
                            <?php endif; ?>
                        </button>
                        <button type="button" data-oauth-provider="Facebook" class="oauth-btn py-2 px-2.5 text-xs flex items-center justify-between gap-1">
                            <span class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="17" height="17" fill="#1877F2">
                                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                </svg>
                                <span>Facebook</span>
                            </span>
                            <?php if ($isFacebookSandbox): ?>
                            <span class="badge badge-warning text-[9px] mono font-bold">SANDBOX</span>
                            <?php endif; ?>
                        </button>
                        <button type="button" data-oauth-provider="Zalo" class="oauth-btn py-2 px-2.5 text-xs flex items-center justify-between gap-1">
                            <span class="flex items-center gap-1.5">
                                <span class="w-4 h-4 rounded bg-[#0068FF] text-white font-extrabold text-[10px] flex items-center justify-center">Z</span>
                                <span>Zalo</span>
                            </span>
                            <?php if ($isZaloSandbox): ?>
                            <span class="badge badge-warning text-[9px] mono font-bold">SANDBOX</span>
                            <?php endif; ?>
                        </button>
                        <button type="button" data-oauth-provider="GitHub" class="oauth-btn py-2 px-2.5 text-xs flex items-center justify-between gap-1">
                            <span class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                    <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/>
                                </svg>
                                <span>GitHub</span>
                            </span>
                            <?php if ($isGithubSandbox): ?>
                            <span class="badge badge-warning text-[9px] mono font-bold">SANDBOX</span>
                            <?php endif; ?>
                        </button>
                    </div>
                </div>

                <!-- Khối Đăng nhập (Sign In Pane) -->
                <div id="auth-pane-signin" class="<?= $view === 'login' ? '' : 'hidden' ?>">
                    <!-- 3 Thẻ Tài khoản Demo 1 Chạm (TNV / Điều phối viên / Demo Admin) -->
                    <div class="mb-5">
                        <div class="flex items-center gap-2 mb-2.5">
                            <div class="h-px bg-slate-200 dark:bg-slate-800 flex-1"></div>
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Hoặc chọn Tài khoản Demo 1 chạm</span>
                            <div class="h-px bg-slate-200 dark:bg-slate-800 flex-1"></div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                            <form method="post" action="?action=do_login">
                                <input type="hidden" name="identifier" value="thao.dieuphoi@example.test">
                                <input type="hidden" name="password" value="XanhHub@2026">
                                <input type="hidden" name="redirect_view" value="coordinator">
                                <button
                                    type="submit"
                                    class="w-full flex items-center justify-between gap-2 rounded-xl border border-blue-200 dark:border-sky-800/70 bg-blue-50/60 dark:bg-sky-950/30 px-3 py-2.5 text-left hover:bg-blue-100/70 hover:border-[#0057B8] transition-all"
                                >
                                    <span class="min-w-0">
                                        <span class="block font-bold text-xs text-slate-900 dark:text-white truncate">Điều phối viên Đoàn</span>
                                        <span class="block font-mono text-[10px] text-slate-500 dark:text-slate-400 truncate">thao.dieuphoi@example.test</span>
                                    </span>
                                    <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400 text-[19px] shrink-0">admin_panel_settings</span>
                                </button>
                            </form>

                            <form method="post" action="?action=do_login">
                                <input type="hidden" name="identifier" value="SV001">
                                <input type="hidden" name="password" value="XanhHub@2026">
                                <input type="hidden" name="redirect_view" value="volunteer">
                                <button
                                    type="submit"
                                    class="w-full flex items-center justify-between gap-2 rounded-xl border border-emerald-200 dark:border-emerald-800/70 bg-emerald-50/60 dark:bg-emerald-950/30 px-3 py-2.5 text-left hover:bg-emerald-100/70 hover:border-emerald-600 transition-all"
                                >
                                    <span class="min-w-0">
                                        <span class="block font-bold text-xs text-slate-900 dark:text-white truncate">Tình nguyện viên (Linh)</span>
                                        <span class="block font-mono text-[10px] text-slate-500 dark:text-slate-400 truncate">Mã SV: SV001</span>
                                    </span>
                                    <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-[19px] shrink-0">badge</span>
                                </button>
                            </form>

                            <form method="post" action="?action=do_login">
                                <input type="hidden" name="identifier" value="admin@xanhhub.vn">
                                <input type="hidden" name="password" value="XanhHub@2026">
                                <input type="hidden" name="redirect_view" value="admin">
                                <button
                                    type="submit"
                                    id="btn-demo-admin-login"
                                    class="w-full flex items-center justify-between gap-2 rounded-xl border border-purple-200 dark:border-purple-800/70 bg-purple-50/60 dark:bg-purple-950/30 px-3 py-2.5 text-left hover:bg-purple-100/70 hover:border-purple-600 transition-all"
                                >
                                    <span class="min-w-0">
                                        <span class="block font-bold text-xs text-slate-900 dark:text-white truncate">Demo Admin</span>
                                        <span class="block font-mono text-[10px] text-slate-500 dark:text-slate-400 truncate">admin@xanhhub.vn</span>
                                    </span>
                                    <span class="material-symbols-outlined text-purple-600 dark:text-purple-400 text-[19px] shrink-0">shield_person</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Biểu mẫu Đăng nhập Chính -->
                    <form method="post" action="?action=do_login" class="flex flex-col gap-3.5">
                        <input type="hidden" name="redirect_view" value="<?= h((string) ($_GET['redirect'] ?? '')) ?>">

                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-bold text-slate-700 dark:text-slate-300" for="login-identifier">Email hoặc Mã sinh viên (SV001..SV030)</label>
                            <div class="relative flex items-center group">
                                <span class="material-symbols-outlined absolute left-3.5 text-slate-400 group-focus-within:text-[#0057B8] text-[20px] transition-colors">mail</span>
                                <input
                                    id="login-identifier"
                                    name="identifier"
                                    type="text"
                                    value="thao.dieuphoi@example.test"
                                    required
                                    placeholder="Nhập Email hoặc Mã SV (VD: SV001)"
                                    class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl py-2.5 pl-11 pr-4 text-sm text-slate-900 dark:text-white focus:border-[#0057B8] focus:ring-2 focus:ring-[#0057B8]/20 focus:outline-none transition-all"
                                />
                            </div>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="flex justify-between items-center">
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300" for="login-password">Mật khẩu</label>
                                <span class="text-xs font-semibold text-[#0057B8] dark:text-sky-400">Mặc định: XanhHub@2026</span>
                            </div>
                            <div class="relative flex items-center group">
                                <span class="material-symbols-outlined absolute left-3.5 text-slate-400 group-focus-within:text-[#0057B8] text-[20px] transition-colors">lock</span>
                                <input
                                    id="login-password"
                                    name="password"
                                    type="password"
                                    value="XanhHub@2026"
                                    required
                                    placeholder="••••••••••••"
                                    class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl py-2.5 pl-11 pr-11 text-sm text-slate-900 dark:text-white focus:border-[#0057B8] focus:ring-2 focus:ring-[#0057B8]/20 focus:outline-none transition-all"
                                />
                                <button
                                    type="button"
                                    data-toggle-password="login-password"
                                    class="absolute right-3.5 text-slate-400 hover:text-[#0057B8] transition-colors"
                                    aria-label="Hiện/Ẩn mật khẩu"
                                >
                                    <span class="material-symbols-outlined text-[20px]">visibility_off</span>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" checked class="w-4 h-4 rounded text-[#0057B8]" />
                                <span>Duy trì phiên đăng nhập</span>
                            </label>
                            <a href="?view=verify" class="text-[#0057B8] dark:text-sky-400 font-semibold hover:underline">Tra cứu Chứng nhận</a>
                        </div>

                        <button
                            type="submit"
                            class="mt-1 w-full py-3 px-6 bg-[#0057B8] hover:bg-[#004494] text-white font-bold text-sm rounded-xl shadow-lg shadow-blue-600/25 transition-all flex justify-center items-center gap-2 group"
                        >
                            <span>Đăng nhập Vào Hệ thống</span>
                            <span class="material-symbols-outlined text-[18px] group-hover:translate-x-1 transition-transform">arrow_forward</span>
                        </button>
                    </form>
                </div>

                <!-- Khối Đăng ký Tình nguyện viên Mới (Register Pane) -->
                <div id="auth-pane-register" class="<?= $view === 'register' ? '' : 'hidden' ?>">
                    <form method="post" action="?action=do_register" class="flex flex-col gap-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">Họ và tên</label>
                                <div class="relative flex items-center">
                                    <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[19px]">person</span>
                                    <input type="text" name="full_name" required placeholder="Nguyễn Minh Khôi" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl py-2 pl-10 pr-3 text-sm focus:border-[#0057B8] focus:outline-none" />
                                </div>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">Mã sinh viên</label>
                                <div class="relative flex items-center">
                                    <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[19px]">badge</span>
                                    <input type="text" name="student_code" required placeholder="VD: SV035" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl py-2 pl-10 pr-3 text-sm focus:border-[#0057B8] focus:outline-none" />
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">Email sinh viên</label>
                                <div class="relative flex items-center">
                                    <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[19px]">mail</span>
                                    <input type="email" name="email" required placeholder="sv035@student.edu.vn" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl py-2 pl-10 pr-3 text-sm focus:border-[#0057B8] focus:outline-none" />
                                </div>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">Số điện thoại</label>
                                <div class="relative flex items-center">
                                    <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[19px]">call</span>
                                    <input type="text" name="phone" required placeholder="0901234567" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl py-2 pl-10 pr-3 text-sm focus:border-[#0057B8] focus:outline-none" />
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">Liên chi Đoàn / Khoa</label>
                                <div class="relative flex items-center">
                                    <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[19px]">school</span>
                                    <input type="text" name="chapter_name" value="Đoàn Khoa CNTT" required class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl py-2 pl-10 pr-3 text-sm focus:border-[#0057B8] focus:outline-none" />
                                </div>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">Mật khẩu (&ge; 6 ký tự)</label>
                                <div class="relative flex items-center">
                                    <span class="material-symbols-outlined absolute left-3 text-slate-400 text-[19px]">lock</span>
                                    <input id="reg-password" type="password" name="password" value="XanhHub@2026" required class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl py-2 pl-10 pr-3 text-sm focus:border-[#0057B8] focus:outline-none" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1.5">Kỹ năng chuyên môn bạn có thể tham gia:</label>
                            <div class="grid grid-cols-2 gap-2 bg-slate-50 dark:bg-slate-800/60 p-3 rounded-xl border border-slate-200 dark:border-slate-700 text-xs">
                                <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="skill_ids[]" value="1" checked> Sơ cứu Y tế</label>
                                <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="skill_ids[]" value="2" checked> Hậu cần Tiếp sức</label>
                                <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="skill_ids[]" value="3"> Truyền thông</label>
                                <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="skill_ids[]" value="4"> Điều tiết GT</label>
                                <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="skill_ids[]" value="5"> Kỹ thuật số</label>
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="mt-1 w-full py-3 px-6 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-emerald-600/25 transition-all flex justify-center items-center gap-2"
                        >
                            <span class="material-symbols-outlined text-[18px]">how_to_reg</span>
                            <span>Hoàn tất Đăng ký &amp; Vào Cổng TNV</span>
                        </button>
                    </form>
                </div>

                <!-- Security Notice Box -->
                <div class="mt-5 flex items-start gap-3 p-3.5 rounded-xl border border-blue-200/80 dark:border-sky-900/60 bg-blue-50/50 dark:bg-sky-950/20">
                    <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400 text-[20px] mt-0.5 filled">verified_user</span>
                    <div>
                        <h4 class="font-bold text-xs text-[#0057B8] dark:text-sky-400 mb-0.5">Cổng Đăng nhập Tập trung — Đoàn Thanh niên &amp; Hội Sinh viên</h4>
                        <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                            Hỗ trợ liên kết tài khoản Google, Email Sinh viên (.edu.vn), Facebook, Zalo và GitHub. Mọi phiên đăng nhập đều được bảo vệ.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Footer Minimal -->
            <div class="mt-6 w-full max-w-md mx-auto flex justify-between items-center text-xs text-slate-400 pt-4 border-t border-slate-100 dark:border-slate-800/80">
                <p>&copy; 2026 Xanh Hub Vietnam.</p>
                <div class="flex gap-4">
                    <a href="?view=home" class="hover:text-[#0057B8] underline">Trang chủ</a>
                    <a href="?view=verify" class="hover:text-[#0057B8] underline">Tra cứu Chứng nhận</a>
                </div>
            </div>
        </div>

        <!-- Cột Phải: Phim Điện ảnh Toàn Chiều Cao + Thẻ Kính Mờ -->
        <div class="hidden lg:block w-1/2 min-h-screen relative overflow-hidden bg-slate-950 border-l border-slate-200 dark:border-slate-800">
            <video
                autoplay
                loop
                muted
                playsinline
                poster="assets/images/hero-volunteer.jpg"
                class="absolute inset-0 w-full h-full object-cover opacity-85"
            >
                <source src="assets/video/xanhhub-cinematic.mp4" type="video/mp4">
            </video>
            <div class="absolute inset-0 bg-gradient-to-r from-slate-950/80 via-slate-950/35 to-transparent"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-transparent to-slate-950/20"></div>

            <div class="absolute top-12 left-12 right-12 z-10 max-w-lg">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/15 backdrop-blur-md text-white text-xs font-bold border border-white/25 mb-3">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                    Thước phim Chiến dịch Hè 2026
                </span>
                <h2 class="text-3xl font-extrabold text-white leading-snug text-glow">
                    Màu Áo Xanh Thanh niên — Kết nối Sức trẻ Vì Cộng đồng
                </h2>
            </div>

            <!-- Glassmorphic Campaign Status Floating Card -->
            <div class="absolute bottom-10 right-10 p-5 rounded-2xl glass-dark text-white shadow-2xl w-80 z-10 float-slow">
                <div class="flex items-center gap-2.5 mb-3 border-b border-white/15 pb-2.5">
                    <span class="material-symbols-outlined text-sky-400 text-[20px] filled">analytics</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-slate-200">Trạng thái Chiến dịch</h3>
                </div>
                <ul class="flex flex-col gap-2 text-xs">
                    <li class="flex justify-between items-center p-2.5 rounded-lg bg-white/5 hover:bg-white/10 transition-all">
                        <span class="text-slate-300 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-sky-400">campaign</span> Chiến dịch Đang mở
                        </span>
                        <span class="font-mono font-bold text-sky-400">03 Chiến dịch</span>
                    </li>
                    <li class="flex justify-between items-center p-2.5 rounded-lg bg-white/5 hover:bg-white/10 transition-all">
                        <span class="text-slate-300 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-amber-400">event_note</span> Ca trực Chuyên môn
                        </span>
                        <span class="font-mono font-bold text-amber-300">12 Ca trực</span>
                    </li>
                    <li class="flex justify-between items-center p-2.5 rounded-lg bg-white/5 hover:bg-white/10 transition-all">
                        <span class="text-slate-300 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-emerald-400">security</span> Liên kết OAuth 2.0
                        </span>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            <span class="font-mono font-bold text-emerald-400">Sẵn sàng</span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </main>

<?php elseif ($view === 'campaigns'): ?>
    <!-- ======================================================================
         TRANG KHÁM PHÁ CHIẾN DỊCH & BỘ LỌC CA TRỰC CHUYÊN SÂU (Chuẩn TechPro ProductList.jsx)
         ====================================================================== -->
    <?php
    $filterSkill = strtoupper(trim((string) ($_GET['skill'] ?? 'ALL')));
    $filterQuery = trim((string) ($_GET['q'] ?? ''));
    $filterSession = strtoupper(trim((string) ($_GET['session'] ?? 'ALL'))); // ALL | MORNING | AFTERNOON
    $campDs = $repo->loadDatasetForActivity($selectedActivityId);

    $stmtCampAssign = $pdo->prepare("
        SELECT shift_id, COUNT(*) AS cnt
          FROM assignments
         WHERE activity_id = :aid
           AND status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN')
         GROUP BY shift_id
    ");
    $stmtCampAssign->execute([':aid' => $selectedActivityId]);
    $campCountByShift = [];
    foreach ($stmtCampAssign->fetchAll() as $r) {
        $campCountByShift[(int) $r['shift_id']] = (int) $r['cnt'];
    }
    ?>
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 md:px-12 py-8 page-enter">
        <!-- Breadcrumb + Campaign Banner -->
        <div class="rounded-3xl overflow-hidden relative mb-8 border border-slate-200 dark:border-slate-800 shadow-lg">
            <img src="<?= h($activeCampaign['cover']) ?>" alt="<?= h($activeCampaign['title']) ?>" class="w-full h-56 object-cover" />
            <div class="absolute inset-0 bg-gradient-to-r from-slate-950/90 via-slate-950/70 to-transparent flex items-center p-6 md:p-10">
                <div class="max-w-2xl text-white">
                    <div class="flex items-center gap-2 text-xs text-sky-300 font-bold uppercase tracking-wider mb-2">
                        <a href="?view=home" class="hover:underline">Trang chủ</a>
                        <span>/</span>
                        <span>Khám phá Chiến dịch &amp; Ca trực</span>
                    </div>
                    <h1 class="text-2xl md:text-4xl font-extrabold mb-2"><?= h($activeCampaign['title']) ?></h1>
                    <p class="text-xs md:text-sm text-slate-200 line-clamp-2"><?= h($activeCampaign['desc']) ?></p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            <!-- Left Sidebar Filter (Chuẩn TechPro ProductList.jsx) -->
            <aside class="lg:col-span-3 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-5 sticky top-28 space-y-6 shadow-sm">
                <div>
                    <?= renderDrawerCampaignList($campaignMeta, $selectedActivityId, '?view=campaigns&activity_id={id}&skill=' . urlencode($filterSkill)) ?>
                </div>

                <div class="border-t border-slate-200 dark:border-slate-800 pt-5">
                    <h3 class="font-extrabold text-sm text-slate-900 dark:text-white mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#0057B8] text-[19px]">verified</span>
                        <span>Nhóm Kỹ năng Chuyên môn</span>
                    </h3>
                    <div class="space-y-1.5">
                        <button type="button" data-camp-skill="ALL" class="camp-skill-btn w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-bold transition-all <?= $filterSkill === 'ALL' ? 'bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                            <span>Tất cả Kỹ năng</span>
                            <span class="mono text-[11px]"><?= count($campDs->shifts) ?></span>
                        </button>
                        <?php foreach ($skillNameVi as $skCode => $skLabel): ?>
                            <button type="button" data-camp-skill="<?= h($skCode) ?>" class="camp-skill-btn w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-bold transition-all <?= $filterSkill === $skCode ? 'bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                                <span class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px]"><?= h($skillIconMap[$skCode] ?? 'verified') ?></span>
                                    <span><?= h($skLabel) ?></span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="border-t border-slate-200 dark:border-slate-800 pt-5">
                    <h3 class="font-extrabold text-sm text-slate-900 dark:text-white mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#0057B8] text-[19px]">schedule</span>
                        <span>Khung giờ trong ngày</span>
                    </h3>
                    <div class="grid grid-cols-3 gap-1.5 text-xs">
                        <button type="button" data-camp-session="ALL" class="camp-session-btn py-2 rounded-lg font-bold border border-slate-200 dark:border-slate-700 bg-[#0057B8] text-white">Tất cả</button>
                        <button type="button" data-camp-session="MORNING" class="camp-session-btn py-2 rounded-lg font-bold border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300">Ca Sáng</button>
                        <button type="button" data-camp-session="AFTERNOON" class="camp-session-btn py-2 rounded-lg font-bold border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300">Ca Chiều</button>
                    </div>
                </div>
            </aside>

            <!-- Right Shifts Catalog -->
            <div class="lg:col-span-9">
                <!-- Toolbar -->
                <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 mb-6 flex flex-wrap items-center justify-between gap-4 shadow-sm">
                    <div class="relative flex-1 min-w-[220px]">
                        <span class="material-symbols-outlined absolute left-3.5 top-2.5 text-slate-400 text-[19px]">search</span>
                        <input
                            type="text"
                            id="camp-filter-search"
                            value="<?= h($filterQuery) ?>"
                            placeholder="Lọc nhanh theo mã ca, tên ca trực, địa điểm..."
                            class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl py-2 pl-10 pr-4 text-xs focus:border-[#0057B8] focus:outline-none"
                        />
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-500">Hiển thị: <strong id="camp-visible-count" class="text-[#0057B8] dark:text-sky-400"><?= count($campDs->shifts) ?></strong> ca trực</span>
                        <button type="button" id="btn-camp-grid" class="w-9 h-9 rounded-lg bg-[#0057B8] text-white flex items-center justify-center" title="Dạng lưới">
                            <span class="material-symbols-outlined text-[18px]">grid_view</span>
                        </button>
                        <button type="button" id="btn-camp-list" class="w-9 h-9 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center" title="Dạng danh sách">
                            <span class="material-symbols-outlined text-[18px]">view_list</span>
                        </button>
                        <a href="?view=map&activity_id=<?= $selectedActivityId ?>" id="btn-camp-map" class="h-9 px-3 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-600 hover:text-white text-emerald-700 dark:text-emerald-300 flex items-center gap-1.5 text-xs font-bold transition-all" title="Chuyển sang Chế độ Bản đồ Số GIS">
                            <span class="material-symbols-outlined text-[18px]">map</span>
                            <span class="hidden sm:inline">Bản đồ</span>
                        </a>
                        <?php if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
                            <button type="button" data-open-modal="modal-create-activity" id="btn-create-activity-campaigns" class="h-9 px-3 rounded-lg bg-[#0057B8] hover:bg-blue-700 text-white flex items-center gap-1.5 text-xs font-bold transition-all shadow-sm" title="Tạo Hoạt động / Chiến dịch Mới">
                                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                                <span class="hidden sm:inline">Tạo Hoạt động Mới</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Shifts Grid -->
                <div id="camp-shifts-container" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    <?php foreach ($campDs->shifts as $s):
                        $req = $s->requirements[0] ?? null;
                        $skCode = $req?->skillCode ?? 'LOGISTICS';
                        $skLabel = $skillNameVi[$skCode] ?? $skCode;
                        $currAssigned = $campCountByShift[$s->id] ?? 0;
                        $hourStart = (int) date('G', $s->startEpoch);
                        $sessionTag = $hourStart < 12 ? 'MORNING' : 'AFTERNOON';
                        $pct = min(100, (int) round(($currAssigned / max(1, $s->maxPeople)) * 100));
                    ?>
                        <article
                            data-tilt-card
                            class="camp-shift-item bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-5 hover:border-[#0057B8] transition-all group hover-lift flex flex-col justify-between h-full"
                            data-skill="<?= h($skCode) ?>"
                            data-session="<?= h($sessionTag) ?>"
                            data-search="<?= h(mb_strtolower($s->code . ' ' . $s->title . ' ' . $s->locationName . ' ' . $skLabel)) ?>"
                        >
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-3">
                                    <span class="badge badge-info mono shrink-0"><?= h($s->code) ?></span>
                                    <span class="badge badge-subtle truncate max-w-[140px]"><?= h($skLabel) ?> &ge; Cấp <?= (int) ($req?->minLevel ?? 1) ?></span>
                                </div>
                                <a href="?view=shift_detail&shift_id=<?= $s->id ?>&activity_id=<?= $selectedActivityId ?>" class="block font-extrabold text-base text-slate-900 dark:text-white mb-2 group-hover:text-[#0057B8] transition-colors line-clamp-2 min-h-[3rem]">
                                    <?= h($s->title) ?>
                                </a>
                                <div class="text-xs text-slate-500 dark:text-slate-400 space-y-1.5 mb-4 bg-slate-50 dark:bg-slate-800/60 p-3 rounded-xl">
                                    <div class="flex items-center gap-1.5 font-semibold text-slate-800 dark:text-slate-200">
                                        <span class="material-symbols-outlined text-[15px] text-[#0057B8] shrink-0">schedule</span>
                                        <span class="mono"><?= date('d/m/Y · H:i', $s->startEpoch) ?> – <?= date('H:i', $s->endEpoch) ?></span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-[15px] text-emerald-600 shrink-0">location_on</span>
                                        <span class="truncate"><?= h($s->locationName) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="mb-3">
                                    <div class="flex justify-between text-[11px] font-semibold text-slate-500 mb-1">
                                        <span>Quân số: <?= $currAssigned ?>/<?= $s->maxPeople ?> TNV</span>
                                        <span>Ưu tiên <?= (int) $s->priority ?>/5</span>
                                    </div>
                                    <div class="h-1.5 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-[#0057B8] to-emerald-500 rounded-full" style="width: <?= $pct ?>%"></div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                                    <a href="?view=shift_detail&shift_id=<?= $s->id ?>&activity_id=<?= $selectedActivityId ?>" class="py-2.5 px-3 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs text-center transition-colors truncate min-h-[38px] flex items-center justify-center">
                                        Xem chi tiết
                                    </a>
                                    <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>" class="py-2.5 px-3 rounded-xl bg-[#0057B8] hover:bg-[#004494] text-white font-bold text-xs text-center transition-colors truncate min-h-[38px] flex items-center justify-center gap-1">
                                        <span>Ứng tuyển</span>
                                        <span class="material-symbols-outlined text-[15px] shrink-0">arrow_forward</span>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>

                    <!-- Empty State khi không có ca trực nào phù hợp với bộ lọc -->
                    <div id="camp-empty-state" class="hidden col-span-full py-16 text-center bg-white dark:bg-[#131822] border border-dashed border-slate-300 dark:border-slate-800 rounded-2xl p-8">
                        <div class="w-16 h-16 rounded-full bg-blue-50 dark:bg-slate-800 text-[#0057B8] dark:text-sky-400 mx-auto flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined text-[32px]">search_off</span>
                        </div>
                        <h3 class="text-base font-extrabold text-slate-900 dark:text-white mb-1.5">Không tìm thấy ca trực phù hợp</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 max-w-sm mx-auto mb-4">Không có ca trực nào khớp với từ khóa tìm kiếm hoặc kỹ năng bạn đã chọn. Vui lòng thử lại với tiêu chí khác.</p>
                        <button type="button" id="btn-reset-filters" class="btn btn-sm btn-outline mx-auto flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">refresh</span>
                            <span>Đặt lại bộ lọc</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>

<?php elseif ($view === 'map'): ?>
    <!-- ======================================================================
         PHÂN HỆ BẢN ĐỒ SỐ GIS & ĐỊNH VỊ ĐIỂM TÌNH NGUYỆN (LEAFLET & OPENSTREETMAP)
         ====================================================================== -->
    <?php
    $gisService = new \XanhHub\Infrastructure\Service\GisService($pdo);
    $mapActId = isset($_GET['activity_id']) && ctype_digit((string) $_GET['activity_id'])
        ? max(1, min(3, (int) $_GET['activity_id']))
        : 0; // 0 = Tất cả 3 chiến dịch (36 ca)

    $gisInitialPayload = $gisService->getShiftsGisPayload($mapActId > 0 ? $mapActId : null);
    $allShiftsJson = json_encode($gisInitialPayload['shifts'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $activeCamp = $mapActId > 0 ? ($campaignMeta[$mapActId] ?? null) : null;
    ?>
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 md:px-12 py-8 page-enter" id="main-gis-view">
        <!-- Breadcrumb & Header Banner -->
        <div class="rounded-3xl overflow-hidden relative mb-8 border border-slate-200 dark:border-slate-800 shadow-lg bg-gradient-to-r from-slate-900 via-sky-950 to-blue-900 text-white p-6 md:p-8">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div class="max-w-3xl">
                    <div class="flex items-center gap-2 text-xs text-sky-300 font-bold uppercase tracking-wider mb-2">
                        <a href="?view=home" class="hover:underline">Trang chủ</a>
                        <span>/</span>
                        <a href="?view=campaigns" class="hover:underline">Khám phá Chiến dịch</a>
                        <span>/</span>
                        <span>Bản đồ Số GIS &amp; Định vị Điểm Tình nguyện</span>
                    </div>
                    <h1 class="text-2xl md:text-4xl font-extrabold mb-2 flex items-center gap-3">
                        <span class="material-symbols-outlined text-emerald-400 text-[32px] md:text-[40px]">explore</span>
                        <span>Bản đồ Số GIS &amp; Định vị Điểm Tình nguyện</span>
                    </h1>
                    <p class="text-xs md:text-sm text-slate-200 leading-relaxed max-w-2xl">
                        Bản đồ trực quan định vị 36 điểm trực thực tế tại TP.HCM (Trường Đại học, Bệnh viện, Tuyến đường, Kênh mương). Hỗ trợ lọc theo khoảng cách gần nơi ở của bạn và đăng ký nhanh 1 chạm.
                    </p>
                    <div class="flex flex-wrap items-center gap-2 mt-4 text-xs">
                        <span class="px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-semibold flex items-center gap-1">
                            <span class="material-symbols-outlined text-[15px]">pin_drop</span>
                            <span>36 Điểm trực GPS thực tế</span>
                        </span>
                        <span class="px-3 py-1 rounded-full bg-blue-500/20 text-sky-300 border border-blue-500/30 font-semibold flex items-center gap-1">
                            <span class="material-symbols-outlined text-[15px]">near_me</span>
                            <span>Lọc ca trực gần vị trí của bạn</span>
                        </span>
                        <span class="px-3 py-1 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30 font-semibold flex items-center gap-1">
                            <span class="material-symbols-outlined text-[15px]">touch_app</span>
                            <span>Ứng tuyển 1-chạm trực quan</span>
                        </span>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row md:flex-col gap-2 shrink-0">
                    <a href="?view=campaigns&activity_id=<?= $mapActId > 0 ? $mapActId : 3 ?>" class="px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 text-white text-xs font-bold transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">view_list</span>
                        <span>Chuyển dạng Danh mục</span>
                    </a>
                    <button type="button" id="btn-my-location" class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-all flex items-center justify-center gap-2 shadow-sm">
                        <span class="material-symbols-outlined text-[18px]">my_location</span>
                        <span>Vị trí của tôi (GPS)</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Filter & Control Toolbar -->
        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 md:p-5 mb-6 shadow-sm space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <!-- Chọn Chiến dịch (Hỗ trợ quy mô 100+ chiến dịch không vỡ giao diện) -->
                <?php
                $totalMapShiftsAll = 0;
                foreach ($campaignMeta as $cm) {
                    $totalMapShiftsAll += (int) ($cm['shifts_count'] ?? 0);
                }
                ?>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-extrabold text-slate-700 dark:text-slate-300 flex items-center gap-1">
                        <span class="material-symbols-outlined text-[17px] text-[#0057B8]">campaign</span>
                        <span>Chiến dịch:</span>
                    </span>
                    <?= renderScalableCampaignSwitcher($campaignMeta, $mapActId, '?view=map&activity_id={id}', true, $totalMapShiftsAll) ?>
                </div>

                <!-- Chú thích 4 Màu Marker Quy Chuẩn -->
                <div class="flex flex-wrap items-center gap-3 text-xs bg-slate-50 dark:bg-slate-800/60 px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700">
                    <span class="font-bold text-slate-500 dark:text-slate-400 text-[11px] uppercase tracking-wider">Nhóm Điểm trực:</span>
                    <span class="inline-flex items-center gap-1.5 font-semibold text-rose-600 dark:text-rose-400">
                        <span class="w-3 h-3 rounded-full bg-[#EF4444] shadow-sm"></span>
                        <span>Y tế đỏ</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 font-semibold text-emerald-600 dark:text-emerald-400">
                        <span class="w-3 h-3 rounded-full bg-[#10B981] shadow-sm"></span>
                        <span>Môi trường xanh</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 font-semibold text-amber-600 dark:text-amber-400">
                        <span class="w-3 h-3 rounded-full bg-[#F59E0B] shadow-sm"></span>
                        <span>Tiếp sức vàng cam</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 font-semibold text-blue-600 dark:text-blue-400">
                        <span class="w-3 h-3 rounded-full bg-[#0057B8] shadow-sm"></span>
                        <span>Mùa hè xanh</span>
                    </span>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-4">
                <!-- Bộ lọc Bán kính Khoảng cách -->
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-extrabold text-slate-700 dark:text-slate-300 flex items-center gap-1">
                        <span class="material-symbols-outlined text-[17px] text-emerald-600">radar</span>
                        <span>Khoảng cách tìm kiếm:</span>
                    </span>
                    <button type="button" class="gis-radius-btn px-3 py-1.5 rounded-lg font-bold border border-slate-200 dark:border-slate-700 bg-[#0057B8] text-white transition-all shadow-sm" data-radius="ALL">
                        Tất cả
                    </button>
                    <button type="button" class="gis-radius-btn px-3 py-1.5 rounded-lg font-bold border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 transition-all" data-radius="3">
                        &lt; 3 km
                    </button>
                    <button type="button" class="gis-radius-btn px-3 py-1.5 rounded-lg font-bold border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 transition-all" data-radius="5">
                        &lt; 5 km
                    </button>
                    <button type="button" class="gis-radius-btn px-3 py-1.5 rounded-lg font-bold border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 transition-all" data-radius="10">
                        &lt; 10 km
                    </button>
                    <button type="button" class="gis-radius-btn px-3 py-1.5 rounded-lg font-bold border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 transition-all" data-radius="15">
                        &lt; 15 km
                    </button>
                </div>

                <!-- Vị trí tâm tính cự ly -->
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <span class="material-symbols-outlined text-[16px] text-emerald-600">location_on</span>
                    <span>Tâm định vị: <strong id="gis-center-label" class="text-slate-800 dark:text-slate-200">10.7769° N, 106.7009° E (Trung tâm TP.HCM)</strong></span>
                </div>
            </div>
        </div>

        <!-- Two-column GIS Workspace: Map + Filtered Shifts List -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Left: Interactive Map Container -->
            <div class="lg:col-span-8 space-y-3">
                <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-3 shadow-sm relative overflow-hidden">
                    <div id="gis-map" class="w-full h-[620px] rounded-2xl bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 relative z-10">
                        <noscript>
                            <div class="p-6 text-center text-sm text-slate-600 dark:text-slate-400">
                                Vui lòng bật JavaScript để hiển thị bản đồ tương tác các điểm trực tình nguyện.
                            </div>
                        </noscript>
                    </div>

                    <!-- Overlay Badge góc bản đồ -->
                    <div class="absolute bottom-6 left-6 z-20 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-800 shadow-md text-xs pointer-events-none flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="font-bold text-slate-800 dark:text-slate-200">Bản đồ Điểm trực Trực tuyến</span>
                    </div>
                </div>

                <!-- Trợ giúp tương tác -->
                <div class="flex flex-wrap items-center justify-between text-xs text-slate-500 dark:text-slate-400 px-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px] text-[#0057B8]">info</span>
                        <span>Bấm vào biểu tượng điểm trực trên bản đồ để xem quân số và đăng ký nhanh. Bấm vào vị trí bất kỳ trên bản đồ để đổi tâm tìm kiếm.</span>
                    </div>
                    <span>Bản đồ Thành Đoàn TP.HCM</span>
                </div>
            </div>

            <!-- Right: Filtered Shifts Catalog List with Distance -->
            <div class="lg:col-span-4 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-5 shadow-sm flex flex-col h-[650px]">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800 mb-3">
                    <div>
                        <h2 class="font-extrabold text-sm text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-emerald-600 text-[19px]">list_alt</span>
                            <span>Ca trực trong Bán kính</span>
                        </h2>
                        <span class="text-[11px] text-slate-500">Hiển thị <strong id="gis-visible-count" class="text-[#0057B8] dark:text-sky-400"><?= count($gisInitialPayload['shifts']) ?></strong> / <?= count($gisInitialPayload['shifts']) ?> ca trực</span>
                    </div>
                    <span id="gis-radius-indicator" class="badge badge-info text-[10px]">Tất cả cự ly</span>
                </div>

                <!-- Scrollable shift list container -->
                <div id="gis-shifts-list" class="flex-1 overflow-y-auto space-y-3 pr-1">
                    <?php foreach ($gisInitialPayload['shifts'] as $s): ?>
                        <div
                            class="gis-shift-card p-3.5 rounded-2xl border border-slate-200 dark:border-slate-800 hover:border-[#0057B8] dark:hover:border-sky-400 bg-slate-50/50 dark:bg-slate-800/40 hover:bg-white dark:hover:bg-slate-800/80 transition-all cursor-pointer shadow-sm group"
                            data-shift-id="<?= $s['id'] ?>"
                            data-lat="<?= $s['lat'] ?>"
                            data-lng="<?= $s['lng'] ?>"
                            data-color="<?= h($s['color_category']) ?>"
                        >
                            <div class="flex items-center justify-between gap-2 mb-1.5">
                                <span class="badge text-[10px] font-bold" style="background-color: <?= h($s['color_hex']) ?>1A; color: <?= h($s['color_hex']) ?>; border: 1px solid <?= h($s['color_hex']) ?>40;">
                                    <?= h($s['color_label']) ?> · <?= h($s['code']) ?>
                                </span>
                                <span class="gis-card-distance text-[11px] font-bold text-slate-500 dark:text-slate-400 mono">
                                    -- km
                                </span>
                            </div>

                            <h3 class="font-extrabold text-xs text-slate-900 dark:text-white line-clamp-1 group-hover:text-[#0057B8] dark:group-hover:text-sky-400 transition-colors">
                                <?= h($s['title']) ?>
                            </h3>

                            <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center gap-1 mt-1 line-clamp-1">
                                <span class="material-symbols-outlined text-[13px] text-rose-500 shrink-0">pin_drop</span>
                                <span><?= h($s['location_name']) ?></span>
                            </p>

                            <div class="mt-2.5 pt-2 border-t border-slate-200/60 dark:border-slate-700/60 flex items-center justify-between text-[11px]">
                                <span class="text-slate-600 dark:text-slate-300 font-medium">
                                    Quân số: <strong class="text-slate-900 dark:text-white"><?= (int) $s['assigned_count'] ?>/<?= (int) $s['max_people'] ?></strong>
                                    <?php if ($s['is_full']): ?>
                                        <span class="text-rose-500 font-bold ml-1">(Đầy)</span>
                                    <?php elseif ($s['assigned_count'] < $s['min_people']): ?>
                                        <span class="text-amber-500 font-bold ml-1">(Cần gấp)</span>
                                    <?php else: ?>
                                        <span class="text-emerald-500 font-bold ml-1">(Đang mở)</span>
                                    <?php endif; ?>
                                </span>
                                <a
                                    href="?view=shift_detail&shift_id=<?= $s['id'] ?>&activity_id=<?= $s['activity_id'] ?>"
                                    class="text-[#0057B8] dark:text-sky-400 hover:underline font-bold inline-flex items-center gap-0.5"
                                    title="Ứng tuyển trực tiếp"
                                >
                                    <span>Ứng tuyển</span>
                                    <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Client-side Interactive Leaflet & Geolocation Logic -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var allShifts = <?= $allShiftsJson ?>;
        var centerLat = <?= GisService::DEFAULT_CENTER_LAT ?>;
        var centerLng = <?= GisService::DEFAULT_CENTER_LNG ?>;
        var activeRadius = null; // null = all
        var userLocationMarker = null;
        var radiusCircle = null;
        var markers = {};
        var leafletMap = null;

        var colorConfig = {
            red: { hex: '#EF4444', label: 'Y tế đỏ' },
            green: { hex: '#10B981', label: 'Môi trường xanh' },
            orange: { hex: '#F59E0B', label: 'Tiếp sức mùa thi vàng cam' },
            blue: { hex: '#0057B8', label: 'Mùa hè xanh xanh dương' }
        };

        function haversineDistanceKm(lat1, lon1, lat2, lon2) {
            if (Math.abs(lat1 - lat2) < 0.000001 && Math.abs(lon1 - lon2) < 0.000001) return 0.0;
            var R = 6371.0;
            var dLat = (lat2 - lat1) * Math.PI / 180.0;
            var dLon = (lon2 - lon1) * Math.PI / 180.0;
            var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                    Math.cos(lat1 * Math.PI / 180.0) * Math.cos(lat2 * Math.PI / 180.0) *
                    Math.sin(dLon / 2) * Math.sin(dLon / 2);
            var c = 2 * Math.atan2(Math.sqrt(Math.max(0, Math.min(1, a))), Math.sqrt(Math.max(0, 1 - a)));
            return Math.round(R * c * 100) / 100;
        }

        function createColorIcon(colorKey, code) {
            var cfg = colorConfig[colorKey] || colorConfig.blue;
            var svgHtml = '<div style="position:relative; width:34px; height:42px; cursor:pointer;" class="gis-marker-pin">' +
                '<svg viewBox="0 0 34 42" width="34" height="42" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                    '<path d="M17 0C7.61 0 0 7.61 0 17C0 27.5 17 42 17 42C17 42 34 27.5 34 17C34 7.61 26.39 0 17 0Z" fill="' + cfg.hex + '" filter="drop-shadow(0 3px 6px rgba(0,0,0,0.25))"/>' +
                    '<circle cx="17" cy="16" r="10" fill="#FFFFFF"/>' +
                    '<circle cx="17" cy="16" r="6" fill="' + cfg.hex + '"/>' +
                '</svg>' +
                '<span style="position:absolute; bottom:-12px; left:50%; transform:translateX(-50%); background:rgba(15,23,42,0.85); color:#fff; font-size:9px; font-weight:800; padding:1px 5px; border-radius:4px; white-space:nowrap; pointer-events:none; font-family:monospace;">' + code + '</span>' +
            '</div>';

            if (typeof L !== 'undefined' && L.divIcon) {
                return L.divIcon({
                    html: svgHtml,
                    className: 'gis-custom-pin',
                    iconSize: [34, 42],
                    iconAnchor: [17, 42],
                    popupAnchor: [0, -38]
                });
            }
            return null;
        }

        function buildPopupContent(shift, distKm) {
            var cfg = colorConfig[shift.color_category] || colorConfig.blue;
            var distText = distKm !== null ? ('Cách bạn <strong>' + distKm.toFixed(2) + ' km</strong>') : 'Tọa độ GPS TP.HCM';

            var statusBadge = '';
            if (shift.is_full) {
                statusBadge = '<span class="badge badge-violation" style="font-size:10px;">Đã đủ người</span>';
            } else if (shift.assigned_count < shift.min_people) {
                statusBadge = '<span class="badge badge-warning" style="font-size:10px;">Cần khẩn cấp</span>';
            } else {
                statusBadge = '<span class="badge badge-valid" style="font-size:10px;">Đang tuyển quân</span>';
            }

            return '<div style="min-width:260px; max-width:320px; font-family:sans-serif;" class="p-1">' +
                '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">' +
                    '<span style="background:' + cfg.hex + '1A; color:' + cfg.hex + '; border:1px solid ' + cfg.hex + '40; font-size:10px; font-weight:800; padding:2px 6px; border-radius:6px; font-family:monospace;">' +
                        shift.campaign_code + ' · ' + shift.code +
                    '</span>' +
                    statusBadge +
                '</div>' +
                '<h4 style="font-size:13px; font-weight:800; color:#0f172a; margin:0 0 4px 0; line-height:1.3;">' + shift.title + '</h4>' +
                '<p style="font-size:11px; color:#64748b; margin:0 0 8px 0; display:flex; align-items:center; gap:4px;">' +
                    '<span>📍</span><span>' + shift.location_name + '</span>' +
                '</p>' +
                '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:6px 8px; margin-bottom:8px; font-size:11px;">' +
                    '<div style="display:flex; justify-content:space-between; margin-bottom:2px;">' +
                        '<span style="color:#64748b;">Quân số:</span>' +
                        '<strong>' + shift.assigned_count + ' / ' + shift.max_people + ' TNV (Tối thiểu ' + shift.min_people + ')</strong>' +
                    '</div>' +
                    '<div style="display:flex; justify-content:space-between; margin-bottom:2px;">' +
                        '<span style="color:#64748b;">Kỹ năng:</span>' +
                        '<strong>' + shift.skill_name + ' (Cấp ' + shift.min_level + ')</strong>' +
                    '</div>' +
                    '<div style="display:flex; justify-content:space-between; color:#0057B8;">' +
                        '<span>Khoảng cách:</span>' +
                        '<span>' + distText + '</span>' +
                    '</div>' +
                '</div>' +
                '<div style="display:flex; gap:6px;">' +
                    '<a href="?view=shift_detail&shift_id=' + shift.id + '&activity_id=' + shift.activity_id + '" ' +
                       'style="flex:1; display:flex; align-items:center; justify-content:center; gap:4px; background:#0057B8; color:#ffffff; font-weight:700; font-size:11px; padding:7px 10px; border-radius:8px; text-decoration:none;">' +
                        '<span>Ứng tuyển 1-chạm</span> →' +
                    '</a>' +
                '</div>' +
            '</div>';
        }

        function refreshGisView() {
            var visibleCount = 0;
            var radiusIndicator = document.getElementById('gis-radius-indicator');
            var countLabel = document.getElementById('gis-visible-count');

            if (radiusIndicator) {
                radiusIndicator.textContent = activeRadius ? ('<' + activeRadius + ' km') : 'Tất cả cự ly';
            }

            allShifts.forEach(function (shift) {
                var dist = haversineDistanceKm(centerLat, centerLng, shift.lat, shift.lng);
                shift.currentDistance = dist;
                var isVisible = (activeRadius === null) || (dist <= activeRadius);

                if (markers[shift.id] && leafletMap) {
                    if (isVisible) {
                        if (!leafletMap.hasLayer(markers[shift.id])) {
                            markers[shift.id].addTo(leafletMap);
                        }
                        markers[shift.id].setPopupContent(buildPopupContent(shift, dist));
                    } else {
                        if (leafletMap.hasLayer(markers[shift.id])) {
                            leafletMap.removeLayer(markers[shift.id]);
                        }
                    }
                }

                var card = document.querySelector('.gis-shift-card[data-shift-id="' + shift.id + '"]');
                if (card) {
                    if (isVisible) {
                        card.style.display = 'block';
                        visibleCount++;
                        var distLabel = card.querySelector('.gis-card-distance');
                        if (distLabel) {
                            distLabel.textContent = dist.toFixed(2) + ' km';
                            distLabel.classList.add('text-[#0057B8]', 'dark:text-sky-400');
                        }
                    } else {
                        card.style.display = 'none';
                    }
                }
            });

            if (countLabel) {
                countLabel.textContent = visibleCount.toString();
            }

            if (leafletMap && typeof L !== 'undefined') {
                if (radiusCircle) {
                    leafletMap.removeLayer(radiusCircle);
                    radiusCircle = null;
                }
                if (activeRadius !== null && activeRadius > 0) {
                    radiusCircle = L.circle([centerLat, centerLng], {
                        radius: activeRadius * 1000,
                        color: '#10B981',
                        fillColor: '#10B981',
                        fillOpacity: 0.12,
                        weight: 2,
                        dashArray: '6, 6'
                    }).addTo(leafletMap);
                }
            }
        }

        if (typeof L !== 'undefined') {
            try {
                leafletMap = L.map('gis-map').setView([centerLat, centerLng], 12);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(leafletMap);

                var userIcon = L.divIcon({
                    html: '<div style="width:18px; height:18px; background:#0057B8; border:3px solid #fff; border-radius:50%; box-shadow:0 0 0 4px rgba(0,87,184,0.3);"></div>',
                    className: 'gis-user-pin',
                    iconSize: [18, 18],
                    iconAnchor: [9, 9]
                });
                userLocationMarker = L.marker([centerLat, centerLng], { icon: userIcon, title: 'Vị trí của bạn' }).addTo(leafletMap);
                userLocationMarker.bindPopup('<strong>Vị trí của bạn (Tâm định vị GPS)</strong><br>Khoảng cách các ca trực được tính từ điểm này.');

                allShifts.forEach(function (shift) {
                    var icon = createColorIcon(shift.color_category, shift.code);
                    var marker = L.marker([shift.lat, shift.lng], icon ? { icon: icon } : {});
                    var dist = haversineDistanceKm(centerLat, centerLng, shift.lat, shift.lng);
                    marker.bindPopup(buildPopupContent(shift, dist));
                    marker.addTo(leafletMap);
                    markers[shift.id] = marker;
                });

                leafletMap.on('click', function (e) {
                    centerLat = e.latlng.lat;
                    centerLng = e.latlng.lng;
                    if (userLocationMarker) {
                        userLocationMarker.setLatLng(e.latlng);
                    }
                    var centerLabel = document.getElementById('gis-center-label');
                    if (centerLabel) {
                        centerLabel.textContent = centerLat.toFixed(4) + '° N, ' + centerLng.toFixed(4) + '° E (Tùy chọn)';
                    }
                    refreshGisView();
                });
            } catch (err) {
                console.warn('Leaflet initialization warning:', err);
            }
        }

        document.querySelectorAll('.gis-radius-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.gis-radius-btn').forEach(function (b) {
                    b.classList.remove('bg-[#0057B8]', 'text-white', 'shadow-sm');
                    b.classList.add('bg-slate-100', 'dark:bg-slate-800', 'text-slate-700', 'dark:text-slate-300');
                });
                btn.classList.add('bg-[#0057B8]', 'text-white', 'shadow-sm');
                btn.classList.remove('bg-slate-100', 'dark:bg-slate-800', 'text-slate-700', 'dark:text-slate-300');

                var rVal = btn.getAttribute('data-radius');
                activeRadius = (rVal === 'ALL' || !rVal) ? null : parseFloat(rVal);
                refreshGisView();
            });
        });

        var btnMyLocation = document.getElementById('btn-my-location');
        if (btnMyLocation) {
            btnMyLocation.addEventListener('click', function () {
                if (navigator.geolocation) {
                    btnMyLocation.classList.add('animate-pulse');
                    navigator.geolocation.getCurrentPosition(
                        function (pos) {
                            btnMyLocation.classList.remove('animate-pulse');
                            centerLat = pos.coords.latitude;
                            centerLng = pos.coords.longitude;
                            if (userLocationMarker && leafletMap) {
                                userLocationMarker.setLatLng([centerLat, centerLng]);
                                leafletMap.setView([centerLat, centerLng], 13);
                            }
                            var centerLabel = document.getElementById('gis-center-label');
                            if (centerLabel) {
                                centerLabel.textContent = centerLat.toFixed(4) + '° N, ' + centerLng.toFixed(4) + '° E (GPS Thiết bị)';
                            }
                            refreshGisView();
                            if (window.XanhHubUI) {
                                window.XanhHubUI.toast('success', 'Định vị GPS thành công', 'Đã cập nhật tâm định vị theo vị trí thiết bị của bạn.');
                            }
                        },
                        function (err) {
                            btnMyLocation.classList.remove('animate-pulse');
                            if (window.XanhHubUI) {
                                window.XanhHubUI.toast('warning', 'Không thể lấy GPS', 'Dùng vị trí mặc định tại Trung tâm TP.HCM (Quảng trường Bến Thành).');
                            }
                        },
                        { timeout: 7000 }
                    );
                }
            });
        }

        document.querySelectorAll('.gis-shift-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                if (e.target.closest('a')) return;
                var sId = parseInt(card.getAttribute('data-shift-id'), 10);
                var marker = markers[sId];
                if (marker && leafletMap) {
                    leafletMap.setView(marker.getLatLng(), 15, { animate: true });
                    marker.openPopup();
                }
            });
        });

        refreshGisView();
    });
    </script>

<?php elseif ($view === 'shift_detail'): ?>
    <!-- ======================================================================
         TRANG CHI TIẾT CA TRỰC & ĐĂNG KÝ TRỰC TIẾP (Chuẩn TechPro ProductDetail.jsx)
         ====================================================================== -->
    <?php if ($shiftNotFound): ?>
        <?php http_response_code(404); ?>
        <main class="flex-1 max-w-4xl w-full mx-auto px-4 md:px-12 py-16 page-enter text-center" id="alert-shift-not-found">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-rose-100 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 mb-6 shadow-inner">
                <span class="material-symbols-outlined text-[40px]">event_busy</span>
            </div>
            <span class="badge badge-violation mono text-xs mb-3 px-3 py-1">Lỗi Tra Cứu Ca Trực · 404 Not Found</span>
            <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white mb-3">
                Không tìm thấy thông tin ca trực yêu cầu
            </h1>
            <p class="text-slate-600 dark:text-slate-400 max-w-lg mx-auto mb-8 text-sm leading-relaxed">
                Mã ca trực <strong>#<?= h(is_scalar($rawShiftId) ? (string) $rawShiftId : 'Trống') ?></strong> không tồn tại trong hệ thống, đã kết thúc hoặc liên kết không còn hợp lệ. Vui lòng kiểm tra lại danh sách các ca trực đang mở đăng ký.
            </p>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <a href="?view=campaigns&activity_id=<?= (int) $selectedActivityId ?>" class="btn btn-primary flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">explore</span>
                    <span>Xem Danh mục Ca trực &amp; Chiến dịch</span>
                </a>
                <a href="?view=home" class="btn btn-outline flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">home</span>
                    <span>Về Trang chủ</span>
                </a>
            </div>
        </main>
    <?php else: ?>
        <?php
        $reqObj = $targetShift->requirements[0] ?? null;
        $skCode = $reqObj?->skillCode ?? 'FIRST_AID';
        $skLabel = $skillNameVi[$skCode] ?? $skCode;

        $stmtShiftMembers = $pdo->prepare("
            SELECT a.id, a.volunteer_id, a.status, u.full_name, v.student_code, v.chapter_name
              FROM assignments a
              JOIN users u ON u.id = a.volunteer_id
              JOIN volunteers v ON v.user_id = a.volunteer_id
             WHERE a.shift_id = :sid
               AND a.status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')
             ORDER BY a.id ASC
        ");
        $stmtShiftMembers->execute([':sid' => $targetShift->id]);
        $shiftMembers = $stmtShiftMembers->fetchAll();
        $currCount = count($shiftMembers);
        $fillPct = min(100, (int) round(($currCount / max(1, $targetShift->maxPeople)) * 100));
        ?>
        <main class="flex-1 max-w-7xl w-full mx-auto px-4 md:px-12 py-8 page-enter">
        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-6">
            <a href="?view=home" class="hover:text-[#0057B8]">Trang chủ</a>
            <span>/</span>
            <a href="?view=campaigns&activity_id=<?= $selectedActivityId ?>" class="hover:text-[#0057B8]"><?= h($activeCampaign['short_title']) ?></a>
            <span>/</span>
            <span class="text-slate-900 dark:text-white font-bold">[<?= h($targetShift->code) ?>] <?= h($targetShift->title) ?></span>
        </nav>

        <!-- 2-Column ProductDetail Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-12">
            <!-- Left: Media Gallery (Photos + Video) -->
            <div class="lg:col-span-7 space-y-3">
                <div class="rounded-3xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-950 aspect-[16/10] relative shadow-lg">
                    <img id="shift-detail-main-img" src="<?= h($activeCampaign['cover']) ?>" alt="<?= h($targetShift->title) ?>" class="w-full h-full object-cover" />
                    <video id="shift-detail-main-video" src="assets/video/xanhhub-cinematic.mp4" controls muted loop playsinline class="hidden w-full h-full object-cover"></video>
                    <span class="absolute top-4 left-4 bg-slate-900/85 backdrop-blur text-white px-3.5 py-1.5 rounded-full text-xs font-mono font-bold">
                        <?= h($targetShift->code) ?> &middot; <?= h($activeCampaign['short_title']) ?>
                    </span>
                </div>

                <!-- Thumbnails -->
                <div class="grid grid-cols-4 gap-3">
                    <?php
                    $thumbs = [
                        ['type' => 'img', 'src' => $activeCampaign['cover'], 'label' => 'Điểm trực chính'],
                        ['type' => 'img', 'src' => 'assets/images/hero-volunteer.jpg', 'label' => 'Đội hình ra quân'],
                        ['type' => 'img', 'src' => 'assets/images/campaign-d2.jpg', 'label' => 'Hỗ trợ tại chỗ'],
                        ['type' => 'video', 'src' => 'assets/video/xanhhub-cinematic.mp4', 'label' => 'Video Thực địa'],
                    ];
                    foreach ($thumbs as $tIdx => $th):
                    ?>
                        <button
                            type="button"
                            data-gallery-type="<?= h($th['type']) ?>"
                            data-gallery-src="<?= h($th['src']) ?>"
                            class="shift-gallery-thumb rounded-2xl overflow-hidden border-2 <?= $tIdx === 0 ? 'border-[#0057B8]' : 'border-transparent opacity-75 hover:opacity-100' ?> aspect-video relative bg-slate-900 transition-all"
                        >
                            <?php if ($th['type'] === 'video'): ?>
                                <img src="assets/images/campaign-d3.jpg" alt="" class="w-full h-full object-cover opacity-60" />
                                <div class="absolute inset-0 flex flex-col items-center justify-center text-white">
                                    <span class="material-symbols-outlined text-[28px] filled text-sky-400">play_circle</span>
                                    <span class="text-[10px] font-bold">Phim Thực địa</span>
                                </div>
                            <?php else: ?>
                                <img src="<?= h($th['src']) ?>" alt="<?= h($th['label']) ?>" class="w-full h-full object-cover" />
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Right: Shift Specifications & Direct Registration -->
            <div class="lg:col-span-5 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 md:p-8 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <span class="badge badge-info mono text-xs px-3 py-1"><?= h($targetShift->code) ?></span>
                        <span class="badge badge-valid"><?= h($skLabel) ?> (Yêu cầu Cấp &ge; <?= (int) ($reqObj?->minLevel ?? 1) ?>)</span>
                    </div>

                    <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white mb-3">
                        <?= h($targetShift->title) ?>
                    </h1>

                    <div class="flex items-center gap-1 mb-5">
                        <?php for ($st = 1; $st <= 5; $st++): ?>
                            <span class="material-symbols-outlined text-[18px] <?= $st <= $targetShift->priority ? 'text-amber-400 filled' : 'text-slate-300 dark:text-slate-700' ?>">star</span>
                        <?php endfor; ?>
                        <span class="text-xs font-semibold text-slate-500 ml-2">Mức độ ưu tiên chiến dịch: <?= (int) $targetShift->priority ?>/5</span>
                    </div>

                    <div class="space-y-3 bg-slate-50 dark:bg-slate-800/60 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-700 mb-6 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400">calendar_month</span>
                            <div>
                                <div class="text-xs text-slate-500">Thời gian trực</div>
                                <div class="font-bold mono"><?= date('d/m/Y · H:i', $targetShift->startEpoch) ?> – <?= date('H:i', $targetShift->endEpoch) ?></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-emerald-600">location_on</span>
                            <div>
                                <div class="text-xs text-slate-500">Địa điểm tập kết</div>
                                <div class="font-bold"><?= h($targetShift->locationName) ?></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-amber-500">groups</span>
                            <div class="flex-1">
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="text-slate-500">Định biên nhân sự</span>
                                    <span class="font-bold mono"><?= $currCount ?> / <?= $targetShift->maxPeople ?> TNV (Tối thiểu <?= $targetShift->minPeople ?>)</span>
                                </div>
                                <div class="h-2 w-full bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-[#0057B8] to-emerald-500 rounded-full" style="width: <?= $fillPct ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Đăng ký Nguyện vọng Trực tiếp -->
                    <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&action=volunteer_register_shift" class="space-y-3">
                        <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                        <input type="hidden" name="shift_id" value="<?= $targetShift->id ?>">
                        <input type="hidden" name="volunteer_id" value="<?= $currentVolunteerId ?>">

                        <div>
                            <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1.5">Chọn Mức độ Nguyện vọng của bạn:</label>
                            <select name="preference_rank" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3.5 py-2.5 text-sm font-semibold">
                                <option value="1">Nguyện vọng 1 — Ưu tiên cao nhất</option>
                                <option value="2">Nguyện vọng 2 — Phù hợp lịch cá nhân</option>
                                <option value="3">Nguyện vọng 3 — Sẵn sàng hỗ trợ bổ sung</option>
                            </select>
                        </div>

                        <button type="submit" class="w-full py-3.5 px-6 bg-[#0057B8] hover:bg-[#004494] text-white font-bold text-sm rounded-xl shadow-lg shadow-blue-600/25 transition-all flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-[20px]">how_to_reg</span>
                            <span>Đăng ký Tham gia Ca trực này</span>
                        </button>
                    </form>
                    <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&action=volunteer_withdraw_registration" class="mt-2.5">
                        <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                        <input type="hidden" name="shift_id" value="<?= $targetShift->id ?>">
                        <input type="hidden" name="volunteer_id" value="<?= $currentVolunteerId ?>">
                        <button type="submit" class="w-full py-2.5 px-4 border border-rose-200 dark:border-rose-800/80 bg-rose-50/60 dark:bg-rose-950/30 hover:bg-rose-100 text-rose-600 dark:text-rose-400 font-bold text-xs rounded-xl transition-all flex items-center justify-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">person_remove</span>
                            <span>Rút đăng ký khỏi ca trực này</span>
                        </button>
                    </form>
                </div>

                <div class="pt-4 mt-5 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px] text-emerald-600">qr_code_scanner</span>
                        <span>Có điểm danh QR 30 giây</span>
                    </span>
                    <?php if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
                        <a href="?view=coordinator&activity_id=<?= $selectedActivityId ?>" class="font-bold text-[#0057B8] dark:text-sky-400 hover:underline">
                            Mở trong Bàn Điều phối &rarr;
                        </a>
                    <?php elseif ($currentRole === 'VOLUNTEER'): ?>
                        <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>" class="font-bold text-[#0057B8] dark:text-sky-400 hover:underline">
                            Mở Sổ tay Ca trực Cá nhân &rarr;
                        </a>
                    <?php else: ?>
                        <a href="?view=campaigns&activity_id=<?= $selectedActivityId ?>" class="font-bold text-[#0057B8] dark:text-sky-400 hover:underline">
                            Xem các ca khác &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Đội hình TNV đã được phân công trong ca này -->
        <section class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 md:p-8 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-[#0057B8]">badge</span>
                <span>Danh sách Tình nguyện viên Đã Phân công vào Ca [<?= h($targetShift->code) ?>] (<?= $currCount ?> bạn)</span>
            </h2>
            <?php if (empty($shiftMembers)): ?>
                <div class="p-6 text-center text-slate-500 bg-slate-50 dark:bg-slate-800/40 rounded-2xl">
                    Ca trực này đang mở tiếp nhận hồ sơ. Hãy là người đầu tiên đăng ký!
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <?php foreach ($shiftMembers as $sm):
                        $smStatusVi = match ((string) ($sm['status'] ?? '')) {
                            'CHECKED_IN' => '✓ Đã điểm danh',
                            'CONFIRMED' => '✓ Đã xác nhận',
                            'ASSIGNED' => 'Đã xếp ca',
                            'AT_RISK' => 'Cần hỗ trợ',
                            default => 'Đã xếp ca',
                        };
                    ?>
                        <div class="flex items-center justify-between p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40">
                            <div class="flex items-center gap-3">
                                <img src="<?= h(avatarUrlFor((int) $sm['volunteer_id'], $sm['full_name'])) ?>" alt="" class="w-11 h-11 rounded-full object-cover border-2 border-[#0057B8]">
                                <div>
                                    <div class="font-bold text-sm text-slate-900 dark:text-white"><?= h($sm['full_name']) ?></div>
                                    <div class="text-xs text-slate-500 mono"><?= $currentRole !== 'PUBLIC' ? (h($sm['student_code']) . ' &middot; ') : '' ?><?= h($sm['chapter_name']) ?></div>
                                </div>
                            </div>
                            <span class="badge badge-valid"><?= h($smStatusVi) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
<?php endif; ?>

<?php elseif ($view === 'news'): ?>
    <!-- ======================================================================
         TRANG PHIM TÀI LIỆU & BẢN TIN TUỔI TRẺ (Chuẩn TechPro News.jsx + NewsDetail.jsx)
         ====================================================================== -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 md:px-12 py-10 page-enter">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-8 gap-4">
            <div>
                <span class="badge badge-info mb-2">Truyền thông &amp; Phim Thực địa</span>
                <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-white">Thước phim Hành trình &amp; Bản tin Áo Xanh</h1>
            </div>
            <button type="button" data-open-modal="modal-cinema-video" class="btn btn-primary">
                <span class="material-symbols-outlined text-[18px] filled">movie</span>
                <span>Mở Rạp Phim Toàn Màn Hình</span>
            </button>
        </div>

        <!-- Featured Documentary Video Showcase -->
        <section class="rounded-3xl overflow-hidden bg-slate-950 text-white border border-slate-800 shadow-2xl mb-12 grid grid-cols-1 lg:grid-cols-12">
            <div class="lg:col-span-7 relative">
                <video autoplay loop muted playsinline controls poster="assets/images/hero-volunteer.jpg" class="w-full h-full object-cover min-h-[320px]">
                    <source src="assets/video/xanhhub-cinematic.mp4" type="video/mp4">
                </video>
            </div>
            <div class="lg:col-span-5 p-8 flex flex-col justify-between">
                <div>
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-sky-400 mb-3">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                        Phóng sự Đặc biệt Hè 2026
                    </span>
                    <h2 class="text-2xl font-extrabold mb-3 leading-snug">
                        Từ Giảng đường Đến Những Điểm Trực Mùa Hè Xanh &amp; Tiếp Sức Mùa Thi
                    </h2>
                    <p class="text-sm text-slate-300 leading-relaxed mb-6">
                        Ghi lại khoảnh khắc hàng trăm sinh viên tình nguyện khoác lên mình màu áo xanh thanh niên, trực chốt sơ cứu y tế dưới nắng hè, phân luồng giao thông tại các điểm thi đại học và cải tạo cảnh quan kênh Nhiêu Lộc.
                    </p>
                </div>
                <div class="pt-4 border-t border-white/10 flex items-center justify-between text-xs text-slate-400">
                    <span>Đội Truyền thông Xanh Hub</span>
                    <a href="?view=register" class="text-sky-400 font-bold hover:underline">Gia nhập Đội hình Truyền thông &rarr;</a>
                </div>
            </div>
        </section>

        <!-- 4 Bài viết Phóng sự Chi tiết -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php
            $newsArticles = [
                [
                    'tag' => 'Tiếp Sức Mùa Thi',
                    'date' => '25/06/2026',
                    'title' => 'Đội hình Sơ cứu Y tế & Tiếp sức Sĩ tử tại Cụm thi ĐH Bách Khoa TP.HCM',
                    'summary' => '30 tình nguyện viên có chứng chỉ sơ cấp cứu và hậu cần được phân bổ đều vào 12 ca trực không trùng lịch thi cá nhân, hỗ trợ kịp thời hơn 2.000 thí sinh.',
                    'img' => 'assets/images/campaign-d2.jpg',
                    'act_id' => 2,
                ],
                [
                    'tag' => 'Chủ Nhật Xanh',
                    'date' => '14/06/2026',
                    'title' => 'Ra quân Cải tạo Cảnh quan Kênh Nhiêu Lộc & Trồng mới 500 Cây xanh Đô thị',
                    'summary' => 'Chiến dịch cuối tuần thu hút đông đảo đoàn viên các Liên chi Đoàn tham gia làm sạch tuyến kênh và hướng dẫn người dân phân loại rác tại nguồn.',
                    'img' => 'assets/images/campaign-d1.jpg',
                    'act_id' => 1,
                ],
                [
                    'tag' => 'Mùa Hè Xanh',
                    'date' => '18/06/2026',
                    'title' => 'Ứng dụng Điểm danh QR Xoay vòng 30 Giây & Cấp Chứng nhận Tình nguyện Số',
                    'summary' => 'Mỗi ca trực tại hiện trường đều được xác thực bằng mã QR động 30 giây, giúp ghi nhận chính xác số giờ cống hiến để xét danh hiệu Sinh viên 5 Tốt.',
                    'img' => 'assets/images/campaign-d3.jpg',
                    'act_id' => 3,
                ],
                [
                    'tag' => 'Gương mặt Áo Xanh',
                    'date' => '20/06/2026',
                    'title' => 'Câu chuyện của Linh & Phúc: Khi Kỹ năng Chuyên môn Được Đặt Đúng Vị trí',
                    'summary' => 'Nhờ cơ chế điều phối thông minh ưu tiên giữ người có kỹ năng Sơ cứu Y tế hiếm cho các chốt trọng điểm, mọi ca trực đều đủ định biên 100%.',
                    'img' => 'assets/images/hero-volunteer.jpg',
                    'act_id' => 3,
                ],
            ];
            foreach ($newsArticles as $art):
            ?>
                <article data-tilt-card class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-sm hover-lift flex flex-col sm:flex-row h-full">
                    <div class="sm:w-2/5 h-52 sm:h-auto relative shrink-0 overflow-hidden">
                        <img src="<?= h($art['img']) ?>" alt="<?= h($art['title']) ?>" class="w-full h-full object-cover hover:scale-110 transition-transform duration-500" />
                        <span class="absolute top-3 left-3 bg-slate-900/80 text-white text-[10px] font-bold px-2.5 py-1 rounded-full"><?= h($art['tag']) ?></span>
                    </div>
                    <div class="p-6 flex flex-col justify-between flex-1">
                        <div>
                            <div class="text-xs text-slate-400 mb-1.5 mono"><?= h($art['date']) ?></div>
                            <h3 class="font-extrabold text-base text-slate-900 dark:text-white mb-2 leading-snug line-clamp-2 min-h-[3rem]"><?= h($art['title']) ?></h3>
                            <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed mb-4 line-clamp-2 min-h-[2.5rem]"><?= h($art['summary']) ?></p>
                        </div>
                        <a href="?view=campaigns&activity_id=<?= $art['act_id'] ?>" class="text-xs font-bold text-[#0057B8] dark:text-sky-400 hover:underline flex items-center gap-1 truncate">
                            <span class="truncate">Xem các ca trực thuộc chiến dịch này</span>
                            <span class="material-symbols-outlined text-[15px] shrink-0">arrow_forward</span>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </main>

<?php elseif ($view === 'about'): ?>
    <!-- ======================================================================
         TRANG GIỚI THIỆU HỆ SINH THÁI XANH HUB & LIÊN HỆ BAN CHỈ HUY (Chuẩn TechPro About.jsx + Contact.jsx)
         ====================================================================== -->
    <main class="flex-1 page-enter">
        <!-- Hero Banner -->
        <section class="relative h-[420px] flex items-center justify-center overflow-hidden bg-slate-950 text-white">
            <img src="assets/images/hero-volunteer.jpg" alt="Về Xanh Hub" class="absolute inset-0 w-full h-full object-cover opacity-40 ken-burns-bg" />
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/60 to-transparent"></div>
            <div class="relative z-10 max-w-4xl mx-auto px-4 text-center fade-in-up">
                <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-sky-500/20 border border-sky-400/30 text-sky-300 text-xs font-bold uppercase tracking-wider mb-4">
                    Sứ mệnh Tuổi trẻ Việt Nam
                </span>
                <h1 class="text-3xl md:text-5xl font-extrabold mb-4 text-glow">Kết nối Chuyên môn — Lan tỏa Tinh thần Tình nguyện</h1>
                <p class="text-sm md:text-base text-slate-200 max-w-2xl mx-auto leading-relaxed">
                    Xanh Hub được xây dựng nhằm giúp các cấp bộ Đoàn – Hội tổ chức hoạt động tình nguyện khoa học, tôn trọng tuyệt đối lịch học của sinh viên và minh bạch hóa từng giờ cống hiến.
                </p>
            </div>
        </section>

        <!-- Bento Grid Story & Core Values -->
        <section class="max-w-7xl mx-auto px-4 md:px-12 py-14">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 mb-12">
                <div class="md:col-span-8 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-8 shadow-sm">
                    <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white mb-4 flex items-center gap-2.5">
                        <span class="material-symbols-outlined text-[#0057B8] text-[28px]">diversity_3</span>
                        <span>Giải quyết Bài toán Điều phối Tình nguyện viên Thực tế</span>
                    </h2>
                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed mb-4">
                        Trong các chiến dịch lớn như <strong>Mùa Hè Xanh</strong>, <strong>Tiếp Sức Mùa Thi</strong> hay <strong>Chủ Nhật Xanh</strong>, việc xếp lịch thủ công trên bảng tính thường dẫn đến trùng giờ học của sinh viên, thiếu hụt nhân sự có chuyên môn <em>Sơ cứu Y tế</em> tại các điểm nóng, hoặc dồn quá nhiều ca cho một số ít thành viên.
                    </p>
                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                        <strong>Xanh Hub</strong> tự động đối chiếu kỹ năng, lịch rảnh 7 ngày và định biên từng điểm trực, kết hợp điểm danh QR xoay vòng 30 giây và cấp Giấy chứng nhận điện tử tra cứu công khai.
                    </p>
                </div>

                <div class="md:col-span-4 flex flex-col gap-6">
                    <div class="bg-[#0057B8] text-white rounded-3xl p-7 flex-1 flex flex-col justify-center items-center text-center shadow-lg">
                        <div class="text-4xl font-extrabold mono mb-1">100%</div>
                        <div class="text-xs font-bold uppercase tracking-wider text-sky-200">Bảo vệ Lịch học &amp; Lịch thi Cá nhân</div>
                    </div>
                    <div class="bg-emerald-600 text-white rounded-3xl p-7 flex-1 flex flex-col justify-center items-center text-center shadow-lg">
                        <div class="text-4xl font-extrabold mono mb-1">100%</div>
                        <div class="text-xs font-bold uppercase tracking-wider text-emerald-100">Minh bạch Giờ Cống hiến &amp; Chứng nhận Điện tử</div>
                    </div>
                </div>
            </div>

            <!-- Liên hệ Ban Chỉ Huy Chiến dịch -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-8 grid grid-cols-1 lg:grid-cols-2 gap-8 shadow-sm">
                <div>
                    <h3 class="text-xl font-extrabold text-slate-900 dark:text-white mb-2">Liên hệ Ban Chỉ Huy Chiến dịch</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                        Gửi yêu cầu phối hợp tổ chức chiến dịch, đăng ký đội hình cấp Khoa/Trường hoặc hỗ trợ xác thực kỹ năng chuyên môn.
                    </p>
                    <div class="space-y-4 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#0057B8]">location_on</span>
                            <span>Văn phòng Đoàn Thanh Niên – Hội Sinh Viên, Khu Đô thị ĐHQG TP.HCM</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-emerald-600">mail</span>
                            <span class="mono">banchihuy@xanhhub.vn</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-amber-500">call</span>
                            <span class="mono">(028) 3724 2026 &middot; Hotline Trực chiến 24/7</span>
                        </div>
                    </div>
                </div>

                <form id="about-contact-form" class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <input type="text" required placeholder="Họ và tên của bạn" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm" />
                        <input type="email" required placeholder="Email liên hệ (@edu.vn / @gmail.com)" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm" />
                    </div>
                    <input type="text" required placeholder="Đơn vị / Liên chi Đoàn / Câu lạc bộ" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm" />
                    <textarea rows="3" required placeholder="Nội dung đề xuất phối hợp hoặc câu hỏi hỗ trợ..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-4 text-sm"></textarea>
                    <button type="submit" class="btn btn-primary w-full py-3">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        <span>Gửi Thông tin tới Ban Chỉ Huy</span>
                    </button>
                </form>
            </div>
        </section>
    </main>

<?php elseif ($view === 'volunteer'): ?>
    <!-- ======================================================================
         CỔNG TÌNH NGUYỆN VIÊN CHUẨN KIẾN TRÚC TECHPRO (Account.jsx)
         Sidebar Điều khiển bên trái + Profile Overview + 3 Tab chức năng
         ====================================================================== -->
    <?php
    $ds = $repo->loadDatasetForActivity($selectedActivityId);
    $vol = $ds->volunteersById[$currentVolunteerId] ?? $ds->volunteers[0];
    $volMerit = $gamificationService->calculateVolunteerMerit($vol->id);

    $stmtReg = $pdo->prepare('
        SELECT r.id, r.shift_id, r.preference_rank, r.status
          FROM registrations r
         WHERE r.activity_id = :aid AND r.volunteer_id = :vid
    ');
    $stmtReg->execute([':aid' => $selectedActivityId, ':vid' => $vol->id]);
    $regShiftIds = [];
    foreach ($stmtReg->fetchAll() as $mr) {
        if ($mr['status'] === 'ACTIVE') {
            $regShiftIds[(int) $mr['shift_id']] = (int) $mr['preference_rank'];
        }
    }

    // Lấy toàn bộ ca trực đã được phân công của TNV trên tất cả các chiến dịch để không bao giờ bị sót ca
    $stmtAllMyAssign = $pdo->prepare("
        SELECT a.id, a.activity_id, a.shift_id, a.status, a.assigned_at, a.confirmed_at,
               s.code, s.title, s.start_time, s.end_time, s.location_name,
               COALESCE(act.title, 'Chiến dịch') AS activity_title,
               COALESCE(act.code, 'CD') AS activity_code
          FROM assignments a
          JOIN shifts s ON s.id = a.shift_id
          LEFT JOIN activities act ON act.id = a.activity_id
         WHERE a.volunteer_id = :vid
           AND a.status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')
         ORDER BY s.start_time ASC, s.id ASC
    ");
    $stmtAllMyAssign->execute([':vid' => $vol->id]);
    $allMyAssignments = $stmtAllMyAssign->fetchAll();

    // Ca trực thuộc riêng chiến dịch đang chọn hiện tại
    $currentActivityAssignments = array_values(array_filter($allMyAssignments, static fn($m) => (int)$m['activity_id'] === (int)$selectedActivityId));

    // Bộ lọc hiển thị phân công: mặc định 'all' để ứng viên luôn thấy ngay toàn bộ phân công của mình
    $assignFilter = (string)($_GET['assign_filter'] ?? 'all');
    if (!in_array($assignFilter, ['all', 'current'], true)) {
        $assignFilter = 'all';
    }
    $myAssignments = ($assignFilter === 'current') ? $currentActivityAssignments : $allMyAssignments;

    $activeVTab = (string) ($_GET['vtab'] ?? 'vtab-my-shifts');
    if (!in_array($activeVTab, ['vtab-my-shifts', 'vtab-register', 'vtab-profile'], true)) {
        $activeVTab = 'vtab-my-shifts';
    }
    ?>
    <!-- Backdrop và Menu Mini Nổi 1 Lớp Toàn Màn Hình (Full-Height Left Drawer - Không đẩy giao diện) -->
    <div id="workspace-drawer-backdrop" class="workspace-floating-backdrop no-print"></div>
    <button type="button" class="workspace-mini-fab btn-toggle-workspace-drawer no-print" title="Mở/Đóng Menu Cổng Tình nguyện viên">
        <span class="material-symbols-outlined text-[18px]">menu_open</span>
        <span>Menu TNV</span>
    </button>

    <aside id="workspace-floating-drawer" class="workspace-floating-drawer bg-white dark:bg-[#131822] border-r border-slate-200 dark:border-slate-800 flex flex-col py-6 px-4 no-print" aria-label="Menu Cổng Tình nguyện viên">
        <div class="flex items-center justify-between mb-4 px-2 pb-3 border-b border-slate-100 dark:border-slate-800">
            <h2 class="font-bold text-[#0057B8] dark:text-sky-400 flex items-center gap-2 tracking-wider text-xs uppercase">
                <span class="material-symbols-outlined text-[18px]">account_circle</span> Cổng Tình nguyện viên
            </h2>
            <button type="button" id="btn-close-workspace-drawer" class="w-7 h-7 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800" title="Đóng menu">
                <span class="material-symbols-outlined text-[18px]">close</span>
            </button>
        </div>
        <nav class="flex flex-col gap-1.5">
            <button
                type="button"
                data-workspace-tab="vtab-my-shifts"
                class="workspace-tab-btn <?= $activeVTab === 'vtab-my-shifts' ? 'active bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?> flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm transition-all text-left"
            >
                <span class="material-symbols-outlined text-[20px]">event_available</span>
                <span>Ca trực &amp; Điểm danh (<?= count($allMyAssignments) ?>)</span>
            </button>
            <button
                type="button"
                data-workspace-tab="vtab-register"
                class="workspace-tab-btn <?= $activeVTab === 'vtab-register' ? 'active bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?> flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm transition-all text-left"
            >
                <span class="material-symbols-outlined text-[20px]">calendar_add_on</span>
                <span>Đăng ký Ca trực (<?= count($ds->shifts) ?>)</span>
            </button>
            <button
                type="button"
                data-workspace-tab="vtab-profile"
                class="workspace-tab-btn <?= $activeVTab === 'vtab-profile' ? 'active bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?> flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm transition-all text-left"
            >
                <span class="material-symbols-outlined text-[20px]">schedule</span>
                <span>Kỹ năng &amp; Lịch rảnh</span>
            </button>

            <div class="my-3 border-t border-slate-200 dark:border-slate-800"></div>
            <?= renderDrawerCampaignList($campaignMeta, $selectedActivityId, '?view=volunteer&activity_id={id}&vtab=' . urlencode($activeVTab)) ?>

            <div class="my-3 border-t border-slate-200 dark:border-slate-800"></div>
            <a href="?action=export_ics&activity_id=<?= $selectedActivityId ?>&vid=<?= $vol->id ?>" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-bold text-[#0057B8] dark:text-sky-400 hover:bg-blue-50 dark:hover:bg-slate-800 rounded-xl transition-colors">
                <span class="material-symbols-outlined text-[18px]">download</span>
                <span>Tải Lịch cá nhân (.ICS)</span>
            </a>
            <a href="?action=logout" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-bold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 rounded-xl transition-colors">
                <span class="material-symbols-outlined text-[18px]">logout</span>
                <span>Đăng xuất</span>
            </a>
        </nav>
    </aside>

    <div class="flex-1 flex flex-col page-enter max-w-7xl w-full mx-auto min-w-0">
        <!-- Main Content Full-Width (Không bị đẩy lệch phải) -->
        <main class="flex-1 min-w-0 w-full px-4 md:px-8 py-6">
            <!-- Profile Overview Card (Giống hệt TechPro Account.jsx) -->
            <section class="grid grid-cols-1 md:grid-cols-12 gap-5 mb-5">
                <div class="md:col-span-8 rounded-2xl p-5 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col sm:flex-row items-center sm:items-start gap-5">
                    <img src="<?= h(avatarUrlFor($vol->id, $vol->fullName)) ?>" alt="<?= h($vol->fullName) ?>" class="w-20 h-20 rounded-full object-cover border-4 border-[#0057B8] shadow-md shrink-0" />
                    <div class="flex-1 text-center sm:text-left">
                        <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 mb-1">
                            <h1 class="text-xl font-extrabold text-slate-900 dark:text-white"><?= h($vol->fullName) ?></h1>
                            <span class="badge badge-info mono"><?= h($vol->studentCode) ?></span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400"><?= h($vol->chapterName) ?> &middot; Chiến dịch: <strong><?= h($activeCampaign['short_title']) ?></strong></p>
                        <div class="flex flex-wrap items-center justify-center sm:justify-start gap-3 mt-3">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 rounded-lg text-xs font-bold text-emerald-700 dark:text-emerald-300">
                                <span class="material-symbols-outlined text-[15px] filled">stars</span> Tình nguyện viên Nòng cốt
                            </span>
                            <span class="text-xs text-slate-600 dark:text-slate-300">
                                Tổng ca đã giao: <strong class="text-[#0057B8] dark:text-sky-400 font-mono text-sm"><?= count($allMyAssignments) ?> ca</strong>
                                <?php if (!empty($allMyAssignments) && count($currentActivityAssignments) !== count($allMyAssignments)): ?>
                                    <span class="text-[11px] text-slate-400">(<?= count($currentActivityAssignments) ?> ca trong chiến dịch này)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-4 rounded-2xl p-5 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <h3 class="font-bold text-xs uppercase tracking-wider text-slate-900 dark:text-white flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[#0057B8] text-[17px]">verified</span>
                                <span>Kỹ năng Đã xác thực</span>
                            </h3>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <?php foreach ($vol->skills as $skId => $skEntry):
                                $skCode = $skillCodeById[(int) $skId] ?? ('SKILL_' . $skId);
                            ?>
                                <span class="badge badge-info"><?= h($skillNameVi[$skCode] ?? $skCode) ?> (Cấp <?= (int) $skEntry['level'] ?>)</span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="?view=verify" class="mt-3 text-xs font-bold text-[#0057B8] dark:text-sky-400 hover:underline flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">workspace_premium</span>
                        <span>Xem Giấy chứng nhận tình nguyện &rarr;</span>
                    </a>
                </div>
            </section>

            <!-- Thanh Điều hướng Nhanh & Nút Mở Menu Mini Nổi (Đặt sát ngay trên vùng Nội dung Tab để thấy chuyển đổi tức thì) -->
            <div id="volunteer-workspace-tabs-bar" class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-3 mb-5 shadow-sm flex flex-wrap items-center justify-between gap-3 sticky top-[68px] z-30">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-toggle-workspace-drawer inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-slate-900 dark:bg-sky-500 text-white dark:text-slate-950 font-bold text-xs shadow-sm hover:opacity-90 transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-[18px]">widgets</span>
                        <span>Menu Cổng TNV</span>
                    </button>
                    <div class="h-5 w-[1px] bg-slate-200 dark:bg-slate-700 hidden sm:block mx-0.5"></div>
                    <button type="button" data-workspace-tab="vtab-my-shifts" class="workspace-tab-btn <?= $activeVTab === 'vtab-my-shifts' ? 'active bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?> inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl font-bold text-xs transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">event_available</span>
                        <span>Ca trực &amp; Điểm danh (<?= count($allMyAssignments) ?>)</span>
                    </button>
                    <button type="button" data-workspace-tab="vtab-register" class="workspace-tab-btn <?= $activeVTab === 'vtab-register' ? 'active bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?> inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl font-bold text-xs transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">calendar_add_on</span>
                        <span>Đăng ký Ca trực (<?= count($ds->shifts) ?>)</span>
                    </button>
                    <button type="button" data-workspace-tab="vtab-profile" class="workspace-tab-btn <?= $activeVTab === 'vtab-profile' ? 'active bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' ?> inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl font-bold text-xs transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">schedule</span>
                        <span>Kỹ năng &amp; Lịch rảnh</span>
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <?= renderScalableCampaignSwitcher($campaignMeta, $selectedActivityId, '?view=volunteer&activity_id={id}&vtab=' . urlencode($activeVTab)) ?>
                </div>
            </div>

            <!-- TAB 1: CA TRỰC CỦA TÔI & LỊCH BIỂU THỜI KHÓA BIỂU -->
            <div id="vtab-my-shifts" class="workspace-tab-pane <?= $activeVTab === 'vtab-my-shifts' ? 'active' : '' ?>">
                <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm mb-6">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-5 pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div>
                            <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                                <span class="material-symbols-outlined text-[#0057B8]">calendar_month</span>
                                <span>Lịch trực &amp; Ca được Phân công của Tôi</span>
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Theo dõi thời khóa biểu trực theo Ngày &amp; Buổi (Sáng / Chiều / Tối), xác nhận tham gia và điểm danh</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <!-- Chuyển chế độ xem: Lịch biểu vs Dạng Thẻ -->
                            <div class="inline-flex items-center bg-slate-100 dark:bg-slate-800 p-1 rounded-xl border border-slate-200 dark:border-slate-700">
                                <button type="button" data-my-shifts-view="schedule" class="btn-my-shifts-view active inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-white dark:bg-slate-900 text-[#0057B8] dark:text-sky-400 shadow-xs transition-all cursor-pointer">
                                    <span class="material-symbols-outlined text-[15px]">calendar_view_week</span>
                                    <span>Lịch theo Ngày &amp; Buổi</span>
                                </button>
                                <button type="button" data-my-shifts-view="cards" class="btn-my-shifts-view inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-slate-900 transition-all cursor-pointer">
                                    <span class="material-symbols-outlined text-[15px]">grid_view</span>
                                    <span>Dạng Thẻ (<?= count($myAssignments) ?>)</span>
                                </button>
                            </div>

                            <div class="h-5 w-[1px] bg-slate-200 dark:bg-slate-700 hidden sm:block"></div>

                            <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-my-shifts&assign_filter=all"
                               class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $assignFilter === 'all' ? 'bg-[#0057B8] text-white shadow-sm' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                                Tất cả chiến dịch (<?= count($allMyAssignments) ?>)
                            </a>
                            <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-my-shifts&assign_filter=current"
                               class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $assignFilter === 'current' ? 'bg-[#0057B8] text-white shadow-sm' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                                <?= h($activeCampaign['short_title']) ?> (<?= count($currentActivityAssignments) ?>)
                            </a>
                        </div>
                    </div>

                    <?php if (empty($myAssignments)): ?>
                        <?php if ($assignFilter === 'current' && !empty($allMyAssignments)): ?>
                            <div class="p-8 text-center rounded-xl bg-blue-50/60 dark:bg-sky-950/20 border border-blue-200 dark:border-sky-800 text-slate-700 dark:text-slate-300">
                                <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400 text-4xl mb-2">info</span>
                                <p class="font-bold text-sm mb-1">Hiện bạn chưa có ca trực nào trong chiến dịch &ldquo;<?= h($activeCampaign['short_title']) ?>&rdquo;.</p>
                                <p class="text-xs text-slate-500 mb-3">Tuy nhiên bạn đang có <strong><?= count($allMyAssignments) ?> ca trực</strong> đã được phân công ở các chiến dịch khác!</p>
                                <a href="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-my-shifts&assign_filter=all" class="btn btn-sm btn-primary inline-flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[16px]">visibility</span>
                                    <span>Xem toàn bộ <?= count($allMyAssignments) ?> ca trực đã phân công</span>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="p-8 text-center text-slate-500">
                                <span class="material-symbols-outlined text-slate-400 text-4xl mb-2">event_busy</span>
                                <p class="font-bold text-sm mb-1">Hiện bạn chưa có ca trực nào được phân công.</p>
                                <p class="text-xs text-slate-500">Hãy bấm sang mục <strong>Đăng ký Ca trực</strong> ở trên để chọn ca phù hợp với lịch rảnh và kỹ năng của bạn!</p>
                            </div>
                        <?php endif; ?>
                    <?php else:
                        // Gom nhóm các ca đã phân công theo Ngày (YYYY-MM-DD) và Khung buổi (MORNING / AFTERNOON / EVENING)
                        $assignmentsByDate = [];
                        foreach ($myAssignments as $maItem) {
                            $fmt = formatShiftTimeRangeVn((string) $maItem['start_time'], (string) $maItem['end_time']);
                            $maItem['_fmt'] = $fmt;
                            $maItem['_clean_activity_title'] = cleanCampaignTitle((string) $maItem['activity_title']);
                            $dKey = $fmt['date_key'];
                            if (!isset($assignmentsByDate[$dKey])) {
                                $assignmentsByDate[$dKey] = [
                                    'date_label' => $fmt['date_label'],
                                    'short_date' => $fmt['short_date'],
                                    'day_name' => $fmt['day_name'],
                                    'sessions' => [
                                        'MORNING' => [],
                                        'AFTERNOON' => [],
                                        'EVENING' => [],
                                    ],
                                    'all' => [],
                                ];
                            }
                            $assignmentsByDate[$dKey]['sessions'][$fmt['session_code']][] = $maItem;
                            $assignmentsByDate[$dKey]['all'][] = $maItem;
                        }
                        ksort($assignmentsByDate);

                        $renderAssignmentActionButtons = static function (array $ma) use ($assignFilter): string {
                            ob_start();
                            $st = (string) ($ma['status'] ?? 'ASSIGNED');
                            ?>
                            <div class="flex flex-wrap items-center gap-2 pt-2.5 border-t border-slate-200/70 dark:border-slate-700/60">
                                <?php if ($st === 'ASSIGNED'): ?>
                                    <form method="post" action="?view=volunteer&activity_id=<?= (int)$ma['activity_id'] ?>&vtab=vtab-my-shifts&assign_filter=<?= h($assignFilter) ?>&action=volunteer_confirm_assignment" class="inline">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $ma['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary"><?= icon('check-circle', 13) ?> Xác nhận tham gia</button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline btn-open-absence text-rose-600" data-assignment-id="<?= (int) $ma['id'] ?>">
                                        <?= icon('alert-triangle', 13) ?> Báo vắng / Xin đổi ca
                                    </button>
                                    <span class="text-[11px] text-slate-400 italic ml-auto">Xác nhận tham gia để mở điểm danh QR khi vào ca</span>
                                <?php elseif ($st === 'CONFIRMED'): ?>
                                    <button type="button" class="btn btn-sm btn-success btn-sim-qr-scan" data-assignment-id="<?= (int) $ma['id'] ?>" data-scan-mode="VALID">
                                        <?= icon('qr-code', 13) ?> Quét QR Điểm danh
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline btn-open-absence text-rose-600" data-assignment-id="<?= (int) $ma['id'] ?>">
                                        <?= icon('alert-triangle', 13) ?> Báo vắng đột xuất
                                    </button>
                                <?php elseif ($st === 'CHECKED_IN'): ?>
                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 dark:text-emerald-400">
                                        <span class="material-symbols-outlined text-[15px]">verified</span>
                                        <span>Đã hoàn thành điểm danh</span>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-primary btn-open-eval-shift flex items-center gap-1 ml-auto"
                                            data-assignment-id="<?= (int) $ma['id'] ?>"
                                            data-shift-id="<?= (int) $ma['shift_id'] ?>"
                                            data-shift-title="<?= h((string) $ma['title']) ?>"
                                            data-open-modal="modal-eval-shift">
                                        <span class="material-symbols-outlined text-[14px]">rate_review</span>
                                        <span>Đánh giá ca trực</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php
                            return (string) ob_get_clean();
                        };
                    ?>
                        <!-- CHẾ ĐỘ 1: LỊCH BIỂU THỜI KHÓA BIỂU THEO NGÀY & BUỔI (SÁNG / CHIỀU / TỐI) -->
                        <div id="my-shifts-schedule-view" class="space-y-4">
                            <?php foreach ($assignmentsByDate as $dKey => $dayBucket): ?>
                                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden bg-slate-50/40 dark:bg-slate-900/30">
                                    <!-- Header Ngày -->
                                    <div class="px-4 py-3 bg-slate-100/80 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <span class="w-8 h-8 rounded-xl bg-[#0057B8] text-white flex items-center justify-center font-extrabold text-xs shadow-xs">
                                                <?= h(substr($dayBucket['short_date'], 0, 5)) ?>
                                            </span>
                                            <div>
                                                <h3 class="font-extrabold text-sm text-slate-900 dark:text-white"><?= h($dayBucket['date_label']) ?></h3>
                                                <p class="text-[11px] text-slate-500">Tổng cộng <?= count($dayBucket['all']) ?> ca trực trong ngày</p>
                                            </div>
                                        </div>
                                        <span class="badge badge-info mono text-[11px]"><?= count($dayBucket['all']) ?> ca trực</span>
                                    </div>

                                    <!-- Lưới 3 Buổi: Sáng / Chiều / Tối -->
                                    <div class="grid grid-cols-1 lg:grid-cols-3 divide-y lg:divide-y-0 lg:divide-x divide-slate-200 dark:divide-slate-800">
                                        <?php
                                        $sessionMetaCols = [
                                            'MORNING' => ['label' => 'Buổi Sáng (06:00 – 12:00)', 'icon' => 'wb_sunny', 'color' => 'text-amber-600 dark:text-amber-400'],
                                            'AFTERNOON' => ['label' => 'Buổi Chiều (12:00 – 17:00)', 'icon' => 'wb_twilight', 'color' => 'text-sky-600 dark:text-sky-400'],
                                            'EVENING' => ['label' => 'Buổi Tối (17:00 – 22:30)', 'icon' => 'dark_mode', 'color' => 'text-indigo-600 dark:text-indigo-400'],
                                        ];
                                        foreach ($sessionMetaCols as $sCode => $sMeta):
                                            $slotShifts = $dayBucket['sessions'][$sCode] ?? [];
                                        ?>
                                            <div class="p-3.5 flex flex-col gap-3">
                                                <div class="flex items-center justify-between">
                                                    <span class="inline-flex items-center gap-1.5 text-xs font-extrabold <?= $sMeta['color'] ?>">
                                                        <span class="material-symbols-outlined text-[16px]"><?= $sMeta['icon'] ?></span>
                                                        <span><?= h($sMeta['label']) ?></span>
                                                    </span>
                                                    <span class="mono text-[10px] text-slate-400"><?= count($slotShifts) ?> ca</span>
                                                </div>

                                                <?php if (empty($slotShifts)): ?>
                                                    <div class="flex-1 min-h-[84px] rounded-xl border border-dashed border-slate-200 dark:border-slate-800 flex items-center justify-center text-[11px] text-slate-400 italic p-3">
                                                        Trống lịch buổi này
                                                    </div>
                                                <?php else: ?>
                                                    <?php foreach ($slotShifts as $ma):
                                                        $fmt = $ma['_fmt'];
                                                    ?>
                                                        <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#131822] p-3.5 shadow-xs hover:border-[#0057B8] transition-all flex flex-col justify-between gap-2.5">
                                                            <div>
                                                                <div class="flex items-center justify-between gap-1.5 mb-1.5">
                                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400 border border-blue-200 dark:border-sky-800 truncate">
                                                                        <span class="material-symbols-outlined text-[11px]">flag</span>
                                                                        <span class="truncate"><?= h($ma['_clean_activity_title']) ?></span>
                                                                    </span>
                                                                    <?php if ($ma['status'] === 'CHECKED_IN'): ?>
                                                                        <span class="badge badge-valid mono text-[10px] shrink-0">✓ Đã điểm danh</span>
                                                                    <?php elseif ($ma['status'] === 'CONFIRMED'): ?>
                                                                        <span class="badge badge-info mono text-[10px] shrink-0">✓ Đã xác nhận</span>
                                                                    <?php else: ?>
                                                                        <span class="badge badge-warning mono text-[10px] shrink-0">Chờ xác nhận</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <a href="?view=shift_detail&activity_id=<?= (int)$ma['activity_id'] ?>&shift_id=<?= (int)$ma['shift_id'] ?>" class="font-extrabold text-slate-900 dark:text-white text-xs hover:text-[#0057B8] transition-colors block mb-1.5">
                                                                    [<?= h((string) $ma['code']) ?>] <?= h((string) $ma['title']) ?>
                                                                </a>
                                                                <div class="text-[11px] text-slate-500 dark:text-slate-400 space-y-1">
                                                                    <div class="flex items-center gap-1.5 font-semibold text-slate-700 dark:text-slate-200">
                                                                        <?= icon('clock', 12) ?>
                                                                        <span class="mono"><?= h($fmt['time_range']) ?></span>
                                                                        <span class="text-slate-400">&middot; <?= h($fmt['hours_label']) ?></span>
                                                                    </div>
                                                                    <div class="flex items-center gap-1.5">
                                                                        <?= icon('map-pin', 12) ?>
                                                                        <span class="truncate"><?= h((string) $ma['location_name']) ?></span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <?= $renderAssignmentActionButtons($ma) ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- CHẾ ĐỘ 2: DẠNG THẺ CHI TIẾT (CARD GRID) -->
                        <div id="my-shifts-cards-view" class="hidden grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($myAssignments as $ma):
                                $fmt = formatShiftTimeRangeVn((string) $ma['start_time'], (string) $ma['end_time']);
                                $cleanActTitle = cleanCampaignTitle((string) $ma['activity_title']);
                            ?>
                                <div class="border border-slate-200 dark:border-slate-800 rounded-xl p-5 bg-slate-50/70 dark:bg-slate-800/40 hover:border-[#0057B8] transition-colors flex flex-col justify-between">
                                    <div>
                                        <div class="flex justify-between items-start mb-2 gap-2">
                                            <div>
                                                <div class="mb-1 flex flex-wrap items-center gap-1.5">
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400 border border-blue-200 dark:border-sky-800">
                                                        <span class="material-symbols-outlined text-[12px]">flag</span>
                                                        <span><?= h($cleanActTitle) ?></span>
                                                    </span>
                                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $fmt['session_badge'] ?>">
                                                        <?= h($fmt['session_label']) ?>
                                                    </span>
                                                </div>
                                                <a href="?view=shift_detail&activity_id=<?= (int)$ma['activity_id'] ?>&shift_id=<?= (int)$ma['shift_id'] ?>" class="font-extrabold text-slate-900 dark:text-white text-sm hover:text-[#0057B8] transition-colors block">
                                                    [<?= h((string) $ma['code']) ?>] <?= h((string) $ma['title']) ?>
                                                </a>
                                            </div>
                                            <?php if ($ma['status'] === 'CHECKED_IN'): ?>
                                                <span class="badge badge-valid mono text-xs shrink-0">✓ Đã điểm danh</span>
                                            <?php elseif ($ma['status'] === 'CONFIRMED'): ?>
                                                <span class="badge badge-info mono text-xs shrink-0">✓ Đã xác nhận</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning mono text-xs shrink-0">Chờ xác nhận</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400 mb-3 space-y-1">
                                            <div class="flex items-center gap-1.5 font-semibold text-slate-700 dark:text-slate-200">
                                                <?= icon('clock', 13) ?>
                                                <span><?= h($fmt['full_label']) ?></span>
                                            </div>
                                            <div class="flex items-center gap-1.5"><?= icon('map-pin', 13) ?> <span><?= h((string) $ma['location_name']) ?></span></div>
                                        </div>
                                    </div>
                                    <?= $renderAssignmentActionButtons($ma) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- BỘ SƯU TẬP HUY HIỆU SỐ ĐOÀN THANH NIÊN & ĐIỂM CỐNG HIẾN -->
                <section class="rounded-2xl p-6 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 shadow-sm" id="volunteer-gamification-section">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-5 pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 flex items-center justify-center text-amber-600 dark:text-amber-400">
                                <span class="material-symbols-outlined text-[22px]">military_tech</span>
                            </div>
                            <div>
                                <h2 class="text-base font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>Bộ Sưu Tập Huy Hiệu Số Đoàn Thanh Niên &amp; Điểm Cống Hiến</span>
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Ghi nhận cống hiến thực tế, điểm uy tín và xếp loại thi đua</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="px-3.5 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 flex items-center gap-2">
                                <span class="text-xs text-slate-500">Điểm Cống Hiến:</span>
                                <span class="font-extrabold text-sm text-amber-600 dark:text-amber-400 mono"><?= number_format($volMerit['xp'], 1) ?> XP</span>
                            </div>
                            <div class="px-3.5 py-1.5 rounded-xl bg-blue-50 dark:bg-sky-950/50 border border-blue-200 dark:border-sky-800 flex items-center gap-2">
                                <span class="text-xs text-slate-500">Điểm Uy Tín:</span>
                                <span class="font-extrabold text-sm text-[#0057B8] dark:text-sky-400 mono"><?= number_format($volMerit['reputation_score'], 2) ?> / 5.0★</span>
                            </div>
                            <a href="?view=leaderboard" class="btn btn-sm btn-outline text-amber-600 dark:text-amber-400 flex items-center gap-1">
                                <span class="material-symbols-outlined text-[15px]">trophy</span>
                                <span>Xem Bảng Vàng</span>
                            </a>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
                        <?php foreach ($volMerit['badges'] as $b): ?>
                            <div class="rounded-xl p-3.5 border transition-all flex items-start gap-3 <?= !empty($b['is_unlocked']) ? 'bg-gradient-to-br from-amber-50/60 to-white dark:from-amber-950/20 dark:to-slate-900/60 border-amber-300 dark:border-amber-800 shadow-xs' : 'bg-slate-50/50 dark:bg-slate-800/30 border-slate-200 dark:border-slate-800 opacity-60' ?>">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 <?= !empty($b['is_unlocked']) ? 'bg-amber-500 text-white shadow-sm shadow-amber-500/30' : 'bg-slate-200 dark:bg-slate-700 text-slate-400' ?>">
                                    <span class="material-symbols-outlined text-[20px]"><?= h($b['icon']) ?></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-1 mb-1">
                                        <h3 class="text-xs font-bold text-slate-900 dark:text-white truncate"><?= h($b['name']) ?></h3>
                                        <?php if (!empty($b['is_unlocked'])): ?>
                                            <span class="inline-flex items-center gap-0.5 text-[10px] font-bold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/60 px-1.5 py-0.5 rounded-full border border-emerald-200 dark:border-emerald-800">
                                                <span class="material-symbols-outlined text-[11px]">check_circle</span> Đã đạt
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-0.5 text-[10px] font-bold text-slate-400 bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded-full">
                                                <span class="material-symbols-outlined text-[11px]">lock</span> Chưa mở
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-tight mb-1.5"><?= h($b['description']) ?></p>
                                    <?php if (!empty($b['is_unlocked']) && !empty($b['unlocked_at'])): ?>
                                        <div class="text-[10px] mono text-amber-600 dark:text-amber-400 font-semibold">
                                            Nhận ngày: <?= date('d/m/Y', strtotime((string)$b['unlocked_at'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-[10px] text-slate-400 italic">
                                            Tiêu chí: Cống hiến &amp; hoàn thành nhiệm vụ
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <!-- TAB 2: ĐĂNG KÝ NGUYỆN VỌNG CA TRỰC (CÓ LỊCH BIỂU THEO NGÀY & BỘ LỌC TƯƠNG THÍCH) -->
            <div id="vtab-register" class="workspace-tab-pane <?= $activeVTab === 'vtab-register' ? 'active' : '' ?>">
                <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                    <div class="flex flex-wrap justify-between items-center gap-4 mb-4 pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div>
                            <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                                <span class="material-symbols-outlined text-[#0057B8]">calendar_add_on</span>
                                <span>Đăng ký Nguyện vọng Ca trực — <?= h($activeCampaign['short_title']) ?></span>
                            </h2>
                            <p class="text-xs text-slate-500 mt-0.5">Xem lịch các ca trực theo Ngày &amp; Buổi hoặc lọc nhanh những ca khớp với kỹ năng và lịch rảnh của bạn</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="inline-flex items-center bg-slate-100 dark:bg-slate-800 p-1 rounded-xl border border-slate-200 dark:border-slate-700">
                                <button type="button" data-reg-view="schedule" class="btn-reg-view active inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-white dark:bg-slate-900 text-[#0057B8] dark:text-sky-400 shadow-xs transition-all cursor-pointer">
                                    <span class="material-symbols-outlined text-[15px]">calendar_view_day</span>
                                    <span>Lịch theo Ngày</span>
                                </button>
                                <button type="button" data-reg-view="table" class="btn-reg-view inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-slate-900 transition-all cursor-pointer">
                                    <span class="material-symbols-outlined text-[15px]">table_rows</span>
                                    <span>Dạng Bảng (<?= count($ds->shifts) ?>)</span>
                                </button>
                            </div>
                            <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-register&action=volunteer_quick_register_all">
                                <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                                <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <?= icon('sparkles', 13) ?> Đăng ký nhanh mọi ca phù hợp lịch rảnh
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Thanh Tìm kiếm & Bộ lọc Nhanh Ca trực -->
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 mb-5 bg-slate-50 dark:bg-slate-800/40 p-3.5 rounded-xl border border-slate-200 dark:border-slate-800">
                        <div class="md:col-span-5 relative">
                            <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-[17px]">search</span>
                            <input type="search" id="vol-reg-search" placeholder="Tìm mã ca, tên ca, địa điểm hoặc kỹ năng..." class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl pl-9 pr-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                        </div>
                        <div class="md:col-span-4 flex flex-wrap items-center gap-1.5">
                            <button type="button" data-reg-session="ALL" class="btn-reg-session active px-2.5 py-1.5 rounded-lg text-xs font-bold bg-[#0057B8] text-white cursor-pointer">Tất cả buổi</button>
                            <button type="button" data-reg-session="MORNING" class="btn-reg-session px-2.5 py-1.5 rounded-lg text-xs font-bold bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 cursor-pointer">Buổi Sáng</button>
                            <button type="button" data-reg-session="AFTERNOON" class="btn-reg-session px-2.5 py-1.5 rounded-lg text-xs font-bold bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 cursor-pointer">Buổi Chiều</button>
                            <button type="button" data-reg-session="EVENING" class="btn-reg-session px-2.5 py-1.5 rounded-lg text-xs font-bold bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 cursor-pointer">Buổi Tối</button>
                        </div>
                        <div class="md:col-span-3">
                            <select id="vol-reg-compat" class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-bold text-slate-700 dark:text-slate-200">
                                <option value="ALL">Tất cả ca trực (<?= count($ds->shifts) ?>)</option>
                                <option value="MATCH">✓ Đủ kỹ năng &amp; Khớp lịch rảnh</option>
                                <option value="REGISTERED">★ Ca tôi đã đăng ký (<?= count($regShiftIds) ?>)</option>
                            </select>
                        </div>
                    </div>

                    <?php
                    // Gom nhóm các ca của chiến dịch theo Ngày để hiển thị Lịch Đăng ký trực quan
                    $regShiftsByDate = [];
                    foreach ($ds->shifts as $sObj) {
                        $sFmt = formatShiftTimeRangeVn($sObj->startEpoch, $sObj->endEpoch);
                        $dKey = $sFmt['date_key'];
                        if (!isset($regShiftsByDate[$dKey])) {
                            $regShiftsByDate[$dKey] = [
                                'date_label' => $sFmt['date_label'],
                                'short_date' => $sFmt['short_date'],
                                'items' => [],
                            ];
                        }
                        $regShiftsByDate[$dKey]['items'][] = ['shift' => $sObj, 'fmt' => $sFmt];
                    }
                    ksort($regShiftsByDate);
                    ?>

                    <!-- CHẾ ĐỘ 1: LỊCH ĐĂNG KÝ THEO NGÀY & BUỔI -->
                    <div id="vol-reg-schedule-view" class="space-y-4">
                        <?php foreach ($regShiftsByDate as $dKey => $dayGroup): ?>
                            <div class="vol-reg-day-group rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                                <div class="px-4 py-2.5 bg-slate-100/80 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400 text-[18px]">event</span>
                                        <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white"><?= h($dayGroup['date_label']) ?></span>
                                    </div>
                                    <span class="badge badge-subtle mono text-[10px]"><?= count($dayGroup['items']) ?> ca</span>
                                </div>
                                <div class="p-3.5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <?php foreach ($dayGroup['items'] as $entry):
                                        $s = $entry['shift'];
                                        $sFmt = $entry['fmt'];
                                        $req = $s->requirements[0] ?? null;
                                        $skLabel = $req !== null ? ($skillNameVi[$req->skillCode] ?? $req->skillCode) : 'Tình nguyện chung';
                                        $hasSkill = $req === null || $vol->satisfiesRequirement($req);
                                        $isAvail = $vol->isAvailableForShift($s);
                                        $myPref = $regShiftIds[$s->id] ?? null;
                                        $compatTag = ($hasSkill && $isAvail) ? 'MATCH' : 'OTHER';
                                        $regTag = ($myPref !== null) ? 'REGISTERED' : 'UNREGISTERED';
                                        $searchBlob = mb_strtolower($s->code . ' ' . $s->title . ' ' . $s->locationName . ' ' . $skLabel);
                                    ?>
                                        <div
                                            class="vol-reg-item rounded-xl border <?= $myPref !== null ? 'border-emerald-300 dark:border-emerald-700 bg-emerald-50/20 dark:bg-emerald-950/15' : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-[#131822]' ?> p-3.5 flex flex-col justify-between gap-3 hover:border-[#0057B8] transition-all"
                                            data-reg-search="<?= h($searchBlob) ?>"
                                            data-reg-session="<?= h($sFmt['session_code']) ?>"
                                            data-reg-compat="<?= h($compatTag) ?>"
                                            data-reg-registered="<?= h($regTag) ?>"
                                        >
                                            <div>
                                                <div class="flex items-center justify-between gap-1.5 mb-1.5">
                                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $sFmt['session_badge'] ?>">
                                                        <?= h($sFmt['session_label']) ?> &middot; <?= h($sFmt['time_range']) ?>
                                                    </span>
                                                    <?php if ($hasSkill && $isAvail): ?>
                                                        <span class="badge badge-valid text-[10px]">✓ Phù hợp</span>
                                                    <?php elseif (!$hasSkill): ?>
                                                        <span class="badge badge-violation text-[10px]">Thiếu kỹ năng</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning text-[10px]">Lệch giờ rảnh</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="font-extrabold text-xs text-slate-900 dark:text-white mb-1">[<?= h($s->code) ?>] <?= h($s->title) ?></div>
                                                <div class="text-[11px] text-slate-500 space-y-0.5">
                                                    <div class="flex items-center gap-1"><?= icon('map-pin', 12) ?> <span class="truncate"><?= h($s->locationName) ?></span></div>
                                                    <?php if ($req !== null): ?>
                                                        <div class="flex items-center gap-1 text-slate-600 dark:text-slate-300 font-semibold">
                                                            <span class="material-symbols-outlined text-[13px] text-[#0057B8]">verified</span>
                                                            <span><?= h($skLabel) ?> &ge; Cấp <?= $req->minLevel ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="flex flex-wrap items-center justify-between gap-1.5 pt-2.5 border-t border-slate-100 dark:border-slate-800">
                                                <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-register&action=volunteer_register_shift" class="flex items-center gap-1.5 flex-1">
                                                    <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                                                    <input type="hidden" name="shift_id" value="<?= $s->id ?>">
                                                    <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                                                    <select name="preference_rank" class="bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-1 text-xs font-semibold">
                                                        <option value="1" <?= $myPref === 1 ? 'selected' : '' ?>>NV 1</option>
                                                        <option value="2" <?= $myPref === 2 ? 'selected' : '' ?>>NV 2</option>
                                                        <option value="3" <?= $myPref === 3 ? 'selected' : '' ?>>NV 3</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-sm <?= $myPref !== null ? 'btn-success' : 'btn-primary' ?> py-1 px-2.5 text-xs">
                                                        <?= $myPref !== null ? '✓ Đã đăng ký' : 'Đăng ký' ?>
                                                    </button>
                                                </form>
                                                <?php if ($myPref !== null): ?>
                                                    <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-register&action=volunteer_withdraw_registration" class="inline">
                                                        <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                                                        <input type="hidden" name="shift_id" value="<?= $s->id ?>">
                                                        <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline text-rose-600 py-1 px-2 text-xs" title="Rút đăng ký khỏi ca trực này">
                                                            Rút
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- CHẾ ĐỘ 2: BẢNG DANH SÁCH CA TRỰC -->
                    <div id="vol-reg-table-view" class="hidden overflow-x-auto max-h-[620px] overflow-y-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead class="sticky top-0 bg-white dark:bg-[#131822] z-10">
                                <tr class="border-b border-slate-200 dark:border-slate-800 text-xs uppercase text-slate-500">
                                    <th class="py-3 px-3">Mã &amp; Tên ca trực</th>
                                    <th class="py-3 px-3 whitespace-nowrap">Ngày &amp; Giờ trực</th>
                                    <th class="py-3 px-3 whitespace-nowrap">Kỹ năng yêu cầu</th>
                                    <th class="py-3 px-3 whitespace-nowrap">Đánh giá Tương thích</th>
                                    <th class="py-3 px-3 whitespace-nowrap">Nguyện vọng</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php foreach ($ds->shifts as $s):
                                    $sFmt = formatShiftTimeRangeVn($s->startEpoch, $s->endEpoch);
                                    $req = $s->requirements[0] ?? null;
                                    $skLabel = $req !== null ? ($skillNameVi[$req->skillCode] ?? $req->skillCode) : 'Tình nguyện chung';
                                    $hasSkill = $req === null || $vol->satisfiesRequirement($req);
                                    $isAvail = $vol->isAvailableForShift($s);
                                    $myPref = $regShiftIds[$s->id] ?? null;
                                    $compatTag = ($hasSkill && $isAvail) ? 'MATCH' : 'OTHER';
                                    $regTag = ($myPref !== null) ? 'REGISTERED' : 'UNREGISTERED';
                                    $searchBlob = mb_strtolower($s->code . ' ' . $s->title . ' ' . $s->locationName . ' ' . $skLabel);
                                ?>
                                    <tr
                                        class="vol-reg-item hover:bg-slate-50 dark:hover:bg-slate-800/40"
                                        data-reg-search="<?= h($searchBlob) ?>"
                                        data-reg-session="<?= h($sFmt['session_code']) ?>"
                                        data-reg-compat="<?= h($compatTag) ?>"
                                        data-reg-registered="<?= h($regTag) ?>"
                                    >
                                        <td class="py-3 px-3 font-bold max-w-[280px] leading-snug">
                                            <div>[<?= h($s->code) ?>] <?= h($s->title) ?></div>
                                            <div class="text-[11px] font-normal text-slate-400"><?= h($s->locationName) ?></div>
                                        </td>
                                        <td class="py-3 px-3 whitespace-nowrap">
                                            <div class="font-bold text-xs text-slate-800 dark:text-slate-200"><?= h($sFmt['date_label']) ?></div>
                                            <div class="mono text-xs text-slate-500"><?= h($sFmt['session_label']) ?> &middot; <?= h($sFmt['time_range']) ?></div>
                                        </td>
                                        <td class="py-3 px-3 whitespace-nowrap">
                                            <?php if ($req !== null): ?>
                                                <span class="badge badge-subtle"><?= h($skLabel) ?> &ge; Cấp <?= $req->minLevel ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 whitespace-nowrap">
                                            <?php if ($hasSkill && $isAvail): ?>
                                                <span class="badge badge-valid">Đủ kỹ năng &amp; Rảnh</span>
                                            <?php elseif (!$hasSkill): ?>
                                                <span class="badge badge-violation">Chưa có kỹ năng này</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Lệch khung giờ rảnh</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 whitespace-nowrap">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-register&action=volunteer_register_shift" class="flex items-center gap-2">
                                                    <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                                                    <input type="hidden" name="shift_id" value="<?= $s->id ?>">
                                                    <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                                                    <select name="preference_rank" class="bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5 text-xs">
                                                        <option value="1" <?= $myPref === 1 ? 'selected' : '' ?>>Nguyện vọng 1</option>
                                                        <option value="2" <?= $myPref === 2 ? 'selected' : '' ?>>Nguyện vọng 2</option>
                                                        <option value="3" <?= $myPref === 3 ? 'selected' : '' ?>>Nguyện vọng 3</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-sm <?= $myPref !== null ? 'btn-success' : 'btn-outline' ?>">
                                                        <?= $myPref !== null ? 'Đã đăng ký' : 'Đăng ký' ?>
                                                    </button>
                                                </form>
                                                <?php if ($myPref !== null): ?>
                                                    <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-register&action=volunteer_withdraw_registration" class="inline">
                                                        <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                                                        <input type="hidden" name="shift_id" value="<?= $s->id ?>">
                                                        <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline text-rose-600 border-rose-200 dark:border-rose-800 hover:bg-rose-50 dark:hover:bg-rose-950/40" title="Rút đăng ký khỏi ca trực này">
                                                            Rút đăng ký
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: KỸ NĂNG & LỊCH RẢNH -->
            <div id="vtab-profile" class="workspace-tab-pane <?= $activeVTab === 'vtab-profile' ? 'active' : '' ?>">
                <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                                <span class="material-symbols-outlined text-[#0057B8]">schedule</span>
                                <span>1. Bảng Chọn Nhanh Lịch Rảnh 7 Ngày &amp; Chọn Ngày Cụ Thể</span>
                            </h2>
                            <p class="text-xs text-slate-500 mt-0.5">Bấm trực tiếp vào các ô Sáng / Chiều / Tối để bật/tắt ngày rảnh, hoặc chọn Ngày cụ thể trên lịch bên dưới rồi bấm Lưu.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" id="btn-avail-preset-weekend" class="btn btn-outline btn-xs">Chọn Cuối tuần (T7 &amp; CN)</button>
                            <button type="button" id="btn-avail-preset-all" class="btn btn-outline btn-xs">Chọn Tất cả 7 ngày</button>
                            <button type="button" id="btn-avail-preset-clear" class="btn btn-outline btn-xs text-rose-600">Bỏ chọn hết</button>
                        </div>
                    </div>

                    <!-- Ma trận Lịch rảnh 7 Ngày Tương tác (Interactive 7-Day Matrix Form) -->
                    <?php
                    $availPeriods = [
                        'MORNING'   => ['label' => 'Sáng (07:00–11:30)',  'range' => [420, 690]],
                        'AFTERNOON' => ['label' => 'Chiều (13:00–17:30)', 'range' => [780, 1050]],
                        'EVENING'   => ['label' => 'Tối (18:00–21:30)',   'range' => [1080, 1290]],
                    ];
                    ?>
                    <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-profile&action=volunteer_save_availability" id="form-avail-matrix" class="mb-6">
                        <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                        <input type="hidden" name="avail_mode" value="matrix">
                        <div class="matrix-scroll-container overflow-x-auto no-scrollbar mb-3 rounded-xl border border-slate-200 dark:border-slate-800">
                            <table class="w-full min-w-[680px] text-center text-xs divide-y divide-slate-200 dark:divide-slate-800">
                                <thead class="bg-slate-50 dark:bg-slate-800/60 font-bold text-slate-700 dark:text-slate-300">
                                    <tr>
                                        <th class="py-2.5 px-3 text-left whitespace-nowrap">Buổi / Khung giờ</th>
                                        <?php for ($d = 1; $d <= 7; $d++): ?>
                                            <th class="py-2.5 px-2.5 whitespace-nowrap <?= $d === 6 ? 'text-blue-600 dark:text-sky-400' : ($d === 7 ? 'text-rose-600 dark:text-rose-400' : '') ?>">
                                                <?= h($dayNameVi[$d] ?? ('Thứ ' . $d)) ?>
                                            </th>
                                        <?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 bg-white dark:bg-[#131822]">
                                    <?php foreach ($availPeriods as $pKey => $pMeta):
                                        [$pStart, $pEnd] = $pMeta['range'];
                                    ?>
                                        <tr>
                                            <td class="py-2.5 px-3 font-semibold text-left text-slate-600 dark:text-slate-300 whitespace-nowrap"><?= h($pMeta['label']) ?></td>
                                            <?php for ($d = 1; $d <= 7; $d++):
                                                $isFree = false;
                                                foreach ($vol->availabilitySlots as $slot) {
                                                    if ($slot->overrideDate === null && $slot->isAvailable && $slot->dayOfWeek === $d && max($slot->startMinuteOfDay, $pStart) < min($slot->endMinuteOfDay, $pEnd)) {
                                                        $isFree = true;
                                                        break;
                                                    }
                                                }
                                                $slotVal = $d . '_' . $pKey;
                                            ?>
                                                <td class="py-2 px-1.5 whitespace-nowrap">
                                                    <label class="avail-matrix-cell inline-flex items-center justify-center w-full py-1.5 px-2 rounded-lg border cursor-pointer select-none transition-all <?= $isFree ? 'bg-emerald-50 dark:bg-emerald-950/50 border-emerald-400 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 font-bold shadow-xs' : 'bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 text-slate-400 hover:border-slate-300' ?>">
                                                        <input type="checkbox" name="matrix_slots[]" value="<?= h($slotVal) ?>" data-day="<?= $d ?>" data-period="<?= h($pKey) ?>" class="sr-only avail-matrix-checkbox" <?= $isFree ? 'checked' : '' ?>>
                                                        <span class="avail-cell-text text-[11px]"><?= $isFree ? '✓ Rảnh' : '— Bận' ?></span>
                                                    </label>
                                                </td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-xs text-slate-500">Mẹo: Tích chọn các buổi bạn rảnh trong tuần rồi bấm nút Lưu bên phải.</span>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <?= icon('check', 13) ?> Lưu Bảng Lịch rảnh 7 Ngày
                            </button>
                        </div>
                    </form>

                    <!-- Chọn Ngày Cụ thể trên Lịch (Date Picker dd/mm/yyyy) hoặc Theo Thứ & Giờ tùy chỉnh -->
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/80 mb-6">
                        <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px] text-[#0057B8]">event</span>
                            <span>Chọn Ngày Cụ Thể trên Lịch (Date Picker) hoặc Khung Giờ Tùy Chỉnh</span>
                        </h3>
                        <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-profile&action=volunteer_save_availability" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
                            <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                            <input type="hidden" name="avail_mode" value="custom">
                            <div>
                                <label class="text-xs font-bold block mb-1">Chọn Ngày cụ thể (Lịch)</label>
                                <input type="date" id="avail-date-input" name="override_date" value="2026-06-14" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono" title="Chọn ngày cụ thể bạn rảnh hoặc để trống nếu chỉ lưu theo Thứ định kỳ" />
                            </div>
                            <div>
                                <label class="text-xs font-bold block mb-1">Hoặc Thứ định kỳ</label>
                                <select id="avail-day-select" name="day_of_week" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs">
                                    <option value="6">Thứ Bảy</option>
                                    <option value="7">Chủ Nhật</option>
                                    <option value="1">Thứ Hai</option>
                                    <option value="2">Thứ Ba</option>
                                    <option value="3">Thứ Tư</option>
                                    <option value="4">Thứ Năm</option>
                                    <option value="5">Thứ Sáu</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-bold block mb-1">Từ giờ</label>
                                <input type="time" id="avail-start-input" name="start_hm" value="07:00" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono" />
                            </div>
                            <div>
                                <label class="text-xs font-bold block mb-1">Đến giờ</label>
                                <input type="time" id="avail-end-input" name="end_hm" value="18:00" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono" />
                            </div>
                            <div>
                                <label class="text-xs font-bold block mb-1">Trạng thái</label>
                                <select id="avail-status-select" name="is_available" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-semibold">
                                    <option value="1">✓ Rảnh ngày/giờ này</option>
                                    <option value="0">✕ Báo bận ngày này</option>
                                </select>
                            </div>
                            <div>
                                <button type="submit" id="btn-save-availability" data-volunteer-id="<?= $vol->id ?>" class="btn btn-primary w-full text-xs py-2.5">
                                    + Lưu Ngày / Giờ
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pb-2 mb-6">
                        <span class="text-xs font-bold text-slate-500 whitespace-nowrap shrink-0">Các ngày cụ thể &amp; khung lịch rảnh đã lưu:</span>
                        <?php foreach ($vol->availabilitySlots as $slot):
                            $stHm = sprintf('%02d:%02d', intdiv($slot->startMinuteOfDay, 60), $slot->startMinuteOfDay % 60);
                            $etHm = sprintf('%02d:%02d', intdiv($slot->endMinuteOfDay, 60), $slot->endMinuteOfDay % 60);
                            $isOverride = ($slot->overrideDate !== null && $slot->overrideDate !== '');
                            $dLabel = $isOverride
                                ? ('Ngày ' . date('d/m/Y', strtotime($slot->overrideDate)) . ($slot->isAvailable ? ' (Rảnh)' : ' (Bận)'))
                                : ($dayNameVi[$slot->dayOfWeek] ?? ('Thứ ' . $slot->dayOfWeek));
                            $badgeClass = $slot->isAvailable ? ($isOverride ? 'badge-valid' : 'badge-info') : 'badge-violation';
                        ?>
                            <span class="badge <?= $badgeClass ?> mono py-1.5 px-3 whitespace-nowrap shrink-0 inline-flex items-center gap-1.5">
                                <span><?= h($dLabel) ?>: <?= h($stHm) ?>–<?= h($etHm) ?></span>
                                <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-profile&action=volunteer_delete_availability" class="inline">
                                    <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                                    <?php if ($isOverride): ?>
                                        <input type="hidden" name="override_date" value="<?= h($slot->overrideDate) ?>">
                                    <?php else: ?>
                                        <input type="hidden" name="day_of_week" value="<?= (int) $slot->dayOfWeek ?>">
                                        <input type="hidden" name="start_hm" value="<?= h($stHm) ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="text-rose-500 hover:text-rose-700 font-bold ml-1" title="Xóa khung lịch này">&times;</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <!-- Quản lý Kỹ năng Chuyên môn + Thêm Kỹ năng Khác trực tiếp tại Tab Kỹ năng & Lịch rảnh -->
                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                            <h3 class="text-base font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                                <span class="material-symbols-outlined text-emerald-600">verified</span>
                                <span>2. Cập nhật Hồ sơ Kỹ năng Chuyên môn &amp; Bổ sung Kỹ năng Khác</span>
                            </h3>
                            <a href="?view=profile" class="text-xs font-bold text-[#0057B8] dark:text-sky-400 hover:underline">Mở trang Hồ sơ đầy đủ &rarr;</a>
                        </div>
                        <form method="post" action="?view=volunteer&activity_id=<?= $selectedActivityId ?>&vtab=vtab-profile&action=volunteer_save_skills" class="space-y-4">
                            <input type="hidden" name="volunteer_id" value="<?= $vol->id ?>">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                                <?php foreach ($skillCodeById as $skId => $skCode):
                                    $currSkillLvl = isset($vol->skills[$skId]) ? (int) $vol->skills[$skId]['level'] : 0;
                                ?>
                                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                                        <label class="text-xs font-bold text-slate-800 dark:text-slate-200 block mb-1.5"><?= h($skillNameVi[$skCode] ?? $skCode) ?></label>
                                        <select name="skills[<?= (int) $skId ?>]" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5 text-xs font-semibold">
                                            <option value="0" <?= $currSkillLvl === 0 ? 'selected' : '' ?>>Chưa có kỹ năng</option>
                                            <option value="1" <?= $currSkillLvl === 1 ? 'selected' : '' ?>>Cấp 1 — Cơ bản</option>
                                            <option value="2" <?= $currSkillLvl === 2 ? 'selected' : '' ?>>Cấp 2 — Đã tập huấn</option>
                                            <option value="3" <?= $currSkillLvl === 3 ? 'selected' : '' ?>>Cấp 3 — Thành thạo</option>
                                            <option value="4" <?= $currSkillLvl === 4 ? 'selected' : '' ?>>Cấp 4 — Chuyên sâu</option>
                                            <option value="5" <?= $currSkillLvl === 5 ? 'selected' : '' ?>>Cấp 5 — Nòng cốt</option>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Khối "+ Thêm Kỹ năng Khác" -->
                            <div class="p-4 rounded-2xl bg-blue-50/50 dark:bg-sky-950/20 border border-blue-200 dark:border-sky-800/70 flex flex-col sm:flex-row items-stretch sm:items-end gap-3">
                                <div class="flex-1">
                                    <label class="text-xs font-extrabold text-[#0057B8] dark:text-sky-400 block mb-1">
                                        + Thêm Kỹ năng Khác (Ngoài các kỹ năng trên)
                                    </label>
                                    <input
                                        type="text"
                                        name="custom_skill_name"
                                        list="custom-skill-suggestions"
                                        placeholder="Nhập tên kỹ năng khác (VD: Ngoại ngữ & Phiên dịch, MC Sự kiện, Nấu ăn hậu cần, Thiết kế...)"
                                        class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3.5 py-2 text-xs"
                                    />
                                    <datalist id="custom-skill-suggestions">
                                        <option value="Ngoại ngữ &amp; Phiên dịch"></option>
                                        <option value="MC &amp; Hoạt náo Sự kiện"></option>
                                        <option value="Nấu ăn &amp; Bếp ấm Hậu cần"></option>
                                        <option value="Thiết kế Đồ họa &amp; Dựng phim"></option>
                                        <option value="Kỹ năng Cứu hộ &amp; Bơi lội"></option>
                                    </datalist>
                                </div>
                                <div class="w-full sm:w-48">
                                    <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">Cấp độ Kỹ năng Khác</label>
                                    <select name="custom_skill_level" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-semibold">
                                        <option value="1">Cấp 1 — Cơ bản</option>
                                        <option value="2">Cấp 2 — Đã tập huấn</option>
                                        <option value="3" selected>Cấp 3 — Thành thạo</option>
                                        <option value="4">Cấp 4 — Chuyên sâu</option>
                                        <option value="5">Cấp 5 — Nòng cốt</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm py-2.5 px-5 shrink-0">
                                    <?= icon('check', 13) ?> Lưu Kỹ năng Chuyên môn
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

<?php elseif ($view === 'coordinator'): ?>
    <!-- ======================================================================
         BÀN ĐIỀU PHỐI ĐOÀN CHUẨN KIẾN TRÚC TECHPRO (Admin.jsx)
         ====================================================================== -->
    <?php
    $ds = ($selectedActivityId === 2)
        ? DatasetGenerator::createD2UnderstaffedDataset(2)
        : $repo->loadDatasetForActivity($selectedActivityId);

    $planB0 = (new FifoUncheckedScheduler())->schedule($ds, 202606);
    $planB1 = (new FifoCheckedScheduler())->schedule($ds, 202606);

    $defaultMode = ($selectedActivityId === 1) ? SchedulerWeights::MODE_FAIRNESS : SchedulerWeights::MODE_MAX_COVERAGE;
    $weightsToUse = $customLabWeights ?? SchedulerWeights::fromMode($defaultMode, 202606);
    $planX = (new XanhHubScheduler())->schedule($ds, $weightsToUse);
    $aiExplanation = $aiService->explainPlan($ds, $planX, $planB0, $planB1);

    $stmtAssign = $pdo->prepare("
        SELECT a.id, a.shift_id, a.volunteer_id, a.requirement_id, a.status, a.override_reason,
               u.full_name, v.student_code, v.chapter_name
          FROM assignments a
          JOIN users u ON u.id = a.volunteer_id
          JOIN volunteers v ON v.user_id = a.volunteer_id
         WHERE a.activity_id = :aid
           AND a.status IN ('ASSIGNED', 'CONFIRMED', 'AT_RISK', 'CHECKED_IN')
         ORDER BY a.id ASC
    ");
    $stmtAssign->execute([':aid' => $selectedActivityId]);
    $activeRows = $stmtAssign->fetchAll();

    $assignedByShift = [];
    $assignedShiftsByVol = [];
    foreach ($activeRows as $r) {
        $sid = (int) $r['shift_id'];
        $vid = (int) $r['volunteer_id'];
        $assignedByShift[$sid][] = $r;
        $assignedShiftsByVol[$vid][] = $sid;
    }

    $loadX = [];
    foreach ($ds->volunteers as $vInit) {
        $loadX[$vInit->id] = 0;
    }
    foreach ($planX->decisions as $d) {
        $loadX[$d->volunteerId] = ($loadX[$d->volunteerId] ?? 0) + 1;
    }
    $unassignedSlots = array_values(array_filter(
        $planX->unassigned,
        static fn($u) => $u->itemType === 'SLOT'
    ));
    $unassignedVolunteers = array_values(array_filter(
        $planX->unassigned,
        static fn($u) => $u->itemType === 'VOLUNTEER'
    ));

    // Chuẩn bị dữ liệu JSON cho Master-Detail, DataGrid và Candidate Inspector Modal
    $gamificationSvc = new \XanhHub\Infrastructure\Service\GamificationService($pdo);
    $allVolUsers = [];
    $stmtUsers = $pdo->query('SELECT u.id, u.email, v.phone FROM users u JOIN volunteers v ON v.user_id = u.id');
    while ($urow = $stmtUsers->fetch()) {
        $allVolUsers[(int) $urow['id']] = $urow;
    }

    // Nạp dữ liệu báo vắng cho từng ca trực
    $stmtAbs = $pdo->prepare("
        SELECT ar.id, ar.assignment_id, ar.volunteer_id, ar.reason, ar.reported_at, ar.status,
               ar.replacement_assignment_id,
               a.shift_id, a.requirement_id,
               u.full_name AS absent_full_name, v.student_code AS absent_student_code,
               ru.full_name AS replacement_full_name, rv.student_code AS replacement_student_code
          FROM absence_reports ar
          JOIN assignments a ON a.id = ar.assignment_id
          JOIN users u ON u.id = ar.volunteer_id
          JOIN volunteers v ON v.user_id = ar.volunteer_id
          LEFT JOIN assignments ra ON ra.id = ar.replacement_assignment_id
          LEFT JOIN users ru ON ru.id = ra.volunteer_id
          LEFT JOIN volunteers rv ON rv.user_id = ra.volunteer_id
         WHERE a.activity_id = :actId
         ORDER BY ar.reported_at DESC
    ");
    $stmtAbs->execute([':actId' => $selectedActivityId]);
    $absencesByShift = [];
    while ($arRow = $stmtAbs->fetch()) {
        $absencesByShift[(int)$arRow['shift_id']][] = [
            'id' => (int) $arRow['id'],
            'assignment_id' => (int) $arRow['assignment_id'],
            'volunteer_id' => (int) $arRow['volunteer_id'],
            'absent_name' => (string) $arRow['absent_full_name'],
            'absent_code' => (string) $arRow['absent_student_code'],
            'reason' => (string) $arRow['reason'],
            'reported_at' => date('H:i d/m/Y', strtotime($arRow['reported_at'])),
            'status' => (string) $arRow['status'],
            'replacement_name' => $arRow['replacement_full_name'] ?: null,
            'replacement_code' => $arRow['replacement_student_code'] ?: null,
        ];
    }

    $replacementRanker = new \XanhHub\Domain\Scheduling\ReplacementRanker();

    // Nạp danh sách đăng ký nguyện vọng (registrations) cho từng ca trực để Điều phối viên duyệt
    $stmtRegs = $pdo->prepare("
        SELECT r.shift_id, r.volunteer_id, r.preference_rank
          FROM registrations r
         WHERE r.activity_id = :actId AND r.status = 'ACTIVE'
         ORDER BY r.preference_rank ASC, r.id ASC
    ");
    $stmtRegs->execute([':actId' => $selectedActivityId]);
    $regsByShift = [];
    while ($regRow = $stmtRegs->fetch()) {
        $regsByShift[(int)$regRow['shift_id']][(int)$regRow['volunteer_id']] = (int)$regRow['preference_rank'];
    }

    $jsShifts = [];
    foreach ($ds->shifts as $s) {
        $cList = $assignedByShift[$s->id] ?? [];
        $req = $s->requirements[0] ?? null;
        $skCode = $req?->skillCode ?? 'LOGISTICS';
        $skLabel = $skillNameVi[$skCode] ?? $skCode;
        $currCount = count($cList);
        $isFull = ($currCount >= $s->maxPeople);
        $isUnder = ($currCount < $s->minPeople);

        $jsAssignments = [];
        foreach ($cList as $item) {
            $vid = (int) $item['volunteer_id'];
            $vObj = $ds->volunteersById[$vid] ?? null;
            $vSkills = [];
            if ($vObj !== null) {
                foreach ($vObj->skills as $skId => $skEntry) {
                    $code = $skillCodeById[(int) $skId] ?? ('SKILL_' . $skId);
                    $vSkills[] = [
                        'code' => $code,
                        'name' => $skillNameVi[$code] ?? $code,
                        'level' => (int) $skEntry['level'],
                        'verified' => !empty($skEntry['is_verified']),
                    ];
                }
            }
            $jsAssignments[] = [
                'id' => (int) $item['id'],
                'volunteer_id' => $vid,
                'full_name' => (string) $item['full_name'],
                'student_code' => (string) $item['student_code'],
                'chapter_name' => (string) $item['chapter_name'],
                'avatar_url' => avatarUrlFor($vid, (string) $item['full_name']),
                'skills' => $vSkills,
            ];
        }

        $shiftReplacements = [];
        try {
            $shiftReplacements = $replacementRanker->rankCandidates(
                dataset: $ds,
                activeAssignments: $planX->decisions,
                shiftId: $s->id,
                requirementId: $req?->id,
                waitlistVolunteerIds: [],
                limit: 5
            );
            foreach ($shiftReplacements as &$rc) {
                $rc['avatar_url'] = avatarUrlFor($rc['volunteer_id'], $rc['full_name']);
            }
            unset($rc);
        } catch (\Throwable) {
        }

        $jsShifts[] = [
            'id' => $s->id,
            'code' => $s->code,
            'title' => $s->title,
            'start_epoch' => $s->startEpoch,
            'end_epoch' => $s->endEpoch,
            'time_fmt' => date('d/m H:i', $s->startEpoch) . '–' . date('H:i', $s->endEpoch),
            'location_name' => $s->locationName,
            'min_people' => $s->minPeople,
            'max_people' => $s->maxPeople,
            'priority' => $s->priority,
            'is_locked' => $s->isLocked,
            'req_skill_code' => $skCode,
            'req_skill_name' => $skLabel,
            'req_min_level' => (int) ($req?->minLevel ?? 1),
            'req_id' => $req?->id ?? '',
            'assigned_count' => $currCount,
            'status' => $s->isLocked ? 'LOCKED' : ($isFull ? 'FULL' : ($isUnder ? 'UNDERSTAFFED' : 'VALID')),
            'assignments' => $jsAssignments,
            'registrations' => $regsByShift[$s->id] ?? (object)[],
            'absence_reports' => $absencesByShift[$s->id] ?? [],
            'replacement_candidates' => $shiftReplacements,
        ];
    }

    $jsVolunteers = [];
    foreach ($ds->volunteers as $v) {
        $vSkills = [];
        foreach ($v->skills as $skId => $skEntry) {
            $code = $skillCodeById[(int) $skId] ?? ('SKILL_' . $skId);
            $vSkills[] = [
                'skill_id' => (int) $skId,
                'code' => $code,
                'name' => $skillNameVi[$code] ?? $code,
                'level' => (int) $skEntry['level'],
                'verified' => !empty($skEntry['is_verified']),
            ];
        }
        $assignedShifts = $assignedShiftsByVol[$v->id] ?? [];
        $uInfo = $allVolUsers[$v->id] ?? null;
        $merit = $gamificationSvc->calculateVolunteerMerit($v->id);

        $jsVolunteers[] = [
            'id' => $v->id,
            'full_name' => $v->fullName,
            'student_code' => $v->studentCode,
            'chapter_name' => $v->chapterName,
            'email' => (string) ($uInfo['email'] ?? ($v->studentCode . '@hcmut.edu.vn')),
            'phone' => (string) ($uInfo['phone'] ?? '0901234567'),
            'max_shifts' => $v->maxShiftsPerWeek,
            'avatar_url' => avatarUrlFor($v->id, $v->fullName),
            'skills' => $vSkills,
            'assigned_shift_ids' => $assignedShifts,
            'assigned_count' => count($assignedShifts),
            'hours' => $merit['total_hours'],
            'xp' => $merit['xp'],
            'reputation' => $merit['reputation_score'],
            'unlocked_badges_count' => $merit['unlocked_badge_count'],
            'badges' => $merit['badges'],
        ];
    }
    ?>
    <!-- Backdrop và Menu Mini Nổi 1 Lớp Toàn Màn Hình cho Ban Chỉ Huy (Full-Height Left Drawer - Không đẩy giao diện) -->
    <div id="workspace-drawer-backdrop" class="workspace-floating-backdrop no-print"></div>
    <button type="button" class="workspace-mini-fab btn-toggle-workspace-drawer no-print" title="Mở/Đóng Menu Ban Chỉ Huy">
        <span class="material-symbols-outlined text-[18px]">menu_open</span>
        <span>Menu BCH</span>
    </button>

    <aside id="workspace-floating-drawer" class="workspace-floating-drawer bg-white dark:bg-[#131822] border-r border-slate-200 dark:border-slate-800 flex flex-col py-6 px-3.5 no-print" aria-label="Menu Ban Chỉ Huy">
        <div class="px-2 mb-4 pb-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2.5">
                <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400 text-[24px]">shield_person</span>
                <div>
                    <div class="font-extrabold text-xs tracking-wider text-slate-900 dark:text-white">BAN CHỈ HUY</div>
                    <div class="text-[11px] text-slate-500">Điều phối Chiến dịch</div>
                </div>
            </div>
            <button type="button" id="btn-close-workspace-drawer" class="w-7 h-7 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800" title="Đóng menu">
                <span class="material-symbols-outlined text-[18px]">close</span>
            </button>
        </div>

        <nav class="flex flex-col gap-1.5">
            <button type="button" data-workspace-tab="tab-board" class="workspace-tab-btn active flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm text-left bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400 transition-all">
                <span class="material-symbols-outlined text-[20px]">dashboard</span>
                <span>Bàn Điều phối Ca trực</span>
            </button>
            <button type="button" data-workspace-tab="tab-volunteers" class="workspace-tab-btn flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm text-left text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                <span class="material-symbols-outlined text-[20px]">group</span>
                <span>Danh sách Tình nguyện viên</span>
            </button>
            <button type="button" data-workspace-tab="tab-lab" class="workspace-tab-btn flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm text-left text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                <span class="material-symbols-outlined text-[20px]">auto_awesome_motion</span>
                <span>Tự động Xếp ca</span>
            </button>
            <button type="button" data-workspace-tab="tab-understaffed" class="workspace-tab-btn flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm text-left text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                <span class="material-symbols-outlined text-[20px]">warning</span>
                <span>Ca thiếu &amp; Chưa xếp (<?= count($unassignedSlots) ?>/<?= count($unassignedVolunteers) ?>)</span>
            </button>
            <button type="button" data-workspace-tab="tab-analytics" class="workspace-tab-btn flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm text-left text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                <span class="material-symbols-outlined text-[20px]">analytics</span>
                <span>Phân tích &amp; Thống kê BI</span>
            </button>

            <div class="my-3 border-t border-slate-200 dark:border-slate-800"></div>
            <?= renderDrawerCampaignList($campaignMeta, $selectedActivityId, '?view=coordinator&activity_id={id}') ?>

            <div class="my-3 border-t border-slate-200 dark:border-slate-800"></div>
            <button type="button" data-open-modal="modal-create-activity" id="btn-create-activity-coordinator" class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl text-xs font-bold text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-slate-800 text-left">
                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                <span>Tạo Hoạt động Mới</span>
            </button>
            <button type="button" data-open-modal="modal-ai-shift" class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl text-xs font-bold text-[#0057B8] dark:text-sky-400 hover:bg-blue-50 dark:hover:bg-slate-800 text-left">
                <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
                <span>Tạo ca nhanh bằng AI</span>
            </button>
            <button type="button" id="btn-undo-assign" data-activity-id="<?= $selectedActivityId ?>" class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-left">
                <span class="material-symbols-outlined text-[18px]">undo</span>
                <span>Hoàn tác phân công</span>
            </button>
            <a href="?view=report&activity_id=<?= $selectedActivityId ?>" class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl text-xs font-bold text-[#0057B8] dark:text-sky-400 hover:bg-blue-50 dark:hover:bg-slate-800">
                <span class="material-symbols-outlined text-[18px]">article</span>
                <span>Báo cáo Tổng kết Đoàn - Hội</span>
            </a>
            <a href="?action=export_csv&activity_id=<?= $selectedActivityId ?>" class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl text-xs font-bold text-emerald-600 hover:bg-emerald-50 dark:hover:bg-slate-800">
                <span class="material-symbols-outlined text-[18px]">download</span>
                <span>Xuất Phân công &amp; Giờ (.CSV)</span>
            </a>
            <a href="?action=export_analytics_csv&activity_id=<?= $selectedActivityId ?>" class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl text-xs font-bold text-sky-600 hover:bg-sky-50 dark:hover:bg-slate-800">
                <span class="material-symbols-outlined text-[18px]">table_chart</span>
                <span>Xuất Thống kê Tác động (.CSV)</span>
            </a>
        </nav>
    </aside>

    <div class="flex-1 flex flex-col page-enter max-w-7xl w-full mx-auto min-w-0">
        <!-- Main Workspace Full-Width (Không bị đẩy lệch phải) -->
        <main class="flex-1 min-w-0 w-full px-4 md:px-6 py-6">
            <?php
            // Tính các chỉ số thực tế phục vụ công việc điều phối của Giáo viên / Người phụ trách
            $coordTotalShifts = count($ds->shifts);
            $coordEnoughShifts = 0;
            $coordUnderShifts = 0;
            foreach ($ds->shifts as $sItem) {
                $cAssigned = count($assignedByShift[$sItem->id] ?? []);
                if ($cAssigned >= $sItem->minPeople) {
                    $coordEnoughShifts++;
                } else {
                    $coordUnderShifts++;
                }
            }
            $coordCoveragePct = $coordTotalShifts > 0 ? round(($coordEnoughShifts / $coordTotalShifts) * 100, 1) : 0.0;
            $coordPendingRegs = 0;
            foreach ($regsByShift as $sIdKey => $rMap) {
                foreach ($rMap as $vIdKey => $pRank) {
                    $alreadyInShift = false;
                    foreach (($assignedByShift[$sIdKey] ?? []) as $asItem) {
                        if ((int)$asItem['volunteer_id'] === (int)$vIdKey) {
                            $alreadyInShift = true;
                            break;
                        }
                    }
                    if (!$alreadyInShift) {
                        $coordPendingRegs++;
                    }
                }
            }
            $coordTotalAbsences = 0;
            foreach ($absencesByShift as $absList) {
                $coordTotalAbsences += count($absList);
            }
            ?>
            <!-- 4 Thẻ Chỉ số Thực tế phục vụ Điều phối Chiến dịch -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
                <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden hover-lift shadow-sm">
                    <div class="absolute -right-3 -top-3 text-emerald-500/10">
                        <span class="material-symbols-outlined text-[88px]">verified</span>
                    </div>
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider relative z-10">Tiến độ Đủ Quân số Ca</span>
                    <div class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-1 relative z-10">
                        <?= $coordEnoughShifts ?> / <?= $coordTotalShifts ?> ca (<?= $coordCoveragePct ?>%)
                    </div>
                    <span class="text-xs text-slate-500 mt-1 block relative z-10">Còn <?= $coordUnderShifts ?> ca chưa đủ định biên tối thiểu</span>
                </div>

                <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden hover-lift shadow-sm">
                    <div class="absolute -right-3 -top-3 text-blue-500/10">
                        <span class="material-symbols-outlined text-[88px]">how_to_reg</span>
                    </div>
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider relative z-10">Nhân sự &amp; Đơn Đăng ký</span>
                    <div class="text-2xl font-extrabold text-slate-900 dark:text-white mt-1 relative z-10">
                        <?= count($activeRows) ?> lượt phân công
                    </div>
                    <span class="text-xs text-[#0057B8] dark:text-sky-400 font-semibold mt-1 block relative z-10"><?= $coordPendingRegs ?> nguyện vọng đang chờ duyệt</span>
                </div>

                <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden hover-lift shadow-sm">
                    <div class="absolute -right-3 -top-3 text-sky-500/10">
                        <span class="material-symbols-outlined text-[88px]">person_alert</span>
                    </div>
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider relative z-10">Báo vắng &amp; Kiểm tra Lịch</span>
                    <div class="text-2xl font-extrabold text-[#0057B8] dark:text-sky-400 mt-1 relative z-10">
                        <?= $coordTotalAbsences ?> báo vắng
                    </div>
                    <span class="text-xs text-emerald-600 font-semibold mt-1 block relative z-10">✓ Đảm bảo 0 trùng giờ &middot; Đúng kỹ năng</span>
                </div>

                <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden hover-lift shadow-sm">
                    <div class="absolute -right-3 -top-3 text-amber-500/10">
                        <span class="material-symbols-outlined text-[88px]">qr_code_2</span>
                    </div>
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider relative z-10">Điểm danh Hiện trường</span>
                    <div class="mt-2 relative z-10">
                        <button
                            type="button"
                            class="btn btn-primary btn-sm w-full btn-open-qr-projector"
                            data-shift-id="<?= h($ds->shifts[0]->id ?? 201) ?>"
                            data-shift-title="<?= h(($ds->shifts[0]->code ?? 'CA-01') . ' · ' . ($ds->shifts[0]->title ?? 'Ca trực')) ?>"
                        >
                            <?= icon('qr-code', 14) ?> Mở Máy chiếu QR Điểm danh
                        </button>
                    </div>
                    <span class="text-xs text-slate-500 mt-1.5 block relative z-10">Vị trí cần bổ sung: <?= count($unassignedSlots) ?> vị trí</span>
                </div>
            </div>

            <!-- Thanh Điều hướng Nhanh Ban Chỉ Huy & Nút Mở Menu Mini Nổi (Đặt sát ngay trên vùng Nội dung Tab) -->
            <div id="coordinator-workspace-tabs-bar" class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-3 mb-5 shadow-sm flex flex-wrap items-center justify-between gap-3 sticky top-[68px] z-30">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" class="btn-toggle-workspace-drawer inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-900 dark:bg-sky-500 text-white dark:text-slate-950 font-bold text-xs shadow-sm hover:opacity-90 transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-[18px]">widgets</span>
                        <span>Menu BCH</span>
                    </button>
                    <div class="h-5 w-[1px] bg-slate-200 dark:bg-slate-700 hidden sm:block mx-0.5"></div>
                    <button type="button" data-workspace-tab="tab-board" class="workspace-tab-btn active inline-flex items-center gap-1.5 px-3 py-2 rounded-xl font-bold text-xs transition-all bg-blue-50 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-400 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">dashboard</span>
                        <span>Bàn Điều phối</span>
                    </button>
                    <button type="button" data-workspace-tab="tab-volunteers" class="workspace-tab-btn inline-flex items-center gap-1.5 px-3 py-2 rounded-xl font-bold text-xs transition-all text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">group</span>
                        <span>Lực lượng TNV</span>
                    </button>
                    <button type="button" data-workspace-tab="tab-lab" class="workspace-tab-btn inline-flex items-center gap-1.5 px-3 py-2 rounded-xl font-bold text-xs transition-all text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">auto_awesome_motion</span>
                        <span>Tự động Xếp ca</span>
                    </button>
                    <button type="button" data-workspace-tab="tab-understaffed" class="workspace-tab-btn inline-flex items-center gap-1.5 px-3 py-2 rounded-xl font-bold text-xs transition-all text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">warning</span>
                        <span>Ca thiếu (<?= count($unassignedSlots) ?>/<?= count($unassignedVolunteers) ?>)</span>
                    </button>
                    <button type="button" data-workspace-tab="tab-analytics" class="workspace-tab-btn inline-flex items-center gap-1.5 px-3 py-2 rounded-xl font-bold text-xs transition-all text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">analytics</span>
                        <span>Thống kê BI</span>
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <?= renderScalableCampaignSwitcher($campaignMeta, $selectedActivityId, '?view=coordinator&activity_id={id}') ?>
                </div>
            </div>

            <!-- TAB 1: BÀN ĐIỀU PHỐI MASTER-DETAIL -->
            <div id="tab-board" class="workspace-tab-pane active">
                <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 sm:p-5 shadow-sm">
                    <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                        <div class="min-w-0">
                            <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white truncate"><?= h($activeCampaign['title']) ?></h2>
                            <p class="text-xs text-slate-500">Chọn một ca trực theo lịch ngày ở cột trái để xem danh sách nhân sự, duyệt đơn đăng ký hoặc phân công ứng viên phù hợp</p>
                        </div>
                        <div class="flex flex-wrap gap-1.5 shrink-0">
                            <button type="button" class="btn btn-sm btn-outline active" data-shift-filter="ALL">Tất cả (<?= count($ds->shifts) ?>)</button>
                            <button type="button" class="btn btn-sm btn-outline" data-shift-filter="UNDERSTAFFED">Thiếu người</button>
                            <button type="button" class="btn btn-sm btn-outline" data-shift-filter="FULL">Đủ người</button>
                            <button type="button" class="btn btn-sm btn-outline" data-shift-filter="LOCKED">Đã khóa</button>
                        </div>
                    </div>

                    <div class="flex flex-col lg:flex-row gap-5 mt-4 min-w-0">
                        <!-- CỘT MASTER: DANH SÁCH CA TRỰC GOM NHÓM THEO NGÀY -->
                        <div id="coordinator-master-col" class="w-full lg:w-[320px] xl:w-[350px] shrink-0 space-y-3">
                            <div class="relative">
                                <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-[18px]">search</span>
                                <input type="text" id="shift-search-input" placeholder="Tìm theo mã ca, tên ca, ngày, địa bàn..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-9 pr-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                            </div>

                            <div id="compact-shifts-list" class="space-y-3 max-h-[760px] overflow-y-auto pr-1">
                                <?php
                                $lastCoordDateKey = '';
                                foreach ($ds->shifts as $idx => $s):
                                    $sFmt = formatShiftTimeRangeVn($s->startEpoch, $s->endEpoch);
                                    $currList = $assignedByShift[$s->id] ?? [];
                                    $currCount = count($currList);
                                    $isValidShift = ($currCount >= $s->minPeople && $currCount <= $s->maxPeople);
                                    $isUnder = ($currCount < $s->minPeople);
                                    $isFull = ($currCount >= $s->maxPeople);
                                    $statusTag = $s->isLocked ? 'LOCKED' : ($isUnder ? 'UNDERSTAFFED' : ($isFull ? 'FULL' : 'VALID'));
                                    $fillPct = min(100, (int) round(($currCount / max(1, $s->maxPeople)) * 100));
                                    $req = $s->requirements[0] ?? null;
                                    $skCode = $req?->skillCode ?? 'LOGISTICS';
                                    $skLabel = $skillNameVi[$skCode] ?? $skCode;
                                    $isActive = ($idx === 0);
                                    if ($sFmt['date_key'] !== $lastCoordDateKey):
                                        $lastCoordDateKey = $sFmt['date_key'];
                                ?>
                                        <div class="coord-date-group-header sticky top-0 z-10 bg-slate-100/95 dark:bg-slate-800/95 backdrop-blur-xs px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 flex items-center justify-between text-[11px] font-extrabold text-slate-700 dark:text-slate-200">
                                            <span class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-[15px] text-[#0057B8] dark:text-sky-400">calendar_today</span>
                                                <span><?= h($sFmt['date_label']) ?></span>
                                            </span>
                                        </div>
                                <?php endif; ?>
                                    <article
                                        class="compact-shift-card p-4 rounded-2xl border transition-all cursor-pointer <?= $isActive ? 'active border-[#0057B8] dark:border-sky-400 bg-blue-50/40 dark:bg-sky-950/30 ring-2 ring-[#0057B8]/20 shadow-sm' : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-[#131822] hover:border-slate-300 dark:hover:border-slate-700' ?>"
                                        data-shift-id="<?= $s->id ?>"
                                        data-shift-code="<?= h($s->code) ?>"
                                        data-shift-title="<?= h($s->title) ?>"
                                        data-shift-status="<?= $statusTag ?>"
                                        data-assigned-count="<?= $currCount ?>"
                                        data-min-people="<?= $s->minPeople ?>"
                                        data-max-people="<?= $s->maxPeople ?>"
                                        data-is-locked="<?= $s->isLocked ? '1' : '0' ?>"
                                        data-search="<?= h(mb_strtolower($s->code . ' ' . $s->title . ' ' . $s->locationName . ' ' . $skLabel . ' ' . $sFmt['date_label'] . ' ' . $sFmt['session_label'])) ?>"
                                    >
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <div>
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <span class="mono font-bold text-xs text-[#0057B8] dark:text-sky-400">[<?= h($s->code) ?>]</span>
                                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold border <?= $sFmt['session_badge'] ?>"><?= h($sFmt['session_label']) ?></span>
                                                    <?php if ($s->isLocked): ?>
                                                        <span class="material-symbols-outlined text-amber-500 text-[14px]" title="Ca đã khóa chốt">lock</span>
                                                    <?php endif; ?>
                                                    <span class="badge <?= $isFull ? 'badge-valid' : ($isUnder ? 'badge-violation' : 'badge-info') ?> text-[10px]">
                                                        <?= $s->isLocked ? 'Đã khóa' : ($isFull ? 'Đủ người' : ($isUnder ? 'Thiếu người' : 'Hợp lệ')) ?>
                                                    </span>
                                                </div>
                                                <h3 class="font-extrabold text-sm text-slate-900 dark:text-white mt-1 line-clamp-1"><?= h($s->title) ?></h3>
                                            </div>
                                            <div class="flex gap-1 shrink-0">
                                                <button type="button" class="btn btn-sm btn-outline btn-toggle-lock px-1.5 py-1 text-slate-500"
                                                        title="<?= $s->isLocked ? 'Mở khóa ca trực' : 'Khóa chốt ca trực' ?>"
                                                        data-shift-id="<?= $s->id ?>" data-shift-code="<?= h($s->code) ?>" data-is-locked="<?= $s->isLocked ? '1' : '0' ?>">
                                                    <?= $s->isLocked ? icon('lock', 12) : icon('unlock', 12) ?>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline btn-open-qr-projector px-1.5 py-1 text-slate-500"
                                                        title="Mở máy chiếu QR điểm danh"
                                                        data-shift-id="<?= $s->id ?>" data-shift-title="[<?= h($s->code) ?>] <?= h($s->title) ?>">
                                                    <?= icon('qr-code', 12) ?>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="text-[11px] text-slate-500 dark:text-slate-400 space-y-1 mb-2.5">
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-[13px]">schedule</span>
                                                <span class="mono font-semibold text-slate-700 dark:text-slate-300"><?= h($sFmt['time_range']) ?></span>
                                                <span>&middot;</span>
                                                <span class="truncate"><?= h($s->locationName) ?></span>
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-[13px]">psychology</span>
                                                <span><?= h($skLabel) ?> &ge; Cấp <?= (int) ($req?->minLevel ?? 1) ?></span>
                                            </div>
                                        </div>

                                        <!-- Capacity Progress Bar -->
                                        <div class="mb-2.5">
                                            <div class="flex justify-between items-center text-[10px] mono text-slate-500 mb-1">
                                                <span>Quân số: <strong class="<?= $isUnder ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $currCount ?></strong>/<?= $s->minPeople ?>–<?= $s->maxPeople ?></span>
                                                <span><?= $fillPct ?>%</span>
                                            </div>
                                            <div class="w-full h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                                <div class="h-full rounded-full transition-all" style="width: <?= $fillPct ?>%; background-color: <?= $isUnder ? '#E11D48' : ($isFull ? '#059669' : '#0284C7') ?>;"></div>
                                            </div>
                                        </div>

                                        <!-- Avatar Stack (Max 4 avatars + +N badge) -->
                                        <div class="flex items-center justify-between pt-2 border-t border-slate-100 dark:border-slate-800/80">
                                            <div class="avatar-stack flex items-center -space-x-2 overflow-hidden">
                                                <?php if (empty($currList)): ?>
                                                    <span class="text-[11px] text-slate-400 italic">Chưa có nhân sự</span>
                                                <?php else:
                                                    $maxShowAvatars = 4;
                                                    $shownAvatars = array_slice($currList, 0, $maxShowAvatars);
                                                    $extraCount = count($currList) - $maxShowAvatars;
                                                    foreach ($shownAvatars as $item):
                                                ?>
                                                        <img
                                                            src="<?= h(avatarUrlFor((int) $item['volunteer_id'], $item['full_name'])) ?>"
                                                            alt="<?= h($item['full_name']) ?>"
                                                            title="<?= h($item['full_name'] . ' (' . $item['student_code'] . ')') ?>"
                                                            class="w-7 h-7 rounded-full object-cover ring-2 ring-white dark:ring-slate-900 shadow-xs"
                                                        />
                                                    <?php endforeach; ?>
                                                    <?php if ($extraCount > 0): ?>
                                                        <span class="w-7 h-7 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-[10px] font-bold flex items-center justify-center ring-2 ring-white dark:ring-slate-900" title="Và <?= $extraCount ?> tình nguyện viên khác">
                                                            +<?= $extraCount ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                            <span class="text-[11px] font-bold text-[#0057B8] dark:text-sky-400 flex items-center gap-0.5">Chi tiết &rarr;</span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- CỘT DETAIL: SHIFT ROSTER INSPECTOR -->
                        <div id="coordinator-detail-col" class="flex-1 min-w-0 bg-slate-50/50 dark:bg-slate-800/30 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 sm:p-5 shadow-sm flex flex-col justify-between overflow-hidden">
                            <div class="min-w-0">
                                <!-- Active Shift Header -->
                                <?php
                                $firstShift = $ds->shifts[0] ?? null;
                                $firstReq = $firstShift?->requirements[0] ?? null;
                                ?>
                                <div id="inspector-shift-header" class="pb-3.5 mb-3.5 border-b border-slate-200 dark:border-slate-800 space-y-3">
                                    <div class="flex flex-wrap sm:flex-nowrap justify-between items-start gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                                <span id="insp-shift-code" class="mono font-extrabold text-sm text-[#0057B8] dark:text-sky-400">[<?= h($firstShift?->code ?? 'CA-01') ?>]</span>
                                                <span id="insp-shift-status-badge" class="badge badge-info">Đang điều phối</span>
                                                <span id="insp-shift-lock-badge" class="hidden"><span class="badge badge-warning text-[10px]">ĐÃ KHÓA</span></span>
                                            </div>
                                            <h2 id="insp-shift-title" class="text-base font-extrabold text-slate-900 dark:text-white truncate"><?= h($firstShift?->title ?? 'Ca trực') ?></h2>
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 mt-1.5">
                                                <span class="flex items-center gap-1 whitespace-nowrap"><span class="material-symbols-outlined text-[15px]">schedule</span><span id="insp-shift-time">--</span></span>
                                                <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">location_on</span><span id="insp-shift-location">--</span></span>
                                                <span class="flex items-center gap-1 whitespace-nowrap"><span class="material-symbols-outlined text-[15px]">psychology</span><span id="insp-shift-skill">--</span></span>
                                            </div>
                                        </div>
                                        <div class="text-right shrink-0 pl-2">
                                            <div class="text-[11px] text-slate-400 uppercase font-bold mb-0.5 whitespace-nowrap">Định biên quân số</div>
                                            <div class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white mono whitespace-nowrap"><span id="insp-assigned-count">0</span> / <span id="insp-capacity-range"><?= (int) ($firstShift?->minPeople ?? 2) ?>–<?= (int) ($firstShift?->maxPeople ?? 4) ?></span></div>
                                            <span id="insp-capacity-note" class="text-[11px] text-slate-500">người</span>
                                        </div>
                                    </div>

                                    <!-- Form Cập nhật Nhanh Số người cần cho từng ca & Yêu cầu Kỹ năng -->
                                    <form method="post" action="?view=coordinator&activity_id=<?= $selectedActivityId ?>&action=update_shift_capacity" class="p-2.5 rounded-xl bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-700/80 flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                                        <input type="hidden" name="shift_id" id="insp-form-shift-id" value="<?= (int) ($firstShift?->id ?? 0) ?>">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-bold text-slate-600 dark:text-slate-300 flex items-center gap-1 whitespace-nowrap">
                                                <span class="material-symbols-outlined text-[15px] text-[#0057B8]">tune</span>
                                                <span>Số người cần &amp; Kỹ năng:</span>
                                            </span>
                                            <label class="flex items-center gap-1 whitespace-nowrap">
                                                <span class="text-slate-500">Min:</span>
                                                <input type="number" name="min_people" id="insp-form-min-people" value="<?= (int) ($firstShift?->minPeople ?? 2) ?>" min="1" max="50" class="w-12 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-1.5 py-1 text-xs mono font-bold">
                                            </label>
                                            <label class="flex items-center gap-1 whitespace-nowrap">
                                                <span class="text-slate-500">Max:</span>
                                                <input type="number" name="max_people" id="insp-form-max-people" value="<?= (int) ($firstShift?->maxPeople ?? 4) ?>" min="1" max="50" class="w-12 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-1.5 py-1 text-xs mono font-bold">
                                            </label>
                                            <label class="flex items-center gap-1 whitespace-nowrap">
                                                <span class="text-slate-500">Kỹ năng:</span>
                                                <select name="skill_code" id="insp-form-skill-code" class="bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-1 text-xs font-semibold">
                                                    <?php foreach ($skillCodeById as $skIdOpt => $skCodeOpt):
                                                        $skLabelOpt = $skillNameVi[$skCodeOpt] ?? $skCodeOpt;
                                                    ?>
                                                        <option value="<?= h($skCodeOpt) ?>" <?= ($firstReq?->skillCode ?? 'LOGISTICS') === $skCodeOpt ? 'selected' : '' ?>><?= h($skLabelOpt) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="flex items-center gap-1 whitespace-nowrap">
                                                <span class="text-slate-500">Cấp &ge;</span>
                                                <select name="min_level" id="insp-form-min-level" class="bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-1.5 py-1 text-xs mono font-bold">
                                                    <?php for ($lv = 1; $lv <= 5; $lv++): ?>
                                                        <option value="<?= $lv ?>" <?= (int) ($firstReq?->minLevel ?? 1) === $lv ? 'selected' : '' ?>><?= $lv ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </label>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-outline text-[#0057B8] dark:text-sky-400 border-blue-200 dark:border-sky-800 hover:bg-blue-50 dark:hover:bg-slate-800 whitespace-nowrap shrink-0">
                                            Lưu số người cần
                                        </button>
                                    </form>
                                </div>

                                <!-- CỤM 3 TAB NGANG FULL-WIDTH TRONG SHIFT INSPECTOR -->
                                <div class="w-full min-w-0 mt-3 flex-1 flex flex-col">
                                    <!-- Header 3 Tab Buttons -->
                                    <div class="flex flex-wrap items-center justify-between border-b border-slate-200 dark:border-slate-800 gap-2 mb-3.5">
                                        <div class="flex flex-wrap items-center gap-1">
                                            <button type="button"
                                                    id="btn-tab-assigned"
                                                    class="inspector-tab-btn active px-3 py-2 text-xs font-bold border-b-2 border-[#0057B8] text-[#0057B8] dark:border-sky-400 dark:text-sky-400 flex items-center gap-1.5 whitespace-nowrap transition-all"
                                                    data-inspector-tab="assigned"
                                                    data-tab-target="#inspector-tab-assigned">
                                                <span class="material-symbols-outlined text-[15px]">how_to_reg</span>
                                                <span>Nhân sự &amp; Duyệt Đăng ký</span>
                                                <span id="badge-assigned-roster-count" class="badge badge-valid mono text-[10px] ml-0.5">0 người</span>
                                            </button>
                                            <button type="button"
                                                    id="btn-tab-eligible"
                                                    class="inspector-tab-btn px-3 py-2 text-xs font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 border-b-2 border-transparent flex items-center gap-1.5 whitespace-nowrap transition-all"
                                                    data-inspector-tab="eligible"
                                                    data-tab-target="#inspector-tab-eligible">
                                                <span class="material-symbols-outlined text-[15px]">person_add</span>
                                                <span>Phân công Thủ công &amp; DS Ứng viên</span>
                                                <span id="badge-eligible-count" class="badge badge-info mono text-[10px] ml-0.5">0 ứng viên</span>
                                            </button>
                                            <button type="button"
                                                    id="btn-tab-replacement"
                                                    class="inspector-tab-btn px-3 py-2 text-xs font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 border-b-2 border-transparent flex items-center gap-1.5 whitespace-nowrap transition-all"
                                                    data-inspector-tab="replacement"
                                                    data-tab-target="#inspector-tab-replacement">
                                                <span class="material-symbols-outlined text-[15px]">published_with_changes</span>
                                                <span>Báo Vắng &amp; Thay Thế</span>
                                                <span id="badge-replacement-tab-count" class="badge badge-warning mono text-[10px] ml-0.5">0 yêu cầu</span>
                                            </button>
                                        </div>
                                        <form method="post" action="?view=coordinator&activity_id=<?= $selectedActivityId ?>&action=run_scheduler" class="pb-1.5">
                                            <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                                            <input type="hidden" name="priority_mode" value="MAX_COVERAGE">
                                            <input type="hidden" name="w_priority" value="5">
                                            <input type="hidden" name="w_preference" value="2">
                                            <input type="hidden" name="w_skill_waste" value="3">
                                            <input type="hidden" name="w_load" value="1">
                                            <input type="hidden" name="apply_immediately" value="1">
                                            <button type="submit" class="btn btn-sm btn-success flex items-center gap-1.5 text-xs font-bold whitespace-nowrap shadow-xs" title="Chạy tự động xét duyệt đăng ký và phân công toàn chiến dịch">
                                                <span class="material-symbols-outlined text-[15px]">bolt</span>
                                                <span>Tự động Đề xuất &amp; Duyệt Phân công</span>
                                            </button>
                                        </form>
                                    </div>

                                    <!-- TAB 1: NHÂN SỰ ĐÃ PHÂN CÔNG & PHÂN CÔNG THỦ CÔNG TRỰC TIẾP (#inspector-tab-assigned) -->
                                    <div id="inspector-tab-assigned" class="inspector-tab-pane w-full block">
                                        <!-- Khung Phân công Thủ công Trực tiếp vào Ca đang chọn -->
                                        <div class="mb-3.5 p-3.5 rounded-xl bg-blue-50/60 dark:bg-sky-950/25 border border-blue-200 dark:border-sky-800/80">
                                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                                <div class="text-xs font-extrabold text-[#0057B8] dark:text-sky-400 flex items-center gap-1.5">
                                                    <span class="material-symbols-outlined text-[17px]">person_add</span>
                                                    <span>Phân công Thủ công / Thêm Người vào Ca trực này</span>
                                                </div>
                                                <span class="text-[11px] text-slate-500">Tự động kiểm tra: Không trùng lịch &middot; Đủ kỹ năng &middot; Phù hợp lịch rảnh &middot; Không vượt quân số</span>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2.5 items-center">
                                                <div class="sm:col-span-6">
                                                    <select id="manual-assign-volunteer-select" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-semibold">
                                                        <?php foreach ($ds->volunteers as $vOpt):
                                                            $vSkNames = [];
                                                            foreach ($vOpt->skills as $skIdV => $skEntryV) {
                                                                $cV = $skillCodeById[(int) $skIdV] ?? ('SKILL_' . $skIdV);
                                                                $vSkNames[] = ($skillNameVi[$cV] ?? $cV) . ' C' . (int) $skEntryV['level'];
                                                            }
                                                            $vSkSummary = !empty($vSkNames) ? implode(', ', $vSkNames) : 'Chưa có kỹ năng';
                                                        ?>
                                                            <option value="<?= (int) $vOpt->id ?>">
                                                                [<?= h($vOpt->studentCode) ?>] <?= h($vOpt->fullName) ?> — (<?= h($vSkSummary) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="sm:col-span-3">
                                                    <input
                                                        type="text"
                                                        id="manual-assign-override-reason"
                                                        placeholder="Lý do ngoại lệ (nếu thiếu kỹ năng/lịch rảnh)..."
                                                        class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs"
                                                    />
                                                </div>
                                                <div class="sm:col-span-3">
                                                    <button
                                                        type="button"
                                                        id="btn-direct-manual-assign"
                                                        class="btn btn-primary w-full py-2 px-3 text-xs font-bold flex items-center justify-center gap-1.5 whitespace-nowrap shadow-xs cursor-pointer"
                                                    >
                                                        <span class="material-symbols-outlined text-[16px]">person_add</span>
                                                        <span>+ Gán vào Ca này</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="zone-assigned-roster" class="p-4 rounded-xl bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-700/80 flex flex-col w-full">
                                            <div class="relative mb-3">
                                                <span class="material-symbols-outlined absolute left-2.5 top-2 text-slate-400 text-[16px]">search</span>
                                                <input type="text" id="search-assigned-roster" placeholder="Lọc nhanh nhân sự trong ca (Họ tên, MSSV, Chi đoàn)..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg pl-8 pr-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                                            </div>

                                            <div id="assigned-roster-list" class="space-y-2.5 max-h-[480px] overflow-y-auto pr-1 flex-1 min-h-[160px] w-full">
                                                <!-- Rendered dynamically via JS -->
                                            </div>
                                        </div>
                                    </div>

                                    <!-- TAB 2: ỨNG VIÊN PHÙ HỢP GỢI Ý (#inspector-tab-eligible) -->
                                    <div id="inspector-tab-eligible" class="inspector-tab-pane w-full hidden">
                                        <div id="zone-eligible-pool" class="p-4 rounded-xl bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-700/80 flex flex-col w-full">
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                                <div class="relative">
                                                    <span class="material-symbols-outlined absolute left-2.5 top-2 text-slate-400 text-[16px]">search</span>
                                                    <input type="text" id="search-eligible-pool" placeholder="Tìm theo tên tình nguyện viên, MSSV, trường..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg pl-8 pr-2.5 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                                                </div>
                                                <select id="filter-eligible-skill" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-2 text-xs">
                                                    <option value="">— Tất cả kỹ năng chuyên môn —</option>
                                                    <option value="FIRST_AID">Sơ cứu Y tế</option>
                                                    <option value="LOGISTICS">Hậu cần Tiếp sức</option>
                                                    <option value="MEDIA">Truyền thông Sự kiện</option>
                                                    <option value="TRAFFIC">Điều tiết Giao thông</option>
                                                    <option value="IT_SUPPORT">Hỗ trợ Kỹ thuật số</option>
                                                </select>
                                            </div>

                                            <div id="eligible-pool-list" class="space-y-2.5 max-h-[440px] overflow-y-auto pr-1 flex-1 min-h-[160px] w-full">
                                                <!-- Rendered dynamically via JS -->
                                            </div>

                                            <!-- Pagination for Eligible Pool -->
                                            <div id="eligible-pool-pagination" class="mt-3 pt-2.5 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between text-xs text-slate-500">
                                                <button type="button" id="btn-eligible-prev" class="btn btn-sm btn-outline py-1 px-3 disabled:opacity-50" disabled>&laquo; Trước</button>
                                                <span id="text-eligible-page" class="mono font-semibold">1 / 1</span>
                                                <button type="button" id="btn-eligible-next" class="btn btn-sm btn-outline py-1 px-3 disabled:opacity-50" disabled>Sau &raquo;</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- TAB 3: BÁO VẮNG & ĐỀ XUẤT NGƯỜI THAY THẾ (#inspector-tab-replacement) -->
                                    <div id="inspector-tab-replacement" class="inspector-tab-pane w-full hidden">
                                        <div class="p-4 rounded-xl bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-700/80 flex flex-col w-full space-y-5">
                                            <!-- 3.1. Danh sách Báo vắng của Ca này -->
                                            <div>
                                                <div class="flex items-center justify-between mb-3">
                                                    <div class="flex items-center gap-2">
                                                        <span class="material-symbols-outlined text-rose-500 text-[18px]">report_problem</span>
                                                        <h4 class="font-extrabold text-xs uppercase tracking-wider text-slate-800 dark:text-slate-200">Phiếu Báo Vắng Trong Ca Trực</h4>
                                                    </div>
                                                    <span id="badge-shift-absences-count" class="badge badge-subtle mono text-[10px]">0 phiếu</span>
                                                </div>
                                                <div id="inspector-absence-reports-list" class="space-y-2.5">
                                                    <!-- Rendered via JS -->
                                                </div>
                                            </div>

                                            <!-- 3.2. Bảng xếp hạng ứng viên thay thế phù hợp -->
                                            <div class="border-t border-slate-200 dark:border-slate-800 pt-4">
                                                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                                                    <div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-[18px]">auto_awesome</span>
                                                            <h4 class="font-extrabold text-xs uppercase tracking-wider text-slate-800 dark:text-slate-200">Đề Xuất Ứng Viên Thay Thế Phù Hợp Nhất</h4>
                                                        </div>
                                                        <p class="text-[11px] text-slate-500 mt-0.5">Chỉ hiển thị ứng viên rảnh giờ, đủ kỹ năng chuyên môn và chưa trùng lịch</p>
                                                    </div>
                                                    <span id="badge-shift-replacements-count" class="badge badge-info mono text-[10px]">Top 5 gợi ý</span>
                                                </div>
                                                <div id="inspector-replacement-suggestions-list" class="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">
                                                    <!-- Rendered via JS -->
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 1.5: DANH SÁCH TÌNH NGUYỆN VIÊN -->
            <div id="tab-volunteers" class="workspace-tab-pane">
                <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                    <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
                        <div>
                            <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                                <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400">group</span>
                                <span>Lực lượng Tình nguyện viên &amp; Năng lực Điều phối</span>
                            </h2>
                            <p class="text-xs text-slate-500">Tra cứu toàn diện <?= count($ds->volunteers) ?> tình nguyện viên, phân tích kỹ năng chuyên môn, lịch rảnh và số ca đã nhận</p>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-slate-500">Hiển thị mỗi trang:</span>
                            <select id="datagrid-page-size-select" class="bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5 font-bold">
                                <option value="10" selected>10 người</option>
                                <option value="20">20 người</option>
                                <option value="50">50 người</option>
                            </select>
                        </div>
                    </div>

                    <!-- Multi-criteria Filter Bar -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-5 p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 text-xs">
                        <div>
                            <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Từ khóa tìm kiếm:</label>
                            <input type="text" id="datagrid-search-input" placeholder="Họ tên, MSSV, email..." class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 focus:border-[#0057B8] focus:outline-none" />
                        </div>
                        <div>
                            <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Cơ sở Đoàn / Trường:</label>
                            <select id="datagrid-unit-select" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5">
                                <option value="">— Tất cả Cơ sở Đoàn —</option>
                                <option value="Bách Khoa">ĐH Bách Khoa</option>
                                <option value="Khoa học Tự nhiên">ĐH Khoa học Tự nhiên</option>
                                <option value="Khoa học Xã hội">ĐH KHXH&NV</option>
                                <option value="Kinh tế - Luật">ĐH Kinh tế - Luật</option>
                                <option value="Công nghệ Thông tin">ĐH Công nghệ Thông tin</option>
                                <option value="Quốc tế">ĐH Quốc tế</option>
                                <option value="Y Dược">ĐH Y Dược TP.HCM</option>
                                <option value="Sư phạm Kỹ thuật">ĐH Sư phạm Kỹ thuật</option>
                            </select>
                        </div>
                        <div>
                            <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Kỹ năng Chuyên môn:</label>
                            <select id="datagrid-skill-select" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5">
                                <option value="">— Tất cả Kỹ năng —</option>
                                <option value="FIRST_AID">Sơ cứu Y tế</option>
                                <option value="LOGISTICS">Hậu cần Tiếp sức</option>
                                <option value="MEDIA">Truyền thông Sự kiện</option>
                                <option value="TRAFFIC">Điều tiết Giao thông</option>
                                <option value="IT_SUPPORT">Hỗ trợ Kỹ thuật số</option>
                            </select>
                        </div>
                        <div>
                            <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Trạng thái Ca đã nhận:</label>
                            <select id="datagrid-shifts-select" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5">
                                <option value="">— Tất cả trạng thái —</option>
                                <option value="ZERO">Chưa nhận ca (0 ca)</option>
                                <option value="ONE">Đã nhận 1 ca</option>
                                <option value="TWO">Đã nhận 2 ca</option>
                                <option value="THREE_PLUS">Đã nhận &ge; 3 ca</option>
                            </select>
                        </div>
                    </div>

                    <!-- Bảng danh sách tình nguyện viên -->
                    <div class="overflow-x-auto no-scrollbar border border-slate-200 dark:border-slate-800 rounded-xl mb-4">
                        <table class="w-full min-w-[960px] text-left text-xs border-collapse">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-800 text-[11px] font-bold uppercase text-slate-500 tracking-wider">
                                    <th class="py-3 px-3.5 text-center w-12">#</th>
                                    <th class="py-3 px-3.5">Tình nguyện viên</th>
                                    <th class="py-3 px-3.5">Cơ sở Đoàn</th>
                                    <th class="py-3 px-3.5">Kỹ năng Chuyên môn</th>
                                    <th class="py-3 px-3.5">Lịch rảnh &amp; Hạn mức</th>
                                    <th class="py-3 px-3.5">Ca đã nhận</th>
                                    <th class="py-3 px-3.5">Cống hiến &amp; XP</th>
                                    <th class="py-3 px-3.5 text-center w-24">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="datagrid-tbody" class="divide-y divide-slate-100 dark:divide-slate-800/60">
                                <!-- Populated by JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Thanh phân trang danh sách -->
                    <div id="datagrid-pagination-bar" class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500 pt-2">
                        <div id="datagrid-info-text">
                            Đang tải danh sách tình nguyện viên...
                        </div>
                        <div id="datagrid-pagination-controls" class="flex items-center gap-1.5">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- EMBEDDED JSON DỮ LIỆU ĐIỀU PHỐI (CHỐNG GIẬT LAG CLIENT-SIDE) -->
            <script id="coordinator-data-payload" type="application/json">
            <?= json_encode([
                'activity_id' => $selectedActivityId,
                'shifts' => $jsShifts,
                'volunteers' => $jsVolunteers,
            ], JSON_UNESCAPED_UNICODE) ?>
            </script>

            <!-- TAB 2: TỰ ĐỘNG ĐỀ XUẤT PHÂN CÔNG THEO ĐIỀU KIỆN & THỨ TỰ ƯU TIÊN -->
            <div id="tab-lab" class="workspace-tab-pane">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                                <h3 class="font-extrabold text-base flex items-center gap-2 text-slate-900 dark:text-white">
                                    <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400">fact_check</span>
                                    <span>Tóm tắt Kết quả Đề xuất Phân công</span>
                                </h3>
                                <span class="badge badge-valid text-xs">Đảm bảo 100% điều kiện lịch &amp; kỹ năng</span>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
                                Công cụ hỗ trợ Người phụ trách tự động ghép Tình nguyện viên vào các ca trực đang mở dựa trên <strong>lịch rảnh đã khai báo</strong>, <strong>kỹ năng chuyên môn bắt buộc</strong> và <strong>nguyện vọng đăng ký</strong> mà không làm mất các phân công thủ công đã gán trước đó.
                            </p>

                            <div class="grid grid-cols-3 gap-3 mb-4 text-center">
                                <div class="p-3 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/60">
                                    <div class="text-[11px] font-bold uppercase text-emerald-700 dark:text-emerald-300">Ca đủ nhân sự</div>
                                    <div class="text-xl font-black text-emerald-600 dark:text-emerald-400 mt-0.5">
                                        <?= h($planX->metrics['valid_covered_shifts']) ?> / <?= h($planX->metrics['total_shifts']) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-500">Đạt <?= h($planX->metrics['valid_coverage_rate']) ?>% tổng số ca</div>
                                </div>
                                <div class="p-3 rounded-xl bg-blue-50/70 dark:bg-sky-950/30 border border-blue-200 dark:border-sky-800/60">
                                    <div class="text-[11px] font-bold uppercase text-[#0057B8] dark:text-sky-300">Phân bổ số ca</div>
                                    <div class="text-xl font-black text-[#0057B8] dark:text-sky-400 mt-0.5">
                                        <?= (int) ($planX->metrics['min_load_per_volunteer'] ?? 0) ?>–<?= (int) ($planX->metrics['max_load_per_volunteer'] ?? 0) ?> ca
                                    </div>
                                    <div class="text-[11px] text-slate-500">Mỗi TNV trong tuần</div>
                                </div>
                                <div class="p-3 rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/60">
                                    <div class="text-[11px] font-bold uppercase text-amber-700 dark:text-amber-300">Vị trí còn trống</div>
                                    <div class="text-xl font-black text-amber-600 dark:text-amber-400 mt-0.5">
                                        <?= count($unassignedSlots) ?> vị trí
                                    </div>
                                    <div class="text-[11px] text-slate-500">Xem chi tiết ở tab Ca thiếu</div>
                                </div>
                            </div>

                            <div class="bg-slate-50 dark:bg-slate-800/60 border-l-4 border-[#0057B8] p-4 rounded-lg text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                <div class="font-bold text-slate-800 dark:text-slate-100 mb-1">Nhận xét &amp; Khuyến nghị Điều phối:</div>
                                <?= nl2br(h(implode("\n\n", $aiExplanation['paragraphs'] ?? []))) ?>
                            </div>
                        </div>

                        <?php if ($currentRole === 'ADMIN'): ?>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500">
                            <span>Cấu hình trọng số toán học chuyên sâu dành cho Quản trị viên:</span>
                            <a href="?view=admin&amp;tab=settings" class="font-bold text-purple-600 dark:text-purple-400 hover:underline flex items-center gap-1">
                                <span class="material-symbols-outlined text-[15px]">tune</span>
                                <span>Mở Cài đặt Trọng số Hệ thống &rarr;</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                        <h3 class="font-extrabold text-base mb-2 flex items-center gap-2 text-slate-900 dark:text-white">
                            <span class="material-symbols-outlined text-emerald-600">auto_awesome_motion</span>
                            <span>Thiết lập Ưu tiên &amp; Chạy Đề xuất Phân công</span>
                        </h3>
                        <p class="text-xs text-slate-500 mb-4">Chọn tiêu chí ưu tiên phù hợp với tính chất chiến dịch và bấm nút để hệ thống tự động xếp ca.</p>
                        <form method="post" action="?view=coordinator&activity_id=<?= $selectedActivityId ?>&action=run_scheduler" class="mb-5 space-y-4">
                            <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
                            <div>
                                <label class="text-xs font-bold block mb-1.5 text-slate-700 dark:text-slate-200">Tiêu chí Ưu tiên khi Xếp ca Chiến dịch</label>
                                <select name="priority_mode" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3.5 py-2.5 text-sm font-semibold">
                                    <option value="MAX_COVERAGE" <?= $weightsToUse->priorityMode === 'MAX_COVERAGE' ? 'selected' : '' ?>>Ưu tiên 1: Lấp đầy tối đa số ca trực đang thiếu người</option>
                                    <option value="FAIRNESS" <?= $weightsToUse->priorityMode === 'FAIRNESS' ? 'selected' : '' ?>>Ưu tiên 2: Chia đều số ca trực công bằng giữa các TNV</option>
                                    <option value="SKILL_FIT" <?= $weightsToUse->priorityMode === 'SKILL_FIT' ? 'selected' : '' ?>>Ưu tiên 3: Ưu tiên bảo toàn người có kỹ năng chuyên môn hiếm (Y tế, Lái xe)</option>
                                </select>
                            </div>
                            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 flex flex-wrap justify-between items-center gap-3">
                                <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                                    <input type="checkbox" name="apply_immediately" value="1" checked class="rounded text-[#0057B8]">
                                    <span>Áp dụng kết quả đề xuất vào Bảng phân công ca trực</span>
                                </label>
                                <button type="submit" class="btn btn-primary btn-sm px-4 py-2">
                                    <?= icon('play', 13) ?> Chạy &amp; Áp dụng Đề xuất
                                </button>
                            </div>
                        </form>

                        <div class="text-xs font-bold text-slate-500 mb-2">Biểu đồ số ca được giao của từng Tình nguyện viên (<?= count($ds->volunteers) ?> TNV):</div>
                        <div class="fairness-bars">
                            <?php foreach ($ds->volunteers as $vBar):
                                $cnt = $loadX[$vBar->id] ?? 0;
                                $heightPct = max(8, min(100, (int) round(($cnt / 3.0) * 100)));
                            ?>
                                <div class="fairness-bar-col" title="<?= h($vBar->studentCode) ?> (<?= h($vBar->fullName) ?>): <?= $cnt ?> ca">
                                    <div class="fairness-bar" style="height:<?= $heightPct ?>%;"></div>
                                    <span class="mono text-[10px] text-slate-400"><?= $cnt ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: CHỈ RÕ CA CHƯA ĐỦ NGƯỜI & NGƯỜI CHƯA BỐ TRÍ ĐƯỢC -->
            <div id="tab-understaffed" class="workspace-tab-pane">
                <div class="space-y-6">
                    <!-- PHẦN 1: CÁC CA CHƯA BỐ TRÍ ĐỦ NGƯỜI (SLOT) -->
                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                            <h3 class="font-extrabold text-base flex items-center gap-2">
                                <span class="material-symbols-outlined text-rose-500">warning</span>
                                <span>1. Danh sách Ca trực Chưa Bố trí Đủ người (<?= count($unassignedSlots) ?> ca)</span>
                            </h3>
                            <span class="badge badge-subtle text-xs">Cần bổ sung nhân sự thực tế</span>
                        </div>
                        <?php if (empty($unassignedSlots)): ?>
                            <div class="p-5 text-center text-emerald-600 font-bold bg-emerald-50 dark:bg-emerald-950/30 rounded-xl text-sm">
                                Đã lấp đầy 100% các ca trực (đạt định biên tối thiểu) trong chiến dịch này!
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <?php foreach ($unassignedSlots as $u):
                                    $sh = $u->shiftId !== null ? ($ds->shiftsById[$u->shiftId] ?? null) : null;
                                ?>
                                    <div class="bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 border-l-4 border-l-rose-500 rounded-xl p-4">
                                        <div class="flex justify-between items-start gap-2 mb-2">
                                            <strong class="text-sm">[<?= h($sh?->code ?? ('#' . $u->shiftId)) ?>] <?= h($sh?->title ?? '') ?></strong>
                                            <span class="badge badge-violation mono shrink-0"><?= h($u->reasonCode) ?></span>
                                        </div>
                                        <div class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                            Cần bổ sung <strong><?= h($u->missingCount) ?> người</strong> &middot; <?= h($u->explanationText) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- PHẦN 2: CÁC TÌNH NGUYỆN VIÊN CHƯA BỐ TRÍ ĐƯỢC -->
                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                            <h3 class="font-extrabold text-base flex items-center gap-2">
                                <span class="material-symbols-outlined text-amber-500">person_off</span>
                                <span>2. Danh sách Tình nguyện viên Đã Đăng ký nhưng Chưa Bố trí Được Ca (<?= count($unassignedVolunteers) ?> người)</span>
                            </h3>
                            <span class="badge badge-info text-xs">Lý do cụ thể từng ứng viên</span>
                        </div>
                        <?php if (empty($unassignedVolunteers)): ?>
                            <div class="p-5 text-center text-emerald-600 font-bold bg-emerald-50 dark:bg-emerald-950/30 rounded-xl text-sm">
                                100% tình nguyện viên có đăng ký hợp lệ trong chiến dịch này đều đã được bố trí ít nhất 1 ca trực phù hợp!
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($unassignedVolunteers as $uv):
                                    $vObj = $uv->volunteerId !== null ? ($ds->volunteersById[$uv->volunteerId] ?? null) : null;
                                ?>
                                    <div class="bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 border-l-4 border-l-amber-500 rounded-xl p-4 flex flex-col justify-between gap-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="font-bold text-sm text-slate-900 dark:text-white">
                                                <?= h($vObj?->fullName ?? ('TNV #' . $uv->volunteerId)) ?>
                                                <span class="mono text-xs text-slate-500">(<?= h($vObj?->studentCode ?? '') ?>)</span>
                                            </div>
                                            <span class="badge badge-warning mono shrink-0"><?= h($uv->reasonCode) ?></span>
                                        </div>
                                        <div class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                            <?= h($uv->explanationText) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- TAB 4: BẢNG ĐIỀU KHIỂN PHÂN TÍCH SỐ LIỆU & BI DASHBOARD -->
            <div id="tab-analytics" class="workspace-tab-pane">
                <?php
                $tabReport = $analyticsService->getCampaignFormalReport($selectedActivityId);
                $tabUniv = $tabReport['university_breakdown'];
                $tabStaff = $tabReport['staffing_ratio'];
                $tabSkills = $tabReport['skill_structure'];
                $tabHonored = $tabReport['honored_volunteers'];
                $tabOv = $tabReport['overview'];
                ?>
                <div class="flex flex-col gap-6">
                    <!-- Top Action Bar -->
                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm flex flex-wrap justify-between items-center gap-4">
                        <div>
                            <span class="badge badge-info mb-1.5">Phân tích BI &amp; Tác động Xã hội</span>
                            <h2 class="text-xl font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                                <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400">monitoring</span>
                                <span>Trung tâm Báo cáo Thống kê — <?= h($activeCampaign['title']) ?></span>
                            </h2>
                            <p class="text-xs text-slate-500 mt-0.5">Số liệu trực quan hóa theo trường Đại học / cơ sở Đoàn, tỷ lệ lấp đầy ca trực và cơ cấu kỹ năng chuyên môn</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="?view=report&activity_id=<?= $selectedActivityId ?>" class="btn btn-primary btn-sm flex items-center gap-1.5 shadow-md shadow-blue-600/20">
                                <?= icon('file-text', 14) ?> <span>Báo cáo Tổng kết Đoàn - Hội</span>
                            </a>
                            <a href="?action=export_csv&activity_id=<?= $selectedActivityId ?>" class="btn btn-outline btn-sm flex items-center gap-1.5 text-emerald-600 border-emerald-300 hover:bg-emerald-50 dark:hover:bg-slate-800">
                                <?= icon('download', 14) ?> <span>Xuất CSV Phân công</span>
                            </a>
                            <a href="?action=export_analytics_csv&activity_id=<?= $selectedActivityId ?>" class="btn btn-outline btn-sm flex items-center gap-1.5 text-sky-600 border-sky-300 hover:bg-sky-50 dark:hover:bg-slate-800">
                                <?= icon('table_chart', 14) ?> <span>Xuất CSV Thống kê</span>
                            </a>
                        </div>
                    </div>

                    <!-- 4 Thẻ KPI Tác động -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden shadow-sm">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Tình nguyện viên Huy động</span>
                            <div class="text-2xl font-extrabold text-slate-900 dark:text-white mono"><?= h($tabOv['total_volunteers']) ?> <span class="text-sm font-semibold text-slate-500">TNV</span></div>
                            <span class="text-[11px] text-emerald-600 font-semibold mt-1 block">Tất cả đều đạt chuẩn kỹ năng</span>
                        </div>
                        <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden shadow-sm">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Tỷ lệ Lấp đầy Ca (Staffing)</span>
                            <div class="text-2xl font-extrabold text-[#0057B8] dark:text-sky-400 mono"><?= h($tabStaff['overall']['overall_ratio_percent']) ?>%</div>
                            <span class="text-[11px] text-slate-500 mt-1 block"><?= h($tabStaff['overall']['fully_staffed_count']) ?>/<?= h($tabStaff['overall']['total_shifts']) ?> ca lấp đầy 100%</span>
                        </div>
                        <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden shadow-sm">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Tổng Giờ Cống hiến</span>
                            <div class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mono"><?= h($tabOv['total_hours_contributed']) ?> <span class="text-sm font-semibold text-slate-500">giờ</span></div>
                            <span class="text-[11px] text-slate-500 mt-1 block">Đã đối soát &amp; xác nhận điểm danh</span>
                        </div>
                        <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden shadow-sm">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Cơ sở Đoàn Phối hợp</span>
                            <div class="text-2xl font-extrabold text-purple-600 dark:text-purple-400 mono"><?= h($tabOv['total_universities']) ?> <span class="text-sm font-semibold text-slate-500">đơn vị</span></div>
                            <span class="text-[11px] text-slate-500 mt-1 block">Khối Đại học &amp; Chi đoàn</span>
                        </div>
                    </div>

                    <!-- 2 Khối Lớn: Cống hiến theo Trường/Đoàn + Staffing Ratio -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Khối 1: Theo trường Đại học / cơ sở Đoàn -->
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-center mb-4">
                                    <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                                        <span class="material-symbols-outlined text-[#0057B8]">account_balance</span>
                                        <span>Số liệu Cống hiến theo Cơ sở Đoàn / Trường</span>
                                    </h3>
                                    <span class="text-xs text-slate-500 mono font-semibold">Tỷ lệ đóng góp</span>
                                </div>
                                <div class="space-y-4 mb-4">
                                    <?php foreach ($tabUniv as $u): ?>
                                        <div>
                                            <div class="flex justify-between items-center text-xs mb-1">
                                                <span class="font-bold text-slate-800 dark:text-slate-200"><?= h($u['short_name']) ?></span>
                                                <span class="mono font-semibold text-slate-600 dark:text-slate-400"><?= h($u['total_hours']) ?> giờ (<?= h($u['rate_percent']) ?>%)</span>
                                            </div>
                                            <div class="bi-progress-track">
                                                <div class="bi-progress-bar" style="width: <?= h($u['rate_percent']) ?>%; background-color: <?= h($u['color']) ?>;"></div>
                                            </div>
                                            <div class="flex justify-between text-[11px] text-slate-500 mt-0.5">
                                                <span><?= h($u['volunteer_count']) ?> tình nguyện viên</span>
                                                <span><?= h($u['shift_count']) ?> lượt ca trực</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Khối 2: Tỷ lệ lấp đầy ca trực (Staffing Ratio) -->
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                                    <span class="material-symbols-outlined text-emerald-600">group_work</span>
                                    <span>Tỷ lệ Lấp đầy Ca trực (Staffing Ratio)</span>
                                </h3>
                                <span class="badge badge-valid mono"><?= h($tabStaff['overall']['overall_ratio_percent']) ?>% độ phủ</span>
                            </div>
                            <div class="max-h-[380px] overflow-y-auto pr-1 space-y-3">
                                <?php foreach ($tabStaff['shifts'] as $sf):
                                    $isFull = ($sf['status'] === 'FULL');
                                    $isUnder = ($sf['status'] === 'UNDERSTAFFED');
                                    $barColor = $isFull ? '#059669' : ($isUnder ? '#E11D48' : '#0284C7');
                                ?>
                                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                                        <div class="flex justify-between items-start gap-2 mb-1.5">
                                            <div>
                                                <span class="mono font-extrabold text-xs text-[#0057B8] dark:text-sky-400">[<?= h($sf['shift_code']) ?>]</span>
                                                <strong class="text-xs text-slate-900 dark:text-white ml-1"><?= h($sf['shift_title']) ?></strong>
                                            </div>
                                            <span class="badge <?= $isFull ? 'badge-valid' : ($isUnder ? 'badge-violation' : 'badge-info') ?> text-[10px]">
                                                <?= h($sf['status_label_vi']) ?>
                                            </span>
                                        </div>
                                        <div class="bi-progress-track mb-1.5">
                                            <div class="bi-progress-bar" style="width: <?= min(100.0, (float)$sf['staffing_ratio_percent']) ?>%; background-color: <?= $barColor ?>;"></div>
                                        </div>
                                        <div class="flex justify-between items-center text-[11px] text-slate-500">
                                            <span>Quân số: <strong class="text-slate-800 dark:text-slate-200"><?= h($sf['assigned_count']) ?>/<?= h($sf['max_people']) ?> người</strong> (Tối thiểu: <?= h($sf['min_people']) ?>)</span>
                                            <span class="mono font-bold"><?= h($sf['staffing_ratio_percent']) ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 2 Khối Tiếp theo: Cơ cấu Kỹ năng & Bảng Vinh danh -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Khối 3: Cơ cấu Kỹ năng Chuyên môn -->
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                                    <span class="material-symbols-outlined text-purple-600">psychology</span>
                                    <span>Cơ cấu Kỹ năng Chuyên môn</span>
                                </h3>
                                <span class="badge badge-info mono">Đã kiểm thực: <?= h($tabSkills['summary']['verified_rate_percent']) ?>%</span>
                            </div>
                            <div class="space-y-3.5">
                                <?php foreach ($tabSkills['skills'] as $sk): ?>
                                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 gap-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-lg bg-white dark:bg-slate-700 flex items-center justify-center text-[#0057B8] dark:text-sky-400 border border-slate-200 dark:border-slate-600">
                                                <span class="material-symbols-outlined text-[20px]"><?= h($sk['icon']) ?></span>
                                            </div>
                                            <div>
                                                <div class="font-bold text-xs text-slate-900 dark:text-white"><?= h($sk['skill_name']) ?></div>
                                                <div class="text-[11px] text-slate-500">Đã kiểm thực: <?= h($sk['verified_count']) ?>/<?= h($sk['volunteer_count']) ?> (<?= h($sk['verified_percent']) ?>%)</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm font-extrabold text-slate-900 dark:text-white mono"><?= h($sk['volunteer_count']) ?> TNV</div>
                                            <div class="text-[11px] font-semibold text-emerald-600"><?= h($sk['percentage']) ?>% lực lượng</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Khối 4: Bảng Vinh danh Cá nhân Xuất sắc -->
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                                    <span class="material-symbols-outlined text-amber-500">military_tech</span>
                                    <span>Vinh danh Cá nhân Xuất sắc (Top Cống hiến)</span>
                                </h3>
                                <a href="?view=report&activity_id=<?= $selectedActivityId ?>" class="text-xs font-bold text-[#0057B8] dark:text-sky-400 hover:underline">Xem trong Báo cáo &rarr;</a>
                            </div>
                            <div class="max-h-[380px] overflow-y-auto pr-1">
                                <table class="w-full text-left text-xs border-collapse">
                                    <thead>
                                        <tr class="border-b border-slate-200 dark:border-slate-700 text-slate-400 font-bold uppercase text-[10px]">
                                            <th class="py-2 px-2">#</th>
                                            <th class="py-2 px-2">Họ &amp; Tên</th>
                                            <th class="py-2 px-2">Đơn vị / Chi đoàn</th>
                                            <th class="py-2 px-2 text-right">Giờ cống hiến</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <?php foreach ($tabHonored as $hv): ?>
                                            <tr>
                                                <td class="py-2.5 px-2">
                                                    <?php if ($hv['rank'] === 1): ?>
                                                        <span class="w-5 h-5 rounded-full bg-amber-500 text-white font-extrabold flex items-center justify-center text-[10px]">1</span>
                                                    <?php elseif ($hv['rank'] === 2): ?>
                                                        <span class="w-5 h-5 rounded-full bg-slate-400 text-white font-extrabold flex items-center justify-center text-[10px]">2</span>
                                                    <?php elseif ($hv['rank'] === 3): ?>
                                                        <span class="w-5 h-5 rounded-full bg-amber-700 text-white font-extrabold flex items-center justify-center text-[10px]">3</span>
                                                    <?php else: ?>
                                                        <span class="text-slate-500 mono font-bold pl-1.5"><?= $hv['rank'] ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-2.5 px-2">
                                                    <div class="font-bold text-slate-900 dark:text-white"><?= h($hv['full_name']) ?></div>
                                                    <div class="mono text-[10px] text-slate-400"><?= h($hv['student_code']) ?></div>
                                                </td>
                                                <td class="py-2.5 px-2">
                                                    <span class="badge badge-subtle text-[10px]"><?= h($hv['unit_name']) ?></span>
                                                </td>
                                                <td class="py-2.5 px-2 text-right">
                                                    <span class="mono font-extrabold text-emerald-600 dark:text-emerald-400 text-sm"><?= h($hv['total_hours']) ?></span>
                                                    <span class="text-[10px] text-slate-400">giờ</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

<?php elseif ($view === 'analytics'): ?>
    <!-- ======================================================================
         TRUNG TÂM BÁO CÁO THỐNG KÊ & PHÂN TÍCH TÁC ĐỘNG XÃ HỘI (?view=analytics)
         ====================================================================== -->
    <?php
    $pageReport = $analyticsService->getCampaignFormalReport($selectedActivityId);
    $pUniv = $pageReport['university_breakdown'];
    $pStaff = $pageReport['staffing_ratio'];
    $pSkills = $pageReport['skill_structure'];
    $pHonored = $pageReport['honored_volunteers'];
    $pOv = $pageReport['overview'];
    ?>
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 md:px-12 py-8 page-enter">
        <!-- Breadcrumb & Top Bar -->
        <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
            <div>
                <div class="flex items-center gap-2 text-xs text-slate-500 mb-1">
                    <a href="?view=home" class="hover:text-[#0057B8]">Trang chủ</a>
                    <span>/</span>
                    <a href="?view=coordinator&activity_id=<?= $selectedActivityId ?>" class="hover:text-[#0057B8]">Bàn Điều phối</a>
                    <span>/</span>
                    <span class="text-slate-800 dark:text-slate-200 font-semibold">Phân tích &amp; BI Dashboard</span>
                </div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white flex items-center gap-2.5">
                    <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400 text-[32px]">analytics</span>
                    <span>Trung tâm Báo cáo Thống kê &amp; Tác động Xã hội</span>
                </h1>
                <p class="text-xs md:text-sm text-slate-500 mt-1">Phân tích đa chiều số liệu cống hiến theo trường Đại học / cơ sở Đoàn, tỷ lệ lấp đầy ca trực và cơ cấu kỹ năng chuyên môn</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="?view=report&activity_id=<?= $selectedActivityId ?>" class="btn btn-primary btn-sm flex items-center gap-1.5 shadow-md shadow-blue-600/20">
                    <?= icon('file-text', 14) ?> <span>Báo cáo Tổng kết Đoàn - Hội</span>
                </a>
                <a href="?action=export_csv&activity_id=<?= $selectedActivityId ?>" class="btn btn-outline btn-sm flex items-center gap-1.5 text-emerald-600 border-emerald-300 hover:bg-emerald-50 dark:hover:bg-slate-800">
                    <?= icon('download', 14) ?> <span>Xuất CSV Phân công</span>
                </a>
                <a href="?action=export_analytics_csv&activity_id=<?= $selectedActivityId ?>" class="btn btn-outline btn-sm flex items-center gap-1.5 text-sky-600 border-sky-300 hover:bg-sky-50 dark:hover:bg-slate-800">
                    <?= icon('table_chart', 14) ?> <span>Xuất CSV Thống kê</span>
                </a>
            </div>
        </div>

        <!-- Campaign Selector (Scalable cho 100+ chiến dịch) -->
        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-3.5 mb-7 flex flex-wrap items-center justify-between gap-3 shadow-sm">
            <div class="flex items-center gap-2 text-xs font-extrabold text-slate-700 dark:text-slate-200">
                <span class="material-symbols-outlined text-[#0057B8] text-[18px]">campaign</span>
                <span>Chiến dịch đang phân tích: <strong class="text-[#0057B8] dark:text-sky-400"><?= h($activeCampaign['title']) ?></strong></span>
            </div>
            <?= renderScalableCampaignSwitcher($campaignMeta, $selectedActivityId, '?view=analytics&activity_id={id}') ?>
        </div>

        <!-- 4 Thẻ KPI Tác động -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden shadow-sm hover-lift">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Tình nguyện viên Huy động</span>
                <div class="text-3xl font-extrabold text-slate-900 dark:text-white mono mt-1"><?= h($pOv['total_volunteers']) ?> <span class="text-base font-semibold text-slate-500">TNV</span></div>
                <span class="text-xs text-emerald-600 font-semibold mt-2 block">100% đạt chuẩn kỹ năng chuyên môn</span>
            </div>
            <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden shadow-sm hover-lift">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Tỷ lệ Lấp đầy Ca (Staffing)</span>
                <div class="text-3xl font-extrabold text-[#0057B8] dark:text-sky-400 mono mt-1"><?= h($pStaff['overall']['overall_ratio_percent']) ?>%</div>
                <span class="text-xs text-slate-500 mt-2 block"><?= h($pStaff['overall']['fully_staffed_count']) ?>/<?= h($pStaff['overall']['total_shifts']) ?> ca lấp đầy tối đa</span>
            </div>
            <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden shadow-sm hover-lift">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Tổng Giờ Cống hiến</span>
                <div class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 mono mt-1"><?= h($pOv['total_hours_contributed']) ?> <span class="text-base font-semibold text-slate-500">giờ</span></div>
                <span class="text-xs text-slate-500 mt-2 block">Đã đối soát &amp; xác thực điện tử</span>
            </div>
            <div class="bg-white dark:bg-[#131822] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 relative overflow-hidden shadow-sm hover-lift">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Cơ sở Đoàn Phối hợp</span>
                <div class="text-3xl font-extrabold text-purple-600 dark:text-purple-400 mono mt-1"><?= h($pOv['total_universities']) ?> <span class="text-base font-semibold text-slate-500">đơn vị</span></div>
                <span class="text-xs text-slate-500 mt-2 block">Khối Trường Đại học &amp; Chi đoàn</span>
            </div>
        </div>

        <!-- 2 Cột Lớn: Cống hiến theo Trường/Cơ sở Đoàn + Staffing Ratio Table -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Panel 1: Thống kê cống hiến theo trường / cơ sở Đoàn -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                <div class="flex justify-between items-center mb-5">
                    <div>
                        <h2 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-[#0057B8]">account_balance</span>
                            <span>Số liệu Cống hiến theo Cơ sở Đoàn / Trường Đại học</span>
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Phân tích mức độ huy động và giờ tình nguyện theo từng đơn vị</p>
                    </div>
                </div>

                <div class="space-y-4 mb-6">
                    <?php foreach ($pUniv as $u): ?>
                        <div>
                            <div class="flex justify-between items-center text-xs mb-1">
                                <span class="font-bold text-slate-900 dark:text-white"><?= h($u['short_name']) ?></span>
                                <span class="mono font-semibold text-slate-600 dark:text-slate-300"><?= h($u['total_hours']) ?> giờ (<?= h($u['rate_percent']) ?>%)</span>
                            </div>
                            <div class="bi-progress-track">
                                <div class="bi-progress-bar" style="width: <?= h($u['rate_percent']) ?>%; background-color: <?= h($u['color']) ?>;"></div>
                            </div>
                            <div class="flex justify-between text-[11px] text-slate-500 mt-0.5">
                                <span><?= h($u['volunteer_count']) ?> tình nguyện viên &middot; <?= h($u['shift_count']) ?> lượt ca trực</span>
                                <span>Bình quân: <?= h($u['avg_hours_per_vol']) ?> giờ/người</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Bảng chi tiết đối chiếu -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-400 font-bold uppercase text-[10px]">
                                <th class="py-2 px-2">Cơ sở Đoàn</th>
                                <th class="py-2 px-2 text-center">TNV</th>
                                <th class="py-2 px-2 text-center">Lượt Ca</th>
                                <th class="py-2 px-2 text-right">Tổng Giờ</th>
                                <th class="py-2 px-2 text-right">Tỷ Lệ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($pUniv as $u): ?>
                                <tr>
                                    <td class="py-2 px-2 font-medium text-slate-900 dark:text-white"><?= h($u['short_name']) ?></td>
                                    <td class="py-2 px-2 text-center mono"><?= h($u['volunteer_count']) ?></td>
                                    <td class="py-2 px-2 text-center mono"><?= h($u['shift_count']) ?></td>
                                    <td class="py-2 px-2 text-right mono font-bold text-emerald-600"><?= h($u['total_hours']) ?></td>
                                    <td class="py-2 px-2 text-right mono font-bold"><?= h($u['rate_percent']) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel 2: Tỷ lệ lấp đầy ca trực (Staffing Ratio) -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                <div class="flex justify-between items-center mb-5">
                    <div>
                        <h2 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-emerald-600">group_work</span>
                            <span>Tỷ lệ Lấp đầy Ca trực (Staffing Ratio)</span>
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">So sánh quân số phân công với định mức tối thiểu và tối đa từng ca</p>
                    </div>
                    <span class="badge badge-valid mono"><?= h($pStaff['overall']['overall_ratio_percent']) ?>% toàn chiến dịch</span>
                </div>

                <div class="max-h-[500px] overflow-y-auto pr-1 space-y-3">
                    <?php foreach ($pStaff['shifts'] as $sf):
                        $isFull = ($sf['status'] === 'FULL');
                        $isUnder = ($sf['status'] === 'UNDERSTAFFED');
                        $barColor = $isFull ? '#059669' : ($isUnder ? '#E11D48' : '#0284C7');
                        $sfFmt = formatShiftTimeRangeVn((int) ($sf['start_epoch'] ?? 0), (int) ($sf['end_epoch'] ?? 0));
                    ?>
                        <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                            <div class="flex justify-between items-start gap-2 mb-2">
                                <div>
                                    <span class="mono font-extrabold text-xs text-[#0057B8] dark:text-sky-400">[<?= h($sf['shift_code']) ?>]</span>
                                    <strong class="text-xs text-slate-900 dark:text-white ml-1"><?= h($sf['shift_title']) ?></strong>
                                    <div class="text-[11px] text-slate-500 mt-0.5"><?= h($sfFmt['full_label']) ?></div>
                                </div>
                                <span class="badge <?= $isFull ? 'badge-valid' : ($isUnder ? 'badge-violation' : 'badge-info') ?> text-[10px]">
                                    <?= h($sf['status_label_vi']) ?>
                                </span>
                            </div>
                            <div class="bi-progress-track mb-2">
                                <div class="bi-progress-bar" style="width: <?= min(100.0, (float)$sf['staffing_ratio_percent']) ?>%; background-color: <?= $barColor ?>;"></div>
                            </div>
                            <div class="flex justify-between items-center text-[11px] text-slate-500">
                                <span>Phân công: <strong class="text-slate-800 dark:text-slate-200"><?= h($sf['assigned_count']) ?>/<?= h($sf['max_people']) ?> người</strong> (Min: <?= h($sf['min_people']) ?> &middot; Cần: <?= h($sf['required_skill']) ?>)</span>
                                <span class="mono font-bold text-xs"><?= h($sf['staffing_ratio_percent']) ?>%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 2 Cột Tiếp theo: Cơ cấu Kỹ năng & Bảng Vinh danh Cá nhân -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Panel 3: Cơ cấu Kỹ năng Chuyên môn -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                <div class="flex justify-between items-center mb-5">
                    <div>
                        <h2 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-purple-600">psychology</span>
                            <span>Cơ cấu Kỹ năng Chuyên môn</span>
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Phân tích năng lực chuyên sâu và tỷ lệ kiểm thực năng lực thực địa</p>
                    </div>
                    <span class="badge badge-info mono">Đã kiểm thực: <?= h($pSkills['summary']['verified_rate_percent']) ?>%</span>
                </div>

                <div class="space-y-4">
                    <?php foreach ($pSkills['skills'] as $sk): ?>
                        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-white dark:bg-slate-700 flex items-center justify-center text-[#0057B8] dark:text-sky-400 border border-slate-200 dark:border-slate-600 shadow-sm">
                                        <span class="material-symbols-outlined text-[22px]"><?= h($sk['icon']) ?></span>
                                    </div>
                                    <div>
                                        <div class="font-bold text-sm text-slate-900 dark:text-white"><?= h($sk['skill_name']) ?></div>
                                        <div class="text-xs text-slate-500">Đã kiểm thực: <strong class="text-emerald-600"><?= h($sk['verified_count']) ?>/<?= h($sk['volunteer_count']) ?></strong> (<?= h($sk['verified_percent']) ?>%)</div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-base font-extrabold text-slate-900 dark:text-white mono"><?= h($sk['volunteer_count']) ?> TNV</div>
                                    <div class="text-xs font-semibold text-emerald-600"><?= h($sk['percentage']) ?>% quân số</div>
                                </div>
                            </div>
                            <!-- Level badges -->
                            <div class="flex items-center gap-1.5 pt-2 border-t border-slate-200/60 dark:border-slate-700/60 text-[11px] text-slate-500">
                                <span class="font-semibold text-slate-600 dark:text-slate-400">Phân bố cấp độ:</span>
                                <?php foreach ($sk['levels'] as $lvl => $cnt): ?>
                                    <span class="px-2 py-0.5 rounded bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 mono text-[10px]">
                                        Cấp <?= $lvl ?>: <strong><?= $cnt ?></strong>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Panel 4: Bảng Vinh danh Cá nhân Xuất sắc -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                <div class="flex justify-between items-center mb-5">
                    <div>
                        <h2 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-amber-500">military_tech</span>
                            <span>Danh sách Vinh danh Cá nhân Xuất sắc</span>
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Top tình nguyện viên có số giờ cống hiến và thành tích nổi bật nhất</p>
                    </div>
                    <a href="?view=report&activity_id=<?= $selectedActivityId ?>" class="btn btn-outline btn-sm flex items-center gap-1">
                        <?= icon('printer', 12) ?> <span>In trong Báo cáo</span>
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-400 font-bold uppercase text-[10px]">
                                <th class="py-2.5 px-3">Hạng</th>
                                <th class="py-2.5 px-3">Họ và Tên</th>
                                <th class="py-2.5 px-3">Đơn vị / Chi đoàn</th>
                                <th class="py-2.5 px-3 text-right">Giờ cống hiến</th>
                                <th class="py-2.5 px-3">Khen thưởng</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($pHonored as $hv): ?>
                                <tr>
                                    <td class="py-3 px-3">
                                        <?php if ($hv['rank'] === 1): ?>
                                            <span class="w-6 h-6 rounded-full bg-gradient-to-br from-amber-400 to-amber-600 text-white font-extrabold flex items-center justify-center text-xs shadow-sm shadow-amber-500/30">1</span>
                                        <?php elseif ($hv['rank'] === 2): ?>
                                            <span class="w-6 h-6 rounded-full bg-slate-400 text-white font-extrabold flex items-center justify-center text-xs">2</span>
                                        <?php elseif ($hv['rank'] === 3): ?>
                                            <span class="w-6 h-6 rounded-full bg-amber-700 text-white font-extrabold flex items-center justify-center text-xs">3</span>
                                        <?php else: ?>
                                            <span class="text-slate-500 mono font-bold pl-2"><?= $hv['rank'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="font-bold text-slate-900 dark:text-white"><?= h($hv['full_name']) ?></div>
                                        <div class="mono text-[10px] text-slate-400"><?= h($hv['student_code']) ?> &middot; <?= h($hv['primary_skill']) ?></div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="badge badge-subtle text-[10px]"><?= h($hv['unit_name']) ?></span>
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <div class="mono font-extrabold text-emerald-600 dark:text-emerald-400 text-sm"><?= h($hv['total_hours']) ?> giờ</div>
                                        <div class="text-[10px] text-slate-400"><?= h($hv['shifts_count']) ?> ca trực</div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="text-[11px] font-semibold text-[#0057B8] dark:text-sky-400"><?= h($hv['title']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

<?php elseif ($view === 'report'): ?>
    <!-- ======================================================================
         BÁO CÁO TỔNG KẾT CHIẾN DỊCH CHUẨN ĐOÀN - HỘI (?view=report)
         ====================================================================== -->
    <?php
    $formalReport = $analyticsService->getCampaignFormalReport($selectedActivityId);
    $fHeader = $formalReport['header'];
    $fOv = $formalReport['overview'];
    $fUniv = $formalReport['university_breakdown'];
    $fStaff = $formalReport['staffing_ratio'];
    $fSkills = $formalReport['skill_structure'];
    $fHonored = $formalReport['honored_volunteers'];
    $fVer = $formalReport['verification'];
    ?>
    <main class="flex-1 max-w-5xl w-full mx-auto px-4 md:px-8 py-8 page-enter">
        <!-- Toolbar Thao tác trên màn hình (Ẩn khi In) -->
        <div class="no-print bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 mb-6 shadow-sm flex flex-wrap justify-between items-center gap-4">
            <div class="flex items-center gap-2 text-xs text-slate-500">
                <a href="?view=analytics&activity_id=<?= $selectedActivityId ?>" class="btn btn-outline btn-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                    <span>Bảng điều khiển BI</span>
                </a>
                <span class="hidden sm:inline">&middot; Mã báo cáo: <strong class="mono text-slate-800 dark:text-slate-200"><?= h($fVer['report_code']) ?></strong></span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()" class="btn btn-primary btn-sm flex items-center gap-1.5 shadow-md shadow-blue-600/25">
                    <?= icon('printer', 14) ?> <span>In Báo Cáo / Xuất PDF Chuẩn</span>
                </button>
                <a href="?action=export_csv&activity_id=<?= $selectedActivityId ?>" class="btn btn-outline btn-sm flex items-center gap-1.5 text-emerald-600 border-emerald-300 hover:bg-emerald-50 dark:hover:bg-slate-800">
                    <?= icon('download', 14) ?> <span>Xuất CSV Phân công</span>
                </a>
                <a href="?action=export_analytics_csv&activity_id=<?= $selectedActivityId ?>" class="btn btn-outline btn-sm flex items-center gap-1.5 text-sky-600 border-sky-300 hover:bg-sky-50 dark:hover:bg-slate-800">
                    <?= icon('table_chart', 14) ?> <span>Xuất CSV Thống kê</span>
                </a>
            </div>
        </div>

        <!-- Biểu mẫu Trang trọng Chuẩn Đoàn - Hội (In ấn khổ A4) -->
        <div class="youth-report-sheet p-8 md:p-12 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-xl transition-all">
            <!-- 1. QUỐC HIỆU, TIÊU NGỮ & CƠ QUAN BAN HÀNH -->
            <div class="grid grid-cols-2 gap-4 pb-6 border-b border-slate-300 dark:border-slate-700">
                <!-- Cơ quan ban hành (Bên trái) -->
                <div>
                    <div class="font-extrabold text-xs tracking-wider uppercase text-slate-900 dark:text-white">
                        <?= h($fHeader['superior_org']) ?>
                    </div>
                    <div class="font-bold text-xs uppercase text-[#0057B8] dark:text-sky-400 mt-0.5">
                        <?= h($fHeader['committee_org']) ?>
                    </div>
                    <div class="text-[11px] font-mono text-slate-500 mt-1">
                        Số: <strong><?= h($fHeader['doc_number']) ?></strong>
                    </div>
                </div>

                <!-- Quốc hiệu & Tiêu ngữ (Bên phải) -->
                <div class="text-center">
                    <div class="font-extrabold text-xs tracking-wider uppercase text-slate-900 dark:text-white">
                        <?= h($fHeader['national_motto_1']) ?>
                    </div>
                    <div class="font-bold text-xs text-slate-800 dark:text-slate-200 mt-0.5">
                        <?= h($fHeader['national_motto_2']) ?>
                    </div>
                    <div class="w-32 h-[1px] bg-slate-400 mx-auto mt-1 mb-2"></div>
                    <div class="text-[11px] italic text-slate-500">
                        <?= h($fHeader['city_date']) ?>
                    </div>
                </div>
            </div>

            <!-- 2. BIỂU TRƯNG ĐOÀN - HỘI & TIÊU ĐỀ BÁO CÁO -->
            <div class="text-center py-8">
                <!-- Cặp Huy hiệu Đoàn TNCS Hồ Chí Minh & Biểu trưng Hội Sinh viên Việt Nam -->
                <div class="flex items-center justify-center gap-6 mb-4">
                    <?= UiIcons::youthUnionEmblem(56) ?>
                    <?= UiIcons::studentAssociationEmblem(56) ?>
                </div>

                <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white uppercase tracking-tight">
                    <?= h($formalReport['title']) ?>
                </h1>
                <div class="text-sm font-bold text-[#0057B8] dark:text-sky-400 mt-1">
                    <?= h($fOv['campaign_title']) ?>
                </div>
                <div class="text-xs italic text-slate-500 mt-1.5 max-w-xl mx-auto">
                    <?= h($formalReport['subtitle']) ?>
                </div>
            </div>

            <!-- 3. PHẦN I: BẢNG TỔNG HỢP SỐ LIỆU CHIẾN DỊCH -->
            <div class="mb-8 page-break-inside-avoid">
                <h2 class="font-extrabold text-sm uppercase text-[#0057B8] dark:text-sky-400 mb-3 flex items-center gap-2 border-b border-blue-200 dark:border-sky-800 pb-1.5">
                    <span>I. BẢNG TỔNG HỢP SỐ LIỆU CHIẾN DỊCH &amp; ĐỘ PHỦ NHÂN LỰC</span>
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border border-slate-300 dark:border-slate-700">
                        <thead class="bg-slate-100 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 font-bold uppercase text-[11px]">
                            <tr>
                                <th class="py-2.5 px-3 border border-slate-300 dark:border-slate-700">Chỉ tiêu / Hạng mục đánh giá</th>
                                <th class="py-2.5 px-3 border border-slate-300 dark:border-slate-700 text-center">Chỉ số đạt được</th>
                                <th class="py-2.5 px-3 border border-slate-300 dark:border-slate-700">Đơn vị tính</th>
                                <th class="py-2.5 px-3 border border-slate-300 dark:border-slate-700">Đánh giá chuẩn Đoàn</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <tr>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-semibold">1. Tổng số ca trực chuyên môn tổ chức</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center mono font-bold text-sm"><?= h($fOv['total_shifts']) ?></td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700">Ca trực</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-emerald-600 font-semibold">100% triển khai đúng lịch trình</td>
                            </tr>
                            <tr>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-semibold">2. Tỷ lệ lấp đầy ca trực (Staffing Ratio)</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center mono font-bold text-sm text-[#0057B8] dark:text-sky-400"><?= h($fOv['staffing_ratio_percent']) ?>%</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700">Phần trăm</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-semibold"><?= h($fOv['fully_staffed_count']) ?>/<?= h($fOv['total_shifts']) ?> ca lấp đầy tối đa</td>
                            </tr>
                            <tr>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-semibold">3. Lực lượng tình nguyện viên huy động</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center mono font-bold text-sm"><?= h($fOv['total_volunteers']) ?></td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700">Đoàn viên, SV</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-emerald-600 font-semibold">100% đạt chuẩn kỹ năng chuyên môn</td>
                            </tr>
                            <tr>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-semibold">4. Tổng giờ tình nguyện cống hiến thực tế</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center mono font-bold text-sm text-emerald-600"><?= h($fOv['total_hours_contributed']) ?></td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700">Giờ cống hiến</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700">Ghi nhận minh bạch vào sổ cái điện tử</td>
                            </tr>
                            <tr>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-semibold">5. Số cơ sở Đoàn / Trường Đại học tham gia</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center mono font-bold text-sm"><?= h($fOv['total_universities']) ?></td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700">Cơ sở Đoàn</td>
                                <td class="py-2 px-3 border border-slate-300 dark:border-slate-700">Liên kết chặt chẽ các trường ĐHQG-HCM</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. PHẦN II: PHÂN TÍCH CƠ CẤU CHUYÊN MÔN & ĐƠN VỊ CỐNG HIẾN -->
            <div class="mb-8 page-break-inside-avoid">
                <h2 class="font-extrabold text-sm uppercase text-[#0057B8] dark:text-sky-400 mb-3 flex items-center gap-2 border-b border-blue-200 dark:border-sky-800 pb-1.5">
                    <span>II. PHÂN BỔ CỐNG HIẾN THEO TRƯỜNG ĐẠI HỌC / CƠ SỞ ĐOÀN</span>
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border border-slate-300 dark:border-slate-700">
                        <thead class="bg-slate-100 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 font-bold uppercase text-[11px]">
                            <tr>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700">STT</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700">Cơ sở Đoàn / Đơn vị phối hợp</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center">Số TNV</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center">Số Ca</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-right">Tổng Giờ Cống Hiến</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-right">Tỷ Lệ Đóng Góp</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <?php $idx = 1; foreach ($fUniv as $u): ?>
                                <tr>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 mono font-bold text-center"><?= $idx++ ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-semibold text-slate-900 dark:text-white"><?= h($u['unit_name']) ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center mono"><?= h($u['volunteer_count']) ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-center mono"><?= h($u['shift_count']) ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-right mono font-bold text-emerald-600"><?= h($u['total_hours']) ?> giờ</td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-right mono font-bold"><?= h($u['rate_percent']) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 5. PHẦN III: DANH SÁCH VINH DANH CÁ NHÂN XUẤT SẮC -->
            <div class="mb-8 page-break-inside-avoid">
                <h2 class="font-extrabold text-sm uppercase text-[#0057B8] dark:text-sky-400 mb-3 flex items-center gap-2 border-b border-blue-200 dark:border-sky-800 pb-1.5">
                    <span>III. DANH SÁCH VINH DANH CÁ NHÂN XUẤT SẮC TIÊU BIỂU</span>
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border border-slate-300 dark:border-slate-700">
                        <thead class="bg-slate-100 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 font-bold uppercase text-[11px]">
                            <tr>
                                <th class="py-2 px-2.5 border border-slate-300 dark:border-slate-700 text-center">STT</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700">Họ và Tên</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700">Mã Sinh Viên</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700">Đơn Vị / Chi Đoàn</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700">Kỹ Năng Nòng Cốt</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-right">Giờ Cống Hiến</th>
                                <th class="py-2 px-3 border border-slate-300 dark:border-slate-700">Đánh Giá Khen Thưởng</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <?php foreach ($fHonored as $hv): ?>
                                <tr>
                                    <td class="py-2 px-2.5 border border-slate-300 dark:border-slate-700 mono font-bold text-center"><?= $hv['rank'] ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-bold text-slate-900 dark:text-white"><?= h($hv['full_name']) ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 mono"><?= h($hv['student_code']) ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700"><?= h($hv['chapter_name']) ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700"><?= h($hv['primary_skill']) ?></td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 text-right mono font-extrabold text-emerald-600"><?= h($hv['total_hours']) ?> giờ</td>
                                    <td class="py-2 px-3 border border-slate-300 dark:border-slate-700 font-semibold text-[#0057B8] dark:text-sky-400"><?= h($hv['title']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 6. PHẦN IV: XÁC THỰC SỔ CÁI BẤT BIẾN & MÃ QR KIỂM THỰC -->
            <div class="pt-6 border-t border-slate-300 dark:border-slate-700 grid grid-cols-1 md:grid-cols-2 gap-8 items-end page-break-inside-avoid">
                <!-- Nơi nhận & Con dấu đại diện (Bên trái) -->
                <div>
                    <div class="text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase mb-2">
                        <em>Nơi nhận:</em>
                    </div>
                    <ul class="text-[11px] text-slate-500 space-y-0.5 list-disc list-inside mb-4">
                        <li>Ban Thường vụ Thành Đoàn TP.HCM (để báo cáo);</li>
                        <li>Đoàn Thanh niên ĐHQG-HCM &amp; các Trường Đại học;</li>
                        <li>Ban Chỉ huy Chiến dịch Tình nguyện Hè;</li>
                        <li>Lưu: Văn phòng Đoàn, Sổ cái Xanh Hub.</li>
                    </ul>

                    <div class="youth-stamp-box">
                        <div>★ BAN CHỈ HUY CHIẾN DỊCH TÌNH NGUYỆN HÈ ★</div>
                        <div class="text-[9px] mt-0.5 font-mono">ĐÃ ĐỐI CHIẾU SỐ LIỆU ĐIỆN TỬ &middot; XÁC THỰC HỢP LỆ</div>
                    </div>
                </div>

                <!-- Chữ ký & Mã QR kiểm thực (Bên phải) -->
                <div class="flex flex-col sm:flex-row items-center justify-end gap-6 text-center sm:text-right">
                    <div>
                        <div class="text-xs font-extrabold uppercase text-slate-900 dark:text-white">
                            <?= h($fVer['signer_title']) ?>
                        </div>
                        <div class="text-xs font-bold text-[#0057B8] dark:text-sky-400 mb-12">
                            <?= h($fVer['signer_position']) ?>
                        </div>
                        <div class="font-extrabold text-sm text-slate-900 dark:text-white underline decoration-slate-400">
                            <?= h($fVer['signer_name']) ?>
                        </div>
                        <div class="text-[10px] text-slate-400 mt-0.5">Bí thư Đoàn &middot; Chỉ huy trưởng Chiến dịch</div>
                    </div>

                    <!-- Khối QR Code Kiểm thực -->
                    <div class="p-2 border border-slate-200 dark:border-slate-700 rounded-xl bg-white shadow-sm shrink-0">
                        <?= $fVer['qr_svg'] ?>
                        <div class="text-[9px] mono font-bold text-slate-600 mt-1">MÃ QR KIỂM THỰC</div>
                        <div class="text-[8px] mono text-slate-400"><?= h($fVer['report_code']) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

<?php elseif ($view === 'verify'): ?>
    <!-- ======================================================================
         CỔNG TRA CỨU CHỨNG NHẬN ĐIỆN TỬ & SỔ CÁI MINH BẠCH (?view=verify)
         ====================================================================== -->
    <?php
    $defaultCertCode = 'XH2026-SV001-9F8A2C';
    $myOwnCerts = [];
    if ($currentRole === 'VOLUNTEER' && $currentVolunteerId !== null) {
        $stmtMyCerts = $pdo->prepare('
            SELECT c.certificate_code AS verify_code, c.total_hours, u.full_name, v.student_code, a.title AS activity_name
              FROM certificates c
              JOIN users u ON u.id = c.volunteer_id
              JOIN volunteers v ON v.user_id = c.volunteer_id
              JOIN activities a ON a.id = c.activity_id
             WHERE c.volunteer_id = :vid
             ORDER BY c.id DESC
        ');
        $stmtMyCerts->execute([':vid' => $currentVolunteerId]);
        $myOwnCerts = $stmtMyCerts->fetchAll();
        if (!empty($myOwnCerts)) {
            $defaultCertCode = (string) $myOwnCerts[0]['verify_code'];
        }
    }
    $certCode = isset($_GET['code']) && trim((string) $_GET['code']) !== ''
        ? trim((string) $_GET['code'])
        : $defaultCertCode;

    // 1. Kiểm tra nếu là mã Báo cáo Tổng kết Chiến dịch chuẩn Đoàn - Hội (XH2026-BC-D...)
    $verifiedReport = null;
    if (preg_match('/^XH2026-BC-D(\d+)/i', $certCode, $m)) {
        $repActId = (int) $m[1];
        try {
            $candidateReport = $analyticsService->getCampaignFormalReport($repActId);
            if (strcasecmp($candidateReport['verification']['report_code'], $certCode) === 0) {
                $verifiedReport = $candidateReport;
            }
        } catch (\Throwable) {
            $verifiedReport = null;
        }
    }

    // 2. Kiểm tra nếu là mã Giấy chứng nhận Tình nguyện viên (XH2026-SV...)
    $certRow = null;
    if ($verifiedReport === null) {
        $stmtCert = $pdo->prepare('
            SELECT c.certificate_code AS verify_code, c.volunteer_id, c.total_hours, c.issued_at,
                   u.full_name, v.student_code, v.chapter_name, a.title AS activity_name
              FROM certificates c
              JOIN users u ON u.id = c.volunteer_id
              JOIN volunteers v ON v.user_id = c.volunteer_id
              JOIN activities a ON a.id = c.activity_id
             WHERE c.certificate_code = :code
             LIMIT 1
        ');
        $stmtCert->execute([':code' => $certCode]);
        $row = $stmtCert->fetch();
        $certRow = is_array($row) ? $row : null;
    }

    $allCerts = [];
    if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)) {
        $allCerts = $pdo->query('
            SELECT c.certificate_code AS verify_code, c.total_hours, u.full_name, v.student_code
              FROM certificates c
              JOIN users u ON u.id = c.volunteer_id
              JOIN volunteers v ON v.user_id = c.volunteer_id
             ORDER BY c.id ASC LIMIT 10
        ')->fetchAll();
    }

    $certMerit = null;
    if ($certRow !== null && isset($gamificationService)) {
        try {
            $certMerit = $gamificationService->calculateVolunteerMerit((int) $certRow['volunteer_id']);
        } catch (\Throwable) {
        }
    }
    ?>
    <main class="flex-1 max-w-4xl w-full mx-auto px-4 md:px-8 py-10 page-enter">
        <div class="mb-8 text-center no-print">
            <span class="badge badge-info mb-2">Cổng Chứng nhận &amp; Xác thực Điện tử</span>
            <h1 class="text-3xl font-extrabold text-slate-900 dark:text-white">
                <?= $currentRole === 'VOLUNTEER' ? 'Chứng nhận Tình nguyện Cá nhân &amp; Tra cứu Mã Xác thực' : 'Tra cứu &amp; Xác thực Giấy Chứng nhận Tình nguyện viên' ?>
            </h1>
            <p class="text-sm text-slate-500 mt-2 max-w-2xl mx-auto">
                Xem, tải về hoặc tra cứu tính hợp lệ của Giấy chứng nhận tham gia hoạt động tình nguyện qua mã số định danh.
            </p>
        </div>

        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
            <div class="flex flex-wrap justify-between items-center gap-3 mb-6 pb-4 border-b border-slate-100 dark:border-slate-800 no-print">
                <h2 class="font-extrabold text-base flex items-center gap-2 text-slate-900 dark:text-white">
                    <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400">workspace_premium</span>
                    <span><?= $verifiedReport !== null ? 'Xác thực Báo cáo Tổng kết Chiến dịch Đoàn - Hội' : 'Chứng nhận Tình nguyện viên Điện tử' ?></span>
                </h2>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-primary flex items-center gap-1.5 shadow-sm">
                    <?= icon('printer', 14) ?> <span>In / Xuất PDF</span>
                </button>
            </div>

            <form method="get" class="no-print flex gap-2 mb-4">
                <input type="hidden" name="view" value="verify">
                <input type="text" name="code" value="<?= h($certCode) ?>" class="flex-1 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:border-[#0057B8] focus:outline-none" placeholder="Nhập mã chứng nhận tình nguyện viên để tra cứu (VD: XH2026-SV001-9F8A2C)..." />
                <button type="submit" class="btn btn-primary px-5 font-bold">Tra cứu</button>
            </form>

            <?php if ($currentRole === 'VOLUNTEER' && !empty($myOwnCerts)): ?>
            <div class="no-print flex flex-wrap items-center gap-1.5 mb-6">
                <span class="text-xs font-bold text-slate-500 mr-1">Chứng nhận của bạn:</span>
                <?php foreach ($myOwnCerts as $mc): ?>
                    <a href="?view=verify&code=<?= urlencode((string) $mc['verify_code']) ?>"
                       class="badge <?= $mc['verify_code'] === $certCode ? 'badge-info' : 'badge-subtle' ?> mono">
                        <?= h($mc['verify_code']) ?> (<?= h($mc['activity_name']) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
            <?php elseif (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
            <div class="no-print flex flex-wrap gap-1.5 mb-6">
                <a href="?view=verify&code=XH2026-BC-D3-F9E13E"
                   class="badge <?= $certCode === 'XH2026-BC-D3-F9E13E' ? 'badge-info' : 'badge-subtle' ?> mono">
                    XH2026-BC-D3-F9E13E (Báo cáo Đoàn - Hội D3)
                </a>
                <?php foreach ($allCerts as $ac): ?>
                    <a href="?view=verify&code=<?= urlencode((string) $ac['verify_code']) ?>"
                       class="badge <?= $ac['verify_code'] === $certCode ? 'badge-info' : 'badge-subtle' ?> mono">
                        <?= h($ac['verify_code']) ?> (<?= h($ac['full_name']) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($verifiedReport !== null): ?>
                <!-- Kết quả xác thực Báo cáo Tổng kết Chiến dịch chuẩn Đoàn - Hội -->
                <div class="certificate-paper border-2 border-blue-600/30 rounded-2xl p-6 bg-gradient-to-b from-white to-blue-50/20">
                    <div class="text-center border-b-2 border-[#0057B8] pb-3 mb-4">
                        <div class="text-xs font-extrabold tracking-widest uppercase text-[#0057B8]">
                            <?= h($verifiedReport['header']['superior_org']) ?>
                        </div>
                        <div class="text-[11px] font-bold text-slate-600 uppercase mt-0.5">
                            <?= h($verifiedReport['header']['committee_org']) ?>
                        </div>
                        <h3 class="text-xl font-extrabold text-slate-900 mt-2 uppercase">
                            XÁC THỰC BÁO CÁO TỔNG KẾT CHIẾN DỊCH HỢP LỆ
                        </h3>
                        <div class="mono text-xs text-emerald-700 font-bold mt-1">
                            ✓ TOÀN VẸN SỐ LIỆU ĐIỆN TỬ &middot; MÃ BÁO CÁO: <?= h($verifiedReport['verification']['report_code']) ?>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <div class="text-xs text-slate-500 font-semibold uppercase">Chiến dịch được chứng thực:</div>
                            <div class="text-base font-extrabold text-slate-900"><?= h($verifiedReport['overview']['campaign_title']) ?></div>
                            <div class="text-xs text-slate-500 italic mt-0.5">Số văn bản: <?= h($verifiedReport['header']['doc_number']) ?> &middot; <?= h($verifiedReport['header']['city_date']) ?></div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center py-2 bg-slate-50 rounded-xl border border-slate-200">
                            <div class="p-2">
                                <div class="text-[10px] text-slate-500 uppercase font-bold">Lực lượng</div>
                                <div class="text-base font-extrabold text-slate-900 mono"><?= h($verifiedReport['overview']['total_volunteers']) ?> TNV</div>
                            </div>
                            <div class="p-2">
                                <div class="text-[10px] text-slate-500 uppercase font-bold">Lấp đầy ca</div>
                                <div class="text-base font-extrabold text-[#0057B8] mono"><?= h($verifiedReport['overview']['staffing_ratio_percent']) ?>%</div>
                            </div>
                            <div class="p-2">
                                <div class="text-[10px] text-slate-500 uppercase font-bold">Cống hiến</div>
                                <div class="text-base font-extrabold text-emerald-600 mono"><?= h($verifiedReport['overview']['total_hours_contributed']) ?> giờ</div>
                            </div>
                            <div class="p-2">
                                <div class="text-[10px] text-slate-500 uppercase font-bold">Cơ sở Đoàn</div>
                                <div class="text-base font-extrabold text-purple-600 mono"><?= h($verifiedReport['overview']['total_universities']) ?> đơn vị</div>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 pt-2 border-t border-slate-200">
                            <div>
                                <div class="text-xs text-slate-500">Mã đối chiếu văn bản điện tử:</div>
                                <div class="mono text-[10px] text-slate-700 font-semibold break-all bg-slate-100 p-1.5 rounded border border-slate-200"><?= h($verifiedReport['verification']['sha256_hash']) ?></div>
                                <div class="text-xs font-bold text-slate-800 mt-1.5"><?= h($verifiedReport['verification']['signer_title']) ?> &middot; <?= h($verifiedReport['verification']['signer_name']) ?></div>
                            </div>
                            <div class="shrink-0 p-1.5 border border-slate-200 rounded-lg bg-white">
                                <?= $verifiedReport['verification']['qr_svg'] ?>
                            </div>
                        </div>

                        <?php if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
                        <div class="no-print flex flex-wrap gap-2 pt-2">
                            <a href="?view=report&activity_id=<?= (int)$verifiedReport['overview']['campaign_id'] ?>" class="btn btn-sm btn-primary flex items-center gap-1">
                                <?= icon('file-text', 12) ?> <span>Xem Văn Bản Báo Cáo</span>
                            </a>
                            <a href="?view=analytics&activity_id=<?= (int)$verifiedReport['overview']['campaign_id'] ?>" class="btn btn-sm btn-outline flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">analytics</span> <span>Xem Phân Tích BI</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($certRow !== null): ?>
                <!-- Kết quả xác thực Giấy chứng nhận Tình nguyện viên -->
                <div class="certificate-paper">
                    <div class="text-center border-b-2 border-[#0057B8] pb-3 mb-4">
                        <div class="text-xs font-extrabold tracking-widest uppercase text-[#0057B8]">
                            ĐOÀN TNCS HỒ CHÍ MINH &middot; HỘI SINH VIÊN VIỆT NAM
                        </div>
                        <h3 class="text-2xl font-extrabold text-slate-900 mt-1">
                            GIẤY CHỨNG NHẬN HOẠT ĐỘNG TÌNH NGUYỆN
                        </h3>
                        <div class="mono text-xs text-emerald-700 font-bold mt-1">
                            ✓ XÁC THỰC HỢP LỆ &middot; MÃ SỐ: <?= h($certRow['verify_code']) ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-4 items-center">
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <img src="<?= h(avatarUrlFor((int) $certRow['volunteer_id'], $certRow['full_name'])) ?>" alt="" class="avatar-img-lg">
                                <div>
                                    <div class="text-xl font-extrabold text-slate-900"><?= h($certRow['full_name']) ?></div>
                                    <div class="mono text-xs text-[#0057B8] font-bold"><?= h($certRow['student_code']) ?> &middot; <?= h($certRow['chapter_name']) ?></div>
                                </div>
                            </div>
                            <div class="text-sm text-slate-700 mb-1">Chiến dịch: <strong><?= h($certRow['activity_name']) ?></strong></div>
                            <div class="text-sm font-bold text-emerald-700">Số giờ tình nguyện tích lũy: <?= h($certRow['total_hours']) ?> giờ</div>
                            <?php if ($certMerit !== null && !empty($certMerit['badges'])): ?>
                                <div class="mt-3 pt-3 border-t border-slate-200">
                                    <div class="text-[11px] font-bold text-slate-500 uppercase mb-1.5">Huy hiệu Số Đoàn Thanh niên:</div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach ($certMerit['badges'] as $b): ?>
                                            <?php if (!empty($b['unlocked'])): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-50 border border-amber-200 rounded-lg text-xs font-bold text-amber-800">
                                                    <span class="material-symbols-outlined text-[15px] text-amber-600">military_tech</span>
                                                    <span><?= h($b['title']) ?></span>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-2 border border-slate-200 rounded-lg bg-white">
                            <?= SvgQrGenerator::renderSvg('https://xanhhub.vn/verify?code=' . $certRow['verify_code'], 104) ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Trạng thái không tìm thấy mã tra cứu -->
                <div class="p-6 text-center border-2 border-dashed border-rose-300 dark:border-rose-900/50 rounded-2xl bg-rose-50/50 dark:bg-rose-950/20">
                    <span class="material-symbols-outlined text-rose-500 text-[40px] mb-2">warning</span>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">Không tìm thấy mã chứng thực</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-md mx-auto">
                        Mã <strong class="mono text-rose-600"><?= h($certCode) ?></strong> không tồn tại trong sổ cái điện tử Xanh Hub hoặc chưa được đồng bộ. Vui lòng kiểm tra lại mã số trên văn bản hoặc quét lại mã QR.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </main>
<?php elseif ($view === 'profile'): ?>
    <!-- ======================================================================
         TRANG HỒ SƠ CÁ NHÂN & BAN CHỈ HUY CHUYÊN BIỆT (R3 Requirement)
         ====================================================================== -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 md:px-12 py-8 page-enter">
        <?php if ($currentRole === 'VOLUNTEER'):
            $stmtProfileVol = $pdo->prepare('
                SELECT u.id, u.email, u.full_name, u.role, u.created_at,
                       v.student_code, v.chapter_name, v.phone, v.max_shifts_per_week, v.lat, v.lng
                  FROM users u
                  JOIN volunteers v ON v.user_id = u.id
                 WHERE u.id = :uid
                 LIMIT 1
            ');
            $stmtProfileVol->execute([':uid' => $actorUserId]);
            $volData = $stmtProfileVol->fetch();
            if (!$volData) {
                $volData = [
                    'id' => $actorUserId,
                    'email' => $authUser['email'] ?? '',
                    'full_name' => $authUser['full_name'] ?? 'Tình nguyện viên',
                    'role' => 'VOLUNTEER',
                    'created_at' => date('Y-m-d H:i:s'),
                    'student_code' => 'SV' . sprintf('%03d', $actorUserId),
                    'chapter_name' => 'Chi đoàn Thanh niên TP.HCM',
                    'phone' => '0901234567',
                    'max_shifts_per_week' => 4,
                    'lat' => 10.7769,
                    'lng' => 106.7009,
                ];
            }

            $stmtSkills = $pdo->prepare('
                SELECT s.id AS skill_id, s.code, s.name, s.description,
                       COALESCE(vs.level, 1) AS level,
                       COALESCE(vs.is_verified, FALSE) AS is_verified
                  FROM skills s
                  LEFT JOIN volunteer_skills vs ON vs.skill_id = s.id AND vs.volunteer_id = :uid
                 ORDER BY s.id ASC
            ');
            $stmtSkills->execute([':uid' => $actorUserId]);
            $userSkills = $stmtSkills->fetchAll();
            $userSkillsById = [];
            foreach ($userSkills as $usk) {
                $userSkillsById[(int) $usk['skill_id']] = $usk;
            }

            $merit = $gamificationService->calculateVolunteerMerit($actorUserId);
        ?>
            <!-- BREADCRUMB -->
            <div class="flex items-center gap-2 text-xs text-slate-500 mb-6">
                <a href="?view=home" class="hover:text-[#0057B8] transition-colors">Trang chủ</a>
                <span>/</span>
                <span class="text-slate-800 dark:text-slate-200 font-bold">Hồ sơ Tình nguyện viên Số</span>
            </div>

            <!-- HEADER BANNER & THẺ CĂN CƯỚC SỐ -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-8">
                <!-- THẺ CĂN CƯỚC TÌNH NGUYỆN VIÊN SỐ (Digital Volunteer ID Card) -->
                <div class="lg:col-span-5 bg-gradient-to-br from-[#0057B8] via-[#004494] to-sky-900 text-white rounded-3xl p-6 sm:p-8 shadow-xl relative overflow-hidden flex flex-col justify-between">
                    <div class="absolute -right-12 -top-12 w-48 h-48 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
                    <div class="absolute -left-12 -bottom-12 w-48 h-48 bg-sky-400/20 rounded-full blur-2xl pointer-events-none"></div>

                    <!-- Card Header -->
                    <div class="relative z-10 flex items-start justify-between gap-4 mb-6">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-white/20 backdrop-blur-md flex items-center justify-center text-white border border-white/30">
                                <span class="material-symbols-outlined text-[22px]">verified_user</span>
                            </div>
                            <div>
                                <div class="text-[10px] font-bold uppercase tracking-widest text-sky-200">ĐOÀN TNCS HỒ CHÍ MINH</div>
                                <div class="text-sm font-extrabold tracking-wide">THẺ TÌNH NGUYỆN VIÊN SỐ</div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full bg-emerald-500/20 border border-emerald-400/40 text-emerald-200 font-bold text-[10px] uppercase tracking-wider flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            <span>XÁC THỰC</span>
                        </span>
                    </div>

                    <!-- Card Body -->
                    <div class="relative z-10 flex items-center gap-5 my-2">
                        <img src="<?= h(avatarUrlFor($actorUserId, $volData['full_name'])) ?>" alt="Avatar" class="w-20 h-20 rounded-2xl ring-4 ring-white/30 object-cover shrink-0 shadow-lg" />
                        <div class="min-w-0">
                            <h2 class="text-xl sm:text-2xl font-black truncate tracking-tight text-white"><?= h($volData['full_name']) ?></h2>
                            <div class="font-mono text-xs text-sky-200 mt-1 font-bold"><?= h($volData['student_code']) ?></div>
                            <div class="text-xs text-slate-200 truncate mt-0.5"><?= h($volData['chapter_name']) ?></div>
                        </div>
                    </div>

                    <!-- Card Footer -->
                    <div class="relative z-10 pt-6 mt-6 border-t border-white/15 grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <span class="text-[10px] uppercase text-sky-200 block font-semibold">Email tài khoản</span>
                            <span class="font-medium text-slate-100 truncate block"><?= h($volData['email']) ?></span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase text-sky-200 block font-semibold">Số điện thoại</span>
                            <span class="font-medium text-slate-100 block"><?= h($volData['phone']) ?></span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase text-sky-200 block font-semibold">Hạn mức số ca</span>
                            <span class="font-bold text-emerald-300 block"><?= (int) $volData['max_shifts_per_week'] ?> ca/tuần</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase text-sky-200 block font-semibold">Trạng thái Hồ sơ</span>
                            <span class="font-bold text-emerald-300 block">✓ Đủ điều kiện nhận ca</span>
                        </div>
                    </div>
                </div>

                <!-- BẢNG THÀNH TÍCH & ĐIỂM CỐNG HIẾN (Merit KPIs) -->
                <div class="lg:col-span-7 flex flex-col justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white mb-1 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400">military_tech</span>
                            <span>Bảng Thành tích &amp; Điểm Cống hiến</span>
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Minh bạch giờ cống hiến tình nguyện, điểm đánh giá 360° và chỉ số tín nhiệm Đoàn viên.</p>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover-lift">
                            <div class="text-[11px] font-bold uppercase text-slate-400 mb-1">Giờ Tình nguyện</div>
                            <div class="text-2xl font-black text-[#0057B8] dark:text-sky-400"><?= number_format((float) $merit['total_hours'], 1) ?> <span class="text-xs font-normal">giờ</span></div>
                            <div class="text-[11px] text-slate-500 mt-1">Đã tích lũy</div>
                        </div>
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover-lift">
                            <div class="text-[11px] font-bold uppercase text-slate-400 mb-1">Ca Hoàn thành</div>
                            <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400"><?= (int) ($merit['on_time_checkins'] ?? $merit['assigned_count'] ?? 0) ?> <span class="text-xs font-normal">ca</span></div>
                            <div class="text-[11px] text-slate-500 mt-1">Đúng giờ 100%</div>
                        </div>
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover-lift">
                            <div class="text-[11px] font-bold uppercase text-slate-400 mb-1">Điểm Uy tín</div>
                            <div class="text-2xl font-black text-amber-500 flex items-center gap-1">
                                <span><?= number_format((float) $merit['reputation_score'], 1) ?></span>
                                <span class="material-symbols-outlined text-[18px] filled">star</span>
                            </div>
                            <div class="text-[11px] text-slate-500 mt-1">Đánh giá 360°</div>
                        </div>
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover-lift">
                            <div class="text-[11px] font-bold uppercase text-slate-400 mb-1">Điểm Cống hiến</div>
                            <div class="text-2xl font-black text-rose-500"><?= number_format((float) $merit['xp'], 0) ?> <span class="text-xs font-normal">XP</span></div>
                            <div class="text-[11px] text-slate-500 mt-1">Thuật toán Đoàn</div>
                        </div>
                    </div>

                    <!-- BỘ 6 HUY HIỆU SỐ ĐOÀN THANH NIÊN (Digital Badges) -->
                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="font-extrabold text-sm text-slate-900 dark:text-white flex items-center gap-2">
                                <span class="material-symbols-outlined text-amber-500">workspace_premium</span>
                                <span>Bộ 6 Huy hiệu Số Danh dự Đoàn Thanh niên</span>
                            </h4>
                            <span class="text-xs font-bold text-[#0057B8] dark:text-sky-400">Đã mở: <?= (int) $merit['unlocked_badge_count'] ?> / 6 huy hiệu</span>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                            <?php foreach ($merit['badges'] as $b): ?>
                                <div class="p-3 rounded-xl border text-center transition-all <?= !empty($b['unlocked']) ? 'bg-amber-50/60 dark:bg-amber-950/30 border-amber-300 dark:border-amber-700 shadow-sm' : 'bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-800 opacity-60' ?>">
                                    <div class="w-10 h-10 mx-auto rounded-full flex items-center justify-center mb-1.5 <?= !empty($b['unlocked']) ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-300 ring-2 ring-amber-400/50' : 'bg-slate-200 text-slate-400 dark:bg-slate-700 dark:text-slate-500' ?>">
                                        <span class="material-symbols-outlined text-[20px] <?= !empty($b['unlocked']) ? 'filled' : '' ?>"><?= h($b['icon'] ?? 'military_tech') ?></span>
                                    </div>
                                    <div class="font-bold text-[11px] text-slate-900 dark:text-white leading-tight truncate" title="<?= h($b['name']) ?>"><?= h($b['name']) ?></div>
                                    <span class="text-[9px] font-bold mt-1 inline-block <?= !empty($b['unlocked']) ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400' ?>">
                                        <?= !empty($b['unlocked']) ? 'ĐÃ ĐẠT' : 'CHƯA ĐẠT' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FORM CẬP NHẬT HỒ SƠ & KỸ NĂNG -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                <div class="mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400">tune</span>
                        <span>Cập nhật Thông tin Hồ sơ &amp; Năng lực Chuyên môn</span>
                    </h3>
                    <p class="text-xs text-slate-500 mt-1">Thông tin được lưu tự động vào hồ sơ cá nhân để Ban Chỉ huy sắp xếp ca trực phù hợp nhất với chuyên môn của bạn.</p>
                </div>

                <form id="form-volunteer-profile" method="post" action="?action=update_profile">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-2">Họ và tên Tình nguyện viên *</label>
                            <input type="text" name="full_name" value="<?= h($volData['full_name']) ?>" required class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:border-[#0057B8] focus:outline-none" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-2">Số điện thoại liên lạc</label>
                            <input type="text" name="phone" value="<?= h($volData['phone']) ?>" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:border-[#0057B8] focus:outline-none" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-2">Cơ sở Đoàn / Đơn vị Trường</label>
                            <input type="text" name="chapter_name" value="<?= h($volData['chapter_name']) ?>" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:border-[#0057B8] focus:outline-none" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-2">Hạn mức số ca tối đa / tuần</label>
                            <input type="number" name="max_shifts_per_week" min="1" max="14" value="<?= (int) $volData['max_shifts_per_week'] ?>" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:border-[#0057B8] focus:outline-none" />
                        </div>
                        <input type="hidden" name="lat" value="<?= (float) $volData['lat'] ?>" />
                        <input type="hidden" name="lng" value="<?= (float) $volData['lng'] ?>" />
                    </div>

                    <!-- KỸ NĂNG CHUYÊN MÔN (5 Kỹ năng chuẩn + Kỹ năng bổ sung) -->
                    <div class="mb-8">
                        <label class="block text-xs font-extrabold text-slate-900 dark:text-white uppercase tracking-wider mb-4">
                            Đánh giá Cấp độ Kỹ năng Chuyên môn Đoàn Thanh niên (Cấp 0 - 5) &amp; Bổ sung Kỹ năng Khác
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-4">
                            <?php foreach ($skillCodeById as $skId => $skCode):
                                $skLabel = $skillNameVi[$skCode] ?? $skCode;
                                $skIcon = $skillIconMap[$skCode] ?? 'workspace_premium';
                                $currentLvl = isset($userSkillsById[$skId]) ? (int) $userSkillsById[$skId]['level'] : 0;
                            ?>
                                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                                    <div class="flex items-center gap-2 mb-2 font-bold text-xs text-slate-800 dark:text-slate-200">
                                        <span class="material-symbols-outlined text-[18px] text-[#0057B8] dark:text-sky-400"><?= h($skIcon) ?></span>
                                        <span><?= h($skLabel) ?></span>
                                    </div>
                                    <select name="skills[<?= (int) $skId ?>]" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-bold">
                                        <option value="0" <?= $currentLvl === 0 ? 'selected' : '' ?>>Chưa có kỹ năng</option>
                                        <?php for ($lvl = 1; $lvl <= 5; $lvl++): ?>
                                            <option value="<?= $lvl ?>" <?= $currentLvl === $lvl ? 'selected' : '' ?>>
                                                Cấp <?= $lvl ?> <?= $lvl === 5 ? '(Nòng cốt)' : ($lvl >= 3 ? '(Thành thạo)' : '(Cơ bản)') ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Khối "+ Thêm Kỹ năng Khác" trên trang Hồ sơ (?view=profile) -->
                        <div class="p-4 rounded-2xl bg-blue-50/50 dark:bg-sky-950/20 border border-blue-200 dark:border-sky-800/70 flex flex-col sm:flex-row items-stretch sm:items-end gap-3">
                            <div class="flex-1">
                                <label class="text-xs font-extrabold text-[#0057B8] dark:text-sky-400 block mb-1">
                                    + Thêm Kỹ năng Khác (Ngoài các kỹ năng có sẵn)
                                </label>
                                <input
                                    type="text"
                                    name="custom_skill_name"
                                    placeholder="Nhập tên kỹ năng khác (VD: Ngoại ngữ & Phiên dịch, MC Sự kiện, Nấu ăn hậu cần, Thiết kế...)"
                                    class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3.5 py-2 text-xs"
                                />
                            </div>
                            <div class="w-full sm:w-48">
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">Cấp độ Kỹ năng Khác</label>
                                <select name="custom_skill_level" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-semibold">
                                    <option value="1">Cấp 1 — Cơ bản</option>
                                    <option value="2">Cấp 2 — Đã tập huấn</option>
                                    <option value="3" selected>Cấp 3 — Thành thạo</option>
                                    <option value="4">Cấp 4 — Chuyên sâu</option>
                                    <option value="5">Cấp 5 — Nòng cốt</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                        <button type="submit" class="btn btn-primary px-8 py-3 rounded-xl font-bold text-sm shadow-md shadow-blue-600/25 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">save</span>
                            <span>Lưu thay đổi Hồ sơ</span>
                        </button>
                    </div>
                </form>
            </div>

        <?php else:
            // GIAO DIỆN HỒ SƠ ĐIỀU PHỐI VIÊN / QUẢN TRỊ VIÊN (COORDINATOR / ADMIN)
            $stmtProfileCoord = $pdo->prepare('
                SELECT u.id, u.email, u.full_name, u.role, u.created_at
                  FROM users u
                 WHERE u.id = :uid
                 LIMIT 1
            ');
            $stmtProfileCoord->execute([':uid' => $actorUserId]);
            $coordData = $stmtProfileCoord->fetch() ?: [
                'id' => $actorUserId,
                'email' => $authUser['email'] ?? 'thao.dieuphoi@example.test',
                'full_name' => $authUser['full_name'] ?? 'Nguyễn Phương Thảo',
                'role' => $currentRole,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            $statActivities = (int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
            $statShifts = (int) $pdo->query('SELECT COUNT(*) FROM shifts')->fetchColumn();
            $statAssignments = (int) $pdo->query("SELECT COUNT(*) FROM assignments WHERE status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')")->fetchColumn();
            $statVolunteers = (int) $pdo->query("SELECT COUNT(DISTINCT volunteer_id) FROM assignments WHERE status IN ('ASSIGNED', 'CONFIRMED', 'CHECKED_IN')")->fetchColumn();

            // Coordinator profile: Danh sách Chiến dịch & Hoạt động Đang Hướng dẫn
            $isAdminUser = ($currentRole === 'ADMIN') ? 1 : 0;
            $stmtActivities = $pdo->prepare('
                SELECT a.id, a.code, a.title, a.status, a.location_name,
                       COUNT(DISTINCT s.id) AS shifts_count,
                       COALESCE(SUM(s.min_people), 0) AS total_min_people,
                       COALESCE(SUM(s.max_people), 0) AS total_max_people,
                       COUNT(DISTINCT CASE WHEN asm.status IN (\'ASSIGNED\', \'CONFIRMED\', \'CHECKED_IN\') THEN asm.id END) AS assigned_count
                  FROM activities a
                  LEFT JOIN activity_coordinators ac ON ac.activity_id = a.id
                  LEFT JOIN shifts s ON s.activity_id = a.id
                  LEFT JOIN assignments asm ON asm.shift_id = s.id
                 WHERE (ac.user_id = :uid OR :is_admin = 1)
                 GROUP BY a.id, a.code, a.title, a.status, a.location_name
                 ORDER BY a.id ASC
            ');
            $stmtActivities->execute([':uid' => $actorUserId, ':is_admin' => $isAdminUser]);
            $assignedActivities = $stmtActivities->fetchAll();

            // Fallback: Nếu cán bộ chưa được gán chiến dịch riêng, hiển thị các hoạt động mở
            if (empty($assignedActivities)) {
                $stmtActivitiesFallback = $pdo->query('
                    SELECT a.id, a.code, a.title, a.status, a.location_name,
                           COUNT(DISTINCT s.id) AS shifts_count,
                           COALESCE(SUM(s.min_people), 0) AS total_min_people,
                           COALESCE(SUM(s.max_people), 0) AS total_max_people,
                           COUNT(DISTINCT CASE WHEN asm.status IN (\'ASSIGNED\', \'CONFIRMED\', \'CHECKED_IN\') THEN asm.id END) AS assigned_count
                      FROM activities a
                      LEFT JOIN shifts s ON s.activity_id = a.id
                      LEFT JOIN assignments asm ON asm.shift_id = s.id
                     GROUP BY a.id, a.code, a.title, a.status, a.location_name
                     ORDER BY a.id ASC
                     LIMIT 5
                ');
                $assignedActivities = $stmtActivitiesFallback->fetchAll();
            }
        ?>
            <!-- BREADCRUMB -->
            <div class="flex items-center gap-2 text-xs text-slate-500 mb-6">
                <a href="?view=home" class="hover:text-[#0057B8] transition-colors">Trang chủ</a>
                <span>/</span>
                <span class="text-slate-800 dark:text-slate-200 font-bold">Hồ sơ Ban Chỉ huy Chiến dịch</span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-8">
                <!-- THẺ ĐỊNH DANH BAN CHỈ HUY (Command Staff ID Card) -->
                <div class="lg:col-span-5 bg-gradient-to-br from-slate-900 via-slate-800 to-emerald-950 text-white rounded-3xl p-6 sm:p-8 shadow-xl relative overflow-hidden flex flex-col justify-between border border-slate-700/50">
                    <div class="absolute -right-12 -top-12 w-48 h-48 bg-emerald-500/10 rounded-full blur-2xl pointer-events-none"></div>

                    <!-- Card Header -->
                    <div class="relative z-10 flex items-start justify-between gap-4 mb-6">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-emerald-500/20 backdrop-blur-md flex items-center justify-center text-emerald-400 border border-emerald-500/30">
                                <span class="material-symbols-outlined text-[22px]">admin_panel_settings</span>
                            </div>
                            <div>
                                <div class="text-[10px] font-bold uppercase tracking-widest text-emerald-300">THÀNH ĐOÀN TP. HỒ CHÍ MINH</div>
                                <div class="text-sm font-extrabold tracking-wide">THẺ ĐỊNH DANH BAN CHỈ HUY</div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full bg-emerald-500/20 border border-emerald-400/40 text-emerald-300 font-bold text-[10px] uppercase tracking-wider">
                            <?= h($coordData['role']) ?>
                        </span>
                    </div>

                    <!-- Card Body -->
                    <div class="relative z-10 flex items-center gap-5 my-2">
                        <img src="<?= h(avatarUrlFor($actorUserId, $coordData['full_name'])) ?>" alt="Avatar" class="w-20 h-20 rounded-2xl ring-4 ring-emerald-500/30 object-cover shrink-0 shadow-lg" />
                        <div class="min-w-0">
                            <h2 class="text-xl sm:text-2xl font-black truncate tracking-tight text-white"><?= h($coordData['full_name']) ?></h2>
                            <div class="font-mono text-xs text-emerald-300 mt-1 font-bold">CÁN BỘ ĐIỀU PHỐI</div>
                            <div class="text-xs text-slate-300 truncate mt-0.5">Ban Thường vụ Thành Đoàn TP.HCM</div>
                        </div>
                    </div>

                    <!-- Card Footer -->
                    <div class="relative z-10 pt-6 mt-6 border-t border-white/10 grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <span class="text-[10px] uppercase text-emerald-300 block font-semibold">Email Công vụ</span>
                            <span class="font-medium text-slate-200 truncate block"><?= h($coordData['email']) ?></span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase text-emerald-300 block font-semibold">Vai trò hệ thống</span>
                            <span class="font-bold text-white block"><?= $coordData['role'] === 'ADMIN' ? 'Quản trị viên Đoàn' : 'Điều phối viên Chiến dịch' ?></span>
                        </div>
                        <div class="col-span-2">
                            <span class="text-[10px] uppercase text-emerald-300 block font-semibold">Nhiệm vụ Phụ trách</span>
                            <span class="font-medium text-slate-300 block"><?= $coordData['role'] === 'ADMIN' ? 'Quản trị toàn diện hệ thống, người dùng và sổ cái kiểm toán' : 'Tổ chức hoạt động, duyệt đăng ký, phân công ca trực và điểm danh TNV' ?></span>
                        </div>
                    </div>
                </div>

                <!-- TỔNG QUAN HOẠT ĐỘNG & CHIẾN DỊCH PHỤ TRÁCH -->
                <div class="lg:col-span-7 flex flex-col justify-between gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                                <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400">query_stats</span>
                                <span><?= $coordData['role'] === 'ADMIN' ? 'Tổng quan Vận hành Hệ thống' : 'Tổng quan Hoạt động &amp; Chiến dịch Phụ trách' ?></span>
                            </h3>
                            <p class="text-xs text-slate-500">Theo dõi nhanh số lượng chiến dịch, ca trực đang mở và tình hình phân công tình nguyện viên.</p>
                        </div>
                        <button type="button" data-open-modal="modal-create-activity" id="btn-open-create-activity" class="btn btn-primary btn-sm flex items-center gap-1.5 shadow-sm">
                            <span class="material-symbols-outlined text-[16px]">add_circle</span>
                            <span>Tạo Hoạt động Mới</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover-lift">
                            <div class="text-[11px] font-bold uppercase text-slate-400 mb-1">Chiến dịch Phụ trách</div>
                            <div class="text-2xl font-black text-[#0057B8] dark:text-sky-400"><?= $statActivities ?> <span class="text-xs font-normal">chiến dịch</span></div>
                            <div class="text-[11px] text-slate-500 mt-1">Đang kích hoạt</div>
                        </div>
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover-lift">
                            <div class="text-[11px] font-bold uppercase text-slate-400 mb-1">Tổng số Ca trực</div>
                            <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400"><?= $statShifts ?> <span class="text-xs font-normal">ca</span></div>
                            <div class="text-[11px] text-slate-500 mt-1">Mở tuyển quân</div>
                        </div>
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover-lift">
                            <div class="text-[11px] font-bold uppercase text-slate-400 mb-1">Lượt Phân công</div>
                            <div class="text-2xl font-black text-amber-500"><?= $statAssignments ?> <span class="text-xs font-normal">lượt</span></div>
                            <div class="text-[11px] text-slate-500 mt-1">Đã bố trí thành công</div>
                        </div>
                        <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover-lift">
                            <div class="text-[11px] font-bold uppercase text-slate-400 mb-1">Lực lượng Huy động</div>
                            <div class="text-2xl font-black text-rose-500"><?= $statVolunteers ?> <span class="text-xs font-normal">TNV</span></div>
                            <div class="text-[11px] text-slate-500 mt-1">Đã sẵn sàng</div>
                        </div>
                    </div>

                    <!-- FORM CẬP NHẬT THÔNG TIN BAN CHỈ HUY -->
                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-sm">
                        <form method="post" action="?action=update_profile">
                            <div class="flex flex-col sm:flex-row items-end gap-4">
                                <div class="flex-1 w-full">
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-2">Họ và tên Cán bộ Ban Chỉ huy *</label>
                                    <input type="text" name="full_name" value="<?= h($coordData['full_name']) ?>" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:border-[#0057B8] focus:outline-none" />
                                </div>
                                <button type="submit" class="btn btn-primary px-6 py-2.5 rounded-xl font-bold text-sm shrink-0 flex items-center gap-1.5 shadow-sm">
                                    <span class="material-symbols-outlined text-[18px]">save</span>
                                    <span>Lưu Họ tên</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- DANH SÁCH CHIẾN DỊCH & HOẠT ĐỘNG ĐANG HƯỚNG DẪN -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h4 class="font-extrabold text-sm sm:text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400">campaign</span>
                            <span>Danh sách Chiến dịch &amp; Hoạt động Đang Hướng dẫn</span>
                        </h4>
                        <p class="text-xs text-slate-500 mt-0.5">Tiến độ huy động nhân sự và điều hành các đội hình tình nguyện phụ trách.</p>
                    </div>
                    <span class="badge badge-info text-xs font-mono"><?= count($assignedActivities) ?> HOẠT ĐỘNG</span>
                </div>
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-3 px-3">Mã &amp; Tên Chiến dịch</th>
                                <th class="py-3 px-3">Địa bàn Trọng điểm</th>
                                <th class="py-3 px-3 text-center">Số Ca trực</th>
                                <th class="py-3 px-3">Tiến độ Phân công Quân số</th>
                                <th class="py-3 px-3 text-center">Trạng thái</th>
                                <th class="py-3 px-3 text-right">Thao tác Nhanh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php if (empty($assignedActivities)): ?>
                                <tr>
                                    <td colspan="6" class="py-6 text-center text-slate-400">Chưa có chiến dịch nào được phân công điều phối.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($assignedActivities as $act):
                                    $targetMax = (int) $act['total_max_people'];
                                    $assigned = (int) $act['assigned_count'];
                                    $pct = $targetMax > 0 ? min(100, (int) round(($assigned / $targetMax) * 100)) : 0;
                                ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                        <td class="py-3 px-3">
                                            <div class="font-bold text-slate-900 dark:text-white"><?= h(cleanCampaignTitle((string) $act['title'])) ?></div>
                                            <div class="font-mono text-[11px] text-slate-400"><?= h($act['code']) ?></div>
                                        </td>
                                        <td class="py-3 px-3 text-slate-600 dark:text-slate-300">
                                            <div class="flex items-center gap-1">
                                                <span class="material-symbols-outlined text-[14px] text-slate-400">location_on</span>
                                                <span><?= h($act['location_name'] ?? 'Toàn thành phố') ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 text-center font-mono font-bold text-slate-700 dark:text-slate-200">
                                            <?= (int) $act['shifts_count'] ?> ca
                                        </td>
                                        <td class="py-3 px-3 min-w-[180px]">
                                            <div class="flex items-center justify-between text-[11px] mb-1">
                                                <span class="font-semibold text-slate-700 dark:text-slate-300"><?= $assigned ?> / <?= $targetMax ?> TNV</span>
                                                <span class="font-mono text-emerald-600 dark:text-emerald-400 font-bold"><?= $pct ?>%</span>
                                            </div>
                                            <div class="w-full bg-slate-100 dark:bg-slate-700 h-2 rounded-full overflow-hidden">
                                                <div class="bg-gradient-to-r from-emerald-500 to-[#0057B8] h-full rounded-full transition-all" style="width: <?= $pct ?>%;"></div>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 text-center">
                                            <span class="badge badge-success text-[10px]"><?= ($act['status'] === 'ACTIVE' || $act['status'] === 'OPEN') ? 'Đang triển khai' : h($act['status']) ?></span>
                                        </td>
                                        <td class="py-3 px-3 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1.5">
                                                <a href="?view=coordinator&amp;activity_id=<?= (int)$act['id'] ?>" class="btn btn-outline btn-xs flex items-center gap-1" title="Bàn Điều phối">
                                                    <span class="material-symbols-outlined text-[14px]">tune</span>
                                                    <span>Bàn Điều phối</span>
                                                </a>
                                                <a href="?view=report&amp;activity_id=<?= (int)$act['id'] ?>" class="btn btn-outline btn-xs flex items-center gap-1" title="Báo cáo Đoàn">
                                                    <span class="material-symbols-outlined text-[14px]">description</span>
                                                    <span>Báo cáo</span>
                                                </a>
                                                <a href="?view=analytics&amp;activity_id=<?= (int)$act['id'] ?>" class="btn btn-outline btn-xs flex items-center gap-1" title="Phân tích BI">
                                                    <span class="material-symbols-outlined text-[14px]">analytics</span>
                                                    <span>BI</span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
<?php elseif ($view === 'admin'): ?>
    <?php
        // ======================================================================
        // CỔNG QUẢN TRỊ HỆ THỐNG — 4 TAB CHUYÊN BIỆT (R2 Requirement)
        // ======================================================================
        $adminTab = trim((string) ($_GET['tab'] ?? 'users'));
        if (!in_array($adminTab, ['users', 'verification', 'settings', 'audit'], true)) {
            $adminTab = 'users';
        }

        // 1. Quản lý tài khoản
        $allUsers = $pdo->query('
            SELECT u.id, u.email, u.full_name, u.role, u.created_at,
                   v.student_code, v.chapter_name
              FROM users u
              LEFT JOIN volunteers v ON v.user_id = u.id
             ORDER BY u.id ASC
        ')->fetchAll();

        // 2. Danh sách hoạt động và cán bộ phụ trách
        $adminActivities = $pdo->query('
            SELECT id, code, title, status, location_name
              FROM activities
             ORDER BY id ASC
        ')->fetchAll();

        $coordsRaw = $pdo->query('
            SELECT ac.activity_id, ac.user_id, u.full_name, u.email, u.role
              FROM activity_coordinators ac
              JOIN users u ON u.id = ac.user_id
             ORDER BY ac.activity_id, u.id
        ')->fetchAll();
        $coordinatorsByAct = [];
        foreach ($coordsRaw as $cr) {
            $coordinatorsByAct[(int)$cr['activity_id']][] = $cr;
        }

        $coordinatorCandidates = $pdo->query("
            SELECT id, full_name, email, role
              FROM users
             WHERE role IN ('COORDINATOR', 'ADMIN')
             ORDER BY id ASC
        ")->fetchAll();

        // 3. Kỹ năng tình nguyện viên
        $skillsList = $pdo->query('
            SELECT vs.volunteer_id, vs.skill_id, vs.level, vs.is_verified, vs.verified_by,
                   u.full_name, v.student_code, v.chapter_name, s.name AS skill_name, s.code AS skill_code,
                   uv.full_name AS verified_by_name
              FROM volunteer_skills vs
              JOIN volunteers v ON v.user_id = vs.volunteer_id
              JOIN users u ON u.id = v.user_id
              JOIN skills s ON s.id = vs.skill_id
              LEFT JOIN users uv ON uv.id = vs.verified_by
             ORDER BY vs.is_verified ASC, vs.volunteer_id ASC, vs.skill_id ASC
        ')->fetchAll();

        // 4. Giấy chứng nhận
        $certificatesList = $pdo->query('
            SELECT c.id, c.certificate_code, c.volunteer_id, c.activity_id, c.total_hours, c.issued_at,
                   u.full_name AS volunteer_name, v.student_code,
                   a.title AS activity_title, a.code AS activity_code,
                   ui.full_name AS issuer_name
              FROM certificates c
              JOIN users u ON u.id = c.volunteer_id
              LEFT JOIN volunteers v ON v.user_id = c.volunteer_id
              JOIN activities a ON a.id = c.activity_id
              LEFT JOIN users ui ON ui.id = c.issued_by
             ORDER BY c.id DESC
        ')->fetchAll();

        $volunteersForCert = $pdo->query('
            SELECT u.id, u.full_name, v.student_code
              FROM users u
              JOIN volunteers v ON v.user_id = u.id
             ORDER BY u.id ASC
        ')->fetchAll();

        // 5. Cấu hình hệ thống
        $currSettingsRow = $pdo->query("SELECT value_json FROM settings WHERE key = 'system_config'")->fetchColumn();
        $currSettings = $currSettingsRow ? json_decode((string)$currSettingsRow, true) : [];
        $withdrawDeadline = (int) ($currSettings['withdraw_deadline_hours'] ?? 24);
        $qrRotation = (int) ($currSettings['qr_rotation_seconds'] ?? 30);
        $priorityMode = (string) ($currSettings['priority_mode'] ?? 'MAX_COVERAGE');
        $wPriority = (float) ($currSettings['weights']['w_priority'] ?? 5.0);
        $wPreference = (float) ($currSettings['weights']['w_preference'] ?? 2.0);
        $wSkillWaste = (float) ($currSettings['weights']['w_skill_waste'] ?? 3.0);
        $wLoad = (float) ($currSettings['weights']['w_load'] ?? 1.0);
        $wReputation = (float) ($currSettings['weights']['w_reputation'] ?? 1.5);

        // 6. Sổ cái kiểm toán toàn diện
        $isChainValid = $repo->verifyAuditLogChain();
        $totalAuditRows = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $auditFilterAction = trim((string)($_GET['audit_action'] ?? ''));
        if ($auditFilterAction !== '') {
            $stmtAud = $pdo->prepare('
                SELECT a.id, a.actor_user_id, a.action AS action_type, a.entity_type, a.entity_id, a.reason, a.prev_hash, a.row_hash, a.created_at,
                       u.full_name AS actor_name
                  FROM audit_log a
                  LEFT JOIN users u ON u.id = a.actor_user_id
                 WHERE a.action = :act
                 ORDER BY a.id DESC
                 LIMIT 50
            ');
            $stmtAud->execute([':act' => $auditFilterAction]);
            $auditLogs = $stmtAud->fetchAll();
        } else {
            $auditLogs = $pdo->query('
                SELECT a.id, a.actor_user_id, a.action AS action_type, a.entity_type, a.entity_id, a.reason, a.prev_hash, a.row_hash, a.created_at,
                       u.full_name AS actor_name
                  FROM audit_log a
                  LEFT JOIN users u ON u.id = a.actor_user_id
                 ORDER BY a.id DESC
                 LIMIT 50
            ')->fetchAll();
        }
        $distinctAuditActions = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);
    ?>
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 md:px-8 py-8 page-enter" id="admin-main">
        <!-- BREADCRUMB -->
        <div class="flex items-center gap-2 text-xs text-slate-500 mb-6">
            <a href="?view=home" class="hover:text-[#0057B8] transition-colors">Trang chủ</a>
            <span>/</span>
            <span class="text-slate-800 dark:text-slate-200 font-bold">Cổng Quản trị Hệ thống</span>
        </div>

        <!-- HERO HEADER -->
        <div class="bg-gradient-to-r from-purple-900 via-indigo-900 to-slate-900 text-white rounded-3xl p-6 sm:p-8 shadow-xl mb-8 border border-purple-500/20 relative overflow-hidden">
            <div class="absolute -right-16 -top-16 w-56 h-56 bg-purple-500/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-500/20 border border-purple-400/30 text-purple-200 text-xs font-bold uppercase tracking-wider mb-3">
                        <span class="material-symbols-outlined text-[16px]">shield_person</span>
                        <span>QUYỀN HẠN TỐI CAO — THÀNH ĐOÀN TP. HỒ CHÍ MINH</span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-white">
                        Cổng Quản trị Hệ thống Xanh Hub
                    </h1>
                    <p class="text-sm text-purple-200/80 mt-2 max-w-2xl leading-relaxed">
                        Điều hành tập trung: Quản lý người dùng, phân quyền điều phối viên, thẩm định chuyên môn sinh viên, cấu hình trọng số thuật toán Kuhn-Munkres và giám sát sổ cái kiểm toán SHA-256.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="bg-white/10 backdrop-blur-md rounded-2xl p-4 border border-white/10 text-center min-w-[110px]">
                        <div class="text-[11px] uppercase font-bold text-purple-200">Tài khoản</div>
                        <div class="text-2xl font-black text-white"><?= count($allUsers) ?></div>
                    </div>
                    <div class="bg-white/10 backdrop-blur-md rounded-2xl p-4 border border-white/10 text-center min-w-[110px]">
                        <div class="text-[11px] uppercase font-bold text-purple-200">Sổ cái</div>
                        <div class="text-2xl font-black text-emerald-400"><?= $totalAuditRows ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4 TAB NAVIGATION BAR (R2 Requirement) -->
        <div class="flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 mb-8 overflow-x-auto no-scrollbar" role="tablist">
            <button
                type="button"
                class="admin-tab-btn py-3 px-4 font-bold text-xs sm:text-sm border-b-2 flex items-center gap-2 whitespace-nowrap transition-colors <?= $adminTab === 'users' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400' ?>"
                data-admin-tab="users"
                id="btn-admin-tab-users"
            >
                <span class="material-symbols-outlined text-[18px]">manage_accounts</span>
                <span>Tài khoản &amp; Phân quyền</span>
                <span class="badge badge-subtle font-mono text-[10px]"><?= count($allUsers) ?></span>
            </button>
            <button
                type="button"
                class="admin-tab-btn py-3 px-4 font-bold text-xs sm:text-sm border-b-2 flex items-center gap-2 whitespace-nowrap transition-colors <?= $adminTab === 'verification' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400' ?>"
                data-admin-tab="verification"
                id="btn-admin-tab-verification"
            >
                <span class="material-symbols-outlined text-[18px]">verified</span>
                <span>Xác minh Kỹ năng &amp; Chứng nhận</span>
                <span class="badge badge-subtle font-mono text-[10px]"><?= count($skillsList) ?></span>
            </button>
            <button
                type="button"
                class="admin-tab-btn py-3 px-4 font-bold text-xs sm:text-sm border-b-2 flex items-center gap-2 whitespace-nowrap transition-colors <?= $adminTab === 'settings' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400' ?>"
                data-admin-tab="settings"
                id="btn-admin-tab-settings"
            >
                <span class="material-symbols-outlined text-[18px]">tune</span>
                <span>Cấu hình Tham số &amp; Thuật toán</span>
            </button>
            <button
                type="button"
                class="admin-tab-btn py-3 px-4 font-bold text-xs sm:text-sm border-b-2 flex items-center gap-2 whitespace-nowrap transition-colors <?= $adminTab === 'audit' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400' ?>"
                data-admin-tab="audit"
                id="btn-admin-tab-audit"
            >
                <span class="material-symbols-outlined text-[18px]">history_edu</span>
                <span>Sổ cái Kiểm toán SHA-256</span>
                <span class="badge badge-valid font-mono text-[10px]"><?= $totalAuditRows ?></span>
            </button>
        </div>

        <!-- ==================================================================
             TAB 1 — QUẢN LÝ TÀI KHOẢN & PHÂN QUYỀN (#admin-tab-users)
             ================================================================== -->
        <div id="admin-tab-users" class="admin-tab-panel <?= $adminTab === 'users' ? '' : 'hidden' ?> space-y-8">
            <!-- PHÂN VÙNG 1: DANH SÁCH TÀI KHOẢN & CẬP NHẬT VAI TRÒ -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">group</span>
                            <span>Danh sách Tài khoản Người dùng &amp; Phân bổ Vai trò</span>
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Nâng hạ quyền hệ thống (VOLUNTEER, COORDINATOR, ADMIN) có ghi nhận nhật ký kiểm toán SHA-256.</p>
                    </div>
                    <span class="badge badge-info text-xs font-mono"><?= count($allUsers) ?> NGƯỜI DÙNG</span>
                </div>

                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-2.5 px-3">#ID</th>
                                <th class="py-2.5 px-3">Người dùng</th>
                                <th class="py-2.5 px-3">Email liên hệ</th>
                                <th class="py-2.5 px-3">Mã SV / Chi đoàn</th>
                                <th class="py-2.5 px-3 text-center">Vai trò hiện tại</th>
                                <th class="py-2.5 px-3 text-right">Cập nhật Vai trò</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($allUsers as $u): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                    <td class="py-2.5 px-3 font-mono text-slate-400">#<?= (int) $u['id'] ?></td>
                                    <td class="py-2.5 px-3 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <img src="<?= h(avatarUrlFor((int)$u['id'], $u['full_name'])) ?>" alt="Avatar" class="w-7 h-7 rounded-full object-cover shrink-0" />
                                            <div class="font-bold text-slate-900 dark:text-white"><?= h($u['full_name']) ?></div>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-600 dark:text-slate-300 font-mono"><?= h($u['email']) ?></td>
                                    <td class="py-2.5 px-3 text-slate-600 dark:text-slate-300">
                                        <?php if (!empty($u['student_code'])): ?>
                                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200"><?= h($u['student_code']) ?></span>
                                            <span class="text-[11px] text-slate-400 block"><?= h($u['chapter_name'] ?? '') ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">Cán bộ chuyên trách</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <?php if ($u['role'] === 'ADMIN'): ?>
                                            <span class="px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 font-bold text-[10px]">ADMIN</span>
                                        <?php elseif ($u['role'] === 'COORDINATOR'): ?>
                                            <span class="px-2 py-0.5 rounded-full bg-blue-100 dark:bg-sky-950/60 text-[#0057B8] dark:text-sky-300 font-bold text-[10px]">COORDINATOR</span>
                                        <?php else: ?>
                                            <span class="badge badge-success text-[10px]">VOLUNTEER</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-right whitespace-nowrap">
                                        <form method="post" action="?action=admin_update_role" class="inline-flex items-center gap-1.5 justify-end">
                                            <input type="hidden" name="target_user_id" value="<?= (int) $u['id'] ?>">
                                            <select name="new_role" class="bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-1 text-xs focus:outline-none focus:border-purple-600">
                                                <option value="VOLUNTEER" <?= $u['role'] === 'VOLUNTEER' ? 'selected' : '' ?>>TNV</option>
                                                <option value="COORDINATOR" <?= $u['role'] === 'COORDINATOR' ? 'selected' : '' ?>>Điều phối viên</option>
                                                <option value="ADMIN" <?= $u['role'] === 'ADMIN' ? 'selected' : '' ?>>Quản trị viên</option>
                                            </select>
                                            <button type="submit" class="btn btn-outline btn-xs hover:border-purple-600 hover:text-purple-600">Lưu</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PHÂN VÙNG 2: PHÂN CÔNG GIÁO VIÊN HƯỚNG DẪN / ĐIỀU PHỐI THEO HOẠT ĐỘNG -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">assignment_ind</span>
                            <span>Phân công Giáo viên Hướng dẫn &amp; Ban Chỉ huy Chiến dịch</span>
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Gán cán bộ điều phối phụ trách các chiến dịch D1, D2, D3 trong bảng activity_coordinators.</p>
                    </div>
                    <span class="badge badge-info text-xs font-mono"><?= count($adminActivities) ?> CHIẾN DỊCH</span>
                </div>

                <!-- FORM PHÂN CÔNG NHANH -->
                <form method="post" action="?action=admin_assign_coordinator" class="flex flex-wrap items-end gap-3 bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-200 dark:border-slate-700 mb-6">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">Chiến dịch / Hoạt động *</label>
                        <select name="activity_id" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-purple-600">
                            <?php foreach ($adminActivities as $act): ?>
                                <option value="<?= (int) $act['id'] ?>"><?= h($act['code']) ?> — <?= h($act['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">Cán bộ Điều phối viên *</label>
                        <select name="user_id" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-purple-600">
                            <?php foreach ($coordinatorCandidates as $cand): ?>
                                <option value="<?= (int) $cand['id'] ?>"><?= h($cand['full_name']) ?> (<?= h($cand['email']) ?> - <?= h($cand['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="assign_action" value="add">
                    <button type="submit" class="btn btn-primary btn-sm flex items-center gap-1.5 shrink-0 bg-purple-600 hover:bg-purple-700 border-none text-white">
                        <span class="material-symbols-outlined text-[16px]">person_add</span>
                        <span>Phân công Cán bộ</span>
                    </button>
                </form>

                <!-- BẢNG THEO DÕI HOẠT ĐỘNG & ĐIỀU PHỐI VIÊN -->
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-2.5 px-3">Chiến dịch</th>
                                <th class="py-2.5 px-3">Địa bàn Trọng điểm</th>
                                <th class="py-2.5 px-3">Trạng thái</th>
                                <th class="py-2.5 px-3">Cán bộ Phụ trách Hiện tại</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($adminActivities as $act):
                                $cList = $coordinatorsByAct[(int)$act['id']] ?? [];
                            ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                    <td class="py-3 px-3">
                                        <div class="font-bold text-slate-900 dark:text-white"><?= h($act['title']) ?></div>
                                        <div class="font-mono text-[11px] text-slate-400"><?= h($act['code']) ?></div>
                                    </td>
                                    <td class="py-3 px-3 text-slate-600 dark:text-slate-300"><?= h($act['location_name'] ?? 'Toàn thành phố') ?></td>
                                    <td class="py-3 px-3"><span class="badge badge-success text-[10px]"><?= ($act['status'] === 'ACTIVE' || $act['status'] === 'OPEN') ? 'Đang triển khai' : h($act['status']) ?></span></td>
                                    <td class="py-3 px-3">
                                        <?php if (empty($cList)): ?>
                                            <span class="text-slate-400 italic">Chưa phân công cán bộ</span>
                                        <?php else: ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($cList as $c): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-purple-50 dark:bg-purple-950/60 border border-purple-200 dark:border-purple-800 text-xs font-semibold text-purple-700 dark:text-purple-300">
                                                        <span><?= h($c['full_name']) ?></span>
                                                        <form method="post" action="?action=admin_assign_coordinator" class="inline m-0 p-0" onsubmit="return confirm('Gỡ phân công cán bộ <?= h($c['full_name']) ?> khỏi chiến dịch này?');">
                                                            <input type="hidden" name="activity_id" value="<?= (int) $act['id'] ?>">
                                                            <input type="hidden" name="user_id" value="<?= (int) $c['user_id'] ?>">
                                                            <input type="hidden" name="assign_action" value="remove">
                                                            <button type="submit" class="text-rose-500 hover:text-rose-700 ml-1 font-bold" title="Gỡ phân công">&times;</button>
                                                        </form>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================================================================
             TAB 2 — XÁC MINH KỸ NĂNG & DUYỆT CHỨNG NHẬN (#admin-tab-verification)
             ================================================================== -->
        <div id="admin-tab-verification" class="admin-tab-panel <?= $adminTab === 'verification' ? '' : 'hidden' ?> space-y-8">
            <!-- PHÂN VÙNG 1: THẨM ĐỊNH KỸ NĂNG CHUYÊN MÔN -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">verified</span>
                            <span>Thẩm định Kỹ năng Chuyên môn Tình nguyện viên</span>
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Xác minh năng lực thực tế để thuật toán Kuhn-Munkres phân bổ đúng ca trực đòi hỏi kỹ năng.</p>
                    </div>
                    <span class="badge badge-info text-xs font-mono"><?= count($skillsList) ?> NĂNG LỰC</span>
                </div>

                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-2.5 px-3">Tình nguyện viên</th>
                                <th class="py-2.5 px-3">Chi đoàn / Đơn vị</th>
                                <th class="py-2.5 px-3">Kỹ năng Chuyên môn</th>
                                <th class="py-2.5 px-3 text-center">Cấp độ Tự khai</th>
                                <th class="py-2.5 px-3 text-center">Trạng thái</th>
                                <th class="py-2.5 px-3 text-right">Thao tác Thẩm định</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($skillsList as $sk): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                    <td class="py-2.5 px-3 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <img src="<?= h(avatarUrlFor((int)$sk['volunteer_id'], $sk['full_name'])) ?>" alt="Avatar" class="w-7 h-7 rounded-full object-cover shrink-0" />
                                            <div>
                                                <div class="font-bold text-slate-900 dark:text-white"><?= h($sk['full_name']) ?></div>
                                                <div class="font-mono text-[10px] text-slate-400"><?= h($sk['student_code']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-600 dark:text-slate-300"><?= h($sk['chapter_name'] ?? 'Chưa phân chi đoàn') ?></td>
                                    <td class="py-2.5 px-3 font-semibold text-slate-800 dark:text-slate-200">
                                        <div class="flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-[16px] text-purple-600 dark:text-purple-400"><?= h($skillIconMap[$sk['skill_code']] ?? 'verified') ?></span>
                                            <span><?= h($sk['skill_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <span class="font-mono font-bold text-amber-500">★ Cấp <?= (int)$sk['level'] ?>/5</span>
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <?php if ($sk['is_verified']): ?>
                                            <span class="badge badge-success text-[10px]">ĐÃ XÁC MINH</span>
                                            <?php if (!empty($sk['verified_by_name'])): ?>
                                                <div class="text-[10px] text-slate-400 mt-0.5">bởi <?= h($sk['verified_by_name']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-subtle text-[10px]">CHỜ DUYỆT</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-right whitespace-nowrap">
                                        <form method="post" action="?action=admin_verify_skill" class="inline m-0 p-0">
                                            <input type="hidden" name="volunteer_id" value="<?= (int) $sk['volunteer_id'] ?>">
                                            <input type="hidden" name="skill_id" value="<?= (int) $sk['skill_id'] ?>">
                                            <?php if ($sk['is_verified']): ?>
                                                <input type="hidden" name="verified" value="0">
                                                <button type="submit" class="btn btn-outline btn-xs text-rose-500 hover:text-rose-600">Hủy xác minh</button>
                                            <?php else: ?>
                                                <input type="hidden" name="verified" value="1">
                                                <button type="submit" class="btn btn-primary btn-xs flex items-center gap-1 bg-purple-600 hover:bg-purple-700 border-none text-white">
                                                    <span class="material-symbols-outlined text-[14px]">check_circle</span>
                                                    <span>Xác minh</span>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PHÂN VÙNG 2: DUYỆT & CẤP GIẤY CHỨNG NHẬN ĐIỆN TỬ -->
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">workspace_premium</span>
                            <span>Duyệt &amp; Cấp mới Giấy Chứng nhận Giờ Tình nguyện</span>
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Sinh mã chứng nhận điện tử chuẩn Đoàn, tra cứu công khai trên trang ?view=verify.</p>
                    </div>
                    <span class="badge badge-info text-xs font-mono"><?= count($certificatesList) ?> ĐÃ CẤP</span>
                </div>

                <!-- FORM CẤP MỚI CHỨNG NHẬN -->
                <form method="post" action="?action=admin_issue_certificate" class="flex flex-wrap items-end gap-3 bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-200 dark:border-slate-700 mb-6">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">Tình nguyện viên *</label>
                        <select name="volunteer_id" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-purple-600">
                            <?php foreach ($volunteersForCert as $vfc): ?>
                                <option value="<?= (int) $vfc['id'] ?>"><?= h($vfc['student_code']) ?> — <?= h($vfc['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">Chiến dịch / Hoạt động *</label>
                        <select name="activity_id" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-purple-600">
                            <?php foreach ($adminActivities as $act): ?>
                                <option value="<?= (int) $act['id'] ?>"><?= h($act['code']) ?> — <?= h($act['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="w-32">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">Số giờ cống hiến *</label>
                        <input type="number" step="0.5" min="1" max="500" name="total_hours" value="20" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono font-bold" />
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm flex items-center gap-1.5 shrink-0 bg-purple-600 hover:bg-purple-700 border-none text-white">
                        <span class="material-symbols-outlined text-[16px]">workspace_premium</span>
                        <span>Cấp Chứng nhận</span>
                    </button>
                </form>

                <!-- BẢNG GIẤY CHỨNG NHẬN ĐÃ CẤP -->
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-2.5 px-3">Mã Chứng nhận</th>
                                <th class="py-2.5 px-3">Tình nguyện viên</th>
                                <th class="py-2.5 px-3">Chiến dịch</th>
                                <th class="py-2.5 px-3 text-center">Số giờ</th>
                                <th class="py-2.5 px-3">Người cấp</th>
                                <th class="py-2.5 px-3 text-right">Xem &amp; Tra cứu</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php if (empty($certificatesList)): ?>
                                <tr>
                                    <td colspan="6" class="py-6 text-center text-slate-400">Chưa có chứng nhận nào được cấp.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($certificatesList as $cert): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                        <td class="py-2.5 px-3 font-mono font-bold text-purple-600 dark:text-purple-400">
                                            <?= h($cert['certificate_code']) ?>
                                        </td>
                                        <td class="py-2.5 px-3">
                                            <div class="font-bold text-slate-900 dark:text-white"><?= h($cert['volunteer_name']) ?></div>
                                            <div class="font-mono text-[10px] text-slate-400"><?= h($cert['student_code']) ?></div>
                                        </td>
                                        <td class="py-2.5 px-3 text-slate-700 dark:text-slate-300">
                                            <div><?= h(cleanCampaignTitle((string) $cert['activity_title'])) ?></div>
                                            <div class="font-mono text-[10px] text-slate-400"><?= h($cert['activity_code']) ?></div>
                                        </td>
                                        <td class="py-2.5 px-3 text-center font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                            <?= number_format((float)$cert['total_hours'], 1) ?>h
                                        </td>
                                        <td class="py-2.5 px-3 text-slate-500">
                                            <?= h($cert['issuer_name'] ?? 'Hệ thống Thành Đoàn') ?>
                                            <div class="text-[10px] text-slate-400"><?= !empty($cert['issued_at']) ? date('H:i · d/m/Y', strtotime((string)$cert['issued_at'])) : '' ?></div>
                                        </td>
                                        <td class="py-2.5 px-3 text-right">
                                            <a href="?view=verify&amp;code=<?= urlencode($cert['certificate_code']) ?>" class="btn btn-outline btn-xs inline-flex items-center gap-1" target="_blank">
                                                <span class="material-symbols-outlined text-[13px]">open_in_new</span>
                                                <span>Xem chứng thực</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================================================================
             TAB 3 — CẤU HÌNH THAM SỐ & TRỌNG SỐ THUẬT TOÁN (#admin-tab-settings)
             ================================================================== -->
        <div id="admin-tab-settings" class="admin-tab-panel <?= $adminTab === 'settings' ? '' : 'hidden' ?> space-y-8">
            <form method="post" action="?action=admin_save_settings">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- THẺ CẤU HÌNH THỜI GIAN & HỆ THỐNG -->
                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm flex flex-col justify-between">
                        <div>
                            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                                <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">timer</span>
                                <h3 class="font-extrabold text-base text-slate-900 dark:text-white">Quy chuẩn Thời gian &amp; Điểm danh</h3>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">
                                        Hạn chót Rút đăng ký ca trực (Giờ trước giờ bắt đầu)
                                    </label>
                                    <input type="number" min="1" max="168" name="withdraw_deadline_hours" value="<?= $withdrawDeadline ?>" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm font-mono font-bold" />
                                    <p class="text-[11px] text-slate-500 mt-1">Mặc định 24 giờ. Quá thời hạn này, TNV phải nộp đơn báo vắng kèm lý do minh chứng.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">
                                        Chu kỳ Xoay vòng Mã QR Điểm danh (Giây)
                                    </label>
                                    <input type="number" min="10" max="300" name="qr_rotation_seconds" value="<?= $qrRotation ?>" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm font-mono font-bold" />
                                    <p class="text-[11px] text-slate-500 mt-1">Mặc định 30 giây theo chuẩn OTP TOTP chống gian lận và chụp ảnh truyền tay.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">
                                        Chế độ Ưu tiên Thuật toán Mặc định
                                    </label>
                                    <select name="priority_mode" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm font-bold">
                                        <option value="MAX_COVERAGE" <?= $priorityMode === 'MAX_COVERAGE' ? 'selected' : '' ?>>Tối đa hóa Độ phủ Ca trực (MAX_COVERAGE)</option>
                                        <option value="FAIRNESS" <?= $priorityMode === 'FAIRNESS' ? 'selected' : '' ?>>Cân bằng &amp; Công bằng Cơ hội (FAIRNESS)</option>
                                        <option value="PREFERENCE" <?= $priorityMode === 'PREFERENCE' ? 'selected' : '' ?>>Ưu tiên Nguyện vọng TNV (PREFERENCE)</option>
                                        <option value="REPUTATION" <?= $priorityMode === 'REPUTATION' ? 'selected' : '' ?>>Độ Uy tín 360° Đánh giá (REPUTATION)</option>
                                    </select>
                                    <p class="text-[11px] text-slate-500 mt-1">Chế độ điều phối tự động áp dụng khi khởi tạo phân công mới.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- THẺ TRỌNG SỐ THUẬT TOÁN KUHN-MUNKRES -->
                    <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                        <div class="flex items-center gap-2 mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">tune</span>
                            <h3 class="font-extrabold text-base text-slate-900 dark:text-white">Trọng số Ma trận Chi phí Kuhn-Munkres</h3>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="font-bold text-slate-700 dark:text-slate-300">Trọng số Ưu tiên Ca trực (w_priority)</span>
                                    <span class="font-mono text-purple-600 font-bold"><?= number_format($wPriority, 1) ?></span>
                                </div>
                                <input type="number" step="0.1" min="0" max="20" name="w_priority" value="<?= $wPriority ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2 text-xs font-mono" />
                            </div>
                            <div>
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="font-bold text-slate-700 dark:text-slate-300">Trọng số Nguyện vọng TNV (w_preference)</span>
                                    <span class="font-mono text-purple-600 font-bold"><?= number_format($wPreference, 1) ?></span>
                                </div>
                                <input type="number" step="0.1" min="0" max="20" name="w_preference" value="<?= $wPreference ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2 text-xs font-mono" />
                            </div>
                            <div>
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="font-bold text-slate-700 dark:text-slate-300">Phạt Lãng phí Kỹ năng Chuyên môn (w_skill_waste)</span>
                                    <span class="font-mono text-purple-600 font-bold"><?= number_format($wSkillWaste, 1) ?></span>
                                </div>
                                <input type="number" step="0.1" min="0" max="20" name="w_skill_waste" value="<?= $wSkillWaste ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2 text-xs font-mono" />
                            </div>
                            <div>
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="font-bold text-slate-700 dark:text-slate-300">Cân bằng Tải ca trực (w_load)</span>
                                    <span class="font-mono text-purple-600 font-bold"><?= number_format($wLoad, 1) ?></span>
                                </div>
                                <input type="number" step="0.1" min="0" max="20" name="w_load" value="<?= $wLoad ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2 text-xs font-mono" />
                            </div>
                            <div>
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="font-bold text-slate-700 dark:text-slate-300">Trọng số Điểm Uy tín 360° (w_reputation)</span>
                                    <span class="font-mono text-purple-600 font-bold"><?= number_format($wReputation, 1) ?></span>
                                </div>
                                <input type="number" step="0.1" min="0" max="20" name="w_reputation" value="<?= $wReputation ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2 text-xs font-mono" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="submit" class="btn btn-primary px-8 py-3 rounded-2xl font-extrabold text-sm flex items-center gap-2 shadow-lg shadow-purple-600/30 bg-purple-600 hover:bg-purple-700 border-none text-white">
                        <span class="material-symbols-outlined text-[20px]">save</span>
                        <span>Lưu Cấu hình &amp; Trọng số Hệ thống</span>
                    </button>
                </div>
            </form>

            <!-- KHỐI ĐỐI CHIẾU THUẬT TOÁN NỘI BỘ (B0 / B1 / PHƯƠNG ÁN X & SNAPSHOT HASH) DÀNH RIÊNG CHO QUẢN TRỊ VIÊN -->
            <?php
            $adminDs = $repo->loadDatasetForActivity($selectedActivityId);
            $adminPlanB0 = (new FifoUncheckedScheduler())->schedule($adminDs, 202606);
            $adminPlanB1 = (new FifoCheckedScheduler())->schedule($adminDs, 202606);
            $adminWeights = new SchedulerWeights(
                priorityMode: $priorityMode,
                wPriority: $wPriority,
                wPreference: $wPreference,
                wSkillWaste: $wSkillWaste,
                wLoad: $wLoad,
                randomSeed: 202606,
                wReputation: $wReputation
            );
            $adminPlanX = (new XanhHubScheduler())->schedule($adminDs, $adminWeights);
            ?>
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="font-extrabold text-base text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">science</span>
                            <span>Bảng Đối chiếu Thử nghiệm Thuật toán Nội bộ (B0 / B1 / Phương án X)</span>
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Thông số đo lường kỹ thuật hệ thống và kiểm chuẩn độ lệch tải ($\sigma$) dành riêng cho Quản trị viên.</p>
                    </div>
                    <span class="badge badge-info mono text-[10px]">Snapshot SHA-256: <?= h(substr($adminPlanX->snapshotHash, 0, 16)) ?>...</span>
                </div>
                <div class="overflow-x-auto no-scrollbar mb-4">
                    <table class="w-full min-w-[560px] text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-2.5 px-3">Mô hình Thuật toán</th>
                                <th class="py-2.5 px-3">Ca đủ người</th>
                                <th class="py-2.5 px-3">Vi phạm cứng</th>
                                <th class="py-2.5 px-3">Độ lệch chuẩn tải (&sigma;)</th>
                                <th class="py-2.5 px-3">Biên độ ca / TNV</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr>
                                <td class="py-2.5 px-3 font-semibold">Xếp theo thứ tự đăng ký (B0 — Không kiểm tra)</td>
                                <td class="py-2.5 px-3"><span class="badge badge-violation mono"><?= h($adminPlanB0->metrics['valid_covered_shifts']) ?>/<?= h($adminPlanB0->metrics['total_shifts']) ?> (<?= h($adminPlanB0->metrics['valid_coverage_rate']) ?>%)</span></td>
                                <td class="py-2.5 px-3"><span class="badge badge-violation mono"><?= h($adminPlanB0->metrics['total_violations']) ?> lỗi</span></td>
                                <td class="py-2.5 px-3 mono"><?= h($adminPlanB0->metrics['load_std_dev']) ?></td>
                                <td class="py-2.5 px-3 mono"><?= (int) ($adminPlanB0->metrics['min_load_per_volunteer'] ?? 0) ?>–<?= (int) ($adminPlanB0->metrics['max_load_per_volunteer'] ?? 0) ?> ca</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 font-semibold">Xếp theo thứ tự đăng ký (B1 — Có kiểm tra ràng buộc)</td>
                                <td class="py-2.5 px-3"><span class="badge badge-warning mono"><?= h($adminPlanB1->metrics['valid_covered_shifts']) ?>/<?= h($adminPlanB1->metrics['total_shifts']) ?> (<?= h($adminPlanB1->metrics['valid_coverage_rate']) ?>%)</span></td>
                                <td class="py-2.5 px-3"><span class="badge badge-valid mono">0 lỗi</span></td>
                                <td class="py-2.5 px-3 mono"><?= h($adminPlanB1->metrics['load_std_dev']) ?></td>
                                <td class="py-2.5 px-3 mono"><?= (int) ($adminPlanB1->metrics['min_load_per_volunteer'] ?? 0) ?>–<?= (int) ($adminPlanB1->metrics['max_load_per_volunteer'] ?? 0) ?> ca</td>
                            </tr>
                            <tr class="bg-purple-50/50 dark:bg-purple-950/20">
                                <td class="py-2.5 px-3 font-bold text-purple-700 dark:text-purple-300">Tối ưu Đa mục tiêu Xanh Hub (Phương án X)</td>
                                <td class="py-2.5 px-3"><span class="badge badge-valid mono"><?= h($adminPlanX->metrics['valid_covered_shifts']) ?>/<?= h($adminPlanX->metrics['total_shifts']) ?> (<?= h($adminPlanX->metrics['valid_coverage_rate']) ?>%)</span></td>
                                <td class="py-2.5 px-3"><span class="badge badge-valid mono">0 lỗi</span></td>
                                <td class="py-2.5 px-3 mono font-bold"><?= h($adminPlanX->metrics['load_std_dev']) ?></td>
                                <td class="py-2.5 px-3 mono font-bold"><?= (int) ($adminPlanX->metrics['min_load_per_volunteer'] ?? 0) ?>–<?= (int) ($adminPlanX->metrics['max_load_per_volunteer'] ?? 0) ?> ca</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 rounded-xl bg-slate-100/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-[11px] mono text-slate-600 dark:text-slate-300 space-y-1">
                    <div><strong>Snapshot dữ liệu đầu vào (SHA-256):</strong> <?= h($adminPlanX->snapshotHash) ?></div>
                    <div><strong>Cấu hình Trọng số đang áp dụng:</strong> <?= h(json_encode($adminPlanX->config, JSON_UNESCAPED_UNICODE)) ?></div>
                </div>
            </div>
        </div>

        <!-- ==================================================================
             TAB 4 — SỔ CÁI KIỂM TOÁN SHA-256 (#admin-tab-audit)
             ================================================================== -->
        <div id="admin-tab-audit" class="admin-tab-panel <?= $adminTab === 'audit' ? '' : 'hidden' ?> space-y-8">
            <div class="bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm">
                <!-- KHỐI BÁO CÁO TOÀN VẸN CHUỖI BĂM -->
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6 pb-6 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">history_edu</span>
                            <h3 class="font-extrabold text-base text-slate-900 dark:text-white">
                                Sổ cái Kiểm toán Bất biến Chuỗi băm SHA-256 (Audit Log)
                            </h3>
                        </div>
                        <p class="text-xs text-slate-500 mt-1 max-w-2xl">
                            Mỗi hành động nghiệp vụ (phân công, điểm danh, xác nhận, rút lui) đều được liên kết mã băm prev_hash và row_hash chống chối bỏ.
                        </p>
                    </div>

                    <?php if ($isChainValid): ?>
                        <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-400/50 text-emerald-700 dark:text-emerald-300 font-bold text-xs shrink-0">
                            <span class="material-symbols-outlined text-[18px]">verified_user</span>
                            <span>Chuỗi SHA-256 Toàn vẹn 100%</span>
                        </div>
                    <?php else: ?>
                        <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-rose-50 dark:bg-rose-950/60 border border-rose-400/50 text-rose-700 dark:text-rose-300 font-bold text-xs shrink-0">
                            <span class="material-symbols-outlined text-[18px]">gpp_maybe</span>
                            <span>Cảnh báo: Chuỗi băm có sai lệch!</span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- BỘ LỌC HÀNH ĐỘNG -->
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
                    <form method="get" action="" class="flex items-center gap-2 flex-wrap">
                        <input type="hidden" name="view" value="admin">
                        <input type="hidden" name="tab" value="audit">
                        <label class="text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Lọc hành động:</label>
                        <select name="audit_action" class="bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-1.5 text-xs focus:outline-none focus:border-purple-600" onchange="this.form.submit()">
                            <option value="">-- Tất cả hành động (<?= $totalAuditRows ?> bản ghi) --</option>
                            <?php foreach ($distinctAuditActions as $actName): ?>
                                <option value="<?= h($actName) ?>" <?= $auditFilterAction === $actName ? 'selected' : '' ?>><?= h($actName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($auditFilterAction !== ''): ?>
                            <a href="?view=admin&amp;tab=audit" class="text-xs text-rose-500 hover:underline">Xóa lọc</a>
                        <?php endif; ?>
                    </form>

                    <div class="text-xs text-slate-500 font-mono">
                        Hiển thị <?= count($auditLogs) ?> / <?= $totalAuditRows ?> bản ghi
                    </div>
                </div>

                <!-- BẢNG SỔ CÁI KIỂM TOÁN CHI TIẾT -->
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-2.5 px-3 whitespace-nowrap">#ID</th>
                                <th class="py-2.5 px-3 whitespace-nowrap">Thời gian</th>
                                <th class="py-2.5 px-3 whitespace-nowrap">Người thực hiện</th>
                                <th class="py-2.5 px-3 whitespace-nowrap">Hành động</th>
                                <th class="py-2.5 px-3 whitespace-nowrap">Đối tượng</th>
                                <th class="py-2.5 px-3">Lý do nghiệp vụ</th>
                                <th class="py-2.5 px-3 whitespace-nowrap">Mã băm SHA-256</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php if (empty($auditLogs)): ?>
                                <tr>
                                    <td colspan="7" class="py-6 text-center text-slate-400">Không tìm thấy bản ghi kiểm toán phù hợp.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($auditLogs as $aud): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                        <td class="py-2.5 px-3 whitespace-nowrap font-mono text-slate-400">#<?= (int) $aud['id'] ?></td>
                                        <td class="py-2.5 px-3 whitespace-nowrap font-mono text-slate-500"><?= h($aud['created_at']) ?></td>
                                        <td class="py-2.5 px-3 whitespace-nowrap">
                                            <div class="flex items-center gap-1.5">
                                                <img src="<?= h(avatarUrlFor((int)($aud['actor_user_id'] ?? 1), $aud['actor_name'] ?? 'Hệ thống')) ?>" alt="Avatar" class="w-5 h-5 rounded-full object-cover shrink-0" />
                                                <span class="font-medium text-slate-700 dark:text-slate-300"><?= h($aud['actor_name'] ?? 'Hệ thống') ?></span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 px-3 whitespace-nowrap">
                                            <span class="badge badge-subtle font-mono text-[10px]"><?= h($aud['action_type']) ?></span>
                                        </td>
                                        <td class="py-2.5 px-3 whitespace-nowrap font-medium text-slate-800 dark:text-slate-200">
                                            <?= h($aud['entity_type']) ?> #<?= h($aud['entity_id']) ?>
                                        </td>
                                        <td class="py-2.5 px-3 text-slate-600 dark:text-slate-400 max-w-xs truncate" title="<?= h($aud['reason']) ?>">
                                            <?= h($aud['reason']) ?>
                                        </td>
                                        <td class="py-2.5 px-3 whitespace-nowrap font-mono text-[11px] text-slate-400">
                                            <span class="text-purple-600 dark:text-purple-400" title="row_hash: <?= h($aud['row_hash']) ?>"><?= substr(h($aud['row_hash']), 0, 10) ?>...</span>
                                            <?php if (!empty($aud['prev_hash'])): ?>
                                                <span class="text-slate-300 dark:text-slate-600 block text-[9px]" title="prev_hash: <?= h($aud['prev_hash']) ?>">prev: <?= substr(h($aud['prev_hash']), 0, 8) ?>...</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
<?php else: ?>
    <!-- ======================================================================
         TRANG LỖI 404 CHUYÊN NGHIỆP — ĐẬM CHẤT THANH NIÊN (R1 Requirement)
         ====================================================================== -->
    <?php http_response_code(404); ?>
    <main class="flex-1 max-w-5xl w-full mx-auto px-4 md:px-12 py-12 md:py-20 page-enter">
        <div class="text-center max-w-2xl mx-auto mb-12">
            <!-- 404 Animated Hero Badge -->
            <div class="inline-flex items-center justify-center relative mb-6">
                <div class="text-8xl md:text-9xl font-extrabold tracking-tighter text-transparent bg-clip-text bg-gradient-to-br from-[#0057B8] via-sky-500 to-emerald-500 select-none">
                    404
                </div>
                <div class="absolute -bottom-2 bg-white dark:bg-[#131822] px-4 py-1 rounded-full border border-slate-200 dark:border-slate-700 shadow-sm">
                    <span class="text-xs font-mono font-bold text-rose-500 dark:text-rose-400 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">explore_off</span>
                        <span>Mã lỗi: 404 Not Found · Không tìm thấy tuyến đường</span>
                    </span>
                </div>
            </div>

            <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white mb-3">
                Trang bạn tìm kiếm không tồn tại hoặc đã được di chuyển
            </h1>
            <p class="text-sm md:text-base text-slate-600 dark:text-slate-400 mb-8 leading-relaxed">
                Đường dẫn <code class="px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-rose-600 dark:text-rose-400 font-mono text-xs font-bold"><?= h($_SERVER['REQUEST_URI'] ?? ('?view=' . $requestedView)) ?></code> không có trong hệ thống Xanh Hub. Hãy kiểm tra lại địa chỉ hoặc khám phá các hoạt động tình nguyện sôi nổi dưới đây.
            </p>

            <!-- Action Buttons: Back + Home -->
            <div class="flex flex-wrap items-center justify-center gap-3">
                <button type="button" onclick="if(window.history.length > 1){window.history.back();}else{window.location.href='?view=home';}" class="py-3 px-6 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-xl font-bold text-sm transition-all flex items-center gap-2 border border-slate-200 dark:border-slate-700">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                    <span>Quay lại trang trước</span>
                </button>
                <a href="?view=home" class="btn btn-primary py-3 px-6 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-blue-600/25">
                    <span class="material-symbols-outlined text-[18px]">home</span>
                    <span>Về Trang chủ Xanh Hub</span>
                </a>
            </div>
        </div>

        <!-- 4 Subsystem Recommendations (Grid 4 Cột Gợi Ý Phân Hệ Chính) -->
        <div class="border-t border-slate-200 dark:border-slate-800 pt-10">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 text-center mb-6">
                Khám phá 4 phân hệ chính của Cổng thông tin Xanh Hub
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Phân hệ 1: Trang chủ -->
                <a href="?view=home" class="group p-5 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 hover:border-[#0057B8] dark:hover:border-sky-400 rounded-2xl transition-all shadow-sm hover:shadow-md flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-950/50 text-[#0057B8] dark:text-sky-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                            <span class="material-symbols-outlined text-[22px]">home</span>
                        </div>
                        <h3 class="font-bold text-sm text-slate-900 dark:text-white mb-1 group-hover:text-[#0057B8] dark:group-hover:text-sky-400 transition-colors">Trang chủ Xanh Hub</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Tổng quan chiến dịch, 12 ca trực nổi bật và nhịp đập áo xanh thanh niên.</p>
                    </div>
                    <span class="text-xs font-bold text-[#0057B8] dark:text-sky-400 mt-4 flex items-center gap-1">Khám phá &rarr;</span>
                </a>

                <!-- Phân hệ 2: Chiến dịch & Ca trực -->
                <a href="?view=campaigns" class="group p-5 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 hover:border-[#0057B8] dark:hover:border-sky-400 rounded-2xl transition-all shadow-sm hover:shadow-md flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                            <span class="material-symbols-outlined text-[22px]">explore</span>
                        </div>
                        <h3 class="font-bold text-sm text-slate-900 dark:text-white mb-1 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors">Chiến dịch &amp; Ca trực</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Bộ lọc 36 ca trực theo chuyên môn Sơ cứu, Hậu cần, Truyền thông, Giao thông.</p>
                    </div>
                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400 mt-4 flex items-center gap-1">Xem ca trực &rarr;</span>
                </a>

                <!-- Phân hệ 3: Phim & Bản tin Tuổi trẻ -->
                <a href="?view=news" class="group p-5 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 hover:border-[#0057B8] dark:hover:border-sky-400 rounded-2xl transition-all shadow-sm hover:shadow-md flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-950/50 text-rose-500 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                            <span class="material-symbols-outlined text-[22px]">movie</span>
                        </div>
                        <h3 class="font-bold text-sm text-slate-900 dark:text-white mb-1 group-hover:text-rose-500 transition-colors">Phim &amp; Bản tin</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Rạp phim điện ảnh thực địa và nhật ký hành trình tình nguyện viên Việt Nam.</p>
                    </div>
                    <span class="text-xs font-bold text-rose-500 mt-4 flex items-center gap-1">Xem bản tin &rarr;</span>
                </a>

                <!-- Phân hệ 4: Tra cứu Chứng nhận -->
                <a href="?view=verify" class="group p-5 bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-800 hover:border-[#0057B8] dark:hover:border-sky-400 rounded-2xl transition-all shadow-sm hover:shadow-md flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                            <span class="material-symbols-outlined text-[22px]">workspace_premium</span>
                        </div>
                        <h3 class="font-bold text-sm text-slate-900 dark:text-white mb-1 group-hover:text-purple-600 dark:group-hover:text-purple-400 transition-colors">Tra cứu Chứng nhận</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Xác thực giấy chứng nhận điện tử và kiểm tra giờ tình nguyện tích lũy công khai.</p>
                    </div>
                    <span class="text-xs font-bold text-purple-600 dark:text-purple-400 mt-4 flex items-center gap-1">Tra cứu ngay &rarr;</span>
                </a>
            </div>
        </div>
    </main>
<?php endif; ?>

<?php if (!$isAuthPage): ?>
    <!-- ======================================================================
         FOOTER 4 CỘT CHUẨN KIẾN TRÚC TECHPRO (Footer.jsx)
         ====================================================================== -->
    <footer class="bg-white dark:bg-[#131822] border-t border-slate-200 dark:border-slate-800 mt-auto no-print">
        <div class="max-w-7xl mx-auto px-4 md:px-12 py-12">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                <!-- Brand Column -->
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#0057B8] to-sky-500 flex items-center justify-center text-white">
                            <span class="material-symbols-outlined text-[20px] filled">volunteer_activism</span>
                        </div>
                        <span class="font-extrabold text-lg text-slate-900 dark:text-white">XANH<span class="text-[#0057B8] dark:text-sky-400">HUB</span></span>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
                        Nền tảng tổ chức hoạt động và phân công tình nguyện viên theo chuyên môn, bảo vệ lịch học cá nhân và minh bạch hóa giờ cống hiến cộng đồng.
                    </p>
                    <div class="flex space-x-2.5">
                        <a href="?view=home" class="w-9 h-9 bg-slate-100 dark:bg-slate-800 rounded-lg flex items-center justify-center text-slate-500 hover:text-[#0057B8] border border-slate-200 dark:border-slate-700 transition-colors">
                            <span class="material-symbols-outlined text-[18px]">share</span>
                        </a>
                        <a href="?view=verify" class="w-9 h-9 bg-slate-100 dark:bg-slate-800 rounded-lg flex items-center justify-center text-slate-500 hover:text-[#0057B8] border border-slate-200 dark:border-slate-700 transition-colors">
                            <span class="material-symbols-outlined text-[18px]">workspace_premium</span>
                        </a>
                        <a href="?view=about" class="w-9 h-9 bg-slate-100 dark:bg-slate-800 rounded-lg flex items-center justify-center text-slate-500 hover:text-[#0057B8] border border-slate-200 dark:border-slate-700 transition-colors">
                            <span class="material-symbols-outlined text-[18px]">mail</span>
                        </a>
                    </div>
                </div>

                <!-- Chiến dịch -->
                <div>
                    <h3 class="font-bold text-xs text-slate-900 dark:text-white mb-4 uppercase tracking-wider">Chiến dịch Tình nguyện</h3>
                    <ul class="space-y-2.5 text-sm text-slate-500 dark:text-slate-400">
                        <li><a href="?view=campaigns&activity_id=3" class="hover:text-[#0057B8] transition-colors">Chiến dịch Mùa Hè Xanh</a></li>
                        <li><a href="?view=campaigns&activity_id=2" class="hover:text-[#0057B8] transition-colors">Chiến dịch Tiếp Sức Mùa Thi</a></li>
                        <li><a href="?view=campaigns&activity_id=1" class="hover:text-[#0057B8] transition-colors">Chiến dịch Chủ Nhật Xanh</a></li>
                        <li><a href="?view=home#danh-sach-ca-truc" class="hover:text-[#0057B8] transition-colors">Lịch 12 Ca trực Đang mở</a></li>
                    </ul>
                </div>

                <!-- Nhóm kỹ năng -->
                <div>
                    <h3 class="font-bold text-xs text-slate-900 dark:text-white mb-4 uppercase tracking-wider">Nhóm Chuyên môn</h3>
                    <ul class="space-y-2.5 text-sm text-slate-500 dark:text-slate-400">
                        <li><a href="?view=home#nhom-ky-nang" class="hover:text-[#0057B8] transition-colors">Sơ cứu Y tế Điểm thi</a></li>
                        <li><a href="?view=home#nhom-ky-nang" class="hover:text-[#0057B8] transition-colors">Hậu cần &amp; Tiếp sức</a></li>
                        <li><a href="?view=home#nhom-ky-nang" class="hover:text-[#0057B8] transition-colors">Truyền thông Sự kiện</a></li>
                        <li><a href="?view=home#nhom-ky-nang" class="hover:text-[#0057B8] transition-colors">Điều tiết Giao thông</a></li>
                    </ul>
                </div>

                <!-- Thành viên -->
                <div>
                    <h3 class="font-bold text-xs text-slate-900 dark:text-white mb-4 uppercase tracking-wider">Cổng Thành viên</h3>
                    <ul class="space-y-2.5 text-sm text-slate-500 dark:text-slate-400">
                        <?php if ($currentRole === 'PUBLIC'): ?>
                            <li><a href="?view=login" class="hover:text-[#0057B8] transition-colors">Đăng nhập Hệ thống</a></li>
                            <li><a href="?view=register" class="hover:text-[#0057B8] transition-colors">Đăng ký Tình nguyện viên</a></li>
                            <li><a href="?view=leaderboard" class="hover:text-[#0057B8] transition-colors">Bảng Vàng Thi Đua</a></li>
                            <li><a href="?view=verify" class="hover:text-[#0057B8] transition-colors">Tra cứu Chứng nhận Điện tử</a></li>
                        <?php elseif ($currentRole === 'VOLUNTEER'): ?>
                            <li><a href="?view=volunteer&activity_id=<?= (int) $selectedActivityId ?>" class="hover:text-[#0057B8] transition-colors">Sổ tay Ca trực Cá nhân</a></li>
                            <li><a href="?view=profile" class="hover:text-[#0057B8] transition-colors">Hồ sơ &amp; Kỹ năng của tôi</a></li>
                            <li><a href="?view=leaderboard" class="hover:text-[#0057B8] transition-colors">Bảng Vàng Thi Đua</a></li>
                            <li><a href="?view=verify" class="hover:text-[#0057B8] transition-colors">Chứng nhận Tình nguyện Số</a></li>
                        <?php else: ?>
                            <li><a href="?view=coordinator&activity_id=<?= (int) $selectedActivityId ?>" class="hover:text-[#0057B8] transition-colors">Bàn Điều phối Chiến dịch</a></li>
                            <li><a href="?view=analytics&activity_id=<?= (int) $selectedActivityId ?>" class="hover:text-[#0057B8] transition-colors">Trung tâm Thống kê &amp; Báo cáo</a></li>
                            <li><a href="?view=profile" class="hover:text-[#0057B8] transition-colors">Hồ sơ Ban Chỉ huy</a></li>
                            <li><a href="?view=verify" class="hover:text-[#0057B8] transition-colors">Tra cứu Chứng nhận Điện tử</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="border-t border-slate-200 dark:border-slate-800 pt-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
                <p>&copy; 2026 Xanh Hub — Hệ thống Tổ chức Hoạt động &amp; Phân công Tình nguyện viên.</p>
                <div class="flex gap-6">
                    <a href="?view=home" class="hover:text-[#0057B8] transition-colors">Trang chủ</a>
                    <a href="?view=verify" class="hover:text-[#0057B8] transition-colors">Chứng nhận Số</a>
                    <?php if ($currentRole === 'PUBLIC'): ?>
                        <a href="?view=login" class="hover:text-[#0057B8] transition-colors">Đăng nhập</a>
                    <?php else: ?>
                        <a href="?view=profile" class="hover:text-[#0057B8] transition-colors">Tài khoản của tôi</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <!-- Mobile Bottom Navigation Bar (R2 Requirement) -->
    <nav id="mobile-bottom-nav" class="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-white/95 dark:bg-[#131822]/95 backdrop-blur-md border-t border-slate-200 dark:border-slate-800 py-2 px-1 flex items-center justify-around shadow-[0_-4px_16px_rgba(0,0,0,0.06)] no-print" aria-label="Điều hướng di động">
        <a href="?view=home" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'home' ? 'text-[#0057B8] dark:text-sky-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-[#0057B8]' ?>">
            <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'home' ? 'filled' : '' ?>">home</span>
            <span class="truncate max-w-[60px] sm:max-w-none">Trang chủ</span>
        </a>
        <a href="?view=campaigns" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'campaigns' ? 'text-[#0057B8] dark:text-sky-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-[#0057B8]' ?>">
            <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'campaigns' ? 'filled' : '' ?>">explore</span>
            <span class="truncate max-w-[60px] sm:max-w-none">Chiến dịch</span>
        </a>
        <?php if (in_array($currentRole, ['COORDINATOR', 'ADMIN'], true)): ?>
            <a href="?view=coordinator&activity_id=<?= (int) $selectedActivityId ?>" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'coordinator' ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-emerald-600' ?>">
                <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'coordinator' ? 'filled' : '' ?>">admin_panel_settings</span>
                <span class="truncate max-w-[60px] sm:max-w-none">Điều phối</span>
            </a>
            <a href="?view=profile" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'profile' ? 'text-[#0057B8] dark:text-sky-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-[#0057B8]' ?>">
                <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'profile' ? 'filled' : '' ?>">badge</span>
                <span class="truncate max-w-[60px] sm:max-w-none">Hồ sơ BCH</span>
            </a>
        <?php elseif ($currentRole === 'VOLUNTEER'): ?>
            <a href="?view=volunteer&activity_id=<?= (int) $selectedActivityId ?>" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'volunteer' ? 'text-[#0057B8] dark:text-sky-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-[#0057B8]' ?>">
                <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'volunteer' ? 'filled' : '' ?>">badge</span>
                <span class="truncate max-w-[60px] sm:max-w-none">Ca của tôi</span>
            </a>
            <a href="?view=profile" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'profile' ? 'text-[#0057B8] dark:text-sky-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-[#0057B8]' ?>">
                <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'profile' ? 'filled' : '' ?>">person</span>
                <span class="truncate max-w-[60px] sm:max-w-none">Hồ sơ</span>
            </a>
        <?php else: ?>
            <a href="?view=map" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'map' ? 'text-[#0057B8] dark:text-sky-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-[#0057B8]' ?>">
                <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'map' ? 'filled' : '' ?>">map</span>
                <span class="truncate max-w-[60px] sm:max-w-none">Bản đồ</span>
            </a>
            <a href="?view=login" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'login' ? 'text-[#0057B8] dark:text-sky-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-[#0057B8]' ?>">
                <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'login' ? 'filled' : '' ?>">login</span>
                <span class="truncate max-w-[60px] sm:max-w-none">Đăng nhập</span>
            </a>
        <?php endif; ?>
        <a href="?view=verify" class="flex flex-col items-center gap-0.5 text-[10px] sm:text-[11px] whitespace-nowrap shrink-0 <?= $activeNav === 'verify' ? 'text-[#0057B8] dark:text-sky-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-[#0057B8]' ?>">
            <span class="material-symbols-outlined text-[20px] shrink-0 <?= $activeNav === 'verify' ? 'filled' : '' ?>">workspace_premium</span>
            <span class="truncate max-w-[60px] sm:max-w-none">Chứng nhận</span>
        </a>
    </nav>
<?php endif; ?>

<!-- CÁC HỘP THOẠI MODAL NGHIỆP VỤ -->
<div id="modal-ai-shift" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog">
        <div class="flex justify-between items-center mb-3">
            <h3 class="font-extrabold text-base flex items-center gap-2">
                <?= icon('sparkles', 16) ?> Tạo Ca trực Nhanh từ Mô tả Tiếng Việt
            </h3>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-ai-shift"><?= icon('x', 14) ?></button>
        </div>
        <div class="flex gap-2 mb-4">
            <input type="text" id="input-ai-shift-prompt" class="flex-1 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs"
                   value="Cần 3 bạn sơ cứu y tế và 2 bạn hậu cần trực điểm thi Bách Khoa sáng thứ Bảy từ 7h00 đến 11h30, ưu tiên cao">
            <button type="button" id="btn-ai-parse-shift" class="btn btn-primary btn-sm">Điền tự động</button>
        </div>
        <form method="post" action="?view=coordinator&activity_id=<?= $selectedActivityId ?>&action=create_shift_from_draft">
            <input type="hidden" name="activity_id" value="<?= $selectedActivityId ?>">
            <div class="grid grid-cols-2 gap-3 mb-4 text-xs">
                <div><label class="font-bold block mb-1">Tên ca trực</label><input type="text" id="shift-form-title" name="title" value="Trực sơ cứu điểm thi Bách Khoa" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5"></div>
                <div><label class="font-bold block mb-1">Địa điểm</label><input type="text" id="shift-form-zone" name="zone" value="Điểm thi ĐH Bách Khoa" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5"></div>
                <div><label class="font-bold block mb-1">Ngày</label><input type="date" id="shift-form-date" name="date_ymd" value="2026-06-13" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5"></div>
                <div>
                    <label class="font-bold block mb-1">Kỹ năng</label>
                    <select id="shift-form-skill" name="skill_code" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5">
                        <option value="FIRST_AID">Sơ cứu Y tế</option>
                        <option value="LOGISTICS">Hậu cần Tiếp sức</option>
                        <option value="MEDIA">Truyền thông</option>
                        <option value="TRAFFIC">Điều tiết GT</option>
                        <option value="IT_SUPPORT">Kỹ thuật số</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="font-bold block mb-1">Bắt đầu</label><input type="time" id="shift-form-start" name="start_hm" value="07:00" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-1.5"></div>
                    <div><label class="font-bold block mb-1">Kết thúc</label><input type="time" id="shift-form-end" name="end_hm" value="11:30" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-1.5"></div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="font-bold block mb-1">Min người</label><input type="number" id="shift-form-min" name="min_people" value="2" min="1" max="20" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-1.5"></div>
                    <div><label class="font-bold block mb-1">Max người</label><input type="number" id="shift-form-max" name="max_people" value="3" min="1" max="20" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-1.5"></div>
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" data-close-modal="modal-ai-shift">Đóng</button>
                <button type="submit" class="btn btn-success">Lưu Ca trực mới</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-override" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog">
        <div class="flex justify-between items-center mb-3">
            <h3 class="font-extrabold text-base text-amber-600 flex items-center gap-2">
                <?= icon('alert-triangle', 16) ?> Cảnh báo Ngoại lệ (Cần ghi chú lý do)
            </h3>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-override"><?= icon('x', 14) ?></button>
        </div>
        <div id="override-warning-codes" class="mb-2"></div>
        <ul id="override-warning-messages" class="mb-4 ml-5 text-xs text-slate-600 list-disc"></ul>
        <form id="form-confirm-override">
            <label class="text-xs font-bold block mb-1">Lý do ghi đè:</label>
            <textarea id="input-override-reason" rows="3" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs" placeholder="VD: Tình nguyện viên đã xác nhận qua điện thoại..."></textarea>
            <div class="flex justify-end gap-2 mt-4">
                <button type="button" class="btn btn-outline" data-close-modal="modal-override">Hủy</button>
                <button type="submit" class="btn btn-primary">Xác nhận Ghi đè</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-lock-shift" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog">
        <div class="flex justify-between items-center mb-3">
            <h3 id="lock-modal-title" class="font-extrabold text-base">Khóa / Mở khóa Ca trực</h3>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-lock-shift"><?= icon('x', 14) ?></button>
        </div>
        <p id="lock-modal-desc" class="text-xs text-slate-500 mb-3"></p>
        <form id="form-lock-shift">
            <input type="text" id="input-lock-reason" value="Chốt danh sách nhân sự ca trực trước giờ xuất quân" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs">
            <div class="flex justify-end gap-2 mt-4">
                <button type="button" class="btn btn-outline" data-close-modal="modal-lock-shift">Đóng</button>
                <button type="submit" class="btn btn-primary">Xác nhận</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-absence" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog">
        <div class="flex justify-between items-center mb-3">
            <h3 class="font-extrabold text-base flex items-center gap-2">
                <?= icon('refresh', 16) ?> Báo vắng &amp; Đề xuất Người thay thế
            </h3>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-absence"><?= icon('x', 14) ?></button>
        </div>
        <div id="a3-target-summary" class="text-xs text-slate-500 mb-3"></div>
        <form id="form-report-absence">
            <input type="hidden" id="absence-assignment-id" name="assignment_id" value="">
            <div class="mb-3">
                <label class="text-xs font-bold block mb-1">Lý do báo vắng:</label>
                <input type="text" name="reason" value="Bận lịch thi đột xuất / Báo ốm trước giờ trực" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs">
            </div>
            <div id="a3-candidates-container" class="max-h-60 overflow-y-auto mb-4"></div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" data-close-modal="modal-absence">Hủy</button>
                <button type="submit" class="btn btn-success">Xác nhận &amp; Thay người</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-qr-projector" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog text-center" style="max-width:460px;">
        <div class="flex justify-between items-center mb-3">
            <h3 class="font-extrabold text-base flex items-center gap-2">
                <?= icon('qr-code', 16) ?> Máy chiếu Mã QR Điểm danh 30 giây
            </h3>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-qr-projector"><?= icon('x', 14) ?></button>
        </div>
        <div id="qr-projector-shift-title" class="font-bold text-[#0057B8] mb-3 text-sm"></div>
        <div id="live-qr-svg-box" class="inline-block p-4 bg-white border-2 border-[#0057B8] rounded-2xl mb-3"></div>
        <div class="flex justify-center gap-2 mb-2">
            <span class="badge badge-valid mono">Đổi mã sau: <strong id="live-qr-countdown">30s</strong></span>
            <span class="badge badge-subtle">Mã Bảo mật 30s</span>
        </div>
        <div id="live-qr-token-text" class="mono text-[11px] text-slate-500 break-all bg-slate-100 dark:bg-slate-800 p-2 rounded-lg"></div>
    </div>
</div>

<!-- HỘP THOẠI WIZARD 2 BƯỚC: TẠO HOẠT ĐỘNG / CHIẾN DỊCH MỚI (R4) -->
<div id="modal-create-activity" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog" style="max-width: 660px;">
        <div class="flex justify-between items-center pb-3 mb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                    <span class="material-symbols-outlined text-[20px]">add_circle</span>
                </div>
                <div>
                    <h3 class="font-extrabold text-base text-slate-900 dark:text-white">Thiết lập Chiến dịch Tình nguyện &amp; Ca trực Mới</h3>
                    <p class="text-xs text-slate-500">Khởi tạo hoạt động mới và ca trực điều phối ban đầu</p>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-create-activity"><?= icon('x', 14) ?></button>
        </div>

        <!-- Wizard Stepper Indicators -->
        <div class="flex items-center justify-between mb-5 px-1">
            <div id="step-indicator-1" class="flex items-center gap-2 text-xs font-bold text-[#0057B8] dark:text-sky-400">
                <span class="w-6 h-6 rounded-full bg-[#0057B8] text-white flex items-center justify-center text-[11px] font-black">1</span>
                <span>Thông tin Chiến dịch</span>
            </div>
            <div class="flex-1 h-0.5 bg-slate-200 dark:bg-slate-700 mx-3"></div>
            <div id="step-indicator-2" class="flex items-center gap-2 text-xs font-bold text-slate-400">
                <span class="w-6 h-6 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center text-[11px] font-black">2</span>
                <span>Cấu hình Ca trực Ban đầu</span>
            </div>
        </div>

        <form method="post" action="?action=create_activity" id="form-create-activity">
            <!-- BƯỚC 1: THÔNG TIN CHIẾN DỊCH -->
            <div id="wizard-step-1" class="wizard-step space-y-3.5 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Mã Chiến dịch / Hoạt động *:</label>
                        <input type="text" name="activity_code" id="input-act-code" required placeholder="VD: MHX-2026, CD-TET..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs mono uppercase focus:border-[#0057B8] focus:outline-none" />
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Tên Chiến dịch Tình nguyện *:</label>
                        <input type="text" name="title" id="input-act-title" required placeholder="VD: Chiến dịch Mùa Hè Xanh 2026..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Nhãn chủ đề / Phân loại:</label>
                        <select name="tag" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs">
                            <option value="Môi trường &amp; Đô thị">Môi trường &amp; Đô thị (Chủ Nhật Xanh)</option>
                            <option value="Tiếp sức Mùa thi">Tiếp sức Mùa thi &amp; Hỗ trợ Học sinh</option>
                            <option value="Tình nguyện Hè">Chiến dịch Mùa Hè Xanh &amp; Biển đảo</option>
                            <option value="Y tế Cộng đồng">Y tế Cộng đồng &amp; Chăm sóc Sức khỏe</option>
                            <option value="Chuyển đổi số">Tình nguyện Chuyển đổi số &amp; Công nghệ</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Địa bàn Trọng điểm / Địa điểm *:</label>
                        <input type="text" name="target_area" id="input-act-location" required placeholder="VD: Huyện Cần Giờ, TP. Hồ Chí Minh" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Tọa độ Vĩ độ (Lat):</label>
                        <input type="number" step="0.0001" name="lat" value="10.7769" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs mono" />
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Tọa độ Kinh độ (Lng):</label>
                        <input type="number" step="0.0001" name="lng" value="106.7009" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs mono" />
                    </div>
                </div>

                <div>
                    <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Mục tiêu &amp; Ý nghĩa hoạt động:</label>
                    <textarea name="description" rows="2" placeholder="Tóm tắt sứ mệnh và tác động xã hội của chiến dịch..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                    <button type="button" class="btn btn-outline" data-close-modal="modal-create-activity">Hủy</button>
                    <button type="button" id="btn-wizard-next" class="btn btn-primary flex items-center gap-1.5">
                        <span>Tiếp tục: Cấu hình Ca trực (Bước 2)</span>
                        <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                    </button>
                </div>
            </div>

            <!-- BƯỚC 2: KHỞI TẠO CA TRỰC BAN ĐẦU -->
            <div id="wizard-step-2" class="wizard-step hidden space-y-3.5 text-xs">
                <div class="p-3 rounded-xl bg-blue-50/70 dark:bg-sky-950/40 border border-blue-200 dark:border-sky-900 text-slate-700 dark:text-slate-300 text-xs">
                    Thiết lập ca trực xuất quân ban đầu cho chiến dịch. Sau khi tạo, bạn có thể bổ sung thêm các ca trực khác bất kỳ lúc nào.
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Mã Ca trực ban đầu *:</label>
                        <input type="text" name="shift_code" id="input-shift-code" placeholder="VD: CA-01" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs mono uppercase focus:border-[#0057B8] focus:outline-none" />
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Tên Ca trực *:</label>
                        <input type="text" name="shift_title" id="input-shift-title" placeholder="VD: Ca ra quân điều phối hiện trường..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Thời gian bắt đầu *:</label>
                        <input type="datetime-local" name="shift_start" id="input-shift-start" value="2026-07-01T07:30" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Thời gian kết thúc *:</label>
                        <input type="datetime-local" name="shift_end" id="input-shift-end" value="2026-07-01T11:30" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Địa điểm cụ thể ca trực:</label>
                        <input type="text" name="shift_location" id="input-shift-location" placeholder="VD: Trạm y tế lưu động số 1" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs focus:border-[#0057B8] focus:outline-none" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Quân số Min:</label>
                            <input type="number" name="shift_min_people" value="2" min="1" max="50" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-xs mono" />
                        </div>
                        <div>
                            <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Quân số Max:</label>
                            <input type="number" name="shift_max_people" value="5" min="1" max="50" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-xs mono" />
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Yêu cầu Kỹ năng:</label>
                        <select name="shift_skill_id" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-xs">
                            <option value="1">Sơ cứu Y tế</option>
                            <option value="2" selected>Hậu cần Tiếp sức</option>
                            <option value="3">Truyền thông</option>
                            <option value="4">Điều tiết Giao thông</option>
                            <option value="5">Hỗ trợ Kỹ thuật số</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Cấp độ tối thiểu:</label>
                        <select name="shift_min_level" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-xs">
                            <option value="1" selected>Cấp 1 — Cơ bản</option>
                            <option value="2">Cấp 2 — Đã huấn luyện</option>
                            <option value="3">Cấp 3 — Thành thạo</option>
                            <option value="4">Cấp 4 — Chuyên sâu</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Mức độ Ưu tiên:</label>
                        <select name="shift_priority" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-xs">
                            <option value="1">1 — Bình thường</option>
                            <option value="2">2 — Đáng chú ý</option>
                            <option value="3" selected>3 — Ưu tiên cao</option>
                            <option value="4">4 — Khẩn cấp</option>
                            <option value="5">5 — Trọng điểm</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-between gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                    <button type="button" id="btn-wizard-prev" class="btn btn-outline flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                        <span>Quay lại Bước 1</span>
                    </button>
                    <button type="submit" id="btn-wizard-submit" class="btn btn-success flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[18px]">verified</span>
                        <span>Tạo Hoạt động &amp; Kích hoạt Phân công</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- HỘP THOẠI ĐÁNH GIÁ 360° TÌNH NGUYỆN VIÊN (R1) -->
<div id="modal-eval-volunteer" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog" style="max-width: 520px;">
        <div class="flex justify-between items-center pb-3 mb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-950/60 flex items-center justify-center text-amber-600 dark:text-amber-400">
                    <span class="material-symbols-outlined text-[20px]">hotel_class</span>
                </div>
                <div>
                    <h3 class="font-extrabold text-base text-slate-900 dark:text-white">Đánh giá 360° Tình nguyện viên</h3>
                    <p class="text-xs text-slate-500">Chấm điểm năng lực &amp; tác phong sau ca trực</p>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-eval-volunteer"><?= icon('x', 14) ?></button>
        </div>
        <form id="form-eval-volunteer" method="post" action="?action=api_submit_evaluation">
            <input type="hidden" id="eval-assignment-id" name="assignment_id" value="">
            <input type="hidden" id="eval-volunteer-id" name="volunteer_id" value="">
            <input type="hidden" id="eval-shift-id" name="shift_id" value="">
            <input type="hidden" name="eval_type" value="COORDINATOR_TO_VOLUNTEER">
            
            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl mb-4 text-xs flex items-center justify-between">
                <div>
                    <span class="text-slate-400 font-semibold block text-[10px] uppercase">Tình nguyện viên</span>
                    <strong id="eval-volunteer-name" class="text-slate-900 dark:text-white text-sm">--</strong>
                </div>
                <div class="text-right">
                    <span class="text-slate-400 font-semibold block text-[10px] uppercase">Vai trò người chấm</span>
                    <span class="badge badge-info">Ban Chỉ huy Đoàn</span>
                </div>
            </div>

            <div class="space-y-3.5 mb-4 text-xs">
                <div>
                    <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">1. Chuyên cần &amp; Đúng giờ (1–5 sao):</label>
                    <select name="punctuality_rating" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs">
                        <option value="5">5 sao — Có mặt sớm &gt; 15 phút, tác phong chuẩn mực</option>
                        <option value="4" selected>4 sao — Đúng giờ quy định, đầy đủ quân tư trang</option>
                        <option value="3">3 sao — Đi muộn &lt; 10 phút có báo trước</option>
                        <option value="2">2 sao — Đi muộn &gt; 15 phút không báo</option>
                        <option value="1">1 sao — Vắng mặt không lý do chính đáng</option>
                    </select>
                </div>
                <div>
                    <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">2. Năng lực Chuyên môn &amp; Hoàn thành Nhiệm vụ (1–5 sao):</label>
                    <select name="skill_rating" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs">
                        <option value="5" selected>5 sao — Xuất sắc, linh hoạt xử lý tình huống phát sinh</option>
                        <option value="4">4 sao — Thành thạo, hoàn thành tốt yêu cầu ca trực</option>
                        <option value="3">3 sao — Đạt yêu cầu cơ bản, cần hướng dẫn thêm</option>
                        <option value="2">2 sao — Thao tác chậm, chưa nắm vững chuyên môn</option>
                        <option value="1">1 sao — Không đáp ứng được yêu cầu chuyên môn</option>
                    </select>
                </div>
                <div>
                    <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">3. Tinh thần Đồng đội &amp; Kỷ luật (1–5 sao):</label>
                    <select name="conduct_rating" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs">
                        <option value="5" selected>5 sao — Rất tích cực, hỗ trợ đồng đội nhiệt tình</option>
                        <option value="4">4 sao — Kỷ luật tốt, hòa đồng và trách nhiệm</option>
                        <option value="3">3 sao — Chấp hành kỷ luật bình thường</option>
                        <option value="2">2 sao — Tinh thần phối hợp chưa cao</option>
                        <option value="1">1 sao — Vi phạm nội quy hoạt động tình nguyện</option>
                    </select>
                </div>
                <div>
                    <label class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Nhận xét, biểu dương hoặc lưu ý:</label>
                    <textarea name="comment" rows="2" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs" placeholder="Ghi nhận nỗ lực hoặc đề xuất khen thưởng Đoàn..."></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" data-close-modal="modal-eval-volunteer">Hủy</button>
                <button type="submit" class="btn btn-primary flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">save</span>
                    <span>Lưu Đánh giá 360°</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- HỘP THOẠI XEM HỒ SƠ ỨNG VIÊN CHO BAN CHỈ HUY (CANDIDATE INSPECTOR MODAL R1) -->
<div id="modal-candidate-inspector" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog" style="max-width: 760px; max-height: 90vh; overflow-y: auto;">
        <div class="flex justify-between items-center pb-3 mb-4 border-b border-slate-200 dark:border-slate-800 sticky top-0 bg-white dark:bg-[#131822] z-10 pt-1">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-sky-950/60 flex items-center justify-center text-[#0057B8] dark:text-sky-400">
                    <span class="material-symbols-outlined text-[20px]">badge</span>
                </div>
                <div>
                    <h3 class="font-extrabold text-base text-slate-900 dark:text-white">Hồ sơ Ứng viên Tình nguyện viên</h3>
                    <p class="text-xs text-slate-500">Chế độ Ban Chỉ huy — Tra cứu năng lực, lịch rảnh &amp; thành tích tại chỗ</p>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-candidate-inspector"><?= icon('x', 14) ?></button>
        </div>

        <div id="candidate-inspector-loading" class="py-12 text-center text-slate-400">
            <div class="inline-block animate-spin w-8 h-8 border-2 border-[#0057B8] border-t-transparent rounded-full mb-2"></div>
            <div class="text-xs">Đang tải hồ sơ ứng viên...</div>
        </div>

        <div id="candidate-inspector-content" class="hidden space-y-5 text-xs">
            <!-- Header card: Avatar, Name, Student Code, Unit, Contact -->
            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4 p-4 rounded-2xl bg-gradient-to-r from-blue-50/70 to-indigo-50/50 dark:from-slate-800/80 dark:to-slate-800/40 border border-blue-100 dark:border-slate-700">
                <img id="cand-avatar" src="<?= h(avatarUrlFor(1, 'Nguyễn Khánh Linh')) ?>" alt="" class="w-16 h-16 rounded-full object-cover ring-4 ring-[#0057B8]/20 shadow-md">
                <div class="flex-1 text-center sm:text-left">
                    <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 mb-1">
                        <h4 id="cand-name" class="text-base font-extrabold text-slate-900 dark:text-white">--</h4>
                        <span id="cand-student-code" class="badge badge-info mono font-bold">--</span>
                    </div>
                    <p id="cand-unit" class="text-slate-600 dark:text-slate-300 font-semibold mb-2">--</p>
                    <div class="flex flex-wrap items-center justify-center sm:justify-start gap-3 text-slate-500">
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">mail</span><span id="cand-email">--</span></span>
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">call</span><span id="cand-phone">--</span></span>
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">event_repeat</span>Hạn mức: <strong id="cand-max-shifts" class="text-slate-800 dark:text-slate-200">-- ca/tuần</strong></span>
                    </div>
                </div>
            </div>

            <!-- Gamification & Merit Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
                    <span class="text-[10px] uppercase font-bold text-slate-400">Giờ cống hiến</span>
                    <div id="cand-merit-hours" class="text-lg font-black text-emerald-600 dark:text-emerald-400 mono">--</div>
                </div>
                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
                    <span class="text-[10px] uppercase font-bold text-slate-400">Ca hoàn thành</span>
                    <div id="cand-merit-shifts" class="text-lg font-black text-[#0057B8] dark:text-sky-400 mono">--</div>
                </div>
                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
                    <span class="text-[10px] uppercase font-bold text-slate-400">Điểm Uy tín</span>
                    <div id="cand-merit-reputation" class="text-lg font-black text-amber-500 mono">--</div>
                </div>
                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
                    <span class="text-[10px] uppercase font-bold text-slate-400">Điểm XP Đoàn</span>
                    <div id="cand-merit-xp" class="text-lg font-black text-purple-600 dark:text-purple-400 mono">--</div>
                </div>
            </div>

            <!-- Skills List -->
            <div>
                <h5 class="font-extrabold text-slate-900 dark:text-white mb-2 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px] text-[#0057B8]">psychology</span>
                    <span>Kỹ năng Chuyên môn &amp; Kiểm định</span>
                </h5>
                <div id="cand-skills-list" class="flex flex-wrap gap-2"></div>
            </div>

            <!-- 7-Day Availability Matrix -->
            <div>
                <h5 class="font-extrabold text-slate-900 dark:text-white mb-2 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px] text-emerald-600">calendar_month</span>
                    <span>Lịch rảnh đăng ký trong tuần</span>
                </h5>
                <div id="cand-availability-grid" class="grid grid-cols-7 gap-1.5 text-center"></div>
            </div>

            <!-- Assigned Shifts in this campaign -->
            <div>
                <h5 class="font-extrabold text-slate-900 dark:text-white mb-2 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px] text-sky-600">assignment</span>
                    <span>Ca trực đã phân công</span>
                </h5>
                <div id="cand-assigned-shifts-list" class="space-y-1.5 max-h-40 overflow-y-auto pr-1"></div>
            </div>

            <!-- 6 Badges -->
            <div>
                <h5 class="font-extrabold text-slate-900 dark:text-white mb-2 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px] text-amber-500">military_tech</span>
                    <span>Huy hiệu Số Danh dự (Gamification)</span>
                </h5>
                <div id="cand-badges-grid" class="grid grid-cols-2 sm:grid-cols-3 gap-2"></div>
            </div>
        </div>

        <div class="mt-6 pt-3 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center text-xs text-slate-500">
            <span>Chế độ Ban chỉ huy — Xem hồ sơ tại chỗ không chuyển trang.</span>
            <button type="button" class="btn btn-outline" data-close-modal="modal-candidate-inspector">Đóng</button>
        </div>
    </div>
</div>

<!-- HỘP THOẠI OAUTH 2.0 ACCOUNT PICKER (GOOGLE, MICROSOFT EDU.VN, FACEBOOK, ZALO, GITHUB) -->
<div id="modal-oauth-picker" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog" style="max-width:460px;">
        <div class="flex justify-between items-center pb-3 mb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-slate-800 flex items-center justify-center text-[#0057B8] dark:text-sky-400">
                    <span class="material-symbols-outlined text-[20px]">verified_user</span>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <div class="text-xs text-slate-400 font-bold uppercase tracking-wider">Xác thực OAuth 2.0 Liên kết</div>
                        <span class="badge badge-warning text-[10px] mono font-bold">SANDBOX MOCK</span>
                    </div>
                    <h3 id="oauth-modal-title" class="font-extrabold text-base text-slate-900 dark:text-white">Đăng nhập bằng Google</h3>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" data-close-modal="modal-oauth-picker"><?= icon('x', 14) ?></button>
        </div>

        <!-- Biểu ngữ thông báo Sandbox trong Modal -->
        <div class="p-3 mb-3.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs flex items-start gap-2 shadow-sm">
            <span class="material-symbols-outlined text-[18px] text-amber-600 flex-shrink-0 mt-0.5">info</span>
            <div class="leading-relaxed">
                <strong class="font-bold">[Chế độ Sandbox / Thử nghiệm]:</strong> Hệ thống đang chạy ở chế độ mô phỏng OAuth cục bộ do chưa cấu hình Khóa API thực tế. Bạn có thể sử dụng các tài khoản mẫu bên dưới để kiểm thử tính năng phân công và điều phối tình nguyện viên mà không bị dừng ứng dụng.
            </div>
        </div>

        <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">
            Chọn một tài khoản <strong id="oauth-provider-label" class="text-slate-800 dark:text-white">Google</strong> để tiếp tục vào <strong>Xanh Hub Vietnam</strong>:
        </p>

        <!-- 2 Tài khoản OAuth 1 Chạm (Tình nguyện viên & Điều phối viên) -->
        <div class="space-y-2.5 mb-4">
            <form method="post" action="?action=oauth_login">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($currentOAuthState) ?>">
                <input type="hidden" name="state" value="<?= htmlspecialchars($currentOAuthState) ?>">
                <input type="hidden" name="provider" class="oauth-hidden-provider" value="GOOGLE">
                <input type="hidden" name="email" value="linh.nk.sv001@gmail.com">
                <input type="hidden" name="full_name" value="Nguyễn Khánh Linh">
                <input type="hidden" name="account_role" value="VOLUNTEER">
                <button type="submit" class="w-full flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-[#0057B8] hover:bg-blue-50/50 dark:hover:bg-slate-800 transition-all text-left">
                    <div class="flex items-center gap-3">
                        <img src="<?= h(avatarUrlFor(1, 'Nguyễn Khánh Linh')) ?>" alt="" class="w-10 h-10 rounded-full object-cover border border-[#0057B8]">
                        <div>
                            <div class="font-bold text-sm text-slate-900 dark:text-white">Nguyễn Khánh Linh (SV001)</div>
                            <div class="text-xs text-slate-500 mono">linh.nk.sv001@gmail.com</div>
                        </div>
                    </div>
                    <span class="badge badge-valid">Tình nguyện viên</span>
                </button>
            </form>

            <form method="post" action="?action=oauth_login">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($currentOAuthState) ?>">
                <input type="hidden" name="state" value="<?= htmlspecialchars($currentOAuthState) ?>">
                <input type="hidden" name="provider" class="oauth-hidden-provider" value="GOOGLE">
                <input type="hidden" name="email" value="thao.dieuphoi@example.test">
                <input type="hidden" name="full_name" value="Lê Thanh Thảo">
                <input type="hidden" name="account_role" value="COORDINATOR">
                <button type="submit" class="w-full flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-[#0057B8] hover:bg-blue-50/50 dark:hover:bg-slate-800 transition-all text-left">
                    <div class="flex items-center gap-3">
                        <img src="<?= h(avatarUrlFor(31, 'Lê Thanh Thảo')) ?>" alt="" class="w-10 h-10 rounded-full object-cover border border-[#0057B8]">
                        <div>
                            <div class="font-bold text-sm text-slate-900 dark:text-white">Lê Thanh Thảo (Bí thư Đoàn)</div>
                            <div class="text-xs text-slate-500 mono">thao.dieuphoi@example.test</div>
                        </div>
                    </div>
                    <span class="badge badge-info">Điều phối viên</span>
                </button>
            </form>
        </div>

        <!-- Hoặc đăng nhập bằng tài khoản OAuth tùy chỉnh -->
        <div class="border-t border-slate-200 dark:border-slate-800 pt-3.5">
            <div class="text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">Sử dụng một tài khoản khác của bạn:</div>
            <form method="post" action="?action=oauth_login" class="space-y-2.5">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($currentOAuthState) ?>">
                <input type="hidden" name="state" value="<?= htmlspecialchars($currentOAuthState) ?>">
                <input type="hidden" name="provider" class="oauth-hidden-provider" value="GOOGLE">
                <input type="hidden" name="account_role" value="VOLUNTEER">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <input type="text" name="full_name" required placeholder="Họ và tên của bạn" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs" />
                    <input type="email" name="email" required placeholder="email.cua.ban@gmail.com" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs" />
                </div>
                <button type="submit" class="btn btn-primary w-full py-2.5 text-xs">
                    <span class="material-symbols-outlined text-[16px]">login</span>
                    <span>Xác thực &amp; Đăng nhập ngay</span>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- RẠP PHIM TÀI LIỆU TOÀN MÀN HÌNH (CINEMA LIGHTBOX MODAL) -->
<div id="modal-cinema-video" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-dialog bg-slate-950 text-white border-slate-800 p-4 sm:p-6" style="max-width:920px;">
        <div class="flex justify-between items-center mb-3">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-sky-400 filled">movie</span>
                <h3 class="font-extrabold text-base text-white">Phim Tài liệu: Nhịp đập Áo Xanh Thanh niên Việt Nam (2026)</h3>
            </div>
            <button type="button" class="btn btn-sm btn-outline text-white border-slate-700" data-close-modal="modal-cinema-video"><?= icon('x', 14) ?></button>
        </div>
        <div class="rounded-2xl overflow-hidden border border-slate-800 bg-black aspect-video mb-3">
            <video id="modal-cinema-player" src="assets/video/xanhhub-cinematic.mp4" controls playsinline class="w-full h-full object-cover"></video>
        </div>
        <div class="flex flex-wrap justify-between items-center gap-2 text-xs text-slate-400">
            <span>4 Phân cảnh: Mùa Hè Xanh &middot; Tiếp Sức Mùa Thi &middot; Chủ Nhật Xanh &middot; Kết nối Sức trẻ</span>
            <a href="?view=register" class="text-sky-400 font-bold hover:underline">Đăng ký tham gia Chiến dịch &rarr;</a>
        </div>
    </div>
</div>

<!-- NÚT ÂM HƯỞNG THANH NIÊN FLOATING PILL VÀ EQUALIZER -->
<button id="floating-audio-pill" class="floating-audio-pill" type="button" aria-label="Bật hoặc Tắt âm hưởng thanh niên">
    <div class="audio-equalizer" aria-hidden="true">
        <span class="audio-bar"></span>
        <span class="audio-bar"></span>
        <span class="audio-bar"></span>
        <span class="audio-bar"></span>
    </div>
    <span class="audio-text font-bold text-xs">Âm hưởng Thanh niên</span>
</button>

<!-- CON TRỎ CHUỘT CINEMATIC CUSTOM CURSOR -->
<div id="cursor-dot" class="custom-cursor-dot" aria-hidden="true"></div>
<div id="cursor-circle" class="custom-cursor-circle" aria-hidden="true"></div>

<!-- RÈM CHUYỂN TRANG MƯỢT MÀ (PAGE TRANSITION CURTAIN) -->
<div id="page-curtain" class="page-transition-curtain" aria-hidden="true"></div>

<script src="assets/app.js?v=<?= h($jsVersion) ?>"></script>
<?php if (!empty($_GET['sandbox_oauth'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var prov = <?= json_encode((string) $_GET['sandbox_oauth']) ?>;
    var state = <?= json_encode((string) ($_GET['state'] ?? '')) ?>;
    var titleEl = document.getElementById('oauth-modal-title');
    var labelEl = document.getElementById('oauth-provider-label');
    if (titleEl) titleEl.textContent = 'Đăng nhập bằng ' + prov.toUpperCase();
    if (labelEl) labelEl.textContent = prov.toUpperCase();
    document.querySelectorAll('.oauth-hidden-provider').forEach(function(inp) {
        inp.value = prov.toUpperCase();
    });
    if (state) {
        document.querySelectorAll('input[name="csrf_token"], input[name="state"]').forEach(function(inp) {
            inp.value = state;
        });
    }
    if (window.XanhHubUI && typeof window.XanhHubUI.openModal === 'function') {
        window.XanhHubUI.openModal('modal-oauth-picker');
    }
});
</script>
<?php endif; ?>
</body>
</html>
