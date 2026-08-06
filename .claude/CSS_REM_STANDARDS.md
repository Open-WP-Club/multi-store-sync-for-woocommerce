# CSS REM Conversion Standards

## Overview

All CSS in this project uses **rem units** instead of pixels for consistent, scalable, and accessible styling. This document defines the conversion standards and best practices for working with CSS in this codebase.

## Base Conversion

**Standard:** `1rem = 16px` (browser default)

## Common Conversions Reference

| Pixels | REM Value  | Common Usage                              |
|--------|------------|-------------------------------------------|
| 1px    | 0.0625rem  | Borders, dividers                         |
| 2px    | 0.125rem   | Small borders, thin spacing               |
| 3px    | 0.1875rem  | Border radius, small gaps                 |
| 4px    | 0.25rem    | Small spacing, border radius              |
| 5px    | 0.3125rem  | Small padding                             |
| 6px    | 0.375rem   | Medium-small spacing                      |
| 8px    | 0.5rem     | Standard small spacing, padding           |
| 10px   | 0.625rem   | Medium spacing, margins                   |
| 11px   | 0.6875rem  | Small font sizes, badges                  |
| 12px   | 0.75rem    | Standard small text, compact elements     |
| 13px   | 0.8125rem  | Small-medium text                         |
| 14px   | 0.875rem   | Standard body text                        |
| 15px   | 0.9375rem  | Medium padding                            |
| 16px   | 1rem       | Base font size, standard spacing          |
| 18px   | 1.125rem   | Large text                                |
| 20px   | 1.25rem    | Large spacing, headings                   |
| 24px   | 1.5rem     | Section spacing, larger elements          |
| 28px   | 1.75rem    | Large values, statistics                  |
| 30px   | 1.875rem   | Large margins                             |
| 38px   | 2.375rem   | Thumbnails, icons                         |
| 40px   | 2.5rem     | Large padding, spacing                    |
| 60px   | 3.75rem    | Extra large spacing, empty states         |
| 140px  | 8.75rem    | Fixed widths for specific elements        |
| 200px  | 12.5rem    | Form field widths, grid minimums          |
| 250px  | 15.625rem  | Form table headers                        |
| 400px  | 25rem      | Max heights, containers                   |
| 500px  | 31.25rem   | Max widths for content                    |
| 600px  | 37.5rem    | Log viewer heights                        |

## Calculation Formula

To convert pixels to rem:
```
rem = pixels / 16
```

Example:
- 24px → 24 / 16 = 1.5rem
- 15px → 15 / 16 = 0.9375rem

## File Locations

### External CSS Files (Converted)

1. **Main Admin Styles**
   - File: `admin/css/admin-styles.css`
   - Contains: Dashboard grids, cards, status badges, progress bars, dark mode
   - All measurements converted to rems

2. **Remote Orders Styles**
   - File: `assets/css/remote-orders.css`
   - Contains: Statistics boxes, order tables, status badges, responsive design
   - All measurements converted to rems

### Inline Styles in PHP Files (Converted)

All inline styles have been converted to use rems:

1. `includes/product-edit.php` - Product sync preview styles
2. `includes/orphan-cleanup.php` - Orphan cleanup results tables
3. `admin/views/deletion-audit.php` - Deletion audit status badges
4. `admin/views/discrepancies.php` - Discrepancy status badges and filters
5. `admin/views/api-usage.php` - HTTP method badges
6. `includes/email-notifications.php` - Email template table styles

## Best Practices

### 1. Always Use Rems for:
- Font sizes
- Padding and margins
- Widths and heights (except percentages)
- Border widths
- Border radius
- Box shadows (blur radius, spread)
- Gaps in flexbox/grid

### 2. Use Percentages for:
- Responsive widths (e.g., `width: 100%`)
- Aspect ratios
- Relative positioning

### 3. Use Unitless Values for:
- Line heights (e.g., `line-height: 1.6`)
- Font weights
- Z-index

### 4. Comment Your Conversions

When adding new CSS files, include a comment at the top:
```css
/**
 * All measurements in rems (1rem = 16px)
 */
```

For inline styles in PHP, add:
```php
// All measurements in rems (1rem = 16px)
```

## Why Use Rems?

### Accessibility
- Respects user browser font size settings
- Users who need larger text get properly scaled layouts
- Critical for WCAG compliance

### Consistency
- All measurements scale together
- Predictable spacing across different screens
- Easier to maintain design system

### Responsiveness
- Better scaling on different devices
- Works well with responsive typography
- Reduces need for media query adjustments

## Examples

### Before (Pixels)
```css
.button {
    padding: 12px 24px;
    font-size: 14px;
    border-radius: 4px;
    margin-bottom: 20px;
}
```

### After (Rems)
```css
.button {
    padding: 0.75rem 1.5rem;
    font-size: 0.875rem;
    border-radius: 0.25rem;
    margin-bottom: 1.25rem;
}
```

### Inline Styles in PHP (Before)
```php
wp_add_inline_style('wp-admin', "
    .preview {
        padding: 15px;
        border: 1px solid #ddd;
        font-size: 12px;
    }
");
```

### Inline Styles in PHP (After)
```php
// All measurements in rems (1rem = 16px)
wp_add_inline_style('wp-admin', "
    .preview {
        padding: 0.9375rem;
        border: 0.0625rem solid #ddd;
        font-size: 0.75rem;
    }
");
```

## Testing

When testing rem conversions:

1. **Browser Default (16px)**
   - Check that all elements look correct at standard size
   - Verify spacing and alignment

2. **Increased Font Size (20px)**
   - Set browser font size to 125%
   - Verify all elements scale proportionally
   - Check for layout breaks

3. **Decreased Font Size (12px)**
   - Set browser font size to 75%
   - Ensure readability is maintained
   - Verify nothing is cut off

## Common Pitfalls to Avoid

1. ❌ Don't mix px and rem for related measurements
   ```css
   /* Bad */
   padding: 1rem 10px;

   /* Good */
   padding: 1rem 0.625rem;
   ```

2. ❌ Don't use px for font sizes
   ```css
   /* Bad */
   font-size: 14px;

   /* Good */
   font-size: 0.875rem;
   ```

3. ❌ Don't forget to convert box-shadow values
   ```css
   /* Bad */
   box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);

   /* Good */
   box-shadow: 0 0.125rem 0.5rem rgba(0, 0, 0, 0.2);
   ```

4. ❌ Don't use rems for media queries (use em or px)
   ```css
   /* Acceptable - use px or em for media queries */
   @media (max-width: 782px) { }
   ```

## Future Development

When adding new styles:

1. Always calculate rem values from design specifications
2. Use the conversion table above for common values
3. Add comments to explain non-obvious conversions
4. Test with different browser font sizes
5. Update this document if you add new common patterns

## Quick Conversion Tool

For quick conversions, use this formula in your head:
- Divide by 16
- Common shortcuts:
  - 8px = 0.5rem (half)
  - 16px = 1rem (base)
  - 32px = 2rem (double)
  - 12px = 0.75rem (three-quarters)

## Conversion Completion Status

✅ **Completed:**
- admin/css/admin-styles.css
- assets/css/remote-orders.css
- includes/product-edit.php (inline styles)
- includes/orphan-cleanup.php (inline styles)
- admin/views/deletion-audit.php (inline styles)
- admin/views/discrepancies.php (inline styles)
- admin/views/api-usage.php (inline styles)
- includes/email-notifications.php (inline styles)

## Maintenance

When updating existing styles:
1. Check if the file has been converted (see status above)
2. Use rem values for all new measurements
3. Maintain consistency with existing rem patterns
4. Update this document if adding new common values

---

**Last Updated:** 2025-12-06
**Conversion Standard:** 1rem = 16px
**Status:** All CSS files converted to rem units
