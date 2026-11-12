<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * H5: outside prod, a validation error echoes the request body for debugging.
 * Secrets in it must be masked, not returned in plain text.
 */
#[TestGroup('security')]
final class ValidationErrorEchoTest extends QaWebTestCase
{
    public function testInvalidPasswordResetDoesNotEchoThePassword(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/auth/reset-password', [
            'token' => 'qa-secret-token',
            'password' => 'qa-secret-pass',
            'qa_unexpected' => 1,
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $envelope['status'] ?? null);
        $echo = $this->asArray($this->jsonGet($envelope, 'validation', 'request_data'));
        self::assertSame('********', $echo['password'] ?? null);
        self::assertSame('********', $echo['token'] ?? null);
        self::assertSame(1, $echo['qa_unexpected'] ?? null, 'Non-secret fields stay visible for debugging.');
    }
}
