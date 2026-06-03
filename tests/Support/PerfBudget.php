<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Single source of truth for backend API performance budgets (plan §28).
 *
 * Values are wall-clock milliseconds in the TEST environment (the most generous
 * tier; staging/production budgets are tighter). The frontend mirror lives in
 * `sh-selfhelp_frontend/e2e/utils/perf.ts` (`PERF_BUDGETS` + `measure`).
 *
 * `Timing` aliases these constants for backward compatibility — do not redefine
 * the literal numbers anywhere else.
 */
final class PerfBudget
{
    /** A real JWT login. */
    public const LOGIN_MS = 500;

    /** `GET /cms-api/v1/admin/pages`. */
    public const ADMIN_PAGES_LIST_MS = 1000;

    /** A form submission (DataService::saveData). */
    public const FORM_SUBMIT_MS = 1000;

    /** Executing a single scheduled job. */
    public const SCHEDULED_JOB_EXECUTE_MS = 1500;

    /** `GET /cms-api/v1/admin/plugins`. */
    public const PLUGINS_LIST_MS = 1000;

    /**
     * A perf assertion warns between WARN_FACTOR× and HARD_FACTOR× the budget
     * and hard-fails above HARD_FACTOR× (mirrors the frontend e2e `measure`).
     */
    public const WARN_FACTOR = 1.5;
    public const HARD_FACTOR = 2.0;

    private function __construct()
    {
    }

    /**
     * Assert an elapsed wall-clock time stays within HARD_FACTOR× the budget.
     * Emits a non-failing warning line between WARN_FACTOR× and HARD_FACTOR× so
     * regressions are visible in CI output before they start blocking.
     */
    public static function assertWithinBudget(float $elapsedMs, int $budgetMs, string $label): void
    {
        $hardLimit = $budgetMs * self::HARD_FACTOR;

        if ($elapsedMs > $budgetMs * self::WARN_FACTOR && $elapsedMs <= $hardLimit) {
            fwrite(
                STDOUT,
                sprintf(
                    "\n[perf][WARN] %s %.0f ms exceeds %.1f× the %d ms budget (%.0f ms)\n",
                    $label,
                    $elapsedMs,
                    self::WARN_FACTOR,
                    $budgetMs,
                    $budgetMs * self::WARN_FACTOR
                )
            );
        }

        Assert::assertLessThanOrEqual(
            $hardLimit,
            $elapsedMs,
            sprintf(
                '%s took %.0f ms, exceeding %.0f ms (%.1f× the %d ms budget).',
                $label,
                $elapsedMs,
                $hardLimit,
                self::HARD_FACTOR,
                $budgetMs
            )
        );
    }
}
