<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

/**
 * The configuration of one page exactly as one visitor is served it: the
 * published version for everyone, the draft only for an authorized preview.
 *
 * Every endpoint that acts on a section of a page (the list state, the history,
 * a form write, a quick action, the row template of a data view) takes its
 * configuration from here, and from nowhere else. The page response and these
 * endpoints therefore describe the same page: a column, a filter, a form input
 * or an operation switch that was added or removed in the draft is not seen
 * (not even by its absence) until it is published, and a page tree is never
 * combined with the properties of another version.
 *
 * What stays live in both modes, by design: the page ACL, the routes and menus,
 * the data of the tables and the records' own history.
 *
 * Built by {@see EffectivePageResolver}; a value for one request.
 */
final class EffectivePage
{
    public const SOURCE_PUBLISHED = 'published';
    public const SOURCE_DRAFT = 'draft';

    /** Keys of a stored section node that are structure, not a stored field. */
    private const NODE_STRUCTURE = [
        'id', 'section_name', 'id_styles', 'style_name', 'can_have_children', 'condition', 'css', 'css_mobile',
        'debug', 'data_config', 'position', 'level', 'path', 'children', 'translations',
    ];

    /** @var array<int, list<array<array-key, mixed>>> section id => its rows (one per place in the tree) */
    private array $rowsBySection = [];

    /** @var array<int, array<string, array<int, string>>> section id => field => language id => content */
    private array $fieldCache = [];

    /**
     * @param list<array<array-key, mixed>> $rows the section tree flattened like `get_page_sections_hierarchical`
     * @param array<int, array<string, array<int, string>>> $storedFields published: every section's stored fields
     * @param (\Closure(int): array<string, array<int, string>>)|null $liveFields draft: reads the stored fields of a section
     * @param list<array<string, mixed>> $nodes published: the stored nested section tree (all languages)
     */
    private function __construct(
        public readonly int $pageId,
        public readonly string $source,
        public readonly ?int $versionId,
        private readonly array $rows,
        private readonly array $storedFields,
        private readonly ?\Closure $liveFields,
        private readonly array $nodes,
    ) {
        foreach ($rows as $row) {
            $id = self::intOf($row['id'] ?? null);
            if ($id > 0) {
                $this->rowsBySection[$id][] = $row;
            }
        }
    }

    /**
     * The page as its draft is: the live tree and the live fields.
     *
     * @param list<array<array-key, mixed>> $rows
     * @param \Closure(int): array<string, array<int, string>> $liveFields
     */
    public static function draft(int $pageId, array $rows, \Closure $liveFields): self
    {
        return new self($pageId, self::SOURCE_DRAFT, null, $rows, [], $liveFields, []);
    }

    /**
     * The page as one published version holds it. The nested tree is flattened
     * into the rows of the live tree, and the stored fields are read from the
     * `translations` of every node (the stored rows, like the draft reads them).
     *
     * @param list<array<string, mixed>> $nodes `page.sections` of the stored version JSON
     */
    public static function published(int $pageId, int $versionId, array $nodes): self
    {
        $rows = [];
        $fields = [];
        self::walk($nodes, [], 0, $rows, $fields);

        return new self($pageId, self::SOURCE_PUBLISHED, $versionId, $rows, $fields, null, $nodes);
    }

    public function isPublished(): bool
    {
        return $this->source === self::SOURCE_PUBLISHED;
    }

    /**
     * @return list<array<array-key, mixed>> every place of every section, ordered like the page tree (path, position)
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<array<array-key, mixed>> the places of one section in the tree (empty when it is not on the page)
     */
    public function rowsOf(int $sectionId): array
    {
        return $this->rowsBySection[$sectionId] ?? [];
    }

    public function contains(int $sectionId): bool
    {
        return isset($this->rowsBySection[$sectionId]);
    }

    /**
     * The style of a section of this page; null when the section is not on it.
     */
    public function styleOf(int $sectionId): ?string
    {
        $style = $this->rowsBySection[$sectionId][0]['style_name'] ?? null;

        return is_string($style) ? $style : null;
    }

    /**
     * The stored content of every field of a section of this page: field name => language id => content.
     *
     * @return array<string, array<int, string>>
     */
    public function rawFields(int $sectionId): array
    {
        if (!$this->contains($sectionId)) {
            return [];
        }
        if ($this->liveFields === null) {
            return $this->storedFields[$sectionId] ?? [];
        }

        return $this->fieldCache[$sectionId] ??= ($this->liveFields)($sectionId);
    }

    /**
     * The first stored content of a field of a section ('' when it holds none).
     */
    public function fieldContent(int $sectionId, string $field, int $languageId = 1): string
    {
        $stored = $this->rawFields($sectionId)[$field] ?? [];

        return $stored[$languageId] ?? ($stored === [] ? '' : $stored[array_key_first($stored)]);
    }

    /**
     * The ids of every section below a section in this page's tree, nearest first.
     *
     * @return list<int>
     */
    public function descendantIds(int $sectionId): array
    {
        $found = [];
        foreach ($this->rows as $row) {
            $ids = self::pathIds($row);
            $position = array_search($sectionId, $ids, true);
            if ($position === false) {
                continue;
            }
            foreach (array_slice($ids, $position + 1) as $id) {
                $found[$id] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The stored nested section tree of a published page, with all languages.
     *
     * @return list<array<string, mixed>>
     */
    public function publishedNodes(): array
    {
        return $this->nodes;
    }

    /**
     * The ids of the sections on the way from the page root to a row, root first.
     *
     * @param array<array-key, mixed> $row
     * @return list<int>
     */
    public static function pathIds(array $row): array
    {
        $path = $row['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return [];
        }

        return array_map('intval', explode(',', $path));
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<int> $ancestors
     * @param list<array<array-key, mixed>> $rows
     * @param array<int, array<string, array<int, string>>> $fields
     */
    private static function walk(array $nodes, array $ancestors, int $level, array &$rows, array &$fields): void
    {
        foreach ($nodes as $node) {
            $id = self::intOf($node['id'] ?? null);
            if ($id < 1) {
                continue;
            }
            $path = [...$ancestors, $id];

            $row = [];
            foreach (self::NODE_STRUCTURE as $key) {
                if ($key !== 'children' && $key !== 'translations' && array_key_exists($key, $node)) {
                    $row[$key] = $node[$key];
                }
            }
            $row['id'] = $id;
            $row['level'] = $level;
            $row['path'] = implode(',', $path);
            $rows[] = $row;

            if (!isset($fields[$id])) {
                $fields[$id] = self::storedFieldsOf($node);
            }

            $children = $node['children'] ?? null;
            if (is_array($children)) {
                /** @var list<array<string, mixed>> $children */
                self::walk($children, $path, $level + 1, $rows, $fields);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, array<int, string>>
     */
    private static function storedFieldsOf(array $node): array
    {
        $fields = [];
        $translations = $node['translations'] ?? null;
        if (!is_array($translations)) {
            return $fields;
        }
        foreach ($translations as $languageId => $byField) {
            if (!is_array($byField)) {
                continue;
            }
            foreach ($byField as $field => $value) {
                $content = is_array($value) ? ($value['content'] ?? null) : $value;
                $fields[(string) $field][(int) $languageId] = is_scalar($content) ? (string) $content : '';
            }
        }

        return $fields;
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
