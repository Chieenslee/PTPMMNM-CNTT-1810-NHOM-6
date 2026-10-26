<?php

declare(strict_types=1);

namespace XanhHub\Tests;

abstract class TestCase
{
    private int $assertions = 0;

    public function getAssertionCount(): int
    {
        return $this->assertions;
    }

    protected function assertTrue(bool $condition, string $message = 'Expected true, got false'): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected false, got true'): void
    {
        $this->assertions++;
        if ($condition) {
            throw new \RuntimeException($message);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $expStr = var_export($expected, true);
            $actStr = var_export($actual, true);
            throw new \RuntimeException($message !== '' ? "{$message} (Expected {$expStr}, got {$actStr})" : "Expected {$expStr}, got {$actStr}");
        }
    }

    protected function assertGreaterThanOrEqual(float|int $expectedMin, float|int $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual < $expectedMin) {
            throw new \RuntimeException($message !== '' ? "{$message} ({$actual} < {$expectedMin})" : "Expected {$actual} >= {$expectedMin}");
        }
    }

    protected function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        $this->assertions++;
        foreach ($haystack as $item) {
            if ($item === $needle) {
                return;
            }
        }
        throw new \RuntimeException($message !== '' ? $message : 'Failed asserting that haystack contains ' . var_export($needle, true));
    }
}
