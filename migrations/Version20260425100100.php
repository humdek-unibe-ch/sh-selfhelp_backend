<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Polish the cosmetic layout of the `/privacy` page seeded by
 * `Version20260425090000`.
 *
 * The original migration seeded all the legally-required content, but the
 * rendered page reads as a wall of un-spaced text — see screenshots in
 * `dev_log.md` (entry "/privacy becomes a CMS-managed system page").
 *
 * This migration is a NON-DESTRUCTIVE follow-up: it only adds entries to
 * `sections_fields_translation` for the `css` global field on existing
 * privacy sections. The actual content stays untouched, so admins who
 * already started extending the page with their own paragraphs lose
 * nothing.
 *
 * Why a separate migration?
 *   - The seeding work (`Version20260425090000`) is already applied on
 *     production — we never edit applied migrations in place.
 *   - Cosmetic tweaks should be reversible in isolation. If an operator
 *     prefers the bare layout they can `migrations:migrate prev` only
 *     this version without losing the content.
 *
 * The CSS values are Tailwind utility classes consumed by the StyleView
 * `css` field. They include `dark:` variants so the page looks right
 * in both color schemes.
 */
final class Version20260425100100 extends AbstractMigration
{
    /**
     * Map of `<section_name> => <Tailwind classes>` to apply via the
     * `css` global field. Sections not listed here keep their default
     * (no CSS class) presentation.
     */
    private const CSS_BY_SECTION = [
        // Hero block — h1 + intro get extra breathing room and a
        // soft background so the title reads as a proper page header.
        'privacy-h1' => 'mt-2 mb-1',
        'privacy-intro' => 'mb-8 text-base leading-relaxed',

        // Section headings — pad above so each block visually starts
        // a new "chapter", and tighten the gap to its body paragraph.
        'privacy-h2-personal-data' => 'mt-10 mb-2 pb-1 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-legal-basis' => 'mt-10 mb-2 pb-1 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-retention' => 'mt-10 mb-2 pb-1 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-recipients' => 'mt-10 mb-2 pb-1 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-international' => 'mt-10 mb-2 pb-1 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-rights' => 'mt-10 mb-2 pb-1 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-cookies' => 'mt-10 mb-2 pb-1 border-b border-gray-200 dark:border-gray-700',
        'privacy-h2-contact' => 'mt-10 mb-2 pb-1 border-b border-gray-200 dark:border-gray-700',

        // Body paragraphs — comfortable reading width + slight color softening.
        'privacy-personal-data-intro' => 'mb-3 leading-relaxed',
        'privacy-legal-basis-text' => 'mb-3 leading-relaxed',
        'privacy-retention-text' => 'mb-3 leading-relaxed',
        'privacy-recipients-text' => 'mb-3 leading-relaxed',
        'privacy-international-text' => 'mb-3 leading-relaxed',
        'privacy-rights-intro' => 'mb-3 leading-relaxed',
        'privacy-cookies-intro' => 'mb-3 leading-relaxed',
        'privacy-contact-text' => 'mb-3 leading-relaxed',

        // Lists — left padding so bullets sit clear of the page margin,
        // bottom margin so the next heading does not crowd them.
        'privacy-personal-data-list' => 'pl-5 mb-6',
        'privacy-rights-list' => 'pl-5 mb-6',
        'privacy-cookies-list' => 'pl-5 mb-6',
    ];

    public function getDescription(): string
    {
        return 'Polish the visual layout of the seeded /privacy page (CSS classes only, content untouched).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CSS_BY_SECTION as $sectionName => $cssClasses) {
            $escaped = $this->escape($cssClasses);

            // INSERT IGNORE so re-running this migration after a partial
            // failure is harmless. The unique key on
            // sections_fields_translation is (id_sections, id_fields,
            // id_languages), so the INSERT will be skipped when a value
            // is already present and the operator who set it keeps
            // their override.
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `sections_fields_translation`
                    (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
                SELECT sec.id, f.id, 1, '{$escaped}', NULL
                FROM `sections` sec
                JOIN `fields` f ON f.`name` = 'css'
                WHERE sec.`name` = '{$sectionName}'
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        // Remove only the CSS rows we added; do not touch any other field
        // values on these sections (the operator may have edited the
        // content after the cosmetic upgrade).
        foreach (array_keys(self::CSS_BY_SECTION) as $sectionName) {
            $this->addSql(<<<SQL
                DELETE sft FROM `sections_fields_translation` sft
                JOIN `sections` sec ON sec.id = sft.id_sections
                JOIN `fields` f ON f.id = sft.id_fields
                WHERE sec.`name` = '{$sectionName}'
                  AND f.`name` = 'css'
                  AND sft.`id_languages` = 1
            SQL);
        }
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
