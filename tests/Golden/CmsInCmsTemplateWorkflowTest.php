<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\Page;
use App\Entity\Section;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Auth\UserContextService;
use App\Tests\Support\ExampleBundleTestPaths;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The six templates of the gallery, installed the way a manager installs them and
 * then worked like the app they are: the manager adds, edits and deletes records
 * in the CMS list, the visitor reads the same dataset on a public page that has
 * no way to write, and each template does what its domain needs (events are
 * cancelled and reopened, testimonials are moderated before they are public, a
 * team member has a portrait that visitors can see, labels follow the language).
 *
 * Everything goes through the real endpoints; one logical dataset serves both
 * surfaces, so nothing is written twice.
 */
#[PhpUnitGroup('golden')]
#[PhpUnitGroup('form-list')]
final class CmsInCmsTemplateWorkflowTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const KEYWORD_PREFIX = 'qa_tw_';
    private const ROUTE_PREFIX = '/qa-tw';
    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const UPDATE = '/cms-api/v1/forms/update';
    private const DELETE = '/cms-api/v1/forms/delete';

    /** A one pixel PNG, what a team member uploads as the portrait. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * What a manager types for a new record of each template; `shown` is the text a
     * reader finds on the public page, `renamed` what the manager changes it to.
     * Optional `publish` is the quick-action id that turns a draft into a public record.
     *
     * @var array<string, array{values: array<string, string>, shown: string, rename: string, renamed: string, public: bool, publish?: string}>
     */
    private const RECORDS = [
        // News and team start as draft: absent from the public page until Publish.
        'news' => [
            'values' => ['title' => 'QA release notes', 'category' => 'Feature', 'tags' => 'platform', 'published_on' => '2026-11-01', 'summary' => 'QA summary text', 'body' => 'QA body text', 'status' => 'draft'],
            'shown' => 'QA release notes', 'rename' => 'title', 'renamed' => 'QA release notes v2', 'public' => false, 'publish' => 'publish',
        ],
        'events' => [
            'values' => ['title' => 'QA workshop', 'event_date' => '2026-12-01 10:00', 'location' => 'QA hall', 'description' => 'QA description', 'status' => 'Scheduled'],
            'shown' => 'QA workshop', 'rename' => 'title', 'renamed' => 'QA workshop v2', 'public' => true,
        ],
        'faq' => [
            'values' => ['category' => 'Privacy', 'question' => 'QA question about privacy?', 'answer' => 'QA answer text.'],
            'shown' => 'QA question about privacy?', 'rename' => 'question', 'renamed' => 'QA question again?', 'public' => true,
        ],
        'team-members' => [
            'values' => ['name' => 'QA Member', 'role' => 'Researcher', 'email' => 'qa.member@selfhelp.test', 'bio' => 'QA biography text.', 'status' => 'draft'],
            'shown' => 'QA Member', 'rename' => 'name', 'renamed' => 'QA Member Two', 'public' => false, 'publish' => 'publish',
        ],
        'contact-directory' => [
            'values' => ['name' => 'QA Contact', 'department' => 'QA department', 'phone' => '+41 00 000 00 00', 'email' => 'qa.contact@selfhelp.test'],
            'shown' => 'QA Contact', 'rename' => 'name', 'renamed' => 'QA Contact Two', 'public' => true,
        ],
        // A new testimonial waits for review: it is not on the public page until a manager approves it.
        'testimonials' => [
            'values' => ['author' => 'QA Author', 'role' => 'QA role', 'quote' => 'QA quote for the wall.', 'status' => 'Pending'],
            'shown' => 'QA Author', 'rename' => 'author', 'renamed' => 'QA Author Two', 'public' => false,
        ],
    ];

    private string $formFilesRoot = '';

    /** @var list<int> */
    private array $formIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
        // The language of the account decides how a plain text is stored for a translatable input and what a
        // condition on the language reads, ahead of the language asked for. The tests ask for both languages,
        // so they start with an account that has none of its own, whatever an earlier run or a person set on
        // the shared QA account.
        $this->withoutAccountLanguage('qa.admin@selfhelp.test');
        $files = self::getContainer()->get(FormFileUploadService::class);
        self::assertInstanceOf(FormFileUploadService::class, $files);
        $this->formFilesRoot = $files->storageRoot();
    }

    protected function tearDown(): void
    {
        foreach ($this->formIds as $formId) {
            foreach (glob($this->formFilesRoot . '/user_*/section_' . $formId) ?: [] as $dir) {
                $this->removeDir($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templates(): array
    {
        $cases = [];
        foreach (array_keys(ExampleBundleTestPaths::cmsInCmsBundles()) as $id) {
            $cases[$id] = [$id];
        }

        return $cases;
    }

    // -- the manager works, the visitor reads ----------------------------------

    #[DataProvider('templates')]
    public function testTheManagerAddsEditsAndDeletesRecordsAndThePublicPageFollows(string $id): void
    {
        $app = $this->install($id);
        $admin = $this->loginAsQaAdmin();
        $record = self::RECORDS[$id];

        $state = $this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin));
        self::assertTrue(self::asArray($state['capabilities'] ?? [])['create'] ?? false, $id . ': the manager may add records.');
        self::assertSame($app['rows'], $this->total($state), $id . ': the manager sees every sample record.');
        $first = $this->listOf($state, 'rows')[0];
        self::assertTrue($first['can_update'] && $first['can_delete'] && $first['can_view_history'], $id . ': the manager may edit, delete and read the history.');
        self::assertFalse($this->publicShows($app, $record['shown']), $id . ': the new record is not there yet.');

        // Add.
        $created = $this->assertEnvelopeSuccess($this->submit($admin, $app['cms'], $app['form'], $record['values']));
        $recordId = self::coerceInt($created['record_id'] ?? null);
        self::assertSame(1, $created['version'] ?? null);
        self::assertSame($app['rows'] + 1, $this->total($this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin))));
        self::assertSame($record['public'], $this->publicShows($app, $record['shown']), $id . ': the public page shows the new record' . ($record['public'] ? '' : ' only after it is approved') . '.');

        $version = 1;
        $publishAction = $record['publish'] ?? null;
        if (is_string($publishAction)) {
            self::assertFalse($this->publicShows($app, $record['shown']), $id . ': a draft is absent from the public page.');
            $published = $this->assertEnvelopeSuccess($this->action($admin, $app['cms'], $app['list'], $recordId, $publishAction, $version));
            $version = self::coerceInt($published['version'] ?? ($version + 1));
            self::assertTrue($this->publicShows($app, $record['shown']), $id . ': after Publish the public page shows the record.');
        }

        // Edit; a save based on an old version is refused.
        $changed = $record['values'];
        $changed[$record['rename']] = $record['renamed'];
        if (isset($changed['status'], $record['publish'])) {
            $changed['status'] = 'published';
        }
        $updated = $this->assertEnvelopeSuccess($this->update($admin, $app['cms'], $app['form'], $recordId, $changed, $version));
        self::assertArrayHasKey('version', $updated);
        self::assertSame($version + 1, $updated['version']);
        $afterEditVersion = self::coerceInt($updated['version']);
        // After Publish the public page follows; a still-pending testimonial stays hidden.
        $visibleAfterEdit = $publishAction ? true : $record['public'];
        self::assertSame($visibleAfterEdit, $this->publicShows($app, $record['renamed']), $id . ': the public page follows the edit.');
        $stale = $this->update($admin, $app['cms'], $app['form'], $recordId, $record['values'], 1);
        self::assertSame(409, $stale['status'] ?? null, $id . ': a stale save is refused.');

        // The history keeps create (+ publish when used) + edit.
        $history = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $this->listUrl($app['list'], '/records/' . $recordId . '/history', $app['cms']), null, $admin));
        self::assertSame($afterEditVersion, $history['total'] ?? null);

        // A visitor who is not a manager may not write through the CMS page: a guest is asked to sign in, and a participant
        // who cannot see the page does not even learn that it exists (the same answer as for reading it).
        foreach (['guest' => null, 'participant' => $this->loginAsQaUser()] as $who => $token) {
            $refused = $this->submit($token, $app['cms'], $app['form'], $record['values']);
            self::assertContains($refused['status'] ?? 0, [401, 403, 404], sprintf('%s: a %s cannot add through the CMS list.', $id, $who));
            $refused = $this->delete($token, $app['cms'], $app['list'], $recordId, $afterEditVersion);
            self::assertContains($refused['status'] ?? 0, [401, 403, 404], sprintf('%s: a %s cannot delete through the CMS list.', $id, $who));
            self::assertSame($afterEditVersion, $this->versionOf($recordId), sprintf('%s: nothing changed.', $id));
        }
        self::assertContains($this->state($app['cms'], $app['list'], '', $this->loginAsQaUser())['status'] ?? 0, [403, 404], $id . ': the CMS list is for managers; a participant does not even learn that it exists.');
        if ($app['view'] instanceof Section) {
            $refused = $this->delete(null, $app['public'], $app['view'], $recordId, $afterEditVersion);
            self::assertGreaterThanOrEqual(400, $refused['status'] ?? 0, $id . ': a data view has no way to delete.');
            $refused = $this->submit(null, $app['public'], $app['view'], $record['values']);
            self::assertGreaterThanOrEqual(400, $refused['status'] ?? 0, $id . ': a data view has no way to add.');
            self::assertSame($afterEditVersion, $this->versionOf($recordId), $id . ': nothing changed.');
        }

        // Delete.
        $deleted = $this->assertEnvelopeSuccess($this->delete($admin, $app['cms'], $app['list'], $recordId, $afterEditVersion));
        self::assertSame($afterEditVersion + 1, $deleted['version'] ?? null);
        self::assertSame($app['rows'], $this->total($this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin))));
        self::assertFalse($this->publicShows($app, $record['renamed']), $id . ': the public page no longer shows the deleted record.');
    }

    // -- events ------------------------------------------------------------------

    public function testAnEventIsCancelledAndReopenedAndThePublicPageShowsTheStatus(): void
    {
        $app = $this->install('events');
        $admin = $this->loginAsQaAdmin();
        $status = $app['keys']['status'];

        $row = $this->listOf($this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin)), 'rows')[0];
        $recordId = self::coerceInt($row['record_id']);
        self::assertSame(['cancel'], $row['quick_actions'], 'A scheduled event can be cancelled, not reopened.');

        $acted = $this->assertEnvelopeSuccess($this->action($admin, $app['cms'], $app['list'], $recordId, 'cancel', self::coerceInt($row['version'])));
        self::assertSame('cancel', $acted['action'] ?? null);
        self::assertSame('Cancelled', $this->cell($recordId, $status));

        $after = $this->rowOf($this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin)), $recordId);
        self::assertSame(['reopen'], $after['quick_actions'], 'The offer follows the stored status.');
        $public = $this->rowOf($this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), '', null)), $recordId);
        self::assertSame('Cancelled', $this->at($public, 'values')[$status] ?? null, 'The visitor sees the status.');
        self::assertSame([], $public['quick_actions'] ?? [], 'A visitor is offered no action.');
        self::assertContains($this->action(null, $app['public'], $this->viewOf($app), $recordId, 'reopen', self::coerceInt($after['version']))['status'] ?? 0, [401, 403, 404], 'A visitor cannot reopen an event.');

        $again = $this->action($admin, $app['cms'], $app['list'], $recordId, 'cancel', self::coerceInt($after['version']));
        self::assertSame(409, $again['status'] ?? null, 'A cancelled event cannot be cancelled again.');

        $this->assertEnvelopeSuccess($this->action($admin, $app['cms'], $app['list'], $recordId, 'reopen', self::coerceInt($after['version'])));
        self::assertSame('Scheduled', $this->cell($recordId, $status));
    }

    // -- testimonials ------------------------------------------------------------

    public function testATestimonialIsPublicOnlyWhileItIsApproved(): void
    {
        $app = $this->install('testimonials');
        $admin = $this->loginAsQaAdmin();
        $status = $app['keys']['status'];
        $quote = $app['keys']['quote'];

        $managers = $this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin));
        $visitors = $this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), '', null));
        $pending = array_values(array_filter($this->listOf($managers, 'rows'), fn (array $row): bool => ($this->at($row, 'values')[$status] ?? null) === 'Pending'));
        self::assertNotSame([], $pending, 'The sample data has a testimonial that waits for review.');
        self::assertSame($this->total($managers) - count($pending), $this->total($visitors), 'The public page shows only what is approved.');
        $waiting = self::coerceInt($pending[0]['record_id']);
        $waitingQuote = self::coerceString($this->at($pending[0], 'values')[$quote] ?? '');
        self::assertNotSame('', $waitingQuote);
        self::assertFalse(self::has((string) json_encode($visitors, JSON_UNESCAPED_UNICODE), $waitingQuote), 'The text of a pending testimonial is not in what the visitor receives.');

        // Asking for the pending record by its address does not get it either.
        $asked = $this->state($app['public'], $this->viewOf($app), 'testimonials.record=' . $waiting . '&testimonials.mode=view', null);
        self::assertFalse(self::has((string) json_encode($asked, JSON_UNESCAPED_UNICODE), $waitingQuote), 'A visitor cannot open a testimonial that is not public.');

        // Approve, then hide.
        self::assertSame(['approve'], $pending[0]['quick_actions']);
        $this->assertEnvelopeSuccess($this->action($admin, $app['cms'], $app['list'], $waiting, 'approve', self::coerceInt($pending[0]['version'])));
        $afterApprove = $this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), '', null));
        self::assertSame($this->total($visitors) + 1, $this->total($afterApprove));
        self::assertTrue(self::has((string) json_encode($afterApprove, JSON_UNESCAPED_UNICODE), $waitingQuote), 'An approved testimonial is public.');

        $row = $this->rowOf($this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin)), $waiting);
        self::assertSame(['hide'], $row['quick_actions'], 'An approved testimonial can be hidden.');
        $this->assertEnvelopeSuccess($this->action($admin, $app['cms'], $app['list'], $waiting, 'hide', self::coerceInt($row['version'])));
        self::assertSame($this->total($visitors), $this->total($this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), '', null))));
        $hidden = $this->rowOf($this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin)), $waiting);
        self::assertSame(['approve'], $hidden['quick_actions'], 'A hidden testimonial can be approved again.');
    }

    // -- team members ------------------------------------------------------------

    public function testATeamMemberPortraitIsUploadedThroughTheListAndVisitorsCanSeeIt(): void
    {
        $this->client->disableReboot();
        $app = $this->install('team-members');
        $admin = $this->loginAsQaAdmin();

        // The bio is a translatable input: a multipart request (a file is part of it) carries the JSON text of its
        // list of languages, exactly what the web form sends.
        $values = ['bio' => (string) json_encode([
            ['language_id' => 2, 'value' => 'Entwicklerin mit Portrait.'],
            ['language_id' => 3, 'value' => 'Developer with a portrait.'],
        ])] + self::RECORDS['team-members']['values'];

        $tmp = (string) tempnam(sys_get_temp_dir(), 'qa_portrait_');
        file_put_contents($tmp, base64_decode(self::PNG, true));
        $this->client->request(
            'POST',
            self::SUBMIT,
            ['page_id' => (int) $app['cms']->getId(), 'section_id' => (int) $app['form']->getId()] + $values,
            ['portrait' => new UploadedFile($tmp, 'portrait.png', 'image/png', null, true)],
            ['HTTP_Authorization' => 'Bearer ' . $admin],
        );
        $created = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $recordId = self::coerceInt($created['record_id'] ?? null);
        $version = self::coerceInt($created['version'] ?? 1);

        // Drafts stay off the public page; Publish makes the bio and portrait visible.
        self::assertFalse(self::has($this->publicPayload($app, 2), 'Entwicklerin mit Portrait.'), 'A draft team member is absent from the public page.');
        $published = $this->assertEnvelopeSuccess($this->action($admin, $app['cms'], $app['list'], $recordId, 'publish', $version));
        $version = self::coerceInt($published['version'] ?? ($version + 1));

        // Visitors read the bio in their own language, never the list it was sent as.
        $de = $this->publicPayload($app, 2);
        $en = $this->publicPayload($app, 3);
        self::assertTrue(self::has($de, 'Entwicklerin mit Portrait.'), 'German visitors read the German bio.');
        self::assertFalse(self::has($de, 'Developer with a portrait.'));
        self::assertTrue(self::has($en, 'Developer with a portrait.'), 'English visitors read the English bio.');
        self::assertFalse(self::has($de . $en, 'language_id'), 'The JSON text of the list is never shown.');

        $row = $this->rowOf($this->assertEnvelopeSuccess($this->state($app['cms'], $app['list'], '', $admin)), $recordId);
        self::assertNotEmpty($this->at($row, 'values')[$app['keys']['portrait']] ?? null, 'The list shows that the record has a portrait.');
        self::assertSame($version, self::coerceInt($row['version'] ?? null));

        // Page viewers open the portrait through the public composition's list route (F165).
        // Team-members uses entry-list / entry-record, not a data-view.
        $portraitKey = $app['keys']['portrait'];
        $publicSectionId = $this->findSectionId((int) $app['public']->getId(), 'entry-list', $admin)
            ?? $this->findSectionId((int) $app['public']->getId(), 'entry-record', $admin)
            ?? ($app['view'] instanceof Section ? (int) $app['view']->getId() : null);
        self::assertNotNull($publicSectionId, 'Public page must expose a section that can serve the portrait.');
        $this->forgetUser();
        $this->client->request(
            'GET',
            sprintf(
                '/cms-api/v1/lists/%d/records/%d/files/%s?page_id=%d&index=0',
                $publicSectionId,
                $recordId,
                $portraitKey,
                (int) $app['public']->getId(),
            ),
        );
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        ob_start();
        $response->sendContent();
        self::assertSame(base64_decode(self::PNG, true), ob_get_clean(), 'The visitor receives the uploaded picture.');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // -- translated labels ---------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> template, label in German, label in English
     */
    public static function labelledTemplates(): array
    {
        return [
            'news' => ['news', 'Freigabe', 'Release'],
            'faq' => ['faq', 'Konto', 'Account'],
            'team-members' => ['team-members', 'Leitende Entwicklung', 'Lead Engineer'],
        ];
    }

    #[DataProvider('labelledTemplates')]
    public function testThePublicPageShowsTheLabelOfAnOptionInTheLanguageOfTheVisitor(string $id, string $german, string $english): void
    {
        $app = $this->install($id);
        $de = $this->publicPayload($app, 2);
        $en = $this->publicPayload($app, 3);

        self::assertTrue(self::has($de, $german), $id . ': German visitors read the German label.');
        self::assertFalse(self::has($en, $german), $id . ': English visitors do not.');
        self::assertTrue(self::has($en, $english), $id . ': English visitors read the English label.');
        self::assertFalse(self::has($de . $en, '{{'), $id . ': every token of the page was replaced.');
    }

    public function testTranslatableDataOfADataViewFollowsTheLanguage(): void
    {
        $app = $this->install('contact-directory');
        $department = $app['keys']['department'];

        $de = $this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), 'language_id=2', null));
        $en = $this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), 'language_id=3', null));
        self::assertContains('Studienkoordination', $this->distinct($de, $department));
        self::assertContains('Study Coordination', $this->distinct($en, $department));
    }

    // -- the record template of a data view --------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}> template, the input a card shows as its heading
     */
    public static function recordTemplates(): array
    {
        return [
            'events' => ['events', 'title'],
            'contact-directory' => ['contact-directory', 'name'],
            'testimonials' => ['testimonials', 'author'],
        ];
    }

    #[DataProvider('recordTemplates')]
    public function testADataViewDrawsOneCardPerRecordFromItsRecordTemplate(string $id, string $heading): void
    {
        $app = $this->install($id);
        $key = $app['keys'][$heading];

        foreach ([2 => 'German', 3 => 'English'] as $languageId => $language) {
            $state = $this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), 'language_id=' . $languageId, null));
            $info = $this->at($state, 'list');
            self::assertSame('custom', $info['layout'] ?? null, $id . ': the view draws the template of one record.');
            self::assertTrue($info['has_template'] ?? false, $id . ': the state says that the rows carry sections.');
            self::assertStringContainsString('grid-cols-1', self::coerceString($info['row_css'] ?? ''), $id . ': the element that holds the cards is a responsive grid.');

            $rows = $this->listOf($state, 'rows');
            self::assertNotSame([], $rows, $id . ' (' . $language . '): the page has records.');
            foreach ($rows as $row) {
                $card = (string) json_encode($row['sections'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $text = self::coerceString($this->at($row, 'values')[$key] ?? '');
                self::assertNotSame('', $text);
                self::assertTrue(self::has($card, trim((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '"')), $id . ' (' . $language . '): the card shows the record "' . $text . '".');
                self::assertFalse(self::has($card, '{{'), $id . ' (' . $language . '): every token of the card was replaced.');
            }
        }
    }

    // -- search, sort and filters ---------------------------------------------------

    public function testTheContactDirectoryIsSearchedSortedAndFilteredOnTheServer(): void
    {
        $app = $this->install('contact-directory');
        $name = $app['keys']['name'];

        $all = $this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), 'contacts.sort=' . $name . ':asc', null));
        $names = $this->distinct($all, $name);
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names, 'The server sorts by the column.');
        self::assertSame($app['rows'], $this->total($all));

        $found = $this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), 'contacts.q=keller', null));
        self::assertSame(['Nora Keller'], $this->distinct($found, $name), 'The search is a case-insensitive substring over the searchable columns.');
        self::assertSame(0, $this->total($this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), 'contacts.q=zzzzzz', null))));
        self::assertSame(400, $this->state($app['public'], $this->viewOf($app), 'contacts.q=k', null)['status'] ?? null, 'A search needs at least two characters.');
    }

    // -- the combined features on an app made by the wizard ---------------------------

    public function testConditionsDataConfigInterpolationAndClassesWorkOnAnAppMadeByTheWizard(): void
    {
        $admin = $this->loginAsQaAdmin();
        $shell = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', ['name' => 'qa-tw-wizard', 'slug' => 'qa-tw-wizard'], $admin), 201);
        $appId = self::coerceInt($shell['id'] ?? null);
        // with_status defaults to true (F169: a declared, never-saved status
        // column reads as empty on the public data-view — never Unknown column).
        $wizard = $this->assertEnvelopeSuccess($this->jsonRequest('POST', sprintf('/cms-api/v1/admin/cms-apps/%d/scaffold', $appId), [
            'base_name' => 'qa-tw-wizard',
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
                ['name' => 'notes', 'style' => 'textarea', 'label' => 'Notes'],
            ],
        ], $admin), 201);

        $pages = [];
        foreach (self::asList($wizard['created'] ?? []) as $entry) {
            $entry = self::asArray($entry);
            $pages[self::coerceString($entry['role'] ?? '')] = self::coerceInt($entry['page_id'] ?? null);
        }
        $cms = $this->pageOf($pages['cms_list']);
        $public = $this->pageOf($pages['public_list']);
        $list = $this->sectionOf($this->sectionIdByStyle($pages['cms_list'], 'entry-form-list', $admin));
        $form = $this->sectionOf($this->childFormId((int) $list->getId()));
        $keys = $this->inputKeys((int) $form->getId());
        $this->formIds[] = (int) $form->getId();
        $tableName = (string) $form->getId();

        // with_status is on by default: the public data-view filters
        // status=published, so the sample writes must be published for the
        // view to read the same three rows (F169 keeps that filter valid
        // before the first save as well).
        $this->assertEnvelopeSuccess($this->submit($admin, $cms, $form, ['title' => 'First entry', 'notes' => 'Notes of the first entry', 'status' => 'published']));
        $this->assertEnvelopeSuccess($this->submit($admin, $cms, $form, ['title' => 'Second entry', 'notes' => 'Notes of the second entry', 'status' => 'published']));
        $this->db->executeStatement('UPDATE pages SET is_open_access = 1 WHERE id = :id', ['id' => $public->getId()]);

        // Content a manager adds to the public page: data_config with a scope, interpolation of it,
        // classes, and conditions on the language of the visitor.
        $latest = $this->pages->createSection('qa_tw_wizard_latest', 'text');
        $this->pages->linkSectionToPage($public, $latest, 5);
        $this->pages->setSectionField($latest, 'text', 'Latest: {{latest.' . $keys['title'] . '}} / {{latest.' . $keys['notes'] . '}}', 1);
        $latest->setDataConfig(json_encode([['scope' => 'latest', 'table' => $tableName, 'retrieve' => 'last', 'current_user' => false]], JSON_THROW_ON_ERROR));
        $latest->setCss('rounded-xl bg-sky-50 p-4 text-sm');
        $latest->setCssMobile('flex flex-col gap-2');
        $german = $this->pages->createSection('qa_tw_wizard_german', 'text');
        $this->pages->linkSectionToPage($public, $german, 6);
        $this->pages->setSectionField($german, 'text', 'Nur auf Deutsch sichtbar', 1);
        $german->setCondition('{"==":[{"var":"language"},2]}');
        $english = $this->pages->createSection('qa_tw_wizard_english', 'text');
        $this->pages->linkSectionToPage($public, $english, 7);
        $this->pages->setSectionField($english, 'text', 'Only visible in English', 1);
        $english->setCondition('{"==":[{"var":"language"},3]}');
        $this->em->flush();
        $this->pages->invalidatePageScopedCaches();

        $url = self::coerceString($this->db->fetchOne('SELECT url FROM pages WHERE id = :id', ['id' => $public->getId()]));
        $resolve = fn (int $language): string => (string) json_encode($this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/resolve?path=' . rawurlencode($url) . '&preview=true&language_id=' . $language, null, $admin),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $de = $resolve(2);
        $en = $resolve(3);
        self::assertTrue(self::has($de, 'Latest: Second entry / Notes of the second entry'), 'data_config with retrieve "last" fills the scope that the text interpolates.');
        self::assertTrue(self::has($de, 'rounded-xl bg-sky-50 p-4 text-sm'), 'The classes of the section reach the page.');
        self::assertTrue(self::has($de, 'Nur auf Deutsch sichtbar'), 'A section for German visitors is shown to them.');
        self::assertFalse(self::has($de, 'Only visible in English'), 'A condition on the language hides the section.');
        self::assertTrue(self::has($en, 'Only visible in English'), 'A section for English visitors is shown to them.');
        self::assertFalse(self::has($en, 'Nur auf Deutsch sichtbar'), 'A condition on the language hides the section.');

        // The view of the generated page and the interpolation follow a write through the list.
        $newest = $this->assertEnvelopeSuccess($this->submit($admin, $cms, $form, ['title' => 'Third entry', 'notes' => 'Notes of the third entry', 'status' => 'published']));
        self::assertGreaterThan(0, self::coerceInt($newest['record_id'] ?? null));
        self::assertTrue(self::has($resolve(2), 'Latest: Third entry / Notes of the third entry'), 'A new record is in the scope at once.');
        $view = $this->sectionOf($this->sectionIdByStyle($pages['public_list'], 'data-view', $admin));
        self::assertSame(3, $this->total($this->assertEnvelopeSuccess($this->state($public, $view, '', null))), 'The public view reads the same dataset.');
        self::assertNotSame([], $keys);

        foreach ($pages as $pageId) {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
        }
        $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
    }

    // -- installing a template ---------------------------------------------------------

    /**
     * Install a template as the "Start from template" flow does, then publish its
     * public pages (a manager does that in the CMS; an import never opens a page).
     *
     * @return array{id: string, cms: Page, public: Page, list: Section, form: Section, view: ?Section, keys: array<string, string>, rows: int}
     */
    private function install(string $id): array
    {
        $admin = $this->loginAsQaAdmin();
        $path = ExampleBundleTestPaths::cmsInCmsBundles()[$id];
        $bundle = self::asArray(json_decode((string) file_get_contents($path), true));
        $roleByKeyword = [];
        foreach (self::asList($bundle['pages'] ?? []) as $page) {
            $page = self::asArray($page);
            $roleByKeyword[self::KEYWORD_PREFIX . self::coerceString($page['keyword'] ?? '')] = self::coerceString($page['cms_app_role'] ?? '');
        }
        $tables = self::asList($bundle['data_tables'] ?? []);
        $rows = count(self::asList(self::asArray($tables[0] ?? [])['rows'] ?? []));

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => ['keywordPrefix' => self::KEYWORD_PREFIX, 'routePrefix' => self::ROUTE_PREFIX, 'importData' => true],
        ], $admin), 201);
        $byRole = [];
        foreach (self::asList($data['created'] ?? []) as $entry) {
            $entry = self::asArray($entry);
            $keyword = self::coerceString($entry['keyword'] ?? '');
            $byRole[$roleByKeyword[$keyword] ?? $keyword] = self::coerceInt($entry['page_id'] ?? null);
        }

        $publicIds = array_values(array_filter([$byRole['public_list'] ?? null, $byRole['public_detail'] ?? null]));
        $this->db->executeStatement('UPDATE pages SET is_open_access = 1 WHERE id IN (' . implode(',', array_map('intval', $publicIds)) . ')');
        $this->pages->invalidatePageScopedCaches();

        $list = $this->sectionOf($this->sectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin));
        $form = $this->sectionOf($this->childFormId((int) $list->getId()));
        $this->formIds[] = (int) $form->getId();
        $viewId = $this->findSectionId($byRole['public_list'], 'data-view', $admin);

        return [
            'id' => $id,
            'cms' => $this->pageOf($byRole['cms_list']),
            'public' => $this->pageOf($byRole['public_list']),
            'list' => $list,
            'form' => $form,
            'view' => $viewId === null ? null : $this->sectionOf($viewId),
            'keys' => $this->inputKeys((int) $form->getId()),
            'rows' => $rows,
        ];
    }

    /**
     * The public data view of a template that has one.
     *
     * @param array{id: string, cms: Page, public: Page, list: Section, form: Section, view: ?Section, keys: array<string, string>, rows: int} $app
     */
    private function viewOf(array $app): Section
    {
        if (!$app['view'] instanceof Section) {
            self::fail($app['id'] . ' has no public data view.');
        }

        return $app['view'];
    }

    // -- what the visitor reads --------------------------------------------------------

    /**
     * Whether a text is on the public page: in the rows of its data view, or in the page
     * a composition renders.
     *
     * @param array{id: string, cms: Page, public: Page, list: Section, form: Section, view: ?Section, keys: array<string, string>, rows: int} $app
     */
    private function publicShows(array $app, string $text): bool
    {
        if ($app['view'] instanceof Section) {
            $state = $this->assertEnvelopeSuccess($this->state($app['public'], $this->viewOf($app), '', null));
            $haystack = (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $haystack = $this->publicPayload($app, 2);
        }

        return self::has($haystack, trim((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '"'));
    }

    /**
     * A failing assertion on a page names the missing text and does not print the page.
     */
    private static function has(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }

    private function withoutAccountLanguage(string $email): void
    {
        $id = self::coerceInt($this->db->fetchOne('SELECT id FROM users WHERE email = :email', ['email' => $email]));
        $this->db->executeStatement('UPDATE users SET id_languages = NULL WHERE id = :id', ['id' => $id]);
        $this->service(CacheService::class)->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $id);
    }

    /**
     * What the public page of a template sends for one language.
     *
     * @param array{id: string, cms: Page, public: Page, list: Section, form: Section, view: ?Section, keys: array<string, string>, rows: int} $app
     */
    private function publicPayload(array $app, int $languageId): string
    {
        $url = self::coerceString($this->db->fetchOne('SELECT url FROM pages WHERE id = :id', ['id' => $app['public']->getId()]));
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/pages/resolve?path=' . rawurlencode($url) . '&preview=true&language_id=' . $languageId, null, $this->loginAsQaAdmin());

        return (string) json_encode($this->assertEnvelopeSuccess($envelope), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // -- calls ----------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function submit(?string $token, Page $page, Section $form, array $values): array
    {
        return $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => $values,
        ], $token);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function update(?string $token, Page $page, Section $form, int $recordId, array $values, int $expectedVersion): array
    {
        return $this->jsonRequest('PUT', self::UPDATE, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => $values,
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => $expectedVersion,
        ], $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(?string $token, Page $page, Section $list, int $recordId, int $expectedVersion): array
    {
        return $this->jsonRequest('DELETE', self::DELETE, [
            'record_id' => $recordId,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
            'expected_version' => $expectedVersion,
        ], $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function action(?string $token, Page $page, Section $list, int $recordId, string $action, int $expectedVersion): array
    {
        return $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/actions/' . $action,
            ['page_id' => (int) $page->getId(), 'expected_version' => $expectedVersion],
            $token,
        );
    }

    private function listUrl(Section $list, string $path, Page $page): string
    {
        return '/cms-api/v1/lists/' . $list->getId() . $path . '?page_id=' . $page->getId();
    }

    /**
     * The next request is made by nobody: the client forgets the person of the last one.
     */
    private function forgetUser(): void
    {
        $context = $this->service(UserContextService::class);
        (new \ReflectionProperty(UserContextService::class, 'userResolved'))->setValue($context, false);
    }

    // -- reading results --------------------------------------------------------------------

    /**
     * @param array<string, mixed> $state
     */
    private function total(array $state): int
    {
        return self::coerceInt($this->at($state, 'pagination')['total'] ?? 0);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function rowOf(array $state, int $recordId): array
    {
        foreach ($this->listOf($state, 'rows') as $row) {
            if (self::coerceInt($row['record_id'] ?? 0) === $recordId) {
                return $row;
            }
        }
        self::fail(sprintf('Record %d is not in the list.', $recordId));
    }

    private function cell(int $recordId, string $key): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols WHERE c.id_data_rows = :row AND col.field_key = :key AND c.id_languages = 1',
            ['row' => $recordId, 'key' => $key],
        );

        return $value === false ? null : self::coerceString($value);
    }

    private function versionOf(int $recordId): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function pageOf(int $pageId): Page
    {
        $page = $this->em->getRepository(Page::class)->find($pageId);
        self::assertInstanceOf(Page::class, $page);

        return $page;
    }

    private function sectionOf(int $sectionId): Section
    {
        $section = $this->em->getRepository(Section::class)->find($sectionId);
        self::assertInstanceOf(Section::class, $section);

        return $section;
    }

    private function sectionIdByStyle(int $pageId, string $style, string $token): int
    {
        $id = $this->findSectionId($pageId, $style, $token);
        self::assertNotNull($id, sprintf('Page %d must carry a "%s" section.', $pageId, $style));

        return $id;
    }

    private function findSectionId(int $pageId, string $style, string $token): ?int
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token));

        return $this->findInSections(self::asList($data['sections'] ?? []), $style);
    }

    /**
     * @param list<mixed> $sections
     */
    private function findInSections(array $sections, string $style): ?int
    {
        foreach ($sections as $section) {
            $section = self::asArray($section);
            if (($section['style_name'] ?? null) === $style && is_int($section['id'] ?? null)) {
                return $section['id'];
            }
            $found = $this->findInSections(self::asList($section['children'] ?? []), $style);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function childFormId(int $listId): int
    {
        // The list is the form: inputs sit directly under the entry-form-list.
        return $listId;
    }

    /**
     * Input name => key (`section_<id>`) of the inputs of a form.
     *
     * @return array<string, string>
     */
    private function inputKeys(int $formId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT h.id_child_section AS id, t.content AS name FROM rel_sections_hierarchy h'
            . ' JOIN sections_fields_translation t ON t.id_sections = h.id_child_section'
            . ' JOIN fields f ON f.id = t.id_fields AND f.name = :field'
            . ' WHERE h.id_parent_section = :form',
            ['field' => 'name', 'form' => $formId],
        );
        $keys = [];
        foreach ($rows as $row) {
            $keys[self::coerceString($row['name'])] = 'section_' . self::coerceInt($row['id']);
        }

        return $keys;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }
}
