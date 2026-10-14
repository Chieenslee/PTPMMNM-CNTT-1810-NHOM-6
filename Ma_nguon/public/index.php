<?php
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/EventFilter.php';
$events = require __DIR__ . '/../data/events.php';
$query = field($_GET, 'q');
$visible = filterEvents($events, $query);
require __DIR__ . '/../templates/header.php';
?>
<form method="get">
    <label>Từ khóa
        <input name="q" value="<?= e($query) ?>">
    </label>
    <button>Tìm</button>
</form>
<p>Kết quả: <?= count($visible) ?></p>
<?php foreach ($visible as $event): ?>
    <article>
        <strong><?= e($event['title']) ?></strong><br>
        Loại: <?= e($event['category']) ?>.<br>
        Còn <?= max(0, $event['capacity'] - $event['registered']) ?>
        chỗ (dữ liệu mẫu).
    </article>
<?php endforeach; ?>
<?php if (!$visible): ?>
    <p>Không có kết quả.</p>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>