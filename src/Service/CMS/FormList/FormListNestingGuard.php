<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Entity\Field;
use App\Entity\Section;
use App\Entity\Style;
use App\Exception\ServiceException;
use App\Repository\SectionRepository;
use App\Service\CMS\Common\StyleNames;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a form-style section (or, on a foreign-table list, an input) below an
 * `entry-form-list`. The list is already the form.
 */
class FormListNestingGuard extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SectionRepository $sectionRepository,
        private readonly FormListConfigReader $reader,
    ) {
    }

    /**
     * Refuse placing `$childStyleName` under `$parentSectionId` when that would
     * put a form (or an input on a foreign-table list) inside an entry list.
     *
     * @throws ServiceException with reason `form_inside_list`, or a
     *         {@see FormListConfigException} naming the field for a foreign-table input
     */
    public function assertChildAllowed(int $pageId, int $parentSectionId, string $childStyleName): void
    {
        $enclosing = $this->enclosingEntryFormList($pageId, $parentSectionId);
        if ($enclosing === null) {
            return;
        }

        if (in_array($childStyleName, StyleNames::FORM_STYLE_NAMES, true)) {
            throw new ServiceException(
                FormListLayout::FORM_INSIDE_LIST_MESSAGE,
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'form_inside_list'],
            );
        }

        if ($this->hasForeignDataTable($enclosing) && $this->styleAcceptsNameField($childStyleName)) {
            throw new FormListConfigException([
                ['field' => 'children', 'message' => 'A list that reads another table cannot hold form inputs; it only deletes and runs quick actions.'],
            ]);
        }
    }

    /**
     * Refuse a bundle section tree that nests a form style under an entry list.
     *
     * @param array<array-key, mixed> $section
     * @return list<array{field: string, message: string}>
     */
    public function nestedFormProblems(array $section): array
    {
        $style = is_string($section['style_name'] ?? null) ? $section['style_name'] : '';
        $children = is_array($section['children'] ?? null) ? array_values($section['children']) : [];
        if ($style === StyleNames::STYLE_ENTRY_FORM_LIST) {
            return $this->nestedFormProblemsBelow($children);
        }

        $problems = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $problems = [...$problems, ...$this->nestedFormProblems($child)];
            }
        }

        return $problems;
    }

    /**
     * @param list<mixed> $children
     * @return list<array{field: string, message: string}>
     */
    private function nestedFormProblemsBelow(array $children): array
    {
        $problems = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $style = is_string($child['style_name'] ?? null) ? $child['style_name'] : '';
            if (in_array($style, StyleNames::FORM_STYLE_NAMES, true)) {
                $problems[] = ['field' => 'children', 'message' => FormListLayout::FORM_INSIDE_LIST_MESSAGE];
            }
            $grand = is_array($child['children'] ?? null) ? array_values($child['children']) : [];
            $problems = [...$problems, ...$this->nestedFormProblemsBelow($grand)];
        }

        return $problems;
    }

    /**
     * The nearest `entry-form-list` that contains `$sectionId` (or is it), or null.
     */
    public function enclosingEntryFormList(int $pageId, int $sectionId): ?Section
    {
        $rows = $this->sectionRepository->fetchSectionsHierarchicalByPageId($pageId);
        $path = [];
        foreach ($rows as $row) {
            if ($this->asInt($row['id'] ?? null) === $sectionId) {
                $path = FormListLayout::pathIds($row);
                break;
            }
        }
        if ($path === []) {
            // Parent may not yet appear as a child row when it is a page-root section.
            $section = $this->sectionRepository->find($sectionId);
            if ($section?->getStyle()?->getName() === StyleNames::STYLE_ENTRY_FORM_LIST) {
                return $section;
            }

            return null;
        }

        foreach (array_reverse($path) as $id) {
            $section = $this->sectionRepository->find($id);
            if ($section?->getStyle()?->getName() === StyleNames::STYLE_ENTRY_FORM_LIST) {
                return $section;
            }
        }

        return null;
    }

    public function hasForeignDataTable(Section $list): bool
    {
        $tableText = trim($this->reader->rawFields((int) $list->getId())['data_table'][1] ?? '');

        return ctype_digit($tableText) && (int) $tableText > 0;
    }

    /**
     * Whether any descendant of the list carries a non-empty `name` (an input).
     */
    public function hasInputDescendants(int $listSectionId): bool
    {
        $ids = $this->descendantIds($listSectionId);
        if ($ids === []) {
            return false;
        }

        $count = $this->asInt($this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM sections_fields_translation t'
            . ' JOIN fields f ON f.id = t.id_fields'
            . ' WHERE t.id_sections IN (' . implode(',', array_map('intval', $ids)) . ')'
            . ' AND f.name = \'name\' AND TRIM(t.content) <> \'\'',
        ));

        return $count > 0;
    }

    private function styleAcceptsNameField(string $styleName): bool
    {
        $style = $this->entityManager->getRepository(Style::class)->findOneBy(['name' => $styleName]);
        if (!$style instanceof Style) {
            return false;
        }
        $nameField = $this->entityManager->getRepository(Field::class)->findOneBy(['name' => 'name']);
        if (!$nameField instanceof Field) {
            return false;
        }

        return $this->asInt($this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM rel_fields_styles WHERE id_styles = :s AND id_fields = :f',
            ['s' => $style->getId(), 'f' => $nameField->getId()],
        )) > 0;
    }

    /**
     * @return list<int>
     */
    private function descendantIds(int $rootId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
            WITH RECURSIVE subtree AS (
                SELECT id_child_section AS id FROM rel_sections_hierarchy WHERE id_parent_section = :root
                UNION ALL
                SELECT h.id_child_section FROM rel_sections_hierarchy h
                INNER JOIN subtree s ON h.id_parent_section = s.id
            )
            SELECT id FROM subtree
            SQL,
            ['root' => $rootId],
        );

        return array_map(fn (array $row): int => $this->asInt($row['id'] ?? null), $rows);
    }
}
