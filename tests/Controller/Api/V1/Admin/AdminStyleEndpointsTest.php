<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Repository\StyleRepository;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage for the two {@see \App\Controller\Api\V1\Admin\AdminStyleController}
 * endpoints not exercised by the legacy StyleControllerTest:
 *
 *   - `admin_styles_schema_get` GET /admin/styles/schema (admin.access) — the
 *     style/field schema consumed by import validation + frontend codegen.
 *   - `admin_ai_section_prompt_template_get` GET /admin/ai/section-prompt-template
 *     (admin.page.export) — renders the AI prompt as text/markdown (NOT an
 *     envelope), so its success path is asserted with the raw client.
 */
#[Group('security')]
final class AdminStyleEndpointsTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testGetStylesSchemaReturnsTheStyleFieldCatalog(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // The payload is keyed by style name -> { fields, ... }. We assert the
        // contract shape directly rather than the published JSON schema because
        // of a KNOWN, pre-existing response-vs-schema drift: styles with no
        // fields serialise `fields` as an empty array `[]` while
        // responses/style/stylesSchema declares `fields` as an object. Asserting
        // the schema here would lock in a fix that changes the public response
        // shape (`[]` -> `{}`); that belongs to the Phase 10 schema-drift work,
        // not this read-only coverage slice.
        self::assertNotEmpty($data, 'Style schema catalog must not be empty.');
        $first = $data[array_key_first($data)];
        self::assertIsArray($first, 'Each style schema entry must be an object.');
        self::assertArrayHasKey('fields', $first, 'Each style schema entry exposes its fields.');
    }

    public function testGetStylesSchemaEmitsFieldScopeForEveryField(): void
    {
        // Mobile rendering plan section 6.4: every field carries a backend-derived
        // `scope` computed from two dimensions — translatability (display) and the
        // platform prefix. The CMS groups by this value, so a missing/wrong scope
        // is a contract break.
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        $validScopes = ['content', 'common', 'shared', 'web', 'mobile'];
        $checked = 0;
        $sawContent = false;
        $sawCommon = false;
        $sawShared = false;
        $sawWeb = false;
        foreach ($data as $styleName => $styleMeta) {
            if (!is_array($styleMeta) || !isset($styleMeta['fields']) || !is_array($styleMeta['fields'])) {
                continue;
            }
            foreach ($styleMeta['fields'] as $fieldName => $fieldMeta) {
                if (!is_array($fieldMeta)) {
                    continue;
                }
                self::assertArrayHasKey('scope', $fieldMeta, "Field '{$fieldName}' on style '{$styleName}' must expose a scope.");
                self::assertArrayHasKey('display', $fieldMeta, "Field '{$fieldName}' on style '{$styleName}' must expose display.");
                $scope = $fieldMeta['scope'];
                self::assertContains($scope, $validScopes, "Field '{$fieldName}' on '{$styleName}' has an invalid scope.");

                $name = (string) $fieldName;
                $display = self::asInt($fieldMeta['display']);
                $expected = StyleRepository::deriveFieldScope($name, $display);
                self::assertSame($expected, $scope, "Emitted scope for field '{$name}' (display={$display}) must match the central derivation.");

                $sawContent = $sawContent || $scope === 'content';
                $sawCommon = $sawCommon || $scope === 'common';
                $sawShared = $sawShared || $scope === 'shared';
                $sawWeb = $sawWeb || $scope === 'web';
                $checked++;
            }
        }

        self::assertGreaterThan(0, $checked, 'The style schema must expose fields with a scope.');
        self::assertTrue($sawContent, 'The catalog must expose translatable content fields (display=1).');
        self::assertTrue($sawCommon, 'The catalog must expose unprefixed common properties (display=0).');
        self::assertTrue($sawShared, 'The catalog must expose shared_* fields after the rename migration.');
        self::assertTrue($sawWeb, 'The catalog must expose web_* fields.');
    }

    /**
     * Regression for the 2026-06-19 style polish wave (migration
     * Version20260619131830): alert/badge/avatar/button/login field + scope
     * changes must be reflected by the live schema endpoint.
     */
    public function testStylePolishWaveFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // alert: dead shared_size removed; close toggle is now cross-platform common.
        $alert = $this->fieldsOf($data, 'alert');
        self::assertArrayNotHasKey('shared_size', $alert, 'alert.shared_size must be removed (dead field).');
        self::assertArrayNotHasKey('web_with_close_button', $alert, 'alert.web_with_close_button must be renamed to closable.');
        self::assertSame('common', $alert['closable']['scope'] ?? null, 'alert.closable must be a common (cross-platform) field.');

        // badge: primary cross-platform shared_variant + circle; web_variant escape hatch.
        $badge = $this->fieldsOf($data, 'badge');
        self::assertSame('shared', $badge['shared_variant']['scope'] ?? null, 'badge.shared_variant must be shared.');
        self::assertSame('common', $badge['circle']['scope'] ?? null, 'badge.circle must be common.');
        self::assertSame('web', $badge['web_variant']['scope'] ?? null, 'badge.web_variant remains a web-only escape hatch.');

        // avatar: name added for auto-initials.
        $avatar = $this->fieldsOf($data, 'avatar');
        self::assertSame('common', $avatar['name']['scope'] ?? null, 'avatar.name must be linked as a common field.');

        // button: web_variant -> shared_variant (clean promotion) + url.
        $button = $this->fieldsOf($data, 'button');
        self::assertSame('shared', $button['shared_variant']['scope'] ?? null, 'button.shared_variant must be shared.');
        self::assertArrayNotHasKey('web_variant', $button, 'button.web_variant must be unlinked (promoted to shared_variant).');
        self::assertSame('common', $button['url']['scope'] ?? null, 'button.url must be linked as a common field.');

        // login: optional subtitle + the already-present shared_color.
        $login = $this->fieldsOf($data, 'login');
        self::assertSame('content', $login['subtitle']['scope'] ?? null, 'login.subtitle must be translatable content.');
        self::assertSame('shared', $login['shared_color']['scope'] ?? null, 'login.shared_color must be shared.');
    }

    public function testAccordionPolishWaveFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // accordion: web_accordion_variant promoted to the cross-platform shared_accordion_variant.
        $accordion = $this->fieldsOf($data, 'accordion');
        self::assertArrayNotHasKey('web_accordion_variant', $accordion, 'accordion.web_accordion_variant must be renamed to shared_accordion_variant.');
        self::assertSame('shared', $accordion['shared_accordion_variant']['scope'] ?? null, 'accordion.shared_accordion_variant must be shared (cross-platform).');

        // accordion-item: optional translatable description subtitle.
        $accordionItem = $this->fieldsOf($data, 'accordion-item');
        self::assertSame('content', $accordionItem['description']['scope'] ?? null, 'accordion-item.description must be translatable content.');
    }

    /**
     * Regression for the 2026-06-19 style polish wave (migration
     * Version20260619191224): card/card-segment/checkbox/chip/code/title field +
     * scope changes must be reflected by the live schema endpoint.
     */
    public function testCardFamilyAndTypographyPolishWaveFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // card: optional auto-styled title + image content; border promoted to shared.
        $card = $this->fieldsOf($data, 'card');
        self::assertSame('content', $card['title']['scope'] ?? null, 'card.title must be translatable content.');
        self::assertSame('content', $card['img_src']['scope'] ?? null, 'card.img_src must be translatable content (asset picker).');
        self::assertSame('shared', $card['shared_border']['scope'] ?? null, 'card.shared_border must be shared (cross-platform).');
        self::assertArrayNotHasKey('web_border', $card, 'card.web_border must be unlinked (replaced by shared_border).');

        // card-segment: shared border + web-only inherit padding.
        $cardSegment = $this->fieldsOf($data, 'card-segment');
        self::assertSame('shared', $cardSegment['shared_border']['scope'] ?? null, 'card-segment.shared_border must be shared.');
        self::assertSame('web', $cardSegment['web_segment_inherit_padding']['scope'] ?? null, 'card-segment.web_segment_inherit_padding must remain web-only.');

        // checkbox: label position promoted to shared.
        $checkbox = $this->fieldsOf($data, 'checkbox');
        self::assertSame('shared', $checkbox['shared_label_position']['scope'] ?? null, 'checkbox.shared_label_position must be shared.');
        self::assertArrayNotHasKey('web_checkbox_label_position', $checkbox, 'checkbox.web_checkbox_label_position must be renamed.');

        // chip: dedicated shared_chip_variant (distinct from generic shared_variant).
        $chip = $this->fieldsOf($data, 'chip');
        self::assertSame('shared', $chip['shared_chip_variant']['scope'] ?? null, 'chip.shared_chip_variant must be shared.');
        self::assertArrayNotHasKey('web_chip_variant', $chip, 'chip.web_chip_variant must be renamed to shared_chip_variant.');

        // code: block toggle promoted to common behaviour + shared radius.
        $code = $this->fieldsOf($data, 'code');
        self::assertSame('common', $code['code_block']['scope'] ?? null, 'code.code_block must be a common (cross-platform) behaviour field.');
        self::assertSame('shared', $code['shared_radius']['scope'] ?? null, 'code.shared_radius must be shared.');
        self::assertArrayNotHasKey('web_code_block', $code, 'code.web_code_block must be renamed to code_block.');

        // title: colourable + semantic order/line-clamp promoted; text wrap stays web.
        $title = $this->fieldsOf($data, 'title');
        self::assertSame('shared', $title['shared_color']['scope'] ?? null, 'title.shared_color must be shared.');
        self::assertSame('common', $title['title_order']['scope'] ?? null, 'title.title_order must be a common semantic field.');
        self::assertSame('shared', $title['shared_line_clamp']['scope'] ?? null, 'title.shared_line_clamp must be shared.');
        self::assertSame('web', $title['web_title_text_wrap']['scope'] ?? null, 'title.web_title_text_wrap must remain web-only.');
        self::assertArrayNotHasKey('web_title_order', $title, 'title.web_title_order must be renamed.');
        self::assertArrayNotHasKey('web_title_line_clamp', $title, 'title.web_title_line_clamp must be renamed.');
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    private function fieldsOf(array $schema, string $style): array
    {
        self::assertArrayHasKey($style, $schema, "Style '{$style}' must exist in the schema.");
        $entry = $schema[$style];
        self::assertIsArray($entry);
        self::assertArrayHasKey('fields', $entry, "Style '{$style}' must expose fields.");
        $fields = $entry['fields'];
        self::assertIsArray($fields, "Style '{$style}' fields must be an object.");

        /** @var array<string, array<string, mixed>> $fields */
        return $fields;
    }

    public function testStylesSchemaEnforcesTheAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/styles/schema');
    }

    public function testGetSectionPromptTemplateReturnsMarkdown(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/ai/section-prompt-template',
            [],
            [],
            $this->authHeaders($this->loginAsQaAdmin()),
        );
        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Prompt template must render for admin.');
        self::assertStringContainsString(
            'text/markdown',
            (string) $response->headers->get('Content-Type'),
            'Prompt template must be served as markdown.',
        );
        self::assertNotEmpty((string) $response->getContent(), 'Rendered prompt template must not be empty.');
    }

    public function testSectionPromptTemplateIsForbiddenForNonAdmins(): void
    {
        // Negative half: denials are JSON envelopes regardless of the markdown
        // success representation, so the matrix helper applies cleanly here.
        $this->assertForbiddenForNonAdmins('GET', '/cms-api/v1/admin/ai/section-prompt-template');
    }
}
