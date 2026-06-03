<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Api\Admin;

use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Contract + permission tests for the draft-vs-version comparison endpoint:
 *
 *   GET /cms-api/v1/admin/pages/{page_id}/versions/compare-draft/{version_id}
 *
 * MIGRATED from the old hardcoded-credentials / old-envelope version. The
 * happy path (real draft compared against a real published version) lives in
 * {@see \App\Tests\Golden\PageVersioningWorkflowTest}, which creates its own
 * qa_ page + version. This class keeps only the DATA-INDEPENDENT contract:
 * format validation, not-found, and the permission matrix — none of which
 * depend on a specific seeded page existing (plan §4: read-only/no business
 * data mutation; the assertions use a non-existent page id on purpose).
 *
 * Controller check order makes these deterministic:
 *   route permission (401/403) -> format validation (400) -> page lookup (404).
 */
#[Group('security')]
final class PageVersionDraftComparisonTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    /** A page id that is never seeded, so no business data is ever touched. */
    private const MISSING_PAGE = 2147483600;
    private const MISSING_VERSION = 2147483601;

    private function compareDraftUri(int $pageId, int $versionId, ?string $format = null): string
    {
        $uri = sprintf('/cms-api/v1/admin/pages/%d/versions/compare-draft/%d', $pageId, $versionId);

        return $format === null ? $uri : $uri . '?format=' . $format;
    }

    public function testInvalidFormatIsRejectedBeforeTouchingData(): void
    {
        // Format is validated in the controller before the page is loaded, so
        // an invalid format yields 400 even for a non-existent page.
        $envelope = $this->jsonRequest(
            'GET',
            $this->compareDraftUri(self::MISSING_PAGE, self::MISSING_VERSION, 'not_a_real_format'),
            null,
            $this->loginAsQaAdmin()
        );

        $this->assertEnvelope400($envelope);
        self::assertIsString($envelope['error'] ?? null);
        self::assertStringContainsStringIgnoringCase('invalid format', (string) $envelope['error']);
    }

    public function testNonExistentPageReturnsNotFound(): void
    {
        $envelope = $this->jsonRequest(
            'GET',
            $this->compareDraftUri(self::MISSING_PAGE, self::MISSING_VERSION),
            null,
            $this->loginAsQaAdmin()
        );

        $this->assertEnvelope404($envelope);
    }

    public function testCompareDraftEnforcesAdminOnlyPermissionMatrix(): void
    {
        // Non-admins -> 403, anonymous -> 401, asserted before any data lookup.
        $this->assertForbiddenForNonAdmins('GET', $this->compareDraftUri(self::MISSING_PAGE, self::MISSING_VERSION));
    }
}
