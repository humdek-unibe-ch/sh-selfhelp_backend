<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\Section;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * The record version at the form endpoints: every response says which version
 * the record is at, a save or delete based on an older version is refused with
 * 409 and writes nothing, and one that names no version is refused with 400.
 * The classic record styles learn the version from the page: see
 * {@see LegacyRecordVersionTest}.
 */
#[PhpUnitGroup('form-list')]
final class FormRecordVersionTest extends QaWebTestCase
{
    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const UPDATE = '/cms-api/v1/forms/update';
    private const DELETE = '/cms-api/v1/forms/delete';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testEveryResponseNamesTheVersionAndAStaleWriteIsRefused(): void
    {
        [$page, $form, $delete] = $this->world('qa_ver_flow');
        $token = $this->loginAsQaUser();

        $created = $this->assertEnvelopeSuccess($this->submit($token, $page, $form, 'one'));
        $recordId = self::coerceInt($created['record_id'] ?? null);
        self::assertSame(1, $created['version'] ?? null, 'A new record starts at version 1.');

        $updated = $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, 'two', 1));
        self::assertSame(2, $updated['version'] ?? null);

        $stale = $this->update($token, $page, $form, $recordId, 'lost edit', 1);
        self::assertSame(409, $stale['status'] ?? null, 'A save based on version 1 is stale once the record is at version 2.');
        $conflict = $stale['data'] ?? null;
        self::assertIsArray($conflict, 'The refusal says which version the record is at now, so the client can reload.');
        self::assertSame('stale_version', $conflict['reason'] ?? null);
        self::assertSame(1, $conflict['expected_version'] ?? null);
        self::assertSame(2, $conflict['current_version'] ?? null);
        self::assertSame($recordId, $conflict['record_id'] ?? null);
        self::assertSame('two', $this->cellValue($recordId), 'The refused edit wrote nothing.');
        self::assertSame(2, $this->versionOf($recordId));

        // A save that does not name the version is refused: it could overwrite a change the person never saw.
        $unversioned = $this->update($token, $page, $form, $recordId, 'unversioned', null);
        self::assertSame(400, $unversioned['status'] ?? null);
        self::assertIsArray($unversioned['data'] ?? null);
        self::assertSame('expected_version_required', $unversioned['data']['reason'] ?? null);
        self::assertSame('two', $this->cellValue($recordId), 'The unversioned edit wrote nothing.');
        self::assertSame(2, $this->versionOf($recordId));

        $current = $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, 'three', 2));
        self::assertSame(3, $current['version'] ?? null);

        // A delete based on an older version is refused and the record stays.
        $staleDelete = $this->delete($token, $page, $delete, $recordId, 2);
        self::assertSame(409, $staleDelete['status'] ?? null);
        self::assertFalse($this->isDeleted($recordId));

        $deleted = $this->assertEnvelopeSuccess($this->delete($token, $page, $delete, $recordId, 3));
        self::assertSame(4, $deleted['version'] ?? null);
        self::assertTrue($this->isDeleted($recordId));

        $operations = $this->em->getConnection()->fetchFirstColumn(
            'SELECT op.lookup_code FROM data_revisions r JOIN lookups op ON op.id = r.id_operations WHERE r.id_data_rows = :id ORDER BY r.version',
            ['id' => $recordId],
        );
        self::assertSame(['create', 'update', 'update', 'delete'], $operations);
    }

    public function testTheHistoryNamesThePersonAndTheDoorTheChangeCameThrough(): void
    {
        [$page, $form] = $this->world('qa_ver_actor');
        $token = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->submit($token, $page, $form, 'first'));
        $recordId = self::coerceInt($created['record_id'] ?? null);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT r.id_users, u.email, src.lookup_code AS source FROM data_revisions r'
            . ' JOIN lookups src ON src.id = r.id_sources LEFT JOIN users u ON u.id = r.id_users'
            . ' WHERE r.id_data_rows = :id AND r.version = 1',
            ['id' => $recordId],
        );
        self::assertIsArray($row);
        self::assertSame('qa.user@selfhelp.test', $row['email']);
        self::assertSame('form', $row['source']);
    }

    public function testTheVersionCanBeSentAsAMultipartFieldToo(): void
    {
        // Forms with a file input are sent as multipart: the version travels as text.
        [$page, $form] = $this->world('qa_ver_multipart');
        $token = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->submit($token, $page, $form, 'one'));
        $recordId = self::coerceInt($created['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, 'two', 1));

        $stale = $this->multipartUpdate($token, $page, $form, $recordId, 'lost edit', '1');
        self::assertSame(409, $stale['status'] ?? null);
        $conflict = $stale['data'] ?? null;
        self::assertIsArray($conflict);
        self::assertSame('stale_version', $conflict['reason'] ?? null);
        self::assertSame(1, $conflict['expected_version'] ?? null);
        self::assertSame(2, $conflict['current_version'] ?? null);
        self::assertSame('two', $this->cellValue($recordId), 'The refused multipart edit wrote nothing.');

        $accepted = $this->assertEnvelopeSuccess($this->multipartUpdate($token, $page, $form, $recordId, 'three', '2'));
        self::assertSame(3, $accepted['version'] ?? null);
        self::assertSame('three', $this->cellValue($recordId));
    }

    public function testADeleteThatNamesNoVersionIsRefusedAndTheRecordStays(): void
    {
        [$page, $form, $delete] = $this->world('qa_ver_delete_unversioned');
        $token = $this->loginAsQaUser();
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->submit($token, $page, $form, 'keep me'))['record_id'] ?? null);

        $envelope = $this->delete($token, $page, $delete, $recordId, null);

        self::assertSame(400, $envelope['status'] ?? null);
        self::assertSame('expected_version_required', is_array($envelope['data'] ?? null) ? ($envelope['data']['reason'] ?? null) : null);
        self::assertFalse($this->isDeleted($recordId));
        self::assertSame(1, $this->versionOf($recordId));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function versionsThatAreNotVersions(): iterable
    {
        yield 'zero' => [0];
        yield 'a negative number' => [-1];
        yield 'a fraction' => [1.5];
        yield 'a word' => ['latest'];
        yield 'digits as text, which would be coerced' => ['1'];
        yield 'a boolean' => [true];
        yield 'a list' => [[1]];
        yield 'an object' => [['version' => 1]];
        yield 'null' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('versionsThatAreNotVersions')]
    public function testAVersionThatIsNoVersionIsRefusedBeforeAnythingIsWritten(mixed $version): void
    {
        [$page, $form, $delete] = $this->world('qa_ver_malformed');
        $token = $this->loginAsQaUser();
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->submit($token, $page, $form, 'original'))['record_id'] ?? null);

        $update = $this->jsonRequest('PUT', self::UPDATE, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['qa_answer' => 'changed'],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => $version,
        ], $token);
        $deletion = $this->jsonRequest('DELETE', self::DELETE, [
            'record_id' => $recordId,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $delete->getId(),
            'expected_version' => $version,
        ], $token);

        self::assertSame(400, $update['status'] ?? null, json_encode($update, JSON_THROW_ON_ERROR));
        self::assertSame(400, $deletion['status'] ?? null, json_encode($deletion, JSON_THROW_ON_ERROR));
        self::assertSame('original', $this->cellValue($recordId));
        self::assertSame(1, $this->versionOf($recordId));
        self::assertFalse($this->isDeleted($recordId));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function multipartVersionsThatAreNotVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'a space' => [' '];
        yield 'a leading space' => [' 1'];
        yield 'a trailing space' => ['1 '];
        yield 'a leading zero' => ['01'];
        yield 'a sign' => ['+1'];
        yield 'zero' => ['0'];
        yield 'a negative number' => ['-1'];
        yield 'a decimal' => ['1.0'];
        yield 'an exponent' => ['1e0'];
        yield 'hexadecimal' => ['0x1'];
        yield 'a word' => ['abc'];
        yield 'another script\'s digit' => ["\u{0661}"];
        yield 'more digits than a version has' => ['99999999999'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('multipartVersionsThatAreNotVersions')]
    public function testAMultipartVersionIsReadStrictlyAndNeverCoerced(string $version): void
    {
        [$page, $form] = $this->world('qa_ver_multipart_malformed');
        $token = $this->loginAsQaUser();
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->submit($token, $page, $form, 'original'))['record_id'] ?? null);

        $envelope = $this->multipartUpdate($token, $page, $form, $recordId, 'changed', $version);

        self::assertSame(400, $envelope['status'] ?? null, json_encode($envelope, JSON_THROW_ON_ERROR));
        self::assertSame('original', $this->cellValue($recordId));
        self::assertSame(1, $this->versionOf($recordId));
    }

    // -- helpers --------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function multipartUpdate(string $token, Page $page, Section $section, int $recordId, string $value, string $expectedVersion): array
    {
        $this->client->request(
            'PUT',
            self::UPDATE,
            [
                'page_id' => (string) $page->getId(),
                'section_id' => (string) $section->getId(),
                'qa_answer' => $value,
                'update_based_on' => json_encode(['record_id' => $recordId], JSON_THROW_ON_ERROR),
                'expected_version' => $expectedVersion,
            ],
            [],
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $token],
        );

        return $this->decode($this->client->getResponse());
    }

    /**
     * @return array{0: Page, 1: Section, 2: Section}
     */
    private function world(string $prefix): array
    {
        [$page, $form] = $this->pages->createFormPage($prefix, openAccess: false);
        $delete = $this->pages->createSection($prefix . '_delete', 'entry-record-delete');
        $this->pages->linkSectionToPage($page, $delete, 30);
        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->pages->grantGroupAcl($page, $subject, select: true, insert: true, update: true, delete: true);

        // The delete trigger is bound to the form's table, which exists after the first save.
        $token = $this->loginAsQaUser();
        $seed = $this->assertEnvelopeSuccess($this->submit($token, $page, $form, 'seed'));
        self::assertIsInt($seed['record_id'] ?? null);
        $tableId = self::coerceInt($this->em->getConnection()->fetchOne('SELECT id FROM data_tables WHERE name = :n', ['n' => (string) $form->getId()]));
        $field = $this->em->getRepository(\App\Entity\Field::class)->findOneBy(['name' => 'data_table']);
        $language = $this->em->getRepository(\App\Entity\Language::class)->find(1);
        self::assertInstanceOf(\App\Entity\Field::class, $field);
        self::assertInstanceOf(\App\Entity\Language::class, $language);
        $translation = new \App\Entity\SectionsFieldsTranslation();
        $managed = $this->em->getRepository(Section::class)->find($delete->getId());
        self::assertInstanceOf(Section::class, $managed);
        $translation->setSection($managed);
        $translation->setField($field);
        $translation->setLanguage($language);
        $translation->setContent((string) $tableId);
        $this->em->persist($translation);
        $this->em->flush();

        return [$page, $form, $delete];
    }

    /**
     * @return array<string, mixed>
     */
    private function submit(string $token, Page $page, Section $section, string $value): array
    {
        return $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => $value],
        ], $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $token, Page $page, Section $section, int $recordId, string $value, ?int $expectedVersion): array
    {
        $body = [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => $value],
            'update_based_on' => ['record_id' => $recordId],
        ];
        if ($expectedVersion !== null) {
            $body['expected_version'] = $expectedVersion;
        }

        return $this->jsonRequest('PUT', self::UPDATE, $body, $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $token, Page $page, Section $section, int $recordId, ?int $expectedVersion): array
    {
        $body = [
            'record_id' => $recordId,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
        ];
        if ($expectedVersion !== null) {
            $body['expected_version'] = $expectedVersion;
        }

        return $this->jsonRequest('DELETE', self::DELETE, $body, $token);
    }

    private function cellValue(int $recordId): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols WHERE c.id_data_rows = :row AND col.field_key = :key AND c.id_languages = 1',
            ['row' => $recordId, 'key' => 'qa_answer'],
        );

        return $value === false ? null : self::coerceString($value);
    }

    private function versionOf(int $recordId): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function isDeleted(int $recordId): bool
    {
        $deletedId = $this->service(LookupService::class)->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);
        $current = $this->em->getConnection()->fetchOne('SELECT id_action_trigger_types FROM data_rows WHERE id = :id', ['id' => $recordId]);

        return self::coerceInt($current) === $deletedId;
    }
}
