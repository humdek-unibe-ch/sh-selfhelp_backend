<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS;

use App\Exception\ServiceException;
use App\Service\CMS\DataColumnService;
use App\Service\CMS\Effective\FormInputValidator;
use App\Service\CMS\FormFieldKeyResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DataColumnServiceTest extends TestCase
{
    public function testGeneratedOptionLabelKeysFailValidationInsteadOfBeingSilentlyDropped(): void
    {
        $service = $this->service();

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Reserved runtime option label key "_category_label"');

        $service->assertValidFieldData([
            'category' => 'release',
            '_category_label' => 'Release',
        ]);
    }

    public function testGeneratedMultiOptionLabelKeyIsReserved(): void
    {
        $service = $this->service();

        self::assertTrue($service->isReservedKey('_tags_labels'));
        self::assertSame([], $service->filterFieldData(['_tags_labels' => 'Release, Notice']));
    }

    public function testContentRefIsReservedLikeTheOtherRowMetadataKeys(): void
    {
        $service = $this->service();

        // `content_ref` is the record's version reference, shown next to `record_id`; a form input or
        // plugin field of that name must never become a column, a form value or a stored cell.
        foreach (['record_id', 'content_ref'] as $key) {
            self::assertTrue($service->isReservedKey($key), $key . ' is reserved.');
            self::assertTrue((new FormInputValidator($service))->isProtectedKey($key), $key . ' is refused as a form value.');
            self::assertSame([], $service->filterFieldData([$key => 'x']), $key . ' is dropped from field data.');
        }
    }

    private function service(): DataColumnService
    {
        return new DataColumnService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(FormFieldKeyResolver::class),
        );
    }
}
