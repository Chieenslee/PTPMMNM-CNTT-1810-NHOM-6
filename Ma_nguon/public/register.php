<?php
require __DIR__ . '/../src/Session.php';
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/Draft.php';
$events = require __DIR__ . '/../data/events.php';
$errors = [];
$data = $_SESSION['draft']['data'] ?? ['event_id' => 1, 'name' => '', 'email' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    $result = validateRegistration($_POST, $events);
    $errors = $result['errors'];
    $data = $result['data'];
    if (!$errors) {
        saveDraft($_SESSION, $data, time());
        header('Location: /confirm.php', true, 303);
        exit;
    }
}
require __DIR__ . '/../templates/header.php';
?>
<h2>Bước 1. Nhập thông tin</h2>
<p>
    <?= e(implode(', ', $errors)) ?>
</p>
<form method="post">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <label>Sự kiện <select name="event_id">
            <?php foreach ($events as $event): ?>
                <option value="<?= $event['id'] ?>" <?= (int)$data['event_id'] === $event['id'] ? 'selected' : '' ?>>
                    <?= e($event['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <br>
    <label>Họ tên <input name="name" value="<?= e($data['name']) ?>">
    </label>
    <br>
    <label>Email <input name="email" value="<?= e($data['email']) ?>">
    </label>
    <br>
    <button>Tiếp tục</button>
</form>
<form method="post" action="/preferences.php">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <label>Cách hiển thị <select name="view">
            <option value="compact">Gọn</option>
            <option value="full">Đầy đủ</option>
        </select>
    </label>
    <button>Lưu tùy chọn</button>
</form>
<p>Tùy chọn Cookie: <?= e(field($_COOKIE, 'view')) ?>
</p>
<?php require __DIR__ . '/../templates/footer.php'; ?>