<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Tests\CapacityGuardTest;
use XanhHub\Tests\CompareReportTest;
use XanhHub\Tests\ConflictTimeTest;
use XanhHub\Tests\ExtendedSystemTest;
use XanhHub\Tests\SkillRequiredTest;
use XanhHub\Tests\TestCase;
use XanhHub\Tests\UnderstaffedReportTest;

$testClasses = [
    ConflictTimeTest::class,
    SkillRequiredTest::class,
    CapacityGuardTest::class,
    UnderstaffedReportTest::class,
    CompareReportTest::class,
    ExtendedSystemTest::class,
];

echo "========================================================================\n";
echo " XANH HUB (ĐỀ TÀI 6) - BỘ KIỂM THỬ TỰ ĐỘNG & 5 PHÉP THỬ BẮT BUỘC\n";
echo "========================================================================\n\n";

$totalTests = 0;
$passedTests = 0;
$totalAssertions = 0;
$failures = [];

foreach ($testClasses as $className) {
    $shortClass = substr($className, strrpos($className, '\\') + 1);
    echo "▶ {$shortClass}\n";

    $reflection = new ReflectionClass($className);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $totalTests++;
        /** @var TestCase $instance */
        $instance = $reflection->newInstance();
        $methodName = $method->getName();

        try {
            $t0 = microtime(true);
            $instance->$methodName();
            $ms = round((microtime(true) - $t0) * 1000, 1);
            $passedTests++;
            $totalAssertions += $instance->getAssertionCount();
            echo sprintf("  [PASS] %-62s (%d assertions, %.1f ms)\n", $methodName, $instance->getAssertionCount(), $ms);
        } catch (\Throwable $e) {
            $failures[] = "{$shortClass}::{$methodName} -> " . $e->getMessage();
            echo sprintf("  [FAIL] %-62s\n         Lý do: %s\n", $methodName, $e->getMessage());
        }
    }
    echo "\n";
}

echo "------------------------------------------------------------------------\n";
echo sprintf(
    " TỔNG KẾT: %d/%d ca kiểm thử ĐẠT (%d phép khẳng định / assertions)\n",
    $passedTests,
    $totalTests,
    $totalAssertions
);
echo "------------------------------------------------------------------------\n";

if ($failures !== []) {
    exit(1);
}
exit(0);
