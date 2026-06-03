<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Entity\Group;
use App\Entity\ValidationCode;
use App\Service\Auth\RegistrationCodeService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Integration coverage for {@see RegistrationCodeService} generation: configured
 * maximums, exact-count uniqueness, collision-safe accounting and the overall
 * available-code cap. All rows are qa-scoped and rolled back by DAMA.
 *
 * The generation path uses real SQL against the seeded schema (no mocks) so the
 * INSERT-IGNORE-with-pre-SELECT accounting is exercised exactly as in production.
 */
#[TestGroup('integration')]
final class RegistrationCodeServiceTest extends QaKernelTestCase
{
    private Group $qaGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qaGroup = (new GroupFactory($this->em))->createGroup('qa_regcode_group');
    }

    public function testGenerateCreatesExactlyTheRequestedNumberOfUniqueCodes(): void
    {
        $service = $this->service(RegistrationCodeService::class);

        $result = $service->generate(25, $this->qaGroupId());
        $codes = $result['codes'];

        self::assertCount(25, $codes, 'generate() must return exactly the requested number of codes.');

        $values = array_map(static fn (array $c): string => $c['code'], $codes);
        self::assertCount(25, array_unique($values), 'Every generated code must be unique.');

        foreach ($codes as $code) {
            self::assertSame($code['id'], $code['code'], 'Entity id mirrors the code value.');
            self::assertSame($this->qaGroupId(), $code['id_groups']);
            self::assertSame($this->qaGroup->getName(), $code['group_name']);
            self::assertFalse($code['is_consumed'], 'A freshly generated code is not consumed.');
            self::assertNull($code['consumed_at']);
            self::assertNull($code['id_users'], 'A freshly generated code is not linked to a user.');
            self::assertNull($code['user_email']);
        }

        // Every returned code is actually persisted, unconsumed, in the group.
        foreach ($values as $value) {
            $row = $this->em->getRepository(ValidationCode::class)->find($value);
            self::assertInstanceOf(ValidationCode::class, $row, "Code {$value} must be persisted.");
            self::assertNull($row->getConsumed());
            self::assertSame($this->qaGroupId(), (int) $row->getGroup()?->getId());
        }
    }

    public function testGenerateRejectsCountAboveTheConfiguredRequestMaximum(): void
    {
        // requestMax = 5: the schema deliberately has no hardcoded maximum, the
        // service is the single source of truth (also surfaced as config.generate_max).
        $service = new RegistrationCodeService($this->em, 10000, 5);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Count must be between 1 and 5.');

        $service->generate(6, $this->qaGroupId());
    }

    public function testGenerateRejectsNonPositiveCount(): void
    {
        $service = $this->service(RegistrationCodeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->generate(0, $this->qaGroupId());
    }

    public function testGenerateRejectsUnknownGroup(): void
    {
        $service = $this->service(RegistrationCodeService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Group not found.');

        $service->generate(1, 999_999_999);
    }

    public function testGenerateNeverReturnsOrDuplicatesAnExistingCode(): void
    {
        // Pre-seed a known code; generation must account for it (the pre-SELECT
        // branch) so it is never re-issued and the count stays exact.
        $existing = $this->seedCode('QAEXISTS1');

        $service = $this->service(RegistrationCodeService::class);
        $result = $service->generate(40, $this->qaGroupId());
        $values = array_map(static fn (array $c): string => $c['code'], $result['codes']);

        self::assertCount(40, $values);
        self::assertCount(40, array_unique($values), 'No duplicates within a batch.');
        self::assertNotContains($existing, $values, 'A pre-existing code must never be re-issued.');

        // The pre-existing code is untouched (still unconsumed, still present).
        $row = $this->em->getRepository(ValidationCode::class)->find($existing);
        self::assertInstanceOf(ValidationCode::class, $row);
        self::assertNull($row->getConsumed());
    }

    public function testGenerateRespectsTheOverallAvailableCodeCap(): void
    {
        $available = $this->availableCodeCount();

        // totalMax equal to the current available count => zero headroom, so any
        // generation must be refused without inserting a row.
        $service = new RegistrationCodeService($this->em, $available, 10000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('table limit');

        $service->generate(1, $this->qaGroupId());
    }

    private function qaGroupId(): int
    {
        return (int) $this->qaGroup->getId();
    }

    private function availableCodeCount(): int
    {
        $raw = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM validation_codes WHERE consumed IS NULL');

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function seedCode(string $code): string
    {
        $entity = new ValidationCode();
        $entity->setCode($code);
        $entity->setGroup($this->qaGroup);
        $this->em->persist($entity);
        $this->em->flush();

        return $code;
    }
}
