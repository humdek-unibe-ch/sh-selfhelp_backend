<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Entity\SectionsFieldsTranslation;
use App\Repository\DataTableRepository;
use App\Service\CMS\FormFieldKeyResolver;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Carries the columns of a list through an export and an import.
 *
 * A list names its columns by the immutable key of the form input
 * (`section_<input id>`). The input ids differ on every install, so a bundle
 * names the columns by the input names instead: the export writes the names, the
 * import looks the keys of the new inputs up again. Which fields name columns
 * and where is {@see FormListColumnReferences}.
 *
 * Two details keep this honest:
 *  - the form that owns the columns is the form of the list's `data_table`,
 *    otherwise the form inside the list (the rule of {@see FormListGuard});
 *  - a name that is already a key of the form is left alone, so running the
 *    import step twice does the same as running it once.
 *
 * The form inside a list is read from the section hierarchy directly, never
 * from the cached page tree: an import calls this while it is still writing.
 */
class FormListPortability
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataTableRepository $dataTableRepository,
        private readonly FormFieldKeyResolver $keyResolver,
        private readonly FormListConfigReader $reader,
    ) {
    }

    /**
     * The exported fields of a list with the column keys replaced by input names.
     * Fields are returned unchanged when the form of the list cannot be found
     * (the list is bound to a table of another install).
     *
     * @param array<array-key, mixed> $fields exported fields: field name => locale => `{content, meta?}`
     * @return array<array-key, mixed>
     */
    public function toPortableFields(int $listSectionId, array $fields): array
    {
        $touched = array_values(array_intersect(FormListColumnReferences::FIELDS, array_map('strval', array_keys($fields))));
        if ($touched === []) {
            return $fields;
        }

        $ownerId = $this->ownerFormSectionId($listSectionId);
        if ($ownerId === null) {
            return $fields;
        }
        $this->keyResolver->forget((string) $ownerId);
        $keyToName = $this->keyResolver->getFieldKeyToName((string) $ownerId);
        if ($keyToName === []) {
            return $fields;
        }

        $map = static fn (string $key): string => $keyToName[$key] ?? $key;
        foreach ($touched as $name) {
            $entries = $fields[$name] ?? null;
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $locale => $entry) {
                if (!is_array($entry) || !is_string($entry['content'] ?? null)) {
                    continue;
                }
                $rewritten = FormListColumnReferences::rewrite($name, $entry['content'], $map);
                if ($rewritten !== null) {
                    $entry['content'] = $rewritten;
                    $entries[$locale] = $entry;
                }
            }
            $fields[$name] = $entries;
        }

        return $fields;
    }

    /**
     * Replace the input names in the stored fields of an imported list with the
     * keys of the inputs the import has created. A list whose form cannot be
     * found yet (its table token is not resolved) is left for a later call.
     *
     * @return bool whether a stored field changed
     */
    public function relink(int $listSectionId): bool
    {
        $ownerId = $this->ownerFormSectionId($listSectionId);
        if ($ownerId === null) {
            return false;
        }
        $this->keyResolver->forget((string) $ownerId);
        $nameToKey = $this->keyResolver->getNameToFieldKey((string) $ownerId);
        $keys = $this->keyResolver->getFieldKeyToName((string) $ownerId);
        if ($nameToKey === []) {
            return false;
        }

        $map = static fn (string $name): string => isset($keys[$name]) ? $name : ($nameToKey[$name] ?? $name);

        $changed = false;
        foreach ($this->translationsOf($listSectionId) as $translation) {
            $rewritten = FormListColumnReferences::rewrite(
                (string) $translation->getField()?->getName(),
                (string) $translation->getContent(),
                $map,
            );
            if ($rewritten !== null) {
                $translation->setContent($rewritten);
                $this->entityManager->persist($translation);
                $changed = true;
            }
        }
        if ($changed) {
            $this->entityManager->flush();
        }

        return $changed;
    }

    /**
     * The form section whose inputs are the columns of the list: the owner of the
     * table named by `data_table`, otherwise the form inside the list. Null when
     * `data_table` is not (yet) a table id of this install or no form is found.
     */
    private function ownerFormSectionId(int $listSectionId): ?int
    {
        $boundTable = trim($this->reader->rawFields($listSectionId)['data_table'][1] ?? '');
        if ($boundTable !== '') {
            if (!ctype_digit($boundTable)) {
                return null; // an owner token that is not resolved yet
            }
            $table = $this->dataTableRepository->find((int) $boundTable);

            return $table !== null && ctype_digit((string) $table->getName()) ? (int) $table->getName() : null;
        }

        return FormListLayout::inlineFormSectionId($this->descendantsOf($listSectionId), $listSectionId);
    }

    /**
     * The sections below a list in the shape {@see FormListLayout} reads:
     * `id`, `style_name`, `level`, `position` and `path` (section ids from the
     * list down to the row).
     *
     * @return list<array<string, mixed>>
     */
    private function descendantsOf(int $listSectionId): array
    {
        $sql = 'WITH RECURSIVE subtree (node_id, node_level, node_position, node_path) AS ('
            . ' SELECT h.id_child_section, 1, COALESCE(h.position, 0), CAST(CONCAT(h.id_parent_section, \',\', h.id_child_section) AS CHAR(2000))'
            . ' FROM rel_sections_hierarchy h WHERE h.id_parent_section = :root'
            . ' UNION ALL'
            . ' SELECT h.id_child_section, t.node_level + 1, COALESCE(h.position, 0), CAST(CONCAT(t.node_path, \',\', h.id_child_section) AS CHAR(2000))'
            . ' FROM rel_sections_hierarchy h JOIN subtree t ON h.id_parent_section = t.node_id'
            . ' WHERE t.node_level < 20'
            . ' ) SELECT t.node_id AS id, st.name AS style_name, t.node_level AS level, t.node_position AS position, t.node_path AS path'
            . ' FROM subtree t JOIN sections s ON s.id = t.node_id JOIN styles st ON st.id = s.id_styles';

        return $this->entityManager->getConnection()->fetchAllAssociative(
            $sql,
            ['root' => $listSectionId],
            ['root' => ParameterType::INTEGER],
        );
    }

    /**
     * @return list<SectionsFieldsTranslation>
     */
    private function translationsOf(int $listSectionId): array
    {
        /** @var list<SectionsFieldsTranslation> $translations */
        $translations = $this->entityManager->getRepository(SectionsFieldsTranslation::class)
            ->createQueryBuilder('t')
            ->join('t.field', 'f')
            ->where('t.section = :section')
            ->andWhere('f.name IN (:names)')
            ->setParameter('section', $listSectionId)
            ->setParameter('names', FormListColumnReferences::FIELDS)
            ->getQuery()
            ->getResult();

        return $translations;
    }
}
