<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Language;
use App\Service\Cache\Core\CacheService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Changes the CMS default language (`default_language_id` on `sh-cms-preferences`)
 * inside one test and leaves no stale cache behind. Web tests that can save it
 * through the admin API use {@see SavesCmsDefaultLanguageAsAdmin}.
 *
 * DAMA rolls the database row back after the test, but the cache categories that
 * depend on the setting live in Redis. A test that changes the setting calls
 * {@see forgetCmsDefaultLanguageCaches()} from its `tearDown()` so the next test
 * reads the baseline value again.
 */
trait InteractsWithCmsDefaultLanguage
{
    private bool $cmsDefaultLanguageChanged = false;

    /**
     * Writes the setting directly (kernel tests without an HTTP client). Null clears it.
     * With `$keepResults` only the preference cache is cleared, so a test can prove
     * that cached results do not survive the change on their own.
     */
    protected function writeCmsDefaultLanguage(?int $languageId, bool $keepResults = false): void
    {
        $db = $this->cmsDefaultLanguageConnection();
        [$pageId, $fieldId] = $this->cmsDefaultLanguageTarget($db);
        $content = $languageId === null ? '' : (string) $languageId;
        $params = ['p' => $pageId, 'f' => $fieldId, 'l' => Language::INDEPENDENT_ID, 'c' => $content];

        $exists = $db->fetchOne(
            'SELECT 1 FROM pages_fields_translation WHERE id_pages = :p AND id_fields = :f AND id_languages = :l',
            $params,
        );
        if ($exists) {
            $db->executeStatement(
                'UPDATE pages_fields_translation SET content = :c WHERE id_pages = :p AND id_fields = :f AND id_languages = :l',
                $params,
            );
        } else {
            $db->executeStatement(
                'INSERT INTO pages_fields_translation (id_pages, id_fields, id_languages, content) VALUES (:p, :f, :l, :c)',
                $params,
            );
        }

        $this->markCmsDefaultLanguageChanged();
        if ($keepResults) {
            $cache = static::getContainer()->get(CacheService::class);
            self::assertInstanceOf(CacheService::class, $cache);
            $cache->withCategory(CacheService::CATEGORY_CMS_PREFERENCES)->invalidateCategory();

            return;
        }
        $this->clearCmsDefaultLanguageCaches();
    }

    protected function markCmsDefaultLanguageChanged(): void
    {
        $this->cmsDefaultLanguageChanged = true;
    }

    /**
     * Call from `tearDown()` before `parent::tearDown()`.
     */
    protected function forgetCmsDefaultLanguageCaches(): void
    {
        if (!$this->cmsDefaultLanguageChanged) {
            return;
        }
        $this->clearCmsDefaultLanguageCaches();
        $this->cmsDefaultLanguageChanged = false;
    }

    protected function clearCmsDefaultLanguageCaches(): void
    {
        $cache = static::getContainer()->get(CacheService::class);
        self::assertInstanceOf(CacheService::class, $cache);
        foreach ([
            CacheService::CATEGORY_CMS_PREFERENCES,
            CacheService::CATEGORY_DATA_TABLES,
            CacheService::CATEGORY_PAGES,
            CacheService::CATEGORY_NAVIGATION,
        ] as $category) {
            $cache->withCategory($category)->invalidateCategory();
        }
    }

    protected function cmsDefaultLanguageConnection(): Connection
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em->getConnection();
    }

    /**
     * @return array{0: int, 1: int} page id and field id
     */
    protected function cmsDefaultLanguageTarget(Connection $db): array
    {
        $pageId = self::coerceInt($db->fetchOne("SELECT id FROM pages WHERE keyword = 'sh-cms-preferences'"));
        $fieldId = self::coerceInt($db->fetchOne("SELECT id FROM fields WHERE name = 'default_language_id'"));
        self::assertGreaterThan(0, $pageId);
        self::assertGreaterThan(0, $fieldId);

        return [$pageId, $fieldId];
    }
}
