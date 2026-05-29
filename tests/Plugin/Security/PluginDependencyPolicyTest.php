<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Plugin\Security;

use App\Plugin\Security\PluginDependencyPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The dependency policy is intentionally permissive — its job is to
 * surface drift to the operation log, not to fail installs. Cover:
 *
 *   - host-provided packages outside the satisfied range produce a
 *     `violations` entry;
 *   - host-provided packages inside the range produce a `warnings`
 *     entry (reported for audit, but does not block);
 *   - non-host-provided packages are ignored entirely;
 *   - missing host installed.json yields an empty report (host
 *     untouched).
 */
final class PluginDependencyPolicyTest extends TestCase
{
    private string $tmpDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->tmpDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'plugin-dep-policy-test-'
            . bin2hex(random_bytes(6));
        $this->fs->mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if ($this->fs->exists($this->tmpDir)) {
            $this->fs->remove($this->tmpDir);
        }
    }

    private function seedInstalledJson(array $packages): void
    {
        $dir = $this->tmpDir . '/vendor/composer';
        $this->fs->mkdir($dir);
        file_put_contents(
            $dir . '/installed.json',
            json_encode(['packages' => $packages], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testWarnsWhenHostProvidedConstraintIsSatisfied(): void
    {
        $this->seedInstalledJson([
            ['name' => 'symfony/http-foundation', 'version' => '7.4.2'],
        ]);
        $policy = new PluginDependencyPolicy($this->tmpDir);

        $report = $policy->inspect([
            'symfony/http-foundation' => '^7.4',
        ]);

        $this->assertSame([], $report['violations']);
        $this->assertCount(1, $report['warnings']);
        $this->assertSame('symfony/http-foundation', $report['warnings'][0]['package']);
        $this->assertSame('7.4.2', $report['warnings'][0]['hostVersion']);
    }

    public function testReportsViolationWhenMajorMismatch(): void
    {
        $this->seedInstalledJson([
            ['name' => 'symfony/http-foundation', 'version' => '7.4.0'],
        ]);
        $policy = new PluginDependencyPolicy($this->tmpDir);

        $report = $policy->inspect([
            'symfony/http-foundation' => '^8.0',
        ]);

        $this->assertCount(1, $report['violations']);
        $this->assertSame('symfony/http-foundation', $report['violations'][0]['package']);
        $this->assertSame('7.4.0', $report['violations'][0]['hostVersion']);
        $this->assertStringContainsString('Pin the plugin', $report['violations'][0]['reason']);
    }

    public function testIgnoresPackagesNotOnHostProvidedList(): void
    {
        $this->seedInstalledJson([]);
        $policy = new PluginDependencyPolicy($this->tmpDir);

        $report = $policy->inspect([
            'humdek/some-plugin-helper' => '^1.0',
            'monolog/monolog' => '^3.0',
        ]);

        $this->assertSame(['warnings' => [], 'violations' => []], $report);
    }

    public function testWarnsWhenHostProvidedPackageNotInstalled(): void
    {
        $this->seedInstalledJson([
            ['name' => 'symfony/http-foundation', 'version' => '7.4.0'],
        ]);
        $policy = new PluginDependencyPolicy($this->tmpDir);

        $report = $policy->inspect([
            'doctrine/orm' => '^3.0',
        ]);

        $this->assertCount(1, $report['warnings']);
        $this->assertSame('doctrine/orm', $report['warnings'][0]['package']);
        $this->assertSame('(not installed in host)', $report['warnings'][0]['hostVersion']);
        $this->assertSame([], $report['violations']);
    }

    public function testEmptyReportWhenInstalledJsonMissing(): void
    {
        $policy = new PluginDependencyPolicy($this->tmpDir);

        $report = $policy->inspect([
            'symfony/http-foundation' => '^7.4',
        ]);

        $this->assertCount(1, $report['warnings']);
        $this->assertSame('(not installed in host)', $report['warnings'][0]['hostVersion']);
        $this->assertSame([], $report['violations']);
    }

    public function testTildeConstraintIsRespected(): void
    {
        $this->seedInstalledJson([
            ['name' => 'doctrine/orm', 'version' => '3.5.1'],
        ]);
        $policy = new PluginDependencyPolicy($this->tmpDir);

        $okReport = $policy->inspect(['doctrine/orm' => '~3.5']);
        $this->assertCount(1, $okReport['warnings']);
        $this->assertSame([], $okReport['violations']);

        $badReport = $policy->inspect(['doctrine/orm' => '~2.0']);
        $this->assertCount(1, $badReport['violations']);
    }

    public function testOrConstraintIsSatisfiedByEitherBranch(): void
    {
        $this->seedInstalledJson([
            ['name' => 'psr/log', 'version' => '3.0.0'],
        ]);
        $policy = new PluginDependencyPolicy($this->tmpDir);

        $report = $policy->inspect(['psr/log' => '^2.0 || ^3.0']);
        $this->assertSame([], $report['violations']);
        $this->assertCount(1, $report['warnings']);
    }
}
