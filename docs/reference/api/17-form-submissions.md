# Form Submission APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-11-26.
Source of truth: Controllers, JSON schemas, route definitions, and exported types in this repository (form storage keys resolved by `App\Service\CMS\DataColumnService`).

## Overview

The Form Submission APIs handle user-generated content through web forms. These endpoints support both JSON and multipart/form-data submissions, enabling rich form experiences with file uploads.

## Core Concepts

### Form Submissions
- **Data Collection**: User input from web forms
- **File Uploads**: Support for file attachments
- **Section Integration**: Forms tied to specific page sections
- **Data Storage**: Form data stored in configurable data tables

### Content Types
- **JSON Forms**: Standard form data submission
- **Multipart Forms**: Forms with file uploads
- **Update Operations**: Modify existing form submissions
- **Delete Operations**: Remove form data

## Form Operations

### Submit Form

Submit new form data from a web form.

**Endpoint:** `POST /cms-api/v1/forms/submit`

**Authentication:** None (public endpoint)

**Request Body (JSON):**
```json
{
  "page_id": 1,
  "section_id": 5,
  "form_data": {
    "name": "John Doe",
    "email": "john@example.com",
    "message": "Contact form submission"
  }
}
```

*Note: Form submission schemas are validated dynamically based on the specific form configuration.*

**Request Body (Multipart with files):**
```
Content-Type: multipart/form-data

page_id=1
section_id=5
form_data={"name":"John","email":"john@example.com"}
attachment=@document.pdf
```

The file part's name must be the `name` of a `file-input` in that form (here `attachment`); any other name is rejected with `400`. Files are stored outside the document root and the record keeps a logical key (`uploads/form-files/user_<id>/section_<id>/field_<name>/<file>`), readable only through [Download an uploaded file](#download-an-uploaded-file).

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": false,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "submission_id": 123,
    "status": "submitted",
    "message": "Form submitted successfully"
  }
}
```

The success `data` also returns `record_id`, `section_id`, `page_id`, `submitted_at`, `user_authenticated` and the new record's `version` (`1`); see `responses/frontend/form_submitted.json`.

Every write of a form (submit, update, delete, and a list's quick action) also stores which version of the page the person was shown, on the record and on its revision: `page:<page id>@v<n>` for the published version served, `page:<page id>@draft` for an editor's preview or a page that was never published. The client does not send it and cannot change it. Admins read it as `content_ref` in the data browser, the record history and the exports; see [record-history](../../developer/record-history.md#content-reference).

### Update Form Submission

Modify an existing form submission.

**Endpoint:** `PUT /cms-api/v1/forms/update`

**Authentication:** None (public endpoint)

**Request Body:**
```json
{
  "page_id": 1,
  "section_id": 5,
  "form_data": {
    "name": "John Smith",
    "email": "john.smith@example.com"
  },
  "update_based_on": {
    "record_id": 123
  },
  "expected_version": 3
}
```

**Response:** Update confirmation (`record_id`, the new `version`).

`update_based_on` names the entry by its number (`{"record_id": 123}`, alone) or by
field values of the form (`{"email": "john@example.com"}`, plain input names only). An
update that names no entry, or one the dataset of the section cannot select that way,
answers `400 unsupported_target_selection`; it never falls back to "the first entry of
the table" and it never creates an entry.

**`expected_version` (integer, at least 1) is required for every update and delete.** It is
the record version the caller read (`data_rows.version`; the submit, update and delete
responses return the new `version`, the list rows carry it). An update without it, or with a
value that is not a whole number of at least 1 (also as multipart text: digits only, no
sign, no leading zero), answers `400 expected_version_required` / `invalid_expected_version`
before anything is stored, uploaded or scheduled. When the stored version differs the
request answers `409` with `data: {reason: "stale_version", record_id, expected_version, current_version}`
and nothing is written. Clients always send it ([22-form-lists.md](22-form-lists.md)).

Where a client gets the version for the classic record styles (no list state around them):
the page payload says it, read when the page is built, so it goes with the record the page
shows (the cached page is scoped to the form's data table and is rebuilt after every save).

| Style | Where the version is |
| --- | --- |
| `form-record`, `entry-record-form` | every row of `section_data` (one per language) carries `_version`, next to `record_id`; it is not a form field and is never sent back inside `form_data` |
| `entry-table` | every entry that offers edit or delete carries `_version` next to `_can_edit` / `_can_delete` |
| `entry-record-delete` | the section carries `record_version` (`{content, meta}`, like `record_id`); it is absent when the record no longer exists |
| `entry-form-list` | `selected.version` and `rows[].version` of the list state |

The form's table must be the table of the section named in `section_id`; a form that belongs to
a list is also guarded by the list's operation switches (`403 operation_disabled`) and by the
list's dataset (see [Rules every write follows](#rules-every-write-follows)).

### Delete Form Submission

Remove a form submission from the system.

**Endpoint:** `DELETE /cms-api/v1/forms/delete`

**Authentication:** None (public endpoint)

**Request Body:**
```json
{
  "record_id": 123,
  "page_id": 1,
  "section_id": 5,
  "expected_version": 3
}
```

`section_id` is a section of style `entry-table`, `entry-record-delete` or
`entry-form-list` on `page_id`; the record must belong to that section's table
(`400 table_mismatch` otherwise). `expected_version` is required and behaves as for
updates (`400` when missing or malformed, `409` when stale).

**Response:** Deletion confirmation (`record_id`, `version`). The record is soft deleted;
its revision history is kept.

### Rules every write follows

These rules are the same for a submit, an update, a delete and, for an *Entry List with
Form*, for a quick action and a restore of an earlier version. A refused write is complete:
no entry, no revision, no stored upload and no scheduled action is left behind.

- **The body is untrusted.** `form_data` may only carry values for the inputs of the form
  as the page is served: the published version of the page, or the draft when a signed-in
  person who may read the draft sends `"preview": true` for the draft they are looking at.
  A key that is not an input of that form answers `400 unknown_field`; a key that names the
  owner, the trigger or other internal data (`id`, `id_users`, `id_action_trigger_types`,
  `triggerType`, `timestamp`, `version`, `record_id`, anything starting with `__`, …)
  answers `400 protected_field`. The owner is always the signed-in person and the trigger is
  always the one of the route. (A form that declares no input at all keeps accepting plain
  keys; every form built with the page builder declares its inputs.)
- **The rules of the inputs apply.** Required, range (`min`/`max`), length, format (e-mail,
  web address), the offered options, dates and time, read-only inputs, inputs a condition
  hides from the person, and the accepted file types and counts are checked against the
  input definitions of the same effective page. A violation answers
  `422 invalid_form_data` with `errors: [{field, key, rule, message}]`; the value a quick
  action assigns and the fields a restore brings back answer to the same rules (a restore
  that would bring back a value the form refuses now blocks the whole restore:
  `422 restore_blocked`).
- **Records are only reached inside the dataset.** For an *Entry List with Form*, a record
  number only means something inside the list's table, its fixed filter and, for lists of
  own entries, the person's own records. Anything else answers `404`, like a record that
  does not exist; for ordinary updates, deletes, alternative selections, quick actions, the
  history and restores alike. A write may move an entry out of the dataset (a status the
  filter does not show); the next request then no longer reaches it.
- **Access.** Page access of the person's groups (`select`) lets a participant read the
  page and use the operations the list's switches (`allow_create`, `allow_update`,
  `allow_delete`) offer, on their own entries; changing someone else's entry needs the
  existing data-table grant. Page `insert`/`update`/`delete` rights are not consulted for
  these writes.
- **Published or draft.** A visitor is always served and written through the published
  configuration of the page. Access rights and the stored data stay live.

### Download an uploaded file

Return a file uploaded through a form.

**Endpoint:** `GET /cms-api/v1/forms/files/{record_id}/{field}?index=0`

- `field`: the `file-input` name (or its `section_<id>` column key), or a column declared with the value type `file` (a plugin's upload column). A cell may hold one key, a list of keys, or JSON with keys inside (a plugin's answer object); `index` counts the keys in that order.
- `index`: which file of a multi-file field (default `0`).

**Authentication:** JWT required. Allowed for the user who submitted the record, or a user with READ on the form's data table (admins always). The field's `files_public` switch no longer opens this route to anyone with the link (F165); page viewers use the section-scoped list file route instead ([22-form-lists.md](22-form-lists.md#current-file-of-a-shown-record)). No route-level permission; access is checked per record.

**Response:** the file bytes (not the JSON envelope), with `X-Content-Type-Options: nosniff`, a sandboxing `Content-Security-Policy`, and `Cache-Control: private, no-store`. HTML/XML are sent as `attachment`; everything else, including SVG, `inline` so it can be viewed (the sandbox CSP stops any script in it). Errors: `401` anonymous, `404` not allowed / unknown record / non-file field / missing file (no `403` that reveals a hidden record).

### Download several uploaded files as a zip

- `GET /cms-api/v1/forms/files/{record_id}/{field}/zip`: every file of one multi-file field, as `record_<id>_<field>.zip`. Same access rule as a single file.
- `GET /cms-api/v1/admin/data/tables/{tableName}/files?record_ids=1,2,3`: the files of the listed records of a form data table (the rows the admin is looking at), as `<table>_files.zip` with entries `record_<id>/<field>/<file>`. Requires the `admin.data.read` permission and READ on the table.

Archives are built on demand and deleted after sending; more than 500 MB returns `413`.

## Form Data Structure

### Form Data Object

```json
{
  "page_id": 1,
  "section_id": 5,
  "form_data": {
    "field_name": "field_value",
    "email": "user@example.com",
    "checkbox_field": true,
    "select_field": "option_1",
    "multi_select": ["option_1", "option_2"],
    "file_field": "uploaded_file_id"
  },
  "files": ["file1.jpg", "document.pdf"]
}
```

> **`form_data` keys are the human input names; the backend maps them to a
> stable storage key.** Core CMS forms (frontend + mobile) submit each value
> keyed by the input's **`name`** field, exactly as authored — no client change
> is needed. On write, `DataService` remaps every name to the immutable
> **`field_key`** of the input that owns it (`section_<input section id>`) and
> carries the name along as the auto `display_name`. Because the key is tied to
> the input **section id** (which never changes), renaming an input only updates
> its `display_name` and **never** forks the column or splits old and new
> submissions (issue #56). On read, prefill / `entry-table` / export remap
> `field_key → current name`, so every human-facing surface shows the current
> label. SurveyJS submits its own stable key (`question.name`) with the question
> `title` as the label; see the per-source `field_key` derivation contract in
> [Database Design](../../developer/04-database-design.md#field_key-derivation-contract-per-data-source).
> Reserved metadata keys (`id`, `id_users`, `trigger_type`, `record_id`,
> anything starting with `__`, …) are never remapped and are stripped before
> write. Labels can also be curated manually via
> `PATCH /admin/data/tables/{tableName}/columns/display-name` (a `manual` label
> is never overwritten by a later submission).

## Frontend Integration Examples

### Form Submission Handler

```javascript
const submitForm = async (formData, files = []) => {
  try {
    const data = new FormData();

    // Add form metadata
    data.append('page_id', formData.pageId);
    data.append('section_id', formData.sectionId);

    // Add form data as JSON string
    data.append('form_data', JSON.stringify(formData.fields));

    // Add files
    files.forEach(file => {
      data.append('files[]', file);
    });

    const response = await fetch('/cms-api/v1/forms/submit', {
      method: 'POST',
      body: data
    });

    const result = await response.json();

    if (response.ok) {
      showSuccess('Form submitted successfully!');
      // Reset form
      resetForm();
    } else {
      showError(result.error || 'Form submission failed');
    }
  } catch (error) {
    showError('Network error. Please try again.');
  }
};

// Usage
const handleSubmit = (e) => {
  e.preventDefault();

  const formData = {
    pageId: 1,
    sectionId: 5,
    fields: {
      name: document.getElementById('name').value,
      email: document.getElementById('email').value,
      message: document.getElementById('message').value
    }
  };

  const files = document.getElementById('files').files;
  submitForm(formData, Array.from(files));
};
```

### File Upload Progress

```javascript
const uploadWithProgress = (formData, files, onProgress) => {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        const percentComplete = (e.loaded / e.total) * 100;
        onProgress(percentComplete);
      }
    });

    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(JSON.parse(xhr.responseText));
      } else {
        reject(new Error(xhr.responseText));
      }
    });

    xhr.addEventListener('error', () => {
      reject(new Error('Upload failed'));
    });

    const data = new FormData();
    data.append('page_id', formData.pageId);
    data.append('section_id', formData.sectionId);
    data.append('form_data', JSON.stringify(formData.fields));

    files.forEach(file => {
      data.append('files[]', file);
    });

    xhr.open('POST', '/cms-api/v1/forms/submit');
    xhr.send(data);
  });
};

// Usage with progress bar
const handleSubmitWithProgress = async (e) => {
  e.preventDefault();

  const progressBar = document.getElementById('upload-progress');
  const submitButton = document.getElementById('submit-btn');

  submitButton.disabled = true;
  progressBar.style.display = 'block';

  try {
    const result = await uploadWithProgress(formData, files, (progress) => {
      progressBar.value = progress;
    });

    showSuccess('Form submitted successfully!');
    resetForm();
  } catch (error) {
    showError('Upload failed. Please try again.');
  } finally {
    submitButton.disabled = false;
    progressBar.style.display = 'none';
  }
};
```

### Form Validation

```javascript
const validateForm = (formData) => {
  const errors = {};

  // Required fields
  if (!formData.fields.name?.trim()) {
    errors.name = 'Name is required';
  }

  // Email validation
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!formData.fields.email?.trim()) {
    errors.email = 'Email is required';
  } else if (!emailRegex.test(formData.fields.email)) {
    errors.email = 'Please enter a valid email address';
  }

  // File validation
  if (files.length > 0) {
    files.forEach((file, index) => {
      if (file.size > 10 * 1024 * 1024) { // 10MB limit
        errors[`file_${index}`] = 'File size must be less than 10MB';
      }

      const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
      if (!allowedTypes.includes(file.type)) {
        errors[`file_${index}`] = 'File type not allowed';
      }
    });
  }

  return errors;
};

// Enhanced form submission with validation
const submitValidatedForm = async (formData, files) => {
  const validationErrors = validateForm(formData, files);

  if (Object.keys(validationErrors).length > 0) {
    showValidationErrors(validationErrors);
    return;
  }

  // Proceed with submission
  await submitForm(formData, files);
};
```

## Security Considerations

1. **Input Validation**: All form data is validated against schemas
2. **File Upload Security**: File type and size restrictions
3. **Rate Limiting**: Prevent form spam and abuse
4. **Data Sanitization**: Input sanitization to prevent XSS
5. **CSRF Protection**: Cross-site request forgery protection
6. **Captcha Integration**: Optional CAPTCHA for spam prevention

## Best Practices

1. **Client-Side Validation**: Validate forms before submission
2. **Progress Indicators**: Show upload progress for large files
3. **Error Handling**: Comprehensive error messages and recovery
4. **File Management**: Handle multiple file uploads gracefully
5. **Form State**: Preserve form state during submission failures
6. **Accessibility**: Ensure forms are accessible to all users
7. **Mobile Optimization**: Responsive form design

> **Where does submitted data go?** Form data is stored in data tables named
> after the form's section id. Admins read, delete, and export those tables
> through the [Admin Data Tables APIs](./18-admin-data-tables.md).

---

**Back to:** [API Overview](../../README.md)
