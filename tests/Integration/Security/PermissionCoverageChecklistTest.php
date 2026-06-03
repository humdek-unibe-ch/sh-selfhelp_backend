<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Repository\ApiRouteRepository;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Permission-coverage checklist guardrail (plan "permission coverage checklist").
 *
 * {@see \App\Tests\Integration\Routing\ApiRouteInventoryTest} proves every core
 * route is *guarded*. This test proves every guarded route GROUP is *tested*: it
 * maps each protected route group to the permission-matrix / controller test
 * class that exercises its allowed + denied paths, and FAILS when:
 *
 *   - a required protected group has no checklist entry (audit minimum);
 *   - a checklist entry points at a class that is missing or does not assert any
 *     permission-denial behaviour (401/403);
 *   - a protected `/admin/*` route group exists in `api_routes` but is neither on
 *     the checklist nor on the (only-shrinking) {@see self::UNCOVERED_ALLOWLIST};
 *   - the allowlist rots (an entry that is now covered or no longer exists).
 *
 * Adding a new admin route group without a permission test therefore becomes a
 * visible, failing reminder rather than a silent gap.
 */
#[Group('security')]
final class PermissionCoverageChecklistTest extends QaKernelTestCase
{
    /**
     * Protected route group => the test class covering its permission matrix.
     * Admin groups use the `/admin/<group>` path prefix; auth/forms groups are
     * keyed by their own prefix.
     *
     * @var array<string, array{prefix: string, test: class-string}>
     */
    private const CHECKLIST = [
        // --- audit minimum (required) ---
        'admin data' => ['prefix' => '/admin/data', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminDataPermissionTest::class],
        'admin data-access' => ['prefix' => '/admin/data-access', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminDataAccessControllerTest::class],
        'admin scheduled jobs' => ['prefix' => '/admin/scheduled-jobs', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminScheduledJobPermissionTest::class],
        'admin pages' => ['prefix' => '/admin/pages', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminPagePermissionTest::class],
        'admin sections' => ['prefix' => '/admin/sections', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminSectionPermissionTest::class],
        'admin cache' => ['prefix' => '/admin/cache', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminCacheControllerTest::class],
        'admin plugins' => ['prefix' => '/admin/plugins', 'test' => \App\Tests\Controller\Api\V1\Admin\Plugin\AdminPluginPermissionTest::class],
        'auth/profile self-service' => ['prefix' => '/auth', 'test' => \App\Tests\Controller\Api\V1\Auth\ProfileControllerTest::class],
        'forms/data submission' => ['prefix' => '/forms', 'test' => \App\Tests\Controller\Api\V1\Frontend\FormControllerTest::class],

        // --- additional covered admin groups ---
        'admin actions' => ['prefix' => '/admin/actions', 'test' => \App\Tests\Controller\Api\V1\Admin\ActionPermissionTest::class],
        'admin ai' => ['prefix' => '/admin/ai', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminAiPermissionTest::class],
        'admin api-routes' => ['prefix' => '/admin/api-routes', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminApiRoutePermissionTest::class],
        'admin assets' => ['prefix' => '/admin/assets', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminAssetPermissionTest::class],
        'admin audit' => ['prefix' => '/admin/audit', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminAuditControllerTest::class],
        'admin cms-preferences' => ['prefix' => '/admin/cms-preferences', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminCmsPreferenceControllerTest::class],
        'admin groups' => ['prefix' => '/admin/groups', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminGroupPermissionTest::class],
        'admin languages' => ['prefix' => '/admin/languages', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminLanguagePermissionTest::class],
        'admin page-keywords' => ['prefix' => '/admin/page-keywords', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminPageKeywordPermissionTest::class],
        'admin permissions' => ['prefix' => '/admin/permissions', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminPermissionsPermissionTest::class],
        'admin registration-codes' => ['prefix' => '/admin/registration-codes', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminRegistrationCodePermissionTest::class],
        'admin roles' => ['prefix' => '/admin/roles', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminRolePermissionTest::class],
        'admin styles' => ['prefix' => '/admin/styles', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminStylePermissionTest::class],
        'admin users' => ['prefix' => '/admin/users', 'test' => \App\Tests\Controller\Api\V1\Admin\AdminUserPermissionTest::class],
    ];

    /**
     * Audit-mandated minimum groups that MUST be on the checklist.
     *
     * @var list<string>
     */
    private const REQUIRED_GROUPS = [
        'admin data',
        'admin data-access',
        'admin scheduled jobs',
        'admin pages',
        'admin sections',
        'admin cache',
        'admin plugins',
        'auth/profile self-service',
        'forms/data submission',
    ];

    /**
     * Protected `/admin/<group>` segments that intentionally have NO dedicated
     * permission-matrix test yet. This list MUST only shrink — each entry is a
     * tracked gap to be covered by a later phase, not a silent omission.
     *
     * It is currently EMPTY: every protected core admin route group has a
     * permission test on the CHECKLIST above. Do NOT add a new entry to silence
     * a missing test — add the permission-matrix test + CHECKLIST entry instead.
     *
     * Modelled as a method (not a `const []`) so the type stays `list<string>`
     * for the consumers below; a literal empty constant would make PHPStan flag
     * the allowlist lookups as dead the moment the ratchet reaches zero.
     *
     * @return list<string>
     */
    private function uncoveredAllowlist(): array
    {
        return [];
    }

    public function testRequiredGroupsAreOnTheChecklist(): void
    {
        $missing = array_values(array_diff(self::REQUIRED_GROUPS, array_keys(self::CHECKLIST)));

        self::assertSame(
            [],
            $missing,
            "Audit-required protected route group(s) missing from the permission checklist: " . implode(', ', $missing)
        );
    }

    public function testEveryChecklistTestClassExistsAndAssertsPermissionBehaviour(): void
    {
        $problems = [];

        foreach (self::CHECKLIST as $label => $entry) {
            $class = $entry['test'];

            if (!class_exists($class)) {
                $problems[] = sprintf('%s: test class %s does not exist', $label, $class);
                continue;
            }

            $file = (new \ReflectionClass($class))->getFileName();
            $source = is_string($file) && is_file($file) ? (string) file_get_contents($file) : '';
            if (!preg_match('/(401|403|Unauthorized|Forbidden|PermissionMatrix|assertForbidden|assertAdminOnly)/', $source)) {
                $problems[] = sprintf('%s: %s does not assert any permission-denial behaviour (401/403)', $label, $class);
            }
        }

        self::assertSame([], $problems, "Permission checklist problems:\n" . implode("\n", $problems));
    }

    public function testEveryProtectedAdminRouteGroupIsCoveredOrAllowlisted(): void
    {
        $coveredAdminGroups = $this->checklistAdminGroups();
        $protectedGroups = $this->protectedAdminGroupsFromDatabase();

        $uncovered = [];
        foreach (array_keys($protectedGroups) as $group) {
            if (in_array($group, $coveredAdminGroups, true)) {
                continue;
            }
            if (in_array($group, $this->uncoveredAllowlist(), true)) {
                continue;
            }
            $uncovered[] = $group;
        }

        self::assertSame(
            [],
            $uncovered,
            "Protected /admin route group(s) with no permission test and not on the UNCOVERED_ALLOWLIST.\n"
            . "Add a permission-matrix test (preferred) and a CHECKLIST entry, or — after review — add the group to UNCOVERED_ALLOWLIST:\n"
            . implode("\n", $uncovered)
        );
    }

    public function testUncoveredAllowlistHasNoStaleEntries(): void
    {
        $coveredAdminGroups = $this->checklistAdminGroups();
        $protectedGroups = $this->protectedAdminGroupsFromDatabase();

        $stale = [];
        foreach ($this->uncoveredAllowlist() as $group) {
            if (!array_key_exists($group, $protectedGroups)) {
                $stale[] = $group . ' (no longer a protected admin group)';
            } elseif (in_array($group, $coveredAdminGroups, true)) {
                $stale[] = $group . ' (now on the checklist — remove from allowlist)';
            }
        }

        self::assertSame(
            [],
            $stale,
            "Stale UNCOVERED_ALLOWLIST entries (the allowlist must only shrink):\n" . implode("\n", $stale)
        );
    }

    /**
     * Admin `<group>` segments covered by a CHECKLIST `/admin/<group>` prefix.
     *
     * @return list<string>
     */
    private function checklistAdminGroups(): array
    {
        $groups = [];
        foreach (self::CHECKLIST as $entry) {
            if (preg_match('#^/admin/([^/]+)#', $entry['prefix'], $m) === 1) {
                $groups[] = $m[1];
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * Protected (permission-guarded) core `/admin/<group>` segments from the
     * live route table.
     *
     * @return array<string, true>
     */
    private function protectedAdminGroupsFromDatabase(): array
    {
        $rows = $this->service(ApiRouteRepository::class)->findAllRoutesWithPermissionsAsArray();

        $groups = [];
        foreach ($rows as $row) {
            if (($row['id_plugins'] ?? null) !== null) {
                continue;
            }
            $permissions = is_array($row['permission_names'] ?? null) ? $row['permission_names'] : [];
            if ($permissions === []) {
                continue;
            }
            $path = is_scalar($row['path'] ?? null) ? (string) $row['path'] : '';
            if (preg_match('#^/admin/([^/]+)#', $path, $m) === 1) {
                $groups[$m[1]] = true;
            }
        }

        return $groups;
    }
}
