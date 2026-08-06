# Translation Files

This directory contains translation files for WooCommerce Multi-Store Sync.

## Text Domain
- **Text Domain**: `wc-multi-store-sync`
- **Domain Path**: `/languages`

## Generating POT File

To generate a POT file for translation:

### Using WP-CLI
```bash
wp i18n make-pot . languages/wc-multi-store-sync.pot
```

### Using Poedit
1. Open Poedit
2. Create new translation from PHP sources
3. Select plugin directory
4. Save as `wc-multi-store-sync.pot`

## Creating Translations

1. Copy `wc-multi-store-sync.pot` to `wc-multi-store-sync-{locale}.po`
2. Translate strings
3. Compile to `.mo` file

### Example for German
```
wc-multi-store-sync-de_DE.po  (translation file)
wc-multi-store-sync-de_DE.mo  (compiled file)
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
