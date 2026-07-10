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

        $validScopes = ['content', 'common', 'web', 'mobile'];
        $checked = 0;
        $sawContent = false;
        $sawCommon = false;
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
                $sawWeb = $sawWeb || $scope === 'web';
                $checked++;
            }
        }

        self::assertGreaterThan(0, $checked, 'The style schema must expose fields with a scope.');
        self::assertTrue($sawContent, 'The catalog must expose translatable content fields (display=1).');
        self::assertTrue($sawCommon, 'The catalog must expose unprefixed common properties (behaviour, data, and cross-platform presentation; display=0).');
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

        // alert: dead size removed; close toggle is now cross-platform common.
        $alert = $this->fieldsOf($data, 'alert');
        self::assertArrayNotHasKey('size', $alert, 'alert.size must be removed (dead field).');
        self::assertArrayNotHasKey('web_with_close_button', $alert, 'alert.web_with_close_button must be renamed to closable.');
        self::assertSame('common', $alert['closable']['scope'] ?? null, 'alert.closable must be a common (cross-platform) field.');

        // badge: primary cross-platform variant + circle; web_variant escape hatch.
        $badge = $this->fieldsOf($data, 'badge');
        self::assertSame('common', $badge['variant']['scope'] ?? null, 'badge.variant must be common.');
        self::assertSame('common', $badge['circle']['scope'] ?? null, 'badge.circle must be common.');
        self::assertSame('web', $badge['web_variant']['scope'] ?? null, 'badge.web_variant remains a web-only escape hatch.');

        // avatar: name added for auto-initials.
        $avatar = $this->fieldsOf($data, 'avatar');
        self::assertSame('common', $avatar['name']['scope'] ?? null, 'avatar.name must be linked as a common field.');

        // button: web_variant -> variant (clean promotion) + url.
        $button = $this->fieldsOf($data, 'button');
        self::assertSame('common', $button['variant']['scope'] ?? null, 'button.variant must be common.');
        self::assertArrayNotHasKey('web_variant', $button, 'button.web_variant must be unlinked (promoted to variant).');
        self::assertSame('common', $button['url']['scope'] ?? null, 'button.url must be linked as a common field.');

        // login: optional subtitle + the already-present color.
        $login = $this->fieldsOf($data, 'login');
        self::assertSame('content', $login['subtitle']['scope'] ?? null, 'login.subtitle must be translatable content.');
        self::assertSame('common', $login['color']['scope'] ?? null, 'login.color must be common.');
    }

    public function testAccordionPolishWaveFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // accordion: web_accordion_variant promoted to the cross-platform accordion_variant.
        $accordion = $this->fieldsOf($data, 'accordion');
        self::assertArrayNotHasKey('web_accordion_variant', $accordion, 'accordion.web_accordion_variant must be renamed to accordion_variant.');
        self::assertSame('common', $accordion['accordion_variant']['scope'] ?? null, 'accordion.accordion_variant must be common (cross-platform).');

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
        self::assertSame('common', $card['border']['scope'] ?? null, 'card.border must be common (cross-platform).');
        self::assertArrayNotHasKey('web_border', $card, 'card.web_border must be unlinked (replaced by border).');

        // card-segment: shared border + web-only inherit padding.
        $cardSegment = $this->fieldsOf($data, 'card-segment');
        self::assertSame('common', $cardSegment['border']['scope'] ?? null, 'card-segment.border must be common.');
        self::assertSame('web', $cardSegment['web_segment_inherit_padding']['scope'] ?? null, 'card-segment.web_segment_inherit_padding must remain web-only.');

        // checkbox: label position promoted to shared.
        $checkbox = $this->fieldsOf($data, 'checkbox');
        self::assertSame('common', $checkbox['label_position']['scope'] ?? null, 'checkbox.label_position must be common.');
        self::assertArrayNotHasKey('web_checkbox_label_position', $checkbox, 'checkbox.web_checkbox_label_position must be renamed.');

        // chip: dedicated chip_variant (distinct from generic variant).
        $chip = $this->fieldsOf($data, 'chip');
        self::assertSame('common', $chip['chip_variant']['scope'] ?? null, 'chip.chip_variant must be common.');
        self::assertArrayNotHasKey('web_chip_variant', $chip, 'chip.web_chip_variant must be renamed to chip_variant.');

        // code: block toggle promoted to common behaviour + shared radius.
        $code = $this->fieldsOf($data, 'code');
        self::assertSame('common', $code['code_block']['scope'] ?? null, 'code.code_block must be a common (cross-platform) behaviour field.');
        self::assertSame('common', $code['radius']['scope'] ?? null, 'code.radius must be common.');
        self::assertArrayNotHasKey('web_code_block', $code, 'code.web_code_block must be renamed to code_block.');

        // title: colourable + semantic order/line-clamp promoted; text wrap stays web.
        $title = $this->fieldsOf($data, 'title');
        self::assertSame('common', $title['color']['scope'] ?? null, 'title.color must be common.');
        self::assertSame('common', $title['title_order']['scope'] ?? null, 'title.title_order must be a common semantic field.');
        self::assertSame('common', $title['line_clamp']['scope'] ?? null, 'title.line_clamp must be common.');
        self::assertSame('web', $title['web_title_text_wrap']['scope'] ?? null, 'title.web_title_text_wrap must remain web-only.');
        self::assertArrayNotHasKey('web_title_order', $title, 'title.web_title_order must be renamed.');
        self::assertArrayNotHasKey('web_title_line_clamp', $title, 'title.web_title_line_clamp must be renamed.');
    }

    /**
     * Regression for the 2026-06-22 layout cross-platform pass (migration
     * Version20260622063129): the layout styles promote width/height/cols/
     * grid-column/divider/space props to shared_*, add paper.title +
     * simple-grid responsive cols, and remove web_px/web_py/web_breakpoints.
     */
    public function testLayoutCrossPlatformPassFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // flex: every flexbox prop + size is cross-platform now.
        $flex = $this->fieldsOf($data, 'flex');
        self::assertSame('common', $flex['shared_width']['scope'] ?? null, 'flex.shared_width must be common.');
        self::assertSame('common', $flex['shared_height']['scope'] ?? null, 'flex.shared_height must be common.');
        self::assertSame('common', $flex['direction']['scope'] ?? null, 'flex.direction must be common.');
        self::assertSame('common', $flex['wrap']['scope'] ?? null, 'flex.wrap must be common.');
        self::assertArrayNotHasKey('web_width', $flex, 'flex.web_width must be relinked to shared_width.');
        self::assertArrayNotHasKey('web_height', $flex, 'flex.web_height must be relinked to shared_height.');

        // group: size shared; wrap/grow stay web-only.
        $group = $this->fieldsOf($data, 'group');
        self::assertSame('common', $group['shared_width']['scope'] ?? null, 'group.shared_width must be common.');
        self::assertSame('web', $group['web_group_wrap']['scope'] ?? null, 'group.web_group_wrap stays web-only.');

        // grid: cols promoted; restricted children unchanged.
        $grid = $this->fieldsOf($data, 'grid');
        self::assertSame('common', $grid['cols']['scope'] ?? null, 'grid.cols must be common.');
        self::assertArrayNotHasKey('web_cols', $grid, 'grid.web_cols must be renamed to cols.');

        // grid-column: span/offset/order/grow all cross-platform.
        $gridColumn = $this->fieldsOf($data, 'grid-column');
        self::assertSame('common', $gridColumn['grid_span']['scope'] ?? null, 'grid-column.grid_span must be common.');
        self::assertSame('common', $gridColumn['grid_offset']['scope'] ?? null, 'grid-column.grid_offset must be common.');
        self::assertSame('common', $gridColumn['grid_order']['scope'] ?? null, 'grid-column.grid_order must be common.');
        self::assertSame('common', $gridColumn['grid_grow']['scope'] ?? null, 'grid-column.grid_grow must be common.');
        self::assertArrayNotHasKey('web_grid_span', $gridColumn, 'grid-column.web_grid_span must be renamed.');

        // simple-grid: shared cols/gap + new web responsive overrides; old breakpoints gone.
        $simpleGrid = $this->fieldsOf($data, 'simple-grid');
        self::assertSame('common', $simpleGrid['cols']['scope'] ?? null, 'simple-grid.cols must be common.');
        self::assertSame('common', $simpleGrid['gap']['scope'] ?? null, 'simple-grid.gap must be common.');
        self::assertSame('common', $simpleGrid['vertical_spacing']['scope'] ?? null, 'simple-grid.vertical_spacing must be common.');
        self::assertSame('web', $simpleGrid['web_cols_sm']['scope'] ?? null, 'simple-grid.web_cols_sm must be a web-only responsive override.');
        self::assertSame('web', $simpleGrid['web_cols_md']['scope'] ?? null, 'simple-grid.web_cols_md must be web-only.');
        self::assertSame('web', $simpleGrid['web_cols_lg']['scope'] ?? null, 'simple-grid.web_cols_lg must be web-only.');
        self::assertArrayNotHasKey('web_breakpoints', $simpleGrid, 'simple-grid.web_breakpoints must be removed.');
        self::assertArrayNotHasKey('web_cols', $simpleGrid, 'simple-grid.web_cols must be renamed to cols.');

        // container: shared size; web padding removed in favour of spacing.
        $container = $this->fieldsOf($data, 'container');
        self::assertSame('common', $container['size']['scope'] ?? null, 'container.size must be common.');
        self::assertArrayNotHasKey('web_px', $container, 'container.web_px must be removed.');
        self::assertArrayNotHasKey('web_py', $container, 'container.web_py must be removed.');

        // paper: optional title content + shared border; web padding removed.
        $paper = $this->fieldsOf($data, 'paper');
        self::assertSame('content', $paper['title']['scope'] ?? null, 'paper.title must be translatable content.');
        self::assertSame('common', $paper['border']['scope'] ?? null, 'paper.border must be common.');
        self::assertArrayNotHasKey('web_border', $paper, 'paper.web_border must be replaced by border.');
        self::assertArrayNotHasKey('web_px', $paper, 'paper.web_px must be removed.');
        self::assertArrayNotHasKey('web_py', $paper, 'paper.web_py must be removed.');

        // center: size constraints cross-platform; inline stays web.
        $center = $this->fieldsOf($data, 'center');
        self::assertSame('common', $center['maw']['scope'] ?? null, 'center.maw must be common.');
        self::assertSame('common', $center['shared_width']['scope'] ?? null, 'center.shared_width must be common.');
        self::assertSame('web', $center['web_center_inline']['scope'] ?? null, 'center.web_center_inline stays web-only.');

        // space: direction folded into orientation.
        $space = $this->fieldsOf($data, 'space');
        self::assertSame('common', $space['orientation']['scope'] ?? null, 'space.orientation must be common.');
        self::assertArrayNotHasKey('web_space_direction', $space, 'space.web_space_direction must be removed.');

        // divider: variant/label-position/orientation all cross-platform.
        $divider = $this->fieldsOf($data, 'divider');
        self::assertSame('common', $divider['divider_variant']['scope'] ?? null, 'divider.divider_variant must be common.');
        self::assertSame('common', $divider['divider_label_position']['scope'] ?? null, 'divider.divider_label_position must be common.');
        self::assertSame('common', $divider['orientation']['scope'] ?? null, 'divider.orientation must be common.');
        self::assertArrayNotHasKey('web_divider_variant', $divider, 'divider.web_divider_variant must be renamed.');

        // scroll-area: height shared so mobile ScrollView can scroll.
        $scrollArea = $this->fieldsOf($data, 'scroll-area');
        self::assertSame('common', $scrollArea['shared_height']['scope'] ?? null, 'scroll-area.shared_height must be common.');
        self::assertSame('web', $scrollArea['web_scroll_area_type']['scope'] ?? null, 'scroll-area.web_scroll_area_type stays web-only.');
    }

    /**
     * Wave F — rich-text on the shared `text` content field. It first switched
     * `textarea` -> `markdown-inline` (migration Version20260622100253) and was
     * then promoted back to `textarea` — now a FULL rich-text editor (Enter,
     * headings, lists, links, alignment) — by migration Version20260629153921 so
     * admins can author nicely styled, interpolated content. The `text` and
     * `highlight` styles read the shared `text` field (`highlight` strips tags, so
     * the richer editor degrades gracefully there).
     */
    public function testTextWaveInlineRichTextFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        $text = $this->fieldsOf($data, 'text');
        self::assertSame('content', $text['text']['scope'] ?? null, 'text.text must be translatable content.');
        self::assertSame('textarea', $text['text']['type'] ?? null, 'text.text must use the rich-text editor (textarea).');

        $highlight = $this->fieldsOf($data, 'highlight');
        self::assertSame('content', $highlight['text']['scope'] ?? null, 'highlight.text must be translatable content.');
        self::assertSame('textarea', $highlight['text']['type'] ?? null, 'highlight.text must use the rich-text editor (textarea).');
    }

    /**
     * Wave G — typography / media / interactive field pass (migration
     * Version20260622110041): inline rich-text on list-item, a dedicated
     * blockquote_content (the generic `content` is unlinked), media playback
     * toggles, link/spoiler colour + link underline/icons, action-icon aria_label.
     * Ex-`shared_color` is now the unprefixed common `color`.
     */
    public function testTypographyMediaInteractiveWaveFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // list-item: inline rich-text content.
        $listItem = $this->fieldsOf($data, 'list-item');
        self::assertSame('content', $listItem['list_item_content']['scope'] ?? null, 'list-item.list_item_content must be translatable content.');
        self::assertSame('markdown-inline', $listItem['list_item_content']['type'] ?? null, 'list-item.list_item_content must use the inline rich-text editor.');

        // blockquote: dedicated content field promoted to the full rich-text
        // editor (textarea) by migration Version20260629153921; the generic
        // `content` is unlinked.
        $blockquote = $this->fieldsOf($data, 'blockquote');
        self::assertSame('content', $blockquote['blockquote_content']['scope'] ?? null, 'blockquote.blockquote_content must be translatable content.');
        self::assertSame('textarea', $blockquote['blockquote_content']['type'] ?? null, 'blockquote.blockquote_content must use the rich-text editor (textarea).');
        self::assertArrayNotHasKey('content', $blockquote, 'blockquote.content must be unlinked in favour of blockquote_content.');

        // image: fallback source (translatable asset picker).
        $image = $this->fieldsOf($data, 'image');
        self::assertSame('content', $image['fallback_src']['scope'] ?? null, 'image.fallback_src must be a translatable asset field.');

        // figure: optional built-in image + alt.
        $figure = $this->fieldsOf($data, 'figure');
        self::assertSame('content', $figure['img_src']['scope'] ?? null, 'figure.img_src must be a translatable asset field.');
        self::assertSame('content', $figure['alt']['scope'] ?? null, 'figure.alt must be translatable content.');

        // link: cross-platform colour + web underline/icons.
        $link = $this->fieldsOf($data, 'link');
        self::assertSame('common', $link['color']['scope'] ?? null, 'link.color must be common (ex-shared_color, unprefixed).');
        self::assertSame('web', $link['web_link_underline']['scope'] ?? null, 'link.web_link_underline must stay web-only.');
        self::assertSame('web', $link['web_left_icon']['scope'] ?? null, 'link.web_left_icon must stay web-only.');
        self::assertSame('web', $link['web_right_icon']['scope'] ?? null, 'link.web_right_icon must stay web-only.');

        // action-icon: accessible name for the icon-only control.
        $actionIcon = $this->fieldsOf($data, 'action-icon');
        self::assertSame('content', $actionIcon['aria_label']['scope'] ?? null, 'action-icon.aria_label must be translatable content.');

        // spoiler: show/hide control colour.
        $spoiler = $this->fieldsOf($data, 'spoiler');
        self::assertSame('common', $spoiler['color']['scope'] ?? null, 'spoiler.color must be common (ex-shared_color, unprefixed).');

        // video: poster (content) + cross-platform playback toggles.
        $video = $this->fieldsOf($data, 'video');
        self::assertSame('content', $video['poster_src']['scope'] ?? null, 'video.poster_src must be a translatable asset field.');
        self::assertSame('common', $video['has_controls']['scope'] ?? null, 'video.has_controls must be common.');
        self::assertSame('common', $video['media_loop']['scope'] ?? null, 'video.media_loop must be common.');
        self::assertSame('common', $video['media_autoplay']['scope'] ?? null, 'video.media_autoplay must be common.');
        self::assertSame('common', $video['media_muted']['scope'] ?? null, 'video.media_muted must be common.');

        // audio: cross-platform playback toggles.
        $audio = $this->fieldsOf($data, 'audio');
        self::assertSame('common', $audio['has_controls']['scope'] ?? null, 'audio.has_controls must be common.');
        self::assertSame('common', $audio['media_loop']['scope'] ?? null, 'audio.media_loop must be common.');
        self::assertSame('common', $audio['media_autoplay']['scope'] ?? null, 'audio.media_autoplay must be common.');
    }

    /**
     * Wave H — form / interactive capability pass (migration
     * Version20260622132034): new web_* Mantine knobs on number-input/color-input/
     * tabs/switch, the cross-platform `max_length` (ex-shared_max_length) + mobile
     * keyboard knobs on text-input/textarea, and progress-root `radius`
     * (ex-shared_radius). NB: `mobile_keyboard_type` is text-input only.
     */
    public function testFormInteractiveCapabilityWaveFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // number-input: currency / format affixes (web-only).
        $numberInput = $this->fieldsOf($data, 'number-input');
        self::assertSame('web', $numberInput['web_number_input_prefix']['scope'] ?? null, 'number-input.web_number_input_prefix must be web-only.');
        self::assertSame('web', $numberInput['web_number_input_suffix']['scope'] ?? null, 'number-input.web_number_input_suffix must be web-only.');
        self::assertSame('web', $numberInput['web_number_input_thousand_separator']['scope'] ?? null, 'number-input.web_number_input_thousand_separator must be web-only.');
        self::assertSame('web', $numberInput['web_number_input_allow_negative']['scope'] ?? null, 'number-input.web_number_input_allow_negative must be web-only.');
        self::assertSame('web', $numberInput['web_number_input_hide_controls']['scope'] ?? null, 'number-input.web_number_input_hide_controls must be web-only.');

        // color-input: picker affordances (web-only).
        $colorInput = $this->fieldsOf($data, 'color-input');
        self::assertSame('web', $colorInput['web_color_input_with_eye_dropper']['scope'] ?? null, 'color-input.web_color_input_with_eye_dropper must be web-only.');
        self::assertSame('web', $colorInput['web_color_input_disallow_input']['scope'] ?? null, 'color-input.web_color_input_disallow_input must be web-only.');
        self::assertSame('web', $colorInput['web_color_input_with_preview']['scope'] ?? null, 'color-input.web_color_input_with_preview must be web-only.');

        // tabs: tab-list layout knobs (web-only).
        $tabs = $this->fieldsOf($data, 'tabs');
        self::assertSame('web', $tabs['web_tabs_grow']['scope'] ?? null, 'tabs.web_tabs_grow must be web-only.');
        self::assertSame('web', $tabs['web_tabs_justify']['scope'] ?? null, 'tabs.web_tabs_justify must be web-only.');
        self::assertSame('web', $tabs['web_tabs_keep_mounted']['scope'] ?? null, 'tabs.web_tabs_keep_mounted must be web-only.');
        self::assertSame('web', $tabs['web_tabs_placement']['scope'] ?? null, 'tabs.web_tabs_placement must be web-only.');

        // switch: thumb knobs (web-only).
        $switch = $this->fieldsOf($data, 'switch');
        self::assertSame('web', $switch['web_switch_with_thumb_indicator']['scope'] ?? null, 'switch.web_switch_with_thumb_indicator must be web-only.');
        self::assertSame('web', $switch['web_switch_thumb_icon']['scope'] ?? null, 'switch.web_switch_thumb_icon must be web-only.');

        // text-input: cross-platform max length + mobile keyboard knobs.
        $textInput = $this->fieldsOf($data, 'text-input');
        self::assertSame('common', $textInput['max_length']['scope'] ?? null, 'text-input.max_length must be common (ex-shared_max_length, unprefixed).');
        self::assertSame('mobile', $textInput['mobile_keyboard_type']['scope'] ?? null, 'text-input.mobile_keyboard_type must be mobile-only.');
        self::assertSame('mobile', $textInput['mobile_auto_capitalize']['scope'] ?? null, 'text-input.mobile_auto_capitalize must be mobile-only.');
        self::assertSame('mobile', $textInput['mobile_secure_entry']['scope'] ?? null, 'text-input.mobile_secure_entry must be mobile-only.');

        // textarea: cross-platform max length + mobile auto-capitalize (no keyboard_type).
        $textarea = $this->fieldsOf($data, 'textarea');
        self::assertSame('common', $textarea['max_length']['scope'] ?? null, 'textarea.max_length must be common (ex-shared_max_length, unprefixed).');
        self::assertSame('mobile', $textarea['mobile_auto_capitalize']['scope'] ?? null, 'textarea.mobile_auto_capitalize must be mobile-only.');

        // progress-root: rounder bar via the cross-platform radius.
        $progressRoot = $this->fieldsOf($data, 'progress-root');
        self::assertSame('common', $progressRoot['radius']['scope'] ?? null, 'progress-root.radius must be common (ex-shared_radius, unprefixed).');
    }

    /**
     * Wave I — mobile-only HeroUI Native capability pass (migration
     * Version20260622145334): 7 mobile_* fields with no web/Mantine equivalent, so
     * they must be scoped `mobile` (the web renderer ignores them). Only the
     * mobile-only additions of this wave are asserted here.
     */
    public function testMobileOnlyCapabilityWaveFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // select + combobox share the native presentation field.
        $select = $this->fieldsOf($data, 'select');
        self::assertSame('mobile', $select['mobile_select_presentation']['scope'] ?? null, 'select.mobile_select_presentation must be mobile-only.');
        $combobox = $this->fieldsOf($data, 'combobox');
        self::assertSame('mobile', $combobox['mobile_select_presentation']['scope'] ?? null, 'combobox.mobile_select_presentation must be mobile-only.');

        // button native press feedback.
        $button = $this->fieldsOf($data, 'button');
        self::assertSame('mobile', $button['mobile_button_feedback']['scope'] ?? null, 'button.mobile_button_feedback must be mobile-only.');

        // slider / range-slider native value bubble.
        $slider = $this->fieldsOf($data, 'slider');
        self::assertSame('mobile', $slider['mobile_slider_show_value']['scope'] ?? null, 'slider.mobile_slider_show_value must be mobile-only.');
        $rangeSlider = $this->fieldsOf($data, 'range-slider');
        self::assertSame('mobile', $rangeSlider['mobile_range_slider_show_value']['scope'] ?? null, 'range-slider.mobile_range_slider_show_value must be mobile-only.');

        // text-input / textarea / checkbox native field variant.
        $textInput = $this->fieldsOf($data, 'text-input');
        self::assertSame('mobile', $textInput['mobile_input_variant']['scope'] ?? null, 'text-input.mobile_input_variant must be mobile-only.');
        $textarea = $this->fieldsOf($data, 'textarea');
        self::assertSame('mobile', $textarea['mobile_textarea_variant']['scope'] ?? null, 'textarea.mobile_textarea_variant must be mobile-only.');
        $checkbox = $this->fieldsOf($data, 'checkbox');
        self::assertSame('mobile', $checkbox['mobile_checkbox_variant']['scope'] ?? null, 'checkbox.mobile_checkbox_variant must be mobile-only.');
    }

    /**
     * Wave J — form / notification / entry-table authoring upgrade (migration
     * Version20260622161717, style renamed from show-user-input by
     * Version20260710093048): form-record/form-log gain title + description +
     * alert titles + confirm dialog; notification promotes its icon
     * (`shared_icon`, a reserved-name exception that stays prefixed but is
     * common scope) + close button (`with_close_button`, unprefixed) to
     * common; entry-table gains title + empty_text.
     */
    public function testFormNotificationAuthoringWaveFieldsAndScopes(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        foreach (['form-record', 'form-log'] as $formStyle) {
            $form = $this->fieldsOf($data, $formStyle);
            self::assertSame('content', $form['title']['scope'] ?? null, "{$formStyle}.title must be translatable content.");
            self::assertSame('content', $form['description']['scope'] ?? null, "{$formStyle}.description must be translatable content.");
            self::assertSame('content', $form['alert_success_title']['scope'] ?? null, "{$formStyle}.alert_success_title must be translatable content.");
            self::assertSame('content', $form['alert_error_title']['scope'] ?? null, "{$formStyle}.alert_error_title must be translatable content.");
            self::assertSame('content', $form['confirm_message']['scope'] ?? null, "{$formStyle}.confirm_message must be translatable content.");
            self::assertSame('common', $form['confirm_submit']['scope'] ?? null, "{$formStyle}.confirm_submit must be a common toggle.");
        }

        // notification: icon + close button promoted to cross-platform (common).
        // `shared_icon` keeps its prefix as a documented reserved-name exception
        // but is common scope; `with_close_button` dropped its shared_ prefix.
        $notification = $this->fieldsOf($data, 'notification');
        self::assertSame('common', $notification['shared_icon']['scope'] ?? null, 'notification.shared_icon must be common (reserved-name exception keeps its prefix).');
        self::assertSame('common', $notification['with_close_button']['scope'] ?? null, 'notification.with_close_button must be common (ex-shared_with_close_button, unprefixed).');
        self::assertArrayNotHasKey('web_notification_with_close_button', $notification, 'notification.web_notification_with_close_button must be promoted away.');

        // entry-table: optional heading + empty-state copy.
        $entryTable = $this->fieldsOf($data, 'entry-table');
        self::assertSame('content', $entryTable['title']['scope'] ?? null, 'entry-table.title must be translatable content.');
        self::assertSame('content', $entryTable['empty_text']['scope'] ?? null, 'entry-table.empty_text must be translatable content.');
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
