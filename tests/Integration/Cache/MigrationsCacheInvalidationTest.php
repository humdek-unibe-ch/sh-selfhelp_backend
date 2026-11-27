<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Cache;

use App\EventListener\MigrationsCacheInvalidationListener;
use App\Service\Cache\Core\CacheService;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Events;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * F216 regression: after a migration run the route-right lists and the
 * permission cache are rebuilt from the database, so a migration that renames
 * a permission or re-links a route is effective at once (before this, Redis
 * kept serving the old rights and the renamed route answered 403).
 *
 * The event is dispatched through the migrations bundle's own dispatcher
 * (the one `doctrine:migrations:migrate` uses), so a listener that is not
 * registered on that event manager fails here. Probes use unique `qa_` keys:
 * the Redis pool escapes the DAMA transaction, like the other cache tests.
 */
#[Group('security')]
final class MigrationsCacheInvalidationTest extends QaKernelTestCase
{
    public function testMigrateRunDropsRouteListsAndPermissionsButKeepsOtherCategories(): void
    {
        $cache = $this->service(CacheService::class);
        $suffix = bin2hex(random_bytes(8));
        $routes = 'qa_mig_routes_' . $suffix;
        $permissions = 'qa_mig_permissions_' . $suffix;
        $pages = 'qa_mig_pages_' . $suffix;

        $readRoutes = static fn (string $value): mixed => $cache
            ->withCategory(CacheService::CATEGORY_API_ROUTES)
            ->getList($routes, static fn (): string => $value);
        $readPermissions = static fn (string $value): mixed => $cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->getItem($permissions, static fn (): string => $value);
        $readPages = static fn (string $value): mixed => $cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->getItem($pages, static fn (): string => $value);

        self::assertSame('old-route', $readRoutes('old-route'));
        self::assertSame('old-permission', $readPermissions('old-permission'));
        self::assertSame('old-page', $readPages('old-page'));
        self::assertSame('old-route', $readRoutes('unused'), 'The route list is cached before the migrate run.');
        self::assertSame('old-permission', $readPermissions('unused'), 'The permission entry is cached before the migrate run.');

        $this->dispatchMigrated();

        self::assertSame('new-route', $readRoutes('new-route'), 'The route list is rebuilt after a migrate run.');
        self::assertSame('new-permission', $readPermissions('new-permission'), 'The permission entry is rebuilt after a migrate run.');
        self::assertSame('old-page', $readPages('unused'), 'Other categories stay warm: only routes and permissions are cleared.');
    }

    public function testRedisFailureIsLoggedAndDoesNotFailTheMigration(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'error') {
                    $this->errors[] = (string) $message;
                }
            }
        };
        $failing = new class(new TagAwareAdapter(new ArrayAdapter())) extends CacheService {
            public function invalidateAllListsInCategory(): void
            {
                throw new \RuntimeException('redis is down');
            }
        };

        $listener = new MigrationsCacheInvalidationListener($failing, $logger);

        self::assertFalse($listener->clear());
        self::assertCount(1, $logger->errors);
    }

    private function dispatchMigrated(): void
    {
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $factory);
        $factory->getEventDispatcher()->dispatchMigrationEvent(
            Events::onMigrationsMigrated,
            new MigrationPlanList([], 'up'),
            new MigratorConfiguration(),
        );
    }
}
