# Admin Languages APIs

## Overview

The Admin Languages APIs provide comprehensive language management functionality for the SelfHelp CMS. Languages are essential for content internationalization, allowing the system to support multiple languages for both content and the user interface.

## Core Concepts

- **Languages**: System-supported languages with locale codes (e.g., 'en', 'de', 'fr')
- **Translations**: Content translations for different languages
- **CSV Separator**: Language-specific separator for data import/export operations
- **Internal Languages**: System languages not exposed to end users

## Get All Languages

Retrieve a list of all non-internal languages available in the system.

**Endpoint:** `GET /cms-api/v1/admin/languages`

**Authentication:** Required (JWT Bearer token)

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": [
    {
      "id": 1,
      "locale": "en",
      "language": "English",
      "csv_separator": ",",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-20T14:45:00Z"
    },
    {
      "id": 2,
      "locale": "de",
      "language": "German",
      "csv_separator": ";",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-20T14:45:00Z"
    }
  ]
}
```

**Permissions:** `admin.settings`

## Get Single Language

Retrieve detailed information about a specific language.

**Endpoint:** `GET /cms-api/v1/admin/languages/{id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `id`: Language ID

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "id": 1,
    "locale": "en",
    "language": "English",
    "csv_separator": ",",
    "created_at": "2024-01-15T10:30:00Z",
    "updated_at": "2024-01-20T14:45:00Z"
  }
}
```

**Permissions:** `admin.settings`

## Create Language

Add a new language to the system.

**Endpoint:** `POST /cms-api/v1/admin/languages`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "locale": "fr",
  "language": "French",
  "csv_separator": ";"
}
```

**Field Descriptions:**
- `locale`: ISO language code (e.g., 'en', 'de', 'fr') - must be unique
- `language`: Human-readable language name
- `csv_separator`: CSV separator character for this language (optional, defaults to ',')

**Response:**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "id": 3,
    "locale": "fr",
    "language": "French",
    "csv_separator": ";",
    "created_at": "2025-01-23T10:30:00Z",
    "updated_at": "2025-01-23T10:30:00Z"
  }
}
```

**Permissions:** `admin.settings`

## Update Language

Modify an existing language's properties.

**Endpoint:** `PUT /cms-api/v1/admin/languages/{id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `id`: Language ID to update

**Request Body:**
```json
{
  "locale": "fr-CA",
  "language": "French (Canada)",
  "csv_separator": ","
}
```

**Response:** Updated language data (same format as GET single language)

**Permissions:** `admin.settings`

## Delete Language

Remove a language from the system.

**Endpoint:** `DELETE /cms-api/v1/admin/languages/{id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `id`: Language ID to delete

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "id": 3,
    "locale": "fr",
    "language": "French",
    "deleted": true
  }
}
```

**Permissions:** `admin.settings`

## Frontend Integration Examples

### Language Management Component

```javascript
const LanguageManager = () => {
  const [languages, setLanguages] = useState([]);
  const [editingLanguage, setEditingLanguage] = useState(null);
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    loadLanguages();
  }, []);

  const loadLanguages = async () => {
    try {
      const response = await apiRequest('/admin/languages');
      setLanguages(response.data);
    } catch (error) {
      console.error('Failed to load languages:', error);
    }
  };

  const createLanguage = async (languageData) => {
    setIsLoading(true);
    try {
      const response = await apiRequest('/admin/languages', {
        method: 'POST',
        body: JSON.stringify(languageData)
      });

      setLanguages([...languages, response.data]);
      showSuccess('Language created successfully');
    } catch (error) {
      showError('Failed to create language');
    } finally {
      setIsLoading(false);
    }
  };

  const updateLanguage = async (id, languageData) => {
    setIsLoading(true);
    try {
      const response = await apiRequest(`/admin/languages/${id}`, {
        method: 'PUT',
        body: JSON.stringify(languageData)
      });

      setLanguages(languages.map(lang =>
        lang.id === id ? response.data : lang
      ));
      setEditingLanguage(null);
      showSuccess('Language updated successfully');
    } catch (error) {
      showError('Failed to update language');
    } finally {
      setIsLoading(false);
    }
  };

  const deleteLanguage = async (id) => {
    if (!confirm('Are you sure you want to delete this language?')) return;

    try {
      await apiRequest(`/admin/languages/${id}`, {
        method: 'DELETE'
      });

      setLanguages(languages.filter(lang => lang.id !== id));
      showSuccess('Language deleted successfully');
    } catch (error) {
      showError('Failed to delete language');
    }
  };

  return (
    <div className="language-manager">
      <h2>Language Management</h2>

      <div className="languages-list">
        {languages.map(language => (
          <div key={language.id} className="language-item">
            <div className="language-info">
              <strong>{language.language}</strong> ({language.locale})
              <span className="csv-separator">CSV: {language.csv_separator}</span>
            </div>
            <div className="language-actions">
              <button onClick={() => setEditingLanguage(language)}>
                Edit
              </button>
              <button onClick={() => deleteLanguage(language.id)}>
                Delete
              </button>
            </div>
          </div>
        ))}
      </div>

      <LanguageForm
        language={editingLanguage}
        onSubmit={editingLanguage ? updateLanguage : createLanguage}
        onCancel={() => setEditingLanguage(null)}
        isLoading={isLoading}
      />
    </div>
  );
};
```

### Language Form Component

```javascript
const LanguageForm = ({ language, onSubmit, onCancel, isLoading }) => {
  const [formData, setFormData] = useState({
    locale: '',
    language: '',
    csv_separator: ','
  });

  useEffect(() => {
    if (language) {
      setFormData({
        locale: language.locale,
        language: language.language,
        csv_separator: language.csv_separator
      });
    } else {
      setFormData({
        locale: '',
        language: '',
        csv_separator: ','
      });
    }
  }, [language]);

  const handleSubmit = (e) => {
    e.preventDefault();

    if (language) {
      onSubmit(language.id, formData);
    } else {
      onSubmit(formData);
    }
  };

  const validateLocale = (locale) => {
    // Basic locale validation (xx or xx-XX format)
    return /^[a-z]{2}(-[A-Z]{2})?$/.test(locale);
  };

  return (
    <form onSubmit={handleSubmit} className="language-form">
      <h3>{language ? 'Edit Language' : 'Add New Language'}</h3>

      <div className="form-group">
        <label htmlFor="locale">Locale Code</label>
        <input
          type="text"
          id="locale"
          value={formData.locale}
          onChange={(e) => setFormData({...formData, locale: e.target.value})}
          placeholder="en, de, fr-CA"
          required
          pattern="^[a-z]{2}(-[A-Z]{2})?$"
          title="Locale should be in format: xx or xx-XX (e.g., en, de, fr-CA)"
        />
      </div>

      <div className="form-group">
        <label htmlFor="language">Language Name</label>
        <input
          type="text"
          id="language"
          value={formData.language}
          onChange={(e) => setFormData({...formData, language: e.target.value})}
          placeholder="English, German, French"
          required
        />
      </div>

      <div className="form-group">
        <label htmlFor="csv_separator">CSV Separator</label>
        <select
          id="csv_separator"
          value={formData.csv_separator}
          onChange={(e) => setFormData({...formData, csv_separator: e.target.value})}
        >
          <option value=",">Comma (,)</option>
          <option value=";">Semicolon (;)</option>
          <option value="\t">Tab</option>
        </select>
      </div>

      <div className="form-actions">
        <button type="submit" disabled={isLoading}>
          {isLoading ? 'Saving...' : (language ? 'Update' : 'Create')}
        </button>
        {onCancel && (
          <button type="button" onClick={onCancel}>
            Cancel
          </button>
        )}
      </div>
    </form>
  );
};
```

### Language Selector Component

```javascript
const LanguageSelector = ({ selectedLanguageId, onLanguageChange }) => {
  const [languages, setLanguages] = useState([]);

  useEffect(() => {
    loadLanguages();
  }, []);

  const loadLanguages = async () => {
    try {
      const response = await apiRequest('/admin/languages');
      setLanguages(response.data);
    } catch (error) {
      console.error('Failed to load languages:', error);
    }
  };

  return (
    <select
      value={selectedLanguageId || ''}
      onChange={(e) => onLanguageChange(e.target.value ? parseInt(e.target.value) : null)}
    >
      <option value="">Select Language</option>
      {languages.map(language => (
        <option key={language.id} value={language.id}>
          {language.language} ({language.locale})
        </option>
      ))}
    </select>
  );
};
```

## Common Use Cases

### Setting Up a New Language

1. **Create the language entry:**
```javascript
await apiRequest('/admin/languages', {
  method: 'POST',
  body: JSON.stringify({
    locale: 'es',
    language: 'Spanish',
    csv_separator: ';'
  })
});
```

2. **Add translations for existing content:**
```javascript
// For each page/section, add Spanish translations
await apiRequest(`/admin/pages/${pageId}`, {
  method: 'PUT',
  body: JSON.stringify({
    pageData: { /* page updates */ },
    fields: [{
      field_name: 'title',
      translations: [{
        language_id: spanishLanguageId,
        value: 'Título en Español'
      }]
    }]
  })
});
```

### Language-Specific CSV Operations

```javascript
const exportWithLanguageSeparator = async (data, languageId) => {
  // Get language info to determine separator
  const languageResponse = await apiRequest(`/admin/languages/${languageId}`);
  const separator = languageResponse.data.csv_separator;

  // Convert data to CSV with appropriate separator
  const csv = convertToCSV(data, separator);

  // Download file
  downloadCSV(csv, `export_${languageResponse.data.locale}.csv`);
};
```

## Error Handling

```javascript
const handleLanguageOperation = async (operation, ...args) => {
  try {
    const response = await apiRequest(operation, ...args);

    if (response.status === 200 || response.status === 201) {
      return { success: true, data: response.data };
    }
  } catch (error) {
    const errorData = error.response?.data;

    if (errorData?.status === 400) {
      // Validation errors
      return {
        success: false,
        error: 'Invalid language data',
        details: errorData.error
      };
    } else if (errorData?.status === 409) {
      return {
        success: false,
        error: 'Language locale already exists',
        action: 'show_duplicate_error'
      };
    } else if (errorData?.status === 403) {
      return {
        success: false,
        error: 'Insufficient permissions',
        action: 'show_permission_error'
      };
    } else {
      return {
        success: false,
        error: 'An unexpected error occurred'
      };
    }
  }
};
```

## Best Practices

1. **Locale Standards**: Use standard ISO locale codes (e.g., 'en', 'de', 'fr-CA')
2. **Unique Locales**: Ensure locale codes are unique across the system
3. **CSV Separators**: Set appropriate separators based on language conventions
4. **Validation**: Always validate locale format and uniqueness
5. **Testing**: Test language switching and content display
6. **Backup**: Backup translations before language deletion
7. **Caching**: Clear relevant caches after language operations

## Integration with Content Management

Languages work closely with the content management system:

- **Page Translations**: Each page can have content in multiple languages
- **Section Translations**: Sections support multi-language content fields
- **User Preferences**: Users can set their preferred language
- **Date/Time Formatting**: Dates are formatted according to language locale

---

**Next:** [Admin Assets](./06-admin-assets.md) | **Previous:** [Admin Pages & Sections](./04-admin-pages-sections.md) | **Back to:** [API Overview](../README.md)
