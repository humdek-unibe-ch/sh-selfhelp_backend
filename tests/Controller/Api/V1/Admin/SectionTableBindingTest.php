<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\User;
use App\DataFixtures\Test\QaBaselineFixture;
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
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F07 (decided, rules.md 9): an editor may bind a data table into a section only
 * when their role can read that table. The check happens when the section is
 * saved. Once bound, visitors see the data without their own grant.
 *
 * Both ways of binding a table are covered: the `data_table` property of a
 * style, and a `table` inside the section's `data_config`. A refusal must leave
 * the section unchanged.
 *
 * C8 (H7): the same rule for the items of a plugin resource type. A style field
 * that the plugin lists in `styles[].resourceFields` holds the id of one item;
 * the editor may bind it only when their role holds the view grant on that item,
 * else 403 `resource_not_bindable` and nothing is written, on save and on import.
 * The superuser passes; clearing the field passes (QA plugin
 * {@see QaItemPluginFixture}).
 */
#[PhpUnitGroup('security')]
final class SectionTableBindingTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private UserFactory $users;
    private RoleFactory $roles;
    private RoleDataAccessFactory $grants;
    private DataTableFactory $tables;

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
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->roles = new RoleFactory($this->em);
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $this->tables = new DataTableFactory($this->em, $this->service(DataService::class));
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    public function testEditorCannotBindATableTheirRoleCannotRead(): void
    {
        $world = $this->world('qa_f07_deny');

        $envelope = $this->bindProperty($world['token'], $world['page'], $world['section'], $world['tableX']);

        $this->assertEnvelope403($envelope);
        self::assertNull($this->storedDataTable($world['section']), 'A refused save must leave the section unchanged.');
    }

    public function testEditorCanBindATableTheirRoleCanRead(): void
    {
        $world = $this->world('qa_f07_allow');

        $envelope = $this->bindProperty($world['token'], $world['page'], $world['section'], $world['tableY']);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame((string) $world['tableY'], $this->storedDataTable($world['section']));
    }

    public function testEditorCannotBindAnUnreadableTableThroughDataConfig(): void
    {
        $world = $this->world('qa_f07_config_deny');

        $envelope = $this->saveDataConfig($world['token'], $world['page'], $world['section'], 'qa_f07_config_deny_x');

        $this->assertEnvelope403($envelope);
        self::assertNull($this->storedDataConfig($world['section']));
    }

    public function testEditorCanBindAReadableTableThroughDataConfig(): void
    {
        $world = $this->world('qa_f07_config_allow');

        $envelope = $this->saveDataConfig($world['token'], $world['page'], $world['section'], 'qa_f07_config_allow_y');

        $this->assertEnvelopeSuccess($envelope);
        self::assertNotNull($this->storedDataConfig($world['section']));
    }

    public function testClearingTheBindingIsAlwaysAllowed(): void
    {
        $world = $this->world('qa_f07_clear');

        $envelope = $this->bindProperty($world['token'], $world['page'], $world['section'], null);

        $this->assertEnvelopeSuccess($envelope);
    }

    public function testAdminCanBindAnyTable(): void
    {
        $world = $this->world('qa_f07_admin');

        $envelope = $this->bindProperty($this->loginAsQaAdmin(), $world['page'], $world['section'], $world['tableX']);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame((string) $world['tableX'], $this->storedDataTable($world['section']));
    }

    public function testEditorCannotImportASectionThatBindsAnUnreadableTable(): void
    {
        $world = $this->world('qa_f07_import_deny');
        $before = $this->sectionCountOnPage($world['page']);

        $envelope = $this->importSection($world['token'], $world['page'], [
            'section_name' => 'qa_f07_imported',
            'style_name' => 'entry-table',
            'fields' => ['data_table' => ['all' => ['content' => (string) $world['tableX']]]],
        ]);

        $this->assertEnvelope403($envelope);
        self::assertSame($before, $this->sectionCountOnPage($world['page']), 'A refused import must not create any section.');
    }

    public function testEditorCannotImportDataConfigThatBindsAnUnreadableTable(): void
    {
        $world = $this->world('qa_f07_import_config_deny');
        $before = $this->sectionCountOnPage($world['page']);

        $envelope = $this->importSection($world['token'], $world['page'], [
            'section_name' => 'qa_f07_imported_config',
            'style_name' => 'container',
            'children' => [[
                'section_name' => 'qa_f07_imported_config_child',
                'style_name' => 'container',
                'global_fields' => [
                    'data_config' => (string) json_encode([['table' => 'qa_f07_import_config_deny_x', 'retrieve' => 'all']]),
                ],
            ]],
        ]);

        $this->assertEnvelope403($envelope);
        self::assertSame($before, $this->sectionCountOnPage($world['page']), 'A refused import must not create any section.');
    }

    public function testEditorCanImportASectionThatBindsAReadableTable(): void
    {
        $world = $this->world('qa_f07_import_allow');
        $before = $this->sectionCountOnPage($world['page']);

        $envelope = $this->importSection($world['token'], $world['page'], [
            'section_name' => 'qa_f07_imported_ok',
            'style_name' => 'entry-table',
            'fields' => ['data_table' => ['all' => ['content' => (string) $world['tableY']]]],
        ]);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame($before + 1, $this->sectionCountOnPage($world['page']));
    }

    // -- C8: the items of a plugin resource type -------------------------------------

    public function testEditorCannotBindAPluginItemTheyMayNotView(): void
    {
        $world = $this->pluginWorld('qa_c8_deny');

        $envelope = $this->bindPluginItem($world['token'], $world['page'], $world['section'], (string) $world['hidden']);

        $this->assertEnvelope403($envelope);
        self::assertSame('resource_not_bindable', $envelope['error'] ?? null);
        self::assertNull($this->storedFieldValue($world['section'], QaItemPluginFixture::FIELD), 'A refused save must leave the section unchanged.');
    }

    public function testEditorCanBindAPluginItemTheyMayView(): void
    {
        $world = $this->pluginWorld('qa_c8_allow');

        $envelope = $this->bindPluginItem($world['token'], $world['page'], $world['section'], (string) $world['visible']);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame((string) $world['visible'], $this->storedFieldValue($world['section'], QaItemPluginFixture::FIELD));
    }

    public function testEditorCannotBindAValueThatIsNoItemId(): void
    {
        $world = $this->pluginWorld('qa_c8_garbage');

        $envelope = $this->bindPluginItem($world['token'], $world['page'], $world['section'], 'not-an-id');

        $this->assertEnvelope403($envelope);
        self::assertNull($this->storedFieldValue($world['section'], QaItemPluginFixture::FIELD));
    }

    public function testSuperuserCanBindAnyPluginItem(): void
    {
        $world = $this->pluginWorld('qa_c8_admin');

        $envelope = $this->bindPluginItem($this->loginAsQaAdmin(), $world['page'], $world['section'], (string) $world['hidden']);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame((string) $world['hidden'], $this->storedFieldValue($world['section'], QaItemPluginFixture::FIELD));
    }

    public function testClearingAPluginItemIsAlwaysAllowed(): void
    {
        $world = $this->pluginWorld('qa_c8_clear');

        $envelope = $this->bindPluginItem($world['token'], $world['page'], $world['section'], '');

        $this->assertEnvelopeSuccess($envelope);
    }

    public function testEditorCannotImportASectionThatBindsAPluginItemTheyMayNotView(): void
    {
        $world = $this->pluginWorld('qa_c8_import_deny');
        $before = $this->sectionCountOnPage($world['page']);

        $envelope = $this->importSection($world['token'], $world['page'], [
            'section_name' => 'qa_c8_imported',
            'style_name' => QaItemPluginFixture::STYLE,
            'fields' => [QaItemPluginFixture::FIELD => ['all' => ['content' => (string) $world['hidden']]]],
        ]);

        $this->assertEnvelope403($envelope);
        self::assertSame('resource_not_bindable', $envelope['error'] ?? null);
        self::assertSame($before, $this->sectionCountOnPage($world['page']), 'A refused import must not create any section.');
    }

    public function testEditorCanImportASectionThatBindsAPluginItemTheyMayView(): void
    {
        $world = $this->pluginWorld('qa_c8_import_allow');
        $before = $this->sectionCountOnPage($world['page']);

        $envelope = $this->importSection($world['token'], $world['page'], [
            'section_name' => 'qa_c8_imported_ok',
            'style_name' => QaItemPluginFixture::STYLE,
            'fields' => [QaItemPluginFixture::FIELD => ['all' => ['content' => (string) $world['visible']]]],
        ]);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame($before + 1, $this->sectionCountOnPage($world['page']));
    }

    public function testSuperuserCanImportASectionThatBindsAnyPluginItem(): void
    {
        $world = $this->pluginWorld('qa_c8_import_admin');
        $before = $this->sectionCountOnPage($world['page']);

        $envelope = $this->importSection($this->loginAsQaAdmin(), $world['page'], [
            'section_name' => 'qa_c8_imported_admin',
            'style_name' => QaItemPluginFixture::STYLE,
            'fields' => [QaItemPluginFixture::FIELD => ['all' => ['content' => (string) $world['hidden']]]],
        ]);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame($before + 1, $this->sectionCountOnPage($world['page']));
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * A page with one `qa-item-block` section (the QA plugin's style, whose field
     * `qa_item_id` holds an item of the type `qa_item`), an editor who may update
     * the page and holds the view grant on item `visible` only.
     *
     * @return array{page: Page, section: Section, token: string, visible: int, hidden: int}
     */
    private function pluginWorld(string $prefix): array
    {
        QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );

        $page = $this->pages->createPage($prefix . '_page', openAccess: false);
        $section = $this->pages->createSection($prefix . '_section', QaItemPluginFixture::STYLE);
        $this->pages->linkSectionToPage($page, $section, 10);

        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group);
        $this->pages->grantGroupAcl($page, $group);

        $email = $prefix . '@selfhelp.test';
        $editor = $this->users->createUser($email, 'QA C8 Editor', groups: [$group]);
        $role = $this->roles->createRole($prefix . '_role');
        $this->roles->grantPermission($role, 'admin.page.update');
        $this->roles->grantPermission($role, 'admin.page.export');
        $this->grants->assignRoleToUser($editor, $role);
        $this->grants->grantPageAccess(
            $role,
            (int) $page->getId(),
            DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE,
        );
        $this->grants->grantResourceAccess($role, QaItemPluginFixture::TYPE_CODE, 9101, DataAccessSecurityService::PERMISSION_READ);

        return [
            'page' => $page,
            'section' => $section,
            'token' => $this->loginAs($email),
            'visible' => 9101,
            'hidden' => 9102,
        ];
    }

    /**
     * Save the item id of the QA style's `qa_item_id` property field.
     *
     * @return array<string, mixed>
     */
    private function bindPluginItem(string $token, Page $page, Section $section, string $value): array
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => QaItemPluginFixture::FIELD]);
        self::assertInstanceOf(Field::class, $field, 'The QA plugin catalogue must have been synced.');

        return $this->jsonRequest(
            'PUT',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $page->getId(), $section->getId()),
            ['contentFields' => [], 'propertyFields' => [['fieldId' => (int) $field->getId(), 'value' => $value]]],
            $token,
        );
    }

    /**
     * A page with one `entry-table` section, an editor who may update the page
     * (route permission + page ACL) and holds READ on table Y only, plus tables
     * X (no grant) and Y. Table names are `<prefix>_x` / `<prefix>_y`.
     *
     * @return array{page: Page, section: Section, token: string, tableX: int, tableY: int}
     */
    private function world(string $prefix): array
    {
        $page = $this->pages->createPage($prefix . '_page', openAccess: false);
        $section = $this->pages->createSection($prefix . '_section', 'entry-table');
        $this->pages->linkSectionToPage($page, $section, 10);

        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group);
        $this->pages->grantGroupAcl($page, $group);

        $owner = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $owner);
        [$tableX] = $this->tables->createTableWithRow($prefix . '_x', (int) $owner->getId());
        [$tableY] = $this->tables->createTableWithRow($prefix . '_y', (int) $owner->getId());

        $email = $prefix . '@selfhelp.test';
        $editor = $this->users->createUser($email, 'QA F07 Editor', groups: [$group]);
        $role = $this->roles->createRole($prefix . '_role');
        $this->roles->grantPermission($role, 'admin.page.update');
        $this->roles->grantPermission($role, 'admin.page.export');
        $this->grants->assignRoleToUser($editor, $role);
        $this->grants->grantPageAccess(
            $role,
            (int) $page->getId(),
            DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE,
        );
        $this->grants->grantDataTableAccess($role, (int) $tableY->getId(), DataAccessSecurityService::PERMISSION_READ);

        return [
            'page' => $page,
            'section' => $section,
            'token' => $this->loginAs($email),
            'tableX' => (int) $tableX->getId(),
            'tableY' => (int) $tableY->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bindProperty(string $token, Page $page, Section $section, ?int $tableId): array
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => 'data_table']);
        self::assertInstanceOf(Field::class, $field, 'The seeded "data_table" field must exist.');

        return $this->jsonRequest(
            'PUT',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $page->getId(), $section->getId()),
            [
                'contentFields' => [],
                'propertyFields' => [['fieldId' => (int) $field->getId(), 'value' => $tableId === null ? '' : (string) $tableId]],
            ],
            $token,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function saveDataConfig(string $token, Page $page, Section $section, string $tableName): array
    {
        return $this->jsonRequest(
            'PUT',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $page->getId(), $section->getId()),
            [
                'contentFields' => [],
                'propertyFields' => [],
                'globalFields' => [
                    'data_config' => (string) json_encode([['table' => $tableName, 'retrieve' => 'all', 'current_user' => false]]),
                ],
            ],
            $token,
        );
    }

    /**
     * @param array<string, mixed> $section One top-level section of the import tree
     * @return array<string, mixed>
     */
    private function importSection(string $token, Page $page, array $section): array
    {
        return $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/import', $page->getId()),
            ['position' => 0, 'sections' => [$section]],
            $token,
        );
    }

    private function sectionCountOnPage(Page $page): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM rel_pages_sections WHERE id_pages = :page',
            ['page' => $page->getId()],
        ));
    }

    private function storedDataTable(Section $section): ?string
    {
        return $this->storedFieldValue($section, 'data_table');
    }

    private function storedFieldValue(Section $section, string $fieldName): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT t.content FROM sections_fields_translation t INNER JOIN fields f ON f.id = t.id_fields '
            . 'WHERE t.id_sections = :section AND f.name = :field',
            ['section' => $section->getId(), 'field' => $fieldName],
        );

        return $value === false || $value === '' ? null : self::coerceString($value);
    }

    private function storedDataConfig(Section $section): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT data_config FROM sections WHERE id = :id',
            ['id' => $section->getId()],
        );

        return $value === false || $value === null || $value === '' ? null : self::coerceString($value);
    }
}
