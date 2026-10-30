# Customer Lens

WordPress plugin that shows a WooCommerce customer's previous orders and risk signals on the order screen: refunds, cancellations, failed payments and refused cash-on-delivery parcels, matched by email, phone and address so guest orders count too.

Signals are counts and dates, never a score. The plugin never cancels or changes an order.

The WordPress.org readme is [readme.txt](readme.txt).

## How it works

- **Tables** (`src/Install/Schema.php`, created with dbDelta, versioned by `mwtcl_schema_version`): customers, identities (email, phone, address; each stored once with a SHA-256 hash), the links between them and orders, events, and signals. Nothing is written to WooCommerce orders or their meta.
- **Identity** (`src/Identity/`): `EmailNormalizer` (Gmail dots and +tags ignored), `PhoneNormalizer` (E.164 with libphonenumber, the order's billing country as the default region), `AddressNormalizer` (`street|number|city|postcode`, without diacritics). `Resolver` attaches an order to a customer by account, email, phone, then address; conflicts go to the stronger match and record a merge proposal. A phone or email used with three or more names in 12 months is marked shared and stops matching. An address alone never links. `Merger` merges two customers and undoes it within 30 days.
- **Order sync** (`src/Orders/OrderSync.php`): links orders on save (new, billing changed, submitted from draft), keeps a status/total/date snapshot in `mwtcl_order_customers`, and records refunds (one per refund object), failed payments, cancellations, trash and deletion. Reads orders through the WooCommerce CRUD API only.
- **Signals** (`src/Signals/`): `Signals::rebuild()` recomputes one customer's row from the snapshot and the events, in two queries. `Badges` turns counts into labels and levels from the store's thresholds.
- **Screens** (`src/Admin/`, `templates/panel.php`, `assets/`): the order-screen panel is server-rendered and refreshed through REST routes under `customer-lens/v1` (`src/Rest/Routes.php`); vanilla JS, no build step. The orders list column costs one query per page (`OrderLinks::summaries()`), and its filter joins the plugin's tables. Settings live under the Customer Lens menu.
- **Import** (`src/Import/Importer.php`): indexes existing orders newest first, 200 per Action Scheduler batch, with an inclusive date cursor; every step is idempotent, so batches can repeat or stop halfway.
- **Nightly** (`src/Maintenance/Maintenance.php`): expires flags, deletes events past the retention setting (notes stay), re-checks orders changed in the last day, removes customers left with no orders.
- **Privacy** (`src/Privacy/Privacy.php`): personal data exporter and eraser, and the privacy policy text.
- **WP-CLI** (`src/Cli/Command.php`): `wp customer-lens import|status|rebuild|erase`.

### Filters

| Filter | Default | |
|---|---|---|
| `mwtcl_capability` | `manage_woocommerce` | Who sees the panel and can act on customers. |
| `mwtcl_address_threshold` | `70` | Confidence an address needs to link an order on its own. Addresses score 60, so by default they never do. |

## Requirements

- PHP 8.1 or later and Composer 2
- A MySQL or MariaDB server for the PHP tests
- WordPress 6.4 or later and WooCommerce 8.2 or later on the sites that run the plugin

## Setup

```bash
composer install
```

`composer install` also runs [Strauss](https://github.com/BrianHenryIE/strauss), which copies libphonenumber into `vendor-prefixed/` under the `CustomerLens\Vendor\` namespace, so it cannot clash with another plugin's copy. Always use the prefixed names; `composer check:names` fails on an unprefixed `libphonenumber\`.

The integration tests run against a real WooCommerce, downloaded into `tests/php/plugins/`:

```bash
bin/install-woocommerce.sh
```

Pass a version (`bin/install-woocommerce.sh 8.2.0`) to test an older one, or point `WC_DIR` at a WooCommerce folder.

The PHP tests need their own empty database. The test suite deletes everything in it on every run, so never point it at a real site.

```bash
mysql -u root -e "CREATE DATABASE mwtcl_tests"
```

Connection settings come from environment variables, or from `tests/php/wp-tests-config.local.php` (not committed):

| Variable | Default |
|---|---|
| `WP_TESTS_DB_NAME` | `mwtcl_tests` |
| `WP_TESTS_DB_USER` | `root` |
| `WP_TESTS_DB_PASSWORD` | (empty) |
| `WP_TESTS_DB_HOST` | `127.0.0.1` |
| `MWTCL_TESTS_HPOS` | `1`; `0` runs on legacy order storage |
| `WP_MULTISITE` | (empty); `1` runs as multisite |

## Checks

```bash
composer lint          # WordPress Coding Standards
composer check:names   # identifiers only from class constants; prefixed libraries only
composer analyse       # PHPStan
composer test          # PHPUnit
composer ci            # all of the above
```

CI runs them on PHP 8.1 with WordPress 6.4 and WooCommerce 8.2, and on PHP 8.4 with the latest of both, on HPOS, legacy storage and multisite, and runs Plugin Check on the release build.

## Demo store, screenshots and assets

`bin/demo-data.php` fills a store with made-up customers and orders. Use a separate, throwaway site:

```bash
CUSTOMER_LENS_DEMO=1 wp eval-file bin/demo-data.php
```

Then, with Node 20 and `npm install`:

```bash
WP_BASE_URL=https://customer-lens-demo.test WP_CLI="wp --path=/path/to/demo" npm run screenshots
npm run assets
```

`screenshots` writes `.wordpress-org/screenshot-*.png` (captions in readme.txt). `assets` renders the icon and banners from `.wordpress-org/icon.svg` and `.wordpress-org/src/banner.html`; it needs a connection to Google Fonts.

## Release

```bash
bin/build-zip.sh
```

builds `dist/customer-lens/` and `dist/customer-lens.zip`: the plugin without development files, with `vendor-prefixed/` and a classmap autoloader. Run Plugin Check on the folder before uploading.

Translations: regenerate `languages/customer-lens.pot` with `wp i18n make-pot . languages/customer-lens.pot --exclude=vendor,vendor-prefixed,tests,dist,docs,node_modules,bin`. Romanian ships in `languages/` and loads only when WordPress has no language pack; once the plugin is on WordPress.org, import `customer-lens-ro_RO.po` into translate.wordpress.org.

## License

GPL-3.0-or-later
