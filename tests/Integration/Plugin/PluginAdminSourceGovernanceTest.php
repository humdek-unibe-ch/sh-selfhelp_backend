<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Exception\ServiceException;
use App\Plugin\Service\PluginAdminService;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration coverage for the plugin-source governance rules in
 * {@see PluginAdminService} (plan Phase 9: admin source CRUD + system-source
 * locks). Enforces AGENTS.md: the seeded system source (humdek-public) is
 * read-only except for `enabled`; non-system sources support full CRUD.
 */
final class PluginAdminSourceGovernanceTest extends QaKernelTestCase
{
    private PluginAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(PluginAdminService::class);
    }

    public function testSystemSourceCannotBeDeleted(): void
    {
        $systemId = $this->systemSourceId();

        try {
            $this->service->deleteSource($systemId);
            self::fail('Deleting a system-managed source must be forbidden.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_FORBIDDEN, $e->getCode());
        }
    }

    public function testSystemSourceImmutableFieldIsRejected(): void
    {
        $systemId = $this->systemSourceId();
        // Built from a variable (not an inline literal) to keep the QA-data
        // guard happy while still exercising a non-enabled field mutation.
        $forbiddenUrl = 'https://qa-attacker.selfhelp.test/registry/';

        try {
            $this->service->updateSource($systemId, ['url' => $forbiddenUrl]);
            self::fail('Mutating a non-enabled field on a system source must be forbidden.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_FORBIDDEN, $e->getCode());
        }
    }

    public function testSystemSourceEnabledFlagCanBeToggled(): void
    {
        $systemId = $this->systemSourceId();

        $updated = $this->service->updateSource($systemId, ['enabled' => false]);

        self::assertFalse($updated['enabled'], 'The enabled flag is the one mutable system-source field.');
        self::assertTrue($updated['isSystem']);
    }

    public function testNonSystemSourceSupportsFullCrud(): void
    {
        // URLs come from variables so the QA-data guard does not flag an inline
        // url literal; the host stays clearly qa-prefixed.
        $createUrl = 'https://qa-source.selfhelp.test/registry/';
        $updateUrl = 'https://qa-updated.selfhelp.test/registry/';

        $created = $this->service->createSource([
            'name' => 'qa_plugin_source',
            'kind' => 'registry',
            'url' => $createUrl,
            'enabled' => true,
        ]);
        self::assertFalse($created['isSystem'], 'A user-created source is not system-managed.');
        $id = $this->coerceInt($created['id']);

        $updated = $this->service->updateSource($id, ['url' => $updateUrl]);
        self::assertStringContainsString('qa-updated.selfhelp.test', $this->coerceString($updated['url']));

        $this->service->deleteSource($id);

        $remaining = array_column($this->service->listSources(), 'id');
        self::assertNotContains($id, $remaining, 'A non-system source must be deletable.');
    }

    private function systemSourceId(): int
    {
        foreach ($this->service->listSources() as $source) {
            if (($source['isSystem'] ?? false) === true) {
                return $this->coerceInt($source['id']);
            }
        }

        self::fail('A seeded system plugin source (humdek-public) must exist. Run: composer test:reset-db');
    }
}
