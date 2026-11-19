<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Language;
use App\Entity\Lookup;
use App\Entity\Page;
use App\Entity\PagesFieldsTranslation;
use App\Entity\PageRoute;
use App\Entity\PagesSection;
use App\Entity\PageType;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Entity\Style;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\NavigationAssignmentService;
use App\Service\CMS\NavigationCacheInvalidator;
use App\Service\Core\LookupService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * QA fixture of the entry lists (`entry-form-list`, `data-view`) the frontend
 * golden spec `e2e/golden/form-list.spec.ts` and the mobile flow
 * `e2e/golden/form-list.yaml` drive through the real UI. Seeded by
 * `composer test:reset-db` next to {@see QaBaselineFixture} and
 * {@see QaFrontendGoldenFixture} (group `qa`).
 *
 *   page  qa-entries  /qa-entries   private; the `subject` group may read, add, edit and delete
 *     ├─ entry-form-list  list_key=entries  form in a modal, history on, 5 per page,
 *     │    search + sort + status filter, quick action "Mark done" for open records;
 *     │    inputs directly inside: qa_entry_title (required), qa_entry_status (select), qa_entry_notes
 *     └─ entry-form-list  list_key=tasks    form on a page, 25 per page;
 *          inputs directly inside: qa_task_title (required), qa_task_notes, qa_task_file (an upload)
 *   page  qa-events   /qa-events    open access; read only
 *     └─ data-view  list_key=events  12 seeded events, search + sort + city filter, 5 per page
 *   page  qa-event-cards  /qa-event-cards  open access; read only
 *     └─ data-view  list_key=cards  the same events drawn as cards from sections placed inside the
 *          view (layout custom): a date badge, the title, the city; 4 per page
 *   page  qa-wide     /qa-wide     private; the `subject` group may read, add, edit and delete
 *     ├─ entry-form-list  list_key=wide      form in a modal
 *     └─ entry-form-list  list_key=widepage  the same form on a page
 *          Both forms hold 24 inputs (text, textarea, select, checkbox, radio, number, switch) whose
 *          labels are very long and contain one unbroken run of 70 characters, and both lists have 12
 *          columns with very long labels: the fixture of the size checks (1366x768 and 390x844)
 *          for keyboard, focus, errors and unsaved input.
 *
 * `qa.user` and `qa.editor` are both in `subject`: the same address shows each
 * of them only their own records (`own_entries_only` is on), which is what the
 * account-switch checks rely on. `qa.guest` has no group and sees no entries.
 *
 * No participant record is seeded: a spec creates its own through the form so
 * it also proves the write path. The events are seeded straight into the
 * generic store, as an import would, because a data view never writes.
 *
 * The lists store the immutable column key `section_<input id>`; the keys are
 * known only after the inputs have an id, so the list fields are written after
 * the first flush.
 */
final class QaFormListFixture extends Fixture implements FixtureGroupInterface
{
    public const ENTRIES_PAGE_KEYWORD = 'qa-entries';
    public const ENTRIES_PAGE_URL = '/qa-entries';
    public const EVENTS_PAGE_KEYWORD = 'qa-events';
    public const EVENTS_PAGE_URL = '/qa-events';
    public const CARDS_PAGE_KEYWORD = 'qa-event-cards';
    public const CARDS_PAGE_URL = '/qa-event-cards';
    public const WIDE_PAGE_KEYWORD = 'qa-wide';
    public const WIDE_PAGE_URL = '/qa-wide';

    public const ENTRIES_LIST_KEY = 'entries';
    public const TASKS_LIST_KEY = 'tasks';
    public const EVENTS_LIST_KEY = 'events';
    public const CARDS_LIST_KEY = 'cards';
    public const WIDE_LIST_KEY = 'wide';
    public const WIDE_PAGE_LIST_KEY = 'widepage';

    public const ENTRIES_LIST_SECTION_NAME = 'qa-entries-list';
    public const TASKS_LIST_SECTION_NAME = 'qa-tasks-list';
    public const EVENTS_VIEW_SECTION_NAME = 'qa-events-view';
    public const CARDS_VIEW_SECTION_NAME = 'qa-event-cards-view';
    public const WIDE_LIST_SECTION_NAME = 'qa-wide-list';
    public const WIDE_PAGE_LIST_SECTION_NAME = 'qa-wide-page-list';

    /** Inputs of each wide form, and how many of them are listed as columns. */
    public const WIDE_FIELD_COUNT = 24;
    public const WIDE_COLUMN_COUNT = 12;

    public const EVENT_COUNT = 12;
    public const EVENTS_TABLE_NAME = 'qa_events_table';

    /** Property language id used for non-translatable fields (`all`). */
    private const PROPERTY_LANGUAGE_ID = 1;

    /** Content locales every shipped install carries. */
    private const CONTENT_LOCALES = ['de-CH', 'en-GB'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ACLService $aclService,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
        private readonly NavigationAssignmentService $navigationAssignment,
        private readonly NavigationCacheInvalidator $navigationCache,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['qa'];
    }

    public function load(ObjectManager $manager): void
    {
        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        if (!$subject instanceof Group) {
            throw new \RuntimeException('QA form list fixture cannot seed: the seeded "subject" group is missing. The schema baseline + reference-data seed migrations must run first.');
        }

        if (!$this->em->getRepository(Page::class)->findOneBy(['keyword' => self::ENTRIES_PAGE_KEYWORD]) instanceof Page) {
            $this->seedEntriesPage($subject);
        }
        if (!$this->em->getRepository(Page::class)->findOneBy(['keyword' => self::EVENTS_PAGE_KEYWORD]) instanceof Page) {
            $this->seedEventsPage();
        }
        if (!$this->em->getRepository(Page::class)->findOneBy(['keyword' => self::CARDS_PAGE_KEYWORD]) instanceof Page) {
            $this->seedEventCardsPage();
        }
        if (!$this->em->getRepository(Page::class)->findOneBy(['keyword' => self::WIDE_PAGE_KEYWORD]) instanceof Page) {
            $this->seedWidePage($subject);
        }

        // Redis outlives a reset DB and ids are reused: drop what was cached for the old pages.
        foreach ([CacheService::CATEGORY_PAGES, CacheService::CATEGORY_SECTIONS, CacheService::CATEGORY_PERMISSIONS] as $category) {
            $this->cache->withCategory($category)->invalidateCategory();
        }
        $this->navigationCache->invalidateAll();
    }

    private function seedEntriesPage(Group $subject): void
    {
        $page = $this->page(self::ENTRIES_PAGE_KEYWORD, self::ENTRIES_PAGE_URL, false);
        $this->pageTitle($page, 'QA entries');

        // The entries list: inputs directly inside the list (modal form).
        $entries = $this->section(self::ENTRIES_LIST_SECTION_NAME, 'entry-form-list');
        $entryTitle = $this->input($entries, 'qa-entry-title', 'text-input', 'qa_entry_title', 'Title', 10);
        $entryStatus = $this->input($entries, 'qa-entry-status', 'select', 'qa_entry_status', 'Status', 20);
        $entryNotes = $this->input($entries, 'qa-entry-notes', 'textarea', 'qa_entry_notes', 'Notes', 30);
        $this->required($entryTitle);
        $this->property($entryStatus, 'options', '[{"value":"open","label":"Open"},{"value":"done","label":"Done"}]');
        $this->property($entryStatus, 'value', 'open');
        $this->link($page, $entries, 10);

        // The tasks list: inputs directly inside the list (page form).
        $tasks = $this->section(self::TASKS_LIST_SECTION_NAME, 'entry-form-list');
        $taskTitle = $this->input($tasks, 'qa-task-title', 'text-input', 'qa_task_title', 'Task', 10);
        $taskNotes = $this->input($tasks, 'qa-task-notes', 'textarea', 'qa_task_notes', 'Details', 20);
        $taskFile = $this->input($tasks, 'qa-task-file', 'file-input', 'qa_task_file', 'Attachment', 30);
        $this->required($taskTitle);
        $this->link($page, $tasks, 20);

        // Ids are needed for the column keys and for the ACL row.
        $this->em->flush();

        $titleKey = $this->key($entryTitle);
        $statusKey = $this->key($entryStatus);
        $notesKey = $this->key($entryNotes);
        $this->property($entries, 'list_key', self::ENTRIES_LIST_KEY);
        $this->translated($entries, 'title', 'Entries');
        $this->property($entries, 'form_presentation', 'modal');
        $this->property($entries, 'page_size', '5');
        $this->property($entries, 'show_history', '1');
        $this->property($entries, 'list_columns', $this->json([
            ['field' => $titleKey, 'label' => 'Title', 'sortable' => true, 'searchable' => true],
            ['field' => $statusKey, 'label' => 'Status', 'sortable' => true],
            ['field' => $notesKey, 'label' => 'Notes', 'searchable' => true],
        ]));
        $this->property($entries, 'list_controls', $this->json([
            ['id' => 'status', 'type' => 'select', 'field' => $statusKey, 'label' => 'Status', 'options_from' => 'data'],
        ]));
        $this->property($entries, 'quick_actions', $this->json([
            [
                'id' => 'done',
                'label' => 'Mark done',
                'confirm' => 'Mark this entry done?',
                'set' => [$statusKey => 'done'],
                'when' => ['mode' => 'and', 'children' => [['column' => $statusKey, 'operator' => 'eq', 'value' => 'open']]],
            ],
        ]));

        $taskTitleKey = $this->key($taskTitle);
        $taskNotesKey = $this->key($taskNotes);
        $taskFileKey = $this->key($taskFile);
        $this->property($tasks, 'list_key', self::TASKS_LIST_KEY);
        $this->translated($tasks, 'title', 'Tasks');
        $this->property($tasks, 'form_presentation', 'page');
        $this->property($tasks, 'page_size', '25');
        $this->property($tasks, 'list_columns', $this->json([
            ['field' => $taskTitleKey, 'label' => 'Task', 'sortable' => true, 'searchable' => true],
            ['field' => $taskNotesKey, 'label' => 'Details'],
            ['field' => $taskFileKey, 'label' => 'Attachment', 'format' => 'file'],
        ]));

        $this->em->flush();
        $this->aclService->addGroupAcl($page, $subject, $this->em);
        $this->em->flush();

        // A menu entry of the mobile drawer, so the app draws it as a route screen. The events page stays
        // off the menu (the app opens it as a page sheet), which lets the mobile flows cover both.
        $this->navigationAssignment->applyAssignmentsForPage($page, [['menuKey' => 'mobile_drawer', 'position' => 20]]);
        $this->em->flush();
    }

    private function seedEventsPage(): void
    {
        $page = $this->page(self::EVENTS_PAGE_KEYWORD, self::EVENTS_PAGE_URL, true);
        $this->pageTitle($page, 'QA events');
        $tableId = $this->eventsTable();

        $view = $this->section(self::EVENTS_VIEW_SECTION_NAME, 'data-view');
        $this->link($page, $view, 10);
        $this->em->flush();

        $this->property($view, 'list_key', self::EVENTS_LIST_KEY);
        $this->translated($view, 'title', 'Events');
        $this->property($view, 'data_table', (string) $tableId);
        $this->property($view, 'page_size', '5');
        $this->property($view, 'list_columns', $this->json([
            ['field' => 'title', 'label' => 'Title', 'sortable' => true, 'searchable' => true],
            ['field' => 'city', 'label' => 'City', 'sortable' => true, 'searchable' => true],
            ['field' => 'starts', 'label' => 'Starts', 'sortable' => true, 'format' => 'date'],
        ]));
        $this->property($view, 'list_sort', $this->json([['field' => 'starts', 'direction' => 'asc']]));
        $this->property($view, 'list_controls', $this->json([
            ['id' => 'city', 'type' => 'select', 'field' => 'city', 'label' => 'City', 'options_from' => 'data'],
        ]));
        $this->em->flush();
    }

    /**
     * The same events drawn from sections: a data view with the layout `custom` holds the layout of one
     * record (a card with a date badge, the title and the city) and the server renders it once per record.
     */
    private function seedEventCardsPage(): void
    {
        $tableId = self::coerceTableId($this->em->getConnection()->fetchOne(
            'SELECT id FROM data_tables WHERE name = :name',
            ['name' => self::EVENTS_TABLE_NAME]
        ));

        $page = $this->page(self::CARDS_PAGE_KEYWORD, self::CARDS_PAGE_URL, true);
        $this->pageTitle($page, 'QA event cards');

        $view = $this->section(self::CARDS_VIEW_SECTION_NAME, 'data-view');
        $this->link($page, $view, 10);
        $this->em->flush();

        $this->property($view, 'list_key', self::CARDS_LIST_KEY);
        $this->property($view, 'data_table', (string) $tableId);
        $this->property($view, 'list_layout', 'custom');
        $this->property($view, 'list_row_css', 'grid grid-cols-1 gap-4 md:grid-cols-2');
        $this->property($view, 'page_size', '4');
        $this->property($view, 'list_columns', $this->json([
            ['field' => 'title', 'label' => 'Title', 'sortable' => true, 'searchable' => true],
            ['field' => 'city', 'label' => 'City', 'sortable' => true, 'searchable' => true],
            ['field' => 'starts', 'label' => 'Starts', 'sortable' => true, 'format' => 'date'],
        ]));
        $this->property($view, 'list_sort', $this->json([['field' => 'starts', 'direction' => 'asc']]));
        $this->property($view, 'list_controls', $this->json([
            ['id' => 'city', 'type' => 'select', 'field' => 'city', 'label' => 'City', 'options_from' => 'data'],
        ]));

        $card = $this->section('qa-event-card', 'container');
        $this->child($view, $card, 10);
        $card->setCss('qa-event-card rounded border p-4');
        $badge = $this->section('qa-event-card-date', 'badge');
        $this->child($card, $badge, 10);
        $this->translated($badge, 'label', '{{starts}}');
        $title = $this->section('qa-event-card-title', 'title');
        $this->child($card, $title, 20);
        $this->translated($title, 'content', '{{title}}');
        $this->property($title, 'title_order', '3');
        $city = $this->section('qa-event-card-city', 'text');
        $this->child($card, $city, 30);
        $this->translated($city, 'text', '{{city}}');
        $this->em->flush();
    }

    /**
     * Two lists with a form of 24 inputs and very long labels: the fixture the size checks run on. A
     * participant form is not always three short fields; the labels of a real questionnaire are sentences,
     * and a translation can be a long compound word. Nothing may overflow the page, the buttons of the form
     * stay reachable, and a required input far down is named and reached by keyboard.
     */
    private function seedWidePage(Group $subject): void
    {
        $page = $this->page(self::WIDE_PAGE_KEYWORD, self::WIDE_PAGE_URL, false);
        $this->pageTitle($page, 'QA wide forms');

        $lists = [
            [self::WIDE_LIST_SECTION_NAME, self::WIDE_LIST_KEY, 'Wide entries', 'modal', 'qa_wide', 10],
            [self::WIDE_PAGE_LIST_SECTION_NAME, self::WIDE_PAGE_LIST_KEY, 'Wide entries on a page', 'page', 'qa_widepage', 20],
        ];

        $built = [];
        foreach ($lists as [$listName, , , , $prefix, $position]) {
            $list = $this->section($listName, 'entry-form-list');
            $inputs = [];
            for ($n = 1; $n <= self::WIDE_FIELD_COUNT; ++$n) {
                $inputs[] = $this->wideInput($list, $prefix, $n);
            }
            $this->link($page, $list, $position);
            $built[] = [$list, $inputs];
        }

        // Ids are needed for the column keys and for the ACL row.
        $this->em->flush();

        foreach ($lists as $index => [, $listKey, $title, $presentation]) {
            [$list, $inputs] = $built[$index];
            $columns = [];
            foreach (array_slice($inputs, 0, self::WIDE_COLUMN_COUNT) as $columnIndex => $input) {
                $columns[] = array_filter([
                    'field' => $this->key($input),
                    'label' => $this->longColumnLabel($columnIndex + 1),
                    'sortable' => $columnIndex < 2 ? true : null,
                    'searchable' => $columnIndex < 2 ? true : null,
                ], static fn (mixed $value): bool => $value !== null);
            }
            $this->property($list, 'list_key', $listKey);
            $this->translated($list, 'title', $title);
            $this->property($list, 'form_presentation', $presentation);
            $this->property($list, 'page_size', '10');
            $this->property($list, 'show_history', '1');
            $this->property($list, 'list_columns', $this->json($columns));
        }

        $this->em->flush();
        $this->aclService->addGroupAcl($page, $subject, $this->em);
        $this->em->flush();
    }

    /**
     * One input of a wide form. The kind follows the position so every kind appears and the same position is the
     * same kind in both forms: the first three text-like ones are required, one of them far down. The checkboxes (6,
     * 14, 22) are never required: the web form sends no value for a checkbox that has no input wrapper, so a required one
     * could not be satisfied from the browser.
     */
    private function wideInput(Section $form, string $prefix, int $n): Section
    {
        $kinds = ['text-input', 'text-input', 'textarea', 'select', 'text-input', 'checkbox', 'text-input', 'number-input'];
        $kind = $n === 24 ? 'switch' : ($n === 23 ? 'radio' : $kinds[($n - 1) % count($kinds)]);
        $input = $this->input($form, sprintf('%s-input-%02d', str_replace('_', '-', $prefix), $n), $kind, sprintf('%s_%02d', $prefix, $n), $this->longLabel($n), $n * 10);
        if ($kind === 'select' || $kind === 'radio') {
            $this->property($input, 'options', '[{"value":"a","label":"Option A"},{"value":"b","label":"Option B"},{"value":"c","label":"Option C"}]');
        }
        // The first, a middle and the last text-like input must be filled in: the error summary has to name inputs
        // that are far apart, with their long labels, and keyboard focus has to go to the first one that is wrong.
        if (in_array($n, [1, 13, 21], true)) {
            $this->required($input);
        }

        return $input;
    }

    /** A label as long as a sentence of a questionnaire, with one run of 70 characters that has no break. */
    private function longLabel(int $n): string
    {
        return sprintf(
            'Question %02d: please describe in as much detail as you can how this request relates to the framework agreement that the department signed last year and to every amendment since, including the unbrokenreferencenumber%s',
            $n,
            str_repeat('0123456789', 7)
        );
    }

    /** The header of a column: long enough to wrap in a table and to break a card, with a run that has no break. */
    private function longColumnLabel(int $n): string
    {
        return sprintf(
            'Column %02d: the answer given to the question about the framework agreement (reference unbrokencolumnheader%s)',
            $n,
            str_repeat('abcdefghij', 4)
        );
    }

    private static function coerceTableId(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('The events table is missing: it is seeded before the cards page.');
        }

        return (int) $value;
    }

    /**
     * The table of the events: written straight into the generic store, the way an import leaves it.
     */
    private function eventsTable(): int
    {
        $db = $this->em->getConnection();
        $db->executeStatement(
            'INSERT INTO data_tables (`name`, `timestamp`, display_name) VALUES (:name, UTC_TIMESTAMP(), :display)',
            ['name' => self::EVENTS_TABLE_NAME, 'display' => 'QA events']
        );
        $tableId = (int) $db->lastInsertId();

        $columns = [];
        foreach (['title' => null, 'city' => null, 'starts' => 'date'] as $key => $type) {
            $typeId = $type === null ? null : $this->lookupId('dataColValueTypes', $type);
            $db->executeStatement(
                'INSERT INTO data_cols (id_data_tables, field_key, display_name, id_value_type) VALUES (:t, :k, :d, :v)',
                ['t' => $tableId, 'k' => $key, 'd' => $key, 'v' => $typeId],
                ['t' => ParameterType::INTEGER, 'k' => ParameterType::STRING, 'd' => ParameterType::STRING, 'v' => $typeId === null ? ParameterType::NULL : ParameterType::INTEGER]
            );
            $columns[$key] = (int) $db->lastInsertId();
        }

        $finished = $this->lookupId('actionTriggerTypes', 'finished');
        $cities = ['Bern', 'Zurich', 'Basel'];
        for ($i = 1; $i <= self::EVENT_COUNT; ++$i) {
            $db->executeStatement(
                'INSERT INTO data_rows (id_data_tables, `timestamp`, id_users, id_action_trigger_types) VALUES (:t, UTC_TIMESTAMP(), NULL, :a)',
                ['t' => $tableId, 'a' => $finished],
                ['t' => ParameterType::INTEGER, 'a' => ParameterType::INTEGER]
            );
            $rowId = (int) $db->lastInsertId();
            $cells = [
                'title' => sprintf('QA event %02d', $i),
                'city' => $cities[($i - 1) % 3],
                'starts' => sprintf('2026-03-%02d', $i),
            ];
            foreach ($cells as $key => $value) {
                $db->executeStatement(
                    'INSERT INTO data_cells (id_data_rows, id_data_cols, id_languages, `value`) VALUES (:r, :c, :l, :v)',
                    ['r' => $rowId, 'c' => $columns[$key], 'l' => self::PROPERTY_LANGUAGE_ID, 'v' => $value],
                    ['r' => ParameterType::INTEGER, 'c' => ParameterType::INTEGER, 'l' => ParameterType::INTEGER, 'v' => ParameterType::STRING]
                );
            }
        }

        return $tableId;
    }

    // -- building blocks -------------------------------------------------------------

    private function page(string $keyword, string $url, bool $openAccess): Page
    {
        // A content page type: the public page tree and the menus list only these, and the mobile app resolves pages through them.
        $pageType = $this->em->getRepository(PageType::class)->findOneBy(['name' => 'experiment']);
        if (!$pageType instanceof PageType) {
            throw new \RuntimeException('Missing page type "experiment". The baseline seed migrations must run first.');
        }
        // The web frontend and the mobile app both drive these pages; a `web` page answers 404 to the mobile client.
        $access = $this->lookupService->findByTypeAndCode(LookupService::PAGE_ACCESS_TYPES, LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB);
        if (!$access instanceof Lookup) {
            throw new \RuntimeException('Missing pageAccessTypes/mobile_and_web lookup. The reference-data seed migration must run first.');
        }

        $page = new Page();
        $page->setKeyword($keyword);
        $page->setUrl($url);
        $page->setPageType($pageType);
        $page->setPageAccessType($access);
        $page->setIsHeadless(false);
        $page->setIsOpenAccess($openAccess);
        $this->em->persist($page);

        // A page is reached by its public path through `page_routes`; without a row the frontend answers "Page not found".
        $route = new PageRoute();
        $route->setPage($page);
        $route->setPathPattern($url);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $route->setPriority(100);
        $this->em->persist($route);

        return $page;
    }

    private function pageTitle(Page $page, string $title): void
    {
        foreach (self::CONTENT_LOCALES as $locale) {
            $translation = new PagesFieldsTranslation();
            $translation->setPage($page);
            $translation->setField($this->field('title'));
            $translation->setLanguage($this->languageByLocale($locale));
            $translation->setContent($title);
            $this->em->persist($translation);
        }
    }

    private function section(string $name, string $styleName): Section
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $styleName]);
        if (!$style instanceof Style) {
            throw new \RuntimeException(sprintf('Missing seeded style "%s". The styles seed migrations must run first.', $styleName));
        }
        $section = new Section();
        $section->setName($name);
        $section->setStyle($style);
        $this->em->persist($section);

        return $section;
    }

    private function input(Section $form, string $sectionName, string $style, string $inputName, string $label, int $position): Section
    {
        $input = $this->section($sectionName, $style);
        $this->child($form, $input, $position);
        $this->property($input, 'name', $inputName);
        $this->translated($input, 'label', $label);

        return $input;
    }

    private function required(Section $input): void
    {
        $this->property($input, 'is_required', '1');
    }

    private function link(Page $page, Section $section, int $position): void
    {
        $link = new PagesSection();
        $link->setPage($page);
        $link->setSection($section);
        $link->setPosition($position);
        $this->em->persist($link);
    }

    private function child(Section $parent, Section $child, int $position): void
    {
        $link = new SectionsHierarchy();
        $link->setParentSection($parent);
        $link->setChildSection($child);
        $link->setPosition($position);
        $this->em->persist($link);
    }

    /** A field that is not translated: stored once under the property language. */
    private function property(Section $section, string $fieldName, string $content): void
    {
        $this->translation($section, $fieldName, $this->language(self::PROPERTY_LANGUAGE_ID), $content);
    }

    /** A field that is translated: stored for every content locale. */
    private function translated(Section $section, string $fieldName, string $content): void
    {
        foreach (self::CONTENT_LOCALES as $locale) {
            $this->translation($section, $fieldName, $this->languageByLocale($locale), $content);
        }
    }

    private function translation(Section $section, string $fieldName, Language $language, string $content): void
    {
        $translation = new SectionsFieldsTranslation();
        $translation->setSection($section);
        $translation->setField($this->field($fieldName));
        $translation->setLanguage($language);
        $translation->setContent($content);
        $this->em->persist($translation);
    }

    /** The immutable column key of an input in its form's table. */
    private function key(Section $input): string
    {
        return 'section_' . (int) $input->getId();
    }

    /**
     * @param list<array<string, mixed>> $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function lookupId(string $typeCode, string $lookupCode): int
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM lookups WHERE type_code = :t AND lookup_code = :c', ['t' => $typeCode, 'c' => $lookupCode]);
        if (!is_numeric($id)) {
            throw new \RuntimeException(sprintf('Lookup %s/%s is not seeded. The reference-data seed migration must run first.', $typeCode, $lookupCode));
        }

        return (int) $id;
    }

    private function field(string $name): Field
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $name]);
        if (!$field instanceof Field) {
            throw new \RuntimeException(sprintf('Missing seeded field "%s". The fields/styles seed migrations must run first.', $name));
        }

        return $field;
    }

    private function language(int $id): Language
    {
        $language = $this->em->getRepository(Language::class)->find($id);
        if (!$language instanceof Language) {
            throw new \RuntimeException(sprintf('Missing language id %d (the property language). The reference-data seed migration must run first.', $id));
        }

        return $language;
    }

    private function languageByLocale(string $locale): Language
    {
        $language = $this->em->getRepository(Language::class)->findOneBy(['locale' => $locale]);
        if (!$language instanceof Language) {
            throw new \RuntimeException(sprintf('Missing language locale "%s". The reference-data seed migration must run first.', $locale));
        }

        return $language;
    }
}
