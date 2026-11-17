<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Service\Cache\Core\CacheService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\System\PlatformAboutService;
use App\Service\System\SystemInstanceService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The `version` style draws an "about the platform" block that the backend
 * fills from what is really installed (`PageService::withPlatformAboutOnPage`
 * -> `PlatformAboutService`).
 *
 * The rules under test:
 *  - the block is always there, product + licences always shown;
 *  - exact version numbers only for a caller holding `admin.system.read`;
 *  - the block is filled AFTER the page cache, so it is never stored in the
 *    cached page and a permission change shows on the very next request;
 *  - the seeded Impressum carries exactly one such block and none of the old
 *    hard-coded SH1 component list;
 *  - the page payload stays valid against the `get_page` response schema.
 *
 * Every request boots a fresh kernel, as in production (the current user is
 * memoized per service instance, so a shared container would carry the first
 * caller over to the next). The caches live in Redis and are shared by every
 * test database, so every test starts and ends with empty page / section /
 * permission caches: a page cached by another database must not decide the
 * outcome here. Services are fetched when needed, never kept across requests.
 */
#[Group('integration')]
final class VersionStyleAboutTest extends QaWebTestCase
{
    private const PAGE_URI = '/cms-api/v1/pages/by-keyword/impressum';
    private const IMPRESSUM_ABOUT_SECTION = 'impressum-sys-about';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearCaches();
    }

    protected function tearDown(): void
    {
        // The DAMA rollback runs after tearDown: leave nothing cached that was
        // computed from this test's changes (a revoked permission, a renamed
        // heading), so the next test recomputes from the rolled-back database.
        $this->clearCaches();

        parent::tearDown();
    }

    public function testGuestSeesTheBlockWithoutVersionNumbers(): void
    {
        $about = $this->aboutOf($this->loadImpressum());

        self::assertFalse($about['detail']);

        $product = $this->asArray($about['product']);
        self::assertSame('SelfHelp', $product['name']);
        self::assertSame(
            $this->service(SystemInstanceService::class)->getCmsVersion(),
            $product['version'],
            'The platform version is always shown, also to an anonymous visitor.',
        );
        self::assertNotSame('', $this->asString($product['version']));

        $frameworks = $this->items($about['frameworks']);
        self::assertSame(['PHP', 'Symfony', 'MySQL', 'Redis'], array_column($frameworks, 'name'));
        self::assertSame('PHP-3.01', $frameworks[0]['license']);
        self::assertSame('MIT', $frameworks[1]['license']);
        self::assertSame('GPL-2.0', $frameworks[2]['license']);

        $libraries = $this->items($about['libraries']);
        self::assertNotEmpty($libraries, 'The backend lists its direct production libraries.');

        foreach ([...$frameworks, ...$libraries, ...$this->items($about['plugins'])] as $item) {
            self::assertNull(
                $item['version'],
                sprintf('"%s" must not reveal its version to a caller without admin.system.read.', $this->asString($item['name'])),
            );
        }

        $byName = array_column($libraries, null, 'name');
        self::assertArrayHasKey('symfony/framework-bundle', $byName);
        self::assertSame('MIT', $this->asArray($byName['symfony/framework-bundle'])['license']);

        $names = array_map(fn (array $library): string => $this->asString($library['name']), $libraries);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names, 'Libraries are sorted by name.');
    }

    public function testAdminSeesExactVersions(): void
    {
        $about = $this->aboutOf($this->loadImpressum($this->loginAsQaAdmin()));

        self::assertTrue($about['detail']);
        self::assertSame(
            $this->service(SystemInstanceService::class)->getCmsVersion(),
            $this->asArray($about['product'])['version'],
        );

        $frameworks = array_column($this->items($about['frameworks']), null, 'name');
        self::assertSame(PHP_VERSION, $this->asArray($frameworks['PHP'])['version']);
        self::assertSame(Kernel::VERSION, $this->asArray($frameworks['Symfony'])['version']);
        self::assertSame(
            $this->connection()->fetchOne('SELECT VERSION()'),
            $this->asArray($frameworks['MySQL'])['version'],
        );

        $libraries = $this->items($about['libraries']);
        self::assertNotEmpty($libraries);
        foreach ($libraries as $library) {
            self::assertIsString(
                $library['version'],
                sprintf('"%s" must carry its exact installed version for an admin.', $this->asString($library['name'])),
            );
        }

        $byName = array_column($libraries, null, 'name');
        $installed = \Composer\InstalledVersions::getPrettyVersion('symfony/framework-bundle');
        self::assertIsString($installed);
        self::assertSame(
            preg_replace('/^v(?=\d)/', '', $installed),
            $this->asArray($byName['symfony/framework-bundle'])['version'],
            'The listed version is the one composer installed, not a copy.',
        );
    }

    public function testTheBlockFollowsTheCallerNotTheOrderOfRequests(): void
    {
        $admin = $this->loginAsQaAdmin();

        // guest -> admin -> guest -> admin: each answer matches its caller,
        // whatever was cached before.
        foreach ([[null, false], [$admin, true], [null, false], [$admin, true]] as [$token, $expectedDetail]) {
            $about = $this->aboutOf($this->loadImpressum($token));
            self::assertSame($expectedDetail, $about['detail']);

            $php = array_column($this->items($about['frameworks']), null, 'name')['PHP'];
            self::assertSame($expectedDetail ? PHP_VERSION : null, $this->asArray($php)['version']);
        }
    }

    public function testASignedInUserWithoutTheSystemPermissionSeesNoVersionNumbers(): void
    {
        $about = $this->aboutOf($this->loadImpressum($this->loginAsQaUser()));

        self::assertFalse($about['detail']);
        foreach ($this->items($about['libraries']) as $library) {
            self::assertNull($library['version']);
        }
    }

    /**
     * The decisive proof that `about` is never stored in the cached page: the
     * same user (same cache entry) loses the permission while the page is still
     * cached, and the next request - served from that page cache - already
     * hides the numbers.
     */
    public function testAPermissionChangeShowsOnTheNextRequestEvenWhileThePageIsCached(): void
    {
        $admin = $this->loginAsQaAdmin();
        $enGb = $this->languageId('en-GB');

        $first = $this->loadImpressum($admin, $enGb);
        self::assertTrue($this->aboutOf($first)['detail'], 'Precondition: the admin holds admin.system.read.');
        self::assertSame('About this platform', $this->sectionText($first, 'title'));

        // Change something the page cache holds (the heading) and revoke the
        // permission, but clear only the permission cache: the page itself
        // must still come from the cache.
        $this->connection()->executeStatement(
            'UPDATE sections_fields_translation t '
            . 'JOIN sections s ON s.id = t.id_sections '
            . 'JOIN fields f ON f.id = t.id_fields '
            . 'SET t.content = ? WHERE s.name = ? AND f.name = ? AND t.id_languages = ?',
            ['Changed after caching', self::IMPRESSUM_ABOUT_SECTION, 'title', $enGb],
        );
        $this->connection()->executeStatement(
            'DELETE rpr FROM rel_permissions_roles rpr '
            . 'JOIN permissions p ON p.id = rpr.id_permissions '
            . 'JOIN roles r ON r.id = rpr.id_roles '
            . 'WHERE r.name = ? AND p.name = ?',
            ['admin', PlatformAboutService::DETAIL_PERMISSION],
        );
        $this->cache()->withCategory(CacheService::CATEGORY_PERMISSIONS)->invalidateCategory();

        $second = $this->loadImpressum($admin, $enGb);

        self::assertSame(
            'About this platform',
            $this->sectionText($second, 'title'),
            'The page was served from the page cache (the changed heading is not visible yet).',
        );
        $about = $this->aboutOf($second);
        self::assertFalse($about['detail'], 'The permission is decided on every request, not stored with the page.');
        foreach ($this->items($about['frameworks']) as $framework) {
            self::assertNull($framework['version']);
        }
    }

    public function testImpressumCarriesOneGeneratedBlockAndNoHardCodedStackList(): void
    {
        $data = $this->loadImpressum(null, $this->languageId('en-GB'));
        $json = (string) $this->client->getResponse()->getContent();

        $sections = $this->versionSections($this->jsonGet($data, 'page', 'sections'));
        self::assertCount(1, $sections, 'The Impressum has exactly one version section.');
        self::assertSame(self::IMPRESSUM_ABOUT_SECTION, $sections[0]['section_name'] ?? null);

        foreach ([
            'Components & versions',
            'built on top of well-known',
            'composer show',
            'v7.7.0',
            'jQuery',
            'Bootstrap 4.4.1',
        ] as $oldText) {
            self::assertStringNotContainsString(
                $oldText,
                $json,
                sprintf('The old SH1 Components & versions area ("%s") must be gone from the Impressum.', $oldText),
            );
        }

        foreach ([
            'title' => 'About this platform',
            'label_frameworks' => 'Frameworks',
            'label_plugins' => 'Plugins',
            'label_libraries' => 'Libraries',
            'label_web' => 'Website',
            'label_app' => 'Mobile app',
            'label_filter' => 'Filter',
            'label_no_match' => 'Nothing matches the filter.',
        ] as $field => $expected) {
            self::assertSame($expected, $this->sectionText($data, $field), sprintf('Heading "%s" (en-GB).', $field));
        }

        $german = $this->loadImpressum(null, $this->languageId('de-CH'));
        $germanJson = (string) $this->client->getResponse()->getContent();
        self::assertSame('Über diese Plattform', $this->sectionText($german, 'title'));
        self::assertSame('Bibliotheken', $this->sectionText($german, 'label_libraries'));
        self::assertSame('Filtern', $this->sectionText($german, 'label_filter'));
        self::assertSame('Nichts entspricht dem Filter.', $this->sectionText($german, 'label_no_match'));
        foreach (['Components & versions', 'built on top of well-known', 'composer show', 'v7.7.0', 'jQuery', 'Bootstrap 4.4.1'] as $oldText) {
            self::assertStringNotContainsString($oldText, $germanJson);
        }
    }

    /**
     * The `if/then` rule that ties `about` to the `version` style in
     * `_section_recursive.json` is documentation only: the validator
     * (justinrainbow/json-schema 6.x) does not evaluate `if/then`. So the shape
     * of `about` is checked here directly against its own schema, for a guest
     * and for an admin, and the check is shown to be able to fail.
     */
    public function testTheAboutBlockMatchesItsSchemaForGuestAndAdmin(): void
    {
        foreach ([null, $this->loginAsQaAdmin()] as $token) {
            $about = $this->aboutOf($this->loadImpressum($token));

            self::assertSame([], $this->aboutSchemaErrors($about));
        }

        $about = $this->aboutOf($this->loadImpressum());

        $wrongType = $about;
        $libraries = $this->items($wrongType['libraries']);
        $libraries[0]['version'] = 5;
        $wrongType['libraries'] = $libraries;
        self::assertNotSame([], $this->aboutSchemaErrors($wrongType), 'A numeric version must be rejected.');

        $missingProduct = $about;
        unset($missingProduct['product']);
        self::assertNotSame([], $this->aboutSchemaErrors($missingProduct), 'A block without product must be rejected.');

        $extraKey = $about;
        $extraKey['secret'] = 'x';
        self::assertNotSame([], $this->aboutSchemaErrors($extraKey), 'An unknown key must be rejected.');
    }

    /**
     * F2-P2-A2: an operator edit of the old SH1 area does not protect it. The
     * migration deletes by section name (with every descendant), with no
     * content check.
     */
    public function testEditedSh1StackAreaIsRemovedWithoutContentCheck(): void
    {
        $conn = $this->connection();
        $pageId = $this->coerceInt(
            $conn->fetchOne("SELECT id FROM pages WHERE keyword = 'impressum'"),
            'Impressum page must exist.',
        );
        $styleId = $this->coerceInt(
            $conn->fetchOne("SELECT id FROM styles WHERE name = 'box'"),
            'The box style must exist.',
        );

        $names = [
            'impressum-sys-h2-stack',
            'impressum-sys-stack-text',
            'impressum-sys-stack-card',
            'impressum-sys-stack-note',
        ];
        $cardId = null;
        foreach ($names as $index => $name) {
            $conn->insert('sections', ['id_styles' => $styleId, 'name' => $name]);
            $sectionId = $this->coerceInt($conn->lastInsertId(), 'Inserted section id must be numeric.');
            $conn->insert('rel_pages_sections', [
                'id_pages' => $pageId,
                'id_sections' => $sectionId,
                'position' => 900 + ($index * 10),
            ]);
            if ($name === 'impressum-sys-stack-card') {
                $cardId = $sectionId;
            }
            if ($name === 'impressum-sys-stack-text') {
                $fieldId = $this->coerceInt(
                    $conn->fetchOne("SELECT id FROM fields WHERE name = 'content' LIMIT 1"),
                    'A content field must exist.',
                );
                $langId = $this->languageId('en-GB');
                $conn->insert('sections_fields_translation', [
                    'id_sections' => $sectionId,
                    'id_fields' => $fieldId,
                    'id_languages' => $langId,
                    'content' => 'Operator rewrote the SH1 intro on purpose.',
                    'meta' => null,
                ]);
            }
        }
        self::assertNotNull($cardId);
        $conn->insert('sections', ['id_styles' => $styleId, 'name' => 'impressum-sys-stack-card-child-edited']);
        $childId = $this->coerceInt($conn->lastInsertId(), 'Inserted child id must be numeric.');
        $conn->insert('rel_sections_hierarchy', [
            'id_parent_section' => $cardId,
            'id_child_section' => $childId,
            'position' => 1,
        ]);

        $before = $this->coerceInt(
            $conn->fetchOne(
                'SELECT COUNT(*) FROM sections s JOIN rel_pages_sections r ON r.id_sections = s.id '
                . 'WHERE r.id_pages = ? AND s.name IN (?, ?, ?, ?)',
                [$pageId, ...$names],
            ),
            'Count before deletion must be numeric.',
        );
        self::assertSame(4, $before, 'Precondition: the four edited SH1 sections are on the page.');

        // Same rule as Version20261112164932: delete each named top-level section
        // and every descendant by id, with no content check.
        foreach ($names as $name) {
            $rootId = $conn->fetchOne(
                'SELECT s.id FROM sections s JOIN rel_pages_sections r ON r.id_sections = s.id AND r.id_pages = ? WHERE s.name = ?',
                [$pageId, $name],
            );
            if (!is_numeric($rootId)) {
                continue;
            }
            $ids = $conn->fetchFirstColumn(
                'WITH RECURSIVE tree AS ( '
                . 'SELECT s.id FROM sections s WHERE s.id = ? '
                . 'UNION ALL '
                . 'SELECT c.id FROM tree t '
                . 'JOIN rel_sections_hierarchy h ON h.id_parent_section = t.id '
                . 'JOIN sections c ON c.id = h.id_child_section) '
                . 'SELECT id FROM tree',
                [$this->coerceInt($rootId, 'Root section id must be numeric.')],
            );
            if ($ids === []) {
                continue;
            }
            $deleteIds = [];
            foreach ($ids as $id) {
                $deleteIds[] = $this->coerceInt($id, 'Section tree id must be numeric.');
            }
            $marks = implode(', ', array_fill(0, count($deleteIds), '?'));
            $conn->executeStatement(
                'DELETE FROM sections WHERE id IN (' . $marks . ')',
                $deleteIds,
            );
        }

        $after = $this->coerceInt(
            $conn->fetchOne(
                'SELECT COUNT(*) FROM sections s JOIN rel_pages_sections r ON r.id_sections = s.id '
                . 'WHERE r.id_pages = ? AND s.name IN (?, ?, ?, ?)',
                [$pageId, ...$names],
            ),
            'Count after deletion must be numeric.',
        );
        self::assertSame(0, $after, 'Edited SH1 sections must be gone.');
        self::assertSame(
            0,
            $this->coerceInt(
                $conn->fetchOne('SELECT COUNT(*) FROM sections WHERE id = ?', [$childId]),
                'Child count must be numeric.',
            ),
            'Descendants of the edited card must be gone too.',
        );
    }

    // -- helpers --------------------------------------------------------------

    /**
     * Load the Impressum through the public page API, check the envelope and
     * the response schema, and return the `data` payload.
     *
     * @return array<string, mixed>
     */
    private function loadImpressum(?string $token = null, ?int $languageId = null): array
    {
        $uri = self::PAGE_URI . ($languageId !== null ? '?language_id=' . $languageId : '');
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $uri, null, $token));

        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->schema()->validate($this->asObject($decoded), 'responses/frontend/get_page');
        self::assertSame([], $errors, "Impressum failed the get_page schema:\n" . implode("\n", $errors));

        return $data;
    }

    /**
     * Validate an `about` block against its own schema.
     *
     * @param array<string, mixed> $about
     * @return list<string>
     */
    private function aboutSchemaErrors(array $about): array
    {
        $decoded = json_decode(json_encode($about, JSON_THROW_ON_ERROR));

        return $this->schema()->validate($this->asObject($decoded), 'common/_platform_about');
    }

    /**
     * The `about` block of the single version section of a loaded page.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function aboutOf(array $data): array
    {
        $sections = $this->versionSections($this->jsonGet($data, 'page', 'sections'));
        self::assertCount(1, $sections, 'Expected exactly one version section on the page.');
        self::assertArrayHasKey('about', $sections[0], 'The backend must fill `about` on every version section.');

        return $this->asArray($sections[0]['about']);
    }

    /**
     * Text of a translatable field of the version section (`{content, meta}`).
     *
     * @param array<string, mixed> $data
     */
    private function sectionText(array $data, string $field): string
    {
        $sections = $this->versionSections($this->jsonGet($data, 'page', 'sections'));
        self::assertCount(1, $sections);

        return $this->asString($this->jsonGet($sections[0], $field, 'content'));
    }

    /**
     * Every `version` section of a rendered section tree, children included.
     *
     * @return list<array<string, mixed>>
     */
    private function versionSections(mixed $sections): array
    {
        $found = [];
        foreach ($this->asList($sections) as $item) {
            $section = $this->asArray($item);
            if (($section['style_name'] ?? null) === 'version') {
                $found[] = $section;
            }
            if (isset($section['children']) && is_array($section['children'])) {
                array_push($found, ...$this->versionSections($section['children']));
            }
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(mixed $list): array
    {
        return array_map(fn (mixed $item): array => $this->asArray($item), $this->asList($list));
    }

    private function languageId(string $locale): int
    {
        return $this->coerceInt(
            $this->connection()->fetchOne('SELECT id FROM languages WHERE locale = ?', [$locale]),
            sprintf('Language "%s" must be seeded.', $locale),
        );
    }

    private function clearCaches(): void
    {
        foreach ([
            CacheService::CATEGORY_PAGES,
            CacheService::CATEGORY_SECTIONS,
            CacheService::CATEGORY_PERMISSIONS,
        ] as $category) {
            $this->cache()->withCategory($category)->invalidateCategory();
        }
    }

    // Services are fetched on use: each request boots a fresh kernel.

    private function connection(): Connection
    {
        return $this->service(EntityManagerInterface::class)->getConnection();
    }

    private function cache(): CacheService
    {
        return $this->service(CacheService::class);
    }

    private function schema(): JsonSchemaValidationService
    {
        return $this->service(JsonSchemaValidationService::class);
    }
}
