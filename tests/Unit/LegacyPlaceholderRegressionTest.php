<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Slice 0 regression guard: active runtime code, active JSON schemas, and active
 * docs must use canonical Mustache `{{recipient.*}}` interpolation, never the
 * legacy action placeholders `@user` / `@user_name` / `@user_code`.
 *
 * Scope is deliberately narrow to avoid false positives:
 *   - We only match the three action recipient tokens (a lookbehind excludes
 *     email local parts like `foo@user.com`, and `@users` targeting tokens do
 *     not match the `\b`-anchored alternation).
 *   - `docs/archive/` is allowed to keep historical references (including this
 *     plan), and `migrations/` seed copy is the install baseline (changed only
 *     via a follow-up migration, not policed here).
 *   - `@link` / `@param` PHPDoc tags and `@media` / `@selfhelp/*` are never
 *     matched because they are not in the token alternation.
 */
final class LegacyPlaceholderRegressionTest extends TestCase
{
    /** Matches @user, @user_name, @user_code but not emails or @users. */
    private const LEGACY_PATTERN = '/(?<![\w])@user(_name|_code)?\b/';

    /**
     * Files allowed to name the legacy tokens because their job is to detect /
     * reject them. `ActionTemplateContextBuilder` is the canonical legacy
     * detector (`LEGACY_PLACEHOLDERS` const + deprecation logging).
     * `AdminActionTranslationService` validates translations and rejects
     * legacy placeholders via `hasLegacyPlaceholders`.
     *
     * @var list<string>
     */
    private const ALLOWED_BASENAMES = ['ActionTemplateContextBuilder.php', 'AdminActionTranslationService.php'];

    /**
     * Active surfaces that must never reintroduce legacy action placeholders.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function activeSurfaceProvider(): iterable
    {
        $root = \dirname(__DIR__, 2);

        yield 'runtime source (src)' => [$root . '/src', 'php'];
        yield 'API JSON schemas' => [$root . '/config/schemas', 'json'];
        yield 'developer docs' => [$root . '/docs/developer', 'md'];
        yield 'reference docs' => [$root . '/docs/reference', 'md'];
    }

    /**
     * Slice 0: the seeded reset-password mail copy must use canonical Mustache
     * placeholders, not the legacy `@project` / `@link` tokens. Scoped to the
     * single baseline migration that owns this copy so unrelated migration
     * PHPDoc (`@link`, `@param`, ...) can never false-positive.
     */
    public function testResetPasswordSeedUsesCanonicalPlaceholders(): void
    {
        $file = \dirname(__DIR__, 2) . '/migrations/Version20260501000600.php';
        self::assertFileExists($file);

        $contents = file_get_contents($file);
        self::assertIsString($contents);

        self::assertStringNotContainsString('@project', $contents, 'Legacy @project placeholder must be migrated to {{system.project_name}}.');
        self::assertStringNotContainsString('@link', $contents, 'Legacy @link placeholder must be migrated to {{mail.link}}.');
        self::assertStringContainsString('{{system.project_name}}', $contents);
        self::assertStringContainsString('{{mail.link}}', $contents);
    }

    #[DataProvider('activeSurfaceProvider')]
    public function testActiveSurfaceHasNoLegacyActionPlaceholders(string $directory, string $extension): void
    {
        if (!is_dir($directory)) {
            self::markTestSkipped(sprintf('Directory not present: %s', $directory));
        }

        $offenders = [];
        foreach ($this->filesWithExtension($directory, $extension) as $file) {
            if (in_array(basename($file), self::ALLOWED_BASENAMES, true)) {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            foreach (explode("\n", $contents) as $lineNumber => $line) {
                if (preg_match(self::LEGACY_PATTERN, $line) === 1) {
                    $offenders[] = sprintf('%s:%d  %s', $file, $lineNumber + 1, trim($line));
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Legacy action placeholders (@user/@user_name/@user_code) found in active surface. "
            . "Use {{recipient.email}} / {{recipient.name}} / {{recipient.code}} instead:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * @return list<string>
     */
    private function filesWithExtension(string $directory, string $extension): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo
                && $file->isFile()
                && strtolower($file->getExtension()) === $extension
            ) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
