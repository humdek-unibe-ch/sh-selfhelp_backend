<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageRoute;
use App\Entity\Section;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\After;

/**
 * Fixtures and request helpers of the list tests (`entry-form-list`, `data-view`):
 * a public data view over a table of events, a participant list with its form,
 * and the calls to the list endpoints. Use inside a {@see QaWebTestCase}.
 */
trait InteractsWithFormLists
{
    private EntityManagerInterface $em;
    private Connection $db;
    private PageSectionFactory $pages;
    private DataGridFactory $grid;

    /** @var list<string> glob patterns of the upload folders this test asked for */
    private array $uploadPatterns = [];

    protected function setUpFormLists(): void
    {
        $this->em = $this->service(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->grid = new DataGridFactory($this->db);
    }

    /**
     * Where the uploads of a form are kept, whoever uploaded them (a glob pattern over the users' folders of the
     * form). The folders are removed with everything in them after the test, so a test never leaves a stored file
     * behind.
     */
    private function uploadsOf(int $formId): string
    {
        $files = self::getContainer()->get(FormFileUploadService::class);
        self::assertInstanceOf(FormFileUploadService::class, $files);
        $pattern = $files->storageRoot() . '/user_*/section_' . $formId;
        $this->uploadPatterns[] = $pattern;

        return $pattern;
    }

    /**
     * Every stored file below the folders of a pattern, at any depth: an upload sits in `field_<input>/`.
     *
     * @return list<string>
     */
    private function filesIn(string $pattern): array
    {
        $found = [];
        foreach (glob($pattern) ?: [] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $found[] = $file->getPathname();
                }
            }
        }
        sort($found);

        return $found;
    }

    #[After]
    protected function removeStoredUploads(): void
    {
        foreach ($this->uploadPatterns as $pattern) {
            foreach (glob($pattern) ?: [] as $directory) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($items as $item) {
                    if ($item instanceof \SplFileInfo) {
                        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                    }
                }
                @rmdir($directory);
            }
        }
        $this->uploadPatterns = [];
    }

    // -- fixtures ---------------------------------------------------------------

    /**
     * A public data view over a table of 30 events; the odd ones are published and the view shows only those.
     *
     * @param array<string, string> $overrides section fields that replace the defaults of this fixture
     * @return array{0: Page, 1: Section}
     */
    private function dataView(string $prefix, array $overrides = [], bool $openAccess = true): array
    {
        $tableId = $this->grid->table($prefix . '_table', [
            'title' => 'text',
            'city' => 'text',
            'seats' => 'number',
            'starts' => 'date',
            'published' => 'boolean',
            'note' => 'text',
        ]);
        $cities = ['Bern', 'Zurich', 'Basel'];
        for ($i = 1; $i <= 30; ++$i) {
            $this->grid->row($tableId, null, [
                'title' => sprintf('Event %02d', $i),
                'city' => $cities[($i - 1) % 3],
                'seats' => (string) ($i * 5),
                'starts' => sprintf('2026-01-%02d', $i),
                'published' => $i % 2 === 1 ? '1' : '0',
                'note' => 'internal note',
            ]);
        }

        $page = $this->pages->createPage($prefix, $openAccess);
        $view = $this->pages->createSection($prefix . '_view', 'data-view');
        $this->pages->linkSectionToPage($page, $view, 10);
        $fields = $overrides + [
            'data_table' => (string) $tableId,
            'list_columns' => json_encode([
                ['field' => 'title', 'label' => 'Title', 'sortable' => true, 'searchable' => true],
                ['field' => 'city', 'label' => 'City', 'sortable' => true, 'searchable' => true],
                ['field' => 'seats', 'label' => 'Seats', 'sortable' => true, 'format' => 'number'],
                ['field' => 'starts', 'label' => 'Starts', 'sortable' => true, 'format' => 'date'],
            ], JSON_THROW_ON_ERROR),
            'list_controls' => json_encode([
                ['id' => 'city', 'type' => 'select', 'field' => 'city', 'label' => 'City', 'options_from' => 'data'],
                ['id' => 'seats', 'type' => 'number_range', 'field' => 'seats', 'label' => 'Seats'],
                ['id' => 'starts', 'type' => 'date_range', 'field' => 'starts', 'label' => 'Starts'],
            ], JSON_THROW_ON_ERROR),
            'list_fixed_filter' => '{"mode":"and","children":[{"column":"published","operator":"eq","value":"1"}]}',
        ];
        foreach ($fields as $name => $content) {
            $this->pages->setSectionField($view, $name, $content);
        }

        return [$page, $view];
    }

    /**
     * A public data view with the layout `custom`: three records per page, ordered by title, in a grid.
     *
     * @param array<string, string> $overrides section fields that replace the defaults of this fixture
     * @return array{0: Page, 1: Section}
     */
    private function customDataView(string $prefix, array $overrides = []): array
    {
        return $this->dataView($prefix, $overrides + [
            'list_layout' => 'custom',
            'list_row_css' => 'grid gap-4',
            'page_size' => '3',
            'list_sort' => '[{"field":"title","direction":"asc"}]',
        ]);
    }

    /**
     * The template of one record of a data view: a card holding a title, a line of text and a button
     * that carry tokens. The texts exist in the languages 2 (de-CH) and 3 (en-GB).
     *
     * @param bool $withHiddenColumn whether the line also uses `{{note}}`, a column the data view does not list
     */
    private function recordTemplate(Section $view, string $prefix, bool $withHiddenColumn = false): Section
    {
        $card = $this->pages->createSection($prefix . '_card', 'container');
        $this->pages->linkSectionToParent($view, $card, 10);
        $card->setCss('qa-card p-4');
        $this->em->flush();

        $title = $this->pages->createSection($prefix . '_title', 'title');
        $this->pages->linkSectionToParent($card, $title, 10);
        foreach ([2, 3] as $languageId) {
            $this->pages->setSectionField($title, 'content', '{{title}}', $languageId);
        }

        $text = $this->pages->createSection($prefix . '_text', 'text');
        $this->pages->linkSectionToParent($card, $text, 20);
        $line = '{{city}} | #{{record_id}} | row {{_index}}' . ($withHiddenColumn ? ' | {{note}}' : '');
        foreach ([2, 3] as $languageId) {
            $this->pages->setSectionField($text, 'text', $line, $languageId);
        }

        $button = $this->pages->createSection($prefix . '_button', 'button');
        $this->pages->linkSectionToParent($card, $button, 30);
        $this->pages->setSectionField($button, 'label', 'Open', 3);
        $this->pages->setSectionField($button, 'label', 'Öffnen', 2);
        $this->pages->setSectionField($button, 'url', '/events/{{record_id}}');

        return $card;
    }

    /**
     * A text section inside a record template that is drawn only for the records its condition holds for (always,
     * when the condition is empty). The text exists in the languages 2 (de-CH) and 3 (en-GB).
     */
    private function conditionalText(Section $parent, string $name, string $text, string $condition, int $position): Section
    {
        $section = $this->pages->createSection($name, 'text');
        $this->pages->linkSectionToParent($parent, $section, $position);
        foreach ([2, 3] as $languageId) {
            $this->pages->setSectionField($section, 'text', $text, $languageId);
        }
        $section->setCondition($condition === '' ? null : $condition);
        $this->em->flush();

        return $section;
    }

    /**
     * An active, canonical route of a page (for example `/team/{record_id}`).
     *
     * @param array<string, string> $requirements placeholder => regular expression
     */
    private function routeFor(Page $page, string $pattern, array $requirements = []): PageRoute
    {
        $route = new PageRoute();
        $route->setPage($page);
        $route->setPathPattern($pattern);
        $route->setRequirements($requirements === [] ? null : $requirements);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();
        $this->pages->invalidatePageScopedCaches();

        return $route;
    }

    /**
     * The id of a table by its name.
     */
    private function tableIdByName(string $name): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT id FROM data_tables WHERE name = :n', ['n' => $name]));
    }

    /**
     * An entry-form-list with its form inside, bound to a table of its own that holds `title` and `status`.
     * The page is private; the subject group may read and write it.
     *
     * @param array<string, string> $fields section fields of the list that replace the defaults of this fixture
     * @param bool $openAccess whether guests may open the page (the list's own rules still apply)
     * @return array{0: Page, 1: Section, 2: int, 3: Section}
     */
    private function formList(string $prefix, array $fields = [], bool $openAccess = false): array
    {
        $page = $this->pages->createPage($prefix, $openAccess);
        $list = $this->pages->createSection($prefix . '_list', 'entry-form-list');
        $form = $this->pages->createSection($prefix . '_form', 'form-record');
        $this->pages->linkSectionToPage($page, $list, 10);
        $this->pages->linkSectionToParent($list, $form, 10);

        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->pages->grantGroupAcl($page, $subject, select: true, insert: true, update: true, delete: true);

        // The form's table is named after the form section; its columns are `title` and `status`.
        $tableId = $this->grid->table((string) $form->getId(), ['title' => 'text', 'status' => 'text']);
        $defaults = [
            'list_columns' => json_encode([
                ['field' => 'title', 'label' => 'Title', 'sortable' => true, 'searchable' => true],
                ['field' => 'status', 'label' => 'Status', 'sortable' => true],
            ], JSON_THROW_ON_ERROR),
        ];
        foreach (($fields + $defaults) as $name => $content) {
            $this->pages->setSectionField($list, $name, $content);
        }

        return [$page, $list, $tableId, $form];
    }

    /**
     * An input of a form: a section of the given style whose `name` field is the
     * input name. Its key in the form's table is `section_<its id>`.
     */
    private function formInput(Section $form, string $sectionName, string $style, string $inputName, int $position = 10): Section
    {
        $input = $this->pages->createSection($sectionName, $style);
        $this->pages->linkSectionToParent($form, $input, $position);
        $this->pages->setSectionField($input, 'name', $inputName);

        return $input;
    }

    /**
     * An entry list whose form declares its inputs, so the server knows the rules of each one. The table of the form
     * holds one column per input under the key the server derives for it (`section_<input id>`); the list shows them all.
     *
     * @param array<string, array{style?: string, fields?: array<string, string>, type?: string}> $inputs
     *        input name => its style (`text-input` when missing), the fields of its section and the value type of its column
     * @param array<string, string> $fields section fields of the list that replace the defaults of this fixture
     * @return array{0: Page, 1: Section, 2: int, 3: Section, 4: array<string, string>} page, list, table id, form,
     *         and the column key of every input by its name
     */
    private function declaredList(string $prefix, array $inputs, array $fields = [], bool $openAccess = false): array
    {
        $page = $this->pages->createPage($prefix, $openAccess);
        $list = $this->pages->createSection($prefix . '_list', 'entry-form-list');
        $form = $this->pages->createSection($prefix . '_form', 'form-record');
        $this->pages->linkSectionToPage($page, $list, 10);
        $this->pages->linkSectionToParent($list, $form, 10);

        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->pages->grantGroupAcl($page, $subject, select: true, insert: true, update: true, delete: true);

        $keys = [];
        $types = [];
        $columns = [];
        $position = 10;
        foreach ($inputs as $name => $spec) {
            $input = $this->formInput($form, $prefix . '_in_' . $name, $spec['style'] ?? 'text-input', $name, $position);
            foreach ($spec['fields'] ?? [] as $field => $content) {
                $this->pages->setSectionField($input, $field, $content);
            }
            $key = 'section_' . $input->getId();
            $keys[$name] = $key;
            $types[$key] = $spec['type'] ?? 'text';
            $columns[] = ['field' => $key, 'label' => $name, 'sortable' => true];
            $position += 10;
        }

        $tableId = $this->grid->table((string) $form->getId(), $types);
        $defaults = ['list_columns' => json_encode($columns, JSON_THROW_ON_ERROR)];
        foreach (($fields + $defaults) as $name => $content) {
            $this->pages->setSectionField($list, $name, $content);
        }

        return [$page, $list, $tableId, $form, $keys];
    }

    /**
     * Two real actions on a table (one for a new entry, one for a change) that mail the acting user: a side effect
     * a test can count with {@see self::scheduledJobs()}.
     *
     * @return list<\App\Entity\Action>
     */
    private function watchTable(int $tableId, string $prefix): array
    {
        $user = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'qa.user@selfhelp.test']);
        self::assertInstanceOf(\App\Entity\User::class, $user);
        $user->setReceivesEmails(true);
        $this->em->flush();

        $table = $this->em->find(\App\Entity\DataTable::class, $tableId);
        self::assertInstanceOf(\App\Entity\DataTable::class, $table);
        $actions = new \App\Tests\Support\Factories\ActionFactory($this->em);

        return [
            $actions->createAction($table, LookupService::ACTION_TRIGGER_TYPES_FINISHED, \App\Tests\Support\Factories\ActionFactory::immediateEmailConfig('A new entry', 'An entry was added.'), $prefix . '_on_new'),
            $actions->createAction($table, LookupService::ACTION_TRIGGER_TYPES_UPDATED, \App\Tests\Support\Factories\ActionFactory::immediateEmailConfig('A changed entry', 'An entry was changed.'), $prefix . '_on_change'),
        ];
    }

    /**
     * @param list<\App\Entity\Action> $watchers
     */
    private function scheduledJobs(array $watchers): int
    {
        $ids = array_map(static fn (\App\Entity\Action $action): int => (int) $action->getId(), $watchers);

        return self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM scheduled_jobs WHERE id_actions IN (' . implode(',', $ids) . ')'));
    }

    /**
     * Everything a refused request must leave alone for the entries of one table: how many there are, their owners
     * and versions, their cells, the revisions and the jobs of the watching actions.
     *
     * @param list<\App\Entity\Action> $watchers
     * @return array<string, mixed>
     */
    private function footprintOf(int $tableId, array $watchers): array
    {
        return [
            'rows' => $this->db->fetchAllAssociative('SELECT id, id_users, version, id_action_trigger_types FROM data_rows WHERE id_data_tables = :t ORDER BY id', ['t' => $tableId]),
            'cells' => $this->db->fetchAllAssociative('SELECT c.id_data_rows, col.field_key, c.id_languages, c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols JOIN data_rows r ON r.id = c.id_data_rows WHERE r.id_data_tables = :t ORDER BY c.id_data_rows, col.field_key, c.id_languages', ['t' => $tableId]),
            'revisions' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_revisions rv JOIN data_rows r ON r.id = rv.id_data_rows WHERE r.id_data_tables = :t', ['t' => $tableId])),
            'jobs' => $this->scheduledJobs($watchers),
        ];
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function state(Page $page, Section $list, string $query = '', ?string $token = null): array
    {
        $uri = '/cms-api/v1/lists/' . $list->getId() . '/state?page_id=' . $page->getId() . ($query === '' ? '' : '&' . $query);

        return $this->jsonRequest('GET', $uri, null, $token);
    }

    private function userId(string $email): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT id FROM users WHERE email = :e', ['e' => $email]));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function at(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value, '"' . $key . '" must be an object.');

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function listOf(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value, '"' . $key . '" must be a list.');

        $result = [];
        foreach ($value as $item) {
            self::assertIsArray($item);
            $row = [];
            foreach ($item as $k => $v) {
                $row[(string) $k] = $v;
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * The distinct values of one column over the rows of a state, as text, in first-seen order.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function distinct(array $data, string $column): array
    {
        $values = [];
        foreach ($this->listOf($data, 'rows') as $row) {
            $values[self::coerceString($this->at($row, 'values')[$column] ?? '')] = true;
        }

        return array_map('strval', array_keys($values));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function titles(array $data): array
    {
        return array_map(
            static fn (array $row): string => is_array($row['values'] ?? null) && is_string($row['values']['title'] ?? null) ? $row['values']['title'] : '',
            $this->listOf($data, 'rows'),
        );
    }
}
