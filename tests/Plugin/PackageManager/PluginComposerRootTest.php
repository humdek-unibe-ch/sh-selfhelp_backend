<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Plugin\PackageManager;

use App\Plugin\PackageManager\PluginComposerRoot;
use App\Tests\Support\NarrowsJson;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `PluginComposerRoot` is the single producer of the plugin Composer
 * root. The runner calls `ensure()` before every subprocess. Cover:
 *
 *   - dir + composer.json materialised on first call;
 *   - second call is a no-op (does not overwrite a hand-edited file);
 *   - seed shape contains the expected top-level keys
 *     (`name`, `type`, `config.platform.php`, `provide`, `require.php`).
 *
 * The `provide` map is populated from the host's installed.json. We
 * don't pin specific package versions here — those move with each
 * `composer update` of the host. Asserting on shape (keys present,
 * each value is a string) is enough.
 */
final class PluginComposerRootTest extends TestCase
{
    use NarrowsJson;

    private string $tmpDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->tmpDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'plugin-composer-root-test-'
            . bin2hex(random_bytes(6));
        $this->fs->mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if ($this->fs->exists($this->tmpDir)) {
            $this->fs->remove($this->tmpDir);
        }
    }

    public function testEnsureCreatesDirAndComposerJson(): void
    {
        $root = new PluginComposerRoot($this->tmpDir);

        $this->assertDirectoryDoesNotExist($root->rootDir());
        $this->assertFileDoesNotExist($root->composerJsonPath());

        $root->ensure();

        $this->assertDirectoryExists($root->rootDir());
        $this->assertFileExists($root->composerJsonPath());
    }

    public function testSeededComposerJsonHasExpectedShape(): void
    {
        $root = new PluginComposerRoot($this->tmpDir);
        $root->ensure();

        $raw = file_get_contents($root->composerJsonPath());
        $this->assertIsString($raw);
        $data = json_decode((string) $raw, true);
        $this->assertIsArray($data);

        $this->assertSame('selfhelp/plugin-composer-root', $data['name'] ?? null);
        $this->assertSame('project', $data['type'] ?? null);
        $this->assertArrayHasKey('config', $data);
        $config = $this->asArray($data['config']);
        $this->assertSame('vendor', $config['vendor-dir'] ?? null);
        $this->assertFalse($config['allow-plugins'] ?? null);
        $this->assertArrayHasKey('platform', $config);
        $platform = $this->asArray($config['platform']);
        $this->assertArrayHasKey('php', $platform);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->asString($platform['php'] ?? null));
        $require = $this->asArray($data['require']);
        $this->assertSame('>=8.4', $require['php'] ?? null);
    }

    public function testSeededPlatformOnlyContainsValidComposerVersions(): void
    {
        $root = new PluginComposerRoot($this->tmpDir);
        $root->ensure();

        $data = $this->asArray(json_decode((string) file_get_contents($root->composerJsonPath()), true));
        $config = $this->asArray($data['config']);
        $platform = $this->asArray($config['platform'] ?? null);

        // Every value must be a Composer-parseable version. A regression
        // here (e.g. "mysqlnd 8.4.0" sneaking back in) would crash the
        // installer with `Invalid version string ...` from
        // VersionParser::normalize() — exactly the bug this guards
        // against.
        foreach ($platform as $key => $value) {
            $this->assertIsString($value, sprintf('Platform value for "%s" must be a string.', $key));
            $this->assertMatchesRegularExpression(
                '/^\d+(?:\.\d+){0,3}(?:[-+][A-Za-z0-9.\-]+)?$/',
                $value,
                sprintf('Platform value "%s" => "%s" is not a valid Composer platform version.', $key, $value),
            );
            // Composer platform keys must be lowercase a-z0-9_- only;
            // a literal space (e.g. "ext-zend opcache") is rejected.
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9][a-z0-9_-]*$/',
                (string) $key,
                sprintf('Platform key "%s" contains characters Composer rejects.', $key),
            );
        }
    }

    public function testEnsureIsIdempotentAndDoesNotOverwriteExistingFile(): void
    {
        $root = new PluginComposerRoot($this->tmpDir);
        $root->ensure();

        $contents = '{"name":"hand-edited","type":"project"}';
        file_put_contents($root->composerJsonPath(), $contents);

        $root->ensure();

        $this->assertSame($contents, file_get_contents($root->composerJsonPath()));
    }

    public function testHostProvidedPrefixesIncludeFrameworkPackages(): void
    {
        $this->assertContains('symfony/', PluginComposerRoot::HOST_PROVIDED_PREFIXES);
        $this->assertContains('doctrine/', PluginComposerRoot::HOST_PROVIDED_PREFIXES);
        $this->assertContains('psr/', PluginComposerRoot::HOST_PROVIDED_PREFIXES);
    }

    public function testRootDirAndAutoloadPathDeriveFromProjectDir(): void
    {
        $root = new PluginComposerRoot($this->tmpDir);

        $this->assertSame(
            $this->tmpDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'plugin-composer',
            $root->rootDir(),
        );
        $this->assertSame(
            $root->rootDir() . DIRECTORY_SEPARATOR . 'vendor',
            $root->vendorDir(),
        );
        $this->assertSame(
            $root->vendorDir() . DIRECTORY_SEPARATOR . 'autoload.php',
            $root->autoloadPath(),
        );
    }
}
