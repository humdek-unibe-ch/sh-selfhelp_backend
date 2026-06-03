<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Language;
use App\Entity\Lookup;
use App\Entity\Page;
use App\Entity\PagesSection;
use App\Entity\PageType;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Entity\Style;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Canonical QA frontend golden fixture: the `qa-feedback` page the frontend
 * golden E2E (`sh-selfhelp_frontend/e2e/golden/form-action-job.spec.ts`)
 * drives end-to-end. Seeded once by `php bin/console app:test:reset-db`
 * (alongside {@see QaBaselineFixture}) and kept stable across the run by
 * DAMA transaction rollback.
 *
 * WHY this is backend-owned: the golden spec used to require developers to
 * export QA_* env vars by hand and hand-build a matching form page. Making
 * the backend the single source of the canonical QA form removes that
 * manual step — `npm run test:golden` and the CI workflow now rely on these
 * exact keyword / field-name / success-message values instead of GitHub
 * vars/secrets.
 *
 * The graph this builds (mirroring the production entity/service patterns of
 * {@see \App\Tests\Support\Factories\PageSectionFactory}, without depending
 * on that test-only class from `src/`):
 *
 *   page  keyword=qa-feedback url=/qa-feedback (NOT open access — ACL governs)
 *     └─ section  style=form-log  name=qa_feedback_form  alert_success=…
 *          └─ section  style=text-input  name=qa_message  label/placeholder=…
 *   ACL   subject group → select + insert  (qa.user@selfhelp.test is in the
 *         subject group via QaBaselineFixture, so it inherits view + submit)
 *
 * `form-log` (not `form-record`) is deliberate: a log form does not read a
 * per-section data table on render, so the page renders without the fixture
 * having to pre-create the section's data table — `DataService::saveData`
 * auto-creates it on first submit (proven by FormControllerTest). The
 * frontend FormStyle shows its success Alert only when `alert_success` has
 * content, so seeding it is what makes the spec's "Success" assertion pass.
 *
 * Non-translatable property fields (`name`, `type_input`) are stored under
 * the property language (`id_languages = 1` = the `all` locale); translatable
 * fields (`alert_success`, `label`, `placeholder`) are stored for every
 * shipped content locale (de-CH + en-GB) so the form renders correctly
 * whatever the resolved CMS language is — the exact convention the system-page
 * seed migration (`Version20260501000600`) uses.
 *
 * Tagged with the `qa` group so `doctrine:fixtures:load --group=qa --append`
 * loads it on top of the migration baseline.
 */
final class QaFrontendGoldenFixture extends Fixture implements FixtureGroupInterface
{
    /** Page keyword the frontend golden spec opens (`QA_FORM_PAGE_KEYWORD`). */
    public const QA_FORM_PAGE_KEYWORD = 'qa-feedback';

    /** Public URL of the seeded page. */
    public const QA_FORM_PAGE_URL = '/qa-feedback';

    /** The form input the spec fills (`QA_FORM_FIELDS` key). */
    public const QA_FORM_FIELD_NAME = 'qa_message';

    /** Form section name (qa-prefixed, deterministic — Testing Rules 9/14). */
    public const QA_FORM_SECTION_NAME = 'qa-feedback-form';

    /** Input section name. */
    public const QA_FORM_INPUT_SECTION_NAME = 'qa-feedback-message';

    /**
     * Property language id used for non-translatable fields. Matches
     * `PageService::PROPERTY_LANGUAGE_ID` and the `id_languages = 1`
     * convention of the system-page seed migration.
     */
    private const PROPERTY_LANGUAGE_ID = 1;

    /** Content locales every shipped install carries (de-CH + en-GB). */
    private const CONTENT_LOCALES = ['de-CH', 'en-GB'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ACLService $aclService,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['qa'];
    }

    public function load(ObjectManager $manager): void
    {
        // Idempotent under `--append`: a previous load (or a not-yet-reset DB)
        // already seeded the page — leave it untouched.
        if ($this->em->getRepository(Page::class)->findOneBy(['keyword' => self::QA_FORM_PAGE_KEYWORD]) instanceof Page) {
            return;
        }

        $subjectGroup = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        if (!$subjectGroup instanceof Group) {
            throw new \RuntimeException(
                'QA frontend golden fixture cannot seed: the seeded "subject" group is missing. '
                . 'The schema baseline + reference-data seed migrations must run first.'
            );
        }

        $page = $this->createPage();

        // Form section (log form: no data-table read on render).
        $formSection = $this->createSection(self::QA_FORM_SECTION_NAME, 'form-log');
        $this->setPropertyField($formSection, 'name', 'qa_feedback_form');
        $this->setTranslatableField($formSection, 'alert_success', [
            'de-CH' => 'Ihr Feedback wurde gespeichert.',
            'en-GB' => 'Your feedback was saved.',
        ]);
        $this->linkSectionToPage($page, $formSection, 10);

        // Child input the spec fills: a plain (non-translatable) text input
        // whose CMS `name` becomes the DOM `name="qa_message"`.
        $inputSection = $this->createSection(self::QA_FORM_INPUT_SECTION_NAME, 'text-input');
        $this->setPropertyField($inputSection, 'name', self::QA_FORM_FIELD_NAME);
        $this->setTranslatableField($inputSection, 'label', [
            'de-CH' => 'Ihre Nachricht',
            'en-GB' => 'Your message',
        ]);
        $this->setTranslatableField($inputSection, 'placeholder', [
            'de-CH' => 'Schreiben Sie uns Ihr Feedback…',
            'en-GB' => 'Tell us your feedback…',
        ]);
        $this->linkSectionToParent($formSection, $inputSection, 10);

        // Flush first so the page/sections have positive ids: addGroupAcl
        // invalidates the page entity scope by id and rejects id 0.
        $this->em->flush();

        // qa.user (subject group) must be able to view (select) and submit
        // (insert) — the authenticated form path the golden spec exercises.
        $this->aclService->addGroupAcl($page, $subjectGroup, select: true, insert: true, update: false, delete: false, em: $this->em);
        $this->em->flush();

        // Redis persists across runs while reset-db recreates the DB and reuses
        // auto-increment ids, so drop the page/section/permission generations so
        // the freshly started backend recomputes this page from the DB.
        $this->invalidatePageScopedCaches();
    }

    private function createPage(): Page
    {
        $page = new Page();
        $page->setKeyword(self::QA_FORM_PAGE_KEYWORD);
        $page->setUrl(self::QA_FORM_PAGE_URL);
        $page->setPageType($this->anyPageType());
        $page->setPageAccessType($this->webAccessType());
        $page->setIsHeadless(false);
        $page->setIsOpenAccess(false);
        $this->em->persist($page);

        return $page;
    }

    private function createSection(string $name, string $styleName): Section
    {
        $section = new Section();
        $section->setName($name);
        $section->setStyle($this->style($styleName));
        $this->em->persist($section);

        return $section;
    }

    private function linkSectionToPage(Page $page, Section $section, int $position): void
    {
        $link = new PagesSection();
        $link->setPage($page);
        $link->setSection($section);
        $link->setPosition($position);
        $this->em->persist($link);
    }

    private function linkSectionToParent(Section $parent, Section $child, int $position): void
    {
        $link = new SectionsHierarchy();
        $link->setParentSection($parent);
        $link->setChildSection($child);
        $link->setPosition($position);
        $this->em->persist($link);
    }

    /**
     * Store a non-translatable field value under the property language (id 1).
     */
    private function setPropertyField(Section $section, string $fieldName, string $content): void
    {
        $this->persistTranslation($section, $fieldName, $this->language(self::PROPERTY_LANGUAGE_ID), $content);
    }

    /**
     * Store a translatable field value for every shipped content locale.
     *
     * @param array<string, string> $byLocale locale => content
     */
    private function setTranslatableField(Section $section, string $fieldName, array $byLocale): void
    {
        foreach (self::CONTENT_LOCALES as $locale) {
            if (!isset($byLocale[$locale])) {
                continue;
            }
            $this->persistTranslation($section, $fieldName, $this->languageByLocale($locale), $byLocale[$locale]);
        }
    }

    private function persistTranslation(Section $section, string $fieldName, Language $language, string $content): void
    {
        $translation = new SectionsFieldsTranslation();
        $translation->setSection($section);
        $translation->setField($this->field($fieldName));
        $translation->setLanguage($language);
        $translation->setContent($content);
        $this->em->persist($translation);
    }

    private function invalidatePageScopedCaches(): void
    {
        foreach ([
            CacheService::CATEGORY_PAGES,
            CacheService::CATEGORY_SECTIONS,
            CacheService::CATEGORY_PERMISSIONS,
        ] as $category) {
            $this->cache->withCategory($category)->invalidateCategory();
        }
    }

    private function anyPageType(): PageType
    {
        $pageType = $this->em->getRepository(PageType::class)->findOneBy([]);
        if (!$pageType instanceof PageType) {
            throw new \RuntimeException('No PageType seeded. The baseline seed migrations must run first.');
        }

        return $pageType;
    }

    private function webAccessType(): Lookup
    {
        $lookup = $this->lookupService->findByTypeAndCode(LookupService::PAGE_ACCESS_TYPES, LookupService::PAGE_ACCESS_TYPES_WEB);
        if (!$lookup instanceof Lookup) {
            throw new \RuntimeException('Missing pageAccessTypes/web lookup. The reference-data seed migration must run first.');
        }

        return $lookup;
    }

    private function style(string $name): Style
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $name]);
        if (!$style instanceof Style) {
            throw new \RuntimeException(sprintf('Missing seeded style "%s". The fields/styles seed migration must run first.', $name));
        }

        return $style;
    }

    private function field(string $name): Field
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $name]);
        if (!$field instanceof Field) {
            throw new \RuntimeException(sprintf('Missing seeded field "%s". The fields/styles seed migration must run first.', $name));
        }

        return $field;
    }

    private function language(int $id): Language
    {
        $language = $this->em->getRepository(Language::class)->find($id);
        if (!$language instanceof Language) {
            throw new \RuntimeException(sprintf('Missing language id %d (the property language). The reference-data seed migration must run first.', $id));
        }

        return $language;
    }

    private function languageByLocale(string $locale): Language
    {
        $language = $this->em->getRepository(Language::class)->findOneBy(['locale' => $locale]);
        if (!$language instanceof Language) {
            throw new \RuntimeException(sprintf('Missing language locale "%s". The reference-data seed migration must run first.', $locale));
        }

        return $language;
    }
}
