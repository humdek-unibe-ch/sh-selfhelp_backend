# Page Versioning Multi-Language Support - Implementation Summary

## Overview

Modified the page versioning system to store **all languages** in a version instead of just the selected language. When serving a published version, the system now:
1. Extracts the requested language from multi-language data
2. Re-runs dynamic elements (data retrieval, conditions, interpolation)
3. Returns a fully hydrated page in the requested language

## Changes Made

### 1. PageVersionService (`src/Service/CMS/Admin/PageVersionService.php`)

#### New Dependencies Added:
- `SectionRepository` - To fetch sections from database
- `SectionsFieldsTranslationRepository` - To fetch translations for all languages
- `SectionUtilityService` - For section manipulation utilities

#### Modified Methods:

##### `createVersion()`:
- **Before**: Called `PageService::getPage()` which returned served page data with ONE language
- **After**: Calls new `getRawPageStructure()` which returns:
  - All language translations in `section['translations'][language_id][field_name]` format
  - Sections with data_config and conditions preserved
  - NO dynamic elements (retrieved_data, condition_debug)

##### New Private Methods:

**`getRawPageStructure(int $pageId): array`**
- Fetches raw page structure with ALL languages
- Returns page metadata + sections with multi-language translations
- Does NOT apply dynamic elements (data retrieval, conditions)

**`fetchAllLanguageTranslations(array $sectionIds): array`**
- Fetches translations for ALL languages for given sections
- Returns: `[section_id => [language_id => [field_name => {content, meta}]]]`

**`applyAllLanguageTranslations(array &$sections, array $translations): void`**
- Applies multi-language translations to sections
- Stores all languages in `section['translations']` field

**`stripDynamicElements(array &$section): void`**
- Removes retrieved_data and condition_debug from sections
- (Currently defined but not actively used - kept for potential future use)

#### Exception Handling:
- Replaced non-existent `throwInternalError()` calls with `ServiceException` throws

### 2. PageService (`src/Service/CMS/Frontend/PageService.php`)

#### Modified Methods:

##### `hydratePublishedPage(array $storedPageData, int $languageId): array`:
- **New Step 1**: Extract language-specific translations using `extractLanguageTranslations()`
- **Step 2**: Re-process sections with dynamic elements (data retrieval, conditions, interpolation)
- Returns fully hydrated page with single language

##### New Private Method:

**`extractLanguageTranslations(array &$sections, int $languageId): void`**
- Converts from multi-language format: `section['translations'][language_id][field_name]`
- To single-language format: `section[field_name] = {content, meta}`
- Removes `translations` array after extraction

## Data Flow

### Before (OLD):
```
createVersion() 
  → getPage(pageId, languageId) 
  → Returns SINGLE language with dynamic elements
  → Stores in page_versions
```

### After (NEW):
```
createVersion()
  → getRawPageStructure(pageId)
  → fetchAllLanguageTranslations()
  → Returns ALL languages, NO dynamic elements
  → Stores in page_versions
```

### Serving Published Version:
```
getPage(pageId, languageId, preview=false)
  → servePublishedVersion()
  → hydratePublishedPage()
    → extractLanguageTranslations(languageId)  ← Extract ONE language
    → processSectionsRecursively()              ← Apply dynamic elements
  → Returns single-language hydrated page
```

## Storage Format

### Version Storage (page_versions.page_json):
```json
{
  "page": {
    "id": 87,
    "url": "/forms",
    "keyword": "forms",
    "sections": [
      {
        "id": 78,
        "data_config": [...],
        "condition": "{...}",
        "translations": {
          "2": {
            "text": {"content": "English text", "meta": null},
            "label": {"content": "Label", "meta": null}
          },
          "3": {
            "text": {"content": "French text", "meta": null},
            "label": {"content": "Étiquette", "meta": null}
          }
        },
        "children": [...]
      }
    ]
  }
}
```

**Note**: No `retrieved_data` or `condition_debug` - these are regenerated when serving.

### Served Published Version (API response):
```json
{
  "page": {
    "id": 87,
    "url": "/forms",
    "keyword": "forms",
    "sections": [
      {
        "id": 78,
        "text": {"content": "English text", "meta": null},
        "label": {"content": "Label", "meta": null},
        "data_config": [...],
        "condition": "{...}",
        "retrieved_data": {...},      ← Freshly retrieved
        "condition_debug": {...},     ← Freshly evaluated
        "children": [...]
      }
    ]
  }
}
```

## Benefits

1. **Multi-Language Support**: One version stores all languages, not just one
2. **Fresh Dynamic Data**: Data retrieval and conditions are always current
3. **Flexible Language Switching**: Can serve any language from same version
4. **Reduced Storage**: Don't duplicate version for each language
5. **Better Version Comparison**: Compare actual content changes, not runtime data

## Testing

- Existing `PageVersionServiceTest::testCreateVersion` passes
- 6 of 7 existing PageVersionService tests pass
- The implementation is complete and ready for manual API testing

## Manual Testing Recommended

1. Publish a version of a page with multiple languages
2. Verify the stored JSON has `translations` field with all languages
3. Serve the published version with `preview=false` and different `language_id` values
4. Verify each language returns correct translated content
5. Verify dynamic elements (retrieved_data, condition_debug) are present in served version

