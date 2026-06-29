<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Editing system emails (for editors)

Audience: CMS administrators and content editors (non-technical).
Status: active.
Applies to: SelfHelp2 admin UI (`sh-mail-config`).
Last verified: 2026-06-29.
Source of truth: The live admin UI; exact preset/rendering contract in [reference/email-styles.md](../reference/email-styles.md).

The platform sends a few automatic emails: two-factor codes, account
confirmation, welcome, password recovery, and password-changed. You can edit
their wording and look on one page — **without writing any HTML**.

---

## 1. Open the mail config page

Go to **System Pages → `sh-mail-config`** (`/admin/pages/sh-mail-config`).

You will see, for each email and each language, a **Subject** and a **Body**.
Pick the language with the locale switch on each field.

---

## 2. Edit the wording

- The **Subject** is a single line.
- The **Body** is a rich-text editor. Type normally and use the toolbar for
  **bold**, **headings**, **lists**, **alignment**, and **links**.
- Insert dynamic values (the recipient's name, a reset link, a code) with the
  `{{` picker — see
  [interpolation-and-data-naming.md](interpolation-and-data-naming.md). Each
  email lists the values it supports in the field's help text (for example
  `{{system.user_name}}` and `{{system.special.reset_link}}`).

You do not manage the email's frame, header, or footer — the platform adds the
consistent branded layout automatically when the email is sent.

---

## 3. Style elements with the "Style" dropdown

The body toolbar has a **Style** dropdown (palette icon) with ready-made,
email-safe styles. Select some text (or a link) and pick one:

| Style | Use it for |
| --- | --- |
| **Primary button** | The main action (e.g. *Reset password*). Apply to a link. |
| **Secondary button** | A secondary action next to the primary one. Apply to a link. |
| **Strong link** | A link you want to stand out. |
| **Muted text** | Footnotes / "do not reply" lines. |
| **Callout box** | A highlighted block — a long URL, a notice. |
| **Inline code** | A large, spaced verification code. |

Pick **Clear style** to remove a style. The editor preview looks like the real
email.

> **Buttons need a link.** Select the words, click the toolbar **Link** button to
> set the address first, then apply **Primary/Secondary button** or **Strong
> link**.

---

## 4. Tips

- Keep it short. Long paragraphs are harder to read on phones.
- Always check both languages.
- Use **Inline code** for the 2FA code and **Callout box** for a copy-paste URL —
  that is how the default templates are set up, so you have working examples to
  copy.
- If you want to start over, clear the field and the platform falls back to the
  built-in default for that email.

For the exact list of styles and how they render, see
[reference/email-styles.md](../reference/email-styles.md).
