<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS;

use App\Entity\User;
use App\Service\Cache\Core\CacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for caching user permissions during request lifecycle
 * This prevents N+1 queries when checking permissions multiple times within a single request
 * 
 * Uses unified CacheService with permissions category for consistent caching
 */
class UserPermissionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
        private readonly CacheService $cache
    ) {
    }

    /**
     * Get user permissions with caching
     * Uses optimized query to avoid N+1 issues
     *
     * @return list<string>
     */
    public function getUserPermissions(User $user): array
    {
        $userId = $user->getId();
        assert($userId !== null);

        $cacheKey = 'user_permissions_' . $userId;
        $cacheBuilder = $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);

        // Add entity scopes for each role
        foreach ($user->getUserRoles() as $role) {
            $roleId = $role->getId();
            assert($roleId !== null);
            $cacheBuilder = $cacheBuilder->withEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
        }

        /** @var list<string> $permissions */
        $permissions = $cacheBuilder->getItem($cacheKey, function () use ($userId) {
            return $this->fetchUserPermissionsFromDatabase($userId);
        });

        return $permissions;
    }

    /**
     * Get route permissions with caching
     * Uses router to get permissions from route options (already cached by ApiRouteLoader)
     *
     * @return list<string>
     */
    public function getRoutePermissions(string $routeName): array
    {
        return $this->getRouteGuard($routeName)['permissions'];
    }

    /**
     * Whether the route is declared public (`api_routes.is_public`).
     */
    public function isRoutePublic(string $routeName): bool
    {
        return $this->getRouteGuard($routeName)['is_public'];
    }

    /**
     * Cached route guard: permissions plus the public flag (F09).
     *
     * @return array{permissions: list<string>, is_public: bool}
     */
    public function getRouteGuard(string $routeName): array
    {
        /** @var array{permissions: list<string>, is_public: bool} $guard */
        $guard = $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->getItem("route_guard_{$routeName}", function () use ($routeName) {
                return $this->fetchRouteGuardFromRouter($routeName);
            });

        return $guard;
    }

    /**
     * Fetch user permissions from database
     *
     * @return list<string>
     */
    private function fetchUserPermissionsFromDatabase(int $userId): array
    {
        // Optimized query to get all permissions in one go
        $sql = '
            SELECT DISTINCT p.name
            FROM permissions p
            INNER JOIN rel_permissions_roles rp ON p.id = rp.id_permissions
            INNER JOIN rel_roles_users ur ON rp.id_roles = ur.id_roles
            WHERE ur.id_users = :userId
            ORDER BY p.name
        ';

        $stmt = $this->entityManager->getConnection()->prepare($sql);
        $stmt->bindValue('userId', $userId);
        $result = $stmt->executeQuery();

        /** @var list<string> $names */
        $names = array_column($result->fetchAllAssociative(), 'name');

        return $names;
    }

    /**
     * Fetch route permissions and public flag from the router collection.
     *
     * @return array{permissions: list<string>, is_public: bool}
     */
    private function fetchRouteGuardFromRouter(string $routeName): array
    {
        $route = $this->router->getRouteCollection()->get($routeName);
        if (!$route) {
            // Unknown route name under /cms-api/: treat as non-public with no
            // permissions so ApiSecurityListener refuses it (F09).
            return ['permissions' => [], 'is_public' => false];
        }

        /** @var list<string> $permissions */
        $permissions = $route->getOption('permissions') ?? [];
        $isPublic = (bool) ($route->getOption('is_public') ?? false);

        return [
            'permissions' => $permissions,
            'is_public' => $isPublic,
        ];
    }
}
