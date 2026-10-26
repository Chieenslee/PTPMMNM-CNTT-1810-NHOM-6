<?php
declare(strict_types=1);

$php = 'C:\\xampp\\php\\php.exe';
$tests = [
    'bin/run-tests.php' => 'Unit & Algorithm Tests (Kuhn-Munkres, Haversine, ReplacementRanker, AuditChain, MonogramAvatar)',
    'bin/test-ux-profile-and-activity.php' => 'UX, Profile, Activity & Monogram Verification',
    'bin/test-map-and-gis.php' => 'Map, GIS & Route Optimization Verification',
    'bin/test-analytics-and-export.php' => 'Analytics, BI & CSV/PDF Export Verification',
    'bin/audit-ui-and-logic.php' => 'UI Polish, Typography & Logic Audit',
    'bin/verify-production-readiness.php' => 'Production Readiness, RBAC & Security Verification'
];

echo "============================================================\n";
echo "   XANH HUB - RUN ALL TEST SUITES & VERIFICATION\n";
echo "============================================================\n\n";

$allPassed = true;
$results = [];

foreach ($tests as $script => $title) {
    echo ">>> Running: $script\n";
    echo "    Description: $title\n";
    $start = microtime(true);
    
    $command = "\"$php\" \"$script\" 2>&1";
    exec($command, $outputLines, $returnCode);
    $elapsed = round(microtime(true) - $start, 2);
    
    $passed = ($returnCode === 0);
    if (!$passed) {
        $allPassed = false;
    }
    
    // Extract summary lines (usually last 3-5 lines)
    $tail = array_slice($outputLines, -4);
    $tailSummary = trim(implode("\n    ", $tail));
    
    $results[$script] = [
        'title' => $title,
        'passed' => $passed,
        'code' => $returnCode,
        'elapsed' => $elapsed,
        'summary' => $tailSummary
    ];
    
    echo "    Result: " . ($passed ? "PASS (Code 0)" : "FAIL (Code $returnCode)") . " in {$elapsed}s\n";
    echo "    $tailSummary\n";
    echo "------------------------------------------------------------\n";
}

echo "\n============================================================\n";
echo "   SUMMARY REPORT\n";
echo "============================================================\n";
foreach ($results as $script => $res) {
    $tag = $res['passed'] ? '[PASS]' : '[FAIL]';
    echo sprintf("%-8s %-38s (%0.2fs)\n", $tag, $script, $res['elapsed']);
}
echo "============================================================\n";

if ($allPassed) {
    echo ">>> ALL 6 TEST SUITES PASSED 100%! Ready for demo/production.\n";
    exit(0);
} else {
    echo ">>> SOME TEST SUITES FAILED. Check logs above.\n";
    exit(1);
}
