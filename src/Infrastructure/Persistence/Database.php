<?php

declare(strict_types=1);

namespace XanhHub\Infrastructure\Persistence;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dbUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $dbname = getenv('DB_DATABASE') ?: 'xanhhub';
        $user = getenv('DB_USERNAME') ?: 'postgres';
        $pass = getenv('DB_PASSWORD') ?: '';
        $sslmode = getenv('DB_SSLMODE') ?: '';

        if (is_string($dbUrl) && $dbUrl !== '') {
            $parts = parse_url($dbUrl);
            if (is_array($parts)) {
                if (!empty($parts['host'])) {
                    $host = $parts['host'];
                }
                if (!empty($parts['port'])) {
                    $port = (string) $parts['port'];
                }
                if (!empty($parts['path'])) {
                    $dbname = ltrim($parts['path'], '/');
                }
                if (isset($parts['user'])) {
                    $user = rawurldecode($parts['user']);
                }
                if (isset($parts['pass'])) {
                    $pass = rawurldecode($parts['pass']);
                }
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $qParams);
                    if (!empty($qParams['sslmode']) && is_string($qParams['sslmode'])) {
                        $sslmode = $qParams['sslmode'];
                    }
                }
                if ($sslmode === '' && !in_array($host, ['127.0.0.1', 'localhost', 'db'], true)) {
                    $sslmode = 'prefer';
                }
            }
        }

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        if ($sslmode !== '') {
            $dsn .= ";sslmode={$sslmode}";
        }

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec("SET TIME ZONE 'Asia/Ho_Chi_Minh'");
        self::$instance = $pdo;
        return self::$instance;
    }

    public static function migrate(string $sqlFilePath): void
    {
        $pdo = self::getConnection();
        $sql = file_get_contents($sqlFilePath);
        if ($sql === false) {
            throw new \RuntimeException("Không thể đọc tệp migration: {$sqlFilePath}");
        }
        $pdo->exec($sql);
    }
}
