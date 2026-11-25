<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Entry lists — show, add, edit and delete records

Audience: CMS administrators and content editors (non-technical).  
Status: active.  
Applies to: the **Entry List with Form** (`entry-form-list`) and **Data View** (`data-view`) styles on web and in the mobile app.  
Last verified: 2026-11-17.  
Source of truth: the section inspector of these two styles and the backend rules in [../developer/form-lists.md](../developer/form-lists.md).

An **entry list** shows the records people saved (journal entries, to-dos, team
members, news items) and lets the right people add, view, edit and delete them,
with search, sorting, filters and pages. You do **not** write code or SQL.

## Which style do I use?

| I want… | Use |
| --- | --- |
| Participants to keep **their own** list (to-dos, journal) and change it | **Entry List with Form** (own entries stay on, the default) |
| My team to manage a **shared** list (news, FAQ, team) behind the CMS | **Entry List with Form** with **Own entries only** off |
| Visitors to **read** a list (public news, FAQ, team) and open a detail page | **Data View** — it can never change anything |

An **Entry List with Form** *is* the form: put the inputs **directly inside**
the list. Leave **Data table** empty so the list owns its table. To show another
list's records (for example an admin page over participants' entries), set
**Data table** to that table — then the list only deletes and runs quick
actions (add, edit and inputs stay off).

## Build a participant list (5 steps)

1. Add an **Entry List with Form** section to a page.
2. Add its inputs **directly inside** the list (for example `title`, `status`, `due_date`). Give inputs short, lowercase names with letters, numbers and underscores. Do not put a Form record / Form log inside the list.
3. Choose the **columns** to show (label, whether visitors can sort or search by it, how to draw it).
4. Choose the **default order**, the **page size** and, if you like, **filter controls** (a status dropdown, a date range).
5. Decide what visitors may do: **Allow add / edit / delete**, **Form presentation** (a pop-up or a page), and **Show history**.

By default each visitor sees **only their own records**. A guest sees nothing until
they sign in. Turning **Own entries only** off shows everybody's records; use it
only where that is allowed, and it is still subject to the table's data-access
permissions (changing someone else's record needs the table's *update* or
*delete* permission).

## Show participants' records on an admin page

Example: participants keep a list A with inputs `title` and `status`. Admins need
a second list B that sees everyone's records, can approve one with a quick action
and can delete.

1. On the admin page, add an **Entry List with Form**.
2. Set **Data table** to list A's table, turn **Own entries only** off, turn
   **Allow add** and **Allow edit** off, leave **Allow delete** on.
3. Set up columns and a quick action (for example **Approve** sets `status` to
   `approved`) from the row editors — they offer A's columns by name. Do not add
   inputs under B.

## Why not the same form name as in SelfHelp 1?

In SelfHelp 1 two lists that shared a form name shared one table. In SelfHelp 2
tables and columns are found by **id**, so renaming is safe and two forms with
the same label never mix data. A second list that should work on the first
list's records names that table in **Data table** instead.

## Build a public list with detail pages

1. Add a **Data View** and choose the **Data table** of the list your team edits.
2. **Choose the columns visitors may see.** A public view needs explicit columns; a column you do not choose never leaves the server.
3. Add a **fixed filter** to keep drafts out (for example *published equals yes*). Visitors can narrow the list but never widen it past this filter.
4. Set **Detail page** to a path with `{record_id}` once (for example `/news/{record_id}`), and put an **Entry record** on that page to lay out one record freely.

## Design each record yourself (custom layout)

A **Data View** can draw every record with a design you compose from sections, for
example an event card with a date badge, the title, the place and a *View details*
button.

1. Set **Layout** to **Custom**.
2. Add sections **inside** the data view (for example a *Card* with a *Title*, a *Badge* and a *Button*). They are the design of **one** record; the view repeats them for every record on the page.
3. Write the **name of a column in double braces** where the record's value should appear: `{{title}}`, `{{event_date}}`, `{{location}}`. Use `{{record_id}}` in a button address (`/events/{{record_id}}`) to open that record, and `{{_status_label}}` to show the label of a choice in the visitor's language.
4. Only the **columns you chose** (plus `record_id` and `entry_date`) can be used. Set **Record container classes** (for example `grid grid-cols-1 md:grid-cols-3 gap-6`) to place the cards next to each other on the web; the phone shows them in one column.
5. Keep **Records per page** small (6 to 12): the page sends the drawn design of every record on it.

Good to know: a form, an entry list or another data view cannot be placed inside the
design; a section cannot be shown or hidden depending on the record's value; and
search, sorting, filters and the page buttons stay the visitor's, exactly as with the
table and cards layouts. A custom layout without sections shows cards.

## Filters, search and sorting

- **Search** looks for the typed text anywhere in the columns you marked searchable (at least 2 characters), ignoring upper/lower case.
- **Sorting** compares by the column's type: numbers as numbers, dates as dates, so 9 comes before 10.
- **Filter controls** are text, single or multiple choice (choices can come from the data itself), yes/no, date range and number range.
- The list remembers its state in the page address (`?news.q=sleep&news.page=2`), so refresh, back and shared links work. If a page has several lists, give each its own **List name**.

## Quick actions

A quick action is a one-click change *you* define, for example **Mark done**
(sets `status` to `done`, offered only while `status` is `open`). Visitors can
only press the buttons you offer, on records they may change; they cannot choose
what is written. Each action can ask for a confirmation first.

## History and restore

With **Show history** on, people who may edit a record can see its earlier
versions (who, when, what changed), compare two versions and **restore** one.
A restore is saved as a **new version**; nothing is rewound or lost. If an old
value no longer fits today's form (a field was removed, a file is gone), the
restore tells you which fields cannot be restored and applies nothing until you
restore only the others.

If two people edit the same record, the second save is **refused** with a message
instead of overwriting the first; the draft stays on screen so it can be compared
and re-applied.

## Good to know

- A new list is empty until the form was submitted once; you can still configure it first.
- Changing the **name** of a form input is safe: lists follow the input, not its name.
- When you **export** a page with its lists and **import** it elsewhere, the lists are reconnected to the imported form by input name. The import refuses (and says why) a list that cannot work with its data.
- Deleting data, a column or a user removes the records' history and uploaded files with it; ordinary edits and deletions keep them.
- The mobile app shows participant lists with add, edit, delete and quick actions, and public data views. Setting a list up is done in the CMS on the web.

## Related guides

- [cms-apps.md](cms-apps.md) - CMS Apps built on these lists.
- [interpolation-and-data-naming.md](interpolation-and-data-naming.md) - naming form data.
- Developers: [../developer/form-lists.md](../developer/form-lists.md), [../reference/api/22-form-lists.md](../reference/api/22-form-lists.md), [../reference/styles/forms.md](../reference/styles/forms.md#entry-form-list).
