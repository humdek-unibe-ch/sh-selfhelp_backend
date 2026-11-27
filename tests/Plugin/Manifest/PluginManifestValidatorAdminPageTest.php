<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Manifest;

use App\Plugin\Manifest\PluginManifestValidator;
use PHPUnit\Framework\TestCase;

/**
 * Rule 4.13 (F197): a plugin admin page is guarded by one of the plugin's
 * own permissions. The manifest validator refuses an admin page whose
 * `permission` is not a `permissions[].key` of the same manifest. Runs on
 * the real validator and canonical schema, DB-free.
 */
final class PluginManifestValidatorAdminPageTest extends TestCase
{
    private PluginManifestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PluginManifestValidator(
            \dirname(__DIR__, 3) . '/docs/plugins/plugin-manifest.schema.json'
        );
    }

    /**
     * @param list<array<string, mixed>> $adminPages
     * @param list<array<string, mixed>> $permissions
     * @return array<string, mixed>
     */
    private function manifest(array $adminPages, array $permissions): array
    {
        return [
            'id' => 'qa-admin-page-probe',
            'name' => 'QA Admin Page Probe',
            'version' => '1.0.0',
            'pluginApiVersion' => '0.1.0',
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0', 'php' => '^8.4'],
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => ['adminPages']],
            'permissions' => $permissions,
            'adminPages' => $adminPages,
        ];
    }

    public function testAdminPageNamingItsOwnPermissionValidates(): void
    {
        $errors = $this->validator->validate($this->manifest(
            [['slug' => 'qa-dashboard', 'label' => 'QA dashboard', 'permission' => 'qa_probe.read']],
            [['key' => 'qa_probe.read', 'description' => 'See the QA probe']],
        ));

        self::assertSame([], $errors);
    }

    public function testAdminPageNamingAnUndeclaredPermissionIsRefused(): void
    {
        $errors = $this->validator->validate($this->manifest(
            [['slug' => 'qa-dashboard', 'label' => 'QA dashboard', 'permission' => 'admin.access']],
            [['key' => 'qa_probe.read', 'description' => 'See the QA probe']],
        ));

        self::assertSame(
            ['Admin page "qa-dashboard" uses permission "admin.access", which the plugin does not declare.'],
            $errors,
        );
    }

    public function testAdminPageWithoutAnyDeclaredPermissionIsRefused(): void
    {
        $manifest = $this->manifest(
            [['slug' => 'qa-dashboard', 'label' => 'QA dashboard', 'permission' => 'qa_probe.read']],
            [],
        );
        unset($manifest['permissions']);

        self::assertSame(
            ['Admin page "qa-dashboard" uses permission "qa_probe.read", which the plugin does not declare.'],
            $this->validator->validate($manifest),
        );
    }

    public function testEachOffendingAdminPageIsReported(): void
    {
        $errors = $this->validator->validate($this->manifest(
            [
                ['slug' => 'qa-ok', 'label' => 'OK', 'permission' => 'qa_probe.read'],
                ['slug' => 'qa-bad-one', 'label' => 'Bad one', 'permission' => 'qa_probe.write'],
                ['slug' => 'qa-bad-two', 'label' => 'Bad two', 'permission' => 'qa_other.read'],
            ],
            [['key' => 'qa_probe.read', 'description' => 'See the QA probe']],
        ));

        self::assertSame([
            'Admin page "qa-bad-one" uses permission "qa_probe.write", which the plugin does not declare.',
            'Admin page "qa-bad-two" uses permission "qa_other.read", which the plugin does not declare.',
        ], $errors);
    }
}
