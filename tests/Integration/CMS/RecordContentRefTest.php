<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataTable;
use App\Entity\Plugin\Plugin;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\DataTableExportService;
use App\Service\CMS\DataRevision\DataRevisionService;
use App\Service\CMS\DataRevision\RecordContentRefReader;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\InteractsWithFormRecordPages;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Content reference of a record (contract C19): the content a record was written for, stored on the
 * record (latest write that named one) and on the revision (that write).
 *
 *  - **A1**, through the real form endpoints: a participant's write on a published page names
 *    `page:<id>@v<n>`, a republish changes the next write only, an editor's preview and a page that was
 *    never published name `page:<id>@draft`, a list's quick action names the version it was served from.
 *  - **A2**, through the write path a plugin takes: `withContentRef('res:qa_item:5@v2')` is stored; a
 *    malformed reference or a type no enabled plugin registered is refused before anything is written;
 *    a write without a reference never clears the record's; delete and restore carry the right one.
 *  - **A3**, the readers: the data browser, the record history (per revision) and the CSV, Excel and JSON
 *    exports show it, `null`/empty for a record written before the reference existed or by a job.
 *
 * Pinning ({@see PluginPinnedVersionTest}) and the record events ({@see PluginRecordEventsTest}) have their own tests.
 */
#[PhpUnitGroup('form-list')]
final class RecordContentRefTest extends QaWebTestCase
{
    use InteractsWithFormLists;
    use InteractsWithFormRecordPages;

    /** The one field of the form: {@see InteractsWithFormRecordPages::submitForm()} submits it. */
    private const FIELD = 'qa_field';

    private DataService $data;
    private DataRevisionService $revisions;
    private bool $pluginInstalled = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Shared container, as the form workflow golden test: the services the test reads are the ones the requests write through.
        $this->client->disableReboot();
        $this->setUpFormLists();
        $this->data = $this->service(DataService::class);
        $this->revisions = $this->service(DataRevisionService::class);
    }

    protected function tearDown(): void
    {
        if ($this->pluginInstalled) {
            // The cache pool outlives the database rollback: the next test must not find the QA plugin.
            QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        }
        parent::tearDown();
    }

    // == A1: the form endpoints ======================================================================

    public function testARecordNamesTheVersionOfThePageThePersonWasShown(): void
    {
        $admin = $this->loginAsQaAdmin();
        $user = $this->loginAsQaUser();
        [$pageId, $sectionId] = $this->createFormRecordPage($admin, 'qa_content_ref_form');

        // A page that was never published serves its draft, so that is what the person saw.
        $unpublished = $this->submitForm($user, $pageId, $sectionId, 'qa_value_unpublished');
        self::assertSame("page:{$pageId}@draft", $this->rowRef($unpublished));
        self::assertSame("page:{$pageId}@draft", $this->revisionRef($unpublished, 1));

        // Published: the participant is served version 1.
        $v1 = $this->publishFormPage($admin, $pageId);
        $second = $this->submitForm($user, $pageId, $sectionId, 'qa_value_v1');
        self::assertSame("page:{$pageId}@v{$v1}", $this->rowRef($second));
        self::assertSame("page:{$pageId}@v{$v1}", $this->revisionRef($second, 1), 'The revision of the write carries the same reference.');

        // Republished: only the next write names the new version; the record written before keeps its own.
        $v2 = $this->publishFormPage($admin, $pageId);
        self::assertSame($v1 + 1, $v2);
        $third = $this->submitForm($user, $pageId, $sectionId, 'qa_value_v2');
        self::assertSame("page:{$pageId}@v{$v2}", $this->rowRef($third));
        self::assertSame("page:{$pageId}@v{$v1}", $this->rowRef($second), 'A record keeps the version it was written for until it is written again.');

        // An editor's preview is the draft, never a published version.
        $preview = $this->submitForm($admin, $pageId, $sectionId, 'qa_value_preview', preview: true);
        self::assertSame("page:{$pageId}@draft", $this->rowRef($preview));

        // Writing the first record again after the republish: the record names the newer version, its history keeps both.
        $update = $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => $pageId,
            'section_id' => $sectionId,
            'form_data' => [self::FIELD => 'qa_value_v1_edited'],
            'update_based_on' => ['record_id' => $second],
            'expected_version' => 1,
        ], $user));
        self::assertSame($second, $update['record_id'] ?? null);
        self::assertSame("page:{$pageId}@v{$v2}", $this->rowRef($second), 'The record carries the reference of its latest write.');
        self::assertSame("page:{$pageId}@v{$v1}", $this->revisionRef($second, 1), 'The first revision still says what was shown then.');
        self::assertSame("page:{$pageId}@v{$v2}", $this->revisionRef($second, 2));

        // The data browser shows the reference of every record; a record a job wrote has none.
        $old = $this->save((string) $sectionId, [self::FIELD => 'qa_value_job']);
        $browser = $this->browserRefs($admin, $sectionId);
        self::assertSame("page:{$pageId}@draft", $browser[$unpublished] ?? 'missing');
        self::assertSame("page:{$pageId}@v{$v2}", $browser[$second] ?? 'missing');
        self::assertSame("page:{$pageId}@v{$v2}", $browser[$third] ?? 'missing');
        self::assertSame("page:{$pageId}@draft", $browser[$preview] ?? 'missing');
        self::assertArrayHasKey($old, $browser, 'The record of a job is listed.');
        self::assertNull($browser[$old], 'A record without a reference shows null, never a guess.');

        // The record history shows the reference of every revision, newest first.
        $recordUrl = sprintf('/cms-api/v1/admin/data/tables/%d/records/%d', $sectionId, $second);
        $history = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $recordUrl . '/history', null, $admin));
        self::assertSame(
            ["page:{$pageId}@v{$v2}", "page:{$pageId}@v{$v1}"],
            array_map(static fn (mixed $item): mixed => self::asArray($item)['content_ref'] ?? 'missing', self::asList($history['items'] ?? null)),
        );
        $revision = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $recordUrl . '/versions/1', null, $admin));
        self::assertSame("page:{$pageId}@v{$v1}", self::jsonGet($revision, 'revision', 'revision', 'content_ref'));

        // A restore brings back the content version of the state it restores, not the one of the person who restores.
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', $recordUrl . '/restore', ['version' => 1, 'expected_version' => 2, 'dry_run' => false], $admin));
        self::assertSame(3, $this->versionOf($second));
        self::assertSame("page:{$pageId}@v{$v1}", $this->revisionRef($second, 3));
        self::assertSame("page:{$pageId}@v{$v1}", $this->rowRef($second));
    }

    public function testAQuickActionNamesTheVersionOfThePageTheListWasServedFrom(): void
    {
        $quickActions = json_encode([[
            'id' => 'done',
            'label' => 'Mark done',
            'set' => ['status' => 'done'],
            'when' => ['mode' => 'and', 'children' => [['column' => 'status', 'operator' => 'eq', 'value' => 'open']]],
        ]], JSON_THROW_ON_ERROR);
        [$page, $list, $tableId] = $this->formList('qa_cref_quick', ['quick_actions' => $quickActions]);
        $pageId = (int) $page->getId();
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $first = $this->grid->row($tableId, $userId, ['title' => 'First', 'status' => 'open']);
        $second = $this->grid->row($tableId, $userId, ['title' => 'Second', 'status' => 'open']);
        $user = $this->loginAsQaUser();

        // Never published: the list is served from the draft. The record predates the reference: its baseline names none.
        $this->assertEnvelopeSuccess($this->quickAction($user, $pageId, (int) $list->getId(), $first));
        self::assertSame("page:{$pageId}@draft", $this->rowRef($first));
        self::assertSame("page:{$pageId}@draft", $this->revisionRef($first, 2));
        self::assertNull($this->revisionRef($first, 1), 'The baseline of a record from before the reference names none.');

        $version = $this->publishFormPage($this->loginAsQaAdmin(), $pageId);
        $this->assertEnvelopeSuccess($this->quickAction($user, $pageId, (int) $list->getId(), $second));
        self::assertSame("page:{$pageId}@v{$version}", $this->rowRef($second));
        self::assertSame("page:{$pageId}@v{$version}", $this->revisionRef($second, 2));
        self::assertSame("page:{$pageId}@draft", $this->rowRef($first), 'The record written before the publish keeps the draft reference.');
    }

    // == A2: the write path of a plugin ==============================================================

    public function testAPluginWriteStoresItsReferenceOnTheRowAndTheRevision(): void
    {
        $this->installPlugin();

        $recordId = $this->save('qa_cref_plugin', ['title' => 'A'], $this->pluginContext('res:qa_item:5@v2'));

        self::assertSame('res:qa_item:5@v2', $this->rowRef($recordId));
        self::assertSame('res:qa_item:5@v2', $this->revisionRef($recordId, 1));
        self::assertSame(1, $this->versionOf($recordId));
    }

    public function testAWriteWithoutAReferenceNeverClearsTheOneTheRecordHas(): void
    {
        $this->installPlugin();
        $recordId = $this->save('qa_cref_keep', ['title' => 'A'], $this->pluginContext('res:qa_item:5@v2'));

        // A job (or an import) writes the record again and names no content.
        $this->save('qa_cref_keep', ['title' => 'B'], null, $recordId);

        self::assertSame(2, $this->versionOf($recordId));
        self::assertSame('res:qa_item:5@v2', $this->rowRef($recordId), 'The record keeps the content version it was begun on.');
        self::assertSame('res:qa_item:5@v2', $this->revisionRef($recordId, 1));
        self::assertNull($this->revisionRef($recordId, 2), 'The revision of the job names none: it is what that write said.');
    }

    public function testALaterReferenceReplacesTheRowsAndLeavesTheRevisionsAlone(): void
    {
        $this->installPlugin();
        $recordId = $this->save('qa_cref_later', ['title' => 'A'], $this->pluginContext('res:qa_item:5@v2'));

        $this->save('qa_cref_later', ['title' => 'B'], $this->pluginContext('res:qa_item:5@v3'), $recordId);

        self::assertSame('res:qa_item:5@v3', $this->rowRef($recordId));
        self::assertSame('res:qa_item:5@v2', $this->revisionRef($recordId, 1));
        self::assertSame('res:qa_item:5@v3', $this->revisionRef($recordId, 2));
    }

    public function testAWriteThatChangesNothingStillMovesTheRowsReferenceAndCreatesNoRevision(): void
    {
        $this->installPlugin();
        $recordId = $this->save('qa_cref_same', ['title' => 'Same'], $this->pluginContext('res:qa_item:5@v2'));

        $this->save('qa_cref_same', ['title' => 'Same'], $this->pluginContext('res:qa_item:5@v3'), $recordId);

        self::assertSame(1, $this->versionOf($recordId), 'Nothing changed: no new version.');
        self::assertSame(1, $this->revisionCount($recordId));
        self::assertSame('res:qa_item:5@v3', $this->rowRef($recordId), 'The record says which content its latest write was made for.');
        self::assertSame('res:qa_item:5@v2', $this->revisionRef($recordId, 1));
    }

    public function testADeleteCarriesTheReferenceOfTheContentThatCausedIt(): void
    {
        $this->installPlugin();
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_cref_delete', ['title' => 'D'], $this->pluginContext('res:qa_item:5@v1'));
        $tableId = $this->tableIdByName('qa_cref_delete');

        $this->data->deleteData($tableId, $recordId, false, (new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1))->withContentRef('res:qa_item:5@v2'));

        self::assertSame('res:qa_item:5@v2', $this->rowRef($recordId));
        self::assertSame('res:qa_item:5@v1', $this->revisionRef($recordId, 1));
        self::assertSame('res:qa_item:5@v2', $this->revisionRef($recordId, 2), 'The delete revision names the content of the delete.');
    }

    public function testARestoreBringsBackTheReferenceOfTheStateItRestores(): void
    {
        $this->installPlugin();
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_cref_restore', ['title' => 'T1'], $this->pluginContext('res:qa_item:5@v1'));
        $this->save('qa_cref_restore', ['title' => 'T2'], $this->pluginContext('res:qa_item:5@v2'), $recordId);
        $tableId = $this->tableIdByName('qa_cref_restore');

        // The context of the caller names another reference: the restore takes the one of the revision it brings back.
        $context = (new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2))->withContentRef('res:qa_item:5@v9');
        $version = $this->data->restoreRecord($tableId, $recordId, 1, null, false, $context);

        self::assertSame(3, $version);
        self::assertSame('res:qa_item:5@v1', $this->revisionRef($recordId, 3));
        self::assertSame('res:qa_item:5@v1', $this->rowRef($recordId));
        self::assertSame(1, $this->restoredFromVersion($recordId, 3));
        self::assertSame('res:qa_item:5@v2', $this->revisionRef($recordId, 2), 'The history is appended to, never rewritten.');
    }

    public function testARestoreOfARevisionWithoutAReferenceWritesNoneAndKeepsTheRowsOne(): void
    {
        $this->installPlugin();
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_cref_restore_none', ['title' => 'N1']);
        $this->save('qa_cref_restore_none', ['title' => 'N2'], $this->pluginContext('res:qa_item:5@v2'), $recordId);
        $tableId = $this->tableIdByName('qa_cref_restore_none');

        $this->data->restoreRecord($tableId, $recordId, 1, null, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2));

        self::assertNull($this->revisionRef($recordId, 3), 'The state it restores was written without a reference.');
        self::assertSame('res:qa_item:5@v2', $this->rowRef($recordId), 'A write without a reference never clears the record\'s.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedReferences(): array
    {
        return [
            'empty' => [''],
            'a page without its version' => ['page:7'],
            'an item without its version' => ['res:qa_item:5'],
            'an item of a draft' => ['res:qa_item:5@draft'],
            'a type in capitals' => ['res:QA_ITEM:5@v2'],
            'a type of one character' => ['res:q:5@v2'],
            'a trailing line break' => ["page:7@draft\n"],
            'a path instead of a reference' => ['../../etc/passwd'],
        ];
    }

    #[DataProvider('malformedReferences')]
    public function testAMalformedReferenceIsRefusedBeforeAnythingIsWritten(string $ref): void
    {
        $before = $this->storeCounts();

        try {
            $this->save('qa_cref_malformed', ['title' => 'never'], (new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB))->withContentRef($ref));
            self::fail('A malformed reference must be refused.');
        } catch (\InvalidArgumentException) {
            // refused when the context is built, before any save starts
        }

        self::assertSame($before, $this->storeCounts());
        self::assertSame(0, $this->intOf('SELECT COUNT(*) FROM data_tables WHERE name = :n', ['n' => 'qa_cref_malformed']));
    }

    public function testAReferenceThatBypassedTheBuilderIsStillRefusedBeforeAnythingIsWritten(): void
    {
        $before = $this->storeCounts();

        // The constructor is public: the save does not trust a context it did not see built.
        $forged = new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB, contentRef: 'not a reference');

        try {
            $this->save('qa_cref_forged', ['title' => 'never'], $forged);
            self::fail('A malformed reference must be refused by the save.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not a reference', $e->getMessage());
        }

        self::assertSame($before, $this->storeCounts());
        self::assertSame(0, $this->intOf('SELECT COUNT(*) FROM data_tables WHERE name = :n', ['n' => 'qa_cref_forged']));
    }

    public function testATypeNoEnabledPluginRegisteredIsRefusedBeforeAnythingIsWritten(): void
    {
        // No plugin is installed: `qa_item` is not a registered resource type.
        $before = $this->storeCounts();

        try {
            $this->save('qa_cref_unregistered', ['title' => 'never'], $this->pluginContext('res:qa_item:5@v2'));
            self::fail('A type nobody registered must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('qa_item', $e->getMessage());
        }

        self::assertSame($before, $this->storeCounts());
        self::assertSame(0, $this->intOf('SELECT COUNT(*) FROM data_tables WHERE name = :n', ['n' => 'qa_cref_unregistered']));
    }

    public function testATypeOfADisabledPluginIsRefusedToo(): void
    {
        $plugin = $this->installPlugin();
        $plugin->setEnabled(false);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
        $before = $this->storeCounts();

        try {
            $this->save('qa_cref_disabled', ['title' => 'never'], $this->pluginContext('res:qa_item:5@v2'));
            self::fail('The type of a disabled plugin must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('qa_item', $e->getMessage());
        }

        self::assertSame($before, $this->storeCounts());
    }

    public function testAPageReferenceNeedsNoRegisteredType(): void
    {
        $recordId = $this->save('qa_cref_page', ['title' => 'P'], (new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB))->withContentRef('page:7@v3'));

        self::assertSame('page:7@v3', $this->rowRef($recordId));
    }

    // == A3: the readers =============================================================================

    public function testTheHistoryShowsTheReferenceOfEveryRevision(): void
    {
        $this->installPlugin();
        $recordId = $this->save('qa_cref_history', ['title' => 'H1'], $this->pluginContext('res:qa_item:5@v1'));
        $this->save('qa_cref_history', ['title' => 'H2'], null, $recordId);
        $this->save('qa_cref_history', ['title' => 'H3'], $this->pluginContext('res:qa_item:6@v4'), $recordId);

        $history = $this->revisions->history($recordId);

        self::assertSame(
            ['res:qa_item:6@v4', null, 'res:qa_item:5@v1'],
            array_column($history['items'], 'content_ref'),
            'Newest first; the revision of a job names none.',
        );
        self::assertSame('res:qa_item:6@v4', $this->revisions->revisionHeader($recordId, 3)['content_ref']);
        self::assertNull($this->revisions->revisionHeader($recordId, 2)['content_ref']);
        self::assertSame('res:qa_item:5@v1', $this->revisions->contentRefOf($recordId, 1));
        self::assertNull($this->revisions->contentRefOf($recordId, 2));
        self::assertNull($this->revisions->contentRefOf($recordId, 99), 'No such revision: no reference.');
    }

    public function testTheCsvTheExcelAndTheJsonExportShowTheReferenceAndOldRecordsStayEmpty(): void
    {
        $this->installPlugin();
        $tableId = $this->grid->table('qa_cref_export', ['title' => 'text']);
        $legacy = $this->grid->row($tableId, null, ['title' => 'Legacy']);
        $fresh = $this->save('qa_cref_export', ['title' => 'Fresh'], $this->pluginContext('res:qa_item:5@v2'));
        $table = $this->em->getRepository(DataTable::class)->find($tableId);
        self::assertInstanceOf(DataTable::class, $table);
        $exports = $this->service(DataTableExportService::class);
        $batches = fn (): \Generator => $exports->batches($table, $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL), true, null, true, 1, 1);

        // CSV: the column exists as soon as one row has a reference; the old record has an empty cell.
        $csv = $this->csvRows($exports->csv($batches()));
        self::assertContains(DataTableExportService::CONTENT_REF_COLUMN, $csv['header']);
        self::assertEquals(
            [$legacy => '', $fresh => 'res:qa_item:5@v2'],
            array_map(static fn (array $row): string => $row[DataTableExportService::CONTENT_REF_COLUMN], $csv['rows']),
        );

        // JSON: the old record has no such key at all.
        $json = json_decode($exports->json($batches()), true, 512, JSON_THROW_ON_ERROR);
        $byRecord = [];
        foreach (self::asList($json) as $row) {
            $row = self::asArray($row);
            $byRecord[self::coerceInt($row['record_id'] ?? null)] = $row;
        }
        self::assertSame('res:qa_item:5@v2', $byRecord[$fresh][DataTableExportService::CONTENT_REF_COLUMN] ?? null);
        self::assertArrayNotHasKey(DataTableExportService::CONTENT_REF_COLUMN, $byRecord[$legacy]);

        // Excel: the same header and rows, in one sheet.
        $sheet = $this->xlsxSheet($exports->xlsx($batches()));
        self::assertStringContainsString('<t xml:space="preserve">' . DataTableExportService::CONTENT_REF_COLUMN . '</t>', $sheet);
        self::assertStringContainsString('<t xml:space="preserve">res:qa_item:5@v2</t>', $sheet);
        self::assertSame(1, substr_count($sheet, 'res:qa_item:5@v2'), 'Only the record that has a reference shows one.');
    }

    public function testATableOfOldRecordsIsExportedExactlyAsBefore(): void
    {
        $tableId = $this->grid->table('qa_cref_export_old', ['title' => 'text']);
        $this->grid->row($tableId, null, ['title' => 'One']);
        $this->grid->row($tableId, null, ['title' => 'Two']);
        $table = $this->em->getRepository(DataTable::class)->find($tableId);
        self::assertInstanceOf(DataTable::class, $table);
        $exports = $this->service(DataTableExportService::class);
        $admin = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);

        $csv = $this->csvRows($exports->csv($exports->batches($table, $admin, true, null, true, 1)));
        self::assertNotContains(DataTableExportService::CONTENT_REF_COLUMN, $csv['header']);

        $json = $exports->json($exports->batches($table, $admin, true, null, true, 1));
        self::assertStringNotContainsString(DataTableExportService::CONTENT_REF_COLUMN, $json);
        self::assertStringNotContainsString('content_ref', $this->xlsxSheet($exports->xlsx($exports->batches($table, $admin, true, null, true, 1))));
    }

    public function testTheReaderLooksUpManyRecordsInChunksAndLeavesStoredColumnsAlone(): void
    {
        $this->installPlugin();
        $withRef = $this->save('qa_cref_reader', ['title' => 'A'], $this->pluginContext('res:qa_item:5@v1'));
        $without = $this->save('qa_cref_reader', ['title' => 'B']);
        $reader = new RecordContentRefReader($this->em);

        self::assertSame([$withRef => 'res:qa_item:5@v1'], $reader->refsOf([$withRef, $without, $withRef, 0, -4]));
        self::assertSame([], $reader->refsOf([]));

        // More ids than one query takes: the record that has a reference is in the second chunk.
        $ids = range(900_000_000, 900_000_999);
        $ids[] = $withRef;
        self::assertSame([$withRef => 'res:qa_item:5@v1'], $reader->refsOf($ids));

        $rows = [
            ['record_id' => $withRef, 'title' => 'A'],
            ['record_id' => (string) $without],
            ['title' => 'a row that names no record'],
            ['record_id' => $withRef, 'content_ref' => 'a data column of that name'],
        ];
        $shown = $reader->attach($rows, 'content_ref', true);
        self::assertSame('res:qa_item:5@v1', $shown[0]['content_ref']);
        self::assertNull($shown[1]['content_ref']);
        self::assertNull($shown[2]['content_ref']);
        self::assertSame('a data column of that name', $shown[3]['content_ref'], 'Stored data is never overwritten.');

        $sparse = $reader->attach($rows, 'content_ref', false);
        self::assertSame('res:qa_item:5@v1', $sparse[0]['content_ref']);
        self::assertArrayNotHasKey('content_ref', $sparse[1]);
        self::assertArrayNotHasKey('content_ref', $sparse[2]);
    }

    // -- fixtures and requests ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function quickAction(string $token, int $pageId, int $listId, int $recordId): array
    {
        return $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/lists/%d/records/%d/actions/done', $listId, $recordId),
            ['page_id' => $pageId, 'expected_version' => 1],
            $token,
        );
    }

    /**
     * The rows the data browser shows for a table: record id => `content_ref`.
     *
     * @return array<int, string|null>
     */
    private function browserRefs(string $admin, int $tableName): array
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/data?table_name=%d&exclude_deleted=true', $tableName),
            null,
            $admin,
        ));

        $refs = [];
        foreach (self::asList($data['rows'] ?? null) as $row) {
            $row = self::asArray($row);
            self::assertArrayHasKey('content_ref', $row, 'Every row of the data browser has the field.');
            $ref = $row['content_ref'];
            self::assertTrue($ref === null || is_string($ref));
            $refs[self::coerceInt($row['record_id'] ?? null)] = $ref;
        }

        return $refs;
    }

    /**
     * A write through the path of a trusted writer: a job when no context is given, else the context given.
     *
     * @param array<string, mixed> $data
     */
    private function save(string $table, array $data, ?RecordWriteContext $context = null, ?int $recordId = null): int
    {
        $saved = $this->data->saveData(
            $table,
            $data + ['trigger_type' => LookupService::ACTION_TRIGGER_TYPES_FINISHED],
            LookupService::TRANSACTION_BY_BY_SYSTEM,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
            null,
            $context ?? new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB),
        );
        self::assertIsInt($saved);

        return $saved;
    }

    /** The context a plugin builds for a write of its own: the platform's door and the reference of the item version. */
    private function pluginContext(string $ref): RecordWriteContext
    {
        return RecordWriteContext::forTransactionBy(LookupService::TRANSACTION_BY_BY_SYSTEM)->withContentRef($ref);
    }

    /** The enabled QA plugin that registers the resource type `qa_item`. */
    private function installPlugin(): Plugin
    {
        $this->pluginInstalled = true;

        return QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
    }

    /** Sign the test in as a QA user, so that the history and the audit know who wrote. */
    private function actAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        // The user context remembers the first user it resolved; forget it.
        $context = $this->service(UserContextService::class);
        $reflection = new \ReflectionClass($context);
        foreach (['userResolved' => false, 'cachedUser' => null] as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($context, $value);
            }
        }
    }

    // -- readings ---------------------------------------------------------------------------------

    private function rowRef(int $recordId): ?string
    {
        $ref = $this->db->fetchOne('SELECT content_ref FROM data_rows WHERE id = :id', ['id' => $recordId]);

        return is_string($ref) ? $ref : null;
    }

    private function revisionRef(int $recordId, int $version): ?string
    {
        $ref = $this->db->fetchOne(
            'SELECT content_ref FROM data_revisions WHERE id_data_rows = :id AND version = :version',
            ['id' => $recordId, 'version' => $version],
        );

        return is_string($ref) ? $ref : null;
    }

    private function restoredFromVersion(int $recordId, int $version): ?int
    {
        $from = $this->db->fetchOne(
            'SELECT restored_from_version FROM data_revisions WHERE id_data_rows = :id AND version = :version',
            ['id' => $recordId, 'version' => $version],
        );

        return $from === null || $from === false ? null : self::coerceInt($from);
    }

    private function versionOf(int $recordId): int
    {
        return $this->intOf('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]);
    }

    private function revisionCount(int $recordId): int
    {
        return $this->intOf('SELECT COUNT(*) FROM data_revisions WHERE id_data_rows = :id', ['id' => $recordId]);
    }

    /**
     * What a write leaves behind, to prove that a refused one left nothing.
     *
     * @return array<string, int>
     */
    private function storeCounts(): array
    {
        $counts = [];
        foreach (['data_tables', 'data_rows', 'data_cols', 'data_cells', 'data_revisions', 'transactions'] as $table) {
            $counts[$table] = $this->intOf('SELECT COUNT(*) FROM ' . $table);
        }

        return $counts;
    }

    /** @param array<string, scalar> $params */
    private function intOf(string $sql, array $params = []): int
    {
        return self::coerceInt($this->db->fetchOne($sql, $params));
    }

    /**
     * A CSV as the header and the rows keyed by their record id.
     *
     * @return array{header: list<string>, rows: array<int, array<string, string>>}
     */
    private function csvRows(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", $csv), static fn (string $line): bool => $line !== ''));
        self::assertNotSame([], $lines, 'The export has a header.');
        $header = array_map('strval', str_getcsv((string) array_shift($lines), escape: ''));

        $rows = [];
        foreach ($lines as $line) {
            $cells = array_map('strval', str_getcsv($line, escape: ''));
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = $cells[$index] ?? '';
            }
            $rows[self::coerceInt($row['record_id'] ?? null)] = $row;
        }

        return ['header' => $header, 'rows' => $rows];
    }

    private function xlsxSheet(string $file): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_xlsx_');
        file_put_contents($path, $file);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path) === true, 'the export is a readable zip');
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
        } finally {
            unlink($path);
        }

        return $sheet;
    }
}
