<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\DataTable;
use App\Entity\Section;
use App\Repository\DataTableRepository;
use App\Service\ACL\ACLService;
use App\Service\CMS\DataQuery\DataQueryException;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Authors write input names (`status`) in legacy filters, sorts and selected
 * columns; {@see DataTableRepository} rewrites them to field keys (`section_N`)
 * the same way FormList / fixed_filter already do.
 */
final class DataTableRepositoryNameRemapTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private DataTableRepository $repository;
    private FormFieldKeyResolver $keys;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->service(EntityManagerInterface::class);
        $this->repository = $this->service(DataTableRepository::class);
        $this->keys = $this->service(FormFieldKeyResolver::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testFilterSortAndColumnsByInputNameMatchFieldKey(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_dt_remap', openAccess: true);
        $statusInput = $this->addInput($formSection, 'qa_dt_remap_status', 'status', 20);
        $titleInput = $this->addInput($formSection, 'qa_dt_remap_title', 'title', 30);
        $this->pages->invalidatePageScopedCaches();
        $this->keys->forget((string) $formSection->getId());

        $admin = $this->loginAsQaAdmin();
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['status' => 'open', 'title' => 'Open task'],
        ], $admin));
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['status' => 'done', 'title' => 'Done task'],
        ], $admin));

        $tableName = (string) $formSection->getId();
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => $tableName]);
        self::assertInstanceOf(DataTable::class, $table);
        $tableId = (int) $table->getId();

        $statusKey = 'section_' . (int) $statusInput->getId();
        $titleKey = 'section_' . (int) $titleInput->getId();
        $map = $this->keys->getNameToFieldKey($tableName);
        self::assertSame($statusKey, $map['status'] ?? null);
        self::assertSame($titleKey, $map['title'] ?? null);

        $byName = $this->repository->getDataTableWithFilter(
            $tableId,
            0,
            "status = 'open' ORDER BY title ASC",
            true,
            1,
            'UTC',
            'status,title',
        );
        $byKey = $this->repository->getDataTableWithFilter(
            $tableId,
            0,
            sprintf("%s = 'open' ORDER BY %s ASC", $statusKey, $titleKey),
            true,
            1,
            'UTC',
            $statusKey . ',' . $titleKey,
        );

        self::assertNotEmpty($byName);
        self::assertSame(
            (string) json_encode($byName),
            (string) json_encode($byKey),
            'Input names and field keys must read the same rows.',
        );
        self::assertStringContainsString('Open task', (string) json_encode($byName));
        self::assertStringNotContainsString('Done task', (string) json_encode($byName));
    }

    public function testUnknownInputNameIsLeftUnchangedAndMatchesNothing(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_dt_remap_unk', openAccess: true);
        $this->addInput($formSection, 'qa_dt_remap_unk_title', 'title', 20);
        $this->pages->invalidatePageScopedCaches();
        $this->keys->forget((string) $formSection->getId());

        $admin = $this->loginAsQaAdmin();
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['title' => 'Only title'],
        ], $admin));

        $tableName = (string) $formSection->getId();
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => $tableName]);
        self::assertInstanceOf(DataTable::class, $table);
        $tableId = (int) $table->getId();

        $all = $this->repository->getDataTableWithFilter($tableId, 0, '', true);
        self::assertNotEmpty($all);

        // Remap leaves unknown names alone; the engine then refuses the column
        // (it was not rewritten to a real field key).
        $this->expectException(DataQueryException::class);
        $this->expectExceptionMessage('Unknown column "no_such_input"');
        $this->repository->getDataTableWithFilter($tableId, 0, "no_such_input = 'x'", true);
    }

    private function addInput(Section $form, string $sectionName, string $inputName, int $position): Section
    {
        $input = $this->pages->createSection($sectionName, 'text-input');
        $this->pages->linkSectionToParent($form, $input, $position);
        $this->pages->setSectionField($input, 'name', $inputName);

        return $input;
    }
}
