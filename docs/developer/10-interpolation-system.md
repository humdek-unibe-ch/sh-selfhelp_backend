# Interpolation System

**Date:** 2025-10-24  
**Status:** ✅ Production Ready

---

## Overview

The SelfHelp backend uses a sophisticated variable interpolation system that allows dynamic content rendering in pages and sections. Variables are properly namespaced and interpolated using Mustache.php templating engine.

The system supports **preview pages** and **published pages** with different data sources and processing flows.

---

## 🎯 Variable Namespacing - Complete Implementation

### **Variables are now properly namespaced - NO MORE FLATTENING!**

All variables must now be accessed via their namespace prefix to prevent collisions and provide clear organization.

```javascript
// OLD (Flattened - NO LONGER WORKS)
{
  "user_name": "stefan@example.com",
  "my_var": "english",
  "language": 3,
  "parent": {...}
}

// NEW (Namespaced - CURRENT STRUCTURE)
{
  "system": {
    "user_name": "stefan@example.com",
    "language": 3,
    "current_date": "2025-10-24"
  },
  "globals": {
    "my_var": "english"
  },
  "parent": {
    "text": "2"
  }
}
```

### ✅ Mustache Handles This Perfectly!

**Yes, Mustache.php natively supports nested objects** - confirmed with 14 passing tests!

- ✅ `{{system.user_name}}` works
- ✅ `{{globals.my_var}}` works
- ✅ `{{parent.text}}` works
- ✅ No performance issues
- ✅ No need to flatten!

---

## 📝 Template Migration

### Update Your Templates

**Before:**
```html
{{user_name}} - {{my_var}} - {{language}}
```

**After:**
```html
{{system.user_name}} - {{globals.my_var}} - {{system.language}}
```

### Complete Variable Map

| Old Variable | New Variable | Type |
|--------------|--------------|------|
| `{{user_name}}` | `{{system.user_name}}` | System |
| `{{user_email}}` | `{{system.user_email}}` | System |
| `{{user_code}}` | `{{system.user_code}}` | System |
| `{{user_id}}` | `{{system.user_id}}` | System |
| `{{language}}` | `{{system.language}}` | System |
| `{{current_date}}` | `{{system.current_date}}` | System |
| `{{current_datetime}}` | `{{system.current_datetime}}` | System |
| `{{current_time}}` | `{{system.current_time}}` | System |
| `{{platform}}` | `{{system.platform}}` | System |
| `{{page_keyword}}` | `{{system.page_keyword}}` | System |
| `{{project_name}}` | `{{system.project_name}}` | System |
| `{{my_var}}` | `{{globals.my_var}}` | Global |
| Any global var | `{{globals.variable_name}}` | Global |

---

## 🔄 How Interpolation Works - Complete Flow

### Phase 1: Section Structure Loading

**Source Options:**
1. **Published Version**: Load from `page_versions` table → Extract language translations
2. **Preview Version**: Load fresh from database with translations

### Phase 2: Processing Pipeline (`PageService::processSectionsRecursively`)

```
┌─────────────────────────────────────────────────────────┐
│ STEP 1: Interpolate data_config with parent data       │
│ Method: interpolateDataConfigInSection()                │
│ Purpose: Allow filters like {{parent.record_id}}       │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ STEP 2: Apply section data                             │
│ Method: SectionUtilityService::applySectionData()      │
│ Creates: retrieved_data = {system: {...}, globals:{...}}│
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ STEP 3: Retrieve data from interpolated data_config    │
│ Method: retrieveSectionData()                           │
│ Adds: parent, test, etc. scopes to retrieved_data      │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ STEP 4: Merge parent + section data                    │
│ Method: mergeDataEfficiently()                          │
│ Result: Complete structured data for interpolation     │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ STEP 5: Interpolate ALL content fields                 │
│ Method: applyOptimizedInterpolationPass()               │
│ Replaces: {{system.user_name}}, {{parent.text}}, etc.  │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ STEP 6: Evaluate condition                             │
│ Method: evaluateSectionCondition()                      │
│ Uses: Fully interpolated data                          │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│ STEP 7: Process children recursively                   │
│ Passes: Merged data as parent data to children         │
└─────────────────────────────────────────────────────────┘
```

---

## 📄 Preview vs Published Pages

### **Published Pages**
- **Data Source**: `page_versions` table (versioned, cached)
- **Purpose**: Production content served to end users
- **Performance**: Optimized with caching
- **Updates**: Only when explicitly published

### **Preview Pages**
- **Data Source**: Live database sections (unversioned)
- **Purpose**: Real-time preview for content creators
- **Performance**: Slower due to live queries
- **Updates**: Immediate changes reflected

### **Interpolation Processing**
- **Same Logic**: Both preview and published use identical interpolation pipeline
- **Different Data**: Preview gets fresh data, published gets versioned data
- **Same Variables**: System, globals, and data variables work identically
- **Same Templates**: Templates work the same in both modes

---

## 🎯 Which Fields Get Interpolation

### **Interpolated Fields (Effective & Selective)**

Interpolation **ONLY** runs on specific content fields to be efficient:

| Field | Type | Purpose |
|-------|------|---------|
| `content` | string | Main section content (HTML/text) |
| `condition` | string | Section display condition |
| `data_config` | JSON string | Database query configuration |
| `css_classes` | string | CSS class definitions |

### **NOT Interpolated (Performance Reasons)**
- `section_name` - Static identifier
- `field_type` - Configuration field
- `sort_order` - Layout field
- `is_active` - Status field
- Metadata fields (created_at, updated_at, etc.)

### **Why Selective Interpolation?**
- **Performance**: Avoids unnecessary processing on static fields
- **Security**: Prevents interpolation in configuration fields
- **Efficiency**: Only processes fields that actually contain variables

---

## 📊 Section Processing Order

### **Processing Sequence**

1. **Parent Section First** → Data retrieved and interpolated
2. **Child Sections After** → Inherit parent data + add own data
3. **Depth-First Processing** → Children processed before siblings

### **Data Inheritance Flow**

```
Root Section (Level 1)
├── Gets: system + globals
├── Retrieves: parent scope data
└── Child Section (Level 2)
    ├── Inherits: system + globals + parent data
    ├── Retrieves: its own scope data (e.g., "child")
    └── Grandchild Section (Level 3)
        ├── Inherits: system + globals + parent + child data
        └── Retrieves: its own scope data
```

### **Variable Resolution Order**
1. **Local Scope** (highest priority): Section's own retrieved data
2. **Inherited Scopes**: Parent section data
3. **Global Scope**: globals namespace
4. **System Scope**: system namespace (lowest priority)

---

## 🏗️ Code Architecture

### **Service Responsibilities**

#### **PageService** - Main Orchestrator
**Location:** `src/Service/CMS/Frontend/PageService.php`

**Responsibilities:**
- ✅ **Interpolate data_config** with parent data (Step 1)
- ✅ **Retrieve data** from interpolated data_config (Step 3)
- ✅ **Merge data** from parent and current section (Step 4)
- ✅ **Interpolate content** with all available data (Step 5)
- ✅ **Evaluate conditions** (Step 6)
- ✅ **Process children** recursively (Step 7)

**Key Methods:**
- `processSectionsRecursively()` - Main orchestrator
- `interpolateDataConfigInSection()` - Step 1
- `retrieveSectionData()` - Step 3
- `mergeDataEfficiently()` - Step 4
- `applyOptimizedInterpolationPass()` - Step 5
- `evaluateSectionCondition()` - Step 6

#### **SectionUtilityService** - Data Structure
**Location:** `src/Service/CMS/Common/SectionUtilityService.php`

**Responsibilities:**
- ✅ **Build initial variable structure** (system + globals)
- ✅ **Handle form record data** (special case)
- ✅ **Provide data retrieval utility** (`retrieveData()`)

**Key Methods:**
- `applySectionData()` - Creates initial retrieved_data
- `structureSystemAndGlobalVariables()` - Namespaces variables
- `retrieveData()` - Utility for fetching from data tables

#### **GlobalVariableService** - Centralized Global Variables
**Location:** `src/Service/CMS/GlobalVariableService.php`

**New centralized service:**
- `getGlobalVariableValues(int $languageId)` - Values for interpolation
- `getGlobalVariableNames()` - Names for admin UI

---

## 📚 Variable Namespaces

### **1. System Variables (`system.*`)**
System variables contain context about the current request and user session.

**Access Pattern:** `{{system.variable_name}}`

| Variable | Type | Description | Example |
|----------|------|-------------|---------|
| `system.user_name` | string | Current user's username/email | `stefan@example.com` |
| `system.user_email` | string | Current user's email | `stefan@example.com` |
| `system.user_code` | string | User's validation code | `admin_stef` |
| `system.user_id` | int | User's ID | `9` |
| `system.user_group` | array | User's group names | `["admin", "therapist"]` |
| `system.page_keyword` | string | Current page keyword | `test_test` |
| `system.platform` | string | Platform (web/mobile) | `web` |
| `system.language` | int | Current language ID | `3` |
| `system.last_login` | string | User's last login date | `2025-10-24 10:30:00` |
| `system.current_date` | string | Current date | `2025-10-24` |
| `system.current_datetime` | string | Current date and time | `2025-10-24 12:58:24` |
| `system.current_time` | string | Current time | `12:58` |
| `system.project_name` | string | Project name | `SelfHelp` |

### **2. Global Variables (`globals.*`)**
Global variables are defined in the `sh-global-values` page and are language-specific.

**Access Pattern:** `{{globals.variable_name}}`

**Characteristics:**
- Defined per language
- Managed via admin UI
- Can contain any custom data
- Cached per language

### **3. Data Variables (`scope.*`)**
Data variables are retrieved from database tables based on `data_config` settings.

**Access Pattern:** `{{scope.field_name}}`

The scope name is defined in your `data_config`. Common scopes:
- `parent` - Parent record data
- `test` - Test data
- `user_data` - User-specific data
- Any custom scope name

---

## 🎨 Mustache Templating

The system uses Mustache.php for variable interpolation. Mustache natively supports:

### **Dot Notation (Nested Objects)**
```html
{{system.user_name}}
{{globals.my_var}}
{{parent.text}}
```

### **Sections (Conditionals)**
```html
{{#system.user_name}}
  <p>Welcome, {{system.user_name}}!</p>
{{/system.user_name}}

{{^system.user_name}}
  <p>Please log in</p>
{{/system.user_name}}
```

### **Loops (Arrays)**
```html
<ul>
{{#system.user_group}}
  <li>{{.}}</li>
{{/system.user_group}}
</ul>
```

### **Inverted Sections**
```html
{{^system.user_group}}
  <p>No groups assigned</p>
{{/system.user_group}}
```

---

## 📊 Data Structure Evolution

### **After Step 2 (`applySectionData`)**
```javascript
section['retrieved_data'] = {
  "system": {
    "user_name": "stefan@example.com",
    "user_id": 9,
    "language": 3,
    "current_date": "2025-10-24",
    // ... all system variables
  },
  "globals": {
    "my_var": "english",
    "site_name": "My Site",
    // ... all global variables
  }
}
```

### **After Step 3 (`retrieveSectionData`)**
```javascript
section['retrieved_data'] = {
  "system": {...},      // From Step 2
  "globals": {...},     // From Step 2
  "parent": {           // NEW: Retrieved from data_config
    "record_id": 56,
    "text": "2",
    "user_name": "Stefan Kodzhabashev"
  },
  "test": {             // NEW: Retrieved from data_config
    "triggerType": "finished",
    "text": "2"
  }
}
```

### **After Step 4 (`mergeDataEfficiently`)**
```javascript
// For child sections, parent's retrieved_data is merged with child's
childSectionData = {
  "system": {...},      // Inherited
  "globals": {...},     // Inherited
  "parent": {...},      // Inherited
  "test": {...},        // Inherited
  "child_scope": {...}  // Child's own data
}
```

---

## 🧪 Testing Results

```
✔ Basic variable interpolation
✔ Nested object interpolation
✔ Deep nested object interpolation
✔ Namespaced structure interpolation ← NEW!
✔ Multiple data arrays interpolation
✔ Data array precedence
✔ Missing variable interpolation
✔ Empty content interpolation
✔ Array content interpolation
✔ Complex nested array interpolation
✔ Special characters interpolation
✔ All namespaced variables work ← NEW!
✔ Numeric value interpolation
✔ Boolean value interpolation

OK (14 tests, 20 assertions)
```

---

## 🚀 What You Need To Do

### **1. Update Your Templates ⚠️**
Find and replace in your templates:

```bash
# System variables
{{user_name}}       → {{system.user_name}}
{{user_email}}      → {{system.user_email}}
{{user_code}}       → {{system.user_code}}
{{language}}        → {{system.language}}
{{current_date}}    → {{system.current_date}}

# Global variables
{{my_var}}          → {{globals.my_var}}
# ... all other global variables need globals. prefix
```

### **2. Test Your Pages**
- [ ] View pages with variable interpolation
- [ ] Check different languages for global variables
- [ ] Verify system variables display correctly
- [ ] Test data variables (parent, test, etc.)

### **3. Update Admin UI Variable Selections (if manually set)**
Variable dropdowns will now show:
- `system.user_name` instead of `user_name`
- `globals.my_var` instead of `my_var`

---

## 📞 Need Help?

- **Quick Reference:** `docs/developer/VARIABLE-REFERENCE.md`
- **Full Guide:** `docs/developer/variable-namespacing-guide.md`
- **Tests:** `tests/Service/Core/InterpolationServiceTest.php`

---

## 🎉 Benefits

### **1. No Variable Name Collisions**
```html
<!-- Clear which 'text' you're referring to -->
{{parent.text}}
{{test.text}}
{{globals.text}}
```

### **2. Better Organization**
```javascript
// Everything is clearly grouped
{
  "system": {...},    // System/context variables
  "globals": {...},   // Global variables
  "parent": {...},    // Data from parent scope
  "test": {...}       // Data from test scope
}
```

### **3. Easier Debugging**
```html
<!-- View entire namespace -->
<pre>System: {{system}}</pre>
<pre>Globals: {{globals}}</pre>
```

### **4. Better Performance**
- No duplicate variables in memory
- Mustache traverses nested objects efficiently
- Cleaner data structure

### **5. Type Safety**
- IDEs can provide better autocomplete
- Clear variable scope
- Easier to catch typos

---

## ❓ Frequently Asked Questions

### **Q: Can Mustache handle nested objects?**
**A:** Yes! Mustache natively supports dot notation like `{{system.user_name}}`. We tested this thoroughly.

### **Q: Will this affect performance?**
**A:** No! It's actually more efficient because:
- No duplicate variables in memory
- Better cache hit rates
- Cleaner data structure

### **Q: Do I have to update all my templates?**
**A:** Yes, but it's straightforward:
- System variables: Add `system.` prefix
- Global variables: Add `globals.` prefix
- Data variables: Already namespaced (parent, test, etc.)

### **Q: What about existing pages?**
**A:** They'll need template updates. Variables without proper prefixes won't be replaced.

---

**Implementation completed successfully! 🎊**
