<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end coverage for the "Create list + detail pages" CMS-in-CMS wizard
 * with the issue #30 p7 extension and the "open in modal + list presentation"
 * pass:
 *
 * - `create_form` scaffolds an append `form-log` that OWNS a fresh data table
 *   (one input per requested field) and closes its modal on save
 *   (`close_modal_on_save`).
 * - The CMS create form + CMS detail pages carry the `open_in_modal` page
 *   property so the web frontend opens them as modal overlays.
 * - The ADMIN list is a `show-user-input` DATA TABLE (search/sort/paginate/
 *   delete) with an "Add new" (`add_url`) button and a per-row open/view
 *   (`edit_url`) action; the PUBLIC list stays an `entry-list` of cards with an
 *   "Open" link to the shareable (non-modal) public detail page.
 *
 * Pages are deleted in a finally block; DAMA rolls back the surrounding
 * transaction.
 */
#[Group('golden')]
final class CmsAppWizardTest extends QaWebTestCase
{
    private const BASE = 'qa-team-wizard';
    private const MULTI_BASE = 'qa-team-wizard-multi';

    public function testWizardScaffoldsModalFormAndAdminDataTableAndPublicCards(): void
    {
        $admin = $this->loginAsQaAdmin();

        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/cms-app', [
            'base_name' => self::BASE,
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'form_field_name' => 'title',
        ], $admin);

        $data = $this->assertEnvelopeSuccess($response, 201);
        $created = $data['created'] ?? null;
        self::assertIsArray($created);
        self::assertCount(5, $created, 'create_form adds a form page on top of the two list/detail pairs.');

        $byRole = [];
        $pageIds = [];
        foreach ($created as $entry) {
            if (!is_array($entry) || !is_int($entry['page_id'] ?? null)) {
                continue;
            }
            $pageIds[] = $entry['page_id'];
            $role = is_string($entry['role'] ?? null) ? $entry['role'] : '';
            $byRole[$role] = $entry['page_id'];
        }

        self::assertArrayHasKey('form', $byRole, 'The wizard must create a form page.');
        self::assertArrayHasKey('admin_list', $byRole, 'The wizard must create an admin list page.');
        self::assertArrayHasKey('admin_detail', $byRole, 'The wizard must create an admin detail page.');
        self::assertArrayHasKey('public_list', $byRole, 'The wizard must create a public list page.');
        self::assertArrayHasKey('public_detail', $byRole, 'The wizard must create a public detail page.');

        try {
            // The form page owns the table: a form-log holder with an input that
            // closes its modal on save.
            $formBody = $this->sectionsBody($byRole['form'], $admin);
            self::assertStringContainsString('form-log', $formBody, 'The form page must scaffold a form-log holder.');
            self::assertStringContainsString('text-input', $formBody, 'The form must carry a default text input.');
            self::assertStringContainsString('close_modal_on_save', $formBody, 'The create form must close its modal on save.');

            // The CMS create form + detail open as modal overlays (web-only).
            self::assertSame('1', $this->pageProperty($byRole['form'], 'open_in_modal', $admin), 'The create form must open in a modal.');
            self::assertSame('1', $this->pageProperty($byRole['admin_detail'], 'open_in_modal', $admin), 'The admin detail must open in a modal.');

            // The admin list is a show-user-input DATA TABLE with delete + the
            // add/open controls (NOT the old entry-record-delete clone list).
            $adminBody = $this->sectionsBody($byRole['admin_list'], $admin);
            self::assertStringContainsString('show-user-input', $adminBody, 'The admin list must be a show-user-input data table.');
            self::assertStringNotContainsString('entry-record-delete', $adminBody, 'The admin list must no longer clone an entry-record-delete row.');
            self::assertStringContainsString('delete_entry', $adminBody, 'The admin table must carry an inline delete.');
            self::assertStringContainsString('add_url', $adminBody, 'The admin table must link "Add new" to the create form.');
            self::assertStringContainsString('edit_url', $adminBody, 'The admin table must carry a per-row open action.');

            // The public list stays an entry-list of cards linking to the
            // shareable (non-modal) public detail page.
            $publicBody = $this->sectionsBody($byRole['public_list'], $admin);
            self::assertStringContainsString('entry-list', $publicBody, 'The public list must be an entry-list of cards.');
            self::assertStringContainsString('{{record_id}}', $publicBody, 'The public list cards interpolate the record id.');
            self::assertNull($this->pageProperty($byRole['public_detail'], 'open_in_modal', $admin), 'The public detail must stay a normal, shareable page.');
        } finally {
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    /**
     * The multi-field builder scaffolds one input per requested field (each with
     * its chosen style) into the new modal form, the admin list is a
     * show-user-input table with the add/open controls, and the detail page
     * carries an editable interpolation block listing every field.
     */
    public function testWizardMultiFieldBuilderScaffoldsEachInputAndDetailInterpolation(): void
    {
        $admin = $this->loginAsQaAdmin();

        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/cms-app', [
            'base_name' => self::MULTI_BASE,
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
            'form_fields' => [
                ['name' => 'first_name', 'style' => 'text-input', 'label' => 'First name'],
                ['name' => 'bio', 'style' => 'textarea'],
                ['name' => 'age', 'style' => 'number-input', 'label' => 'Age'],
            ],
        ], $admin);

        $data = $this->assertEnvelopeSuccess($response, 201);
        $created = $data['created'] ?? null;
        self::assertIsArray($created);

        $byRole = [];
        $pageIds = [];
        foreach ($created as $entry) {
            if (!is_array($entry) || !is_int($entry['page_id'] ?? null)) {
                continue;
            }
            $pageIds[] = $entry['page_id'];
            $role = is_string($entry['role'] ?? null) ? $entry['role'] : '';
            $byRole[$role] = $entry['page_id'];
        }

        self::assertArrayHasKey('form', $byRole);
        self::assertArrayHasKey('admin_list', $byRole);
        self::assertArrayHasKey('admin_detail', $byRole);

        try {
            // Each requested field becomes an input of the requested style + name.
            $formBody = $this->sectionsBody($byRole['form'], $admin);
            self::assertStringContainsString('textarea', $formBody, 'A textarea field must be scaffolded.');
            self::assertStringContainsString('number-input', $formBody, 'A number field must be scaffolded.');
            self::assertStringContainsString('first_name', $formBody);
            self::assertStringContainsString('bio', $formBody);
            self::assertStringContainsString('age', $formBody);
            self::assertSame('1', $this->pageProperty($byRole['form'], 'open_in_modal', $admin));

            // The admin list is the show-user-input table with the add/open controls.
            $adminBody = $this->sectionsBody($byRole['admin_list'], $admin);
            self::assertStringContainsString('show-user-input', $adminBody);
            self::assertStringContainsString('add_url', $adminBody);
            self::assertStringContainsString('edit_url', $adminBody);

            // The detail page carries the editable interpolation block (all field tokens).
            $detailBody = $this->sectionsBody($byRole['admin_detail'], $admin);
            self::assertStringContainsString('{{first_name}}', $detailBody, 'The detail must interpolate every field.');
            self::assertStringContainsString('{{age}}', $detailBody);
        } finally {
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    /**
     * Fetch the raw sections JSON body for a page (string match is enough for
     * style-name / field-name presence assertions).
     */
    private function sectionsBody(int $pageId, string $token): string
    {
        // The page sections list is structural (style names, hierarchy); the
        // scaffolded FIELD values live behind the per-section detail endpoint,
        // so fetch every section's detail and concatenate all bodies.
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);
        $body = (string) $this->client->getResponse()->getContent();

        $sectionIds = [];
        $collect = static function (array $sections) use (&$collect, &$sectionIds): void {
            foreach ($sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                if (is_int($section['id'] ?? null)) {
                    $sectionIds[] = $section['id'];
                }
                if (is_array($section['children'] ?? null)) {
                    $collect($section['children']);
                }
            }
        };
        $collect(is_array($data['sections'] ?? null) ? $data['sections'] : []);

        foreach ($sectionIds as $sectionId) {
            $detail = $this->jsonRequest(
                'GET',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                null,
                $token
            );
            $this->assertEnvelopeSuccess($detail);
            $body .= (string) $this->client->getResponse()->getContent();
        }

        return $body;
    }

    /**
     * Resolve a page PROPERTY field value (display=0, stored under language 1)
     * from the admin "get page with fields" response. Returns null when the page
     * carries no value for the field.
     */
    private function pageProperty(int $pageId, string $fieldName, string $token): ?string
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        foreach ($fields as $field) {
            if (!is_array($field) || ($field['name'] ?? null) !== $fieldName) {
                continue;
            }
            $translations = is_array($field['translations'] ?? null) ? $field['translations'] : [];
            $first = $translations[0] ?? null;
            $content = is_array($first) ? ($first['content'] ?? null) : null;

            return is_string($content) ? $content : null;
        }

        return null;
    }
}
