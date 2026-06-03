<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Security;

use App\DataFixtures\Test\QaBaselineFixture;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reusable permission-matrix assertions for admin API routes (plan §29).
 *
 * Must be used inside a {@see \App\Tests\Support\QaWebTestCase} (it relies on
 * that class's login + jsonRequest + envelope helpers).
 *
 * The QA baseline (see {@see QaBaselineFixture}) seeds exactly one admin role,
 * held only by qa.admin. Therefore every admin API route has the SAME matrix:
 *
 *   qa.admin                 -> allowed (200 / route-specific success status)
 *   qa.editor / qa.user / qa.guest -> 403 (authenticated, lacks the permission)
 *   anonymous (no token)     -> 401
 *
 * Security tests must assert FAILURE behaviour, not only success — this trait
 * makes the negative cases (403/401) impossible to forget.
 */
trait PermissionMatrixProvider
{
    /**
     * Assert the canonical admin-only matrix for a single route.
     *
     * @param array<string, mixed>|null $body request body for write routes (use qa_-prefixed values)
     * @param int $allowedStatus the success status qa.admin should receive (200/201/...)
     */
    protected function assertAdminOnlyMatrix(
        string $method,
        string $uri,
        ?array $body = null,
        int $allowedStatus = Response::HTTP_OK,
    ): void {
        // Allowed: qa.admin.
        $adminEnvelope = $this->jsonRequest($method, $uri, $body, $this->loginAsQaAdmin());
        self::assertSame(
            $allowedStatus,
            $adminEnvelope['status'] ?? null,
            sprintf('qa.admin must be allowed on %s %s (got %s)', $method, $uri, var_export($adminEnvelope['status'] ?? null, true))
        );

        // Forbidden: every authenticated non-admin persona.
        foreach ($this->forbiddenPersonaTokens() as $label => $token) {
            $forbidden = $this->jsonRequest($method, $uri, $body, $token);
            self::assertSame(
                Response::HTTP_FORBIDDEN,
                $forbidden['status'] ?? null,
                sprintf('%s must be forbidden (403) on %s %s', $label, $method, $uri)
            );
        }

        // Unauthorized: no token at all.
        $anonymous = $this->jsonRequest($method, $uri, $body, null);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $anonymous['status'] ?? null,
            sprintf('Anonymous request must be unauthorized (401) on %s %s', $method, $uri)
        );
    }

    /**
     * Assert only the negative half of the matrix (no qa.admin success call).
     *
     * Useful for write/destructive routes where the success path is exercised
     * by a dedicated workflow test and the matrix test must NOT mutate data.
     *
     * @param array<string, mixed>|null $body
     */
    protected function assertForbiddenForNonAdmins(string $method, string $uri, ?array $body = null): void
    {
        foreach ($this->forbiddenPersonaTokens() as $label => $token) {
            $forbidden = $this->jsonRequest($method, $uri, $body, $token);
            self::assertSame(
                Response::HTTP_FORBIDDEN,
                $forbidden['status'] ?? null,
                sprintf('%s must be forbidden (403) on %s %s', $label, $method, $uri)
            );
        }

        $anonymous = $this->jsonRequest($method, $uri, $body, null);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $anonymous['status'] ?? null,
            sprintf('Anonymous request must be unauthorized (401) on %s %s', $method, $uri)
        );
    }

    /**
     * The authenticated non-admin personas keyed by a human label.
     *
     * @return array<string, string> label => bearer token
     */
    private function forbiddenPersonaTokens(): array
    {
        return [
            'qa.editor (' . QaBaselineFixture::QA_EDITOR_EMAIL . ')' => $this->loginAsQaEditor(),
            'qa.user (' . QaBaselineFixture::QA_USER_EMAIL . ')' => $this->loginAsQaUser(),
            'qa.guest (' . QaBaselineFixture::QA_GUEST_EMAIL . ')' => $this->loginAsQaGuest(),
        ];
    }
}
