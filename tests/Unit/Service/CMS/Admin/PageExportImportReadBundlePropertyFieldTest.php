<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\Admin;

use App\Service\CMS\Admin\PageExportImportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Gallery CMS-in-CMS bundles store property fields as
 * `{ "all": { "content": "..." } }`. Import validation must read that shape
 * (not only the round-tripped `language_id: 1` list form).
 */
final class PageExportImportReadBundlePropertyFieldTest extends TestCase
{
    public function testReadsCompactAllLocaleFilter(): void
    {
        $filter = $this->invokeRead([
            'fields' => [
                'filter' => [
                    'all' => [
                        'content' => 'AND record_id = {{route.record_id}}',
                    ],
                ],
            ],
        ], 'filter');

        self::assertSame('AND record_id = {{route.record_id}}', $filter);
    }

    public function testReadsLanguageIdOneListShape(): void
    {
        $filter = $this->invokeRead([
            'fields' => [
                'filter' => [
                    [
                        'language_id' => 1,
                        'content' => 'AND record_id = {{route.record_id}}',
                    ],
                ],
            ],
        ], 'filter');

        self::assertSame('AND record_id = {{route.record_id}}', $filter);
    }

    public function testReadsLanguageCodeAllListShape(): void
    {
        $filter = $this->invokeRead([
            'fields' => [
                'filter' => [
                    [
                        'language_code' => 'all',
                        'content' => 'AND record_id = {{route.record_id}}',
                    ],
                ],
            ],
        ], 'filter');

        self::assertSame('AND record_id = {{route.record_id}}', $filter);
    }

    public function testReturnsEmptyWhenMissing(): void
    {
        self::assertSame('', $this->invokeRead(['fields' => []], 'filter'));
    }

    /**
     * @param array<string, mixed> $section
     */
    private function invokeRead(array $section, string $fieldName): string
    {
        $method = $this->readMethod();
        $service = (new ReflectionClass(PageExportImportService::class))
            ->newInstanceWithoutConstructor();

        $result = $method->invoke($service, $section, $fieldName);
        self::assertIsString($result);

        return $result;
    }

    private function readMethod(): ReflectionMethod
    {
        $method = new ReflectionMethod(PageExportImportService::class, 'readBundlePropertyField');
        $method->setAccessible(true);

        return $method;
    }
}
