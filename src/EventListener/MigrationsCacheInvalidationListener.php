<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Cache\Core\CacheService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Migrations\Event\MigrationsEventArgs;
use Doctrine\Migrations\Events;
use Psr\Log\LoggerInterface;

/**
 * Clears the route-right and permission caches after every successful
 * `doctrine:migrations:migrate` run (F216).
 *
 * A migration can rename a permission or re-link an API route to another
 * permission for everybody at once. Both are read from Redis (`api_routes`
 * lists, `permissions` entries), so without this the old rights are served
 * until someone clears the caches by hand and the renamed route answers 403.
 * One listener covers every caller of the migrate command: the manager's
 * update step, `scripts/fresh-test-install.php`, `app:test:reset-db` and a
 * local run.
 *
 * Clearing the two categories is the narrowest correct thing here: a migrate
 * run changes routes and permissions for every user (rules.md section 3). A
 * Redis outage must not fail the migration, so errors are logged and
 * swallowed; `php bin/console cache:clear-api-routes` is still there for a
 * manual route change.
 */
#[AsDoctrineListener(event: Events::onMigrationsMigrated)]
final class MigrationsCacheInvalidationListener
{
    public function __construct(
        private readonly CacheService $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onMigrationsMigrated(MigrationsEventArgs $args): void
    {
        $this->clear();
    }

    /**
     * @return bool true when both categories were invalidated
     */
    public function clear(): bool
    {
        try {
            $this->cache
                ->withCategory(CacheService::CATEGORY_API_ROUTES)
                ->invalidateAllListsInCategory();
            $this->cache
                ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                ->invalidateCategory();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Could not clear the route and permission caches after migrating.', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
