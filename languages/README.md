# Translation Files

This directory contains translation files for WooCommerce Multi-Store Sync.

## Text Domain
- **Text Domain**: `multi-store-sync-for-woocommerce`
- **Domain Path**: `/languages`

## Generating POT File

To generate a POT file for translation:

### Using WP-CLI
```bash
wp i18n make-pot . languages/multi-store-sync-for-woocommerce.pot
```

### Using Poedit
1. Open Poedit
2. Create new translation from PHP sources
3. Select plugin directory
4. Save as `multi-store-sync-for-woocommerce.pot`

## Creating Translations

1. Copy `multi-store-sync-for-woocommerce.pot` to `multi-store-sync-for-woocommerce-{locale}.po`
2. Translate strings
3. Compile to `.mo` file

### Example for German
```
multi-store-sync-for-woocommerce-de_DE.po  (translation file)
multi-store-sync-for-woocommerce-de_DE.mo  (compiled file)
```

## JavaScript translations

Admin scripts use WordPress `wp.i18n` and `wp_set_script_translations()` with the same text domain. Include JavaScript strings when extracting the POT file. After translating the PO files, generate the JSON catalogs used by WordPress:

```bash
wp i18n make-json languages --no-purge
```

## Translation Guidelines

- Maintain context and tone
- Keep placeholders like `%s`, `%d` in the same position
- Test translations in plugin interface
- Check for text overflow in UI

## Contributing Translations

We welcome translation contributions!

1. Fork the repository
2. Create translation files
3. Test thoroughly
4. Submit pull request

## Supported Languages

Currently available translations will be listed here as they're contributed.

- English (en_US) - Default

## Translation Tools

### Recommended
- **Poedit**: https://poedit.net/
- **WP-CLI i18n**: https://developer.wordpress.org/cli/commands/i18n/

### Online Services
- **translate.wordpress.org**: For WordPress.org hosted plugins
- **Transifex**: For collaborative translation
- **Crowdin**: Alternative translation platform

## Resources

- [WordPress Internationalization](https://developer.wordpress.org/apis/handbook/internationalization/)
- [WP-CLI i18n Commands](https://developer.wordpress.org/cli/commands/i18n/)
- [Poedit Tutorial](https://poedit.net/trac/wiki/Doc/Tutorial)
