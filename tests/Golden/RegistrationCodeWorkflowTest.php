<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\User;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Notifier\RecordingNotifier;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Golden workflow for self-registration with a validation code, end to end over
 * the real HTTP API (no domain mocking):
 *
 *   1. qa.admin generates a registration code for a qa group;
 *   2. an anonymous visitor registers with that code via the public endpoint;
 *   3. the backend creates the (blocked) user, consumes the code and links it to
 *      the new user id — atomically;
 *   4. qa.admin sees the code as consumed + linked in the admin list;
 *   5. qa.admin's CSV export contains the consumed code with the linked email.
 *
 * Cleanup note: registration legitimately creates a system scheduled-job row
 * (the validation email) whose description is not qa-prefixed, so this test does
 * not use {@see \App\Tests\Support\QaCleanupVerifier} (which would flag that
 * inherent side effect). DAMA still rolls every row back at tearDown. "No real
 * outbound" is asserted via the null mailer transport.
 */
#[Group('golden')]
final class RegistrationCodeWorkflowTest extends QaWebTestCase
{
    private const REG_CODES = '/cms-api/v1/admin/registration-codes';

    public function testAdminGeneratesCodePublicRegistersCodeIsConsumedAndLinked(): void
    {
        // One kernel for the whole chain so the DAMA transaction + EM identity
        // map stay consistent across the generate/register/list requests.
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $qaGroup = (new GroupFactory($em))->createGroup('qa_golden_reg_group');
        $qaGroupId = (int) $qaGroup->getId();
        $registerPageId = $this->resolveRegisterPageId($em);
        $email = 'qa_golden_register@selfhelp.test';

        $token = $this->loginAsQaAdmin();

        // 1) Admin generates one code for the qa group.
        $generated = $this->jsonRequest('POST', self::REG_CODES . '/generate', [
            'count' => 1,
            'id_groups' => $qaGroupId,
        ], $token);
        $generatedData = $this->assertEnvelopeSuccess($generated, Response::HTTP_CREATED);
        $codes = $generatedData['codes'] ?? null;
        self::assertIsArray($codes);
        self::assertCount(1, $codes, 'Exactly one code must be generated.');
        $first = $codes[0];
        self::assertIsArray($first);
        $code = $first['code'] ?? null;
        self::assertIsString($code);
        self::assertSame($qaGroupId, $first['id_groups'] ?? null);
        self::assertFalse($first['is_consumed'] ?? null, 'A freshly generated code is not consumed.');

        // 2) Anonymous visitor registers with that code (no auth token).
        $registered = $this->jsonRequest('POST', '/cms-api/v1/auth/register', [
            'page_id' => $registerPageId,
            'email' => $email,
            'code' => $code,
        ]);
        $registeredData = $this->assertEnvelopeSuccess($registered, Response::HTTP_CREATED);
        self::assertTrue($registeredData['registered'] ?? null, 'Registration must report success.');

        // 3) The user exists, blocked + invited; the code is consumed + linked.
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, 'The registered user must be persisted.');
        self::assertTrue($user->isBlocked(), 'A freshly registered user is blocked pending validation.');
        $userId = (int) $user->getId();

        // 4) Admin list shows the code consumed and linked to the new user.
        $list = $this->jsonRequest('GET', self::REG_CODES . '?search=' . urlencode($code), null, $token);
        $listData = $this->assertEnvelopeSuccess($list);
        $listCodes = $listData['codes'] ?? null;
        self::assertIsArray($listCodes);
        $match = $this->findCode($listCodes, $code);
        self::assertNotNull($match, 'The generated code must appear in the admin list.');
        self::assertTrue($match['is_consumed'] ?? null, 'The code must show as consumed.');
        self::assertNotNull($match['consumed_at'] ?? null, 'A consumed code carries a consumption timestamp.');
        self::assertSame($userId, $match['id_users'] ?? null, 'The code must be linked to the new user id.');
        self::assertSame($email, $match['user_email'] ?? null, 'The admin list exposes the linked user email.');

        // 5) CSV export contains the consumed code + linked email.
        $csv = $this->exportCsv($token, $code);
        self::assertStringContainsString($code, $csv, 'Export must include the code.');
        self::assertStringContainsString($email, $csv, 'Export must include the linked user email.');
        self::assertStringContainsString('Used', $csv, 'The exported code is marked Used.');

        // No real outbound: the validation email used the null mailer transport.
        $mailerDsn = $_SERVER['MAILER_DSN'] ?? null;
        RecordingNotifier::assertMailerIsNullTransport(is_string($mailerDsn) ? $mailerDsn : null);
    }

    /**
     * @param array<array-key, mixed> $codes
     * @return array<string, mixed>|null
     */
    private function findCode(array $codes, string $code): ?array
    {
        foreach ($codes as $row) {
            if (is_array($row) && ($row['code'] ?? null) === $code) {
                /** @var array<string, mixed> $row */
                return $row;
            }
        }

        return null;
    }

    private function exportCsv(string $token, string $code): string
    {
        $this->client->request(
            'GET',
            self::REG_CODES . '/export?search=' . urlencode($code),
            [],
            [],
            $this->authHeaders($token),
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        // The functional client already drained the StreamedResponse into the
        // BrowserKit internal response during the request.
        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function resolveRegisterPageId(EntityManagerInterface $em): int
    {
        $raw = $em->getConnection()->fetchOne("SELECT id FROM pages WHERE keyword = 'register' LIMIT 1");
        self::assertNotFalse($raw, 'The seeded register page must exist (run composer test:reset-db).');

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
