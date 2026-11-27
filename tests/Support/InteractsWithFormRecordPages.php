<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Style;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page with one `form-record` section that a participant fills in, built and used through the real
 * endpoints like the form data golden workflow: the admin creates the page and the section (which creates the
 * form's data table, named after the section id), publishes it, and the participant submits the form.
 * Use inside a {@see QaWebTestCase}.
 */
trait InteractsWithFormRecordPages
{
    /**
     * Create the page and its form-record section. Creating the page seeds the subject group's select right,
     * which is all a participant needs to work with a form on it.
     *
     * @param string $keyword a `qa_` keyword; the page url is derived from it
     * @return array{0: int, 1: int} page id, section id (the data table is named after the section id)
     */
    private function createFormRecordPage(string $admin, string $keyword): array
    {
        $page = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => $keyword,
                'pageAccessTypeCode' => 'web',
                'url' => '/' . str_replace('_', '-', $keyword),
            ], $admin),
            Response::HTTP_CREATED,
        );
        $pageId = self::asInt($page['id'] ?? null);

        $style = $this->service(EntityManagerInterface::class)->getRepository(Style::class)->findOneBy(['name' => 'form-record']);
        self::assertInstanceOf(Style::class, $style, 'The seeded form-record style must exist.');
        $section = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId), [
                'styleId' => (int) $style->getId(),
                'position' => 10,
                'name' => $keyword . '_section',
            ], $admin),
            Response::HTTP_CREATED,
        );

        return [$pageId, self::asInt($section['id'] ?? null)];
    }

    /** Publish the page as it is now; returns the number of the published version. */
    private function publishFormPage(string $admin, int $pageId): int
    {
        $published = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', sprintf('/cms-api/v1/admin/pages/%d/versions/publish', $pageId), [], $admin),
            Response::HTTP_CREATED,
        );

        return self::asInt($published['version_number'] ?? null);
    }

    /**
     * One form submit with a single field; returns the record id.
     *
     * @param bool $preview an editor's preview of the draft instead of the page a participant is served
     */
    private function submitForm(string $token, int $pageId, int $sectionId, string $value, bool $preview = false): int
    {
        $body = ['page_id' => $pageId, 'section_id' => $sectionId, 'form_data' => ['qa_field' => $value]];
        if ($preview) {
            $body['preview'] = true;
        }
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', $body, $token));

        return self::asInt($data['record_id'] ?? null);
    }
}
