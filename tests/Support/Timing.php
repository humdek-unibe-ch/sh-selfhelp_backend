<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Central home for test timing constants so no magic numbers leak into test
 * bodies (anti-flakiness, plan §9). Performance budgets (plan §28) live here
 * too; golden/smoke tests assert against these rather than hard-coded values.
 *
 * Values are wall-clock milliseconds in the TEST environment, which is the
 * most generous tier (staging/production budgets are tighter).
 */
final class Timing
{
    /**
     * Performance budgets for critical APIs (plan §28), in milliseconds.
     * Aliases of {@see PerfBudget} — the canonical source — kept so existing
     * callers keep working without redefining the literal numbers.
     */
    public const BUDGET_LOGIN_MS = PerfBudget::LOGIN_MS;
    public const BUDGET_ADMIN_PAGES_LIST_MS = PerfBudget::ADMIN_PAGES_LIST_MS;
    public const BUDGET_FORM_SUBMIT_MS = PerfBudget::FORM_SUBMIT_MS;
    public const BUDGET_SCHEDULED_JOB_EXECUTE_MS = PerfBudget::SCHEDULED_JOB_EXECUTE_MS;
    public const BUDGET_ADMIN_PLUGINS_LIST_MS = PerfBudget::PLUGINS_LIST_MS;

    /**
     * Whole golden-workflow wall-clock ceiling (plan §6.2: a golden test runs
     * in well under this). Used as the hard assertion bound for the
     * form -> action -> scheduled-job chain.
     */
    public const BUDGET_GOLDEN_CHAIN_MS = 5000;

    /**
     * A test asserting a performance budget fails hard above HARD_FACTOR×
     * the budget and is allowed to warn between WARN_FACTOR× and HARD_FACTOR×
     * (plan §28). Tests use HARD_FACTOR for the assertion ceiling.
     */
    public const PERF_WARN_FACTOR = PerfBudget::WARN_FACTOR;
    public const PERF_HARD_FACTOR = PerfBudget::HARD_FACTOR;

    private function __construct()
    {
    }
}
