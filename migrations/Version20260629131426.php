<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Auth\MailTemplateDefaults;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convert legacy full-HTML-document mail bodies (sh-mail-config) to the new
 * WYSIWYG content fragments.
 *
 * The mail body fields used to be seeded as complete `<!DOCTYPE html>…<body>`
 * documents with inline-styled markup. Those mangle in the CMS rich-text editor
 * and can no longer be sent verbatim now that {@see App\Service\Auth\MailHtmlRenderer}
 * wraps the body in a shared branded shell at send time (issue #56 mail editor).
 *
 * This backfill replaces any still-default full-document body with the new
 * fragment default (read from the updated `templates/emails/*.html` files), but
 * ONLY where the stored content still looks like a full document — an admin who
 * already authored a fragment is untouched, and fresh installs already seed the
 * fragments (so this becomes a no-op there). It is therefore idempotent.
 */
final class Version20260629131426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert legacy full-HTML-document mail bodies (sh-mail-config) to WYSIWYG fragments rendered by MailHtmlRenderer.';
    }

    public function up(Schema $schema): void
    {
        foreach (MailTemplateDefaults::TYPES as $type) {
            foreach (MailTemplateDefaults::LOCALES as $locale) {
                $body = MailTemplateDefaults::getBody($type, $locale);
                if ($body === '') {
                    continue;
                }

                // Only rewrite bodies still stored as a full HTML document; an
                // already-migrated fragment never matches, keeping this idempotent
                // and non-destructive to admin-authored content.
                $this->addSql(
                    'UPDATE pages_fields_translation
                        SET content = :content
                      WHERE id_pages = (SELECT id FROM pages WHERE keyword = :keyword LIMIT 1)
                        AND id_fields = (SELECT id FROM fields WHERE name = :field LIMIT 1)
                        AND id_languages = (SELECT id FROM languages WHERE locale = :locale LIMIT 1)
                        AND (content LIKE :doctype OR content LIKE :htmltag OR content LIKE :bodytag)',
                    [
                        'content' => $body,
                        'keyword' => MailTemplateDefaults::PAGE_KEYWORD,
                        'field' => $type . '_body',
                        'locale' => $locale,
                        'doctype' => '%<!DOCTYPE%',
                        'htmltag' => '%<html%',
                        'bodytag' => '%<body%',
                    ]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible data backfill: the original full-document bodies are not
        // retained. The new fragments still render and send correctly, so down()
        // is intentionally a no-op (the migration only rewrites default content).
    }
}
