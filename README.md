# wpmodules — ModulY for WordPress

Monorepo of the WordPress side of [phpfasty/moduly](https://github.com/phpfasty/moduly) (the CMS-agnostic core).

- `moduly/` — the plugin **ModulY** (Author: PHP FastY, AGPL-3.0-or-later). Installs modules from a zip through the
  core's validated pipeline: quarantine folder → JSON Schema (`module.json`) → sha256 of every file + ed25519 publisher
  signature (`SIGNATURE`) → requirements/dependencies → static scan → atomic move. Enable/disable/rollback, audit log
  (`{prefix}moduly_audit`), safe mode (`MODULY_SAFE_MODE` or `?moduly_safe=<MODULY_SAFE_TOKEN>`), fatal attribution
  (a module that fatals is disabled on the next request). UI: Plugins → Modules (ModulY); REST `moduly/v1/modules`;
  WP-CLI `wp moduly list|install|enable|disable|rollback|remove|status`.
  The core is vendored in `moduly/lib/core` (`tools/sync-core.sh <ref>`; `VERSION` records the commit).
- `modules/cart`, `modules/orders` — the shop logic extracted from the latte-shop theme (alexy-os/wp-fasty): signed
  cookie cart `latte_shop_cart`, money in integer cents, private `shop_order` posts with the same `_order_*` meta keys.
  Themes keep presentation only and read `wpfasty_shop_{state,cart,product,checkout,order}` filters.

Trust: modules signed by publisher `phpfasty` are accepted (public key in `moduly/src/Keys.php`); add keys with
`MODULY_TRUSTED_KEYS`, revoke with `MODULY_REVOKED_KEYS`; unsigned modules only with `MODULY_ALLOW_UNSIGNED` on
local/development environments. `DISALLOW_FILE_MODS` blocks uploads (WP-CLI still works).

Data: migrations are forward-only (ADR-002); removing or disabling a module never deletes posts, meta or options.

## Develop

    composer install
    vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/phpcs
    MODULY_SIGNING_KEY=… bash tools/build.sh   # dist/moduly-<v>.zip, dist/modules/<id>-<v>.zip, SHA256SUMS

CI (`.github/workflows/ci.yml`): lint + tests on PHP 8.2–8.4, signed build, then a WordPress + MariaDB job that installs
the plugin zip and the modules with `wp moduly install --enable`, checks the shop hooks and rejects a tampered package.
