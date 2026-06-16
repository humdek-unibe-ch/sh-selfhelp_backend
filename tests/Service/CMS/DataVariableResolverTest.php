<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\Service\CMS\DataVariableResolver;
use PHPUnit\Framework\TestCase;

/**
 * Guards the system-variable list the CMS `{{ }}` editor offers as autocomplete
 * suggestions (returned to the SPA as `data_variables`).
 *
 * `getSystemVariables()` is a pure, dependency-free list, so the resolver is
 * built without its constructor to keep this a fast unit test (no DB / cache).
 */
final class DataVariableResolverTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function systemVariables(): array
    {
        $resolver = (new \ReflectionClass(DataVariableResolver::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DataVariableResolver::class, 'getSystemVariables');
        $method->setAccessible(true);

        /** @var list<string> $vars */
        $vars = $method->invoke($resolver);

        return $vars;
    }

    public function testEditorSuggestsTheMaintenanceMessageVariable(): void
    {
        self::assertContains(
            'system.maintenance_message',
            $this->systemVariables(),
            'The CMS editor must suggest system.maintenance_message so operators can place the '
                . 'maintenance note that the seeded maintenance page renders.',
        );
    }

    public function testEstablishedSystemVariablesStayAvailable(): void
    {
        $vars = $this->systemVariables();

        // Regression guard: adding the maintenance variable must not drop the
        // existing contract the section editor relies on.
        foreach (['system.project_name', 'system.current_datetime', 'system.user_email'] as $expected) {
            self::assertContains($expected, $vars);
        }
    }
}
