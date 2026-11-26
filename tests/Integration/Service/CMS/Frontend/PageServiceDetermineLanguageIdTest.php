<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\CMS\Frontend;

use App\Entity\Language;
use App\Repository\LanguageRepository;
use App\Service\CMS\CmsPreferenceService;
use App\Service\CMS\Frontend\PageService;
use App\Tests\Support\InteractsWithCmsDefaultLanguage;
use App\Tests\Support\QaKernelTestCase;

/**
 * "Default language" always means the CMS setting; with none set (or the
 * language-independent row stored by mistake) it is the real language with the
 * lowest id. Never id 1, never a literal 2 (P1-A5, fix-1 F1-P1-A2).
 */
final class PageServiceDetermineLanguageIdTest extends QaKernelTestCase
{
    use InteractsWithCmsDefaultLanguage;

    protected function tearDown(): void
    {
        $this->forgetCmsDefaultLanguageCaches();
        parent::tearDown();
    }

    public function testFallsBackToLowestRealLanguageWhenNothingElseIsSet(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 5) . '/src/Service/CMS/Frontend/PageService.php'
        );
        self::assertStringNotContainsString(
            'return 2;',
            $this->determineLanguageIdBody($source),
            'determineLanguageId must not hard-code language id 2'
        );

        $this->writeCmsDefaultLanguage(null);

        $expected = $this->lowestRealLanguageId();
        $pageService = $this->service(PageService::class);
        self::assertInstanceOf(PageService::class, $pageService);

        self::assertSame($expected, $pageService->determineLanguageId(null));
        self::assertSame(3, $pageService->determineLanguageId(3));
        self::assertSame($expected, $pageService->determineLanguageId(Language::INDEPENDENT_ID));
    }

    public function testEffectiveDefaultLanguageIsTheCmsSettingElseTheLowestRealLanguage(): void
    {
        $preferences = $this->service(CmsPreferenceService::class);
        self::assertInstanceOf(CmsPreferenceService::class, $preferences);
        $lowest = $this->lowestRealLanguageId();

        $this->writeCmsDefaultLanguage(null);
        self::assertSame($lowest, $preferences->getEffectiveDefaultLanguageId(), 'Unset: lowest real language');

        // A stored id 1 (written before the write path refused it) is not a language.
        $this->writeCmsDefaultLanguage(Language::INDEPENDENT_ID);
        self::assertSame($lowest, $preferences->getEffectiveDefaultLanguageId(), 'Id 1 stored: lowest real language');

        $this->writeCmsDefaultLanguage(3);
        self::assertSame(3, $preferences->getEffectiveDefaultLanguageId(), 'Set: the CMS setting');
    }

    private function lowestRealLanguageId(): int
    {
        $languages = $this->service(LanguageRepository::class);
        self::assertInstanceOf(LanguageRepository::class, $languages);
        $lowest = $languages->findLowestRealLanguageId();
        self::assertGreaterThan(Language::INDEPENDENT_ID, $lowest);

        return $lowest;
    }

    private function determineLanguageIdBody(string $source): string
    {
        self::assertMatchesRegularExpression(
            '/public function determineLanguageId\([^)]*\): int\s*\{/',
            $source
        );
        $start = strpos($source, 'public function determineLanguageId');
        self::assertNotFalse($start);
        $brace = strpos($source, '{', $start);
        self::assertNotFalse($brace);
        $depth = 0;
        for ($i = $brace; $i < strlen($source); ++$i) {
            $char = $source[$i];
            if ($char === '{') {
                ++$depth;
            } elseif ($char === '}') {
                --$depth;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }
        self::fail('Could not isolate determineLanguageId body');
    }
}
