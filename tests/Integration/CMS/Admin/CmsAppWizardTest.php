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
 * with the issue #30 p7 extension and the record edit mode pass:
 *
 * - `create_form` scaffolds a `form-record` in record edit mode
 *   (`load_record_from` + `own_entries_only=0`) that OWNS a fresh data table
 *   (one input per requested field) and closes its modal on save
 *   (`close_modal_on_save`). Opened without the route param it stays blank
 *   (create); opened with it, it prefills that record (edit).
 * - The ADMIN DETAIL page attaches the SAME shared form section as its edit
 *   form — no separate read-only `entry-record` copy.
 * - The CMS create form + CMS detail pages carry the `open_in_modal` page
 *   property so the web frontend opens them as modal overlays.
 * - The ADMIN list is an `entry-table` DATA TABLE (search/sort/paginate/
 *   delete) with an "Add new" (`add_url`) button and a per-row edit
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
            // The form page owns the table: a form-record holder in record edit
            // mode with an input, closing its modal on save.
            $formBody = $this->sectionsBody($byRole['form'], $admin);
            self::assertStringContainsString('form-record', $formBody, 'The form page must scaffold a form-record holder.');
            self::assertStringContainsString('text-input', $formBody, 'The form must carry a default text input.');
            self::assertStringContainsString('close_modal_on_save', $formBody, 'The create form must close its modal on save.');
            self::assertStringContainsString('load_record_from', $formBody, 'The form must bind its record context to the URL (record edit mode).');

            // The admin detail page attaches the SAME shared form section as
            // its edit form (record edit mode) instead of a read-only copy.
            $adminDetailBody = $this->sectionsBody($byRole['admin_detail'], $admin);
            self::assertStringContainsString('form-record', $adminDetailBody, 'The admin detail must be the shared edit form.');
            self::assertStringNotContainsString('entry-record', str_replace('form-record', '', $adminDetailBody), 'The admin detail must not scaffold a read-only entry-record.');

            // The CMS create form + detail open as modal overlays (web-only).
            self::assertSame('1', $this->pageProperty($byRole['form'], 'open_in_modal', $admin), 'The create form must open in a modal.');
            self::assertSame('1', $this->pageProperty($byRole['admin_detail'], 'open_in_modal', $admin), 'The admin detail must open in a modal.');

            // The admin list is an entry-table DATA TABLE with delete + the
            // add/open controls (NOT the old entry-record-delete clone list).
            $adminBody = $this->sectionsBody($byRole['admin_list'], $admin);
            self::assertStringContainsString('entry-table', $adminBody, 'The admin list must be an entry-table data table.');
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
     * its chosen style) into the new modal form, the admin list is an
     * entry-table with the add/edit controls, and the admin detail page
     * shares that same form section as its edit form.
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

            // The admin list is the entry-table with the add/edit controls.
            $adminBody = $this->sectionsBody($byRole['admin_list'], $admin);
            self::assertStringContainsString('entry-table', $adminBody);
            self::assertStringContainsString('add_url', $adminBody);
            self::assertStringContainsString('edit_url', $adminBody);

            // The admin detail page shares the form section: every requested
            // input is editable there (record edit mode), no read-only tokens.
            $detailBody = $this->sectionsBody($byRole['admin_detail'], $admin);
            self::assertStringContainsString('form-record', $detailBody, 'The admin detail must be the shared edit form.');
            self::assertStringContainsString('first_name', $detailBody);
            self::assertStringContainsString('age', $detailBody);
            self::assertStringContainsString('load_record_from', $detailBody, 'The edit form must load its record from the URL param.');
        } finally {
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    /**
     * The full CMS-in-CMS record edit loop on a scaffolded app: submitting on
     * the create page (no route param) creates a row and the form stays blank
     * on re-render (create mode); resolving the admin detail URL with the new
     * record id prefills the SHARED form section with that record's values
     * (record edit mode).
     */
    public function testScaffoldedAppCreateThenEditPrefillsAddressedRecord(): void
    {
        $admin = $this->loginAsQaAdmin();
        $base = 'qa-editmode-app';

        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/cms-app', [
            'base_name' => $base,
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($response, 201);

        $byRole = [];
        $pageIds = [];
        foreach (is_array($data['created'] ?? null) ? $data['created'] : [] as $entry) {
            if (!is_array($entry) || !is_int($entry['page_id'] ?? null)) {
                continue;
            }
            $pageIds[] = $entry['page_id'];
            $role = is_string($entry['role'] ?? null) ? $entry['role'] : '';
            $byRole[$role] = $entry['page_id'];
        }

        try {
            $formSectionId = $this->firstSectionIdByStyle($byRole['form'], 'form-record', $admin);
            self::assertGreaterThan(0, $formSectionId, 'The form page must carry the scaffolded form-record section.');

            // The admin detail page shares the SAME section (not a copy).
            $detailSectionId = $this->firstSectionIdByStyle($byRole['admin_detail'], 'form-record', $admin);
            self::assertSame($formSectionId, $detailSectionId, 'The admin detail must attach the shared form section.');

            // Create a row through the scaffolded create form.
            $submit = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => $byRole['form'],
                'section_id' => $formSectionId,
                'form_data' => ['title' => 'qa Alice'],
            ], $admin);
            $submitData = $this->assertEnvelopeSuccess($submit);
            self::assertIsInt($submitData['record_id'] ?? null);
            $recordId = $submitData['record_id'];

            // Create mode: re-rendering the create page (no route param) stays
            // BLANK even though the admin now owns a record.
            $createRender = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/by-keyword/cms-' . $base . '-form?preview=true',
                null,
                $admin
            );
            $createData = $this->assertEnvelopeSuccess($createRender);
            $createForm = $this->findSectionByStyleInPage($createData, 'form-record');
            self::assertNotNull($createForm);
            self::assertSame([], $createForm['section_data'] ?? null, 'With load_record_from set and no route param the form must stay blank (create mode).');

            // Edit mode: resolving the admin detail URL with the record id
            // prefills the shared section with that record's values.
            $editRender = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/cms/' . $base . '/' . $recordId) . '&preview=true',
                null,
                $admin
            );
            $editData = $this->assertEnvelopeSuccess($editRender);
            $editForm = $this->findSectionByStyleInPage($editData, 'form-record');
            self::assertNotNull($editForm, 'The admin detail render must contain the shared form section.');
            $sectionData = $editForm['section_data'] ?? null;
            self::assertIsArray($sectionData);
            self::assertNotSame([], $sectionData, 'The edit form must prefill the addressed record.');
            self::assertStringContainsString('qa Alice', (string) json_encode($sectionData), 'The prefilled record must carry the submitted value.');
        } finally {
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    /**
     * Resolve the first section id with the given style on a page (top level of
     * the admin sections tree, recursively).
     */
    private function firstSectionIdByStyle(int $pageId, string $styleName, string $token): int
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);

        $found = 0;
        $walk = static function (array $sections) use (&$walk, &$found, $styleName): void {
            foreach ($sections as $section) {
                if (!is_array($section) || $found !== 0) {
                    continue;
                }
                if (($section['style_name'] ?? null) === $styleName && is_int($section['id'] ?? null)) {
                    $found = $section['id'];
                    return;
                }
                if (is_array($section['children'] ?? null)) {
                    $walk($section['children']);
                }
            }
        };
        $walk(is_array($data['sections'] ?? null) ? $data['sections'] : []);

        return $found;
    }

    /**
     * Find the first section with the given style in a rendered page envelope
     * (`data.page.sections` or `data.sections`).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function findSectionByStyleInPage(array $data, string $styleName): ?array
    {
        $sections = is_array($data['page'] ?? null) && is_array($data['page']['sections'] ?? null)
            ? $data['page']['sections']
            : (is_array($data['sections'] ?? null) ? $data['sections'] : []);

        return $this->findSectionByStyle($sections, $styleName);
    }

    /**
     * @param array<int|string, mixed> $sections
     * @return array<string, mixed>|null
     */
    private function findSectionByStyle(array $sections, string $styleName): ?array
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['style_name'] ?? null) === $styleName) {
                /** @var array<string, mixed> $section */
                return $section;
            }
            if (is_array($section['children'] ?? null)) {
                $found = $this->findSectionByStyle($section['children'], $styleName);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
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
