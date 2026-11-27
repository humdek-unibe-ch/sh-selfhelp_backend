<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\AdminUserService;
use App\Service\CMS\DataService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * F269: a member's data write clears the table scope (so form-record reads are
 * fresh) and does not bump the writer's user scope (profile / groups stay hits).
 */
#[Group('form-list')]
final class DataWriteUserScopeTest extends QaKernelTestCase
{
    public function testCurrentUserTableWriteKeepsUserDataCacheHit(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $userId = (int) $user->getId();

        $this->service(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );

        $dataService = $this->service(DataService::class);
        $cache = $this->service(CacheService::class);
        $tables = new DataTableFactory($this->em, $dataService);
        $tableName = 'qa_f269_write_scope';
        $table = $tables->createTable($tableName);
        $tableId = (int) $table->getId();

        // Force has_current_user_config so the old path would have bumped the
        // writer user scope; the table-scope bump alone must still refresh form reads.
        $cache->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId)
            ->getList(
                "data_table_current_user_config_{$tableId}",
                static fn (): array => [
                    'has_current_user_config' => true,
                    'has_global_config' => false,
                ],
            );

        $groupsBefore = $this->service(AdminUserService::class)->getUserGroups($userId);
        self::assertNotSame([], $groupsBefore);

        $formComputes = 0;
        $formKey = "form_record_data_{$tableName}_{$userId}";
        $cache->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList($formKey, static function () use (&$formComputes): array {
                ++$formComputes;

                return [['qa_field' => 'stale']];
            });
        self::assertSame(1, $formComputes);

        $tables->addRow($tableName, ['qa_field' => 'fresh-after-write'], $userId);

        $formAfter = $cache->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList($formKey, static function () use (&$formComputes): array {
                ++$formComputes;

                return [['qa_field' => 'recomputed']];
            });
        self::assertSame(2, $formComputes, 'The form-record cache must miss after the table-scope clear.');
        self::assertSame([['qa_field' => 'recomputed']], $formAfter);

        $groupsComputes = 0;
        $groupsAfter = $cache->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem(
                "user_groups_{$userId}",
                static function () use (&$groupsComputes, $groupsBefore): array {
                    ++$groupsComputes;

                    return $groupsBefore;
                },
            );
        self::assertSame(0, $groupsComputes, 'A cached user-groups read must stay a hit (no user-scope bump).');
        self::assertSame($groupsBefore, $groupsAfter);
    }
}
