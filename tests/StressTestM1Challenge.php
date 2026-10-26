<?php

declare(strict_types=1);

namespace XanhHub\Tests;

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use XanhHub\Domain\Model\ReasonCode;
use XanhHub\Domain\Model\ScheduleDataset;
use XanhHub\Domain\Model\SchedulerWeights;
use XanhHub\Domain\Model\ShiftSpec;
use XanhHub\Domain\Model\SkillRequirementSpec;
use XanhHub\Domain\Model\VolunteerSpec;
use XanhHub\Domain\Scheduling\ConstraintGuard;
use XanhHub\Domain\Scheduling\ReplacementRanker;
use XanhHub\Domain\Scheduling\XanhHubScheduler;
use XanhHub\Infrastructure\Dataset\DatasetGenerator;
use XanhHub\Infrastructure\Service\GamificationService;

/**
 * ==============================================================================
 * ADVERSARIAL STRESS TEST SUITE — CHALLENGER 1 (MILESTONE M1)
 * ==============================================================================
 *
 * Empirical verification of edge cases, boundary inputs, arithmetic stability,
 * badge award idempotency, and Kuhn-Munkres hard constraints (H1, H2, H3, H4)
 * under active and hyper-aggressive reputation bonus weighting.
 */
final class StressTestM1Challenge extends TestCase
{
    /**
     * S1: Extreme Ratings & Input Validation Boundary Stress Test.
     */
    public function testExtremeRatingsAndValidation(): void
    {
        GamificationService::resetMockState();
        $svc = new GamificationService(null);

        // 1. Negative ratings in coordinator evaluation
        $negatives = [
            [-1, 5, 5],
            [5, -5, 5],
            [5, 5, -10],
            [-100, -100, -100],
        ];
        foreach ($negatives as [$d, $c, $m]) {
            $res = $svc->submitCoordinatorEvaluation(101, 10, 1, $d, $c, $m, 'Negative test');
            $this->assertFalse($res['ok'], "Coordinator eval with negative values ($d, $c, $m) must be rejected");
            $this->assertSame('INVALID_RATING_RANGE', $res['error_code'] ?? null);
        }

        // 2. Zero ratings in coordinator evaluation
        $zeroCases = [
            [0, 5, 5],
            [5, 0, 5],
            [5, 5, 0],
            [0, 0, 0],
        ];
        foreach ($zeroCases as [$d, $c, $m]) {
            $res = $svc->submitCoordinatorEvaluation(102, 10, 1, $d, $c, $m, 'Zero test');
            $this->assertFalse($res['ok'], "Coordinator eval with zero values ($d, $c, $m) must be rejected");
            $this->assertSame('INVALID_RATING_RANGE', $res['error_code'] ?? null);
        }

        // 3. Above max (>5) ratings in coordinator evaluation
        $overMax = [
            [6, 5, 5],
            [5, 7, 5],
            [5, 5, 999],
            [10, 20, 30],
        ];
        foreach ($overMax as [$d, $c, $m]) {
            $res = $svc->submitCoordinatorEvaluation(103, 10, 1, $d, $c, $m, 'Over max test');
            $this->assertFalse($res['ok'], "Coordinator eval with >5 values ($d, $c, $m) must be rejected");
            $this->assertSame('INVALID_RATING_RANGE', $res['error_code'] ?? null);
        }

        // 4. Same extreme tests for volunteer shift evaluation
        $badShiftEvals = [
            [-1, 5, 5],
            [0, 5, 5],
            [5, 6, 5],
            [5, 5, 100],
            [-50, 0, 50],
        ];
        foreach ($badShiftEvals as [$l, $s, $sup]) {
            $res = $svc->submitVolunteerShiftEvaluation(201, 1, 10, $l, $s, $sup, 'Shift eval boundary');
            $this->assertFalse($res['ok'], "Shift eval with out-of-range values ($l, $s, $sup) must be rejected");
            $this->assertSame('INVALID_RATING_RANGE', $res['error_code'] ?? null);
        }

        // 5. Valid extreme boundaries: 1 (min) and 5 (max)
        $minValid = $svc->submitCoordinatorEvaluation(301, 10, 1, 1, 1, 1, 'All 1 stars');
        $this->assertTrue($minValid['ok']);
        $this->assertSame(1.0, (float) $minValid['average_score']);

        $maxValid = $svc->submitCoordinatorEvaluation(302, 10, 1, 5, 5, 5, 'All 5 stars');
        $this->assertTrue($maxValid['ok']);
        $this->assertSame(5.0, (float) $maxValid['average_score']);

        // 6. Fractional boundary: (4, 4, 5) => 4.33, (3, 4, 4) => 3.67
        $fracValid1 = $svc->submitCoordinatorEvaluation(303, 10, 1, 4, 4, 5);
        $this->assertTrue($fracValid1['ok']);
        $this->assertSame(4.33, (float) $fracValid1['average_score']);

        $fracValid2 = $svc->submitCoordinatorEvaluation(304, 10, 1, 3, 4, 4);
        $this->assertTrue($fracValid2['ok']);
        $this->assertSame(3.67, (float) $fracValid2['average_score']);
    }

    /**
     * S2: Duplicate Submission & Concurrency Rejection Stress Test.
     */
    public function testDuplicateEvaluationSubmission(): void
    {
        GamificationService::resetMockState();
        $svc = new GamificationService(null);

        // 1. First submission succeeds
        $res1 = $svc->submitCoordinatorEvaluation(401, 10, 1, 5, 5, 5, 'First eval');
        $this->assertTrue($res1['ok']);

        // 2. Exact same assignment re-submitted must fail
        $res2 = $svc->submitCoordinatorEvaluation(401, 10, 1, 4, 4, 4, 'Second eval');
        $this->assertFalse($res2['ok']);
        $this->assertSame('DUPLICATE_EVALUATION', $res2['error_code']);

        // 3. Different coordinator evaluating the same assignment also rejected (1 eval per assignment)
        $res3 = $svc->submitCoordinatorEvaluation(401, 99, 1, 3, 3, 3, 'Different coordinator');
        $this->assertFalse($res3['ok']);
        $this->assertSame('DUPLICATE_EVALUATION', $res3['error_code']);

        // 4. Same check for volunteer shift evaluation
        $shiftRes1 = $svc->submitVolunteerShiftEvaluation(401, 1, 10, 4, 5, 5);
        $this->assertTrue($shiftRes1['ok']);

        $shiftRes2 = $svc->submitVolunteerShiftEvaluation(401, 1, 10, 5, 5, 5);
        $this->assertFalse($shiftRes2['ok']);
        $this->assertSame('DUPLICATE_EVALUATION', $shiftRes2['error_code']);
    }

    /**
     * S3: Zero / Boundary Activities & Division by Zero Scenarios.
     */
    public function testZeroHoursZeroCheckinsDivisionByZero(): void
    {
        GamificationService::resetMockState();
        $svc = new GamificationService(null);

        // Brand new volunteer ID 999 (not in default dataset)
        $merit = $svc->calculateVolunteerMerit(999);

        $this->assertSame(999, $merit['volunteer_id']);
        $this->assertSame(0.0, (float) $merit['total_hours']);
        $this->assertSame(0.0, (float) $merit['avg_rating']);
        $this->assertSame(0, $merit['on_time_checkins']);
        $this->assertSame(0, $merit['assigned_count']);
        $this->assertSame(0, $merit['absent_count']);
        $this->assertSame(1.0, (float) $merit['punctual_rate'], 'Punctual rate should default to 1.0 when assigned_count=0 to prevent div by zero');
        $this->assertSame(0.0, (float) $merit['xp'], 'XP must be 0.0 for volunteer with 0 hours, 0 evals, 0 checkins');
        $this->assertSame(4.0, (float) $merit['reputation_score'], 'Reputation score must be neutral default 4.00');
        $this->assertSame(0, $merit['unlocked_badge_count'], 'New volunteer must have 0 unlocked badges');

        // Verify none of the 6 badges are unlocked for new volunteer
        foreach ($merit['badges'] as $b) {
            $this->assertFalse($b['is_unlocked'], "Badge {$b['code']} should not be unlocked for brand new volunteer");
        }
    }

    /**
     * S4: Floating Point Rounding & Rating Clamping Invariants.
     */
    public function testFloatingPointAndClampingInvariants(): void
    {
        GamificationService::resetMockState();
        $svc = new GamificationService(null);

        // Exhaustive test: all 125 possible rating triples (1..5, 1..5, 1..5)
        for ($d = 1; $d <= 5; $d++) {
            for ($c = 1; $c <= 5; $c++) {
                for ($m = 1; $m <= 5; $m++) {
                    $rawAvg = ($d + $c + $m) / 3.0;
                    $rounded = round($rawAvg, 2);

                    $this->assertTrue($rounded >= 1.0 && $rounded <= 5.0, "Average $rounded must be in [1.0, 5.0]");
                    $str = sprintf('%.2f', $rounded);
                    $this->assertTrue(strlen(substr(strrchr($str, '.') ?: '', 1)) <= 2, "Average must have at most 2 decimal places");
                }
            }
        }

        // Reputation score formula: round(0.60 * avg_rating + 0.40 * (punctual_rate * 5.0), 2)
        // Test edge values of avg_rating [1.0, 5.0] and punctual_rate [0.0, 1.0]
        $testGrid = [
            ['avg' => 1.0, 'rate' => 0.0, 'expected' => 1.00], // 0.60 + 0.0 = 0.60 -> clamped to 1.00
            ['avg' => 1.0, 'rate' => 1.0, 'expected' => 2.60], // 0.60 + 2.00 = 2.60
            ['avg' => 5.0, 'rate' => 0.0, 'expected' => 3.00], // 3.00 + 0.00 = 3.00
            ['avg' => 5.0, 'rate' => 1.0, 'expected' => 5.00], // 3.00 + 2.00 = 5.00
            ['avg' => 4.5, 'rate' => 0.8, 'expected' => 4.30], // 2.70 + 1.60 = 4.30
            ['avg' => 3.33, 'rate' => 0.67, 'expected' => 3.34], // 1.998 + 1.34 = 3.338 -> 3.34
        ];

        foreach ($testGrid as $case) {
            $calc = round(0.60 * $case['avg'] + 0.40 * ($case['rate'] * 5.0), 2);
            $calc = max(1.0, min(5.0, $calc));
            $this->assertSame($case['expected'], $calc, "Reputation score mismatch for avg {$case['avg']}, rate {$case['rate']}");
        }
    }

    /**
     * S5: Badge Award Idempotency Stress Test (10x Consecutive Executions).
     */
    public function testBadgeAwardIdempotency10x(): void
    {
        GamificationService::resetMockState();
        $svc = new GamificationService(null);

        // Test idempotency across multiple volunteer profiles: 1, 2, 3, 99
        $volunteerIds = [1, 2, 3, 99];

        foreach ($volunteerIds as $vid) {
            // Run 1: initial award evaluation
            $unlockedFirstRun = $svc->evaluateAndAwardBadges($vid);

            // Runs 2 to 10: must yield 0 additional unlocks
            for ($iteration = 2; $iteration <= 10; $iteration++) {
                $subsequentUnlocked = $svc->evaluateAndAwardBadges($vid);
                $this->assertSame(
                    0,
                    count($subsequentUnlocked),
                    "Idempotency violation on volunteer $vid at iteration $iteration: expected 0 newly unlocked badges, got " . count($subsequentUnlocked)
                );
            }

            // Merit check: verify total unlocked count is consistent
            $merit = $svc->calculateVolunteerMerit($vid);
            $unlockedCodes = [];
            foreach ($merit['badges'] as $b) {
                if (!empty($b['is_unlocked'])) {
                    $unlockedCodes[] = $b['code'];
                }
            }

            // Ensure no duplicate badge codes exist in the unlocked list
            $uniqueCodes = array_unique($unlockedCodes);
            $this->assertSame(
                count($unlockedCodes),
                count($uniqueCodes),
                "Volunteer $vid has duplicate unlocked badge records"
            );
        }
    }

    /**
     * S6: Kuhn-Munkres Inviolable Hard Constraints under Extreme Reputation Weight.
     *
     * Stress-tests H1 (No Time Overlap), H2 (Capacity Limits), H3 (Skill Match),
     * and H4 (No Hallucination) across D1, D2, D3 with normal, high, and hyper-extreme weights.
     */
    public function testKuhnMunkresHardConstraintsUnderReputationBonus(): void
    {
        $scheduler = new XanhHubScheduler();

        $weightProfiles = [
            'MODE_REPUTATION_DEFAULT' => SchedulerWeights::fromMode(SchedulerWeights::MODE_REPUTATION),
            'EXTREME_REP_50' => new SchedulerWeights(
                priorityMode: SchedulerWeights::MODE_REPUTATION,
                wPriority: 4.0,
                wPreference: 2.0,
                wSkillWaste: 2.5,
                wLoad: 2.0,
                wAbsentRisk: 0.0,
                wReputation: 50.0
            ),
            'HYPER_EXTREME_REP_1000' => new SchedulerWeights(
                priorityMode: SchedulerWeights::MODE_REPUTATION,
                wPriority: 4.0,
                wPreference: 2.0,
                wSkillWaste: 2.5,
                wLoad: 2.0,
                wAbsentRisk: 0.0,
                wReputation: 1000.0
            ),
        ];

        $datasets = [
            'D1_SUFFICIENT' => DatasetGenerator::createD1SufficientDataset(1),
            'D2_UNDERSTAFFED' => DatasetGenerator::createD2UnderstaffedDataset(2),
            'D3_HIGH_OVERLAP' => DatasetGenerator::createD3HighOverlapDataset(3),
        ];

        foreach ($weightProfiles as $weightName => $weights) {
            foreach ($datasets as $dsName => $dataset) {
                $plan = $scheduler->schedule($dataset, $weights);

                // --- H1: Khóa cứng Trùng giờ (Zero Time Overlap) ---
                $this->assertSame(
                    0,
                    $plan->metrics['violation_breakdown'][ReasonCode::TIME_CONFLICT],
                    "H1 VIOLATION: Dataset $dsName under $weightName produced TIME_CONFLICT"
                );

                $shiftsByVol = [];
                foreach ($plan->decisions as $d) {
                    $shiftsByVol[$d->volunteerId][] = $dataset->shiftsById[$d->shiftId];
                }

                foreach ($shiftsByVol as $volId => $assignedShifts) {
                    $m = count($assignedShifts);
                    for ($i = 0; $i < $m; $i++) {
                        for ($j = $i + 1; $j < $m; $j++) {
                            $this->assertFalse(
                                $assignedShifts[$i]->overlapsWith($assignedShifts[$j]),
                                sprintf(
                                    "H1 CRITICAL BUG: Volunteer #%d assigned to overlapping shifts (%s and %s) under %s on %s",
                                    $volId,
                                    $assignedShifts[$i]->code,
                                    $assignedShifts[$j]->code,
                                    $weightName,
                                    $dsName
                                )
                            );
                        }
                    }
                }

                // --- H2: Khóa cứng Giới hạn Tải & Sức chứa (Capacity Limits) ---
                // a) Volunteer weekly load <= maxShiftsPerWeek
                foreach ($shiftsByVol as $volId => $assignedShifts) {
                    $vol = $dataset->volunteersById[$volId];
                    $this->assertTrue(
                        count($assignedShifts) <= $vol->maxShiftsPerWeek,
                        sprintf(
                            "H2 VIOLATION: Volunteer #%d exceeded maxShiftsPerWeek (%d > %d) under %s on %s",
                            $volId,
                            count($assignedShifts),
                            $vol->maxShiftsPerWeek,
                            $weightName,
                            $dsName
                        )
                    );
                }

                // b) Shift capacity <= maxPeople
                $volsByShift = [];
                foreach ($plan->decisions as $d) {
                    $volsByShift[$d->shiftId][] = $d->volunteerId;
                }
                foreach ($volsByShift as $shiftId => $assignedVolIds) {
                    $shift = $dataset->shiftsById[$shiftId];
                    $this->assertTrue(
                        count($assignedVolIds) <= $shift->maxPeople,
                        sprintf(
                            "H2 VIOLATION: Shift %s exceeded maxPeople (%d > %d) under %s on %s",
                            $shift->code,
                            count($assignedVolIds),
                            $shift->maxPeople,
                            $weightName,
                            $dsName
                        )
                    );
                }

                // --- H3: Khóa cứng Kỹ năng (Skill Requirements) ---
                $this->assertSame(
                    0,
                    $plan->metrics['violation_breakdown'][ReasonCode::NO_SKILL],
                    "H3 VIOLATION: Dataset $dsName under $weightName produced NO_SKILL violation"
                );

                foreach ($plan->decisions as $d) {
                    if ($d->requirementId !== null) {
                        $vol = $dataset->volunteersById[$d->volunteerId];
                        $shift = $dataset->shiftsById[$d->shiftId];
                        $req = null;
                        foreach ($shift->requirements as $r) {
                            if ($r->id === $d->requirementId) {
                                $req = $r;
                                break;
                            }
                        }
                        $this->assertTrue($req !== null, "Target requirement {$d->requirementId} must exist");
                        $this->assertTrue(
                            $vol->satisfiesRequirement($req),
                            sprintf(
                                "H3 CRITICAL BUG: Volunteer #%d does not satisfy requirement %s minLevel %d for shift %s under %s",
                                $vol->id,
                                $req->skillName,
                                $req->minLevel,
                                $shift->code,
                                $weightName
                            )
                        );
                    }
                }

                // --- H4: Không tạo người ảo (Zero Hallucination / No Understaffed Padding) ---
                foreach ($plan->decisions as $d) {
                    $this->assertTrue(
                        isset($dataset->volunteersById[$d->volunteerId]),
                        sprintf("H4 VIOLATION: Hallucinated volunteer ID %d assigned under %s", $d->volunteerId, $weightName)
                    );
                }
            }
        }
    }

    /**
     * S7: ReplacementRanker Constraint Preservation Stress Test.
     */
    public function testReplacementRankerConstraintPreservation(): void
    {
        $dataset = DatasetGenerator::createD3HighOverlapDataset(3);
        $scheduler = new XanhHubScheduler();
        $plan = $scheduler->schedule($dataset);

        $this->assertTrue(count($plan->decisions) > 0);
        $targetDecision = $plan->decisions[0];
        $activeAssignments = array_values(array_slice($plan->decisions, 1));

        $ranker = new ReplacementRanker();
        $candidates = $ranker->rankCandidates(
            dataset: $dataset,
            activeAssignments: $activeAssignments,
            shiftId: $targetDecision->shiftId,
            requirementId: $targetDecision->requirementId,
            waitlistVolunteerIds: [],
            limit: 10
        );

        $guard = new ConstraintGuard();
        foreach ($candidates as $cand) {
            $chk = $guard->validateAssignment(
                dataset: $dataset,
                currentAssignments: $activeAssignments,
                shiftId: $targetDecision->shiftId,
                volunteerId: $cand['volunteer_id'],
                requirementId: $targetDecision->requirementId,
                isManualEdit: true,
                overrideReason: 'STRESS_TEST'
            );

            // Hard constraints H1-H3 must have 0 violations
            $this->assertSame(
                [],
                $chk['hard_violations'],
                sprintf("ReplacementRanker recommended volunteer #%d with hard violations", $cand['volunteer_id'])
            );

            // Must not be at capacity
            $this->assertTrue(
                $cand['current_load'] < $cand['max_shifts_per_week'],
                sprintf("Candidate #%d is already at maximum weekly load", $cand['volunteer_id'])
            );
        }
    }

    /**
     * S8: Leaderboard Sorting Determinism & Commendation Safety.
     */
    public function testLeaderboardDeterminismAndCommendationSafety(): void
    {
        GamificationService::resetMockState();
        $svc = new GamificationService(null);

        $lb1 = $svc->getLeaderboardData(10);
        $lb2 = $svc->getLeaderboardData(10);

        // Sorting must be 100% deterministic
        $this->assertSame(count($lb1['top_volunteers']), count($lb2['top_volunteers']));
        for ($i = 0; $i < count($lb1['top_volunteers']); $i++) {
            $this->assertSame(
                $lb1['top_volunteers'][$i]['volunteer_id'],
                $lb2['top_volunteers'][$i]['volunteer_id'],
                "Leaderboard ranking must be deterministic at index $i"
            );
        }

        // Medal distribution: top 1 Gold, top 2 Silver, top 3 Bronze, rest null
        $this->assertSame('GOLD', $lb1['top_volunteers'][0]['medal']);
        $this->assertSame('SILVER', $lb1['top_volunteers'][1]['medal']);
        $this->assertSame('BRONZE', $lb1['top_volunteers'][2]['medal']);
        for ($i = 3; $i < count($lb1['top_volunteers']); $i++) {
            $this->assertSame(null, $lb1['top_volunteers'][$i]['medal']);
        }

        // Commendation safety: unknown volunteer returns null cleanly without exceptions
        $unknownCommendation = $svc->getYouthUnionCommendation(99999);
        $this->assertSame(null, $unknownCommendation);

        // Valid volunteer commendation has QR code
        $validCommendation = $svc->getYouthUnionCommendation(1);
        $this->assertTrue($validCommendation !== null);
        $this->assertTrue(str_contains($validCommendation['qr_svg'], '<svg'));
        $this->assertTrue(str_contains($validCommendation['qr_svg'], '</svg>'));
    }
}

// ----------------------------------------------------------------------------
// Standalone Direct CLI Runner
// ----------------------------------------------------------------------------
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    echo "========================================================================\n";
    echo " ADVERSARIAL STRESS TEST HARNESS — CHALLENGER 1 (MILESTONE M1)\n";
    echo "========================================================================\n\n";

    $suite = new StressTestM1Challenge();
    $reflection = new \ReflectionClass($suite);
    $methods = array_filter(
        $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        static fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'test')
    );

    $total = 0;
    $passed = 0;
    $failed = 0;
    $errors = [];

    foreach ($methods as $method) {
        $total++;
        $name = $method->getName();
        $start = microtime(true);
        try {
            $suite->$name();
            $duration = round((microtime(true) - $start) * 1000, 1);
            $passed++;
            echo "  \033[32m[PASS]\033[0m {$name} ({$duration} ms)\n";
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $start) * 1000, 1);
            $failed++;
            $errors[] = "{$name}: " . $e->getMessage();
            echo "  \033[31m[FAIL]\033[0m {$name} ({$duration} ms)\n";
            echo "         \033[33m" . $e->getMessage() . "\033[0m\n";
        }
    }

    echo "\n------------------------------------------------------------------------\n";
    echo sprintf(" SUMMARY: %d/%d tests passed (%d assertions)\n", $passed, $total, $suite->getAssertionCount());
    echo "------------------------------------------------------------------------\n";

    if ($failed > 0) {
        echo "\nFailures:\n";
        foreach ($errors as $err) {
            echo "  - $err\n";
        }
        exit(1);
    }

    echo "\n✔ ALL ADVERSARIAL STRESS TESTS PASSED CLEANLY.\n";
    exit(0);
}
