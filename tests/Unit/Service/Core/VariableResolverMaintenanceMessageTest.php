<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Core;

use App\Repository\UserRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\GlobalVariableService;
use App\Service\Core\UserContextAwareService;
use App\Service\Core\VariableResolverService;
use App\Service\System\MaintenanceModeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Unit guard for the maintenance-note end of the interpolation chain.
 *
 * The seeded maintenance page renders the operator's live note through the
 * `{{system.maintenance_message}}` token. That token only resolves if
 * {@see VariableResolverService::getAllVariables()} exposes the message set on
 * {@see MaintenanceModeService} as the `maintenance_message` variable. This test
 * pins both branches of that wiring in isolation:
 *   - a set note is surfaced verbatim;
 *   - no note falls back to the friendly default (never a blank placeholder).
 *
 * Maintenance state is driven through a REAL service pointed at a throwaway temp
 * dir (never the repo's `var/`), so toggling it here cannot put a running
 * instance into maintenance. The remaining collaborators are stubs because the
 * anonymous, globals-disabled path never touches the DB, router, or cache.
 */
final class VariableResolverMaintenanceMessageTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/shqa-varres-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $lock = $this->projectDir . '/var/maintenance_mode.lock';
        if (is_file($lock)) {
            @unlink($lock);
        }
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
    }

    public function testSurfacesTheOperatorNoteWhenMaintenanceIsOn(): void
    {
        $maintenance = new MaintenanceModeService($this->projectDir, false);
        $maintenance->enable('Back online at 14:00 UTC.', 'qa.admin@selfhelp.test');

        $variables = $this->makeResolver($maintenance)->getAllVariables(null, 1, false);

        self::assertSame('Back online at 14:00 UTC.', $variables['maintenance_message']);
    }

    public function testFallsBackToTheDefaultMessageWhenNoNoteIsSet(): void
    {
        $maintenance = new MaintenanceModeService($this->projectDir, false);

        $variables = $this->makeResolver($maintenance)->getAllVariables(null, 1, false);

        self::assertSame(
            VariableResolverService::DEFAULT_MAINTENANCE_MESSAGE,
            $variables['maintenance_message'],
        );
    }

    private function makeResolver(MaintenanceModeService $maintenance): VariableResolverService
    {
        // Pure constructor placeholders: resolving the maintenance note for an
        // anonymous request with globals disabled never calls any of them, so
        // stubs (no expectation tracking) keep the test focused and notice-free.
        return new VariableResolverService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserRepository::class),
            $this->createStub(CacheService::class),
            new RequestStack(),
            $this->createStub(RouterInterface::class),
            $this->createStub(UserContextAwareService::class),
            $this->createStub(GlobalVariableService::class),
            $maintenance,
        );
    }
}
