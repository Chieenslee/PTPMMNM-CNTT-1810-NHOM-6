<?php
require __DIR__ . '/../src/Session.php';
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/Draft.php';
if (draftExpired($_SESSION, time(), 1800)) {
    cancelDraft($_SESSION);
    header('Location: /register.php', true, 303);
    exit;
}
$data = $_SESSION['draft']['data'];
require __DIR__ . '/../templates/header.php';
?>
<h2>Bước 2. Xác nhận</h2>
<p>
    <?= e($data['name']) ?> — <?= e($data['email']) ?>
</p>
<a href="/register.php">Sửa lại</a>
<form method="post" action="/complete.php">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <button>Xác nhận</button>
</form>
<form method="post" action="/cancel.php">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <button>Hủy bản nháp</button>
</form>
<?php require __DIR__ . '/../templates/footer.php'; ?>