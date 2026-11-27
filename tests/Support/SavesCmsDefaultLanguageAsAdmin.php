<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Language;

/**
 * Saves the CMS default language through the normal admin page-field save path
 * (for {@see QaWebTestCase} tests). Call `forgetCmsDefaultLanguageCaches()` from
 * `tearDown()`, as with {@see InteractsWithCmsDefaultLanguage}.
 */
trait SavesCmsDefaultLanguageAsAdmin
{
    use InteractsWithCmsDefaultLanguage;

    /**
     * Saves the setting through the normal admin page-field save path. Null clears it.
     */
    protected function saveCmsDefaultLanguageAsAdmin(?int $languageId, string $adminToken): void
    {
        [$pageId, $fieldId] = $this->cmsDefaultLanguageTarget($this->cmsDefaultLanguageConnection());

        $this->markCmsDefaultLanguageChanged();
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/pages/' . $pageId,
            [
                'pageData' => new \stdClass(),
                'fields' => [[
                    'fieldId' => $fieldId,
                    'languageId' => Language::INDEPENDENT_ID,
                    'content' => $languageId === null ? '' : (string) $languageId,
                ]],
            ],
            $adminToken,
        ));
        // The save clears the preference and page caches itself; results that
        // were computed with the old default go too.
        $this->clearCmsDefaultLanguageCaches();
    }
}
