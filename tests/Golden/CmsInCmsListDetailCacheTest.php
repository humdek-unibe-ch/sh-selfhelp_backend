<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Tests\Support\ExampleBundleTestPaths;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Golden workflow + cache-regression for the CMS-in-CMS list/detail pattern
 * (issue #30): import the self-contained Team-Members bundle (form-owned table +
 * sample rows), resolve the public list, follow a row to its detail BY URL id,
 * and prove two different record ids never share a cached render.
 *
 * The cache-regression is the plan's headline correctness risk: `route_params`
 * are folded into the page cache key (`routeCacheSuffix`), so `/team-members/{a}`
 * and `/team-members/{b}` must render their OWN record. This test would fail if a
 * future change reintroduced cross-record cache leakage.
 *
 * Imported with a `qa_` keyword prefix and NO route prefix (so the in-content
 * detail links match the seeded routes); pages are deleted in a finally block
 * and DAMA rolls back the surrounding transaction.
 */
#[Group('golden')]
final class CmsInCmsListDetailCacheTest extends QaWebTestCase
{
    private const LIST_PATH = '/team-members';

    public function testListResolvesRowsAndEachDetailRendersItsOwnRecord(): void
    {
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->loadExampleBundle();

        $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => [
                'keywordPrefix' => 'qa_',
                'importData' => true,
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($import, 201);

        $createdPageIds = [];
        foreach (is_array($data['created'] ?? null) ? $data['created'] : [] as $entry) {
            if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                $createdPageIds[] = $entry['page_id'];
            }
        }

        try {
            // Golden step 1: the public list resolves the seeded rows.
            $listBody = $this->resolveBody(self::LIST_PATH, $admin);
            self::assertStringContainsString('Ada Lovelace', $listBody, 'The list must resolve the seeded rows.');

            // Discover at least two distinct record ids from the resolved list,
            // tolerant of either hydrated detail links or raw record_id fields.
            $ids = $this->extractRecordIds($listBody);
            self::assertGreaterThanOrEqual(2, count($ids), 'The seeded list must expose >= 2 record ids.');

            // Golden step 2 + cache-regression: resolve two distinct details BY
            // URL id and prove they render DIFFERENT records (no shared cache).
            $bodyA = $this->resolveBody(self::LIST_PATH . '/' . $ids[0], $admin);
            $bodyB = $this->resolveBody(self::LIST_PATH . '/' . $ids[1], $admin);

            $names = ['Ada Lovelace', 'Alan Turing', 'Grace Hopper'];
            $inA = array_values(array_filter($names, static fn (string $n): bool => str_contains($bodyA, $n)));
            $inB = array_values(array_filter($names, static fn (string $n): bool => str_contains($bodyB, $n)));

            self::assertNotEmpty($inA, 'Detail A must render a seeded record.');
            self::assertNotEmpty($inB, 'Detail B must render a seeded record.');
            self::assertNotEquals(
                $inA,
                $inB,
                'Two distinct record ids must not render the same record — cross-record cache leak.'
            );
        } finally {
            foreach ($createdPageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    private function resolveBody(string $path, string $token): string
    {
        // preview=true renders the imported draft regardless of publish state.
        $this->client->request(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode($path) . '&preview=true',
            [],
            [],
            $this->authHeaders($token)
        );
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'resolve ' . $path . ': ' . (string) $response->getContent());

        return (string) $response->getContent();
    }

    /**
     * @return list<int>
     */
    private function extractRecordIds(string $body): array
    {
        // The resolve body is raw JSON, so `/` appears escaped (`\/`): match
        // hydrated detail links (`\/team-members\/34`) or raw record_id fields.
        if (preg_match_all('#(?:\\\\?/team-members\\\\?/|record_id"\s*:\s*"?)(\d+)#', $body, $matches) === false) {
            return [];
        }

        $ids = [];
        foreach ($matches[1] as $raw) {
            $ids[(int) $raw] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadExampleBundle(): array
    {
        $path = ExampleBundleTestPaths::teamMembersBundle();
        self::assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        $bundle = [];
        foreach ($decoded as $key => $value) {
            $bundle[(string) $key] = $value;
        }

        return $bundle;
    }
}
