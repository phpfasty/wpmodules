=== ModulY ===
Contributors: alexy-os
Tags: modules, extensions, security, signatures, shop
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.3.0
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

= Modules 2026-11-06 =
* cart 0.4.1: changing line quantity on the cart form (`qtys[product_id]`) applies again. Value::ints() reindexes to a list and turned the update into Cart::set('0', N), a no-op.

= 0.3.0 =
* BREAKING: the deprecated latte_shop_* names are removed: admin-post latte_shop_cart / latte_shop_checkout (forms must post moduly_cart / moduly_checkout), action latte_shop_order_created, filters latte_shop_currency_symbol / latte_shop_flat_shipping_cents / latte_shop_free_shipping_cents. Support\Hooks (the alias helper) is removed.
* BREAKING (modules): cart 0.4.0 — cart cookie renamed latte_shop_cart → moduly_cart (open carts are emptied once); orders 0.4.0; types 0.5.0 — Model\Field / Model\PostType aliases removed (use Domain\Field / Domain\PostType); downloads 0.3.0 — Downloads\Token alias removed (use Downloads\Domain\Token). consent-log, licenses, reviews 0.2.2 require cart / orders ^0.4.

= Modules 2026-11-04 (2) =
* types 0.4.0: WordPress-free Domain (Field, PostType) with a SanitizerInterface port (PlainSanitizer default, WordPress adapter WpSanitizer at boot). Model\Field / Model\PostType remain as deprecated aliases until 1.0.
* downloads 0.2.2: Downloads\Token remains as a deprecated alias of Downloads\Domain\Token until 1.0.

= Modules 2026-11-04 =
* cart 0.3.1, orders 0.3.1, reviews / licenses / downloads / consent-log / cookie-consent 0.2.1: WordPress-free Domain classes; cart summary primes posts, meta and thumbnails in one go; the review list is loaded on the product page only (no N+1 on listings).

= 0.2.0 =
* Hooks renamed to moduly_* (admin-post moduly_cart / moduly_checkout, action moduly_order_created, filters moduly_shop_currency_symbol / moduly_shop_flat_shipping_cents / moduly_shop_free_shipping_cents); the latte_shop_* names keep working as deprecated aliases.
* Typed value helpers for modules (Support\Value, Support\Hooks, Support\Db); prepared SQL with %i identifiers.
* Downloads are counted after the whole file is sent; X-Accel-Redirect takes an internal URI (filter moduly_downloads_accel_uri).
* Checkout form input is kept read-once for 2 minutes after a validation error.
* Safe-mode token accepted only in the X-Moduly-Safe header (query-string tokens removed).

= 0.1.2 =
* Theme definitions: accept a base theme layer of the active theme (filter wpfasty_theme_dirs, inside the themes root).

= 0.1.1 =
* Disabled modules answer their declared forms and REST routes with 503 "temporarily unavailable".
* Plugin Check fixes; requires WordPress 6.5.

= 0.1.0 =
* First release.
