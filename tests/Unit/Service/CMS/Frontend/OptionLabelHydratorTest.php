<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\Frontend;

use App\Service\CMS\CmsPreferenceService;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\CMS\Frontend\OptionLabelHydrator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OptionLabelHydratorTest extends TestCase
{
    public function testEveryOptionStyleUsesLanguageFallbackAndStyleMultiplicity(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ...$this->sectionFields(10, 'select', [
                    'name' => [1 => 'category'],
                    'options' => [1 => '[{"value":"release"}]'],
                    'option_labels' => [3 => '{"release":"Release"}'],
                ]),
                ...$this->sectionFields(11, 'select', [
                    'name' => [1 => 'tags'],
                    'options' => [1 => '[{"value":"release"},{"value":"notice"}]'],
                    'option_labels' => [2 => '{"release":"Freigabe","notice":"Hinweis"}'],
                    'is_multiple' => [1 => '1'],
                ]),
                ...$this->sectionFields(12, 'radio', [
                    'name' => [1 => 'channel'],
                    'radio_options' => [1 => '[{"value":"notice","text":"Legacy notice"}]'],
                ]),
                ...$this->sectionFields(13, 'combobox', [
                    'name' => [1 => 'topics'],
                    'combobox_options' => [1 => '[{"value":"feature"},{"value":"notice"}]'],
                    'option_labels' => [3 => '{"feature":"Feature","notice":"Notice"}'],
                    'web_combobox_multi_select' => [1 => 'true'],
                ]),
                ...$this->sectionFields(14, 'segmented-control', [
                    'name' => [1 => 'view'],
                    'segmented_control_data' => [1 => '[{"value":"feature"}]'],
                    'option_labels' => [2 => '{"feature":"Funktion"}'],
                ]),
            ]);

        $preferences = $this->createStub(CmsPreferenceService::class);
        $preferences->method('getDefaultLanguageId')->willReturn(2);
        $fieldKeys = $this->createStub(FormFieldKeyResolver::class);
        $fieldKeys->method('getNameToFieldKey')->willReturn([]);
        $hydrator = new OptionLabelHydrator($connection, $preferences, $fieldKeys);

        self::assertSame([[
            'category' => 'release',
            'tags' => 'release,notice',
            'channel' => 'notice',
            'topics' => 'feature,notice',
            'view' => 'feature',
            '_category_label' => 'Release',
            '_tags_labels' => 'Freigabe, Hinweis',
            '_channel_label' => 'Legacy notice',
            '_topics_labels' => 'Feature, Notice',
            '_view_label' => 'Funktion',
        ]], $hydrator->hydrate([[
            'category' => 'release',
            'tags' => 'release,notice',
            'channel' => 'notice',
            'topics' => 'feature,notice',
            'view' => 'feature',
        ]], '42', 3));
    }

    public function testExistingRuntimeKeyCollisionFailsLoudly(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($this->sectionFields(10, 'select', [
            'name' => [1 => 'category'],
            'options' => [1 => '[{"value":"release"}]'],
        ]));
        $preferences = $this->createStub(CmsPreferenceService::class);
        $fieldKeys = $this->createStub(FormFieldKeyResolver::class);
        $fieldKeys->method('getNameToFieldKey')->willReturn([]);
        $hydrator = new OptionLabelHydrator($connection, $preferences, $fieldKeys);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('collides with reserved runtime option label');

        $hydrator->hydrate([[
            'category' => 'release',
            '_category_label' => 'User value',
        ]], '42', 3);
    }

    /**
     * @param array<string, array<int, string>> $fields
     * @return list<array{section_id: int, style_name: string, field_name: string, language_id: int, content: string}>
     */
    private function sectionFields(int $sectionId, string $styleName, array $fields): array
    {
        $rows = [];
        foreach ($fields as $fieldName => $translations) {
            foreach ($translations as $languageId => $content) {
                $rows[] = [
                    'section_id' => $sectionId,
                    'style_name' => $styleName,
                    'field_name' => $fieldName,
                    'language_id' => $languageId,
                    'content' => $content,
                ];
            }
        }

        return $rows;
    }
}
