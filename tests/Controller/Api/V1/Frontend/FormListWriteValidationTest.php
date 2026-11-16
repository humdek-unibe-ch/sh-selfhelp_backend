<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Action;
use App\Entity\DataTable;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * One set of rules for every way a value gets into an entry: the inputs of the form as the page is served say what
 * a value may be (required, a range, one of the offered options, a format, read-only, hidden by a condition, which
 * files). A normal submit or edit, the value a quick action assigns and the restore of an earlier version all
 * answer to them, and a refusal is complete: no row, no revision, no stored upload and no scheduled action.
 */
#[PhpUnitGroup('form-list')]
#[PhpUnitGroup('security')]
final class FormListWriteValidationTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const STATUS_OPTIONS = '[{"value":"open","text":"Open"},{"value":"done","text":"Done"}]';
    private const HIDDEN = '{"==":[1,2]}';

    /** @var array<string, array{style?: string, fields?: array<string, string>, type?: string}> */
    private const INPUTS = [
        'title' => ['style' => 'text-input', 'fields' => ['is_required' => '1', 'max_length' => '12']],
        'seats' => ['style' => 'number-input', 'type' => 'number', 'fields' => ['web_numeric_min' => '1', 'web_numeric_max' => '10']],
        'status' => ['style' => 'select', 'fields' => ['options' => self::STATUS_OPTIONS]],
        'contact' => ['style' => 'input', 'fields' => ['type_input' => 'email']],
        'locked' => ['style' => 'text-input', 'fields' => ['readonly' => '1']],
        'secret' => ['style' => 'text-input'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function brokenRules(): iterable
    {
        yield 'a required value is missing' => [['seats' => '3'], 'title', 'required'];
        yield 'a required value is empty' => [['title' => '  ', 'seats' => '3'], 'title', 'required'];
        yield 'a text longer than the input allows' => [['title' => 'much too long a title'], 'title', 'max_length'];
        yield 'a number above the range' => [['title' => 'A', 'seats' => '11'], 'seats', 'max'];
        yield 'a number below the range' => [['title' => 'A', 'seats' => '0'], 'seats', 'min'];
        yield 'a number that is not one' => [['title' => 'A', 'seats' => 'many'], 'seats', 'type'];
        yield 'an option the input does not offer' => [['title' => 'A', 'status' => 'closed'], 'status', 'option'];
        yield 'an e-mail address that is not one' => [['title' => 'A', 'contact' => 'not-an-address'], 'contact', 'format'];
        yield 'a value for a read-only input' => [['title' => 'A', 'locked' => 'forced'], 'locked', 'read_only'];
        yield 'a value for an input a condition hides' => [['title' => 'A', 'secret' => 'hidden'], 'secret', 'not_available'];
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('brokenRules')]
    public function testANewEntryThatBreaksARuleOfItsInputIsRefusedWhole(array $payload, string $input, string $rule): void
    {
        [$page, , $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_new');
        $before = $this->footprint($tableId, $watchers);

        $envelope = $this->submit($this->loginAsQaUser(), $page, $form, $payload);

        $this->assertRefusedWith($envelope, $keys[$input], $rule);
        self::assertSame($before, $this->footprint($tableId, $watchers), 'A refused entry leaves no row, no revision and no scheduled action.');
    }

    public function testAnEntryThatKeepsTheRulesIsStoredAndRunsTheTablesActionOnce(): void
    {
        [$page, , $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_ok');

        $data = $this->assertEnvelopeSuccess($this->submit($this->loginAsQaUser(), $page, $form, [
            'title' => 'Fits', 'seats' => '4', 'status' => 'done', 'contact' => 'a@b.test',
        ]));

        $recordId = self::coerceInt($data['record_id'] ?? null);
        self::assertSame('done', $this->cell($recordId, $keys['status']));
        self::assertSame('4', $this->cell($recordId, $keys['seats']));
        self::assertSame(1, $this->footprint($tableId, $watchers)['jobs'], 'The same request that is refused above would have scheduled it.');
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('brokenRules')]
    public function testAnEditThatBreaksARuleOfItsInputKeepsTheRecordAsItWas(array $payload, string $input, string $rule): void
    {
        [$page, , $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_edit');
        $recordId = $this->record($tableId, $keys, ['title' => 'Kept', 'seats' => '2', 'status' => 'open']);
        $before = $this->footprint($tableId, $watchers);

        // A required value that is absent from an edit is not a change; only the cases that send a value apply here.
        if ($rule === 'required') {
            $payload = ['title' => ''];
        }
        $envelope = $this->update($this->loginAsQaUser(), $page, $form, $recordId, $payload, 1);

        $this->assertRefusedWith($envelope, $keys[$rule === 'required' ? 'title' : $input], $rule);
        self::assertSame($before, $this->footprint($tableId, $watchers));
        self::assertSame('Kept', $this->cell($recordId, $keys['title']));
        self::assertSame(1, $this->versionOf($recordId));
    }

    public function testAnEditThatKeepsTheRulesChangesTheRecordOnce(): void
    {
        [$page, , $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_edit_ok');
        $recordId = $this->record($tableId, $keys, ['title' => 'Kept', 'seats' => '2', 'status' => 'open']);

        $data = $this->assertEnvelopeSuccess($this->update($this->loginAsQaUser(), $page, $form, $recordId, ['status' => 'done', 'seats' => '5'], 1));

        self::assertSame(2, $data['version'] ?? null);
        self::assertSame('done', $this->cell($recordId, $keys['status']));
        self::assertSame('5', $this->cell($recordId, $keys['seats']));
        self::assertSame('Kept', $this->cell($recordId, $keys['title']), 'What the edit did not send stays.');
        self::assertSame(1, $this->footprint($tableId, $watchers)['jobs']);
    }

    public function testAQuickActionThatWouldWriteAValueTheFormRefusesChangesNothing(): void
    {
        [$page, $list, $tableId, , $keys, $watchers] = $this->world('qa_lwv_action');
        // The author names the column by its key; the value is one the input does not offer.
        $this->pages->setSectionField($list, 'quick_actions', json_encode([
            ['id' => 'close', 'label' => 'Close', 'set' => [$keys['status'] => 'closed']],
            ['id' => 'finish', 'label' => 'Finish', 'set' => [$keys['status'] => 'done']],
        ], JSON_THROW_ON_ERROR));
        $this->pages->invalidatePageScopedCaches();
        $recordId = $this->record($tableId, $keys, ['title' => 'Kept', 'status' => 'open']);
        $before = $this->footprint($tableId, $watchers);
        $token = $this->loginAsQaUser();

        $envelope = $this->act($token, $page, $list, $recordId, 'close', 1);

        $this->assertRefusedWith($envelope, $keys['status'], 'option');
        self::assertSame($before, $this->footprint($tableId, $watchers));
        self::assertSame('open', $this->cell($recordId, $keys['status']));

        // The same click on an action whose value is offered goes through.
        $data = $this->assertEnvelopeSuccess($this->act($token, $page, $list, $recordId, 'finish', 1));
        self::assertSame(2, $data['version'] ?? null);
        self::assertSame('done', $this->cell($recordId, $keys['status']));
    }

    public function testAnOldValueThatTheFormRefusesNowBlocksTheWholeRestore(): void
    {
        [$page, $list, $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_restore', [], ['show_history' => '1']);
        $token = $this->loginAsQaUser();
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->submit($token, $page, $form, ['title' => 'First', 'seats' => '3', 'status' => 'open']))['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, ['title' => 'Second', 'status' => 'done'], 1));

        // The author narrows the form: "open" is not an option any more.
        $this->setField($keys['status'], 'options', '[{"value":"done","text":"Done"}]');
        $before = $this->footprint($tableId, $watchers);

        $envelope = $this->restore($token, $page, $list, $recordId, 1, 2);

        self::assertSame(422, $envelope['status'] ?? null, json_encode($envelope, JSON_THROW_ON_ERROR));
        $data = $this->at($envelope, 'data');
        self::assertSame('restore_blocked', $data['reason'] ?? null);
        $blockers = $this->listOf($data, 'blockers');
        self::assertSame(
            [$keys['status']],
            array_values(array_unique(array_map(static fn (array $blocker): string => self::coerceString($blocker['field_key'] ?? ''), $blockers))),
        );
        self::assertSame('invalid_value', $blockers[0]['reason'] ?? null);
        self::assertSame($before, $this->footprint($tableId, $watchers), 'A blocked restore writes nothing, not even the fields that would have fit.');
        self::assertSame('Second', $this->cell($recordId, $keys['title']));
        self::assertSame(2, $this->versionOf($recordId));
    }

    public function testRemovingAFieldThatIsRequiredNowBlocksTheWholeRestore(): void
    {
        [$page, $list, $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_restore_required', [], ['show_history' => '1']);
        $token = $this->loginAsQaUser();
        // Version 1 has no status, version 2 has one: restoring version 1 would remove it.
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->submit($token, $page, $form, ['title' => 'First']))['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, ['status' => 'done'], 1));

        $this->setField($keys['status'], 'is_required', '1');
        $before = $this->footprint($tableId, $watchers);

        $envelope = $this->restore($token, $page, $list, $recordId, 1, 2);

        self::assertSame(422, $envelope['status'] ?? null, json_encode($envelope, JSON_THROW_ON_ERROR));
        self::assertSame('restore_blocked', $this->at($envelope, 'data')['reason'] ?? null);
        self::assertSame($before, $this->footprint($tableId, $watchers));
        self::assertSame('done', $this->cell($recordId, $keys['status']));
    }

    public function testARestoreThatStillFitsTheFormIsWrittenOnce(): void
    {
        [$page, $list, $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_restore_ok', [], ['show_history' => '1']);
        $token = $this->loginAsQaUser();
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->submit($token, $page, $form, ['title' => 'First', 'status' => 'open']))['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, ['title' => 'Second', 'status' => 'done'], 1));
        $jobs = $this->footprint($tableId, $watchers)['jobs'];

        $data = $this->assertEnvelopeSuccess($this->restore($token, $page, $list, $recordId, 1, 2));

        self::assertSame(3, $data['version'] ?? null);
        self::assertSame('First', $this->cell($recordId, $keys['title']));
        self::assertSame('open', $this->cell($recordId, $keys['status']));
        self::assertSame($jobs + 1, $this->footprint($tableId, $watchers)['jobs'], 'A restore is an update: the table\'s action runs once.');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function restoresWithoutAUsableVersion(): iterable
    {
        yield 'no version' => [[]];
        yield 'zero' => [['expected_version' => 0]];
        yield 'a fraction' => [['expected_version' => 1.5]];
        yield 'text, which would be coerced' => [['expected_version' => '2']];
        yield 'null' => [['expected_version' => null]];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('restoresWithoutAUsableVersion')]
    public function testARestoreThatNamesNoUsableVersionIsRefusedWholeAndAPreviewNeedsNone(array $extra): void
    {
        [$page, $list, $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_restore_version', [], ['show_history' => '1']);
        $token = $this->loginAsQaUser();
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->submit($token, $page, $form, ['title' => 'First', 'status' => 'open']))['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, ['title' => 'Second', 'status' => 'done'], 1));
        $before = $this->footprint($tableId, $watchers);
        $url = '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/restore';

        $refused = $this->jsonRequest('POST', $url, $extra + ['page_id' => (int) $page->getId(), 'version' => 1], $token);
        $preview = $this->jsonRequest('POST', $url, ['page_id' => (int) $page->getId(), 'version' => 1, 'dry_run' => true], $token);

        self::assertSame(400, $refused['status'] ?? null, json_encode($refused, JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->footprint($tableId, $watchers), 'A refused restore writes nothing.');
        self::assertSame('Second', $this->cell($recordId, $keys['title']));
        $data = $this->assertEnvelopeSuccess($preview);
        self::assertTrue($data['dry_run'] ?? null);
        self::assertTrue($data['restorable'] ?? null, 'A dry run only reads: it shows what would change, so it needs no version to be based on.');
        self::assertSame($before, $this->footprint($tableId, $watchers));
    }

    public function testAFileThatBreaksTheInputsRulesIsNeverStoredAndTheWholeRequestIsRefused(): void
    {
        [$page, , $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_file', [
            'doc' => ['style' => 'file-input', 'fields' => ['web_file_input_accept' => '.txt']],
        ]);
        $before = $this->footprint($tableId, $watchers);
        $directory = $this->uploadsOf((int) $form->getId());
        self::assertSame([], $this->filesIn($directory));

        // A text that fits next to a file the input does not accept.
        $envelope = $this->submitWithFiles($this->loginAsQaUser(), $page, $form, ['title' => 'Fits'], ['doc' => [$this->upload('virus.exe', "MZ\n")]]);
        $this->assertRefusedWith($envelope, $keys['doc'], 'file_type');
        self::assertSame([], $this->filesIn($directory), 'A refused request leaves no upload behind.');

        // A file that fits next to a text that does not.
        $envelope = $this->submitWithFiles($this->loginAsQaUser(), $page, $form, ['title' => 'a title that is far too long'], ['doc' => [$this->upload('note.txt', "fine\n")]]);
        $this->assertRefusedWith($envelope, $keys['title'], 'max_length');
        self::assertSame([], $this->filesIn($directory), 'The upload is only stored once every value is accepted.');

        // Two files in an input that takes one.
        $envelope = $this->submitWithFiles($this->loginAsQaUser(), $page, $form, ['title' => 'Fits'], ['doc' => [$this->upload('a.txt', "a\n"), $this->upload('b.txt', "b\n")]]);
        $this->assertRefusedWith($envelope, $keys['doc'], 'too_many');
        self::assertSame([], $this->filesIn($directory));

        self::assertSame($before, $this->footprint($tableId, $watchers));

        $accepted = $this->submitWithFiles($this->loginAsQaUser(), $page, $form, ['title' => 'Fits'], ['doc' => [$this->upload('note.txt', "fine\n")]]);
        $this->assertEnvelopeSuccess($accepted);
        self::assertCount(1, $this->filesIn($directory));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: int}>
     */
    public static function versionsThatStopAnEdit(): iterable
    {
        yield 'no version' => [null, 400];
        yield 'a malformed version' => ['1.0', 400];
        yield 'a version that is stale' => ['1', 409];
    }

    #[DataProvider('versionsThatStopAnEdit')]
    public function testAnUploadIsNeverStoredForAnEditThatItsVersionRefuses(?string $version, int $status): void
    {
        [$page, , $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_upload_version', [
            'doc' => ['style' => 'file-input'],
        ]);
        $token = $this->loginAsQaUser();
        $recordId = $this->record($tableId, $keys, ['title' => 'Kept']);
        $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, ['title' => 'Kept again'], 1)); // the record is at version 2
        $before = $this->footprint($tableId, $watchers);
        $directory = $this->uploadsOf((int) $form->getId());

        $fields = [
            'page_id' => (string) $page->getId(),
            'section_id' => (string) $form->getId(),
            'update_based_on' => json_encode(['record_id' => $recordId], JSON_THROW_ON_ERROR),
            'title' => 'Changed',
        ] + ($version === null ? [] : ['expected_version' => $version]);
        $this->client->request(
            'PUT',
            '/cms-api/v1/forms/update',
            $fields,
            ['doc' => $this->upload('note.txt', "a file\n")],
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $token],
        );
        $envelope = $this->decode($this->client->getResponse());

        self::assertSame($status, $envelope['status'] ?? null, json_encode($envelope, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->filesIn($directory), 'The version is checked before an upload is stored.');
        self::assertSame($before, $this->footprint($tableId, $watchers));
        self::assertSame('Kept again', $this->cell($recordId, $keys['title']));
    }

    public function testARequiredFileCannotBeLeftOut(): void
    {
        [$page, , $tableId, $form, $keys, $watchers] = $this->world('qa_lwv_file_required', [
            'doc' => ['style' => 'file-input', 'fields' => ['is_required' => '1']],
        ]);
        $before = $this->footprint($tableId, $watchers);

        $envelope = $this->submit($this->loginAsQaUser(), $page, $form, ['title' => 'No file']);

        $this->assertRefusedWith($envelope, $keys['doc'], 'required');
        self::assertSame($before, $this->footprint($tableId, $watchers));
    }

    // -- fixtures ----------------------------------------------------------------

    /**
     * A participant list whose form has the inputs above (plus `$more`), a hidden `secret` input and two real actions
     * on its table (one for a new entry, one for a change) that mail the acting user: a side effect the tests can count.
     *
     * @param array<string, array{style?: string, fields?: array<string, string>, type?: string}> $more
     * @param array<string, string> $fields list fields that replace the defaults of this fixture
     * @return array{0: Page, 1: Section, 2: int, 3: Section, 4: array<string, string>, 5: list<Action>}
     */
    private function world(string $prefix, array $more = [], array $fields = []): array
    {
        [$page, $list, $tableId, $form, $keys] = $this->declaredList($prefix, self::INPUTS + $more, $fields);

        $secret = $this->em->getRepository(Section::class)->findOneBy(['name' => $prefix . '_in_secret']);
        self::assertInstanceOf(Section::class, $secret);
        $secret->setCondition(self::HIDDEN);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'qa.user@selfhelp.test']);
        self::assertInstanceOf(User::class, $user);
        $user->setReceivesEmails(true);
        $this->em->flush();
        $this->pages->invalidatePageScopedCaches();

        $table = $this->em->find(DataTable::class, $tableId);
        self::assertInstanceOf(DataTable::class, $table);
        $actions = new ActionFactory($this->em);
        $watchers = [
            $actions->createAction($table, LookupService::ACTION_TRIGGER_TYPES_FINISHED, ActionFactory::immediateEmailConfig('A new entry', 'An entry was added.'), $prefix . '_on_new'),
            $actions->createAction($table, LookupService::ACTION_TRIGGER_TYPES_UPDATED, ActionFactory::immediateEmailConfig('A changed entry', 'An entry was changed.'), $prefix . '_on_change'),
        ];

        return [$page, $list, $tableId, $form, $keys, $watchers];
    }

    /**
     * A stored entry of the current user, written the way the grid fixtures do.
     *
     * @param array<string, string> $keys input name => column key
     * @param array<string, string> $values input name => value
     */
    private function record(int $tableId, array $keys, array $values): int
    {
        $cells = [];
        foreach ($values as $name => $value) {
            $cells[$keys[$name]] = $value;
        }

        return $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), $cells);
    }

    private function setField(string $key, string $field, string $content): void
    {
        $sectionId = (int) substr($key, strlen('section_'));
        $section = $this->em->find(Section::class, $sectionId);
        self::assertInstanceOf(Section::class, $section);
        $this->pages->setSectionField($section, $field, $content);
        $this->pages->invalidatePageScopedCaches();
    }

    // -- requests ----------------------------------------------------------------

    /**
     * @param array<string, string> $formData
     * @return array<string, mixed>
     */
    private function submit(?string $token, Page $page, Section $form, array $formData): array
    {
        return $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => $formData,
        ], $token);
    }

    /**
     * @param array<string, string> $formData
     * @return array<string, mixed>
     */
    private function update(?string $token, Page $page, Section $form, int $recordId, array $formData, int $version): array
    {
        return $this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => $formData,
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => $version,
        ], $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function act(?string $token, Page $page, Section $list, int $recordId, string $action, int $version): array
    {
        return $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/actions/' . $action,
            ['page_id' => (int) $page->getId(), 'expected_version' => $version],
            $token,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function restore(?string $token, Page $page, Section $list, int $recordId, int $version, int $expected): array
    {
        return $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/restore',
            ['page_id' => (int) $page->getId(), 'version' => $version, 'expected_version' => $expected],
            $token,
        );
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, list<UploadedFile>> $files input name => files
     * @return array<string, mixed>
     */
    private function submitWithFiles(string $token, Page $page, Section $form, array $fields, array $files): array
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/forms/submit',
            ['page_id' => (int) $page->getId(), 'section_id' => (int) $form->getId()] + $fields,
            array_map(static fn (array $list): UploadedFile|array => count($list) === 1 ? $list[0] : $list, $files),
            ['HTTP_Authorization' => 'Bearer ' . $token],
        );

        return $this->decode($this->client->getResponse());
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_lwv_');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    // -- readings ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $envelope
     */
    private function assertRefusedWith(array $envelope, string $key, string $rule): void
    {
        self::assertSame(422, $envelope['status'] ?? null, json_encode($envelope, JSON_THROW_ON_ERROR));
        $errors = $this->listOf($this->at($envelope, 'data'), 'errors');
        $found = array_filter(
            $errors,
            static fn (array $error): bool => ($error['key'] ?? null) === $key && ($error['rule'] ?? null) === $rule,
        );
        self::assertNotEmpty($found, sprintf('Expected the rule "%s" of %s in %s', $rule, $key, json_encode($errors, JSON_THROW_ON_ERROR)));
    }

    /**
     * Everything a refused request must leave alone: the entries of the table with their versions, the revisions
     * and the jobs of the table's actions.
     *
     * @param list<Action> $watchers
     * @return array{rows: int, versions: string, revisions: int, jobs: int, cells: int}
     */
    private function footprint(int $tableId, array $watchers): array
    {
        $ids = array_map(static fn (Action $a): int => (int) $a->getId(), $watchers);

        return [
            'rows' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_rows WHERE id_data_tables = :t', ['t' => $tableId])),
            'versions' => implode(',', array_map(
                static fn (mixed $version): string => self::coerceString($version),
                $this->db->fetchFirstColumn('SELECT version FROM data_rows WHERE id_data_tables = :t ORDER BY id', ['t' => $tableId]),
            )),
            'revisions' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_revisions rv JOIN data_rows r ON r.id = rv.id_data_rows WHERE r.id_data_tables = :t', ['t' => $tableId])),
            'jobs' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM scheduled_jobs WHERE id_actions IN (' . implode(',', $ids) . ')')),
            'cells' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_cells c JOIN data_rows r ON r.id = c.id_data_rows WHERE r.id_data_tables = :t', ['t' => $tableId])),
        ];
    }

    private function versionOf(int $recordId): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function cell(int $recordId, string $key): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols WHERE c.id_data_rows = :row AND col.field_key = :key AND c.id_languages = 1',
            ['row' => $recordId, 'key' => $key],
        );

        return $value === false ? null : self::coerceString($value);
    }

}
