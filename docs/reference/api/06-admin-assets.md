# Admin Assets APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend, core 0.1.43 and later.
Last verified: 2026-11-12.
Source of truth: `AdminAssetController`, `AssetController`, `AdminAssetService`, `AssetAccessService`, `AssetFileTypes`, the JSON schemas under `config/schemas/api/v1/{requests,responses}/admin/` (`*asset*`), and migrations `Version20261103112133` and `Version20261112103337`.

## Core concepts

- **Identity.** Every asset has a stable `uuid`, and the uuid **is** its file
  name: the file is stored on disk under it with no extension, so two uploads
  never collide and there is no overwrite. `name` is an editable label without
  extension (the uploaded file name by default). The checked `extension` is
  stored next to it and gives the MIME type and the download name
  `<name>-<uuid>.<extension>` (`file_name`).
- **Replace keeps the uuid.** Editing an asset can replace its file; the uuid,
  and therefore the URL every page already uses, stays the same.
- **Viewing is per asset, by group.** An asset with **no groups is public**,
  including to visitors who are not logged in. With groups, only members of
  any of them can view it. The admin role views every asset.
- **Managing is per asset, by role.** Which roles may list, edit, delete and
  export an asset in the CMS is a `role_data_access` grant (resource type
  `asset`). An upload is granted to the uploader's roles **that hold
  `admin.asset.create`** (a role that can only read assets does not get to edit
  what its member uploads). The admin role manages every asset **without a
  grant**: it is never listed under `roles`, and sending its id in `role_ids`
  stores nothing. Route permissions
  (`admin.asset.read|create|update|delete`) still gate each action; the grant
  decides which assets it applies to. Every grant written or removed is logged
  as a `role_data_access` transaction, like on the role screens.
- **Folders organise.** Nestable folders (20 levels at most) have no access
  meaning; "set access" on a folder applies once to the assets in it and stores
  nothing on the folder.
- **Default alt.** An image section whose own `alt` is empty gets the asset's
  alt in the page payload, read when the page is served; a section's own alt
  always wins.
- **File and row change together.** A replace, an upload or a delete never
  leaves a row without its file or a file that disagrees with its row, even when
  the database commit fails.

## Asset object

Returned by every admin endpoint (`responses/admin/assets/asset.json`):

| Field | Type | Notes |
| --- | --- | --- |
| `id` | int | |
| `uuid` | string | Stable public id; survives file replacement |
| `asset_type` | string | `Asset` or `CSS` |
| `name` | string\|null | Editable label, no extension |
| `url` | string | `/cms-api/v1/assets/<uuid>`, the only way to fetch the bytes |
| `mime_type` | string\|null | Recorded at upload: the canonical type of the extension (a CSV is `text/csv`) |
| `extension` | string\|null | The checked extension, lower case, no dot. `null` for assets uploaded before it was stored |
| `file_name` | string | The download name `<name>-<uuid>.<extension>` (name sanitised, at most 100 characters; `<uuid>.<extension>` without a name) |
| `size` | int\|null | Bytes |
| `alt` | string\|null | Default alt text |
| `folder_id` | int\|null | Folder; null = top level |
| `roles` | `{id, name}[]` | Roles that may manage it. The admin role is never listed: it manages every asset |
| `groups` | `{id, name}[]` | Groups that may view it; empty = public |
| `can_view` | bool | The **caller** may open the file: public asset, admin, or a member of one of its groups. When `false` the delivery URL answers `403`, so a client draws a lock instead of a preview |
| `can_update` | bool | The **caller** may edit it: the `update` bit of a grant on this asset (admin: always). When `false` an edit answers `403` |
| `can_delete` | bool | The **caller** may delete it: the `delete` bit of a grant on this asset (admin: always). When `false` a delete answers `403` |
| `created_at`, `updated_at` | string | ISO 8601 |

The three `can_*` flags are computed for each request, after the cached list is
read, so no shared cache holds an access answer. A client shows or hides an
action from them and never works the rule out itself; the server still checks
every request.

## List assets

`GET /cms-api/v1/admin/assets` - permission `admin.asset.read`

Query parameters: `page` (default 1), `pageSize` (default 100, max 1000),
`search` (matches name, uuid, alt, MIME type and asset type), `group_id`,
`folder_id` (the assets directly in that folder; `0` = only assets in no
folder; omitted = every folder).

Only assets the caller may manage are listed. Response `data`:
`{ assets: Asset[], pagination: { page, pageSize, total, totalPages } }`.

## Get one asset

`GET /cms-api/v1/admin/assets/{assetId}` - permission `admin.asset.read`

`404` when the asset does not exist, `403` when the caller may not manage it.

## Upload

`POST /cms-api/v1/admin/assets` - permission `admin.asset.create`,
`multipart/form-data`, validated by `requests/admin/create_assets.json`.

| Field | Notes |
| --- | --- |
| `file` or `files[]` | One file, or up to 50 |
| `name` | Label (single upload; max 255). Default: the uploaded file name without extension |
| `alt` | Default alt text (max 500) |
| `group_ids` | Comma-separated ids of the groups that may view it. **Omitted or empty = public** |
| `role_ids` | Comma-separated ids of the roles that may manage it. **Omitted = the uploader's roles that hold `admin.asset.create`**; sent empty = only the admin role, which only an admin may ask for (`400` for anyone else) |
| `folder_id` | Folder id; empty = top level |

Id lists are strings because multipart cannot express an empty list.

Responses: `201` with the asset (single file), or `201`/`206` with
`{ uploaded, errors, total_files, successful_uploads, failed_uploads }`
(several files; `206` when any failed). `400` for a disallowed file type, a
file whose content does not fit its extension, a missing file, or an empty
`role_ids` from a caller who is not admin (checked once, before any file is
stored).

Allowed extensions: images (jpg, jpeg, png, gif, webp, svg), pdf, video (mp4,
avi, mov, wmv, flv, webm), audio (mp3, wav, ogg), css, js, archives (zip, rar,
7z, gz, tar), json, txt, csv, tsv, rtf, Microsoft Office (doc, docx, xls, xlsx,
ppt, pptx) and OpenDocument (odt, ods, odp). The bytes are sniffed and must fit
the extension: a PHP or HTML file named `x.png` is refused with `400 The file
content does not match its extension`, and nothing is stored.

**Duplicates.** The asset in the response (each one of `uploaded` for several
files) also carries `duplicates`: up to 5 other assets with exactly the same
bytes that the **caller may manage**, as `{ id, name, folder_id }`. Nothing is
refused; both assets exist. An asset the caller may not manage is never listed.
`duplicates` is only in upload responses, not in the list or the single asset.

## Edit and replace

`POST /cms-api/v1/admin/assets/{assetId}` - permission `admin.asset.update`
(and an `update` grant on the asset), `multipart/form-data`, validated by
`requests/admin/update_asset.json`. Only the fields sent change:

| Field | Notes |
| --- | --- |
| `file` | Optional. Replaces the bytes under the **same** uuid (the same type checks as an upload; the extension, MIME type and hash are updated) |
| `name` | New label (empty keeps the current one) |
| `alt` | New default alt (empty clears it) |
| `group_ids` | **Replaces** the viewing groups (empty = public) |
| `role_ids` | **Replaces** the managing roles. Empty leaves the asset to the admin role alone: only an admin may send it, otherwise `400` and nothing changes |
| `folder_id` | Moves it (empty = top level) |

It is a `POST` because PHP does not parse multipart bodies on `PUT`. Page
payloads read an asset's alt when they are served, so a changed alt shows on
every page at once; no cache has to be flushed.

A replacement file is put in place only after the row, the grants and the log
entry are written, as the last step before the commit; if the commit fails the
old bytes come back and the request is a `500`. The old file is never
half-written, and no hidden work file is left behind.

## Bulk actions

`POST /cms-api/v1/admin/assets/bulk-groups` - permission `admin.asset.update`

```json
{ "asset_ids": [12, 13], "group_ids": [5], "mode": "add" }
```

`mode` is `add` or `remove`. Response `data`: `{ "updated": 2 }`. `404` if any
asset id does not exist, `403` if the caller may not update one of them. All of
the bulk actions are **all-or-nothing**: every id is loaded and the caller's
right is checked on each asset before the first one changes, so a refusal or an
unknown id changes nothing.

`POST /cms-api/v1/admin/assets/bulk-folder` - permission `admin.asset.update`

```json
{ "asset_ids": [12, 13], "folder_id": 4 }
```

`folder_id` `null` moves them to the top level. Response `data`:
`{ "moved": 2 }`. `400` for an unknown folder, `404` if any asset id does not
exist, `403` if the caller may not update one of them.

`POST /cms-api/v1/admin/assets/bulk-delete` - permission `admin.asset.delete`

```json
{ "asset_ids": [12, 13] }
```

Response `data`: `{ "deleted": 2 }`. `404` if any asset id does not exist, `403`
if the caller may not delete one of them (then nothing is deleted).

## Delete

`DELETE /cms-api/v1/admin/assets/{assetId}` - permission `admin.asset.delete`

Removes the row, its grants and the stored file. The rows, grants and log
entries go in one transaction and the files are removed after it committed, so a
failed commit leaves the asset exactly as it was. Pages still referencing the
URL get `404`.

## Folders

Organisation only; returned as a flat list, clients build the tree from
`parent_id`. Every write returns the full list.

| Route | Permission | Body |
| --- | --- | --- |
| `GET /cms-api/v1/admin/asset-folders` | `admin.asset.read` | - |
| `POST /cms-api/v1/admin/asset-folders` | `admin.asset.create` | `{ "name": "Team", "parent_id": null }` |
| `PUT /cms-api/v1/admin/asset-folders/{folderId}` | `admin.asset.update` | same (rename and/or move) |
| `DELETE /cms-api/v1/admin/asset-folders/{folderId}` | `admin.asset.delete` | - |
| `POST /cms-api/v1/admin/asset-folders/{folderId}/access` | `admin.asset.update` | `{ "group_ids"?: int[], "role_ids"?: int[] }` |

Response `data`: `[{ id, name, parent_id, asset_count, subtree_asset_count }]`;
`asset_count` is the number of assets directly in the folder and
`subtree_asset_count` the number in it and in every folder below it, both only
of assets the caller may manage.

Rules:

- `400` for an empty name, when a folder would move into itself or one of its
  subfolders, and when it would sit deeper than 20 levels (counting the
  subfolders it takes along).
- `409` when a sibling under the same parent already has the name, compared
  case-insensitively (`A folder named 'X' already exists here`). The same name
  under another parent is fine.
- Only an **empty** folder is deleted: `409 The folder is not empty` when it has
  a subfolder or any asset, including assets the caller cannot see. Nothing
  changes then.

### Set access on a folder

`POST /cms-api/v1/admin/asset-folders/{folderId}/access` applies who sees and/or
who manages to **every asset in the folder and all its subfolders, once**.
Nothing is stored on the folder: assets added later are not affected.

- At least one key is required (`400` otherwise). A present key **replaces**
  that set on each asset; an absent key leaves it. `group_ids: []` makes the
  assets public. `role_ids: []` (or only the admin role) leaves them to the admin
  role alone: only an admin may send it, anyone else gets `400`.
- `404` for an unknown folder. `403` and **nothing changed** when the subtree
  holds an asset the caller may not update, whether the caller can see it or
  not (all or nothing).
- `200`, `data: { "updated": n }` with the number of assets changed (`0` for a
  folder without assets).

## Export

`POST /cms-api/v1/admin/assets/export` - permission `admin.asset.read`

Body `{ "asset_ids": [12, 13] }`; empty or omitted exports every asset the
caller may manage. Returns `application/zip`, bundle `selfhelp/asset-bundle`
version `2.0`:

```
manifest.json    { bundle_type, bundle_version, exported_at, assets: [
                   { uuid, name, alt, mime_type, extension, asset_type,
                     groups: [names], folder_path: [names], bundle_path } ] }
files/<name>-<uuid>.<ext>   the bytes, named like a download
```

A bundle without `extension` (made before it existed) is imported with the
extension of its `mime_type`.

Groups and folders travel by **name** because ids differ between instances;
`folder_path` lists the asset's folders from the top level down (`[]` = the top
level). The managing roles do not travel. The archive is sent from disk and
deleted afterwards.

An export is limited to what an import accepts (2000 assets, 4 GB of files in
total): a larger selection is refused with `400` before an archive is started,
and the caller exports fewer assets at a time. Assets whose file is missing on
disk are left out of the manifest.

## Import

`POST /cms-api/v1/admin/assets/import` - permission `admin.asset.create`,
multipart field `file` (the zip).

Import never overwrites: an entry whose uuid already exists is **skipped**.
Otherwise the asset is created with the same uuid, managed by the importer's
roles that hold `admin.asset.create`, and put into its `folder_path`: missing
folders are created by name, existing ones are reused, and a bundle without
`folder_path` (or with `[]`) imports into the top level. A `folder_path` that is
not a list of at most 20 non-empty names refuses that entry (`Invalid folder
path`). An asset that names a group which does not exist on this instance is
**not imported**: it is reported in `errors` (`Unknown group name(s): ...`),
because dropping the group would silently make a restricted asset public. Create
the group, or edit the manifest, and import again; the other assets of the same
bundle are imported normally. This is the same rule as the users CSV import.

An uploaded bundle is untrusted. Files are read one entry at a time (a file the
manifest does not name is never written; a `bundle_path` must be exactly
`files/<name>`, otherwise the entry is reported as `Invalid bundle path`), the
bytes are counted as they are read rather than taken from the archive's
declared sizes, and the import stops at these limits:

| Limit | Value | When exceeded |
| --- | --- | --- |
| assets (manifest entries; the archive may hold at most this + 1 entries) | 2000 | `400`, no asset is imported |
| `manifest.json` | 8 MB | `400` |
| one file | 512 MB | that entry is reported in `errors`, the others still import |
| files in total | 4 GB | the import stops there and keeps what came before; the entry is reported in `errors` |

A bundle that is not a zip, has no manifest, has another `bundle_type` or
`bundle_version`, or whose manifest is not valid JSON is refused with `400`.

Response `data`: `{ imported, skipped, errors: [{ file, error }] }`.

## Asset delivery

`GET /cms-api/v1/assets/{uuid}` is the **only** way asset bytes reach a client.
Files live outside the document root (`var/assets/`), so the web server cannot
serve them directly and every request is authorized against the asset's groups.

| Caller | Asset with groups | Asset without groups |
| --- | --- | --- |
| Anonymous | `403` | `200` |
| Logged in, in one of its groups | `200` | `200` |
| Logged in, in none of its groups | `403` | `200` |
| Admin role | `200` | `200` |

The route carries **no permission link** in `api_routes` (like `health`),
because access is resource-side: a route-level permission would reject
anonymous readers of public assets.

**Responses:** `200` with the file. `Content-Type` comes from the stored
MIME type, `Content-Disposition` names the file `<name>-<uuid>.<extension>` and
is `inline` for images, audio, video, PDF and plain text and `attachment` for
everything else, and `X-Content-Type-Options: nosniff` is
always set. SVG, HTML and XML get a sandboxing `Content-Security-Policy` so an
uploaded file cannot run script on the app's origin. `ETag`/`Last-Modified` are
emitted, conditional requests return `304`, and responses are
`Cache-Control: private` so a shared proxy can never serve one user's asset to
another. `403` when the caller may not view it, `404` for an unknown uuid or a
missing file.

**No content negotiation:** the response is always the file bytes, whatever the
request's `Accept` header says. Do not add `Accept`-based branching.

### Identity must reach this route

A browser cannot attach an `Authorization` header to an `<img>`, and the JWT
lives in an httpOnly cookie, so the frontend proxies asset requests server-side
(`/api/assets/...`) and attaches `Authorization: Bearer <token>` there, omitting
it when there is no session so anonymous reads of public assets still work. Do
not add a reverse-proxy rule that forwards `/cms-api/v1/assets/*` while
stripping identity.

### Deployment requirement

`var/assets/` must **not** be served by the web server and must be writable by
PHP.

## Where assets are referenced

- Page content stores the delivery URL (image/video fields, `select-image` /
  `select-video` options, labelled `name.<extension>`). Those URLs keep working
  across edits and replaces.
- Action email attachments store the delivery URL; the mailer resolves it to
  the stored file and attaches it as `<name>-<uuid>.<extension>`. Only assets can be
  attached: saving an action whose attachment is a server path, an external URL
  or an unknown asset is a `400`, and attaching an asset the person saving the
  action may not view is a `403` (see
  [`14-admin-actions.md`](14-admin-actions.md)).
- The navigation logo stores the delivery URL.

## Migration (core 0.1.43)

See [`docs/developer/09-asset-management.md`](../../developer/09-asset-management.md#migration)
for what `Version20261103112133` changes (including the removal of the `admin`
and `therapist` groups: `subject` is the one built-in group). Folder-era asset
URLs in content are not rewritten. `Version20261112103337` then adds the
extension and content hash, makes folder deletion `RESTRICT`, adds the folder
access route and removes the admin role's asset grants; existing assets keep a
NULL extension and hash.
