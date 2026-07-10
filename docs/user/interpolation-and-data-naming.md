<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Variables and data naming (for editors)

Audience: CMS administrators and content editors (non-technical).
Status: active.
Applies to: SelfHelp2 admin UI (section editor, data config, data browser, mail config).
Last verified: 2026-07-09.
Source of truth: The live admin UI; developer details in [developer/10-interpolation-system.md](../developer/10-interpolation-system.md).

This guide explains, without any code, how to put **dynamic values** into your
text (a user's name, a value they entered in a form, today's date) and how to
**name your data** so it stays readable and never breaks.

---

## 1. What is a variable?

A variable is a placeholder that gets replaced with a real value when the page
loads or the email is sent. For example, writing:

> Hello {{system.user_name}}, welcome back!

shows the visitor:

> Hello Anna Müller, welcome back!

Variables are written between double curly braces: `{{ ... }}`. You almost never
type them by hand — you use the **picker** (next section).

---

## 2. Inserting a variable with the picker

Wherever you can enter text in the editor (a heading, a paragraph, a label, a
form field, an email body, even the CSS or SQL boxes), type two opening braces:

```
{{
```

A small **dropdown** appears listing the available variables with **readable
names**. Start typing to filter, then click one. The editor inserts a coloured
**chip** that shows the readable name (for example `Daily mood` or
`User name`).

You can scroll the list — the number of variables depends on what data the
section is connected to. If the list is empty, the section is not connected to any
data yet (see "Connecting data" below), but the **system** variables (user name,
date, etc.) are always there.

### What you can insert

- **System values** — the logged-in user's name, email, code, the current date
  and time, the platform, and so on (these start with `system.`).
- **Global values** — texts your team manages centrally (these start with
  `globals.`).
- **Data values** — values from a form/data table the section is connected to
  (these start with the **scope name** you chose, for example `parent.` or
  `test.`).

---

## 3. Why a chip shows a name but stores something stable

When you insert a data variable, the chip shows a **display name** you can read
(like `Daily mood`). Behind the scenes the editor stores a **stable key** — an
internal name that never changes.

This separation is the whole point: **you can rename the display name any time
and nothing breaks.** If you change a form input's label from `Daily mood` to
`Mood today`, every chip that referenced it simply shows the new name — the
stored content keeps working, and no data is lost or duplicated.

> **Takeaway:** rename labels freely. The connection between your text and the
> data is based on the stable key, not on the label you see.

---

## 4. Naming your data so it reads nicely

Every record in a data table automatically has some **standard columns** that are
always available, plus the **columns from your form inputs**.

### Standard columns (always there)

These belong to every record and you can use them in filters and variables:

| Column | What it is |
| --- | --- |
| `record_id` | The unique id of the record. |
| `entry_date` | When the record was saved. |
| `user_code` | The participant's code. |
| `id_users` | The user id. |
| `user_name` | The user's name. |
| `triggerType` | How the record was created (for example, a finished form). |

### Your form columns

Each form input becomes a column. To make these read nicely:

1. Go to **Content → Data** and open the table (the "Manage" screen).
2. Under **Column labels**, type a friendly **display label** for any column
   (for example, turn `q1_mood` into `Daily mood`).
3. Click save. The label is now what you see in the variable picker and in the
   data browser — but the underlying data and any text you already wrote keep
   working.

A label you set by hand is marked **Manual** and will not be overwritten when the
form is edited later. Use **Reset to auto** to let it follow the form again.

> The standard columns above are read-only here — they are owned by the platform
> and cannot be renamed or deleted.

---

## 5. Connecting data to a section

To get **data variables** in a section, connect it to a data source:

1. In the section editor, open **Data configuration**.
2. Add a data source, pick the table, and give it a **scope name** (a short word
   like `parent` or `entry`). That word is the prefix you will see in the
   picker — for example `parent.user_name`.
3. Optionally add filters with the visual **builder** (recommended) — it lists
   every column, including the standard ones, so you can filter by them.

Once saved, reopen the section editor and the new variables appear in the `{{`
picker immediately — you do **not** need to reload the whole page.

### Entry list and entry record (different rule)

For **`entry-list`** and **`entry-record`** sections, which rows appear is **not**
controlled by Data configuration. Use the **Properties** panel instead:

- **Data table** — which table to load (required for rows to appear).
- **Filter** — optional SQL constraints on those rows.
- **Own entries only** — whether to hide other users' rows.

Data configuration on the same section is only for **helper scopes** you want to
reference elsewhere (for example `{{filters.status}}` inside the **Filter**
field). Putting a table in Data configuration alone will **not** show list cards or
a detail record. See [composite.md#entry-list](../reference/styles/composite.md#entry-list).

### The SQL filter box

Advanced users can edit the raw filter directly. The filter box is **locked** by
default (it is normally produced by the builder). Click **Unlock** to edit it by
hand; you can use `{{` there too to insert variables.

---

## 6. Where variables work

You can use `{{` variables in essentially every text you author in the CMS:
headings and paragraphs, labels and placeholders, alerts and descriptions, the
custom **CSS** box, the **data** filter, and the **email** bodies on the mail
config page.

For styling emails, see [email-templates.md](email-templates.md).
