<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Entity\Field;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\PageRoute;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Route placeholder visibility for the interpolation picker and query-preview
 * endpoint must respect page read data-access grants via
 * {@see \App\Service\CMS\Common\SectionAccessibleRouteService}.
 */
#[PhpUnitGroup('security')]
final class RouteVariableVisibilityTest extends QaWebTestCase
{
    private const INTERPOLATION = '/cms-api/v1/admin/interpolation/variables?context=section&id=%d';
    private const QUERY_PREVIEW = '/cms-api/v1/admin/data/query-preview';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private RoleFactory $roles;
    private RoleDataAccessFactory $grants;
    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->roles = new RoleFactory($this->em);
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
    }

    public function testRoutePlaceholderVisibleWhenPageReadGranted(): void
    {
        [$sectionId, $token] = $this->setUpActor(grantPageRead: true);

        $interpolation = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf(self::INTERPOLATION, $sectionId), null, $token),
        );
        $variables = is_array($interpolation['data_variables'] ?? null) ? $interpolation['data_variables'] : [];
        self::assertArrayHasKey('route.record_id', $variables);
        self::assertSame('route.record_id', $variables['route.record_id']);

        $preview = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::QUERY_PREVIEW, ['section_id' => $sectionId], $token),
        );
        $routeParams = is_array($preview['route_params'] ?? null) ? $preview['route_params'] : [];
        $routeRequirements = is_array($preview['route_requirements'] ?? null) ? $preview['route_requirements'] : [];
        self::assertArrayHasKey('record_id', $routeParams);
        self::assertArrayHasKey('record_id', $routeRequirements);
    }

    public function testRoutePlaceholderHiddenWhenPageReadDenied(): void
    {
        [$sectionId, $token] = $this->setUpActor(grantPageRead: false);

        $interpolation = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf(self::INTERPOLATION, $sectionId), null, $token),
        );
        $variables = is_array($interpolation['data_variables'] ?? null) ? $interpolation['data_variables'] : [];
        self::assertArrayNotHasKey('route.record_id', $variables);

        $preview = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::QUERY_PREVIEW, ['section_id' => $sectionId], $token),
        );
        $routeParams = is_array($preview['route_params'] ?? null) ? $preview['route_params'] : [];
        $routeRequirements = is_array($preview['route_requirements'] ?? null) ? $preview['route_requirements'] : [];
        self::assertArrayNotHasKey('record_id', $routeParams);
        self::assertArrayNotHasKey('record_id', $routeRequirements);
    }

    /**
     * @return array{0: int, 1: string} section id, auth token
     */
    private function setUpActor(bool $grantPageRead): array
    {
        $suffix = $grantPageRead ? 'grant' : 'deny';
        $table = (new DataTableFactory($this->em, $this->service(DataService::class)))
            ->createTable('qa_route_vis_' . $suffix);

        $page = $this->pages->createPage('qa_route_vis_' . $suffix, openAccess: false);
        $this->attachParameterizedRoute($page, '/qa-route-vis-' . $suffix . '/{record_id}');

        $section = $this->pages->createSection('qa_route_vis_section_' . $suffix, 'entry-record');
        $this->pages->linkSectionToPage($page, $section, 10);
        $this->setSectionField($section, 'data_table', (string) $table->getId(), 1);
        $this->setSectionField($section, 'load_record_from', 'record_id', 1);

        $role = $this->roles->createRole('qa_route_vis_role_' . $suffix);
        $this->roles->grantPermission($role, 'admin.page.read');
        $this->roles->grantPermission($role, 'admin.data.read');
        $this->grants->grantDataTableAccess(
            $role,
            (int) $table->getId(),
            DataAccessSecurityService::PERMISSION_READ,
        );
        if ($grantPageRead) {
            $this->grants->grantPageAccess($role, (int) $page->getId(), DataAccessSecurityService::PERMISSION_READ);
        }

        $email = 'qa.route.vis.' . $suffix . '@selfhelp.test';
        $user = $this->users->createUser($email, 'QA Route Visibility', roles: [$role]);
        $this->grants->assignRoleToUser($user, $role);

        $this->pages->invalidatePageScopedCaches();

        return [(int) $section->getId(), $this->loginAs($email)];
    }

    private function attachParameterizedRoute(Page $page, string $pathPattern): void
    {
        $route = new PageRoute();
        $route->setPage($page);
        $route->setPathPattern($pathPattern);
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();
    }

    private function setSectionField(Section $section, string $fieldName, string $content, int $languageId): void
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $fieldName]);
        self::assertInstanceOf(Field::class, $field, sprintf('Missing seeded field "%s".', $fieldName));
        $language = $this->em->getRepository(Language::class)->find($languageId);
        self::assertInstanceOf(Language::class, $language);

        $translation = new SectionsFieldsTranslation();
        $translation->setSection($section);
        $translation->setField($field);
        $translation->setLanguage($language);
        $translation->setContent($content);
        $this->em->persist($translation);
        $this->em->flush();
    }
}
