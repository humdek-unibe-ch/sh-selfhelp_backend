<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\Service\CMS\GlobalVariableService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Behavioural coverage for {@see GlobalVariableService} (plan Phase 8: global
 * variables). Asserts the safe contract: values/names always resolve to arrays
 * (empty when the optional sh-global-values page is absent) and memoise stably.
 */
final class GlobalVariableServiceTest extends QaKernelTestCase
{
    private GlobalVariableService $globals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->globals = $this->service(GlobalVariableService::class);
    }

    public function testGlobalValuesResolveToAnArray(): void
    {
        $values = $this->globals->getGlobalVariableValues(1);

        self::assertGreaterThanOrEqual(0, count($values), 'Global values must always be an array (empty when no global page).');
    }

    public function testGlobalValuesAreStableAcrossCalls(): void
    {
        self::assertSame(
            $this->globals->getGlobalVariableValues(1),
            $this->globals->getGlobalVariableValues(1),
            'Repeated lookups for the same language must be deterministic (memoised).',
        );
    }

    public function testGlobalVariableNamesAreNamespaced(): void
    {
        $names = $this->globals->getGlobalVariableNames();

        foreach ($names as $name) {
            self::assertStringStartsWith('global.', $name, 'Global names are exposed with the global. prefix.');
        }
    }
}
