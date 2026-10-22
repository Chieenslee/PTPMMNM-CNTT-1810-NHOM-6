<?php
require __DIR__.'/../src/Session.php';
require __DIR__.'/../src/Input.php';
require __DIR__.'/../src/Draft.php';
require __DIR__.'/../src/DraftGuard.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    if (!canComplete($_SESSION, (int)field($_POST, 'event_id'), time(), 1800)) {
        cancelDraft($_SESSION);
        header('Location: /register.php', true, 303);
        exit;
    }
    $_SESSION['completed'] = 'SIMULATED_OK';
    cancelDraft($_SESSION);
    header('Location: /complete.php', true, 303); exit;
}
if (!isset($_SESSION['completed'])) {
    header('Location: /register.php', true, 303); exit;
}
require __DIR__.'/../templates/header.php';
?>
<div class="page-heading">
<h1>Bước 3. Hoàn tất đăng ký mô phỏng</h1>
<p>Đề tài 6: Hệ thống tổ chức hoạt động và phân công tình nguyện viên (Nhóm 6)</p>
</div>
<section class="completion" role="status">
<p class="status-message">
<?= e($_SESSION['completed']) ?>
</p>
<p>Chưa ghi vào CSDL; đây không phải vé hoặc bằng chứng đăng ký thật.</p>
</section>
<?php require __DIR__.'/../templates/footer.php'; ?>
