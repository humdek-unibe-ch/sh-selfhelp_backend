<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Navigation;

use App\Entity\Language;
use App\Entity\NavigationMenuItem;
use App\Entity\NavigationMenuItemTranslation;
use App\Repository\NavigationMenuItemTranslationRepository;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Syncs and resolves translatable labels for group/external navigation menu items.
 */
final class NavigationMenuItemTranslationSupport
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NavigationMenuItemTranslationRepository $translationRepository,
    ) {
    }

    /**
     * Replace an item's translations from an admin/import payload. A
     * `translations` array is a full replace including the presentation
     * fields (`description`, `aria_label`); a bare `label` upserts the
     * default-language label only.
     *
     * @param array<string, mixed> $data
     */
    public function syncMenuItemTranslations(NavigationMenuItem $item, array $data, int $defaultLanguageId): void
    {
        $typeCode = $item->getItemType()?->getLookupCode() ?? '';
        if (!$this->isTranslatableItemType($typeCode)) {
            return;
        }

        if (array_key_exists('translations', $data) && is_array($data['translations'])) {
            /** @var list<array<string, mixed>> $translationRows */
            $translationRows = array_values($data['translations']);
            $this->replaceTranslationsFromPayload($item, $translationRows, $defaultLanguageId);

            return;
        }

        if (array_key_exists('label', $data)) {
            $label = $data['label'];
            if (is_string($label) && $label !== '') {
                $this->upsertTranslation($item, $defaultLanguageId, $label);
                $item->setLabel($label);
            } elseif ($label === null || $label === '') {
                $item->setLabel(null);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $translations
     */
    private function replaceTranslationsFromPayload(
        NavigationMenuItem $item,
        array $translations,
        int $defaultLanguageId,
    ): void {
        $itemId = $item->getId();
        if ($itemId !== null) {
            $existing = $this->translationRepository->findByMenuItemId($itemId);
            foreach ($existing as $row) {
                $this->entityManager->remove($row);
            }
        }

        $defaultLabel = null;
        /** @var array<int, true> $seenLanguageIds */
        $seenLanguageIds = [];
        foreach ($translations as $row) {
            $languageId = $row['language_id'] ?? $row['languageId'] ?? null;
            $label = $row['label'] ?? null;
            if (!is_int($languageId) && !is_numeric($languageId)) {
                continue;
            }
            if (!is_string($label) || trim($label) === '') {
                continue;
            }
            $languageId = (int) $languageId;
            if (isset($seenLanguageIds[$languageId])) {
                continue;
            }
            $seenLanguageIds[$languageId] = true;
            $trimmed = trim($label);
            $this->upsertTranslation($item, $languageId, $trimmed, $row);
            if ($languageId === $defaultLanguageId) {
                $defaultLabel = $trimmed;
            }
        }

        if ($defaultLabel === null && $itemId !== null) {
            $labels = $this->translationRepository->findLabelsByMenuItemIds([$itemId]);
            $byLang = $labels[$itemId] ?? [];
            $defaultLabel = $byLang[$defaultLanguageId] ?? (reset($byLang) ?: null);
        }

        $item->setLabel($defaultLabel);
    }

    /**
     * @param array<string, mixed>|null $presentation
     */
    public function upsertTranslation(NavigationMenuItem $item, int $languageId, string $label, ?array $presentation = null): void
    {
        $description = null;
        $ariaLabel = null;
        if ($presentation !== null) {
            $desc = $presentation['description'] ?? null;
            $description = is_string($desc) && $desc !== '' ? $desc : null;
            $aria = $presentation['aria_label'] ?? null;
            $ariaLabel = is_string($aria) && $aria !== '' ? $aria : null;
        }

        $itemId = $item->getId();
        if ($itemId === null) {
            $translation = (new NavigationMenuItemTranslation())
                ->setMenuItem($item)
                ->setLanguage($this->entityManager->getReference(Language::class, $languageId))
                ->setLabel($label)
                ->setDescription($description)
                ->setAriaLabel($ariaLabel);
            $this->entityManager->persist($translation);

            return;
        }

        $existing = $this->translationRepository->findOneBy([
            'menuItem' => $itemId,
            'language' => $languageId,
        ]);
        if ($existing instanceof NavigationMenuItemTranslation) {
            $existing->setLabel($label);
            if ($presentation !== null) {
                $existing->setDescription($description);
                $existing->setAriaLabel($ariaLabel);
            }

            return;
        }

        $translation = (new NavigationMenuItemTranslation())
            ->setMenuItem($item)
            ->setLanguage($this->entityManager->getReference(Language::class, $languageId))
            ->setLabel($label)
            ->setDescription($description)
            ->setAriaLabel($ariaLabel);
        $this->entityManager->persist($translation);
    }

    /**
     * @param array<int, string> $labelsByLanguage
     */
    public function resolveLabel(
        array $labelsByLanguage,
        ?string $storedLabel,
        int $languageId,
        int $defaultLanguageId,
    ): ?string {
        if (isset($labelsByLanguage[$languageId]) && $labelsByLanguage[$languageId] !== '') {
            return $labelsByLanguage[$languageId];
        }
        if ($languageId !== $defaultLanguageId
            && isset($labelsByLanguage[$defaultLanguageId])
            && $labelsByLanguage[$defaultLanguageId] !== '') {
            return $labelsByLanguage[$defaultLanguageId];
        }
        if ($storedLabel !== null && $storedLabel !== '') {
            return $storedLabel;
        }

        return null;
    }

    /**
     * Resolve one translated text field with requested → default language fallback.
     *
     * @param array<int, ?string> $valuesByLanguage
     */
    public function resolveText(array $valuesByLanguage, int $languageId, int $defaultLanguageId): ?string
    {
        $requested = $valuesByLanguage[$languageId] ?? null;
        if ($requested !== null && $requested !== '') {
            return $requested;
        }
        $fallback = $valuesByLanguage[$defaultLanguageId] ?? null;
        if ($fallback !== null && $fallback !== '') {
            return $fallback;
        }

        return null;
    }

    public function isTranslatableItemType(string $typeCode): bool
    {
        return $typeCode === LookupService::NAVIGATION_ITEM_TYPE_GROUP
            || $typeCode === LookupService::NAVIGATION_ITEM_TYPE_EXTERNAL_URL;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function assertTranslatableLabelsPresent(array $data, string $typeCode, int $defaultLanguageId): void
    {
        if (!$this->isTranslatableItemType($typeCode)) {
            return;
        }

        if (array_key_exists('translations', $data) && is_array($data['translations'])) {
            foreach ($data['translations'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = $row['label'] ?? null;
                if (is_string($label) && trim($label) !== '') {
                    return;
                }
            }
        }

        $label = $data['label'] ?? null;
        if (is_string($label) && trim($label) !== '') {
            return;
        }

        throw new \InvalidArgumentException('Group headings and external links require at least one translated label.');
    }
}
