<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\MaintenanceModeListener;
use App\Exception\ServiceException;
use App\Service\CMS\UserPermissionService;
use App\Service\System\MaintenanceModeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit coverage for the maintenance 503 gate.
 *
 * Asserts the observable contract: when maintenance is OFF every request passes;
 * when ON, normal /cms-api traffic gets a clean 503, while the health probe, the
 * Manager update loop, authentication, and admin.system.* routes stay reachable.
 */
final class MaintenanceModeListenerTest extends TestCase
{
    /**
     * @param array<string, list<string>> $routePermissions
     */
    private function listener(bool $maintenanceOn, array $routePermissions = []): MaintenanceModeListener
    {
        $maintenance = $this->createStub(MaintenanceModeService::class);
        $maintenance->method('isEnabled')->willReturn($maintenanceOn);

        $permissions = $this->createStub(UserPermissionService::class);
        $permissions->method('getRoutePermissions')->willReturnCallback(
            static fn (string $route): array => $routePermissions[$route] ?? [],
        );

        return new MaintenanceModeListener($maintenance, $permissions);
    }

    private function event(string $path, string $routeName, string $method = 'GET'): ControllerEvent
    {
        $request = Request::create($path, $method);
        $request->attributes->set('_route', $routeName);
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ControllerEvent($kernel, static fn () => new Response(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function assert503(MaintenanceModeListener $listener, ControllerEvent $event): void
    {
        try {
            $listener->onKernelController($event);
            self::fail('Expected a 503 ServiceException during maintenance.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $e->getCode());
        }
    }

    public function testPassesEverythingWhenMaintenanceOff(): void
    {
        $listener = $this->listener(false);
        $listener->onKernelController($this->event('/cms-api/v1/pages', 'frontend_pages_v1'));
        $this->expectNotToPerformAssertions();
    }

    public function testIgnoresNonApiTraffic(): void
    {
        $listener = $this->listener(true);
        $listener->onKernelController($this->event('/some/other/path', 'app_other'));
        $this->expectNotToPerformAssertions();
    }

    public function testBlocksNormalApiTrafficDuringMaintenance(): void
    {
        $listener = $this->listener(true, ['frontend_pages_v1' => ['admin.page.read']]);
        $this->assert503($listener, $this->event('/cms-api/v1/pages', 'frontend_pages_v1'));
    }

    public function testHealthProbeStaysReachable(): void
    {
        $listener = $this->listener(true);
        $listener->onKernelController($this->event('/cms-api/v1/health', 'health'));
        $this->expectNotToPerformAssertions();
    }

    public function testManagerLoopStaysReachable(): void
    {
        $listener = $this->listener(true);
        $listener->onKernelController(
            $this->event('/cms-api/v1/manager/system/update/pending', 'manager_system_update_pending'),
        );
        $this->expectNotToPerformAssertions();
    }

    public function testAuthStaysReachable(): void
    {
        $listener = $this->listener(true);
        $listener->onKernelController($this->event('/cms-api/v1/auth/login', 'auth_login_v1', 'POST'));
        $this->expectNotToPerformAssertions();
    }

    public function testAdminSystemRoutesStayReachable(): void
    {
        $listener = $this->listener(true, [
            'admin_system_maintenance_set' => ['admin.system.maintenance'],
        ]);
        $listener->onKernelController(
            $this->event('/cms-api/v1/admin/system/maintenance', 'admin_system_maintenance_set', 'PUT'),
        );
        $this->expectNotToPerformAssertions();
    }
}
