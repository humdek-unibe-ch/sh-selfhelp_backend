<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed the GDPR-compliant `/privacy` CMS page.
 *
 * Why this lives in the CMS (and not as a static Next.js route):
 *   - EU operators need the page to be translatable (en-GB + de-CH out of the
 *     box, more languages addable from the admin panel).
 *   - Operators of each instance need the freedom to extend the notice with
 *     their own data-controller identity, contact, retention specifics, etc.,
 *     by adding additional sections from the admin UI.
 *
 * Why it cannot be deleted:
 *   - The page is marked `is_system = 1`. The companion patch in
 *     `AdminPageService::deletePage()` rejects deletion when that flag is set,
 *     so admins can edit / extend the content but the privacy notice can never
 *     vanish from a production install.
 *
 * Why anonymous visitors can read it:
 *   - `is_open_access = 1` lets the slug catch-all on the frontend render this
 *     page without an authenticated session, which is required for
 *     GDPR/ePrivacy compliance: the privacy notice must be reachable BEFORE
 *     the user creates an account.
 *
 * Page structure (top-level sections in display order; list children are nested
 * via `sections_hierarchy`):
 *
 *   - h1                              "Privacy & Cookies"
 *   - intro                           short summary paragraph
 *   - h2-personal-data                "Personal Data We Process"
 *   - personal-data-intro             paragraph
 *   - personal-data-list              `list` with 4 list-items
 *   - h2-legal-basis                  "Legal Basis for Processing"
 *   - legal-basis-text                paragraph
 *   - h2-retention                    "Data Retention"
 *   - retention-text                  paragraph
 *   - h2-recipients                   "Recipients of the Data"
 *   - recipients-text                 paragraph
 *   - h2-international                "International Transfers"
 *   - international-text              paragraph
 *   - h2-rights                       "Your Rights"
 *   - rights-intro                    paragraph
 *   - rights-list                     `list` with 6 list-items
 *   - h2-cookies                      "Strictly Necessary Cookies"
 *   - cookies-intro                   paragraph
 *   - cookies-list                    `list` with 7 list-items (sh_auth, etc.)
 *   - h2-contact                      "Contact"
 *   - contact-text                    paragraph
 *
 * Migration is idempotent at the page-level via `INSERT IGNORE` on
 * `pages.keyword = 'privacy'`. Doctrine's migration tracker prevents
 * accidental re-runs in normal use; if `down()` is invoked the page row is
 * removed and the FK CASCADE on `pages_sections`, `pages_fields_translation`
 * and `acl_groups` cleans up everything bound to it. Sections themselves are
 * not cascade-deleted by the page (they live in their own table) so `down()`
 * also does an explicit `DELETE FROM sections WHERE name LIKE 'privacy-%'`.
 */
final class Version20260425090000 extends AbstractMigration
{
    private const PAGE_KEYWORD = 'privacy';
    private const PAGE_URL = '/privacy';
    private const SECTION_PREFIX = 'privacy';
    private const FOOTER_POSITION = 400;

    /**
     * Page-level title + description, per locale. Used for SEO + footer label.
     *
     * @var array<string, array{title:string, description:string}>
     */
    private const PAGE_META = [
        'en-GB' => [
            'title' => 'Privacy & Cookies',
            'description' => 'Privacy notice for the SelfHelp platform: what personal data we process, why, your GDPR rights, and the strictly necessary cookies we use.',
        ],
        'de-CH' => [
            'title' => 'Datenschutz & Cookies',
            'description' => 'Datenschutzerklärung der SelfHelp-Plattform: welche personenbezogenen Daten wir verarbeiten, warum, Ihre Rechte gemäss DSGVO und die unbedingt erforderlichen Cookies, die wir verwenden.',
        ],
    ];

    /**
     * The full content of the page, declared as a flat structure that we
     * iterate to emit DB rows. Each entry produces:
     *
     *   - one row in `sections` (name = `privacy-<key>`, id_styles = style)
     *   - if `parent` is null: one row in `pages_sections`     (top-level)
     *     otherwise          : one row in `sections_hierarchy` (nested child)
     *   - one row in `sections_fields_translation` per non-translatable field
     *     in `fields` (language = 'all'/id 1)
     *   - one row in `sections_fields_translation` per translatable field
     *     in `translations`, per language (en-GB, de-CH).
     *
     * Schema:
     *   key         : string, unique within this migration (used for the
     *                 section name and to refer to the parent of nested items)
     *   style       : string, must match `styles.name` (resolved at insert)
     *   parent      : ?string, parent key for nested items (only for list-item)
     *   fields      : ?array<field_name, scalar_value> — non-translatable
     *   translations: ?array<field_name, array<locale, value>> — translatable
     *
     * @var list<array{
     *   key:string,
     *   style:string,
     *   parent?:string,
     *   fields?:array<string,string>,
     *   translations?:array<string,array<string,string>>,
     * }>
     */
    private const SECTIONS = [
        // ---- Heading + intro ------------------------------------------------
        [
            'key' => 'h1',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '1'],
            'translations' => [
                'content' => [
                    'en-GB' => 'Privacy & Cookies',
                    'de-CH' => 'Datenschutz & Cookies',
                ],
            ],
        ],
        [
            'key' => 'intro',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'SelfHelp is a research support platform. We do not run analytics, advertising, or any third-party tracking. This notice explains what personal data we process when you use the platform, on what legal basis, how long we keep it, and the rights you have under the EU General Data Protection Regulation (GDPR).',
                    'de-CH' => 'SelfHelp ist eine Plattform zur Unterstützung der Forschung. Wir setzen keine Analyse-, Werbe- oder Tracking-Dienste Dritter ein. Diese Erklärung beschreibt, welche personenbezogenen Daten wir verarbeiten, auf welcher Rechtsgrundlage, wie lange wir sie aufbewahren und welche Rechte Ihnen gemäss der Datenschutz-Grundverordnung (DSGVO) zustehen.',
                ],
            ],
        ],

        // ---- Personal Data --------------------------------------------------
        [
            'key' => 'h2-personal-data',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '2'],
            'translations' => [
                'content' => [
                    'en-GB' => 'Personal Data We Process',
                    'de-CH' => 'Personenbezogene Daten, die wir verarbeiten',
                ],
            ],
        ],
        [
            'key' => 'personal-data-intro',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'When you use SelfHelp we process the following categories of personal data:',
                    'de-CH' => 'Bei der Nutzung von SelfHelp verarbeiten wir folgende Kategorien personenbezogener Daten:',
                ],
            ],
        ],
        [
            'key' => 'personal-data-list',
            'style' => 'list',
            'fields' => ['mantine_list_list_style_type' => 'disc'],
        ],
        [
            'key' => 'pd-1',
            'style' => 'list-item',
            'parent' => 'personal-data-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Account information: email address, name, hashed password, language preference, and two-factor authentication settings.',
                    'de-CH' => 'Kontoinformationen: E-Mail-Adresse, Name, gehashtes Passwort, Spracheinstellung und Einstellungen zur Zwei-Faktor-Authentifizierung.',
                ],
            ],
        ],
        [
            'key' => 'pd-2',
            'style' => 'list-item',
            'parent' => 'personal-data-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Activity data: login timestamps, IP address, and browser/device information used for security and audit purposes.',
                    'de-CH' => 'Aktivitätsdaten: Anmeldezeitstempel, IP-Adresse sowie Browser- und Geräteinformationen, die zu Sicherheits- und Protokollierungszwecken verarbeitet werden.',
                ],
            ],
        ],
        [
            'key' => 'pd-3',
            'style' => 'list-item',
            'parent' => 'personal-data-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Form responses and content: any data you submit through the platform’s forms is stored as part of the research project you joined.',
                    'de-CH' => 'Formularantworten und Inhalte: alle Daten, die Sie über die Formulare der Plattform übermitteln, werden im Rahmen des Forschungsprojekts gespeichert, dem Sie beigetreten sind.',
                ],
            ],
        ],
        [
            'key' => 'pd-4',
            'style' => 'list-item',
            'parent' => 'personal-data-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Administrator metadata: changes to pages, configuration, and user accounts are logged with the user identity for audit purposes.',
                    'de-CH' => 'Administrator-Metadaten: Änderungen an Seiten, Konfigurationen und Benutzerkonten werden mit Benutzeridentität zu Audit-Zwecken protokolliert.',
                ],
            ],
        ],

        // ---- Legal Basis ----------------------------------------------------
        [
            'key' => 'h2-legal-basis',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '2'],
            'translations' => [
                'content' => [
                    'en-GB' => 'Legal Basis for Processing',
                    'de-CH' => 'Rechtsgrundlage der Verarbeitung',
                ],
            ],
        ],
        [
            'key' => 'legal-basis-text',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'We rely on (a) your consent given when you joined the research project, (b) the contract between you and the research institution operating this instance, and (c) our legitimate interest in maintaining a secure platform (e.g. security audit logs).',
                    'de-CH' => 'Wir stützen uns auf (a) Ihre Einwilligung beim Beitritt zum Forschungsprojekt, (b) den Vertrag zwischen Ihnen und der Forschungsinstitution, die diese Instanz betreibt, sowie (c) unser berechtigtes Interesse am sicheren Betrieb der Plattform (z. B. Sicherheits-Audit-Protokolle).',
                ],
            ],
        ],

        // ---- Retention ------------------------------------------------------
        [
            'key' => 'h2-retention',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '2'],
            'translations' => [
                'content' => [
                    'en-GB' => 'Data Retention',
                    'de-CH' => 'Aufbewahrungsdauer',
                ],
            ],
        ],
        [
            'key' => 'retention-text',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'Account and activity data are kept for the duration of the research project plus the retention period defined by the operating institution’s data management plan. Security and audit logs are kept for at least 12 months. After this period, data is either deleted or anonymised.',
                    'de-CH' => 'Konto- und Aktivitätsdaten werden für die Dauer des Forschungsprojekts zuzüglich des im Datenmanagementplan der betreibenden Institution festgelegten Aufbewahrungszeitraums gespeichert. Sicherheits- und Audit-Protokolle werden mindestens 12 Monate aufbewahrt. Nach Ablauf dieser Frist werden die Daten entweder gelöscht oder anonymisiert.',
                ],
            ],
        ],

        // ---- Recipients -----------------------------------------------------
        [
            'key' => 'h2-recipients',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '2'],
            'translations' => [
                'content' => [
                    'en-GB' => 'Recipients of the Data',
                    'de-CH' => 'Empfänger der Daten',
                ],
            ],
        ],
        [
            'key' => 'recipients-text',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'Your data is accessible to the research team operating this instance and to the technical hosting provider on which the platform runs. We do not share data with third-party advertisers, analytics providers, or external trackers.',
                    'de-CH' => 'Ihre Daten sind für das Forschungsteam, das diese Instanz betreibt, und für den technischen Hosting-Anbieter, auf dem die Plattform läuft, zugänglich. Wir geben keine Daten an Werbedienste, Analyse-Anbieter oder externe Tracker weiter.',
                ],
            ],
        ],

        // ---- International Transfers ---------------------------------------
        [
            'key' => 'h2-international',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '2'],
            'translations' => [
                'content' => [
                    'en-GB' => 'International Transfers',
                    'de-CH' => 'Internationale Datenübermittlung',
                ],
            ],
        ],
        [
            'key' => 'international-text',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'Data is hosted within the European Union (or in a country recognised by the EU as offering an adequate level of data protection) unless you have been informed otherwise by the research team operating this instance.',
                    'de-CH' => 'Die Daten werden innerhalb der Europäischen Union gehostet (oder in einem Land, das von der EU als angemessen sicher anerkannt wurde), sofern Sie vom betreibenden Forschungsteam nicht ausdrücklich anders informiert wurden.',
                ],
            ],
        ],

        // ---- Your Rights ----------------------------------------------------
        [
            'key' => 'h2-rights',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '2'],
            'translations' => [
                'content' => [
                    'en-GB' => 'Your Rights',
                    'de-CH' => 'Ihre Rechte',
                ],
            ],
        ],
        [
            'key' => 'rights-intro',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'Under the EU GDPR you have the following rights regarding your personal data:',
                    'de-CH' => 'Gemäss DSGVO haben Sie hinsichtlich Ihrer personenbezogenen Daten folgende Rechte:',
                ],
            ],
        ],
        [
            'key' => 'rights-list',
            'style' => 'list',
            'fields' => ['mantine_list_list_style_type' => 'disc'],
        ],
        [
            'key' => 'r-1',
            'style' => 'list-item',
            'parent' => 'rights-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Right of access — you can request a copy of the personal data we hold about you.',
                    'de-CH' => 'Auskunftsrecht — Sie können eine Kopie Ihrer bei uns gespeicherten personenbezogenen Daten anfordern.',
                ],
            ],
        ],
        [
            'key' => 'r-2',
            'style' => 'list-item',
            'parent' => 'rights-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Right to rectification — you can ask us to correct inaccurate or incomplete data.',
                    'de-CH' => 'Recht auf Berichtigung — Sie können verlangen, dass unrichtige oder unvollständige Daten berichtigt werden.',
                ],
            ],
        ],
        [
            'key' => 'r-3',
            'style' => 'list-item',
            'parent' => 'rights-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Right to erasure — you can request deletion of your personal data, subject to legal retention obligations.',
                    'de-CH' => 'Recht auf Löschung — Sie können die Löschung Ihrer personenbezogenen Daten verlangen, vorbehaltlich gesetzlicher Aufbewahrungspflichten.',
                ],
            ],
        ],
        [
            'key' => 'r-4',
            'style' => 'list-item',
            'parent' => 'rights-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Right to restrict or object to processing — you can ask us to limit how we use your data.',
                    'de-CH' => 'Recht auf Einschränkung der Verarbeitung oder Widerspruch — Sie können verlangen, dass wir die Nutzung Ihrer Daten einschränken.',
                ],
            ],
        ],
        [
            'key' => 'r-5',
            'style' => 'list-item',
            'parent' => 'rights-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Right to data portability — you can request a structured, machine-readable export of your data.',
                    'de-CH' => 'Recht auf Datenübertragbarkeit — Sie können einen strukturierten, maschinenlesbaren Export Ihrer Daten anfordern.',
                ],
            ],
        ],
        [
            'key' => 'r-6',
            'style' => 'list-item',
            'parent' => 'rights-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'Right to lodge a complaint with a supervisory authority (the data protection authority of your EU member state).',
                    'de-CH' => 'Recht auf Beschwerde bei einer Aufsichtsbehörde (die Datenschutzbehörde Ihres EU-Mitgliedstaats).',
                ],
            ],
        ],

        // ---- Cookies (strictly necessary) -----------------------------------
        [
            'key' => 'h2-cookies',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '2'],
            'translations' => [
                'content' => [
                    'en-GB' => 'Strictly Necessary Cookies',
                    'de-CH' => 'Unbedingt erforderliche Cookies',
                ],
            ],
        ],
        [
            'key' => 'cookies-intro',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'We use only the cookies listed below. They are strictly necessary for authentication, security, and user preferences and therefore do not require explicit consent under GDPR / ePrivacy.',
                    'de-CH' => 'Wir verwenden ausschliesslich die nachfolgend aufgeführten Cookies. Sie sind für Authentifizierung, Sicherheit und Benutzereinstellungen unbedingt erforderlich und bedürfen daher gemäss DSGVO / ePrivacy keiner ausdrücklichen Einwilligung.',
                ],
            ],
        ],
        [
            'key' => 'cookies-list',
            'style' => 'list',
            'fields' => ['mantine_list_list_style_type' => 'disc'],
        ],
        [
            'key' => 'c-auth',
            'style' => 'list-item',
            'parent' => 'cookies-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'sh_auth — HttpOnly session access token. Used by the server to identify the current user. Rotates on refresh and is cleared on logout.',
                    'de-CH' => 'sh_auth — HttpOnly-Session-Token. Wird vom Server zur Identifikation des aktuellen Benutzers verwendet. Wird beim Aktualisieren rotiert und beim Abmelden gelöscht.',
                ],
            ],
        ],
        [
            'key' => 'c-refresh',
            'style' => 'list-item',
            'parent' => 'cookies-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'sh_refresh — HttpOnly refresh token that lets the server silently renew the access token so you do not get logged out during an active session. Cleared on logout.',
                    'de-CH' => 'sh_refresh — HttpOnly-Refresh-Token, mit dem der Server das Sitzungs-Token im Hintergrund erneuern kann, sodass Sie während einer aktiven Sitzung nicht abgemeldet werden. Beim Abmelden gelöscht.',
                ],
            ],
        ],
        [
            'key' => 'c-csrf',
            'style' => 'list-item',
            'parent' => 'cookies-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'sh_csrf — Anti-CSRF token set on first visit. Compared against the X-CSRF-Token header on any state-changing API call to block cross-site request forgery attacks.',
                    'de-CH' => 'sh_csrf — Anti-CSRF-Token, beim ersten Besuch gesetzt. Wird bei jedem zustandsändernden API-Aufruf mit dem X-CSRF-Token-Header verglichen, um Cross-Site-Request-Forgery-Angriffe zu blockieren.',
                ],
            ],
        ],
        [
            'key' => 'c-lang',
            'style' => 'list-item',
            'parent' => 'cookies-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'sh_lang — Remembers your selected language so the site renders in your preferred locale on first paint.',
                    'de-CH' => 'sh_lang — Speichert die von Ihnen gewählte Sprache, damit die Seite beim ersten Aufruf in Ihrer bevorzugten Sprache angezeigt wird.',
                ],
            ],
        ],
        [
            'key' => 'c-accept-locale',
            'style' => 'list-item',
            'parent' => 'cookies-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'sh_accept_locale — Records the browser’s Accept-Language hint on first visit so the server can pick a sensible default language before you have signed in or explicitly chosen one. Stores only a short locale tag (e.g. en-GB), never any personal data.',
                    'de-CH' => 'sh_accept_locale — Speichert beim ersten Besuch den Accept-Language-Hinweis des Browsers, damit der Server eine sinnvolle Standardsprache wählen kann, bevor Sie sich angemeldet oder ausdrücklich eine Sprache gewählt haben. Es wird nur ein kurzer Locale-Code gespeichert (z. B. de-CH), niemals personenbezogene Daten.',
                ],
            ],
        ],
        [
            'key' => 'c-color-scheme',
            'style' => 'list-item',
            'parent' => 'cookies-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'sh_color_scheme — Remembers your light / dark / auto theme choice so the site renders in the correct color scheme on the very first paint (no white flash on dark-mode reloads). Stores only one of light, dark, or auto.',
                    'de-CH' => 'sh_color_scheme — Speichert Ihre Wahl des Farbschemas (hell / dunkel / automatisch), damit die Seite beim ersten Aufruf im richtigen Farbschema angezeigt wird (kein weisses Aufblitzen beim Neuladen im Dunkelmodus). Es wird nur einer der Werte light, dark oder auto gespeichert.',
                ],
            ],
        ],
        [
            'key' => 'c-preview',
            'style' => 'list-item',
            'parent' => 'cookies-list',
            'translations' => [
                'mantine_list_item_content' => [
                    'en-GB' => 'sh_preview — Set only for administrators when previewing unpublished CMS content. Has no effect for regular visitors and is never set on anonymous sessions. Cleared as soon as preview mode is turned off.',
                    'de-CH' => 'sh_preview — Wird ausschliesslich für Administratoren gesetzt, wenn unveröffentlichte CMS-Inhalte vorab angesehen werden. Hat keine Auswirkung auf reguläre Besucher und wird nie in anonymen Sitzungen gesetzt. Wird gelöscht, sobald der Vorschaumodus deaktiviert wird.',
                ],
            ],
        ],

        // ---- Contact --------------------------------------------------------
        [
            'key' => 'h2-contact',
            'style' => 'title',
            'fields' => ['mantine_title_order' => '2'],
            'translations' => [
                'content' => [
                    'en-GB' => 'Contact',
                    'de-CH' => 'Kontakt',
                ],
            ],
        ],
        [
            'key' => 'contact-text',
            'style' => 'text',
            'translations' => [
                'text' => [
                    'en-GB' => 'For any privacy-related questions or to exercise any of the rights listed above, please contact the research team that gave you access to this platform. The research team operating this instance acts as the data controller and is responsible for replying to your request within the deadlines set by GDPR.',
                    'de-CH' => 'Bei datenschutzrechtlichen Fragen oder zur Ausübung der oben aufgeführten Rechte wenden Sie sich bitte an das Forschungsteam, das Ihnen Zugang zu dieser Plattform gewährt hat. Das diese Instanz betreibende Forschungsteam ist der Verantwortliche und für die Beantwortung Ihrer Anfrage innerhalb der von der DSGVO vorgesehenen Fristen zuständig.',
                ],
            ],
        ],
    ];

    public function getDescription(): string
    {
        return 'Seed CMS-managed /privacy page (GDPR-compliant, en-GB + de-CH, marked is_system)';
    }

    public function up(Schema $schema): void
    {
        $this->insertPageRow();
        $this->insertPageTranslations();
        $this->insertAclRows();
        $this->insertSections();
    }

    public function down(Schema $schema): void
    {
        // Sections live in their own table — `pages_sections` cascades on
        // page delete but does NOT delete the section rows themselves, so we
        // remove them explicitly. The `sections_fields_translation` and
        // `sections_hierarchy` rows cascade off `sections.id`.
        $prefix = self::SECTION_PREFIX;
        $this->addSql("DELETE FROM `sections` WHERE `name` LIKE '{$prefix}-%'");

        // FK CASCADE handles `pages_sections`, `pages_fields_translation`,
        // `acl_groups` rows that reference this page id.
        $keyword = self::PAGE_KEYWORD;
        $this->addSql("DELETE FROM `pages` WHERE `keyword` = '{$keyword}'");
    }

    /**
     * Insert the row in `pages` describing the privacy page.
     *
     * `INSERT IGNORE` keeps the migration safe if it is re-applied on an
     * install where a previous attempt left the page row in place; the
     * subsequent statements look the page up by keyword so they will reuse
     * whichever id is present.
     */
    private function insertPageRow(): void
    {
        $keyword = self::PAGE_KEYWORD;
        $url = self::PAGE_URL;
        $footerPos = self::FOOTER_POSITION;

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `pages`
                (`keyword`, `url`, `parent`, `is_headless`, `nav_position`, `footer_position`,
                 `id_type`, `id_pageAccessTypes`, `is_open_access`, `is_system`, `published_version_id`)
            VALUES
                ('{$keyword}', '{$url}', NULL, 0, NULL, {$footerPos},
                 (SELECT id FROM `pageType` WHERE `name` = 'core' LIMIT 1),
                 (SELECT id FROM `lookups` WHERE `type_code` = 'pageAccessTypes' AND `lookup_code` = 'mobile_and_web' LIMIT 1),
                 1, 1, NULL)
        SQL);
    }

    /**
     * Insert page-level title + description translations into
     * `pages_fields_translation`. These power the SEO `<title>` /
     * meta-description on the rendered page and the footer link label.
     */
    private function insertPageTranslations(): void
    {
        $keyword = self::PAGE_KEYWORD;

        foreach (self::PAGE_META as $locale => $meta) {
            $title = $this->escape($meta['title']);
            $description = $this->escape($meta['description']);

            // SEO `title` field
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
                SELECT p.id, f.id, l.id, '{$title}'
                FROM `pages` p
                JOIN `fields` f ON f.`name` = 'title'
                JOIN `languages` l ON l.`locale` = '{$locale}'
                WHERE p.`keyword` = '{$keyword}'
            SQL);

            // SEO `description` field
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
                SELECT p.id, f.id, l.id, '{$description}'
                FROM `pages` p
                JOIN `fields` f ON f.`name` = 'description'
                JOIN `languages` l ON l.`locale` = '{$locale}'
                WHERE p.`keyword` = '{$keyword}'
            SQL);
        }
    }

    /**
     * Mirror the ACL pattern used by other system legal pages
     * (impressum / agb / disclaimer):
     *
     *   - admin     : select + update only (cannot insert root or delete the page)
     *   - therapist : read-only
     *   - subject   : read-only
     *
     * Anonymous visitors bypass ACL via the `is_open_access = 1` flag set on
     * the page row, so no entry is needed for them.
     */
    private function insertAclRows(): void
    {
        $keyword = self::PAGE_KEYWORD;

        // (group_name, select, insert, update, delete)
        $rules = [
            ['admin',     1, 0, 1, 0],
            ['therapist', 1, 0, 0, 0],
            ['subject',   1, 0, 0, 0],
        ];

        foreach ($rules as [$group, $sel, $ins, $upd, $del]) {
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `acl_groups`
                    (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
                SELECT g.id, p.id, {$sel}, {$ins}, {$upd}, {$del}
                FROM `groups` g, `pages` p
                WHERE g.`name` = '{$group}' AND p.`keyword` = '{$keyword}'
            SQL);
        }
    }

    /**
     * Walk the SECTIONS table once to:
     *   1. Insert each `sections` row.
     *   2. Wire it into `pages_sections` (top-level) or `sections_hierarchy` (nested).
     *   3. Persist its non-translatable field values (language id 1).
     *   4. Persist its translatable field values for each locale.
     *
     * Position increments by 10 within each container so admins have room to
     * insert custom blocks between the seeded ones without re-numbering.
     */
    private function insertSections(): void
    {
        $prefix = self::SECTION_PREFIX;
        $keyword = self::PAGE_KEYWORD;

        // We track the next position per container so list-items inside a list
        // get ascending positions independently of the page-level ordering.
        $rootPos = 10;
        $childPos = [];

        foreach (self::SECTIONS as $entry) {
            $sectionName = $prefix . '-' . $entry['key'];
            $styleName = $entry['style'];
            $parentKey = $entry['parent'] ?? null;

            // 1) Section row
            $this->addSql(<<<SQL
                INSERT INTO `sections` (`id_styles`, `name`)
                SELECT s.id, '{$sectionName}'
                FROM `styles` s
                WHERE s.`name` = '{$styleName}'
            SQL);

            // 2) Wire into page or parent section
            if ($parentKey === null) {
                // Top-level section attached to the page
                $this->addSql(<<<SQL
                    INSERT INTO `pages_sections` (`id_pages`, `id_sections`, `position`)
                    SELECT p.id, sec.id, {$rootPos}
                    FROM `pages` p, `sections` sec
                    WHERE p.`keyword` = '{$keyword}' AND sec.`name` = '{$sectionName}'
                SQL);
                $rootPos += 10;
            } else {
                $parentName = $prefix . '-' . $parentKey;
                $childPos[$parentName] = ($childPos[$parentName] ?? 0) + 10;
                $pos = $childPos[$parentName];
                $this->addSql(<<<SQL
                    INSERT INTO `sections_hierarchy` (`parent`, `child`, `position`)
                    SELECT parent_sec.id, child_sec.id, {$pos}
                    FROM `sections` parent_sec, `sections` child_sec
                    WHERE parent_sec.`name` = '{$parentName}'
                      AND child_sec.`name` = '{$sectionName}'
                SQL);
            }

            // 3) Non-translatable field values (language id = 1, locale 'all')
            foreach ($entry['fields'] ?? [] as $fieldName => $value) {
                $escaped = $this->escape((string) $value);
                $this->addSql(<<<SQL
                    INSERT INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
                    SELECT sec.id, f.id, 1, '{$escaped}', NULL
                    FROM `sections` sec
                    JOIN `fields` f ON f.`name` = '{$fieldName}'
                    WHERE sec.`name` = '{$sectionName}'
                SQL);
            }

            // 4) Translatable field values, one row per locale
            foreach ($entry['translations'] ?? [] as $fieldName => $byLocale) {
                foreach ($byLocale as $locale => $value) {
                    $escaped = $this->escape($value);
                    $this->addSql(<<<SQL
                        INSERT INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
                        SELECT sec.id, f.id, l.id, '{$escaped}', NULL
                        FROM `sections` sec
                        JOIN `fields` f ON f.`name` = '{$fieldName}'
                        JOIN `languages` l ON l.`locale` = '{$locale}'
                        WHERE sec.`name` = '{$sectionName}'
                    SQL);
                }
            }
        }
    }

    /**
     * Escape a literal for direct interpolation into a single-quoted SQL
     * string. Only handles the characters our seeded content can produce
     * (single quote, backslash); we never accept user input here.
     */
    private function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
