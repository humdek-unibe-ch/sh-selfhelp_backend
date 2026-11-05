# Admin Assets APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend, core 0.1.43 and later.
Last verified: 2026-11-04.
Source of truth: `AdminAssetController`, `AssetController`, `AdminAssetService`, `AssetAccessService`, the JSON schemas under `config/schemas/api/v1/{requests,responses}/admin/` (`*asset*`), and migration `Version20261103112133`.

## Core concepts

- **Identity.** Every asset has a stable `uuid`, and the uuid **is** its file
  name: the file is stored on disk under it with no extension, so two uploads
  never collide and there is no overwrite. `name` is an editable label without
  extension (the uploaded file name by default). The type comes from the stored
  MIME type.
- **Replace keeps the uuid.** Editing an asset can replace its file; the uuid,
  and therefore the URL every page already uses, stays the same.
- **Viewing is per asset, by group.** An asset with **no groups is public**,
  including to visitors who are not logged in. With groups, only members of
  any of them can view it. The admin role views every asset.
- **Managing is per asset, by role.** Which roles may list, edit, delete and
  export an asset in the CMS is a `role_data_access` grant (resource type
  `asset`). An upload is granted to the uploader's roles. The admin role
  manages every asset. Route permissions (`admin.asset.read|create|update|delete`)
  still gate each action; the grant decides which assets it applies to.
- **Folders organise.** Nestable folders have no access meaning.
- **Default alt.** An image section whose own `alt` is empty gets the asset's
  alt in the page payload; a section's own alt always wins.

## Asset object

Returned by every admin endpoint (`responses/admin/assets/asset.json`):

| Field | Type | Notes |
| --- | --- | --- |
| `id` | int | |
| `uuid` | string | Stable public id; survives file replacement |
| `asset_type` | string | `Asset` or `CSS` |
| `name` | string\|null | Editable label, no extension |
| `file_path` | string | Logical storage key (`uploads/assets/<uuid>`). Not fetchable - use `url` |
| `url` | string | `/cms-api/v1/assets/<uuid>`, the only way to fetch the bytes |
| `mime_type` | string\|null | Recorded at upload |
| `size` | int\|null | Bytes |
| `alt` | string\|null | Default alt text |
| `folder_id` | int\|null | Folder; null = top level |
| `roles` | `{id, name}[]` | Roles that may manage it |
| `groups` | `{id, name}[]` | Groups that may view it; empty = public |
| `created_at`, `updated_at` | string | ISO 8601 |

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
| `role_ids` | Comma-separated ids of the roles that may manage it. **Omitted = the uploader's roles** |
| `folder_id` | Folder id; empty = top level |

Id lists are strings because multipart cannot express an empty list.

Responses: `201` with the asset (single file), or `201`/`206` with
`{ uploaded, errors, total_files, successful_uploads, failed_uploads }`
(several files; `206` when any failed). `400` for a disallowed file type or a
missing file.

Allowed extensions: images (jpg, jpeg, png, gif, webp, svg), pdf, video (mp4,
avi, mov, wmv, flv, webm), audio (mp3, wav, ogg), css, js, archives (zip, rar,
7z), json, txt and Office files.

## Edit and replace

`POST /cms-api/v1/admin/assets/{assetId}` - permission `admin.asset.update`
(and an `update` grant on the asset), `multipart/form-data`, validated by
`requests/admin/update_asset.json`. Only the fields sent change:

| Field | Notes |
| --- | --- |
| `file` | Optional. Replaces the bytes under the **same** uuid |
| `name` | New label (empty keeps the current one) |
| `alt` | New default alt (empty clears it) |
| `group_ids` | **Replaces** the viewing groups (empty = public) |
| `role_ids` | **Replaces** the managing roles |
| `folder_id` | Moves it (empty = top level) |

It is a `POST` because PHP does not parse multipart bodies on `PUT`. Changing
the alt invalidates cached page sections so pages show the new alt at once.

## Bulk actions

`POST /cms-api/v1/admin/assets/bulk-groups` - permission `admin.asset.update`

```json
{ "asset_ids": [12, 13], "group_ids": [5], "mode": "add" }
```

`mode` is `add` or `remove`. Response `data`: `{ "updated": 2 }`. `404` if any
asset id does not exist, `403` if the caller may not update one of them.

`POST /cms-api/v1/admin/assets/bulk-delete` - permission `admin.asset.delete`

```json
{ "asset_ids": [12, 13] }
```

Response `data`: `{ "deleted": 2 }`.

## Delete

`DELETE /cms-api/v1/admin/assets/{assetId}` - permission `admin.asset.delete`

Removes the row, its grants and the stored file. Pages still referencing the
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

Response `data`: `[{ id, name, parent_id, asset_count }]`; `asset_count` is
the number of assets directly in the folder that the caller may manage. `400` when a folder would move
into itself or one of its subfolders. Deleting a folder deletes its
subfolders; their assets move to the top level.

## Export

`POST /cms-api/v1/admin/assets/export` - permission `admin.asset.read`

Body `{ "asset_ids": [12, 13] }`; empty or omitted exports every asset the
caller may manage. Returns `application/zip`, bundle `selfhelp/asset-bundle`
version `2.0`:

```
manifest.json    { bundle_type, bundle_version, exported_at, assets: [
                   { uuid, name, alt, mime_type, asset_type, groups: [names], bundle_path } ] }
files/<uuid>     the bytes
```

Groups travel by **name** because ids differ between instances.

## Import

`POST /cms-api/v1/admin/assets/import` - permission `admin.asset.create`,
multipart field `file` (the zip).

Import never overwrites: an entry whose uuid already exists is **skipped**.
Otherwise the asset is created with the same uuid, managed by the importer's
roles. Group names that do not exist on this instance are ignored. Version
`1.0` (folder-era) bundles still import, as public assets.

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

**Responses:** `200` with the file inline. `Content-Type` comes from the stored
MIME type, `Content-Disposition` names the file
`<uuid>.<extension of its MIME type>`, and `X-Content-Type-Options: nosniff` is
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
PHP. `public/.htaccess` denies `/uploads/assets/` as defence in depth.

## Where assets are referenced

- Page content stores the delivery URL (image/video fields, `select-image` /
  `select-video` options, labelled `name.<extension>`). Those URLs keep working
  across edits and replaces.
- Action email attachments store the delivery URL; the mailer resolves it to
  the stored file and attaches it as `<uuid>.<extension>`.
- The navigation logo stores the delivery URL.

## Migration (core 0.1.43)

See [`docs/developer/09-asset-management.md`](../../developer/09-asset-management.md#migration-from-the-folder-model)
for what `Version20261103112133` changes (including the group rename to
`researcher` and the removal of the `admin` group). Folder-era asset URLs in
content are not rewritten.
