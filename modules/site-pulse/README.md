# SitePulse (ModulY module `site-pulse`)

Connects a WordPress site to a [SitePulse](https://github.com/fastygo/site-pulse) server (`diagd` 0.1.0):

- **Settings → SitePulse** (`manage_options`): server URL, secret key `sk_live_…`, public key `pk_live_…`, tracker
  on/off, optional tracker/ingest URL, cookie-consent mode, automatic re-analysis.
- **Editor sidebar** (Gutenberg, `PluginSidebar`): up to 5 focus keywords (post meta `_sitepulse_keywords`), *Analyze*
  (optionally debounced while typing), score 0–100, findings grouped fail / warn / pass / not measured, and
  highlighting of `spans` in the editor canvas. *In SitePulse*: score history and internal-link ideas when the studio
  has already imported the post.
- **Tracker**: `sitepulse.js` in `wp_head`, exactly the snippet of `GET /v1/sites/{id}/install`
  (`<script src="<server>/sitepulse.js" data-site="pk_live_…" defer></script>`), or — in consent mode — only after
  analytics consent.

WordPress REST stays enabled: the studio imports posts through `wp-json` (`POST /v1/sites/{id}/import/wp`).

## Security model

- The secret key lives in the option `sitepulse_secret` (not autoloaded, `show_in_rest: false`). The settings form
  shows `sk_live_…1234` only; an empty field keeps the key, *Remove* clears it, an invalid value is rejected.
- The browser talks only to this site's REST proxy (cookie auth + `wp_rest` nonce through `apiFetch`):

  | Route | Permission | Upstream |
  |---|---|---|
  | `POST /wp-json/sitepulse/v1/analyze` `{post_id, html, title, description, keywords}` | `edit_post` of `post_id` | `POST /v1/analyze` |
  | `GET /wp-json/sitepulse/v1/insights/<post_id>` | `edit_post` | `GET /v1/me`, `GET /v1/sites/{site}/articles?q=<slug>` (match `external_id` = post ID), `…/history`, `…/links/suggest` |
  | `GET /wp-json/sitepulse/v1/status` | `manage_options` | `GET /v1/me` (*Test connection*) |

  PHP sends `Authorization: Bearer sk_live_…` with `wp_remote_request` to the configured server only: https, or
  plain http for `localhost` / `127.0.0.1` / `[::1]` / `*.localhost`; no credentials, query or fragment in the URL;
  fixed `/v1/…` paths with validated ids; no redirects; WordPress' unsafe-URL guard for non-loopback hosts
  (filter `sitepulse_reject_unsafe_urls`); timeout from the module setting `timeout_seconds` (default 15).
- Upstream errors become `WP_Error` codes: `sitepulse_auth` (401/403 → 502), `sitepulse_not_found` (404),
  `sitepulse_bad_request` (400/422 → 422), `sitepulse_too_large` (413), `sitepulse_rate_limited` (429, `retry_after`),
  `sitepulse_upstream` (other → 502), `sitepulse_unreachable` (transport → 502), `sitepulse_not_configured` (503).
  Key-like strings in upstream messages are replaced with `[key]`.
- `module.json` declares `http:outbound:127.0.0.1` (local `diagd`); the production SitePulse host is chosen by the
  administrator, which ModulY permissions (`http:outbound:<fixed host>`) cannot express yet.
- While the module is disabled ModulY answers `sitepulse/v1` with 503 and prints no tag; settings and meta stay.

## Cookie consent

Mode **require** (default) prints an inline loader instead of the tag; `sitepulse.js` is neither requested nor able to
write `localStorage` before one of these signals:

- `window.SitePulseConsent = true` (before or after the loader) or
  `document.dispatchEvent(new CustomEvent('sitepulse:consent'))` (`detail.analytics === false` is ignored);
- ModulY **cookie-consent** / wp-fasty themes: cookie `wpfasty_cookie_consent=all` or the `wpfasty:consent` event with
  `detail.analytics === true`;
- WP Consent API: `wp_has_consent('statistics')` or `wp_listen_for_consent_change` with `statistics: 'allow'`;
- Cookiebot: `Cookiebot.consent.statistics` or `CookiebotOnAccept`.

Mode **off** prints the snippet as is (sites without a consent requirement). Filter `sitepulse_track_request`
(bool) skips the tag for a request. Add the site origin to the site's allowed origins in the SitePulse studio,
otherwise `POST /v1/ingest` answers 403.

**wp-fasty**: no theme change is needed when the ModulY cookie-consent module renders the banner (its `wpfasty:consent`
event and cookie are honoured). A theme with its own banner only has to dispatch `sitepulse:consent` (or set
`window.SitePulseConsent = true`) when analytics is accepted.

## Highlighting

`diagd` numbers blocks while parsing `<title>`, `<meta name="description">` and the body in document order
(`title-1`, `meta-2`, `p-3`, `h2-4`, `li-5`, `td-6`, `alt-7`, …) and returns spans in UTF-16 code units of the
whitespace-collapsed block text. The sidebar rebuilds that numbering from the serialized blocks it sent
(`wp.blocks.getBlockContent`), maps every id to its top-level block, then selects and scrolls to it and marks the range
with the CSS Custom Highlight API — content is never modified. When the text changed since the analysis it falls back
to selecting the block. `window.SitePulseEditor.buildModel()` exposes the mapping for tests.

## Develop

No build step: `assets/*.js` use the core `wp-*` script globals and are printed inline (ModulY modules may live
outside the web root). PHP: `modules/site-pulse/src/Domain` is WordPress-free (DTOs, `ClientInterface` port,
`SitePulseService`, `ErrorMapper`); the rest are WordPress adapters. Tests: `tests/SitePulse*Test.php`.
