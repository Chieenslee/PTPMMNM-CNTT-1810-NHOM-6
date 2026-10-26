<?php

declare(strict_types=1);

/**
 * PSR-4 Autoloader & Environment Bootstrap cho Xanh Hub.
 * Hỗ trợ chạy trực tiếp trên PHP 8.2+ kể cả khi chưa chạy composer install.
 */

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'XanhHub\\Tests\\' => dirname(__DIR__) . '/tests/',
        'XanhHub\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// Nạp biến môi trường từ .env hoặc .env.example
$envFile = is_file(dirname(__DIR__) . '/.env')
    ? dirname(__DIR__) . '/.env'
    : (is_file(dirname(__DIR__) . '/.env.example') ? dirname(__DIR__) . '/.env.example' : null);

if ($envFile !== null && is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            if ($key === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                continue;
            }

            $rawVal = trim($parts[1]);
            $val = '';

            // Xử lý chuỗi có dấu nháy ("..." hoặc '...')
            if (strlen($rawVal) >= 2 && ($rawVal[0] === '"' || $rawVal[0] === "'")) {
                $quote = $rawVal[0];
                $closingPos = strrpos($rawVal, $quote);
                if ($closingPos !== false && $closingPos > 0) {
                    $val = substr($rawVal, 1, $closingPos - 1);
                    if ($quote === '"') {
                        $val = stripcslashes($val);
                    }
                } else {
                    $val = trim($rawVal, $quote);
                }
            } else {
                // Không có dấu nháy: tách bỏ chú thích inline nếu có (# ...)
                $inlineCommentPos = strpos($rawVal, ' #');
                if ($inlineCommentPos !== false) {
                    $rawVal = trim(substr($rawVal, 0, $inlineCommentPos));
                }
                $val = trim($rawVal);
            }

            // Gán đồng bộ vào getenv (putenv), $_ENV và $_SERVER
            if (getenv($key) === false) {
                putenv("{$key}={$val}");
            }
            $_ENV[$key] = getenv($key) !== false ? (string) getenv($key) : $val;
            $_SERVER[$key] = getenv($key) !== false ? (string) getenv($key) : $val;
        }
    }
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Ho_Chi_Minh');
