# CMS Translation System

## Core Translation Principles

1. **Default language as source**: All initial content is saved in the default language
2. **Centralized translation interface**: Translators can work efficiently with all content in one place
3. **Automatic fallback mechanism**: Ensures content is always available even when translations are incomplete
4. **Database-driven translations**: Dynamic content is stored in translation tables with language associations

### Translation Interface Features

- Group translations by content type (pages, sections)
- Show side-by-side translation editing with the default language as reference
- Add visual indicators for missing translations

### Batch Translation Operations

- Export/import translations (CSV/XLSX)
- Batch translation status updates

### Translation Caching

- Caching layer for translations to improve performance
- Symfony's cache component with tags for efficient invalidation