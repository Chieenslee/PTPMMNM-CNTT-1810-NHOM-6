#!/usr/bin/env sh
set -eu

cd /var/www/html

# Tự động khởi tạo lược đồ & dữ liệu mẫu ở lần khởi động đầu tiên trên Cloud DB rỗng
php -r '
require "bootstrap/autoload.php";
try {
    $pdo = XanhHub\Infrastructure\Persistence\Database::getConnection();
    $exists = $pdo->query("SELECT to_regclass('\''public.users'\'')")->fetchColumn();
    if (!$exists) {
        echo "[XanhHub Cloud] Empty database detected — running migrations + seed.php...\n";
        passthru("php bin/seed.php", $code);
        if ($code !== 0) {
            fwrite(STDERR, "[XanhHub Cloud] Warning: seed exited with code {$code}\n");
        }
    } else {
        $hasNews = $pdo->query("SELECT to_regclass('\''public.news'\'')")->fetchColumn();
        if (!$hasNews && file_exists("bin/migrate-003.php")) {
            passthru("php bin/migrate-003.php");
        }
        echo "[XanhHub Cloud] Database schema verified.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[XanhHub Cloud] DB check warning: " . $e->getMessage() . "\n");
}
'

export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
LISTEN_PORT="${PORT:-8080}"
echo "[XanhHub Cloud] Starting PHP web server on 0.0.0.0:${LISTEN_PORT}..."
exec php -S "0.0.0.0:${LISTEN_PORT}" -t public
