<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract + behaviour for GET /admin/registration-codes/stats.
 *
 * The endpoint is unfiltered by design: it counts the whole visible set so
 * `total` equals the list's unfiltered `totalCount` for the same caller. The
 * list is not group/ACL scoped, so neither is this. The core contract the
 * frontend relies on is `available + used === total`, asserted here both on the
 * live seeded state and after generating/consuming codes through the real API.
 *
 * Codes are 8-char random uppercase strings (PK), so they cannot carry a `qa_`
 * prefix; like the golden RegistrationCodeWorkflowTest, this writes only through
 * the real admin/registration flow and relies on DAMA rollback for cleanup.
 */
#[Group('contract')]
final class AdminRegistrationCodeStatsTest extends QaWebTestCase
{
    private const BASE = '/cms-api/v1/admin/registration-codes';

    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testStatsResponseMatchesSchemaAndInvariant(): void
    {
        $admin = $this->loginAsQaAdmin();

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/stats', null, $admin),
        );

        // Response validates against the published schema (full envelope: the
        // schema's top level is the standard _response_envelope).
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $decoded = self::asObject($decoded);
        $errors  = $this->schema->validate($decoded, 'responses/admin/registration_codes_stats');
        self::assertSame([], $errors, "Response failed schema responses/admin/registration_codes_stats:\n" . implode("\n", $errors));

        // The three fields exist, are ints, and available + used === total.
        $total     = $this->intField($data, 'total');
        $available = $this->intField($data, 'available');
        $used      = $this->intField($data, 'used');
        self::assertSame(
            $total,
            $available + $used,
            'Contract violated: available + used must equal total.',
        );
    }

    public function testStatsTotalEqualsUnfilteredListTotalCount(): void
    {
        $admin = $this->loginAsQaAdmin();

        $stats = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/stats', null, $admin),
        );
        $list = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '?page=1&pageSize=1', null, $admin),
        );

        $pagination = $list['pagination'] ?? null;
        self::assertIsArray($pagination);
        self::assertSame(
            $pagination['totalCount'] ?? null,
            $stats['total'] ?? null,
            'stats.total must equal the unfiltered list totalCount for the same caller.',
        );
    }

    public function testGeneratingAndConsumingCodesMovesTheBreakdown(): void
    {
        // One kernel for the whole chain so the DAMA transaction stays consistent
        // across generate / register / stats requests.
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $qaGroup   = (new GroupFactory($em))->createGroup('qa_reg_stats_group');
        $qaGroupId = (int) $qaGroup->getId();

        $admin = $this->loginAsQaAdmin();

        $before = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/stats', null, $admin),
        );
        $beforeTotal     = $this->intField($before, 'total');
        $beforeAvailable = $this->intField($before, 'available');
        $beforeUsed      = $this->intField($before, 'used');

        // Generate 3 fresh (available) codes.
        $generated = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BASE . '/generate', [
                'count'     => 3,
                'group_ids' => [$qaGroupId],
            ], $admin),
            Response::HTTP_CREATED,
        );
        $codes = $generated['codes'] ?? null;
        self::assertIsArray($codes);
        self::assertCount(3, $codes);

        $afterGenerate = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/stats', null, $admin),
        );
        $genTotal     = $this->intField($afterGenerate, 'total');
        $genAvailable = $this->intField($afterGenerate, 'available');
        $genUsed      = $this->intField($afterGenerate, 'used');
        self::assertSame($beforeTotal + 3, $genTotal, 'Generating 3 codes raises total by 3.');
        self::assertSame($beforeAvailable + 3, $genAvailable, 'Fresh codes are available.');
        self::assertSame($beforeUsed, $genUsed, 'Generating codes does not change used.');
        self::assertSame($genTotal, $genAvailable + $genUsed);

        // Consume one code via the public self-registration flow.
        $first = $codes[0];
        self::assertIsArray($first);
        $code = $first['code'] ?? null;
        self::assertIsString($code);

        $registerPageId = $this->resolveRegisterPageId($em);
        $registered = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/auth/register', [
                'page_id' => $registerPageId,
                'email'   => 'qa_reg_stats_register@selfhelp.test',
                'code'    => $code,
            ]),
            Response::HTTP_CREATED,
        );
        self::assertTrue($registered['registered'] ?? null);

        $afterConsume = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/stats', null, $admin),
        );
        $conTotal     = $this->intField($afterConsume, 'total');
        $conAvailable = $this->intField($afterConsume, 'available');
        $conUsed      = $this->intField($afterConsume, 'used');
        self::assertSame($genTotal, $conTotal, 'Consuming a code does not change total.');
        self::assertSame($genAvailable - 1, $conAvailable, 'Consuming a code moves it out of available.');
        self::assertSame($genUsed + 1, $conUsed, 'Consuming a code moves it into used.');
        self::assertSame($conTotal, $conAvailable + $conUsed);
    }

    /**
     * Assert a stats field is present and an int, and return it narrowed so the
     * arithmetic assertions above stay statically typed.
     *
     * @param array<string, mixed> $data
     */
    private function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        self::assertIsInt($value, "stats.$key must be an integer.");

        return $value;
    }

    private function resolveRegisterPageId(EntityManagerInterface $em): int
    {
        $raw = $em->getConnection()->fetchOne("SELECT id FROM pages WHERE keyword = 'register' LIMIT 1");
        self::assertNotFalse($raw, 'The seeded register page must exist (run composer test:reset-db).');

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
