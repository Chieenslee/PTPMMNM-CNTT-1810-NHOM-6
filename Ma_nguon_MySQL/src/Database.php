<?php
declare(strict_types=1);
function mysqlConfig(): array
{
    $path = __DIR__ . '/../.local/db.json';
    if (!is_file($path)) {
        throw new RuntimeException('DATABASE_CONFIG_MISSING');
    }
    $config = json_decode((string)file_get_contents($path), true);
    foreach (['host', 'port', 'database', 'user', 'password'] as $key) {
        if (!is_array($config) || !isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('DATABASE_CONFIG_INVALID');
        }
    }
    if (!ctype_digit($config['port']) || (int)$config['port'] < 1 || (int)$config['port'] > 65535) {
        throw new RuntimeException('DATABASE_CONFIG_INVALID');
    }
    return $config;
}
function connectMysql(array $config): mysqli
{
    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('MYSQLI_EXTENSION_MISSING');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $connection = mysqli_init();
        $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        $connection->real_connect($config['host'], $config['user'], $config['password'],
            $config['database'], (int)$config['port']);
        $connection->set_charset('utf8mb4');
        // Use UTC for comparable timestamps; strict mode prevents silent truncation.
        $connection->query("SET time_zone = '+00:00'");
        $connection->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
        return $connection;
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException('DATABASE_UNAVAILABLE');
    }
}
function db(): mysqli
{
    return connectMysql(mysqlConfig());
}
function queryParams(mysqli $connection, string $sql, array $params): mysqli_stmt
{
    try {
        $statement = $connection->prepare($sql);
        // PHP 8.1 supports execute(array); each ? receives a separate value.
        $statement->execute($params);
        return $statement;
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException('DATABASE_OPERATION_FAILED', (int)$e->getCode());
    }
}
