<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\RoleDataAccess;
use App\Entity\Section;
use App\Entity\User;
use App\Service\CMS\DataRevision\DataRevisionErasureService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A file that an earlier version of an entry held is downloaded over HTTP, and only by the people the history of
 * that entry is for: the record's owner, or somebody who may update the table of a shared list. The file is found by
 * record, version, field, language and position: never by a path. It is a private attachment, and it is gone once the
 * history is erased.
 */
#[PhpUnitGroup('form-list')]
#[PhpUnitGroup('security')]
final class FormListHistoricalFileTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const FIRST = "the first file\n";
    private const SECOND = "the second file, which replaced it\n";

    private UserFactory $users;
    private RoleDataAccessFactory $grants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
        // Built before the first request: a request reboots the kernel, and these must keep to this entity manager.
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->grants = new RoleDataAccessFactory($this->em, $this->service(LookupService::class), $this->service(DataAccessSecurityService::class));
    }

    public function testTheOwnerDownloadsTheFileEveryVersionHeldAsAPrivateAttachment(): void
    {
        $w = $this->world('qa_lhf_owner');

        [$first, $firstBody] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 1, $w['doc']);
        [$second, $secondBody] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 2, $w['doc']);

        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        self::assertSame(self::FIRST, $firstBody, 'Version 1 still holds the file it was saved with.');
        self::assertSame(self::SECOND, $secondBody);
        self::assertStringStartsWith('attachment', (string) $first->headers->get('Content-Disposition'));
        self::assertStringContainsString('first.txt', (string) $first->headers->get('Content-Disposition'));
        self::assertTrue($first->headers->hasCacheControlDirective('private'), (string) $first->headers->get('Cache-Control'));
        self::assertTrue($first->headers->hasCacheControlDirective('no-store'), (string) $first->headers->get('Cache-Control'));
        self::assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $first->headers->get('Content-Security-Policy'));
        self::assertSame(Response::HTTP_OK, $second->getStatusCode());
    }

    public function testAnotherParticipantCannotDownloadTheFileOfAnEntryThatIsNotTheirs(): void
    {
        $w = $this->world('qa_lhf_other');
        $other = $this->participant('qa.lhf.other@selfhelp.test');

        [$response, $body] = $this->download($other, $w['page'], $w['list'], $w['record'], 1, $w['doc']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), 'An entry of somebody else is not in the list of this person.');
        self::assertStringNotContainsString(trim(self::FIRST), $body);
    }

    public function testAGuestCannotDownloadAFileOfAPrivatePage(): void
    {
        $w = $this->world('qa_lhf_guest');

        [$response, $body] = $this->download(null, $w['page'], $w['list'], $w['record'], 1, $w['doc']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringNotContainsString(trim(self::FIRST), $body);
    }

    public function testAGuestOfAnOpenPageIsAskedToSignInAndGetsNoFile(): void
    {
        $w = $this->world('qa_lhf_guest_open', [], true);

        [$response, $body] = $this->download(null, $w['page'], $w['list'], $w['record'], 1, $w['doc']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), 'The page is open, the history of an entry never is.');
        self::assertStringNotContainsString(trim(self::FIRST), $body);
    }

    public function testTheOwnerLosesTheFileWhenThePageIsNoLongerTheirs(): void
    {
        $w = $this->world('qa_lhf_revoked_page');
        [$before] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 1, $w['doc']);
        self::assertSame(Response::HTTP_OK, $before->getStatusCode());

        $this->db->executeStatement('DELETE FROM page_acl_groups WHERE id_pages = :page', ['page' => $w['page']->getId()]);
        $this->pages->invalidatePageScopedCaches();
        [$after, $body] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 1, $w['doc']);

        self::assertSame(Response::HTTP_NOT_FOUND, $after->getStatusCode());
        self::assertStringNotContainsString(trim(self::FIRST), $body);
    }

    public function testTheFileOfAnEntryOfSomebodyElseIsForThoseWhoMayUpdateTheTable(): void
    {
        $w = $this->world('qa_lhf_shared', ['own_entries_only' => '0']);
        $reader = $this->participant('qa.lhf.reader@selfhelp.test');

        [$withoutGrant] = $this->download($reader, $w['page'], $w['list'], $w['record'], 1, $w['doc']);
        self::assertSame(Response::HTTP_FORBIDDEN, $withoutGrant->getStatusCode(), 'Reading the list is not the right to see the history of its entries.');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'qa.lhf.reader@selfhelp.test']);
        self::assertInstanceOf(User::class, $user);
        $role = $this->grants->createRole('qa_lhf_updater');
        $this->grants->assignRoleToUser($user, $role);
        $grant = $this->grants->grantDataTableAccess($role, $w['tableId'], DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE);
        [$granted, $body] = $this->download($reader, $w['page'], $w['list'], $w['record'], 1, $w['doc']);
        self::assertSame(Response::HTTP_OK, $granted->getStatusCode());
        self::assertSame(self::FIRST, $body);

        // The grant is revoked: the very next download is refused again.
        $this->revoke($grant, $role->getId());
        [$revoked, $revokedBody] = $this->download($reader, $w['page'], $w['list'], $w['record'], 1, $w['doc']);
        self::assertSame(Response::HTTP_FORBIDDEN, $revoked->getStatusCode());
        self::assertStringNotContainsString(trim(self::FIRST), $revokedBody);
    }

    /**
     * Each case changes one thing of a request that works: the page, the list, the field, the version, the position
     * or the language. None of them finds the file.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function requestsThatFindNoFile(): iterable
    {
        yield 'the page of another list' => ['page', Response::HTTP_FORBIDDEN];
        yield 'another list' => ['list', Response::HTTP_NOT_FOUND];
        yield 'a field that holds no file' => ['text field', Response::HTTP_NOT_FOUND];
        yield 'a field that does not exist' => ['unknown field', Response::HTTP_NOT_FOUND];
        yield 'a version the entry never had' => ['version', Response::HTTP_NOT_FOUND];
        yield 'version zero' => ['version zero', Response::HTTP_NOT_FOUND];
        yield 'a position past the files' => ['index', Response::HTTP_NOT_FOUND];
        yield 'a position that is no number' => ['index text', Response::HTTP_BAD_REQUEST];
        yield 'a language that holds no file' => ['language', Response::HTTP_NOT_FOUND];
        yield 'a field name that climbs out of the folder' => ['path', Response::HTTP_NOT_FOUND];
    }

    #[DataProvider('requestsThatFindNoFile')]
    public function testAWrongPageListFieldVersionPositionOrLanguageFindsNoFile(string $change, int $status): void
    {
        $w = $this->world('qa_lhf_wrong');
        [$otherPage, $otherList] = $this->anotherList('qa_lhf_wrong_b');

        $page = $w['page'];
        $list = $w['list'];
        $version = 1;
        $field = $w['doc'];
        $query = ['file_language' => '1', 'index' => '0'];
        switch ($change) {
            case 'page':
                $page = $otherPage;
                break;
            case 'list':
                $page = $otherPage;
                $list = $otherList;
                break;
            case 'text field':
                $field = $w['title'];
                break;
            case 'unknown field':
                $field = 'section_999999999';
                break;
            case 'version':
                $version = 99;
                break;
            case 'version zero':
                $version = 0;
                break;
            case 'index':
                $query['index'] = '1';
                break;
            case 'index text':
                $query['index'] = 'first';
                break;
            case 'language':
                $query['file_language'] = '2';
                break;
            case 'path':
                $field = '..';
                break;
        }

        [$response, $body] = $this->download($w['token'], $page, $list, $w['record'], $version, $field, $query);

        self::assertSame($status, $response->getStatusCode(), $change . ': ' . $body);
        self::assertStringNotContainsString(trim(self::FIRST), $body);
        self::assertStringNotContainsString(trim(self::SECOND), $body);
    }

    public function testAFieldTheFormHidesFromTheReaderGivesNoFile(): void
    {
        $w = $this->world('qa_lhf_hidden');
        // The list shows the title only, so the file is reachable as an input of the form, and only while the form shows it.
        $this->pages->setSectionField($w['list'], 'list_columns', json_encode([['field' => $w['title'], 'label' => 'Title']], JSON_THROW_ON_ERROR));
        $input = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_lhf_hidden_in_doc']);
        self::assertInstanceOf(Section::class, $input);
        $this->pages->invalidatePageScopedCaches();
        [$shown] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 1, $w['doc']);
        self::assertSame(Response::HTTP_OK, $shown->getStatusCode());

        $input->setCondition('{"==":[1,2]}');
        $this->em->flush();
        $this->pages->invalidatePageScopedCaches();
        [$hidden, $body] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 1, $w['doc']);

        self::assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode(), 'The history never shows what the form hides.');
        self::assertStringNotContainsString(trim(self::FIRST), $body);
    }

    public function testAListThatOffersNoHistoryOffersNoFile(): void
    {
        $w = $this->world('qa_lhf_off', ['show_history' => '0']);

        [$response, $body] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 1, $w['doc']);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringNotContainsString(trim(self::FIRST), $body);
    }

    public function testErasedHistoryAndTheFilesItKeptCannotBeDownloaded(): void
    {
        $w = $this->world('qa_lhf_erased');
        $directory = $this->uploadsOf((int) $w['form']->getId());
        self::assertCount(2, $this->filesIn($directory), 'Both the current and the replaced file are kept.');
        [$before] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 1, $w['doc']);
        self::assertSame(Response::HTTP_OK, $before->getStatusCode());

        $erased = $this->service(DataRevisionErasureService::class)->eraseTable($w['tableId']);
        $removed = $this->service(FormFileUploadService::class)->deleteUnreferencedFiles($erased->fileKeys);
        $this->pages->invalidatePageScopedCaches();

        self::assertSame(1, $removed, 'The replaced file is no longer named by anything and is removed; the file the entry holds stays.');
        self::assertCount(1, $this->filesIn($directory));
        [$first, $firstBody] = $this->download($w['token'], $w['page'], $w['list'], $w['record'], 1, $w['doc']);
        self::assertSame(Response::HTTP_NOT_FOUND, $first->getStatusCode(), 'The erased version is gone.');
        self::assertStringNotContainsString(trim(self::FIRST), $firstBody);
    }

    // -- fixtures ----------------------------------------------------------------

    /**
     * Another participant list with its own page and table, which the QA user may read too.
     *
     * @return array{0: Page, 1: Section}
     */
    private function anotherList(string $prefix): array
    {
        [$page, $list] = $this->declaredList($prefix, ['title' => ['style' => 'text-input'], 'doc' => ['style' => 'file-input']], ['show_history' => '1']);

        return [$page, $list];
    }

    /**
     * A participant list with a title and a document input, and one entry of the QA user that was saved with a file
     * and then edited with another: version 1 holds the first file, version 2 the second.
     *
     * @param array<string, string> $fields list fields that replace the defaults of this fixture
     * @return array{page: Page, list: Section, form: Section, tableId: int, doc: string, title: string, record: int, token: string}
     */
    private function world(string $prefix, array $fields = [], bool $openAccess = false): array
    {
        [$page, $list, $tableId, $form, $keys] = $this->declaredList(
            $prefix,
            ['title' => ['style' => 'text-input'], 'doc' => ['style' => 'file-input']],
            $fields + ['show_history' => '1'],
            $openAccess,
        );
        $this->uploadsOf((int) $form->getId());

        $token = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->save($token, 'POST', $page, $form, ['title' => 'Report'], 'first.txt', self::FIRST));
        $record = self::coerceInt($created['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->save($token, 'PUT', $page, $form, [
            'title' => 'Report, second edition',
            'update_based_on' => json_encode(['record_id' => $record], JSON_THROW_ON_ERROR),
            'expected_version' => '1',
        ], 'second.txt', self::SECOND));
        self::assertSame(2, self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $record])));

        return [
            'page' => $page,
            'list' => $list,
            'form' => $form,
            'tableId' => $tableId,
            'doc' => $keys['doc'],
            'title' => $keys['title'],
            'record' => $record,
            'token' => $token,
        ];
    }

    /** Another loginable participant: in the group that may read the page, owner of nothing. */
    private function participant(string $email): string
    {
        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->users->createUser($email, $email, [$subject]);

        return $this->loginAs($email);
    }

    private function revoke(RoleDataAccess $grant, ?int $roleId): void
    {
        $this->em->remove($grant);
        $this->em->flush();
        self::assertNotNull($roleId);
        $this->service(DataAccessSecurityService::class)->invalidateRolePermissions($roleId);
    }

    // -- requests ----------------------------------------------------------------

    /**
     * A save with one uploaded file in the `doc` input.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function save(string $token, string $method, Page $page, Section $form, array $fields, string $name, string $contents): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_lhf_');
        file_put_contents($path, $contents);
        $this->client->request(
            $method,
            $method === 'POST' ? '/cms-api/v1/forms/submit' : '/cms-api/v1/forms/update',
            ['page_id' => (string) $page->getId(), 'section_id' => (string) $form->getId()] + $fields,
            ['doc' => new UploadedFile($path, $name, null, null, true)],
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $token],
        );

        return $this->decode($this->client->getResponse());
    }

    /**
     * @param array<string, string> $query
     * @return array{0: Response, 1: string} the response and the body it sent (a file or an error)
     */
    private function download(?string $token, Page $page, Section $list, int $record, int $version, string $field, array $query = ['file_language' => '1', 'index' => '0']): array
    {
        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/lists/%d/records/%d/versions/%d/files/%s', $list->getId(), $record, $version, $field),
            ['page_id' => (string) $page->getId()] + $query,
            [],
            $token === null ? [] : ['HTTP_Authorization' => 'Bearer ' . $token],
        );

        return [$this->client->getResponse(), (string) $this->client->getInternalResponse()->getContent()];
    }

}
