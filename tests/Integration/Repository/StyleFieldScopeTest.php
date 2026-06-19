<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\StyleRepository;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for the CMS field-scope contract (mobile rendering plan,
 * section 6.4). The backend is the single source of truth for field scope:
 * {@see StyleRepository::deriveFieldScope()} classifies every field by its two
 * independent dimensions — translatability (`display`) and platform prefix — and
 * {@see StyleRepository::findAllStylesWithFields()} emits a `scope` per field so
 * the CMS groups by it instead of re-deriving from the field name or display.
 *
 * Proves:
 *   - translatable fields (display=1) are always `content`, even when prefixed;
 *   - property fields (display=0) split into common/shared/web/mobile by prefix,
 *     matching only at the start of the name;
 *   - every field in the established catalog emits a valid scope equal to the
 *     central derivation for its (name, display) pair;
 *   - the catalog actually contains content, common, shared and web fields
 *     (representative coverage of every non-mobile bucket present today).
 */
final class StyleFieldScopeTest extends QaKernelTestCase
{
    /** @var list<string> */
    private const VALID_SCOPES = ['content', 'common', 'shared', 'web', 'mobile'];

    public function testDeriveFieldScopeClassifiesBothDimensions(): void
    {
        // Dimension 1 — translatable content (display=1) is always content,
        // regardless of any platform prefix on the name.
        self::assertSame('content', StyleRepository::deriveFieldScope('label', 1));
        self::assertSame('content', StyleRepository::deriveFieldScope('title', 1));
        self::assertSame('content', StyleRepository::deriveFieldScope('text', 1));
        self::assertSame('content', StyleRepository::deriveFieldScope('shared_placeholder', 1));
        self::assertSame('content', StyleRepository::deriveFieldScope('web_html', 1));

        // Dimension 2 — property fields (display=0) split by prefix.
        // unprefixed behavior/data property -> common
        self::assertSame('common', StyleRepository::deriveFieldScope('is_required', 0));
        self::assertSame('common', StyleRepository::deriveFieldScope('value', 0));
        self::assertSame('common', StyleRepository::deriveFieldScope('name', 0));
        // shared portable semantics
        self::assertSame('shared', StyleRepository::deriveFieldScope('shared_size', 0));
        self::assertSame('shared', StyleRepository::deriveFieldScope('shared_spacing', 0));
        self::assertSame('shared', StyleRepository::deriveFieldScope('shared_text_align', 0));
        // web-only presentation
        self::assertSame('web', StyleRepository::deriveFieldScope('web_variant', 0));
        self::assertSame('web', StyleRepository::deriveFieldScope('web_spacing_margin', 0));
        // mobile-only presentation
        self::assertSame('mobile', StyleRepository::deriveFieldScope('mobile_keyboard_type', 0));
        self::assertSame('mobile', StyleRepository::deriveFieldScope('mobile_haptic_feedback', 0));
        // prefix must be at the start, never mid-name
        self::assertSame('common', StyleRepository::deriveFieldScope('is_web_thing', 0));
        self::assertSame('common', StyleRepository::deriveFieldScope('user_shared_note', 0));
        self::assertSame('common', StyleRepository::deriveFieldScope('row_mobile_count', 0));
    }

    public function testEveryCatalogFieldEmitsAScopeMatchingItsContract(): void
    {
        $repository = $this->service(StyleRepository::class);
        $schema = $repository->findAllStylesWithFields();
        self::assertNotEmpty($schema, 'The QA baseline must seed the style/field catalog.');

        $checked = 0;
        $sawContent = false;
        $sawCommon = false;
        $sawShared = false;
        $sawWeb = false;

        foreach ($schema as $styleName => $styleMeta) {
            $fields = self::asArray(self::asArray($styleMeta)['fields'] ?? []);
            foreach ($fields as $fieldName => $fieldMeta) {
                $meta = self::asArray($fieldMeta);
                self::assertArrayHasKey('scope', $meta, "Field '{$fieldName}' on style '{$styleName}' must emit a scope.");
                self::assertArrayHasKey('display', $meta, "Field '{$fieldName}' on style '{$styleName}' must emit display.");

                $scope = self::asString($meta['scope'] ?? null);
                self::assertContains($scope, self::VALID_SCOPES, "Field '{$fieldName}' on '{$styleName}' has an invalid scope '{$scope}'.");

                $name = (string) $fieldName;
                $display = self::asInt($meta['display']);
                $expected = StyleRepository::deriveFieldScope($name, $display);
                self::assertSame($expected, $scope, "Emitted scope for '{$name}' (display={$display}) must match the central derivation.");

                $sawContent = $sawContent || $scope === 'content';
                $sawCommon = $sawCommon || $scope === 'common';
                $sawShared = $sawShared || $scope === 'shared';
                $sawWeb = $sawWeb || $scope === 'web';
                $checked++;
            }
        }

        self::assertGreaterThan(0, $checked, 'The catalog must expose fields with a scope.');
        self::assertTrue($sawContent, 'The catalog must contain translatable content fields (display=1).');
        self::assertTrue($sawCommon, 'The catalog must contain unprefixed common behavior/data properties (display=0).');
        self::assertTrue($sawShared, 'The catalog must contain shared_* fields after the rename migration.');
        self::assertTrue($sawWeb, 'The catalog must contain web_* fields.');
    }
}
