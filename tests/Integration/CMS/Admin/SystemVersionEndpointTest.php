<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Kernel;

/**
 * `GET /admin/system/version`: the admin System page's source for the exact
 * versions. Besides the instance fields it lists the frameworks and the
 * direct production libraries with their installed version and licence.
 * Admin-only via `admin.system.read`.
 */
#[Group('integration')]
#[Group('security')]
final class SystemVersionEndpointTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const URI = '/cms-api/v1/admin/system/version';

    public function testOnlyACallerWithTheSystemPermissionMaySeeTheVersions(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::URI);
    }

    public function testTheResponseListsFrameworksAndLibrariesWithExactVersions(): void
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $this->loginAsQaAdmin()));

        // The whole body is valid against the response schema.
        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->service(JsonSchemaValidationService::class)
            ->validate($this->asObject($decoded), 'responses/admin/system_version');
        self::assertSame([], $errors, "system_version response failed its schema:\n" . implode("\n", $errors));

        // What the page showed before is still there.
        foreach (['instance_id', 'selfhelp_version', 'backend_version', 'database_migration_version', 'installed_plugins'] as $key) {
            self::assertArrayHasKey($key, $data);
        }

        $frameworks = array_column($this->items($data['frameworks']), null, 'name');
        self::assertSame(['PHP', 'Symfony', 'MySQL', 'Redis'], array_keys($frameworks));
        self::assertSame(PHP_VERSION, $this->asArray($frameworks['PHP'])['version']);
        self::assertSame(Kernel::VERSION, $this->asArray($frameworks['Symfony'])['version']);
        self::assertSame($this->connection()->fetchOne('SELECT VERSION()'), $this->asArray($frameworks['MySQL'])['version']);

        $libraries = $this->items($data['libraries']);
        self::assertNotEmpty($libraries);
        foreach ($libraries as $library) {
            self::assertIsString(
                $library['version'],
                sprintf('"%s" must carry its exact installed version.', $this->asString($library['name'])),
            );
        }
        self::assertContains('symfony/framework-bundle', array_column($libraries, 'name'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(mixed $list): array
    {
        return array_map(fn (mixed $item): array => $this->asArray($item), $this->asList($list));
    }

    private function connection(): Connection
    {
        return $this->service(EntityManagerInterface::class)->getConnection();
    }
}
