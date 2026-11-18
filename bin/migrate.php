<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Infrastructure\Persistence\Database;

$migrationFile = dirname(__DIR__) . '/database/migrations/001_init_schema.sql';
echo "[Xanh Hub] Đang chạy migration khởi tạo lược đồ PostgreSQL...\n";
Database::migrate($migrationFile);

$mig002 = dirname(__DIR__) . '/database/migrations/002_gamification_and_evaluations.sql';
if (file_exists($mig002)) {
    Database::migrate($mig002);
}

$mig003 = dirname(__DIR__) . '/database/migrations/003_news_management.sql';
if (file_exists($mig003)) {
    Database::migrate($mig003);
}
echo "[Xanh Hub] Hoàn tất tạo 23 bảng (bao gồm news & gamification), ràng buộc EXCLUDE USING gist, trigger FOR UPDATE và hàm append_audit_log!\n";
