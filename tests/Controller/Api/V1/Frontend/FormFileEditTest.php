<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Page;
use App\Entity\Section;
use App\Service\CMS\FormFileUploadService;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract 4: update keeps, replaces or removes files; each change is one
 * revision; old bytes stay while history names them.
 */
#[PhpUnitGroup('form-list')]
final class FormFileEditTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const FIRST = "first upload\n";
    private const SECOND = "second upload\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testUpdateWithoutFileKeepsTheFile(): void
    {
        $w = $this->world('qa_ffe_keep');
        $before = $this->cellValue($w['record'], $w['doc']);
        self::assertNotNull($before);

        $this->assertEnvelopeSuccess($this->update($w, ['title' => 'Renamed'], null));

        self::assertSame($before, $this->cellValue($w['record'], $w['doc']));
        self::assertSame(2, $this->versionOf($w['record']));
        self::assertCount(1, $this->filesIn($w['uploads']));
    }

    public function testRemoveClearsTheFileAndKeepsBytesForHistory(): void
    {
        $w = $this->world('qa_ffe_remove');
        $before = $this->cellValue($w['record'], $w['doc']);
        self::assertNotNull($before);

        $this->assertEnvelopeSuccess($this->update($w, [
            'title' => 'Cleared',
            'doc' => json_encode(['remove' => true], JSON_THROW_ON_ERROR),
        ], null));

        self::assertSame('', (string) $this->cellValue($w['record'], $w['doc']));
        self::assertSame(2, $this->versionOf($w['record']));
        self::assertCount(1, $this->filesIn($w['uploads']), 'Old bytes stay while a revision names them.');

        [$response, $body] = $this->historical($w, 1);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::FIRST, $body);
    }

    public function testReplaceStoresTheNewFileAndKeepsTheOldBytes(): void
    {
        $w = $this->world('qa_ffe_replace');

        $this->assertEnvelopeSuccess($this->update($w, ['title' => 'Replaced'], ['name' => 'second.txt', 'body' => self::SECOND]));

        $after = $this->cellValue($w['record'], $w['doc']);
        self::assertNotNull($after);
        self::assertStringContainsString('second', FormFileUploadService::nameOfKey(
            FormFileUploadService::keysIn((string) $after)[0] ?? ''
        ));
        self::assertSame(2, $this->versionOf($w['record']));
        self::assertCount(2, $this->filesIn($w['uploads']));

        [$response, $body] = $this->historical($w, 1);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::FIRST, $body);
    }

    /**
     * @return array{
     *   page: Page,
     *   list: Section,
     *   form: Section,
     *   doc: string,
     *   record: int,
     *   token: string,
     *   uploads: string
     * }
     */
    private function world(string $prefix): array
    {
        [$page, $list, , $form, $keys] = $this->declaredList(
            $prefix,
            ['title' => ['style' => 'text-input'], 'doc' => ['style' => 'file-input']],
            ['show_history' => '1', 'own_entries_only' => '0'],
        );
        $uploads = $this->uploadsOf((int) $form->getId());
        $token = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->submit($token, $page, $form, 'first.txt', self::FIRST));
        $record = self::coerceInt($created['record_id'] ?? null);

        return [
            'page' => $page,
            'list' => $list,
            'form' => $form,
            'doc' => $keys['doc'],
            'record' => $record,
            'token' => $token,
            'uploads' => $uploads,
        ];
    }

    /**
     * @param array<string, string> $fields
     * @param array{name: string, body: string}|null $file
     * @param array{page: Page, form: Section, record: int, token: string} $w
     * @return array<string, mixed>
     */
    private function update(array $w, array $fields, ?array $file): array
    {
        $fields['update_based_on'] = json_encode(['record_id' => $w['record']], JSON_THROW_ON_ERROR);
        $fields['expected_version'] = (string) $this->versionOf($w['record']);
        $uploads = [];
        if ($file !== null) {
            $path = (string) tempnam(sys_get_temp_dir(), 'qa_ffe_');
            file_put_contents($path, $file['body']);
            $uploads['doc'] = new UploadedFile($path, $file['name'], null, null, true);
        }
        $this->client->request(
            'PUT',
            '/cms-api/v1/forms/update',
            ['page_id' => (string) $w['page']->getId(), 'section_id' => (string) $w['form']->getId()] + $fields,
            $uploads,
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $w['token']],
        );

        return $this->decode($this->client->getResponse());
    }

    /**
     * @return array<string, mixed>
     */
    private function submit(string $token, Page $page, Section $form, string $name, string $contents): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_ffe_');
        file_put_contents($path, $contents);
        $this->client->request(
            'POST',
            '/cms-api/v1/forms/submit',
            ['page_id' => (string) $page->getId(), 'section_id' => (string) $form->getId(), 'title' => 'Doc'],
            ['doc' => new UploadedFile($path, $name, null, null, true)],
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $token],
        );

        return $this->decode($this->client->getResponse());
    }

    private function cellValue(int $recordId, string $fieldKey): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols dc ON dc.id = c.id_data_cols
             WHERE c.id_data_rows = :id AND dc.field_key = :key LIMIT 1',
            ['id' => $recordId, 'key' => $fieldKey],
        );

        return is_string($value) ? $value : null;
    }

    private function versionOf(int $recordId): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    /**
     * @param array{page: Page, list: Section, record: int, doc: string, token: string} $w
     * @return array{0: Response, 1: string}
     */
    private function historical(array $w, int $version): array
    {
        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/lists/%d/records/%d/versions/%d/files/%s', $w['list']->getId(), $w['record'], $version, $w['doc']),
            ['page_id' => (string) $w['page']->getId(), 'index' => '0'],
            [],
            ['HTTP_Authorization' => 'Bearer ' . $w['token']],
        );

        return [$this->client->getResponse(), (string) $this->client->getInternalResponse()->getContent()];
    }
}
