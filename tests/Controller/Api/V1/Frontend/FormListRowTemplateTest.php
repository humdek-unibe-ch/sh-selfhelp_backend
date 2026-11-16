<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Section;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * A data view with the layout `custom` draws every record with the sections the
 * author placed inside it. The server renders that template once per record of
 * the page (the same way an `entry-list` clones its children), so search, sorting,
 * filters and paging stay the visitor's and every section feature stays the
 * author's. Every rule below protects either of them: tokens resolve per record,
 * only the columns the list shows reach the layout, and a read-only view never
 * holds a form or a list.
 */
#[PhpUnitGroup('form-list')]
final class FormListRowTemplateTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    /** The ids of the content languages: de-CH and en-GB. */
    private const DE = 2;
    private const EN = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testEveryRecordOfThePageIsDrawnFromTheTemplateWithItsOwnValues(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_basic');
        $this->recordTemplate($view, 'qa_rt_basic');

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));

        $list = $this->at($data, 'list');
        self::assertSame('custom', $list['layout']);
        self::assertTrue($list['has_template']);
        self::assertSame('grid gap-4', $list['row_css']);

        self::assertSame(['Event 01', 'Event 03', 'Event 05'], $this->titles($data), 'The server still orders and pages the records.');
        $cities = ['Bern', 'Basel', 'Zurich']; // events 1, 3 and 5
        $recordIds = [];
        foreach ($this->listOf($data, 'rows') as $index => $row) {
            $recordId = self::coerceInt($row['record_id']);
            $recordIds[] = $recordId;

            $sections = $this->listOf($row, 'sections');
            self::assertCount(1, $sections, 'One card per record.');
            self::assertSame('container', $sections[0]['style_name']);

            $children = $this->listOf($sections[0], 'children');
            self::assertSame(['title', 'text', 'button'], array_column($children, 'style_name'));
            self::assertSame(sprintf('Event %02d', 2 * $index + 1), $this->contentOf($children[0], 'content'), 'The record\'s own value.');
            self::assertSame(
                sprintf('%s | #%d | row %d', $cities[$index], $recordId, $index),
                $this->contentOf($children[1], 'text'),
                'The city of the record, its number and its place on the page.',
            );
            self::assertSame('/events/' . $recordId, $this->contentOf($children[2], 'url'), 'A link built from the record number.');
        }
        self::assertCount(3, array_unique($recordIds));
    }

    public function testOnlyWhatTheListShowsReachesTheLayout(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_private');
        $this->recordTemplate($view, 'qa_rt_private', withHiddenColumn: true);

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));

        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('internal note', $encoded, 'The `note` column is not one of the list\'s columns: it never leaves the server, template or not.');
        foreach ($this->listOf($data, 'rows') as $row) {
            self::assertArrayNotHasKey('id_users', $row);
            self::assertStringNotContainsString('user_name', json_encode($row['sections'] ?? [], JSON_THROW_ON_ERROR));
        }
    }

    public function testTheTemplateFollowsTheVisitorsLanguage(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_language');
        $this->recordTemplate($view, 'qa_rt_language');
        $title = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_rt_language_title']);
        self::assertInstanceOf(Section::class, $title);
        $this->pages->setSectionField($title, 'content', 'Anlass: {{title}}', self::DE);

        $german = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::DE));
        $english = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));

        self::assertSame('Anlass: Event 01', $this->firstTitleOf($german));
        self::assertSame('Event 01', $this->firstTitleOf($english));
    }

    public function testASearchAndAFilterNarrowTheRecordsThatAreDrawn(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_query', ['page_size' => '10']);
        $this->recordTemplate($view, 'qa_rt_query');

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN . '&list.f.city=Basel'));

        $rows = $this->listOf($data, 'rows');
        self::assertCount(5, $rows, 'The five published events of Basel.');
        self::assertSame(['Basel'], $this->distinct($data, 'city'));
        foreach ($rows as $row) {
            self::assertCount(1, $this->listOf($row, 'sections'), 'Every record that is on the page is drawn.');
        }
        self::assertSame(5, $this->at($data, 'pagination')['total']);
    }

    public function testACustomLayoutWithoutSectionsHasNoTemplateAndTheRowsCarryNone(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_empty');

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));

        self::assertFalse($this->at($data, 'list')['has_template'], 'The client falls back to cards.');
        foreach ($this->listOf($data, 'rows') as $row) {
            self::assertSame([], $row['sections']);
        }
    }

    public function testAReadOnlyViewNeverHoldsAFormOrAList(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_forbidden');
        $card = $this->recordTemplate($view, 'qa_rt_forbidden');
        $nested = $this->pages->createSection('qa_rt_forbidden_nested_list', 'entry-form-list');
        $this->pages->linkSectionToParent($card, $nested, 40);
        $form = $this->pages->createSection('qa_rt_forbidden_form', 'form-record');
        $this->pages->linkSectionToParent($view, $form, 20);
        $other = $this->pages->createSection('qa_rt_forbidden_other_view', 'data-view');
        $this->pages->linkSectionToParent($view, $other, 30);

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));

        $encoded = json_encode($this->listOf($data, 'rows'), JSON_THROW_ON_ERROR);
        foreach (['entry-form-list', 'form-record', 'data-view'] as $style) {
            self::assertStringNotContainsString('"style_name":"' . $style . '"', $encoded, $style . ' is left out of the row template.');
        }
        foreach ($this->listOf($data, 'rows') as $row) {
            $sections = $this->listOf($row, 'sections');
            self::assertSame(['container'], array_column($sections, 'style_name'), 'The form and the view next to the card are left out; the card stays.');
            self::assertSame(['title', 'text', 'button'], array_column($this->listOf($sections[0], 'children'), 'style_name'), 'So is the list inside the card.');
        }
    }

    // -- conditions that read the record, the route and the supporting values ----------------

    public function testASectionOfTheLayoutCanDependOnTheRecordItDraws(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_cond');
        $card = $this->recordTemplate($view, 'qa_rt_cond');
        $this->conditionalText($card, 'qa_rt_cond_roomy', 'Roomy: {{seats}} seats', '{">":[{"var":"{{seats}}"},10]}', 40);
        $this->conditionalText($card, 'qa_rt_cond_basel', 'Held in Basel', '{"==":["{{city}}","Basel"]}', 50);
        $this->conditionalText($card, 'qa_rt_cond_away', 'Not in Bern', '{"!=":[{"var":"{{city}}"},"Bern"]}', 60);
        $this->conditionalText($card, 'qa_rt_cond_both', 'Roomy and not Zurich', '{"and":[{">":[{"var":"{{seats}}"},10]},{"!=":["{{city}}","Zurich"]}]}', 70);
        $this->conditionalText($card, 'qa_rt_cond_private', 'Reads a private column', '{"==":[{"var":"{{note}}"},"internal note"]}', 80);
        $this->conditionalText($card, 'qa_rt_cond_system', 'Still reads the visitor', '{"==":[{"var":"language"},3]}', 90);
        $this->conditionalText($card, 'qa_rt_cond_text', 'Stored as text', (string) json_encode('{"==":["{{city}}","Basel"]}', JSON_THROW_ON_ERROR), 100);
        // Next to the card, a section of the template of its own with its own condition.
        $this->conditionalText($view, 'qa_rt_cond_banner', 'Roomy banner', '{">":[{"var":"{{seats}}"},10]}', 30);

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));

        self::assertSame(['Event 01', 'Event 03', 'Event 05'], $this->titles($data));
        // What each record draws is written once, in the fixture the web and the mobile app draw from as well.
        $fixture = $this->conditionFixture();
        self::assertSame(
            $fixture['drawn'],
            array_map(fn (array $row): array => $this->textsOf($row), $this->listOf($data, 'rows')),
            'Every record draws exactly the sections its own values allow; nothing of another record decides.',
        );
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        foreach ($fixture['neverDrawn'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, 'A column the list does not show is not there for a condition either.');
        }
    }

    /**
     * The cross-client fixture (`tests/fixtures/form-list/row-conditions.json`, the same bytes in the web and the mobile
     * tests): the answer the server gives for the scenario above, trimmed to what a client reads. It must be a valid state
     * and must say what the server really draws.
     */
    public function testTheFixtureTheClientsDrawFromIsAValidStateAndSaysWhatTheServerDraws(): void
    {
        $fixture = $this->conditionFixture();
        $state = $fixture['state'];

        // Validated as decoded objects, the way the server's own answers are (an empty object stays an object).
        $raw = self::asObject(json_decode($this->conditionFixtureText(), false, 512, JSON_THROW_ON_ERROR));
        $envelope = (object) ['status' => 200, 'message' => 'OK', 'error' => null, 'logged_in' => false, 'meta' => (object) ['version' => 'v1', 'timestamp' => '2026-01-01T00:00:00+00:00'], 'data' => $raw->state];
        $errors = $this->service(JsonSchemaValidationService::class)->validate($envelope, 'responses/frontend/form_list_state');
        self::assertSame([], $errors, "The fixture is not a valid list state:\n" . implode("\n", $errors));

        $rows = $this->listOf($state, 'rows');
        self::assertSame($fixture['drawn'], array_map(fn (array $row): array => $this->textsOf($row), $rows), 'What the fixture shows is what it says it draws.');
        foreach ($rows as $row) {
            $encoded = json_encode($row['sections'] ?? [], JSON_THROW_ON_ERROR);
            foreach ($fixture['neverDrawn'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $encoded);
            }
        }
    }

    public function testAValueOfTheRecordNeverPicksAVariableOrChangesTheConditionItIsPartOf(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_hostile', ['page_size' => '10']);
        $tableId = $this->tableIdByName('qa_rt_hostile_table');
        // The city is the name of a system variable (the language of the visitor is 3), then a text that tries to close
        // the string of the condition and to add a clause that is always true.
        foreach (['Event 00a' => 'language', 'Event 00b' => 'Bern"},{"==":[1,1'] as $title => $city) {
            $this->grid->row($tableId, null, ['title' => $title, 'city' => $city, 'seats' => '3', 'starts' => '2026-02-01', 'published' => '1', 'note' => 'x']);
        }
        $card = $this->recordTemplate($view, 'qa_rt_hostile');
        $this->conditionalText($card, 'qa_rt_hostile_pick', 'City is the language', '{"==":[{"var":"{{city}}"},3]}', 40);
        $this->conditionalText($card, 'qa_rt_hostile_shape', 'City is Bern', '{"==":["{{city}}","Bern"]}', 50);
        $this->conditionalText($card, 'qa_rt_hostile_clause', 'City is not Bern', '{"!=":["{{city}}","Bern"]}', 60);
        $this->conditionalText($card, 'qa_rt_hostile_system', 'The visitor reads English', '{"==":[{"var":"language"},3]}', 70);

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));

        $byTitle = [];
        foreach ($this->listOf($data, 'rows') as $row) {
            $byTitle[$this->titles(['rows' => [$row]])[0]] = array_slice($this->textsOf($row), 1);
        }
        self::assertSame(['City is not Bern', 'The visitor reads English'], $byTitle['Event 00a'], 'The value "language" is a text to compare, not the variable of that name.');
        self::assertSame(['City is not Bern', 'The visitor reads English'], $byTitle['Event 00b'], 'A value with quotes and braces stays one text: the condition keeps its shape.');
        self::assertSame(['City is Bern', 'The visitor reads English'], $byTitle['Event 01'], 'The records next to it are not affected.');
    }

    public function testTheRouteOfThePageReachesTheLayoutAndIsOnlyBelievedWhenThePageHasSuchARoute(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_route', ['page_size' => '1']);
        $this->routeFor($page, '/qa-rt-route/{record_id}', ['record_id' => '\d+']);
        $card = $this->recordTemplate($view, 'qa_rt_route');
        $this->conditionalText($card, 'qa_rt_route_line', 'Route {{route.record_id}}', '', 40);
        $this->conditionalText($card, 'qa_rt_route_three', 'Route three', '{"==":["{{route.record_id}}","3"]}', 50);
        $this->conditionalText($card, 'qa_rt_route_other', 'Not route three', '{"!=":["{{route.record_id}}","3"]}', 60);

        $three = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3&route%5Brecord_id%5D=3'));
        self::assertSame(['Route 3', 'Route three'], array_slice($this->textsOf($this->listOf($three, 'rows')[0]), 1));
        $four = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3&route%5Brecord_id%5D=4'));
        self::assertSame(['Route 4', 'Not route three'], array_slice($this->textsOf($this->listOf($four, 'rows')[0]), 1));
        $none = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3'));
        self::assertSame(['Route ', 'Not route three'], array_slice($this->textsOf($this->listOf($none, 'rows')[0]), 1), 'No route, no route values.');

        foreach ([
            'route%5Brecord_id%5D=abc' => 'route_mismatch',
            'route%5Brecord_id%5D=3&route%5Bextra%5D=1' => 'route_mismatch',
            'route%5Bother%5D=3' => 'route_mismatch',
            'route%5Brecord_id%5D=3%2F4' => 'route_mismatch',
            'route=3' => 'invalid_route',
            'route%5B0%5D=3' => 'invalid_route',
        ] as $query => $reason) {
            $refused = $this->state($page, $view, 'language_id=3&' . $query);
            self::assertSame(400, $refused['status'] ?? null, $query);
            self::assertSame($reason, $this->at($refused, 'data')['reason'] ?? null, $query);
        }
    }

    public function testThePageCarriesTheFirstPaintOfALayoutThatReadsTheRoute(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_paint', ['page_size' => '1']);
        $this->routeFor($page, '/qa-rt-paint/{record_id}', ['record_id' => '\d+']);
        $card = $this->recordTemplate($view, 'qa_rt_paint');
        $this->conditionalText($card, 'qa_rt_paint_line', 'Route {{route.record_id}}', '', 40);
        $this->conditionalText($card, 'qa_rt_paint_three', 'Route three', '{"==":["{{route.record_id}}","3"]}', 50);

        $payload = $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-rt-paint/3') . '&language_id=3'));

        $section = $this->findSection($this->at($payload, 'page')['sections'] ?? [], (int) $view->getId());
        self::assertNotNull($section, 'The data view is on the page.');
        $rows = $this->listOf($this->at($section, 'list_state'), 'rows');
        self::assertSame(['Route 3', 'Route three'], array_slice($this->textsOf($rows[0]), 1), 'The page and the list state draw the layout from the same context.');
    }

    public function testNoValueOfARecordTakesTheRouteOrTheVisitorsIdentityOver(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_shadow', [
            'page_size' => '1',
            'list_columns' => json_encode([
                ['field' => 'title', 'label' => 'Title'],
                ['field' => 'route', 'label' => 'Route'],
                ['field' => 'system', 'label' => 'System'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->routeFor($page, '/qa-rt-shadow/{record_id}', ['record_id' => '\d+']);
        $tableId = $this->tableIdByName('qa_rt_shadow_table');
        foreach (['route', 'system'] as $column) {
            $this->grid->column($tableId, $column, 'text');
        }
        $this->grid->row($tableId, null, [
            'title' => 'Event 00s', 'city' => 'Bern', 'seats' => '1', 'starts' => '2026-02-01', 'published' => '1', 'note' => 'x',
            'route' => '999', 'system' => 'sys',
        ]);
        $card = $this->recordTemplate($view, 'qa_rt_shadow');
        $this->conditionalText($card, 'qa_rt_shadow_line', 'R={{route.record_id}}|S={{system.language}}|I={{_index}}', '', 40);
        $this->conditionalText($card, 'qa_rt_shadow_cond', 'Route three', '{"==":["{{route.record_id}}","3"]}', 50);
        $this->conditionalText($card, 'qa_rt_shadow_lang', 'English', '{"==":["{{system.language}}",3]}', 60);
        $this->conditionalText($card, 'qa_rt_shadow_route', 'The record replaced the route', '{"==":["{{route}}","999"]}', 70);
        $this->conditionalText($card, 'qa_rt_shadow_system', 'The record replaced the system values', '{"==":["{{system}}","sys"]}', 80);

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3&route%5Brecord_id%5D=3'));

        self::assertSame(['Event 00s'], $this->titles($data));
        self::assertSame(
            ['R=3|S=3|I=0', 'Route three', 'English'],
            array_slice($this->textsOf($this->listOf($data, 'rows')[0]), 1),
            'The route, the system values and the place on the page are the server\'s, whatever the columns of a record are called.',
        );

        $undeclared = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3'));
        self::assertSame(
            ['R=|S=3|I=0', 'English'],
            array_slice($this->textsOf($this->listOf($undeclared, 'rows')[0]), 1),
            'Without a route of the request the record\'s own `route` and `system` columns still do not become the scopes of that name.',
        );
    }

    public function testSupportingValuesBoundToTheRouteAndToTheRecordStayWithTheirOwnCard(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_support');
        $this->routeFor($page, '/qa-rt-support/{record_id}', ['record_id' => '\d+']);
        $table = 'qa_rt_support_table';
        // The view as a whole may read one record of the table that the route names ...
        $view->setDataConfig(json_encode([['scope' => 'picked', 'table' => $table, 'retrieve' => 'first', 'current_user' => false, 'filter' => 'AND record_id = {{route.record_id}}']], JSON_THROW_ON_ERROR));
        $card = $this->recordTemplate($view, 'qa_rt_support');
        $this->conditionalText($card, 'qa_rt_support_picked', 'Picked: {{picked.title}}', '', 40);
        $own = $this->conditionalText($card, 'qa_rt_support_own', 'Own: {{same.title}}', '', 50);
        // ... and a section of the layout may read the record of the card it is drawn for.
        $own->setDataConfig(json_encode([['scope' => 'same', 'table' => $table, 'retrieve' => 'first', 'current_user' => false, 'filter' => 'AND record_id = {{record_id}}']], JSON_THROW_ON_ERROR));
        // A section of the layout may also bind its own values to the route.
        $bound = $this->conditionalText($card, 'qa_rt_support_bound', 'Bound: {{bound.title}}', '', 55);
        $bound->setDataConfig(json_encode([['scope' => 'bound', 'table' => $table, 'retrieve' => 'first', 'current_user' => false, 'filter' => 'AND record_id = {{route.record_id}}']], JSON_THROW_ON_ERROR));
        $this->conditionalText($card, 'qa_rt_support_only', 'Picked is Event 03', '{"==":["{{picked.title}}","Event 03"]}', 60);
        $this->em->flush();

        $plain = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3'));
        $ids = array_map(static fn (array $row): int => self::coerceInt($row['record_id'] ?? null), $this->listOf($plain, 'rows'));
        self::assertCount(3, $ids);

        foreach ([1 => 'Event 03', 2 => 'Event 05'] as $index => $picked) {
            $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3&route%5Brecord_id%5D=' . $ids[$index]));
            foreach ($this->listOf($data, 'rows') as $position => $row) {
                $expected = ['Picked: ' . $picked, sprintf('Own: Event %02d', 2 * $position + 1), 'Bound: ' . $picked];
                if ($picked === 'Event 03') {
                    $expected[] = 'Picked is Event 03';
                }
                self::assertSame($expected, array_slice($this->textsOf($row), 1), 'Route ' . $ids[$index] . ', card ' . $position . ': the values of the route and of its own record, and nothing of another card.');
            }
        }

        foreach ([$plain, $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3&route%5Brecord_id%5D=999999'))] as $without) {
            foreach ($this->listOf($without, 'rows') as $position => $row) {
                self::assertSame(
                    ['Picked: ', sprintf('Own: Event %02d', 2 * $position + 1), 'Bound: '],
                    array_slice($this->textsOf($row), 1),
                    'A route value that names no record, or that is missing, never turns the filter into "everything".',
                );
            }
        }
    }

    public function testTheValuesAConditionWasResolvedWithDoNotTravelToTheVisitor(): void
    {
        [$page, $view] = $this->customDataView('qa_rt_debug');
        $this->routeFor($page, '/qa-rt-debug/{record_id}', ['record_id' => '\d+']);
        $table = 'qa_rt_debug_table';
        $view->setDataConfig(json_encode([['scope' => 'picked', 'table' => $table, 'retrieve' => 'first', 'current_user' => false, 'filter' => 'AND record_id = {{route.record_id}}']], JSON_THROW_ON_ERROR));
        $card = $this->recordTemplate($view, 'qa_rt_debug');
        // The condition reads a cell of the supporting record that the layout never draws (the private `note`).
        $this->conditionalText($card, 'qa_rt_debug_line', 'Drawn without the note', '{"!=":["{{picked.note}}","zzz"]}', 40);
        $this->em->flush();
        $plain = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3'));
        $recordId = self::coerceInt($this->listOf($plain, 'rows')[0]['record_id'] ?? null);

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3&route%5Brecord_id%5D=' . $recordId));

        self::assertSame(['Drawn without the note'], array_slice($this->textsOf($this->listOf($data, 'rows')[0]), 1), 'The condition did read the note and held.');
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('internal note', $encoded, 'What a condition was resolved with is not part of the answer.');
        self::assertStringContainsString('{{picked.note}}', $encoded, 'The condition is shown as the author wrote it.');
    }

    public function testAnyOtherLayoutIgnoresTheSectionsInsideTheView(): void
    {
        [$page, $view] = $this->dataView('qa_rt_cards', ['list_layout' => 'cards', 'list_row_css' => 'grid']);
        $this->recordTemplate($view, 'qa_rt_cards');

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));

        $list = $this->at($data, 'list');
        self::assertSame('cards', $list['layout']);
        self::assertFalse($list['has_template'], 'Only the layout custom draws the sections.');
        self::assertSame('', $list['row_css'], 'The record classes belong to the layout custom.');
        foreach ($this->listOf($data, 'rows') as $row) {
            self::assertArrayNotHasKey('sections', $row, 'Cards and tables carry only values.');
        }
    }

    // -- helpers ----------------------------------------------------------------

    private function conditionFixtureText(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/fixtures/form-list/row-conditions.json');
    }

    /**
     * @return array{drawn: list<list<string>>, neverDrawn: list<string>, state: array<string, mixed>}
     */
    private function conditionFixture(): array
    {
        $decoded = json_decode($this->conditionFixtureText(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $state = $decoded['state'] ?? null;
        self::assertIsArray($state);
        $drawn = [];
        foreach (is_array($decoded['drawn'] ?? null) ? $decoded['drawn'] : [] as $lines) {
            self::assertIsArray($lines);
            $drawn[] = $this->strings($lines);
        }
        $never = $this->strings(is_array($decoded['neverDrawn'] ?? null) ? $decoded['neverDrawn'] : []);

        $keyed = [];
        foreach ($state as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return ['drawn' => $drawn, 'neverDrawn' => $never, 'state' => $keyed];
    }

    /**
     * The values as a list of texts; a value of any other type is a broken fixture.
     *
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            self::assertIsString($value);
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * The texts a record's layout drew inside its card, in order, with the record number masked.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function textsOf(array $row): array
    {
        $sections = $this->listOf($row, 'sections');
        $card = $sections[0] ?? [];
        $texts = [];
        foreach ($this->listOf($card, 'children') as $child) {
            if (($child['style_name'] ?? null) === 'text') {
                $texts[] = (string) preg_replace('/#\d+/', '#N', $this->contentOf($child, 'text'));
            }
        }
        // The sections of the template next to the card.
        foreach (array_slice($sections, 1) as $beside) {
            if (($beside['style_name'] ?? null) === 'text') {
                $texts[] = (string) preg_replace('/#\d+/', '#N', $this->contentOf($beside, 'text'));
            }
        }

        return $texts;
    }

    /**
     * A section of a page payload by its id, at any depth.
     *
     * @return array<string, mixed>|null
     */
    private function findSection(mixed $sections, int $sectionId): ?array
    {
        if (!is_array($sections)) {
            return null;
        }
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (is_numeric($section['id'] ?? null) && (int) $section['id'] === $sectionId) {
                $keyed = [];
                foreach ($section as $key => $value) {
                    $keyed[(string) $key] = $value;
                }

                return $keyed;
            }
            $inner = $this->findSection($section['children'] ?? [], $sectionId);
            if ($inner !== null) {
                return $inner;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $section
     */
    private function contentOf(array $section, string $field): string
    {
        $value = $section[$field] ?? null;
        if (is_array($value)) {
            $value = $value['content'] ?? null;
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function firstTitleOf(array $data): string
    {
        $sections = $this->listOf($this->listOf($data, 'rows')[0], 'sections');
        $children = $this->listOf($sections[0], 'children');

        return $this->contentOf($children[0], 'content');
    }
}
