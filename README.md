# Multi-Store Sync for WooCommerce

One-way product/stock sync from a main WooCommerce store to any number of remote WooCommerce stores. Install only on the main store — remote stores just need WooCommerce and a REST API key.

[![License](https://img.shields.io/badge/license-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![Tests](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce/actions/workflows/functional-tests.yml/badge.svg)](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce/actions)

> Formerly "WooCommerce API Product Sync with Multiple WooCommerce Stores".

## Requirements

WordPress 5.8+, WooCommerce 6.0+, PHP 8.4+, HTTPS.

## Install

```bash
cd wp-content/plugins/
wp plugin activate wc-multi-store-sync
```

Then generate a REST API key on each remote store (**WooCommerce > Settings > Advanced > REST API**, Read/Write, Administrator user if you'll use API image transfer) and add the store under **WooCommerce > Settings > Multi-Store Sync > Stores**. Full walkthrough: [Installation Guide](documentation/INSTALLATION.md).

## What it does

Full product, price, stock, image, category/tag, and variation sync, plus category/attribute/custom-field mapping per store, pricing rules, stock allocation, coupon and shipping class sync, remote order aggregation, scheduled and webhook-triggered sync, a dead letter queue, weekly verification with auto-correction, and WP-CLI commands. See the [User Manual](documentation/USER_MANUAL.md) for the full feature list.

## Docs

- [Installation](documentation/INSTALLATION.md)
- [Configuration](documentation/CONFIGURATION.md)
- [User Manual](documentation/USER_MANUAL.md)
- [FAQ](documentation/FAQ.md)
- [Troubleshooting](documentation/TROUBLESHOOTING.md)
- [Developer Guide](documentation/DEVELOPER.md)
- [Changelog](CHANGELOG.md)

## License

GPLv3 or later.

## Support

[GitHub Issues](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce/issues) · support@gkanev.com · [gkanev.com](https://gkanev.com)
