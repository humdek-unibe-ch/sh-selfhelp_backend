<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\DataTable;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\Role;
use App\Entity\User;
use App\Service\CMS\Admin\AdminDataAccessService;
use App\Service\CMS\Admin\AdminGroupService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * View-only grants of the read/update persona (F239, least-privilege walk).
 *
 * `qa_read_update` (seeded by {@see QaBaselineFixture}) holds every core read
 * and update right, but on the QA items it gets the VIEW bit only, so the walk
 * proves that item controls follow the server's per-item flags (rule 4.11,
 * F234) and not the route rights. Items:
 *
 *   page        qa-feedback      ({@see QaFrontendGoldenFixture})
 *   data table  qa_events_table  ({@see QaFormListFixture})
 *   group       qa_access_group  (created here; no QA fixture seeds a group)
 *   asset       not seeded: no QA fixture creates an asset.
 *
 * Group and grants go through {@see AdminGroupService} and
 * {@see AdminDataAccessService} as qa.admin (Testing Rule 8), never raw SQL.
 */
final class QaAccessPersonaFixture extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public const QA_ACCESS_GROUP_NAME = 'qa_access_group';

    public function __construct(
        private readonly AdminGroupService $adminGroupService,
        private readonly AdminDataAccessService $adminDataAccessService,
        private readonly LookupService $lookupService,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['qa'];
    }

    /**
     * @return list<class-string<\Doctrine\Common\DataFixtures\FixtureInterface>>
     */
    public function getDependencies(): array
    {
        return [QaBaselineFixture::class, QaFrontendGoldenFixture::class, QaFormListFixture::class];
    }

    public function load(ObjectManager $manager): void
    {
        $qaAdmin = $manager->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        $role = $manager->getRepository(Role::class)->findOneBy(['name' => QaBaselineFixture::QA_READ_UPDATE_ROLE]);
        $page = $manager->getRepository(Page::class)->findOneBy(['keyword' => QaFrontendGoldenFixture::QA_FORM_PAGE_KEYWORD]);
        $table = $manager->getRepository(DataTable::class)->findOneBy(['name' => QaFormListFixture::EVENTS_TABLE_NAME]);
        if (!$qaAdmin instanceof User || !$role instanceof Role || !$page instanceof Page || !$table instanceof DataTable) {
            throw new \RuntimeException(
                'QA access persona fixture cannot seed: qa.admin, role qa_read_update, page qa-feedback or table '
                . 'qa_events_table is missing. The other qa fixtures must load first.'
            );
        }
        $qaAdminId = (int) $qaAdmin->getId();

        if (!$manager->getRepository(Group::class)->findOneBy(['name' => self::QA_ACCESS_GROUP_NAME]) instanceof Group) {
            $this->adminGroupService->createGroup([
                'name' => self::QA_ACCESS_GROUP_NAME,
                'description' => 'QA group the read/update persona may only view',
            ]);
        }
        $group = $manager->getRepository(Group::class)->findOneBy(['name' => self::QA_ACCESS_GROUP_NAME]);
        if (!$group instanceof Group) {
            throw new \RuntimeException('QA access persona fixture could not create group ' . self::QA_ACCESS_GROUP_NAME . '.');
        }

        $grants = [];
        foreach ([
            LookupService::RESOURCE_TYPES_PAGES => (int) $page->getId(),
            LookupService::RESOURCE_TYPES_DATA_TABLE => (int) $table->getId(),
            LookupService::RESOURCE_TYPES_GROUP => (int) $group->getId(),
        ] as $resourceType => $resourceId) {
            $typeId = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, $resourceType);
            if ($typeId === null) {
                throw new \RuntimeException('QA access persona fixture: resource type lookup missing: ' . $resourceType);
            }
            $grants[] = (object) [
                'resource_type_id' => $typeId,
                'resource_id' => $resourceId,
                'crud_permissions' => DataAccessSecurityService::PERMISSION_READ,
            ];
        }

        $this->adminDataAccessService->setRolePermissions($qaAdminId, (int) $role->getId(), $grants);
    }
}
