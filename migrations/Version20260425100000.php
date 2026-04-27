<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed CMS content for the remaining out-of-the-box system pages.
 *
 * The privacy migration (`Version20260425090000`) introduced the pattern.
 * This follow-up migration applies the same approach to every other
 * `is_system = 1` page that ships with `new_create_db.sql` so a fresh
 * install boots with a complete, branded surface — login, 2FA, reset
 * password, validate, profile, home, missing, access-denied screens, and
 * the legal triplet (agb / impressum / disclaimer).
 *
 * Why these pages live in the CMS and not in static React routes:
 *   - Translatable per locale (en-GB + de-CH out of the box, more languages
 *     addable from the admin panel).
 *   - Operators of each instance can extend / rebrand them by adding their
 *     own sections from the admin UI — without touching the codebase.
 *   - Login / 2FA still keep a hardcoded fallback under
 *     `src/app/auth/{login,two-factor-authentication}/page.tsx` (frontend)
 *     so the platform is recoverable even if a migration is broken or an
 *     admin accidentally empties the page. The slug catch-all
 *     (`src/app/[[...slug]]/page.tsx`) redirects to that fallback when
 *     the CMS payload is empty.
 *
 * Pages that DO NOT need content here:
 *   - `profile-link` — only its TITLE is rendered (it is the avatar
 *     dropdown label). No body content.
 *   - `logout`       — clicking it just signs the user out and redirects
 *     to /login. No body content needed.
 *   - `privacy`      — handled by `Version20260425090000`.
 *
 * Cosmetic upgrades to the privacy page itself live in a separate
 * follow-up migration (`Version20260425100100`) so they can be reverted
 * independently of the seeding work.
 */
final class Version20260425100000 extends AbstractMigration
{
    /**
     * Locale → human label, used for the page-level title + description
     * translations. The two locales we ship with by default match the
     * privacy migration; additional locales can be added from the admin
     * UI without touching this file.
     */
    private const LOCALES = ['en-GB', 'de-CH'];

    public function getDescription(): string
    {
        return 'Seed remaining is_system CMS pages (login, 2fa, reset, validate, profile, home, missing, no_access, no_access_guest, agb, impressum, disclaimer).';
    }

    public function up(Schema $schema): void
    {
        // Some translations are missing on existing page rows — fix those
        // first so the SEO `<title>` is correct in every locale.
        $this->backfillPageTitleTranslations();

        // Seed page-level meta + ACL rows + sections, page by page. Every
        // call is idempotent at the row level: page meta uses INSERT
        // IGNORE on the unique (page, field, language) tuple, ACL rows
        // use INSERT IGNORE on the unique (group, page) tuple, and
        // section names follow a `<keyword>-<key>` pattern that is unique
        // to this migration so the `down()` clean-up is unambiguous.
        $this->seedFormPages();
        $this->seedProfilePage();
        $this->seedLandingAndStatusPages();
        $this->seedLegalPages();
    }

    public function down(Schema $schema): void
    {
        // Sections do NOT cascade off pages — delete by name pattern.
        // We use distinct prefixes per migration so privacy seeding is
        // unaffected by a rollback of this one.
        $prefixes = [
            'login-sys',
            'twofa-sys',
            'reset-sys',
            'validate-sys',
            'profile-sys',
            'home-sys',
            'missing-sys',
            'noaccess-sys',
            'noaccessguest-sys',
            'agb-sys',
            'impressum-sys',
            'disclaimer-sys',
        ];

        foreach ($prefixes as $prefix) {
            $this->addSql("DELETE FROM `sections` WHERE `name` LIKE '{$prefix}-%'");
        }

        // Page-level translations + ACL rows cascade off page id, but the
        // page rows themselves were created by `new_create_db.sql` and
        // must NOT be deleted here.
    }

    // ------------------------------------------------------------------
    // Page-level title backfill
    // ------------------------------------------------------------------

    /**
     * Some shipped page rows have a German title but no English one (or
     * vice versa). Add the missing translations so SEO + footer labels
     * never fall back to the keyword.
     */
    private function backfillPageTitleTranslations(): void
    {
        $titles = [
            'two-factor-authentication' => [
                'en-GB' => 'Two-Factor Authentication',
            ],
        ];

        foreach ($titles as $keyword => $byLocale) {
            foreach ($byLocale as $locale => $title) {
                $escaped = $this->escape($title);
                $this->addSql(<<<SQL
                    INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
                    SELECT p.id, f.id, l.id, '{$escaped}'
                    FROM `pages` p
                    JOIN `fields` f ON f.`name` = 'title'
                    JOIN `languages` l ON l.`locale` = '{$locale}'
                    WHERE p.`keyword` = '{$keyword}'
                SQL);
            }
        }
    }

    // ------------------------------------------------------------------
    // Form pages: login, two-factor, reset password, validate
    // ------------------------------------------------------------------

    /**
     * Seed each form page with one section that uses its dedicated
     * styled component (LoginStyle, TwoFactorAuthStyle, ResetPasswordStyle,
     * ValidateStyle) plus a card/paper wrapper for visual breathing room.
     *
     * The styled components read every label from CMS fields and fall
     * back to hardcoded English defaults when a field is missing. Seeding
     * the field translations gives admins an editable starting point in
     * both English and German.
     */
    private function seedFormPages(): void
    {
        // login --------------------------------------------------------
        $this->seedPageSections(
            keyword: 'login',
            prefix: 'login-sys',
            descriptions: [
                'en-GB' => 'Sign in to your SelfHelp account.',
                'de-CH' => 'Melden Sie sich bei Ihrem SelfHelp-Konto an.',
            ],
            sections: [
                [
                    'key' => 'wrapper',
                    'style' => 'container',
                    'fields' => [
                        'mantine_size' => 'xs',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'form',
                    'style' => 'login',
                    'parent' => 'wrapper',
                    'fields' => [
                        'type' => 'light',
                    ],
                    'translations' => [
                        'login_title' => [
                            'en-GB' => 'Welcome back',
                            'de-CH' => 'Willkommen zurück',
                        ],
                        'label_user' => [
                            'en-GB' => 'Email or username',
                            'de-CH' => 'E-Mail oder Benutzername',
                        ],
                        'label_pw' => [
                            'en-GB' => 'Password',
                            'de-CH' => 'Passwort',
                        ],
                        'label_login' => [
                            'en-GB' => 'Sign in',
                            'de-CH' => 'Anmelden',
                        ],
                        'label_pw_reset' => [
                            'en-GB' => 'Forgot your password?',
                            'de-CH' => 'Passwort vergessen?',
                        ],
                        'alert_fail' => [
                            'en-GB' => 'Invalid email or password. Please check your credentials and try again.',
                            'de-CH' => 'Ungültige E-Mail-Adresse oder ungültiges Passwort. Bitte überprüfen Sie Ihre Zugangsdaten und versuchen Sie es erneut.',
                        ],
                    ],
                ],
            ]
        );

        // two-factor-authentication -----------------------------------
        $this->seedPageSections(
            keyword: 'two-factor-authentication',
            prefix: 'twofa-sys',
            descriptions: [
                'en-GB' => 'Enter the verification code we just sent to your email to complete sign-in.',
                'de-CH' => 'Geben Sie den Bestätigungscode ein, den wir Ihnen soeben per E-Mail gesendet haben, um die Anmeldung abzuschliessen.',
            ],
            sections: [
                [
                    'key' => 'wrapper',
                    'style' => 'container',
                    'fields' => [
                        'mantine_size' => 'xs',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'form',
                    'style' => 'twoFactorAuth',
                    'parent' => 'wrapper',
                    'translations' => [
                        'label' => [
                            'en-GB' => 'Two-factor authentication',
                            'de-CH' => 'Zwei-Faktor-Authentifizierung',
                        ],
                        'text_md' => [
                            'en-GB' => 'For your security we have sent a 6-digit verification code to your email address. Enter it below to finish signing in.',
                            'de-CH' => 'Aus Sicherheitsgründen haben wir Ihnen einen 6-stelligen Bestätigungscode an Ihre E-Mail-Adresse gesendet. Geben Sie ihn unten ein, um die Anmeldung abzuschliessen.',
                        ],
                        'label_expiration_2fa' => [
                            'en-GB' => 'Code expires in',
                            'de-CH' => 'Code läuft ab in',
                        ],
                        'alert_fail' => [
                            'en-GB' => 'Invalid verification code. Please try again or request a new one.',
                            'de-CH' => 'Ungültiger Bestätigungscode. Bitte versuchen Sie es erneut oder fordern Sie einen neuen Code an.',
                        ],
                    ],
                ],
            ]
        );

        // reset_password ----------------------------------------------
        $this->seedPageSections(
            keyword: 'reset_password',
            prefix: 'reset-sys',
            descriptions: [
                'en-GB' => 'Request a password-reset link by entering the email address linked to your account.',
                'de-CH' => 'Fordern Sie einen Link zum Zurücksetzen Ihres Passworts an, indem Sie die mit Ihrem Konto verknüpfte E-Mail-Adresse eingeben.',
            ],
            sections: [
                [
                    'key' => 'wrapper',
                    'style' => 'container',
                    'fields' => [
                        'mantine_size' => 'sm',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'header',
                    'style' => 'title',
                    'parent' => 'wrapper',
                    'fields' => ['mantine_title_order' => '1'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Reset your password',
                            'de-CH' => 'Passwort zurücksetzen',
                        ],
                    ],
                ],
                [
                    'key' => 'lead',
                    'style' => 'text',
                    'parent' => 'wrapper',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'Enter the email address associated with your account and we will send you a secure link to choose a new password.',
                            'de-CH' => 'Geben Sie die mit Ihrem Konto verknüpfte E-Mail-Adresse ein. Wir senden Ihnen einen sicheren Link, mit dem Sie ein neues Passwort vergeben können.',
                        ],
                    ],
                ],
                [
                    'key' => 'form',
                    'style' => 'resetPassword',
                    'parent' => 'wrapper',
                    'fields' => [
                        'is_html' => '0',
                        'type' => 'blue',
                    ],
                    'translations' => [
                        'label_pw_reset' => [
                            'en-GB' => 'Send reset link',
                            'de-CH' => 'Reset-Link senden',
                        ],
                        'placeholder' => [
                            'en-GB' => 'you@example.com',
                            'de-CH' => 'sie@beispiel.ch',
                        ],
                        'alert_success' => [
                            'en-GB' => 'If an account exists for that email, we have just sent a reset link. Check your inbox (and your spam folder).',
                            'de-CH' => 'Wenn für diese E-Mail-Adresse ein Konto besteht, haben wir Ihnen soeben einen Link zum Zurücksetzen gesendet. Bitte prüfen Sie Ihren Posteingang (und gegebenenfalls den Spam-Ordner).',
                        ],
                        'subject_user' => [
                            'en-GB' => '@project — reset your password',
                            'de-CH' => '@project — Passwort zurücksetzen',
                        ],
                        'email_user' => [
                            'en-GB' => "Hello,\n\nWe received a request to reset the password on your @project account. Click the link below to choose a new password:\n\n@link\n\nIf you did not request this, you can safely ignore this email — your password will not change.\n\nThank you,\nthe @project team",
                            'de-CH' => "Hallo,\n\nWir haben eine Anfrage erhalten, das Passwort Ihres @project-Kontos zurückzusetzen. Klicken Sie auf den untenstehenden Link, um ein neues Passwort zu wählen:\n\n@link\n\nFalls Sie diese Anfrage nicht selbst gestellt haben, können Sie diese E-Mail einfach ignorieren — Ihr Passwort bleibt unverändert.\n\nVielen Dank,\nIhr @project-Team",
                        ],
                    ],
                ],
            ]
        );

        // validate ----------------------------------------------------
        $this->seedPageSections(
            keyword: 'validate',
            prefix: 'validate-sys',
            descriptions: [
                'en-GB' => 'Activate your SelfHelp account by choosing a display name and a password.',
                'de-CH' => 'Aktivieren Sie Ihr SelfHelp-Konto, indem Sie einen Anzeigenamen und ein Passwort wählen.',
            ],
            sections: [
                [
                    'key' => 'wrapper',
                    'style' => 'container',
                    'fields' => [
                        'mantine_size' => 'sm',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'form',
                    'style' => 'validate',
                    'parent' => 'wrapper',
                    'fields' => [
                        'name' => 'validate_form',
                        'mantine_card_padding' => 'lg',
                        'mantine_card_shadow' => 'sm',
                        'mantine_radius' => 'md',
                        'mantine_border' => '1',
                        'mantine_buttons_size' => 'md',
                        'mantine_buttons_variant' => 'filled',
                        'mantine_btn_save_color' => 'blue',
                    ],
                    'translations' => [
                        'title' => [
                            'en-GB' => 'Activate your account',
                            'de-CH' => 'Konto aktivieren',
                        ],
                        'subtitle' => [
                            'en-GB' => 'Welcome! Choose a display name and a password to finish setting up your account.',
                            'de-CH' => 'Herzlich willkommen! Wählen Sie einen Anzeigenamen und ein Passwort, um die Einrichtung Ihres Kontos abzuschliessen.',
                        ],
                        'label_name' => [
                            'en-GB' => 'Display name',
                            'de-CH' => 'Anzeigename',
                        ],
                        'name_placeholder' => [
                            'en-GB' => 'How should we call you?',
                            'de-CH' => 'Wie sollen wir Sie nennen?',
                        ],
                        'name_description' => [
                            'en-GB' => 'This name is shown to other members of your research project.',
                            'de-CH' => 'Dieser Name wird anderen Teilnehmenden Ihres Forschungsprojekts angezeigt.',
                        ],
                        'label_pw' => [
                            'en-GB' => 'Password',
                            'de-CH' => 'Passwort',
                        ],
                        'pw_placeholder' => [
                            'en-GB' => 'Choose a strong password',
                            'de-CH' => 'Wählen Sie ein starkes Passwort',
                        ],
                        'label_pw_confirm' => [
                            'en-GB' => 'Confirm password',
                            'de-CH' => 'Passwort bestätigen',
                        ],
                        'label_activate' => [
                            'en-GB' => 'Activate account',
                            'de-CH' => 'Konto aktivieren',
                        ],
                        'alert_success' => [
                            'en-GB' => 'Your account has been activated. You can now sign in.',
                            'de-CH' => 'Ihr Konto wurde aktiviert. Sie können sich jetzt anmelden.',
                        ],
                        'alert_fail' => [
                            'en-GB' => 'We could not activate your account. The link may have expired — please request a new one.',
                            'de-CH' => 'Wir konnten Ihr Konto nicht aktivieren. Der Link ist möglicherweise abgelaufen — bitte fordern Sie einen neuen an.',
                        ],
                    ],
                ],
            ]
        );
    }

    // ------------------------------------------------------------------
    // Profile page
    // ------------------------------------------------------------------

    /**
     * The profile style is a single rich React component that renders
     * the entire account-management surface (account info / display name
     * / timezone / password / account deletion). Seed it with a single
     * `profile` section and let admins tweak labels per locale.
     */
    private function seedProfilePage(): void
    {
        $this->seedPageSections(
            keyword: 'profile',
            prefix: 'profile-sys',
            descriptions: [
                'en-GB' => 'Manage your SelfHelp account: change your display name, password, timezone, or delete your account.',
                'de-CH' => 'Verwalten Sie Ihr SelfHelp-Konto: Anzeigename, Passwort, Zeitzone ändern oder Konto löschen.',
            ],
            sections: [
                [
                    'key' => 'wrapper',
                    'style' => 'container',
                    'fields' => [
                        'mantine_size' => 'lg',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'profile',
                    'style' => 'profile',
                    'parent' => 'wrapper',
                    'fields' => [
                        'profile_columns' => '2',
                        'profile_gap' => 'md',
                        'profile_use_accordion' => '0',
                        'profile_variant' => 'default',
                        'profile_radius' => 'md',
                        'profile_shadow' => 'sm',
                    ],
                    'translations' => [
                        'profile_title' => [
                            'en-GB' => 'My account',
                            'de-CH' => 'Mein Konto',
                        ],
                        'profile_account_info_title' => [
                            'en-GB' => 'Account information',
                            'de-CH' => 'Kontoinformationen',
                        ],
                        'profile_label_email' => [
                            'en-GB' => 'Email',
                            'de-CH' => 'E-Mail',
                        ],
                        'profile_label_username' => [
                            'en-GB' => 'Username',
                            'de-CH' => 'Benutzername',
                        ],
                        'profile_label_name' => [
                            'en-GB' => 'Display name',
                            'de-CH' => 'Anzeigename',
                        ],
                        'profile_label_created' => [
                            'en-GB' => 'Account created',
                            'de-CH' => 'Konto erstellt',
                        ],
                        'profile_label_last_login' => [
                            'en-GB' => 'Last login',
                            'de-CH' => 'Letzte Anmeldung',
                        ],
                        'profile_label_timezone' => [
                            'en-GB' => 'Timezone',
                            'de-CH' => 'Zeitzone',
                        ],
                        'profile_name_change_title' => [
                            'en-GB' => 'Display name',
                            'de-CH' => 'Anzeigename',
                        ],
                        'profile_name_change_description' => [
                            'en-GB' => '<p>Your display name is what other members of your research project will see. Pick something you are happy to be addressed by.</p>',
                            'de-CH' => '<p>Ihr Anzeigename ist das, was andere Teilnehmende Ihres Forschungsprojekts sehen. Wählen Sie etwas, mit dem Sie gerne angesprochen werden.</p>',
                        ],
                        'profile_name_change_label' => [
                            'en-GB' => 'New display name',
                            'de-CH' => 'Neuer Anzeigename',
                        ],
                        'profile_name_change_button' => [
                            'en-GB' => 'Update display name',
                            'de-CH' => 'Anzeigenamen aktualisieren',
                        ],
                        'profile_name_change_success' => [
                            'en-GB' => 'Display name updated.',
                            'de-CH' => 'Anzeigename aktualisiert.',
                        ],
                        'profile_password_reset_title' => [
                            'en-GB' => 'Password',
                            'de-CH' => 'Passwort',
                        ],
                        'profile_password_reset_description' => [
                            'en-GB' => '<p>Choose a new password. We recommend at least 12 characters mixing letters, numbers, and symbols.</p>',
                            'de-CH' => '<p>Wählen Sie ein neues Passwort. Wir empfehlen mindestens 12 Zeichen, mit Buchstaben, Zahlen und Sonderzeichen.</p>',
                        ],
                        'profile_password_reset_label_current' => [
                            'en-GB' => 'Current password',
                            'de-CH' => 'Aktuelles Passwort',
                        ],
                        'profile_password_reset_label_new' => [
                            'en-GB' => 'New password',
                            'de-CH' => 'Neues Passwort',
                        ],
                        'profile_password_reset_label_confirm' => [
                            'en-GB' => 'Confirm new password',
                            'de-CH' => 'Neues Passwort bestätigen',
                        ],
                        'profile_password_reset_button' => [
                            'en-GB' => 'Update password',
                            'de-CH' => 'Passwort aktualisieren',
                        ],
                        'profile_password_reset_success' => [
                            'en-GB' => 'Password updated.',
                            'de-CH' => 'Passwort aktualisiert.',
                        ],
                        'profile_timezone_change_title' => [
                            'en-GB' => 'Timezone',
                            'de-CH' => 'Zeitzone',
                        ],
                        'profile_timezone_change_description' => [
                            'en-GB' => '<p>Pick the timezone you want all dates and times on the platform to be displayed in.</p>',
                            'de-CH' => '<p>Wählen Sie die Zeitzone, in der alle Datums- und Zeitangaben auf der Plattform angezeigt werden sollen.</p>',
                        ],
                        'profile_timezone_change_label' => [
                            'en-GB' => 'Timezone',
                            'de-CH' => 'Zeitzone',
                        ],
                        'profile_timezone_change_button' => [
                            'en-GB' => 'Update timezone',
                            'de-CH' => 'Zeitzone aktualisieren',
                        ],
                        'profile_timezone_change_success' => [
                            'en-GB' => 'Timezone updated.',
                            'de-CH' => 'Zeitzone aktualisiert.',
                        ],
                        'profile_delete_title' => [
                            'en-GB' => 'Delete account',
                            'de-CH' => 'Konto löschen',
                        ],
                        'profile_delete_description' => [
                            'en-GB' => '<p>Permanently delete your account and every piece of data we hold about you. This is irreversible — once your account is deleted there is no way to restore it.</p>',
                            'de-CH' => '<p>Löschen Sie Ihr Konto und alle Daten, die wir über Sie speichern, dauerhaft. Dieser Vorgang kann nicht rückgängig gemacht werden — gelöschte Konten lassen sich nicht wiederherstellen.</p>',
                        ],
                        'profile_delete_alert_text' => [
                            'en-GB' => 'This action cannot be undone. All your data will be permanently deleted.',
                            'de-CH' => 'Dieser Vorgang kann nicht rückgängig gemacht werden. Alle Ihre Daten werden dauerhaft gelöscht.',
                        ],
                        'profile_delete_modal_warning' => [
                            'en-GB' => '<p>Deleting your account will remove your profile, your form responses, and any content you have created on this platform. Active research projects you are participating in will lose your contributions. Type your email address below to confirm.</p>',
                            'de-CH' => '<p>Wenn Sie Ihr Konto löschen, werden Ihr Profil, Ihre Formularantworten und alle Inhalte, die Sie auf dieser Plattform erstellt haben, entfernt. Aktive Forschungsprojekte, an denen Sie teilnehmen, verlieren Ihre Beiträge. Geben Sie zur Bestätigung Ihre E-Mail-Adresse ein.</p>',
                        ],
                        'profile_delete_label_email' => [
                            'en-GB' => 'Confirm by typing your email',
                            'de-CH' => 'Bestätigen Sie durch Eingabe Ihrer E-Mail',
                        ],
                        'profile_delete_button' => [
                            'en-GB' => 'Delete my account',
                            'de-CH' => 'Konto löschen',
                        ],
                        'profile_delete_success' => [
                            'en-GB' => 'Account deleted.',
                            'de-CH' => 'Konto gelöscht.',
                        ],
                    ],
                ],
            ]
        );
    }

    // ------------------------------------------------------------------
    // Landing + status pages: home, missing, no_access, no_access_guest
    // ------------------------------------------------------------------

    private function seedLandingAndStatusPages(): void
    {
        // home --------------------------------------------------------
        $this->seedPageSections(
            keyword: 'home',
            prefix: 'home-sys',
            descriptions: [
                'en-GB' => 'SelfHelp — a research platform for translating evidence into care.',
                'de-CH' => 'SelfHelp — eine Forschungsplattform, die Evidenz in Versorgung übersetzt.',
            ],
            sections: [
                [
                    'key' => 'hero-paper',
                    'style' => 'paper',
                    'fields' => [
                        'mantine_paper_shadow' => 'sm',
                        'mantine_radius' => 'md',
                        'mantine_border' => '1',
                        'mantine_px' => 'xl',
                        'mantine_py' => 'xl',
                        'mantine_spacing_margin_padding' => '{"mb":"lg"}',
                    ],
                ],
                [
                    'key' => 'hero-icon',
                    'style' => 'theme-icon',
                    'parent' => 'hero-paper',
                    'fields' => [
                        'mantine_color' => 'blue',
                        'mantine_variant' => 'light',
                        'mantine_size' => '64px',
                        'mantine_radius' => 'xl',
                        'mantine_left_icon' => 'IconHeartHandshake',
                    ],
                ],
                [
                    'key' => 'hero-title',
                    'style' => 'title',
                    'parent' => 'hero-paper',
                    'fields' => ['mantine_title_order' => '1'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Welcome to SelfHelp',
                            'de-CH' => 'Willkommen bei SelfHelp',
                        ],
                    ],
                ],
                [
                    'key' => 'hero-text',
                    'style' => 'text',
                    'parent' => 'hero-paper',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'A research support platform for designing, running, and analysing studies that put participants first. Sign in with the credentials your research team gave you to access your project.',
                            'de-CH' => 'Eine Plattform zur Unterstützung von Forschung — entwickeln, durchführen und auswerten Sie Studien, die die Teilnehmenden in den Mittelpunkt stellen. Melden Sie sich mit den Zugangsdaten an, die Sie von Ihrem Forschungsteam erhalten haben, um zu Ihrem Projekt zu gelangen.',
                        ],
                    ],
                ],
                [
                    'key' => 'features-title',
                    'style' => 'title',
                    'fields' => [
                        'mantine_title_order' => '2',
                        'mantine_spacing_margin' => '{"mt":"lg","mb":"sm"}',
                    ],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'What you can do here',
                            'de-CH' => 'Was Sie hier tun können',
                        ],
                    ],
                ],
                [
                    'key' => 'features-list',
                    'style' => 'list',
                    'fields' => ['mantine_list_list_style_type' => 'disc'],
                ],
                [
                    'key' => 'feat-1',
                    'style' => 'list-item',
                    'parent' => 'features-list',
                    'translations' => [
                        'mantine_list_item_content' => [
                            'en-GB' => 'Take part in the surveys, diaries, and interventions your research team has set up.',
                            'de-CH' => 'Nehmen Sie an Umfragen, Tagebüchern und Interventionen teil, die Ihr Forschungsteam für Sie eingerichtet hat.',
                        ],
                    ],
                ],
                [
                    'key' => 'feat-2',
                    'style' => 'list-item',
                    'parent' => 'features-list',
                    'translations' => [
                        'mantine_list_item_content' => [
                            'en-GB' => 'Track your own progress and review the data you contributed.',
                            'de-CH' => 'Verfolgen Sie Ihren persönlichen Fortschritt und überprüfen Sie die von Ihnen beigetragenen Daten.',
                        ],
                    ],
                ],
                [
                    'key' => 'feat-3',
                    'style' => 'list-item',
                    'parent' => 'features-list',
                    'translations' => [
                        'mantine_list_item_content' => [
                            'en-GB' => 'Reach the research team through the in-platform messaging if your project provides it.',
                            'de-CH' => 'Erreichen Sie das Forschungsteam über die plattforminterne Nachrichtenfunktion, falls Ihr Projekt diese anbietet.',
                        ],
                    ],
                ],
            ]
        );

        // missing (404) -----------------------------------------------
        $this->seedPageSections(
            keyword: 'missing',
            prefix: 'missing-sys',
            descriptions: [
                'en-GB' => 'The page you are looking for could not be found.',
                'de-CH' => 'Die gesuchte Seite konnte nicht gefunden werden.',
            ],
            sections: [
                [
                    'key' => 'wrapper',
                    'style' => 'container',
                    'fields' => [
                        'mantine_size' => 'sm',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'paper',
                    'style' => 'paper',
                    'parent' => 'wrapper',
                    'fields' => [
                        'mantine_paper_shadow' => 'sm',
                        'mantine_radius' => 'md',
                        'mantine_border' => '1',
                        'mantine_px' => 'xl',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'icon',
                    'style' => 'theme-icon',
                    'parent' => 'paper',
                    'fields' => [
                        'mantine_color' => 'gray',
                        'mantine_variant' => 'light',
                        'mantine_size' => '64px',
                        'mantine_radius' => 'xl',
                        'mantine_left_icon' => 'IconCompass',
                    ],
                ],
                [
                    'key' => 'title',
                    'style' => 'title',
                    'parent' => 'paper',
                    'fields' => ['mantine_title_order' => '1'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Page not found',
                            'de-CH' => 'Seite nicht gefunden',
                        ],
                    ],
                ],
                [
                    'key' => 'text',
                    'style' => 'text',
                    'parent' => 'paper',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'The page you are looking for does not exist or has been moved. Please check the URL or head back to the home page.',
                            'de-CH' => 'Die gesuchte Seite existiert nicht oder wurde verschoben. Bitte überprüfen Sie die URL oder kehren Sie zur Startseite zurück.',
                        ],
                    ],
                ],
                [
                    'key' => 'home-button',
                    'style' => 'button',
                    'parent' => 'paper',
                    'fields' => [
                        'mantine_color' => 'blue',
                        'mantine_variant' => 'filled',
                        'mantine_size' => 'md',
                        'mantine_radius' => 'sm',
                        'mantine_left_icon' => 'IconHome',
                        'page_keyword' => 'home',
                        'is_link' => '1',
                    ],
                    'translations' => [
                        'label' => [
                            'en-GB' => 'Back to home',
                            'de-CH' => 'Zur Startseite',
                        ],
                    ],
                ],
            ]
        );

        // no_access (signed-in but lacking permission) ----------------
        $this->seedPageSections(
            keyword: 'no_access',
            prefix: 'noaccess-sys',
            descriptions: [
                'en-GB' => 'You do not have access to this area.',
                'de-CH' => 'Sie haben keinen Zugriff auf diesen Bereich.',
            ],
            sections: [
                [
                    'key' => 'wrapper',
                    'style' => 'container',
                    'fields' => [
                        'mantine_size' => 'sm',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'paper',
                    'style' => 'paper',
                    'parent' => 'wrapper',
                    'fields' => [
                        'mantine_paper_shadow' => 'sm',
                        'mantine_radius' => 'md',
                        'mantine_border' => '1',
                        'mantine_px' => 'xl',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'icon',
                    'style' => 'theme-icon',
                    'parent' => 'paper',
                    'fields' => [
                        'mantine_color' => 'red',
                        'mantine_variant' => 'light',
                        'mantine_size' => '64px',
                        'mantine_radius' => 'xl',
                        'mantine_left_icon' => 'IconLock',
                    ],
                ],
                [
                    'key' => 'title',
                    'style' => 'title',
                    'parent' => 'paper',
                    'fields' => ['mantine_title_order' => '1'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Access denied',
                            'de-CH' => 'Zugriff verweigert',
                        ],
                    ],
                ],
                [
                    'key' => 'text',
                    'style' => 'text',
                    'parent' => 'paper',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'Your account does not have permission to view this page. If you think this is a mistake, please contact the research team or your administrator.',
                            'de-CH' => 'Ihr Konto verfügt nicht über die nötigen Rechte, um diese Seite anzuzeigen. Falls Sie der Meinung sind, dass es sich um einen Fehler handelt, wenden Sie sich bitte an das Forschungsteam oder Ihre Administratorin / Ihren Administrator.',
                        ],
                    ],
                ],
                [
                    'key' => 'home-button',
                    'style' => 'button',
                    'parent' => 'paper',
                    'fields' => [
                        'mantine_color' => 'blue',
                        'mantine_variant' => 'light',
                        'mantine_size' => 'md',
                        'mantine_radius' => 'sm',
                        'mantine_left_icon' => 'IconHome',
                        'page_keyword' => 'home',
                        'is_link' => '1',
                    ],
                    'translations' => [
                        'label' => [
                            'en-GB' => 'Back to home',
                            'de-CH' => 'Zur Startseite',
                        ],
                    ],
                ],
            ]
        );

        // no_access_guest (anonymous visitor blocked) -----------------
        $this->seedPageSections(
            keyword: 'no_access_guest',
            prefix: 'noaccessguest-sys',
            descriptions: [
                'en-GB' => 'Sign in to access this area.',
                'de-CH' => 'Melden Sie sich an, um auf diesen Bereich zuzugreifen.',
            ],
            sections: [
                [
                    'key' => 'wrapper',
                    'style' => 'container',
                    'fields' => [
                        'mantine_size' => 'sm',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'paper',
                    'style' => 'paper',
                    'parent' => 'wrapper',
                    'fields' => [
                        'mantine_paper_shadow' => 'sm',
                        'mantine_radius' => 'md',
                        'mantine_border' => '1',
                        'mantine_px' => 'xl',
                        'mantine_py' => 'xl',
                    ],
                ],
                [
                    'key' => 'icon',
                    'style' => 'theme-icon',
                    'parent' => 'paper',
                    'fields' => [
                        'mantine_color' => 'blue',
                        'mantine_variant' => 'light',
                        'mantine_size' => '64px',
                        'mantine_radius' => 'xl',
                        'mantine_left_icon' => 'IconUserShield',
                    ],
                ],
                [
                    'key' => 'title',
                    'style' => 'title',
                    'parent' => 'paper',
                    'fields' => ['mantine_title_order' => '1'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Sign in required',
                            'de-CH' => 'Anmeldung erforderlich',
                        ],
                    ],
                ],
                [
                    'key' => 'text',
                    'style' => 'text',
                    'parent' => 'paper',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'This area is only available to signed-in users. Please sign in with the credentials your research team gave you to continue.',
                            'de-CH' => 'Dieser Bereich steht nur angemeldeten Personen zur Verfügung. Bitte melden Sie sich mit den Zugangsdaten an, die Sie von Ihrem Forschungsteam erhalten haben, um fortzufahren.',
                        ],
                    ],
                ],
                [
                    'key' => 'login-button',
                    'style' => 'button',
                    'parent' => 'paper',
                    'fields' => [
                        'mantine_color' => 'blue',
                        'mantine_variant' => 'filled',
                        'mantine_size' => 'md',
                        'mantine_radius' => 'sm',
                        'mantine_left_icon' => 'IconLogin',
                        'page_keyword' => 'login',
                        'is_link' => '1',
                    ],
                    'translations' => [
                        'label' => [
                            'en-GB' => 'Sign in',
                            'de-CH' => 'Anmelden',
                        ],
                    ],
                ],
            ]
        );
    }

    // ------------------------------------------------------------------
    // Legal pages: agb, impressum, disclaimer
    // ------------------------------------------------------------------

    /**
     * `agb`, `impressum`, and `disclaimer` are operator-specific in
     * the same way the privacy notice is — the institution running each
     * SelfHelp instance is the data controller and must set them. We seed
     * sensible defaults so the pages are never blank, and operators
     * adjust the wording from the admin UI before going live.
     *
     * `impressum` includes a hand-curated "components & versions" list
     * because EU operators must disclose the technical components in
     * their imprint. The list is plain content rather than a dynamic
     * `version` style because we have no live `version` React renderer
     * yet. Operators can replace it with the dynamic style as soon as
     * one ships.
     */
    private function seedLegalPages(): void
    {
        // agb (Terms & Conditions / GTC) ------------------------------
        $this->seedPageSections(
            keyword: 'agb',
            prefix: 'agb-sys',
            descriptions: [
                'en-GB' => 'General terms and conditions for using the SelfHelp research platform.',
                'de-CH' => 'Allgemeine Geschäftsbedingungen für die Nutzung der SelfHelp-Forschungsplattform.',
            ],
            sections: [
                [
                    'key' => 'h1',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '1'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'General Terms & Conditions',
                            'de-CH' => 'Allgemeine Geschäftsbedingungen',
                        ],
                    ],
                ],
                [
                    'key' => 'intro',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'These terms govern your use of the SelfHelp research platform operated by the research institution responsible for the project that gave you access. Please read them carefully — by using the platform you accept them in full.',
                            'de-CH' => 'Diese Bedingungen regeln Ihre Nutzung der SelfHelp-Forschungsplattform, betrieben durch die Forschungsinstitution, die Ihnen den Zugang gewährt hat. Bitte lesen Sie sie sorgfältig — durch die Nutzung der Plattform akzeptieren Sie sie vollumfänglich.',
                        ],
                    ],
                ],
                [
                    'key' => 'placeholder-alert',
                    'style' => 'alert',
                    'fields' => [
                        'mantine_color' => 'yellow',
                        'mantine_variant' => 'light',
                        'mantine_radius' => 'md',
                        'mantine_left_icon' => 'IconAlertTriangle',
                    ],
                    'translations' => [
                        'mantine_alert_title' => [
                            'en-GB' => 'Operator action required',
                            'de-CH' => 'Anpassung durch Betreiber erforderlich',
                        ],
                        'value' => [
                            'en-GB' => 'This is a default scaffold. Replace this notice (and the placeholder sections below) with the actual terms of the institution operating this instance before going live with real users.',
                            'de-CH' => 'Dies ist eine Standardvorlage. Ersetzen Sie diesen Hinweis (und die nachfolgenden Platzhalter-Abschnitte) durch die tatsächlichen Bedingungen der betreibenden Institution, bevor Sie Endnutzerinnen und Endnutzer auf die Plattform lassen.',
                        ],
                    ],
                ],
                [
                    'key' => 'h2-scope',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '2'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Scope',
                            'de-CH' => 'Geltungsbereich',
                        ],
                    ],
                ],
                [
                    'key' => 'scope-text',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'These terms apply to every visitor and registered user of this SelfHelp instance, including participants in research projects, members of research teams, and administrators. Specific research projects may add additional terms; in case of conflict, the project-specific terms prevail.',
                            'de-CH' => 'Diese Bedingungen gelten für alle Besuchenden und registrierten Personen dieser SelfHelp-Instanz, einschliesslich Teilnehmenden an Forschungsprojekten, Mitgliedern der Forschungsteams sowie Administratorinnen und Administratoren. Einzelne Forschungsprojekte können zusätzliche Bedingungen aufstellen; im Konfliktfall haben die projektspezifischen Bedingungen Vorrang.',
                        ],
                    ],
                ],
                [
                    'key' => 'h2-eligibility',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '2'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Eligibility',
                            'de-CH' => 'Teilnahmevoraussetzungen',
                        ],
                    ],
                ],
                [
                    'key' => 'eligibility-text',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'You may use the platform only if the research team responsible for your project has invited you, the project has the required ethical approval, and you meet the eligibility criteria of that specific project (typically: minimum age, capacity to consent, and the absence of any contraindication communicated to you in the consent process).',
                            'de-CH' => 'Sie dürfen die Plattform nur nutzen, wenn das für Ihr Projekt zuständige Forschungsteam Sie eingeladen hat, das Projekt über die erforderliche Ethikbewilligung verfügt und Sie die Teilnahmevoraussetzungen dieses Projekts erfüllen (in der Regel: Mindestalter, Einwilligungsfähigkeit und keine Kontraindikationen, wie sie Ihnen im Einwilligungsverfahren mitgeteilt wurden).',
                        ],
                    ],
                ],
                [
                    'key' => 'h2-conduct',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '2'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Acceptable use',
                            'de-CH' => 'Zulässige Nutzung',
                        ],
                    ],
                ],
                [
                    'key' => 'conduct-list',
                    'style' => 'list',
                    'fields' => ['mantine_list_list_style_type' => 'disc'],
                ],
                [
                    'key' => 'conduct-1',
                    'style' => 'list-item',
                    'parent' => 'conduct-list',
                    'translations' => [
                        'mantine_list_item_content' => [
                            'en-GB' => 'Do not attempt to access another user’s account or data.',
                            'de-CH' => 'Greifen Sie nicht auf das Konto oder die Daten anderer Personen zu.',
                        ],
                    ],
                ],
                [
                    'key' => 'conduct-2',
                    'style' => 'list-item',
                    'parent' => 'conduct-list',
                    'translations' => [
                        'mantine_list_item_content' => [
                            'en-GB' => 'Do not upload content that is unlawful, harassing, or that you do not have the right to share.',
                            'de-CH' => 'Laden Sie keine Inhalte hoch, die rechtswidrig oder belästigend sind oder zu deren Weitergabe Sie nicht berechtigt sind.',
                        ],
                    ],
                ],
                [
                    'key' => 'conduct-3',
                    'style' => 'list-item',
                    'parent' => 'conduct-list',
                    'translations' => [
                        'mantine_list_item_content' => [
                            'en-GB' => 'Do not probe, scan, or attempt to bypass the platform’s security mechanisms.',
                            'de-CH' => 'Versuchen Sie nicht, die Sicherheitsmechanismen der Plattform zu umgehen, zu testen oder zu prüfen.',
                        ],
                    ],
                ],
                [
                    'key' => 'h2-liability',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '2'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Liability',
                            'de-CH' => 'Haftung',
                        ],
                    ],
                ],
                [
                    'key' => 'liability-text',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'The platform is provided "as is" for research purposes. The institution operating this instance excludes any liability for indirect or consequential damages to the extent permitted by the applicable law of the country where the institution is established.',
                            'de-CH' => 'Die Plattform wird «wie sie ist» für Forschungszwecke bereitgestellt. Die betreibende Institution schliesst jegliche Haftung für indirekte Schäden oder Folgeschäden im Rahmen des anwendbaren Rechts des Landes, in dem die Institution ihren Sitz hat, aus.',
                        ],
                    ],
                ],
            ]
        );

        // impressum (Imprint + version table) -------------------------
        $this->seedPageSections(
            keyword: 'impressum',
            prefix: 'impressum-sys',
            descriptions: [
                'en-GB' => 'Imprint and technical-component disclosure for the SelfHelp platform.',
                'de-CH' => 'Impressum und Offenlegung der technischen Komponenten der SelfHelp-Plattform.',
            ],
            sections: [
                [
                    'key' => 'h1',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '1'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Imprint',
                            'de-CH' => 'Impressum',
                        ],
                    ],
                ],
                [
                    'key' => 'intro',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'This SelfHelp instance is operated by the research institution that provided your access. Please contact them directly for any legal, billing, or research-related questions.',
                            'de-CH' => 'Diese SelfHelp-Instanz wird von der Forschungsinstitution betrieben, die Ihnen den Zugang gewährt hat. Bitte wenden Sie sich für rechtliche, abrechnungsbezogene oder forschungsbezogene Fragen direkt an diese.',
                        ],
                    ],
                ],
                [
                    'key' => 'operator-alert',
                    'style' => 'alert',
                    'fields' => [
                        'mantine_color' => 'yellow',
                        'mantine_variant' => 'light',
                        'mantine_radius' => 'md',
                        'mantine_left_icon' => 'IconAlertTriangle',
                    ],
                    'translations' => [
                        'mantine_alert_title' => [
                            'en-GB' => 'Operator action required',
                            'de-CH' => 'Anpassung durch Betreiber erforderlich',
                        ],
                        'value' => [
                            'en-GB' => 'Replace this block with the legal name, address, contact email, and registration / VAT identifiers of the institution responsible for this SelfHelp instance.',
                            'de-CH' => 'Ersetzen Sie diesen Block durch den rechtlichen Namen, die Anschrift, die Kontakt-E-Mail-Adresse sowie die Handelsregister- bzw. UID-Nummer der für diese SelfHelp-Instanz verantwortlichen Institution.',
                        ],
                    ],
                ],
                [
                    'key' => 'h2-controller',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '2'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Operating institution',
                            'de-CH' => 'Betreibende Institution',
                        ],
                    ],
                ],
                [
                    'key' => 'controller-text',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => '[Institution name], [Department/Unit], [Street + number], [Postal code + city], [Country]. Email: [contact@example.org]. Registered representative: [Name + role]. Registration / VAT: [identifiers].',
                            'de-CH' => '[Institution], [Abteilung/Einheit], [Strasse + Nummer], [PLZ + Ort], [Land]. E-Mail: [kontakt@beispiel.ch]. Vertretungsberechtigte Person: [Name + Funktion]. Handelsregister / UID: [Nummern].',
                        ],
                    ],
                ],
                [
                    'key' => 'h2-stack',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '2'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Components & versions',
                            'de-CH' => 'Komponenten & Versionen',
                        ],
                    ],
                ],
                [
                    'key' => 'stack-text',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'SelfHelp is built on top of well-known open-source components. The list below documents the components shipped with this distribution and their licences.',
                            'de-CH' => 'SelfHelp basiert auf bekannten Open-Source-Komponenten. Die folgende Liste dokumentiert die mit dieser Distribution ausgelieferten Komponenten und ihre Lizenzen.',
                        ],
                    ],
                ],
                [
                    'key' => 'stack-card',
                    'style' => 'card',
                    'fields' => [
                        'mantine_card_padding' => 'lg',
                        'mantine_card_shadow' => 'sm',
                        'mantine_radius' => 'md',
                        'mantine_border' => '1',
                        'mantine_spacing_margin_padding' => '{"mt":"md"}',
                    ],
                ],
                [
                    'key' => 'stack-h3-app',
                    'style' => 'title',
                    'parent' => 'stack-card',
                    'fields' => ['mantine_title_order' => '3'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Application',
                            'de-CH' => 'Anwendung',
                        ],
                    ],
                ],
                [
                    'key' => 'stack-list-app',
                    'style' => 'list',
                    'parent' => 'stack-card',
                    'fields' => ['mantine_list_list_style_type' => 'disc'],
                ],
                ...$this->stackList('stack-list-app', [
                    ['SelfHelp Application v7.7.0 — MPL2.0', 'SelfHelp Anwendung v7.7.0 — MPL2.0'],
                    ['sh-shp-api v1.1.1 (DB v1.1.0) — plugin', 'sh-shp-api v1.1.1 (DB v1.1.0) — Plugin'],
                    ['sh-shp-formula_parser v1.5.6 (DB v1.5.0) — plugin', 'sh-shp-formula_parser v1.5.6 (DB v1.5.0) — Plugin'],
                    ['sh-shp-mobile_styles v1.0.1 (DB v1.0.0) — plugin', 'sh-shp-mobile_styles v1.0.1 (DB v1.0.0) — Plugin'],
                    ['sh-shp-mobisense v1.0.6 (DB v1.0.0) — plugin', 'sh-shp-mobisense v1.0.6 (DB v1.0.0) — Plugin'],
                    ['surveyJS v1.4.7 (DB v1.4.0) — plugin', 'surveyJS v1.4.7 (DB v1.4.0) — Plugin'],
                ]),
                [
                    'key' => 'stack-h3-libs',
                    'style' => 'title',
                    'parent' => 'stack-card',
                    'fields' => [
                        'mantine_title_order' => '3',
                        'mantine_spacing_margin' => '{"mt":"md"}',
                    ],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Frameworks & libraries',
                            'de-CH' => 'Frameworks & Bibliotheken',
                        ],
                    ],
                ],
                [
                    'key' => 'stack-list-libs',
                    'style' => 'list',
                    'parent' => 'stack-card',
                    'fields' => ['mantine_list_list_style_type' => 'disc'],
                ],
                ...$this->stackList('stack-list-libs', [
                    ['Altorouter 1.2.0 — MIT', 'Altorouter 1.2.0 — MIT'],
                    ['Autosize 1.1.6 — MIT', 'Autosize 1.1.6 — MIT'],
                    ['Bootstrap 4.4.1 — MIT', 'Bootstrap 4.4.1 — MIT'],
                    ['Datatables 1.10.18 — MIT', 'Datatables 1.10.18 — MIT'],
                    ['Deepmerge 4.2.2 — MIT', 'Deepmerge 4.2.2 — MIT'],
                    ['EasyMDE 2.16.1 — MIT', 'EasyMDE 2.16.1 — MIT'],
                    ['Flatpickr 4.6.13 — MIT', 'Flatpickr 4.6.13 — MIT'],
                    ['Font Awesome 5.2.0 — code MIT, icons CC, fonts OFL', 'Font Awesome 5.2.0 — Code MIT, Icons CC, Fonts OFL'],
                    ['GUMP 1.5.6 — MIT', 'GUMP 1.5.6 — MIT'],
                    ['Html2pdf 0.9.2 — MIT', 'Html2pdf 0.9.2 — MIT'],
                    ['Iscroll 4.2.5 — MIT', 'Iscroll 4.2.5 — MIT'],
                    ['jQuery 3.3.1 — MIT', 'jQuery 3.3.1 — MIT'],
                    ['jQuery QueryBuilder 2.6.0 — MIT', 'jQuery QueryBuilder 2.6.0 — MIT'],
                    ['jQueryConfirm 3.3.4 — MIT', 'jQueryConfirm 3.3.4 — MIT'],
                    ['JsonLogic 1.3.10 — MIT', 'JsonLogic 1.3.10 — MIT'],
                    ['mermaid 8.2.3 — MIT', 'mermaid 8.2.3 — MIT'],
                    ['Monaco Editor 0.33.0 — MIT', 'Monaco Editor 0.33.0 — MIT'],
                    ['Parsedown 1.7.1 — MIT', 'Parsedown 1.7.1 — MIT'],
                    ['PHP-fcm 1.2.0 — MIT', 'PHP-fcm 1.2.0 — MIT'],
                    ['PHPMailer 6.0.7 — LGPL', 'PHPMailer 6.0.7 — LGPL'],
                    ['Plotly.js 1.52.3 — MIT', 'Plotly.js 1.52.3 — MIT'],
                    ['ResizeSensor 1.2.2 — MIT', 'ResizeSensor 1.2.2 — MIT'],
                    ['Sortable 1.7.0 — MIT', 'Sortable 1.7.0 — MIT'],
                ]),
                [
                    'key' => 'stack-note',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'Versions correspond to the application baseline shipped with this SelfHelp distribution. Each operator may have applied additional patches; check `composer show` and `npm ls` on your installation for the live versions.',
                            'de-CH' => 'Die angegebenen Versionen entsprechen der Basisinstallation dieser SelfHelp-Distribution. Einzelne Betreiber können zusätzliche Patches installiert haben; prüfen Sie `composer show` und `npm ls` Ihrer Installation für die tatsächlich ausgelieferten Versionen.',
                        ],
                    ],
                ],
            ]
        );

        // disclaimer --------------------------------------------------
        $this->seedPageSections(
            keyword: 'disclaimer',
            prefix: 'disclaimer-sys',
            descriptions: [
                'en-GB' => 'Disclaimer for the SelfHelp research platform.',
                'de-CH' => 'Haftungsausschluss für die SelfHelp-Forschungsplattform.',
            ],
            sections: [
                [
                    'key' => 'h1',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '1'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Disclaimer',
                            'de-CH' => 'Haftungsausschluss',
                        ],
                    ],
                ],
                [
                    'key' => 'intro',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'SelfHelp is a research support platform. Nothing on this site constitutes medical advice, diagnosis, or treatment. Always seek the advice of a qualified clinician with any questions you may have regarding a medical condition.',
                            'de-CH' => 'SelfHelp ist eine Plattform zur Unterstützung von Forschung. Nichts auf dieser Plattform stellt eine medizinische Beratung, Diagnose oder Behandlung dar. Holen Sie bei gesundheitlichen Fragen stets den Rat einer qualifizierten Fachperson ein.',
                        ],
                    ],
                ],
                [
                    'key' => 'crisis-alert',
                    'style' => 'alert',
                    'fields' => [
                        'mantine_color' => 'red',
                        'mantine_variant' => 'light',
                        'mantine_radius' => 'md',
                        'mantine_left_icon' => 'IconAlertTriangle',
                    ],
                    'translations' => [
                        'mantine_alert_title' => [
                            'en-GB' => 'In a crisis?',
                            'de-CH' => 'Sind Sie in einer Krise?',
                        ],
                        'value' => [
                            'en-GB' => 'If you are in immediate danger or experiencing a mental-health emergency, please contact your local emergency services or a crisis helpline. Do NOT use this platform for emergencies — it is not monitored 24/7.',
                            'de-CH' => 'Wenn Sie sich in unmittelbarer Gefahr befinden oder eine psychische Notlage erleben, wenden Sie sich bitte an die örtlichen Notdienste oder eine Krisenhotline. Benutzen Sie diese Plattform NICHT für Notfälle — sie wird nicht rund um die Uhr betreut.',
                        ],
                    ],
                ],
                [
                    'key' => 'h2-content',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '2'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'Content provided by research teams',
                            'de-CH' => 'Inhalte der Forschungsteams',
                        ],
                    ],
                ],
                [
                    'key' => 'content-text',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'Each research project hosted on this instance is responsible for the accuracy and lawfulness of the content it publishes. Concerns about the content of a specific project should be raised with that project’s research team or the institution operating this instance.',
                            'de-CH' => 'Für die Richtigkeit und Rechtmässigkeit der publizierten Inhalte ist jedes auf dieser Instanz gehostete Forschungsprojekt selbst verantwortlich. Bedenken zu Inhalten eines bestimmten Projekts richten Sie bitte an das Forschungsteam des Projekts oder an die betreibende Institution.',
                        ],
                    ],
                ],
                [
                    'key' => 'h2-external',
                    'style' => 'title',
                    'fields' => ['mantine_title_order' => '2'],
                    'translations' => [
                        'content' => [
                            'en-GB' => 'External links',
                            'de-CH' => 'Externe Links',
                        ],
                    ],
                ],
                [
                    'key' => 'external-text',
                    'style' => 'text',
                    'translations' => [
                        'text' => [
                            'en-GB' => 'The platform may link to external resources outside our control. We do not endorse and are not responsible for the content, accuracy, or privacy practices of those external sites.',
                            'de-CH' => 'Die Plattform kann auf externe, nicht von uns kontrollierte Ressourcen verweisen. Für deren Inhalte, Richtigkeit oder Datenschutzpraktiken übernehmen wir keine Verantwortung.',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Helper that produces N list-item rows with the given (en, de)
     * label pairs nested under an existing list section. Used by the
     * impressum component table to keep that section readable.
     *
     * @param string $parentKey  Key of the parent `list` section.
     * @param array<int, array{0:string,1:string}> $items  EN/DE pairs.
     *
     * @return list<array<string,mixed>>
     */
    private function stackList(string $parentKey, array $items): array
    {
        $rows = [];
        $i = 1;
        foreach ($items as [$en, $de]) {
            $rows[] = [
                'key' => $parentKey . '-i' . $i,
                'style' => 'list-item',
                'parent' => $parentKey,
                'translations' => [
                    'mantine_list_item_content' => [
                        'en-GB' => $en,
                        'de-CH' => $de,
                    ],
                ],
            ];
            $i++;
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Generic helpers
    // ------------------------------------------------------------------

    /**
     * Seed one page's worth of content.
     *
     *   1. INSERT IGNORE the page-level `description` translation per locale.
     *   2. INSERT IGNORE the canonical (admin / therapist / subject) ACL rows.
     *   3. Walk the `sections` list to insert sections, wire them into
     *      `pages_sections` or `sections_hierarchy`, and persist their
     *      property + translation field values.
     *
     * The `prefix` is used to namespace section names so each migration
     * can clean up its own sections in `down()` without clobbering
     * sections created elsewhere.
     *
     * @param string $keyword
     * @param string $prefix
     * @param array<string, string> $descriptions  locale => description
     * @param list<array<string, mixed>> $sections
     */
    private function seedPageSections(
        string $keyword,
        string $prefix,
        array $descriptions,
        array $sections
    ): void {
        $this->insertPageDescriptionTranslations($keyword, $descriptions);
        $this->insertAclRows($keyword);
        $this->insertSections($keyword, $prefix, $sections);
    }

    /**
     * Insert page-level `description` translations. We do not touch the
     * `title` field here because every system page already has a title
     * translated in `new_create_db.sql` (and the `backfillPageTitleTranslations`
     * pass at the start of `up()` covers the few exceptions).
     *
     * @param array<string, string> $descriptions  locale => description
     */
    private function insertPageDescriptionTranslations(string $keyword, array $descriptions): void
    {
        foreach ($descriptions as $locale => $description) {
            $escaped = $this->escape($description);
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
                SELECT p.id, f.id, l.id, '{$escaped}'
                FROM `pages` p
                JOIN `fields` f ON f.`name` = 'description'
                JOIN `languages` l ON l.`locale` = '{$locale}'
                WHERE p.`keyword` = '{$keyword}'
            SQL);
        }
    }

    /**
     * Mirror the ACL pattern from the privacy migration:
     *   - admin     : select + update only (cannot insert root or delete the page)
     *   - therapist : read-only
     *   - subject   : read-only
     *
     * Anonymous visitors bypass ACL via `is_open_access = 1` on the page row.
     */
    private function insertAclRows(string $keyword): void
    {
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
     * Walk a list of section descriptors and emit the SQL to:
     *   1. Insert each `sections` row (`<prefix>-<key>` as `name`).
     *   2. Wire it into the page (top-level) or its parent (nested).
     *   3. Insert non-translatable property rows (`id_languages = 1`).
     *   4. Insert translatable rows, one per (field × locale).
     *
     * @param list<array<string, mixed>> $sections
     */
    private function insertSections(string $keyword, string $prefix, array $sections): void
    {
        $rootPos = 10;
        $childPos = [];

        foreach ($sections as $entry) {
            $sectionName = $prefix . '-' . $entry['key'];
            $styleName = $entry['style'];
            $parentKey = $entry['parent'] ?? null;

            $this->addSql(<<<SQL
                INSERT INTO `sections` (`id_styles`, `name`)
                SELECT s.id, '{$sectionName}'
                FROM `styles` s
                WHERE s.`name` = '{$styleName}'
            SQL);

            if ($parentKey === null) {
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

            foreach ($entry['translations'] ?? [] as $fieldName => $byLocale) {
                foreach ($byLocale as $locale => $value) {
                    if (!in_array($locale, self::LOCALES, true)) {
                        continue;
                    }
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
