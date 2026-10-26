<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Infrastructure\Persistence\Database;

$migrationFile = dirname(__DIR__) . '/database/migrations/001_init_schema.sql';
echo "[Xanh Hub] Đang chạy migration khởi tạo lược đồ PostgreSQL...\n";
Database::migrate($migrationFile);
echo "[Xanh Hub] Hoàn tất tạo 22 bảng, ràng buộc EXCLUDE USING gist, trigger FOR UPDATE và hàm append_audit_log!\n";
