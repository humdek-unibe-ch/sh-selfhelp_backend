<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Email styles and mail rendering

Audience: CMS administrators and developers.
Status: active.
Applies to: SelfHelp2 Symfony backend (`sh-mail-config` page + transactional emails).
Last verified: 2026-06-29.
Source of truth: `src/Service/Auth/MailHtmlRenderer.php`, `templates/emails/*.html`, the frontend email-style extension (`sh-selfhelp_frontend/src/app/components/shared/mentions/EmailStyleExtension.ts`), and `src/Service/Core/JobSchedulerService.php`.

This page is the contract for the small, named set of **email style presets**
that the mail-config editor offers, and for how a mail body is turned into a real
email at send time. It is the bridge between two halves that **must stay in
lockstep**: the frontend editor (which attaches a CSS class) and the backend
renderer (which inlines that class into email-safe CSS).

---

## For administrators

### What you edit

Open **System Pages → `sh-mail-config`** (`/admin/pages/sh-mail-config`). Each
transactional email (2FA, account confirmation, welcome, password recovery,
password changed) has a **Subject** and a **Body** field, per language.

You edit the body in a normal rich-text editor — **you never write HTML**. Type
your text, use the toolbar for bold / headings / lists / links, and insert
variables with the `{{` picker (see
[user/interpolation-and-data-naming.md](../user/interpolation-and-data-naming.md)).

### The "Style" dropdown

The body toolbar has a **Style** dropdown (palette icon). Select some text (or a
link) and pick a preset to make it look like a button, a callout, etc. Pick
**Clear style** to remove it. The preview in the editor looks like the final
email.

| Style | Use it for | Apply to |
| --- | --- | --- |
| **Primary button** | The main call to action (reset password, activate account). | A link. |
| **Secondary button** | A less important action shown next to a primary button. | A link. |
| **Strong link** | A link you want to stand out inline. | A link. |
| **Muted text** | Footnotes, disclaimers, "do not reply" lines. | Any text. |
| **Callout box** | A highlighted block — a code, a long URL, a notice. | A paragraph / text. |
| **Inline code** | A large, spaced-out verification code. | Short text. |

> Buttons and links need a real link target. Select the text, add the link with
> the toolbar **Link** button first, then apply the **Primary/Secondary button**
> or **Strong link** style.

### What gets sent

You only edit the **content** of the email. At send time the platform wraps your
content in the shared branded frame (the centered white card on a grey
background) automatically — so every email looks consistent and you do not
manage layout, headers, or footers.

---

## For developers

### Architecture

```
Admin edits body            JobSchedulerService::sendEmail()
in CMS rich-text   ──────►  MailHtmlRenderer::render($body)  ──────►  Symfony Mailer
editor (fragment)           1. inline base tag + preset CSS            (HTML email)
  <h2>…<a class=             2. wrap in branded shell
   "email-button">…
```

- **Storage**: the body is a small **HTML fragment** (headings, paragraphs,
  links, lists, `<hr>`, and `<span>`/`<a>` carrying an `email-*` preset class).
  It is **not** a full HTML document. Admins never see or write the `<html>` /
  `<head>` / inline CSS.
- **Frontend**: the email "Style" presets are a Tiptap **extension**
  (`EmailStyleExtension` in `EmailStyleExtension.ts`). It registers a global
  `emailStyleClass` attribute on the `paragraph`, `heading` and `link` node
  types, so applying a preset puts a single `email-*` class on the **real
  element** — `email-button` / `email-button-secondary` / `email-link-strong` on
  an `<a>`, and `email-callout` / `email-muted` / `email-code` on a `<p>` /
  `<h*>` — and that class **round-trips** through load → edit → save. (An earlier
  span-only mark never matched those elements, so the classes were silently
  dropped when the editor re-parsed the body and reseeded mails lost their
  styling; the global-attribute approach fixes that.) The class is stored
  verbatim in the saved fragment, and the editor CSS (`MentionEditor.module.css`)
  mirrors the inlined styles so the WYSIWYG preview matches the delivered mail.
- **Backend**: `App\Service\Auth\MailHtmlRenderer::render()` is called from
  `JobSchedulerService::sendEmail()` for every **HTML** mail (plain-text mails
  are sent verbatim). It:
  1. **inlines** the base tag styles (`h1`–`h4`, `p`, `a`, `ul`, `ol`, `li`,
     `hr`, `blockquote`) and every recognised `email-*` preset class as inline
     `style="…"` (email clients strip `<style>`/`<head>`), and forces the link
     colour inside button/strong-link presets;
  2. **wraps** the result in the shared branded shell (centered 600px card).
- **Legacy passthrough**: a body that still begins with `<!doctype>`, `<html>`,
  or `<body>` is returned untouched and never double-wrapped. The data migration
  `Version20260629131426` rewrites the old full-document seed bodies to the new
  fragment form (only where the stored content still matches a full document, so
  admin-authored content is preserved).

### Preset contract

The preset id ↔ class ↔ inline CSS map is duplicated in exactly two files and
**must be edited together**:

| Preset id (frontend) | CSS class (stored) | Backend inline CSS (`MailHtmlRenderer::PRESET_STYLES`) |
| --- | --- | --- |
| `primary_button` | `email-button` | solid blue button (`#2f6fed`, white text, padded, rounded) |
| `secondary_button` | `email-button-secondary` | outlined blue button (white bg, blue border + text) |
| `text_link_strong` | `email-link-strong` | bold underlined blue text |
| `muted_text` | `email-muted` | small grey text (`#6b7280`, 12px) |
| `callout_box` | `email-callout` | grey block (`#f3f4f6`), padded, rounded, word-break |
| `code_block` | `email-code` | large monospace, bold, letter-spaced |

`email-button`, `email-button-secondary`, and `email-link-strong` also force the
contained `<a>` colour (`MailHtmlRenderer::LINK_COLOR_CLASSES`) so a button's link
text is never the default blue-on-blue.

### Adding or changing a preset

1. Edit `EMAIL_STYLE_PRESETS` in
   `sh-selfhelp_frontend/.../mentions/EmailStyleExtension.ts` (id, label, class,
   description) and the matching preview rule in `MentionEditor.module.css`.
2. Edit `MailHtmlRenderer::PRESET_STYLES` (and `LINK_COLOR_CLASSES` if the preset
   wraps a link) with the **same class name** and the email-safe inline CSS.
3. Update the table above and the admin table.
4. Keep the inline CSS email-client-safe: inline styles only, no flexbox/grid,
   web-safe fonts, hex colours, table-friendly spacing.

> A preset that exists in only one of the two files is a broken contract: the
> editor would store a class the renderer ignores (or vice-versa). Review treats
> a one-sided change as incomplete.

### Default templates

`templates/emails/<type>.<locale>.html` are the seeded fragment defaults
(`MailTemplateDefaults::getBody()` reads them; the seed migration copies them
into `pages_fields_translation`). They are the canonical examples of the
fragment + preset style — e.g. `mail_2fa.en-GB.html` uses `email-code` +
`email-muted`, `mail_recovery.en-GB.html` uses `email-button` + `email-callout`.

### Related

- [user/email-templates.md](../user/email-templates.md) — non-technical guide.
- [developer/10-interpolation-system.md](../developer/10-interpolation-system.md) — how `{{system.*}}` placeholders in the body are resolved.
