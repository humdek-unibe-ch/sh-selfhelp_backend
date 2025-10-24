# CMS Versioning System

The SelfHelp Backend implements a comprehensive page versioning and publishing system. This system provides robust version management with multi-language support, draft comparison, and fast change detection.

## Key Features

- **Hybrid Versioning**: Store page structure while dynamically refreshing data
- **Multi-Language Support**: Store all language translations in a single version
- **Complete JSON Storage**: Store all languages, conditions, data table configs in published versions
- **Fresh Data**: Data tables are re-queried when serving published versions
- **Version Comparison**: Multiple diff formats (unified, side-by-side, JSON Patch, summary)
- **Draft Comparison**: Real-time comparison between current draft and published version
- **Fast Change Detection**: Hash-based detection of unpublished changes (< 50ms)
- **Retention Policies**: Automated cleanup of old versions

## Implementation Details

For comprehensive documentation on the versioning system, including API endpoints, database schema, and service layer implementation, see:

**[Page Versioning & Publishing System](./developer/18-page-versioning-publishing.md)**
