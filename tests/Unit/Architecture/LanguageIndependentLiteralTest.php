<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Id 1 of `languages` is the language-independent row. Every reference in `src/`
 * must go through `Language::INDEPENDENT_ID` (or `INDEPENDENT_LOCALE`), never a
 * bare literal that means that row.
 */
final class LanguageIndependentLiteralTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN = [
        'PROPERTY_LANGUAGE',
        'DEFAULT_LANGUAGE = 1',
        'Language::class)->find(1)',
        "'language_id' => 1",
        'languageId === 1',
        'id_languages = 1',
        'l.id > 1',
        'int $languageId = 1',
        // Id 1 used as "default language" when no CMS default is set (fix-1).
        'getDefaultLanguageId() ?? 1',
        "'file_language', 1",
        'PROPS_LOCALE) ?? 1',
    ];

    /**
     * Statement-level forms a plain substring cannot catch (fix-2): an
     * assignment to a language variable whose ternary default is a bare `1`,
     * also when the ternary spans several lines.
     *
     * @var list<non-empty-string>
     */
    private const FORBIDDEN_REGEX = [
        '/\$\w*[Ll]anguage\w*\s*=(?![=>])[^;]*\?[^;]*:\s*1\s*;/',
    ];

    public function testSrcHasNoBareLanguageIndependentLiterals(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
        self::assertDirectoryExists($root);

        $hits = [];
        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/\.php$/i'
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);
            foreach (self::FORBIDDEN as $pattern) {
                if (!str_contains($contents, $pattern)) {
                    continue;
                }
                foreach (explode("\n", $contents) as $lineNumber => $line) {
                    if (str_contains($line, $pattern)) {
                        $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
                        $hits[] = sprintf('%s:%d contains %s', $relative, $lineNumber + 1, $pattern);
                    }
                }
            }
            foreach (self::FORBIDDEN_REGEX as $regex) {
                if (preg_match_all($regex, $contents, $matches, PREG_OFFSET_CAPTURE) < 1) {
                    continue;
                }
                foreach ($matches[0] as [$match, $offset]) {
                    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
                    $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                    $hits[] = sprintf('%s:%d contains %s', $relative, $line, preg_replace('/\s+/', ' ', $match));
                }
            }
        }

        self::assertSame([], $hits, "Bare language-independent literals remain:\n" . implode("\n", $hits));
    }

    /**
     * The statement pattern catches every ternary `: 1` form removed in fix-2.
     */
    #[DataProvider('forbiddenTernarySamples')]
    public function testTernaryLanguageDefaultOfOneIsCaught(string $sample): void
    {
        self::assertTrue(self::matchesForbiddenRegex($sample), 'Not caught: ' . $sample);
    }

    /**
     * The statement pattern leaves non-language `: 1` and the allowed literals alone.
     */
    #[DataProvider('allowedSamples')]
    public function testAllowedLiteralsAreNotCaught(string $sample): void
    {
        self::assertFalse(self::matchesForbiddenRegex($sample), 'Wrongly caught: ' . $sample);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenTernarySamples(): iterable
    {
        yield 'query language_id default' => ['$languageId = $request->query->has(\'language_id\') ? (int) $request->query->get(\'language_id\') : 1;'];
        yield 'body language_id default' => ['$languageId = isset($data[\'language_id\']) && is_int($data[\'language_id\']) ? $data[\'language_id\'] : 1;'];
        yield 'positive language id else 1' => ['$languageId = (is_int($languageId) && $languageId > 0) ? $languageId : 1;'];
        yield 'multi-line property row' => ["\$languageId = \$display\n    ? (\$localeMap[\$code] ?? null)\n    : 1;"];
        yield 'short ternary' => ['$languageId = $candidate ?: 1;'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedSamples(): iterable
    {
        yield 'independent constant' => ['$languageId = $request->query->has(\'language_id\') ? (int) $request->query->get(\'language_id\') : Language::INDEPENDENT_ID;'];
        yield 'page default' => ['$page = $request->query->has(\'page\') ? (int) $request->query->get(\'page\') : 1;'];
        yield 'navigation settings row' => ['$settings = $this->repository->find(1);'];
        yield 'flag comparison' => ['$visible = $row[\'acl_select\'] != 1 ? false : true;'];
        yield 'language count' => ['$multiLanguage = count($languages) > 1 ? true : false;'];
        yield 'language count comparison' => ['$isMulti = $languageCount === 1 ? false : true;'];
    }

    private static function matchesForbiddenRegex(string $sample): bool
    {
        foreach (self::FORBIDDEN_REGEX as $regex) {
            if (preg_match($regex, $sample) === 1) {
                return true;
            }
        }

        return false;
    }
}
