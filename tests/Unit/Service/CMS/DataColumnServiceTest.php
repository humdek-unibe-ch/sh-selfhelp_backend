<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS;

use App\Exception\ServiceException;
use App\Service\CMS\DataColumnService;
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

    private function service(): DataColumnService
    {
        return new DataColumnService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(FormFieldKeyResolver::class),
        );
    }
}
