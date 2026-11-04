# Definition of Done — wpmodules refactor (parity with wp-fasty)

An item is done when its check runs in CI (or the stated script) and passes. Measured on **2026-11-04** on `main`
(MSK). ✅ green, 🟡 partly in place, ❌ not in place yet. Findings: [audit-2026-11-04.md](audit-2026-11-04.md).

## Status table

| Item | Check | Measured | Status |
|---|---|---|---|
| Core has no WordPress | Deptrac `Core: ~` + CI grep of WP calls in `moduly/lib/core/src` | 0 violations | ✅ |
| Hexagonal layers | `composer deptrac` (Core ← Support ← Host; per-module rules; `ModuleDomain` → Core only) | 0 violations, 401 allowed | ✅ |
| Module domain split (ports/DTO) | cart: `Domain\LineCodec`, `ShippingPolicy`, `CartLine`/`CartSummary` (view/v1 `toArray()`) | cart done; other modules: see "Left" | 🟡 |
| PHPStan max, no baseline | `composer phpstan` (core included) | 0 errors, no baseline, 2 targeted ignores | ✅ |
| PHPCS Symfony + WordPress.Security, PHP-CS-Fixer @Symfony | `composer cs` | 0 | ✅ |
| strict_types, camelCase, `*Exception` | PHPCS (camelCase sniff from wp-fasty), core exceptions | all files | ✅ |
| PHPUnit | `composer test` | 116 tests, 445 assertions | ✅ |
| Golden snapshots | `tests/snapshots/` (slot view data of every module, cart summary, 503 page HTML) | 7 snapshots, 0 differ | ✅ |
| Infection MSI ≥ 95 % | `composer infection`, CI job `mutation` (pcov) | 265 mutants, 257 killed, covered MSI 96 % | ✅ |
| N+1 | `Reviews::verifiedBuyer` primes the meta cache (`update_meta_cache`) | fixed; no query-count guard in CI yet | 🟡 |
| PHP 8.5 primary + 8.2 floor, CVE floor | CI matrix 8.2 / 8.5, step "PHP security floor" (8.5 ≥ 8.5.12, 8.2 ≥ 8.2.35) | green | ✅ |
| opcache | CI WordPress job: `php -S` with opcache on PHP 8.5 (off on 8.2, chaosbun#120) | green | ✅ |
| WordPress ≥ 7.1.4 | CI downloads `WP_VERSION=7.1.4` and fails below it (CVE-2026-119977) | 7.1.4 | ✅ |
| `composer audit` | CI, `composer.lock` committed (platform 8.2.35) | 0 advisories | ✅ |
| Deprecated aliases for renamed hooks | `Support\Hooks` (`apply_filters_deprecated` / `do_action_deprecated`), both admin-post names registered, CI checks both | `latte_shop_*` → `moduly_*` | ✅ |
| Signed modules, Plugin Check | CI build (ed25519 verify), Plugin Check 0 errors | green | ✅ |
| License | `GPL-2.0-or-later` (composer.json, plugin header, every module.json) | — | ✅ |

## 1. Architecture

- Core (`moduly/lib/core`, vendored from phpfasty/moduly) is CMS-agnostic: kernel, container, manifest, signatures,
  migrations, ports (`Contract\*`). Deptrac: `Core` may depend on nothing.
- `PhpFasty\Moduly\Wp\Support` — WordPress-free helpers (`Value`) plus thin WordPress shims (`Hooks`, `Db`) that modules
  may use. `Host` — the WordPress adapter (plugin). `HostServices` — services modules receive through the container
  (`wp.option_lock`, `wp.theme_definitions`).
- Every module may use Core contracts, Support and WordPress, and other modules only when declared in `module.json`
  `requires.modules` (orders → cart). `PhpFasty\Module\*\Domain\*` (pure DTOs / policies / codecs) may use Core only.
- View data: each module's slot payload (`['view' => 'moduly/<module>/<view>', 'data' => …]`) is pinned by a snapshot.
  DTOs expose `toArray()` in the field names templates read (ui8kit/contract view/v1).

## 2. Code quality

- PHPStan level **max**, no baseline, `moduly/lib/core/src` included. Untyped WordPress values are narrowed through
  `Support\Value` (`str/int/float/bool/arr/map/list/strings/ints/at`) — never by `(string)` / `(int)` on `mixed`.
  2 targeted `@phpstan-ignore argument.type` (types module: `register_post_type` / `register_taxonomy` with
  theme-defined args).
- PHPCS: Symfony (escapestudios, same exclusions as wp-fasty) + the wp-fasty camelCase variable sniff + strict
  comparison as error + WordPress.Security / PreparedSQL. Superglobals are read as `is_string()` + `wp_unslash()` +
  a WordPress sanitizer; SQL identifiers via `%i`.
- PHP-CS-Fixer @Symfony (`.php-cs-fixer.dist.php`), blocking in CI.

## 3. Behavior

- `tests/snapshots/*` — golden files; `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit` rewrites them, the diff is reviewed.
- CI WordPress job (PHP 8.2 and 8.5, WordPress 7.1.4, MariaDB 11): install plugin + signed modules through the
  validated installer, handlers of new and deprecated names, disable scenario (503 for disabled modules' forms,
  including the deprecated `latte_shop_checkout`), types schema round trip, tampered package rejected, Plugin Check.

## 4. Security and release

- `composer audit --locked` clean. Release zips (`edge`) only after green CI on `main`; modules signed (ed25519).
- Low findings fixed: downloads counted after the full stream, X-Accel-Redirect gets an internal URI, checkout
  input transient read-once / 2 min, safe-mode token via header.

## Left

- Domain/port split of orders, downloads, reviews, licenses, consent-log, types (cart done; the pattern is in
  `modules/cart/src/Domain`).
- Infection: `modules/types/src/Model/Field.php` (field rules) has escaped mutants and is outside the gate.
- Query-count guard in CI for the reviews N+1 fix.
- wp-fasty themes still post `latte_shop_*` actions (served by the deprecated aliases): switch to `cart_action` /
  `checkout_action` from the shop state.
