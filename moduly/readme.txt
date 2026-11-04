=== ModulY ===
Contributors: alexy-os
Tags: modules, extensions, security, signatures, shop
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure module loader: install signed modules (cart, orders, …) from a zip, enable, disable and update them safely.

== Description ==

ModulY installs feature modules for WP FastY sites through a validated pipeline before any module code runs:

* the zip is unpacked into a quarantine folder (no zip-slip, symlinks, hidden files or zip bombs);
* `module.json` is validated against a JSON Schema;
* every file is checked against its sha256 and the publisher's ed25519 signature;
* PHP, WordPress and module dependencies are resolved;
* a static scan rejects dangerous code (eval, shell, raw superglobals);
* only then the module is moved into place.

Modules can be enabled, disabled and rolled back from Plugins → Modules (ModulY), the REST API (`moduly/v1/modules`)
or WP-CLI (`wp moduly …`). A module that causes a fatal error is disabled automatically; a safe mode boots no modules.
While a module is disabled its declared forms and REST routes answer "temporarily unavailable" (HTTP 503) instead of
a blank page. Disabling or removing a module never deletes content: posts, orders and settings stay in the database.

Source code: https://github.com/phpfasty/wpmodules (plugin) and https://github.com/phpfasty/moduly (core).

== Installation ==

1. Upload the plugin and activate it.
2. Go to Plugins → Modules (ModulY) and upload a signed module zip, e.g. `cart.zip` from
   https://github.com/phpfasty/wpmodules/releases

== Frequently Asked Questions ==

= Which modules are trusted? =

Modules signed by the publisher "phpfasty". Site owners can add publisher keys with the `MODULY_TRUSTED_KEYS` constant
and revoke keys with `MODULY_REVOKED_KEYS`.

= Can I turn module uploads off? =

Yes: with `DISALLOW_FILE_MODS` uploads from wp-admin are disabled; WP-CLI still works for deployments.

== Changelog ==

= 0.2.0 =
* Hooks renamed to moduly_* (admin-post moduly_cart / moduly_checkout, action moduly_order_created, filters moduly_shop_currency_symbol / moduly_shop_flat_shipping_cents / moduly_shop_free_shipping_cents); the latte_shop_* names keep working as deprecated aliases.
* Typed value helpers for modules (Support\Value, Support\Hooks, Support\Db); prepared SQL with %i identifiers.
* Downloads are counted after the whole file is sent; X-Accel-Redirect takes an internal URI (filter moduly_downloads_accel_uri).
* Checkout form input is kept read-once for 2 minutes after a validation error.
* Safe-mode token accepted in the X-Moduly-Safe header.

= 0.1.2 =
* Theme definitions: accept a base theme layer of the active theme (filter wpfasty_theme_dirs, inside the themes root).

= 0.1.1 =
* Disabled modules answer their declared forms and REST routes with 503 "temporarily unavailable".
* Plugin Check fixes; requires WordPress 6.5.

= 0.1.0 =
* First release.
