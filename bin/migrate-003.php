<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Infrastructure\Persistence\Database;

$pdo = Database::getConnection();
$sql = file_get_contents(dirname(__DIR__) . '/database/migrations/003_news_management.sql');
$pdo->exec($sql);
echo "[Xanh Hub] Migration 003 applied: bảng news đã được tạo và nạp dữ liệu thành công!\n";
