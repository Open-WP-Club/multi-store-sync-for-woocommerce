# Claude Context File Maintenance Guide

## Overview

The `.claude.md` file in the root directory is a critical documentation file that provides context to Claude Code (AI assistant) about the project structure, architecture, and implementation details. This file enables Claude to understand the codebase quickly and provide accurate assistance.

**CRITICAL:** This file MUST be kept up-to-date whenever you make changes to the codebase. An outdated context file will result in Claude providing incorrect suggestions or missing important architectural changes.

## Purpose of .claude.md

The `.claude.md` file serves several important purposes:

1. **Rapid Onboarding** - Allows Claude to understand the project architecture without reading every file
2. **Consistent Guidance** - Ensures Claude follows project conventions and patterns
3. **Feature Documentation** - Documents all features, classes, and their purposes
4. **Architecture Reference** - Describes design patterns, data flow, and system integration
5. **Developer Onboarding** - Helps new developers (human or AI) understand the codebase

## When to Update .claude.md

### 1. Adding New Features or Classes

**When:** You create a new PHP class file in the `includes/` directory

**What to update:**
- Add the file to the **Plugin Structure** section (lines 14-58)
- Add a new subsection under **Core Classes** with:
  - File path
  - Purpose description
  - Key features
  - Important methods
  - Any relevant notes

**Example:**
```markdown
### 19. WC_Multi_Store_New_Feature
**File:** `includes/new-feature.php`
**Purpose:** Brief description of what this class does
**Key Features:**
- Feature 1
- Feature 2
- Feature 3

**Key Methods:**
- `method_name($params)` - Description of what it does
```

### 2. Removing Features or Classes

**When:** You delete a class file or remove functionality

**What to update:**
- Remove the file from the **Plugin Structure** section
- Remove the class documentation section
- Remove any references in other sections (hooks, filters, etc.)
- Update the **Version History Context** section to note the removal

**Important:** Search the entire `.claude.md` file for references to the removed feature to ensure complete cleanup.

### 3. Moving or Renaming Files

**When:** You reorganize files or rename classes

**What to update:**
- Update file paths in the **Plugin Structure** section
- Update the **File:** reference in the class documentation (note: as of December 2025, we no longer use 'class-' prefix in filenames)
- Update any file path references in code examples
- Update the **Key Files to Understand** section if applicable

### 4. Adding Database Tables

**When:** You create a new custom database table

**What to update:**
- Add to the **Database Tables** section (around line 304)
- Include table name with prefix placeholder: `{prefix}_table_name`
- List all column names with brief descriptions
- Update the class documentation that manages this table
- Add to the `activate()` method documentation if table is created on activation

**Example:**
```markdown
5. **`{prefix}_wc_multi_store_new_table`** - Description of table purpose
   - Columns: id, column1, column2, created_at, updated_at
```

### 5. Adding Admin Pages or Views

**When:** You add a new admin page, tab, or view file

**What to update:**
- Add the view file to **Plugin Structure** under `admin/views/`
- Add the page URL to **Admin Pages** section (around line 326)
- If it's a WooCommerce settings page, update the **WooCommerce Settings Integration** section

**Example:**
```markdown
- **New Feature:** `admin.php?page=wc-multi-store-sync&tab=new-feature`
```

### 6. Adding or Changing Hooks and Filters

**When:** You add new actions/filters or change existing ones

**What to update:**
- Add to **Hooks & Filters** section (around line 342)
- Categorize as either:
  - **Actions (Plugin Provides)** - Actions that your plugin fires
  - **Filters (Plugin Provides)** - Filters that your plugin provides
  - **WordPress Hooks (Plugin Uses)** - WP/WC hooks your plugin listens to
- Include code examples showing proper usage

**Example:**
```php
// New feature action
do_action('wc_mss_new_action', $param1, $param2);

// New feature filter
apply_filters('wc_mss_new_filter', $value, $context);
```

### 7. Adding or Changing Settings

**When:** You add new plugin settings or options

**What to update:**
- Add to **Options** section (around line 317)
- Describe the option name and purpose
- Update relevant class documentation (usually `class-settings.php`)
- Add to default settings in the `activate()` documentation if applicable

**Example:**
```markdown
8. `wc_multi_store_sync_new_settings` - Description of settings group
```

### 8. Major Architectural Changes

**When:** You make significant changes to how the plugin works

**What to update:**
- **Core Architecture** section - Update design patterns or architecture overview
- **Key Design Patterns** section - Add or modify patterns being used
- **Data Flow** - Update if you change how data moves through the system
- **Performance Considerations** - Update optimization strategies
- **Development Guidelines** - Add new guidelines for the architectural change

## Update Checklist

Use this checklist when making changes:

- [ ] Updated **Plugin Structure** section with new/modified/removed files
- [ ] Added or updated **Core Classes** documentation
- [ ] Updated **Database Tables** section if tables were added/modified
- [ ] Updated **Options** section if settings were added/changed
- [ ] Updated **Admin Pages** section if pages/views were added
- [ ] Updated **Hooks & Filters** section if hooks were added/changed
- [ ] Updated **API Reference** if external API usage changed
- [ ] Updated **Key Files to Understand** if critical files changed
- [ ] Updated **Development Guidelines** if new patterns introduced
- [ ] Updated **Last Updated** timestamp at the bottom of the file
- [ ] Verified all file paths are correct
- [ ] Removed references to deleted features
- [ ] Added code examples where appropriate

## Best Practices

### 1. Keep Descriptions Concise but Complete

- **Purpose** should be 1-2 sentences
- **Key Features** should be bullet points
- **Key Methods** should include method signature and brief description

### 2. Use Consistent Formatting

- Always use the same heading levels for similar content
- Use code blocks for code examples
- Use bold for emphasis on important points
- Use bullet points for lists

### 3. Include Practical Examples

When documenting hooks and filters, always include usage examples:

```php
// Good example with context
add_filter('wc_mss_sync_product_data', function($data, $product, $sync_type, $store) {
    if ($sync_type === 'full_product') {
        // Modify data
    }
    return $data;
}, 10, 4);
```

### 4. Keep File Paths Accurate

- Use absolute paths from plugin root
- Format: `includes/name.php`
- Don't use `./` or `../` relative paths

### 5. Document Dependencies

When a feature depends on other features or external libraries:

```markdown
**Dependencies:**
- Requires WooCommerce 6.0+
- Uses Action Scheduler (bundled with WooCommerce)
- Depends on api-client.php
```

### 6. Update Version History

When making significant changes, update the **Version History Context** section:

```markdown
- **v2.1.0** - Added new feature X, improved feature Y
```

## Common Mistakes to Avoid

### ❌ Don't Leave Outdated Information

**Bad:** Leaving documentation for a deleted class

**Good:** Remove all references when deleting features

### ❌ Don't Use Vague Descriptions

**Bad:** "Handles product stuff"

**Good:** "Synchronizes product data including variations, images, and categories from main store to remote stores"

### ❌ Don't Forget Cross-References

**Bad:** Adding a new admin page but not updating the Admin Pages section

**Good:** Update all relevant sections (structure, admin pages, class docs)

### ❌ Don't Skip Code Examples

**Bad:** Just listing a hook name without context

**Good:** Show how to use the hook with a practical example

### ❌ Don't Ignore Database Changes

**Bad:** Adding a database column without updating the table documentation

**Good:** Update table documentation with new columns and their purposes

## Verification Steps

After updating `.claude.md`, verify your changes:

1. **Read Through** - Read the updated sections to ensure clarity
2. **Cross-Check** - Verify file paths by actually checking the files exist
3. **Search** - Search for the feature name throughout the document to ensure consistency
4. **Test with Claude** - Ask Claude about the new feature to verify it understands
5. **Review Formatting** - Ensure markdown formatting is correct

## Example Update Workflow

Here's a complete example of adding a new feature:

### Scenario: Adding a new "Product Variant Mapper" class

#### Step 1: Create the File
```php
// includes/class-variant-mapper.php
class WC_Multi_Store_Variant_Mapper {
    // Implementation
}
```

#### Step 2: Update Plugin Structure
Add to the structure tree:
```markdown
│   ├── variant-mapper.php           # Product variant mapping
```

#### Step 3: Add Class Documentation
```markdown
### 19. WC_Multi_Store_Variant_Mapper
**File:** `includes/variant-mapper.php`
**Purpose:** Maps product variants between stores with different attribute structures
**Key Features:**
- Automatic attribute mapping
- Custom mapping rules
- Fallback handling for missing variants

**Key Methods:**
- `map_variant($product, $store)` - Map variant to store structure
- `create_mapping_rule($from, $to)` - Create custom mapping
- `get_mappings($store_id)` - Retrieve all mappings for store
```

#### Step 4: Update Development Guidelines
```markdown
### Adding Custom Variant Mapping

Use the variant mapper to handle stores with different attribute structures:

\`\`\`php
$mapper = new WC_Multi_Store_Variant_Mapper();
$mapper->create_mapping_rule(
    array('store_id' => 2, 'attribute' => 'color', 'term' => 'red'),
    array('attribute' => 'colour', 'term' => 'crimson')
);
\`\`\`
```

#### Step 5: Update Last Updated
```markdown
**Last Updated:** December 5, 2025 - Added variant mapper feature (v2.1.0)
```

## Integration with Development Workflow

### Before Committing Code

1. ✅ Code changes are complete
2. ✅ `.claude.md` is updated with all relevant changes
3. ✅ Changes are tested
4. ✅ Commit message references both code and documentation updates

### Example Commit Message
```
feat: Add product variant mapping feature

- Added WC_Multi_Store_Variant_Mapper class
- Implemented automatic attribute mapping
- Added custom mapping rules interface
- Updated .claude.md with new feature documentation
```

### Code Review Checklist

When reviewing pull requests, verify:

- [ ] Code changes are complete and functional
- [ ] `.claude.md` is updated appropriately
- [ ] All new classes are documented
- [ ] New hooks/filters are documented with examples
- [ ] Database changes are documented
- [ ] Plugin structure section is accurate

## Tools and Automation

### Automated Checks (Future Enhancement)

Consider creating a pre-commit hook to verify:

```bash
#!/bin/bash
# Check if .claude.md was updated when includes/ files changed

if git diff --cached --name-only | grep -q "^includes/"; then
    if ! git diff --cached --name-only | grep -q "^.claude.md$"; then
        echo "Warning: You modified files in includes/ but didn't update .claude.md"
        echo "Please update .claude.md to reflect your changes"
        exit 1
    fi
fi
```

### Quick Reference Script

Create a helper script to show what needs updating:

```bash
#!/bin/bash
# scripts/check-claude-md.sh

echo "Checking .claude.md completeness..."

# Count files in includes/
includes_count=$(find includes -name "*.php" | wc -l)

# Count documented classes in .claude.md
documented_count=$(grep -c "^### [0-9]\+\. WC_Multi_Store_" .claude.md)

echo "Files in includes/: $includes_count"
echo "Documented classes: $documented_count"

if [ $includes_count -ne $documented_count ]; then
    echo "⚠️  Mismatch detected! Some classes may be undocumented."
else
    echo "✅ All classes appear to be documented."
fi
```

## Getting Help

If you're unsure about how to document something:

1. **Look at Examples** - Find similar features in `.claude.md` and follow the same pattern
2. **Ask Claude** - If Claude Code is available, ask it to help update the documentation
3. **Reference This Guide** - Use the examples and checklists in this document
4. **Review Git History** - Look at past updates to `.claude.md` for guidance

## Summary

The `.claude.md` file is a living document that must evolve with your codebase. By keeping it updated, you ensure that:

- AI assistants can provide accurate help
- New developers can onboard quickly
- Project architecture is well-documented
- Code reviews are more effective
- Technical debt is minimized

**Remember:** A few minutes updating documentation saves hours of confusion later!

---

**Document Version:** 1.0
**Last Updated:** December 5, 2025
**Maintainer:** Development Team
