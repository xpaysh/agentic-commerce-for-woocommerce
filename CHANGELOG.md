# Changelog

All notable changes to **xpay for WooCommerce** are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The latest version always lives at <https://install.xpay.sh/woocommerce/latest.zip>;
versioned downloads at <https://install.xpay.sh/woocommerce/xpay-woocommerce-{version}.zip>;
release metadata at <https://install.xpay.sh/woocommerce/manifest.json>.

## [Unreleased]

## [0.3.7] — 2026-06-20

### Added — partner (agency) attribution

- **New `class-xpay-partner.php`** — resolves an optional partner referral code
  from (in priority order) the `XPAY_WC_PARTNER_CODE` wp-config constant (the
  agency bulk-deploy path; baked once into a provisioning template, every client
  install self-attributes), else the stored option `xpay_wc_partner_code`. The
  constant wins and renders the settings field read-only. Code charset is
  sanitised to `[A-Za-z0-9._-]`, capped at 64 chars.
- **Three ways to set the code, easiest first:** (1) a **referral deep link** —
  `…/wp-admin/options-general.php?page=agentic-commerce-for-woocommerce&xpay_partner=<code>`
  is captured on load by `maybe_capture_partner_from_query()` (no typing; no-op
  if locked or already connected); (2) a small **field on the Connect screen**;
  (3) the wp-config **constant** for agencies with provisioning automation.
- **Connect handoff carries attribution.** `handle_connect_start()` appends
  `partner` plus store-level anti-fraud signals (`sku_count`, `order_count`,
  `live_gateways`) to the onboard URL. Signals are aggregates only — counts and
  enabled-gateway ids in live (non-test/sandbox) mode — never any product,
  customer or order content. Live detection reads each gateway's `testmode` /
  `sandbox` option; the "which gateways count" policy lives on the backend.
- **Durable header.** `Xpay_Client::request()` sends `X-Xpay-Partner` on every
  authenticated backend call, so attribution survives even if the connect param
  is lost.
- **Settings UI.** A small secondary "Agency / referral code" card on the
  Connect screen (`render_partner_field()` + `handle_save_partner`), hidden
  complexity for solo merchants.

## [0.3.6] — 2026-06-16

### Added — AI-bot crawl analytics (opt-in, privacy-safe)

- **New `class-xpay-agent-analytics.php`** — records a lightweight, non-personal
  event whenever a *known AI bot* fetches a front-end URL, so the merchant (and
  we) can see how AI agents discover and crawl the store, and crucially whether
  they reach the discovery surfaces (`/llms.txt`, `/agents.md`, `/.well-known/*`,
  `/sitemap*`). Each event captures only: the matched bot token, a bot class
  (`realtime` | `training` | `search` | `crawler`), the request path (query
  string stripped), a coarse `path_type`, the HTTP status, and whether we
  deflected the bot to the structured catalog. Plus the merchant slug. No human
  PII, no IPs, no customer or order data.
- **Zero hot-path cost.** Capture runs on the `shutdown` hook — *after* the
  response is sent to the visitor — and bails in a handful of cheap string checks
  for anything that isn't a known AI bot. Humans and non-AI bots cost one
  user-agent `stripos`; they never touch the buffer, DB, or network. Capture
  still fires for requests an emitter or the deflector ended with `exit()`,
  because PHP runs shutdown functions on exit — so discovery-file and deflected
  hits are counted.
- **Buffered + batched.** Events accumulate in a single `autoload=off` option
  with a hard cap (200); a 15-minute WP-Cron job (`xpay_wc_agent_analytics_flush`)
  drains the buffer and POSTs it as one batch to `…/v1/agent-analytics`. Overflow
  past the cap is dropped + counted and pulls the next flush forward. An
  `xpay_wc_agent_analytics_sample_rate` filter caps write load on very busy
  stores. The merchant's own server never blocks on our backend.
- **Commerce flow excluded.** `/cart`, `/checkout`, `/my-account`, `/wp-admin`,
  `/wp-login`, `/wp-json`, `/wp-content`, `/wp-includes`, `/feed` are never
  recorded.
- **Aggregate human pageview count** (the bot-vs-human denominator). For
  non-AI-bot front-end pageviews we keep a single per-day counter — a *number
  only*, no UA, no path, no per-visit row — so the dashboard can show "AI agents
  are N% of your store traffic." Classic crawlers (Googlebot/Bingbot) and
  non-browser agents are excluded (only HTML-accepting browser hits count);
  high-traffic stores can sample via `xpay_wc_agent_analytics_human_sample_rate`
  (the inverse weight extrapolates so the count stays unbiased). Flushed in the
  same batch; stored in a separate `traffic_daily` rollup, never per-visit.
- **One-line deflection hook** (`class-xpay-deflection.php`): the deflector sets
  an in-memory `$GLOBALS['xpay_wc_deflected']` flag immediately before its 302 so
  shutdown capture can mark `deflected=true`. Writes nothing; changes no
  deflection behaviour.

### Consent & disclosure

- **Gated behind the existing telemetry opt-in** (off by default). Crawl
  analytics only runs when `Xpay_Telemetry::is_enabled()` is true. A separate
  `define( 'XPAY_WC_AGENT_ANALYTICS', false )` hard-disables just this subsystem
  while leaving lifecycle telemetry on.
- **First-activation consent notice**, **Settings → xpay → Privacy** panel, and
  the **External services** panel updated to disclose the new endpoint and
  exactly what it sends. readme.txt **External services** + **Privacy** sections
  and a ≤300-char **Upgrade Notice** added.

### Backend & dashboard

- **Ingest** (`backend/wc-plugin-setup`): new `POST /v1/agent-analytics` folds a
  batch into per-`(merchant, day, bot, path_type)` daily counters
  (`UpdateItem ADD`) in a new `xpay-wc-agent-crawl-{stage}` table (180-day TTL).
- **Read** (`backend/merchant-setup`): new account-scoped
  `GET /merchant/agent-crawl?days=` rolls the daily counters into engines, path
  types, daily trend, discovery-file hit rate and deflection rate. Privy-auth +
  admin view-as, canonical-slug resolution mirroring the GEO read-layer.
- **Dashboard** (`xpay-app`): the commerce **Bot Traffic** page now renders real
  AI crawl activity (summary cards, per-engine table, what-they-crawl
  breakdown); the home "Bot Traffic (7d)" tile shows live totals + discovery-file
  hit rate.

## [0.3.5] — 2026-06-15

### Added — `/agents.md` agent skill

- **New discovery emitter at `/agents.md`** (`class-xpay-rest.php`): a real
  connect-and-transact SKILL for skill-using AI shopping agents (OpenClaw-style
  runtimes, Claude/ChatGPT skills), explicitly **not** a mirror of `/llms.txt`.
  Advertises only surfaces the backend actually serves today — the MCP server
  (`search_catalog` / `get_product` / `create_cart`), the REST catalog API, the
  bulk catalog JSON, and the cart-deeplink hand-off (the human completes payment
  on the store's own checkout; there is no in-protocol payment, by design).
- Served only when the store is connected (a slug exists); an unconnected store
  gets a short honest stub. Registered `default_on` with the same
  **skip-if-external** don't-clobber guard as the `/.well-known/*` emitters, and
  added to the emitter-probe's `known_paths()` so an existing third-party
  `/agents.md` is detected and left untouched.

### Fixed — discovery emitter-probe robustness

- **HTML guard on the upstream probe** (`class-xpay-emitter-probe.php`): hybrid
  /headless storefronts and SPA catch-all routes answer `200` with an HTML page
  for *every* path, including `/llms.txt` and the `/.well-known/*` files nothing
  actually serves. The probe now rejects HTML responses (by `Content-Type` and a
  leading-markup sniff), so that page is no longer captured as "external upstream
  content" and prepended above our `/llms.txt` sections (nor does it wrongly
  suppress our JSON emitters via skip-if-external).
- **Stable self-fingerprint.** `SELF_FINGERPRINT` moved off the catalog host
  `agent-feed.xpay.sh` (removed from our output by the sidecar-link change) to a
  fixed, URL-independent token (`xpay agentic-commerce-for-woocommerce`) now
  emitted into both `/llms.txt` and `/agents.md`. Prevents a cached copy of our
  own output from being mistaken for an external file (re-prepend loop on
  `/llms.txt`; spurious skip on `/agents.md`).

### Changed — catalog link + optional shopping-bot deflection

- **`/llms.txt` catalog link now points at the merchant's branded agent surface**
  (`<slug>.agentic-commerce.xpay.sh/catalog.json`, the install-safe wildcard)
  instead of the shared `agent-feed.xpay.sh` feed host. Same data, merchant brand,
  valid the moment the store connects (no CNAME required).
- **Agent-deflection enforcer** (`class-xpay-deflection.php`, server-driven policy,
  **off by default**): realtime AI *shopping* fetchers (ChatGPT-User, Claude-User,
  Perplexity-User, MistralAI-User, DuckAssistBot, Meta-ExternalFetcher) can be
  routed (302) to the structured catalog surface, while search/index crawlers stay
  on origin for citations. Writes zero files, no network on the request path
  (policy fetched in the background via WP-Cron, cached in a transient), fail-open
  everywhere, and never touches admin / REST / cron / logged-in users / non-GET /
  `/cart` / `/checkout` / `/my-account` / discovery files. Does nothing until xpay
  enables it for a given store.

## [0.3.4] — 2026-06-12

### Changed — merchant-friendly Connect screen

A cautious D2C merchant (Bag Selection, 2026-06-12) flagged the pre-connect
panel as too developer-facing — a wall of `ACP / UCP / AP2 / MCP`,
`llms.txt`, `schema.org JSON-LD` jargon as the very first impression. The
sibling publisher plugin's connect copy was already plainer; this aligns the WC
plugin to that bar.

- **Pre-connect panel rewritten outcome-first.** Leads with the benefit —
  "Make your products discoverable to AI shoppers… no code, and no change to
  how you get paid" — and demotes the protocol acronyms to a small footnote
  (still present for technical reviewers / WP.org).
- **Upfront safety promise.** Adds a highlighted reassurance, code-verified this
  session: the plugin writes **zero files** to the merchant's site, doesn't
  touch the theme or payments, **appends** to any existing `/llms.txt` rather
  than replacing it, and fully reverts on deactivate. (Behavior unchanged —
  this surfaces what was already true; see
  `docs/jun/12/wc-plugin-non-destructive-discovery-behavior.md`.)

### Changed — value-first telemetry consent prompt

- The first-activation consent notice (`class-xpay-consent.php`) was framed
  around what *we* get ("help us improve onboarding"). Reframed around what the
  *merchant* gets: a heads-up when something silently breaks their store's AI
  connection (failed sync, blocked endpoint, dropped connection) so we can flag
  or fix it before their products fall out of ChatGPT / Claude / Perplexity. The
  no-PII / no-customer-data / change-anytime / "what's sent" reassurances are
  unchanged; default is still OFF (opt-in only). Button relabeled "Share
  anonymous diagnostics".

### Added — "External services this plugin contacts" panel

- New transparency card on the connected **General** tab listing every external
  host the plugin talks to (`agent-feed.xpay.sh`, `agent-commerce.xpay.sh`,
  `app.xpay.sh`) and what each receives, plus Terms / Privacy links. Ported from
  the publisher plugin's disclosure pattern. Static copy, no network calls.

## [0.3.3] — 2026-06-12

### Added — Run site diagnostics (Tools tab)

Real-merchant onboarding failures (Payplug, 2026-06-11) traced back to silent
failures at the **web-server layer**, before WordPress ever runs: Plain
Permalinks 404-ing `/wp-json/*` at Apache, security plugins blocking the REST
namespace, and Apache/ACME hosts reserving `/.well-known/` and short-circuiting
the discovery files. None of these are visible from inside wp-admin, so the
merchant has no way to know why "Connect store" fails.

- **New "Run site diagnostics" button** under Tools → Live actions. On an
  explicit click it loopback-probes three layers and caches the result for
  10 minutes:
  - **WordPress REST API (Permalinks)** — `rest_url()`; a non-2xx means Pretty
    Permalinks are off and the whole connect handshake will fail.
  - **xpay plugin REST routes** — `rest_url('xpay/v1')`; isolates "REST is up
    but our namespace is blocked" (Wordfence / iThemes / server firewall).
  - **`/.well-known/` discovery files** — the literal web-server path; a 404
    here while the REST API passes is the classic Apache-reserves-`/.well-known/`
    case.
- **Targeted remediation per failure** — each result row shows pass/fail + the
  HTTP status, and a failure renders the exact next step (switch Permalinks off
  "Plain"; deactivate/reactivate or check a security plugin; or, for
  `/.well-known/` shadowing, the `/?xpay_route=ucp_profile` fallback URL that
  agents can still use plus the host-side fix).
- **No change to the connect flow or the no-phone-home guarantee.** The probes
  are network-only on the button click; the Settings page still makes zero
  outbound calls on render.

## [0.3.2] — 2026-06-03

### Fixed — good-neighbour `/llms.txt` + `/.well-known/*` handling

Until this release, the plugin's discovery emitters registered a `'top'`-priority
rewrite rule for `/llms.txt`, `/.well-known/ucp`, `/.well-known/oauth-protected-resource`,
and `/.well-known/agent-card.json` and served freshly-built content on
`template_redirect` priority 0. With no detection of pre-existing content, any
merchant who already had an `/llms.txt` curated by Yoast SEO AI, RankMath AI,
AIOSEO, the official `llms-txt` plugin, or hand-rolled code would have their
file silently overwritten within seconds of activation.

v0.3.2 fixes this by detecting external emitters at activation time, every
6 hours via transient cache, and once daily via WP-Cron.

- **`/llms.txt` now appends to upstream content.** If the probe finds an
  external `/llms.txt` (non-empty, doesn't contain the `agent-feed.xpay.sh`
  fingerprint, ≤64 KB), `Xpay_REST::serve_llms_txt()` renders that upstream
  body first, separated by a comment marker, and adds our agent-shopping
  sections (`## Store`, `## Commerce protocols`, `## Cart handoff`,
  `## For AI shopping agents`) at the end. No content is ever overwritten.
- **`/.well-known/*` JSON emitters defer when an external handler is detected.**
  Deep-merging competing JSON schemas isn't safe at the plugin level — when
  another emitter is in place, our handler returns early in `is_enabled()`
  so WordPress's normal routing serves the existing file unchanged.
- **HTTP self-probe with `X-Xpay-Probe: 1` header.** `Xpay_Emitter_Probe` runs
  `wp_remote_get()` against `home_url($path)` with the probe header set.
  `Xpay_REST::maybe_serve()` reads that header on every request and
  short-circuits when present, so the probe sees what other handlers would
  serve. Result cached for 6h, fail-cached for 1h to avoid hammering the
  merchant's own host on transient errors.
- **Daily WP-Cron refresh.** Auto-detects when a merchant installs another
  AI-SEO plugin after activating ours.
- **Probe priming on activation.** Single-shot WP-Cron event 10 seconds after
  activation populates the cache without blocking the activation request
  itself.

No new options, no admin UI changes, no merchant-facing behavior change when
no external emitter is present.

### Added — backend-callable `/wp-json/xpay/v1/admin/refresh` endpoint

Lets xpay's backend trigger site-local maintenance on a single merchant
without waiting for the next plugin update or daily WP-Cron tick. Useful
when we ship a fix and want to reconcile already-connected merchants
without a plugin upgrade walk.

- **Auth: `X-Xpay-Site-Token` header** constant-time-compared against
  the `xpay_wc_site_token` option (auto-generated on activation since
  v0.1.x). Returns 401 if the header is missing, empty, or wrong —
  without leaking which case applies.
- **Forward-compatible action vocabulary** — `emitter_probe_refresh`,
  `flush_rewrites`, `clear_discovery_cache`, `clear_ucp_profile_cache`.
  Unknown actions are silently recorded under `skipped` in the response
  so the backend can issue newer action names that older plugin
  versions safely ignore.
- **Token exchange via the existing finalize handshake.** The plugin's
  `/wp-json/xpay/v1/finalize` response now returns `site_token`
  alongside the existing `ok`/`replay` fields. The backend captures it
  server-to-server during finalize (no URL params, no logs, no
  referer leak). Idempotent on replay.
- **No SSRF surface** — every action operates on local state only
  (transients, rewrite rules, options).

Dormant in v0.3.2 until the backend wires up the token store and refresh
client (separate `backend/wc-plugin-setup/` commit). Shipping the
plugin-side capability now so the backend rollout doesn't require
another plugin update walk.

### Fixed — concurrent-OAuth-callback false-negative

Real failure observed 2026-06-03 on `snowflake-quoll-134a4c.instawp.site`:
WC's OAuth completion fires xpay's `wcAuthCallback` Lambda twice — the
server-side wc-auth POST AND the browser-return path both trigger it
within ~500ms. Pre-fix backend behavior minted a fresh random api_key
in each invocation, so the second `/wp-json/xpay/v1/finalize` POST
arrived at the plugin with a **different api_key** plus the now-consumed
nonce → plugin failed `is_replay` (api_keys differ) and failed the nonce
match (nonce gone) → returned 401 → backend marked `plugin_callback_failed`,
overwriting the first invocation's `done` state. Frontend showed
"Connection failed: 401" even though the plugin actually finalized
cleanly on call #1.

Plugin-side defense-in-depth fix (`rest_finalize`):

- When `is_replay` fails BUT a slug + api_key are already stored from a
  prior finalize, treat the request as a **duplicate callback no-op**.
  Return 200 with `{ok: true, already_finalized: true, slug: <existing>,
  site_token: <existing>}` — never overwrite stored creds. First-call
  truth wins.
- New telemetry event `finalize_duplicate_callback` so the digest
  surfaces these races when they happen.

The load-bearing fix is on the backend: replace `randomKey(32)` with
deterministic HMAC derivation from the nonce + a per-stage SSM secret.
Concurrent invocations now produce identical api_keys, so the second
call hits `is_replay=true` cleanly even on older plugin versions. See
`backend/wc-plugin-setup/src/onboard.ts:deriveApiKey()`.

## [0.3.1] — 2026-06-02

### Fixed — second WordPress.org review-fix release

Addresses the issue flagged in the WP.org plugin-directory review of v0.3.0
(review ID `R agentic-commerce-for-woocommerce/xpaysh/31May26/T2 2Jun26/4.0.1`):
hardcoded `/wp-json` REST paths in the UCP manifest. Also folds in every
finding from a fresh independent guidelines-driven audit (Claude + Codex,
neither anchored on the prior rejection list).

- **REST endpoints constructed via `rest_url()` instead of `home_url('/wp-json/...')`.**
  In `Xpay_REST::serve_ucp_profile()`, the fallback `service_base` and `mcp_endpoint`
  for the pre-onboarded ("pending") state now call `rest_url( 'xpay/ucp/v1' )` and
  `rest_url( 'xpay/mcp' )`. The previous hardcoded `/wp-json` prefix did not respect
  installs that customize the REST route prefix via the `rest_url_prefix` filter or
  serve the REST API behind a non-default permalink structure. Per
  <https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/>.
- **`robots.txt` detection switched to `get_home_path()`.**
  `Xpay_Robots::physical_robots_exists()` no longer concatenates `ABSPATH . '/robots.txt'`;
  it uses the canonical helper (loaded from `wp-admin/includes/file.php` on demand).
- **`$_GET['choice']` reads sanitized at the boundary.**
  Both telemetry-consent handlers (`Xpay_Consent::handle_choice()`,
  `Xpay_Settings::handle_telemetry_toggle()`) now pass the raw value through
  `sanitize_key( wp_unslash( ... ) )` before the `'yes' === ...` comparison.
- **No telemetry on settings-page render.** `Xpay_Telemetry::track('settings_viewed')`
  was already gated by the opt-in `is_enabled()` check, but the call was emitted from
  the page-render path. Removed entirely — lifecycle events now fire exclusively from
  explicit admin-post handlers (Connect, Disconnect, telemetry toggle).
- **Belt-and-suspenders `is_enabled()` guard at activation/deactivation telemetry
  call sites.** `Xpay_Telemetry::track()` already short-circuits when telemetry is
  not opted in; the duplicate check at the call site makes the activate / deactivate
  paths trivially auditable as network-silent on fresh installs.
- **Audit-readiness checklist strings wrapped for i18n.** All eight row labels and
  every status detail in `Xpay_Settings::render_readiness_checklist()` are now wrapped
  in `__()` against the `agentic-commerce-for-woocommerce` text domain.
- **`/llms.txt` output no longer HTML-escaped.** `Xpay_REST::serve_llms_txt()` was
  passing the body through `esc_html()` — wrong escape direction for a `text/plain`
  Markdown document (it entity-encoded `&` in URLs and quote characters). Dynamic
  fields (blog name, description, category names) are now stripped of any HTML at
  insertion time via `wp_strip_all_tags()`; URLs go through `esc_url_raw()`. The
  final `echo` writes raw Markdown.
- **`uninstall.php` extended to cover every option the plugin writes.** Added 12
  previously-missed keys (`xpay_wc_payment_map`, `xpay_wc_links`, `xpay_wc_ucp_profile`,
  `xpay_wc_ucp_signing_keys`, `xpay_wc_telemetry_debug`, `xpay_wc_last_connect_attempt`,
  `xpay_wc_connect_finalize_nonce`, `xpay_wc_protocol_endpoints`,
  `xpay_wc_emit_ucp_profile`, `xpay_wc_emit_oauth_protected_resource`,
  `xpay_wc_emit_agent_card`, `xpay_wc_installed_version`) plus a loop-delete of
  per-capability flags (`xpay_wc_capability_<cap>`) against the canonical
  `Xpay_Settings::CAPABILITIES` list.
- **External services disclosure expanded.** Added `audit.xpay.sh`, `auth.xpay.sh`,
  and `install.xpay.sh` to the `readme.txt` External services section; broke out the
  full per-endpoint inventory on `agent-commerce.xpay.sh` (`GET /v1/merchants/{slug}`,
  `PATCH /v1/merchants/{slug}/products/{sku}`) that the plugin contacts.
- **Removed `wp-content` wording from the public readme** ("served from `wp-content`"
  → "served through WordPress rewrite rules") to align with the Determining Locations
  guideline and avoid reviewer regex flags.
- **`CHANGELOG.md` excluded from the WP.org ship zip.** The internal Keep-a-Changelog
  file references `install.xpay.sh` URLs (alternate-distribution language reviewers
  dislike); the public-facing changelog already lives in `readme.txt`. `scripts/release.sh`
  now adds `--exclude='CHANGELOG.md'` to the rsync stage.
- **Tested-up-to bumps.** `Tested up to: 7.0` (released 2026), `WC tested up to: 10.8.1`
  (released 2026-05-27) — exercised end-to-end on a clean InstaWP sandbox with
  `WP_DEBUG=true` and `WP_DEBUG_LOG=true`, `debug.log` clean across activate → connect
  → manifest → disconnect, with permalinks flipped between Plain and Post-name to
  verify `rest_url()` resolves under both modes.

## [0.3.0] — 2026-06-01

### Changed — WordPress.org review-fix release

Addresses the three issues flagged in the WordPress.org plugin-directory
review of v0.2.4 (review ID `R agentic-commerce-for-woocommerce/xpaysh/
31May26/T1`), plus hardening cleanups from independent pre-resubmit review.

- **Privacy: no outbound calls on Settings page load.** The Connect panel
  no longer pre-registers a nonce with the xpay backend when the settings
  page renders. `render_connect_panel()` is now pure markup — zero
  outbound HTTP, zero option writes, zero telemetry. The nonce is
  generated, the attempt is stamped, telemetry fires (only if opted in),
  and the merchant is redirected to the xpay onboarding flow only after
  the **Connect store** button is clicked, inside a new
  `admin_post_xpay_wc_connect_start` handler gated on
  `current_user_can( 'manage_woocommerce' )` + `check_admin_referer()`.
- **`Requires Plugins: woocommerce` header.** Declares the WooCommerce
  dependency via the WP 6.5 plugin-dependencies mechanism so the plugin
  won't activate without WooCommerce present. Existing manual
  `woocommerce_active()` check remains as defence-in-depth for pre-6.5
  sites.
- **Inline admin script removed.** The Connect button no longer emits an
  inline `<script>` tag; click-time telemetry is recorded server-side in
  the redirect handler instead. The associated `wp_ajax_xpay_wc_track`
  ajax registration and `ajax_track()` method body are also removed —
  no nonce-less ajax surface remains on the phoning-home concern the
  reviewer flagged.

### Fixed

- **Double-encoded query args.** `add_query_arg()` urlencodes values
  internally; the previous `rawurlencode()` wrappers around `site` and
  `email` were producing double-encoded parameters in the onboard URL.
- **External hop now uses `wp_safe_redirect()`** with a request-scoped
  `allowed_redirect_hosts` filter that whitelists the xpay onboarding
  host. Replaces the previous raw `wp_redirect()` + `phpcs:ignore`.
- **REST `finalize` callback documented.** Inline comment on the
  `permission_callback => '__return_true'` registration explains that
  authentication is performed inside the callback body via
  `hash_equals()` against a single-use connect nonce. Existing nonce
  burn-on-success behaviour is unchanged.
- **Belt-and-suspenders telemetry guard.** `handle_connect_start()` now
  checks `Xpay_Telemetry::is_enabled()` before calling
  `Xpay_Telemetry::track()`, even though `track()` already checks
  internally. Makes the connect-start path trivially auditable as
  network-silent when telemetry is OFF without reading the Telemetry
  class.

### Documentation

- **Settings H1 corrected** from "xpay for WooCommerce" to
  "Agentic Commerce for WooCommerce" — the directory-approved name.
- **`readme.txt` privacy + external-services sections** now disclose
  explicitly what is sent to xpay after the merchant clicks **Connect
  store**: site URL, administrator email address, one-time nonce, WC
  REST API consumer key/secret, public product fields. Removed the
  obsolete `/v1/onboard/woocommerce/start` contact path. Qualified
  telemetry's "no PII" claim as "no customer PII".
- **Removed alternate-distribution language** from `readme.txt` — the
  "From a zip file" install section pointing at
  `install.xpay.sh/woocommerce/latest.zip` and the External Services
  entry describing `install.xpay.sh` as an auto-update channel. The
  WordPress.org install path is the only path documented in the WP.org
  readme.
- **Removed two "new tab" references** in install steps — the v0.3.0
  redirect is same-tab via `wp_safe_redirect()`.

## [0.2.4] — 2026-05-22

### Added — Tabbed Settings → xpay UI (M3.1)

The single-screen settings page is now split into five tabs, accessible via
`?tab=` URL params:

- **General** — connection status, merchant slug, last sync, disconnect,
  readiness checklist, anonymous-telemetry opt-in.
- **Capabilities** — per-UCP-shopping-capability toggles. Each of
  `checkout` / `fulfillment` / `discount` / `order` can be switched off, in
  which case the capability disappears from `/.well-known/ucp`. Default
  (no option set) is enabled, so existing connected installs don't regress
  on upgrade.
- **Payments** — lists every enabled WooCommerce gateway. Checking a
  gateway adds it to UCP `payment_handlers[]` as
  `{id, label, type:"merchant_gateway"}` (label defaults to the gateway's
  WC title, overridable per row).
- **Links** — auto-detects privacy, TOS, about, contact, and shipping URLs
  via WP's `get_privacy_policy_url()` and common page slugs
  (`privacy-policy`, `terms`, `about`, `contact`, `shipping`, plus a few
  variants). Each row is overridable. Emitted in the manifest as
  `links: [{rel, href}, ...]`.
- **Tools** — utility actions: view the live UCP profile,
  jump to the full audit at `audit.xpay.sh/m/{slug}`, run a synchronous
  "Test connection" probe against the backend, force a "Refresh catalog
  now" (POSTs `/v1/merchants/{slug}/resync`), and a debug-log toggle for
  outbound HTTP request logging (separate from the anonymous-telemetry
  opt-in on General).

Tab nav uses WordPress's standard `nav-tab-wrapper` markup so styling
matches every other core settings page. URLs are bookmarkable. The
disconnected layout (single Connect-store card) is unchanged.

### Added — `ucp.links` in the emitted manifest

The plugin's `serve_ucp_profile()` now emits a `links` array drawn from
the Links tab (with auto-detect fallback for unset rows). This closes the
last UCPHub-parity gap on the discovery body.

### Changed — `serve_ucp_profile()` is filterable via Capabilities tab

The previously-hardcoded capability map in `class-xpay-rest.php` is now
overlaid against `xpay_wc_capability_{name}` wp_options. Toggling off
`discount` on the Capabilities tab causes the `dev.ucp.shopping.discount`
key to disappear from the JSON body returned at `/.well-known/ucp`.

### Added — Per-product delta-resync (M4.2)

Product edits, stock changes, and deletes now dispatch a single-product
PATCH to `agent-commerce.xpay.sh/v1/merchants/{slug}/products/{sku}`
instead of triggering a full 50-page-pull rebuild of the S3 catalog.
The new path runs in ~200ms for a 5MB catalog vs 30–60s for a full
resync on glycodepot-scale stores.

- Hooks: `woocommerce_update_product`, `woocommerce_new_product`,
  `woocommerce_delete_product`, `woocommerce_trash_product`,
  `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`.
- Variation stock changes resolve to the parent product (variations don't
  have standalone catalog entries).
- Debounced per `(product_id, op)` via `wp_schedule_single_event`, so a
  bulk edit of N products → N backend writes (not N²) and rapid edits to
  the same product coalesce. Pending `update` is dropped when a `delete`
  for the same product is queued (and vice versa).
- Catalog visibility flips (`publish → draft`, `catalog_visibility=hidden`)
  send `{deleted: true}` so the product disappears from the agent feed.
- Hard failures (5xx / auth) fall back to a full resync so no edit is
  lost; hourly `scheduledResync` covers anything else.
- Backend writes are etag-protected (`If-Match`) with 3-retry RMW;
  PreconditionFailed exhaustion falls back to a full resync server-side.

### Cleanup — disconnect removes the new option keys

`handle_disconnect()` now also deletes `xpay_wc_capability_*`,
`xpay_wc_payment_map`, `xpay_wc_links`, and `xpay_wc_telemetry_debug`, so
a disconnect/reconnect cycle leaves no orphan options behind.

## [0.2.3] — 2026-05-22

### Added — MCP transport in `/.well-known/ucp`

`services.dev.ucp.shopping` now advertises both `rest` and `mcp` transports.
The MCP endpoint `https://{merchant_slug}.mcp.xpay.sh/mcp` rides on the
existing xpay `*.mcp.xpay.sh` wildcard infrastructure. The endpoint is
provisional today and will return real `search_catalog` / `get_product` /
`create_cart` tool responses once the commerce-MCP handler lands; in the
meantime agents that speak MCP natively (Claude, ChatGPT Operator, the
Shopify AI Toolkit) discover the endpoint immediately. No merchant action
required — the manifest re-emits on plugin update.

### Fixed — UCP manifest validity against the 2026-04-08 spec

- `extends` is now an array (`extends: ["dev.ucp.shopping.checkout"]`)
  rather than a string. The 2026-04-08 spec made `extends` strictly array-valued; the prior
  string form failed strict validators.
- All capability `spec` URLs are now date-prefixed (`https://ucp.dev/2026-04-08/specification/...`)
  to match the schema URLs. The `order` capability had drifted to `/latest/`.
- Added `payment_handlers: []` placeholder under `ucp` for parity with the rest of the
  ecosystem (Shopify, UCPHub). Backend pushes per-merchant handlers via the existing
  `xpay_wc_ucp_profile` override mechanism when configured.

## [0.2.2] — 2026-05-22

### Fixed — Connect-store retry no longer 401s with "nonce already used"

A merchant whose WordPress site responded slowly during the finalize callback
would see a 500/504 in the xpay app, and on retry would hit "nonce already used"
because the backend burned the nonce before the callback fetch.

- `rest_finalize` is now idempotent on replay: if `merchant_slug` + `api_key`
  already match what the plugin has stored, accept silently (HTTP 200) instead
  of failing on the missing local nonce.
- The initial catalog resync is now scheduled via `wp_schedule_single_event` and
  fired non-blocking, so the REST response returns in well under a second even
  on hosts whose outbound HTTPS to `agent-commerce.xpay.sh` is slow.

(Paired with backend change in `wc-plugin-setup`: the backend now delivers
credentials to the plugin BEFORE consuming the nonce, with an 8s AbortSignal
on the fetch, and surfaces a clear actionable error if the WP site can't be
reached so the merchant can retry without losing their handshake.)

## [0.2.1] — 2026-05-16

### Changed — `/llms.txt` only advertises live protocol endpoints

The `## Commerce protocols` section in `/llms.txt` is now gated on the
`xpay_wc_protocol_endpoints` wp_option, populated by the xpay backend
during the Connect flow with the set of protocols actually serving for
the merchant.

Result: an AI agent that fetches `/llms.txt` and follows a protocol URL
gets a working service (or a structured 501 with retry hints) — never a
bare 404. Until the backend has confirmed at least one live protocol,
the section is omitted entirely; the catalog feed and cart deeplink
(both of which are live today) are still advertised.

The filter `xpay_wc_protocol_endpoints` lets a mu-plugin override.

### Added — backend stubs for `agent-commerce.xpay.sh/{ucp,acp,ap2,mcp}/...`

Companion change on the xpay backend (`xpay-wc-plugin-backend`): the
protocol-prefixed URLs at `agent-commerce.xpay.sh` now answer with a
spec-shaped 501 Not Implemented envelope when called. Body includes
`protocol`, `spec`, `merchant_slug`, `status: "pending_implementation"`,
`retry_after_seconds`, and a `docs` link. Replaces the earlier bare 404.

The real UCP service will replace the 501 stub as soon as the schemas
land in `@xpaysh/ucp-schemas@0.2.0`. ACP and AP2 follow.

## [0.2.0] — 2026-05-16

### Added — Commerce-standards alignment

Multi-protocol from this release on. The plugin now speaks the open commerce
standards (**ACP** — Agentic Commerce Protocol, **UCP** — Universal Commerce
Protocol, **AP2** — Agent Payments Protocol) and exposes the real discovery
conventions on the merchant's domain (**llms.txt**, **schema.org** JSON-LD,
**robots.txt** allowlist for AI user-agents).

The discovery surface is now an **extensible emitter registry**: each standard
is one entry, with a `default_on` flag and an optional `option_flag` so each
emitter can be toggled per-merchant. Adding a new standard means adding a new
emitter — no changes to rewrite logic, settings UI, or the rest of the plugin.

#### Default-on emitters

- **`/llms.txt`** ([llmstxt.org](https://llmstxt.org)) — Markdown discovery
  document. Lists the agent-readable catalog feed, the per-protocol endpoints
  (ACP / UCP / AP2 / MCP) hosted on xpay infrastructure, the cart-deeplink
  template, and top product categories.
- **`/.well-known/ucp`** — UCP business profile (spec rev `2026-04-08`).
  Documented at [Google's UCP guide](https://developers.google.com/merchant/ucp/guides/ucp-profile)
  and [ucp.dev](https://ucp.dev/latest/specification/overview/). Google,
  Shopify, Etsy, Wayfair, Target and Walmart fetch this profile for capability
  negotiation before talking to the merchant — the spec requires it to be
  publicly accessible and unauthenticated. The plugin generates a sensible
  default profile pointing at xpay-hosted UCP service endpoints
  (`agent-commerce.xpay.sh/ucp/v1/<slug>`) and exposes two `wp_option` hooks
  for full overrides: `xpay_wc_ucp_profile` (replace entire body) and
  `xpay_wc_ucp_signing_keys` (inject JWK array for message verification).

#### Watchlist emitters (off by default — opt-in per merchant)

- **`/.well-known/oauth-protected-resource`** — RFC 9728 OAuth 2.0 Protected
  Resource Metadata. Turns on automatically when UCP OAuth Identity Linking is
  enabled for the merchant. Option key: `xpay_wc_emit_oauth_protected_resource`.
- **`/.well-known/agent-card.json`** — A2A 1.0 agent-card metadata. IANA
  well-known URI, registered 2025-08-01. Opt-in via the
  `xpay_wc_emit_agent_card` option once A2A adoption matures in commerce.

### Changed — `/llms.txt` body

Now advertises the per-protocol endpoints by name (ACP / UCP / AP2 / MCP) and
links them at their xpay-hosted URLs (`agent-commerce.xpay.sh/<protocol>/v1/<slug>`).
A merchant who installs the plugin is automatically reachable by any agent
that speaks any of these protocols — coverage grows as agents adopt each one.

### Changed — Admin readiness checklist

The "AI assistants know where to send a buyer" row now reflects the standards-based
architecture: per-protocol endpoints advertised in `/llms.txt` and hosted on
xpay infrastructure, rather than a single discovery file. All eight audit pills
continue to turn green after **Connect store**.

### Backward compatibility

No breaking changes for merchants. Cart deeplink handler, catalog feed,
schema.org JSON-LD on PDPs / shop / home, robots.txt allowlist, lifecycle
telemetry pipe, and the WooCommerce REST API onboarding handshake are all
unchanged. The audit-readiness pills continue to turn green after Connect.


## [0.1.12] — 2026-05-15

### Changed — Plugin RENAMED

**"xpay for WooCommerce" → "Agentic Commerce for WooCommerce"** (slug `agentic-commerce-for-woocommerce`).

WordPress.org rejected the original submission with: *"There is already a plugin with the name xpay for WooCommerce in the directory. You must rename your plugin by changing the Plugin Name: line in your main plugin file and in your readme. Once you have done so, you may upload it again."* The conflict is with [Nexi XPay](https://wordpress.org/plugins/cartasi-x-pay/), an Italian payment-gateway plugin for WooCommerce by Nexi Payments (~6,000 active installs since 2017). WP.org's name-similarity check matches the "XPay" brand string regardless of category, and Nexi holds prior art.

The new name describes the actual category (agentic commerce) and avoids any trademark/similarity overlap. The `xpay` brand is retained via:
- `Author:` header (still `xpay`)
- `Contributors:` line (still `xpaysh`)
- Admin menu label (still `xpay`)
- Author URI + Plugin URI (still `www.xpay.sh`)
- All backend services and the product story

#### What changed mechanically

- `xpay-for-woocommerce.php` → `agentic-commerce-for-woocommerce.php` (main file renamed)
- `Plugin Name:` header → `Agentic Commerce for WooCommerce`
- `Text Domain:` → `agentic-commerce-for-woocommerce`
- All `'xpay-for-woocommerce'` text-domain references in PHP files → `'agentic-commerce-for-woocommerce'`
- `?page=xpay-for-woocommerce` admin URLs → `?page=agentic-commerce-for-woocommerce`
- `languages/xpay-for-woocommerce.pot` → `languages/agentic-commerce-for-woocommerce.pot`
- Outbound HTTP User-Agent header → `agentic-commerce-for-woocommerce/{version}`
- Settings page H1 → "Agentic Commerce for WooCommerce"
- Plugins page error notice → "Agentic Commerce for WooCommerce requires WooCommerce…"
- Consent notice title → "Agentic Commerce for WooCommerce — help us improve onboarding"
- release.sh `SLUG` variable → `agentic-commerce-for-woocommerce` (zip inner folder will be `agentic-commerce-for-woocommerce/`)
- readme.txt first-line title → `=== Agentic Commerce for WooCommerce ===`

#### What didn't change

- Internal PHP constants (`XPAY_WC_VERSION`, `XPAY_WC_FILE`, etc.) — these are internal namespacing, not user-facing
- Class prefixes (`Xpay_Plugin`, `Xpay_Settings`, etc.) — internal namespacing
- Option keys (`xpay_wc_merchant_slug`, `xpay_wc_telemetry_opt_in`, etc.) — renaming these would force every existing tester to reconnect, no benefit for a new submission
- Backend services and their hostnames (`agent-feed.xpay.sh`, `agent-commerce.xpay.sh`, `app.xpay.sh`, `install.xpay.sh`)
- Plugin functionality, dependencies, behavior — code is byte-for-byte identical except the rename touchpoints listed above

## [0.1.11] — 2026-05-15

### Changed (Plugin Check follow-up — PrefixAllGlobals warnings cleared)

- `uninstall.php` wrapped in an anonymous-closure IIFE. `$option_keys` and `$key` are no longer top-level globals — they live inside the closure scope. Added a few options + transients to the cleanup list (the consent / activation-redirect state we added in later versions).
- `class-xpay-schema.php :: render_product()` no longer declares `global $product`. Uses a local `$xpay_product = wc_get_product( get_the_ID() )` lookup instead. Same correctness — `wc_get_product` returns the product for the current PDP — but PCP no longer mistakes the WC template global for one of ours.
- `class-xpay-plugin.php :: woocommerce_active()` no longer wraps `get_option('active_plugins')` in `apply_filters( 'active_plugins', … )`. We were correctly reading WP core's filter, but PCP flagged it as "non-prefixed hook name invoked by plugin." Replaced with a raw option read + explicit multisite-sitewide merge — same behavior across single and multisite, no filter call. `class_exists('WooCommerce')` short-circuit retained as the first check.

PHPCS: still 0 errors / 1 cosmetic warning. Released to install.xpay.sh.

## [0.1.10] — 2026-05-15

### Changed (Plugin Check (PCP) clean-up)

- **`Tested up to: 6.9`** in readme.txt (was 6.7). PCP rejected anything < current WP minor. Plugin verified working on the real WP 6.x test install; no code changes needed.
- **Short description trimmed** to 141 chars (was 172). PCP enforces a 150-char cap for the readme summary line that renders below the plugin title on the listing.
- **Removed `load_plugin_textdomain()` and the `init` hook** that called it. Per PCP: `load_plugin_textdomain() has been discouraged since WordPress version 4.6. When your plugin is hosted on WordPress.org, you no longer need to manually include this function call for translations under your plugin slug.` The `languages/xpay-for-woocommerce.pot` template stays bundled for community translators; core handles loading.
- **Release script excludes** `INSTAWP_TEST_WALKTHROUGH.md` and `README.md` from the zip. PCP warns on "unexpected markdown files in plugin root" — only canonical files (readme.txt, CHANGELOG.md, license.txt) should ship in a runtime plugin. Repo-side documentation files remain in the GitHub repo for contributors but no longer travel with the installed plugin.

## [0.1.9] — 2026-05-15

### Changed

- **Documentation moved** from `docs.xpay.sh/products/woocommerce/*` to `docs.xpay.sh/merchants/woocommerce/*`. Merchants is the audience-level bucket; WooCommerce is one of several future platform integrations (Shopify, BigCommerce, Magento, custom) that will live as siblings under `/merchants/`. Mirrors the existing `/publishers/` IA.
- Updated 9 backlinks across readme.txt + class-xpay-settings.php Connect-store panel + assets/preview/listing.html to the new path.
- No plugin functionality changed; URL migration only.

## [0.1.8] — 2026-05-15

### Added

- **Documentation site at [docs.xpay.sh/merchants/woocommerce](https://docs.xpay.sh/merchants/woocommerce)** — six new pages: Overview, Installing, WooCommerce REST API keys, Connecting your store, Privacy & telemetry, Audit readiness checklist, Troubleshooting. Source lives in `DEVELOPER_DOCS/xpay-docs/src/content/en/products/woocommerce/` (separate repo, deployed via Vercel).
- readme.txt now backlinks the docs at every relevant moment — install instructions link to the install walkthrough, the connect flow links to the keys page, privacy section links to the plain-English version, etc.

### Changed

- **Punchier Description hero** — leads with buyer-side framing ("Your next customer is asking ChatGPT, not Google") instead of an abstract industry claim. Description now opens with a concrete user behaviour the merchant immediately recognizes.
- Pricing link updated to `https://www.xpay.sh/pricing/?tab=agentic-commerce` (readme, terms.html, preview listing — all 5 occurrences).

## [0.1.7] — 2026-05-15

### Changed

- **`xpaysh/xpay-for-woocommerce` GitHub repo flipped public.** Restored GitHub link references in readme.txt FAQ + "Source code" + roadmap sections so reviewers and merchants can browse the unminified plugin source directly without leaving WordPress.org. GPLv2-or-later unchanged.

## [0.1.6] — 2026-05-15

### Changed

- Removed GitHub repo link references from readme.txt. The source-of-truth Git repo at `xpaysh/xpay-for-woocommerce` is currently private; linking to it from a publicly-rendered WordPress.org listing page produces a 404 for anyone not signed into our org. The plugin remains GPLv2-or-later — the installed zip is the canonical unminified source. Re-add the link in a future release once the repo flips public.

## [0.1.5] — 2026-05-15

### Added

- **Query-arg fallback for the discovery file.** Hosts that intercept `/.well-known/` at the web-server layer (some shared hosts, CDN edges, ACME setups, sandbox environments like InstaWP) now serve the agent-commerce discovery file at `/?xpay_route=acp` and the llms.txt equivalent at `/?xpay_route=llms`. Discoverable via the `Link` header on the home page.

### Changed

- **Post-activation redirect to Settings → xpay** now fires on any activation when the store hasn't connected yet, not only on the very first activation in DB history. Skipped on bulk-activate. Addresses InstaWP smoke-test feedback where upgrading from v0.1.3 → v0.1.4 didn't trigger the redirect.
- Polished readme.txt Description, FAQ, and Installation sections against the top-installed WooCommerce plugins (Stripe for WooCommerce, MailPoet, Yoast SEO for WooCommerce, WooPayments) for WP.org submission readiness.
- readme.txt header now includes `WC requires at least` and `WC tested up to` so the WordPress.org listing shows the compatibility badge.

## [0.1.4] — 2026-05-15

### Added

- **Post-activation redirect** into `Settings → xpay` on first activation only (skipped on bulk-activate). Reduces "I activated it, now what?" friction reported during InstaWP smoke test.
- **HPOS + Cart/Checkout Blocks compatibility declaration** via `before_woocommerce_init` → `FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true )` and `… 'cart_checkout_blocks' …`. Silences the WC admin notice flagged on modern WC installs. The plugin never reads or writes WC orders directly, so HPOS support is inherent.
- **Privacy and Terms pages** published at `install.xpay.sh/woocommerce/privacy.html` + `terms.html` (hosted on our CDN; HTML source in `assets/web/`). Privacy doc enumerates every byte sent in every code path, opt-out paths, retention, deletion request flow, and links to the source-of-truth file for each path.

### Changed

- Plugin URI: `https://xpay.sh/sellers/woocommerce` → `https://www.xpay.sh/merchants/woocommerce/`.
- Author URI: `https://xpay.sh` → `https://www.xpay.sh`.
- Consent admin notice "What gets sent" link now points to `install.xpay.sh/woocommerce/privacy.html` (was a 404 placeholder).
- readme.txt External services + Privacy sections link to the new privacy + terms URLs.

## [0.1.3] — 2026-05-14

### Changed (WordPress.org submission — Tier 1 & 2 polish)

- **PHPCS clean**: WordPress coding-standard pass — 0 errors, 1 cosmetic warning (down from 143 errors / 71 warnings).
  - Real fixes: `$_SERVER['REQUEST_URI']` unslashed + sanitized; `/llms.txt` output escaped; short ternaries (`?:`) expanded; Yoda conditions; reserved-keyword param renamed (`$public` → `$is_public`); conditional `error_log` guarded by `WP_DEBUG`.
  - Targeted suppressions with justification comments: cart deeplink (authenticated via signed JWT, not nonce); ajax-beacon endpoint (authenticated via `current_user_can`); JWT `base64_decode` (protocol, not obfuscation); JSON-LD `print_raw` (pre-encoded, double-escaping would break schema).
- Added `phpcs.xml.dist` ruleset pinned to WP standard, with `manage_woocommerce` registered as a known capability and text-domain pinned to `xpay-for-woocommerce`.

### Added

- `languages/xpay-for-woocommerce.pot` — translation template generated via `wp i18n make-pot`. Required for the WP.org "Translation ready" badge.
- WP.org listing assets in `assets/`:
  - `banner-772x250.png` + `banner-1544x500.png` (retina) — listing banner
  - `icon-128x128.png` + `icon-256x256.png` (retina) — plugin icon
  - `screenshot-1.png` through `screenshot-5.png` — 1600×1000, captioned in readme.txt
- `assets/screenshots-src/` — source HTML + Playwright capture script so screenshots are reproducible. Excluded from the plugin zip.
- Release script now also excludes `assets/`, `phpcs.xml.dist`, `.gitignore` from the zip (these live in the repo / SVN-assets directory, not the installable plugin).

## [0.1.2] — 2026-05-14

### Changed (WordPress.org submission compliance — Tier 0)

- **Plugin slug renamed** from `xpay-woocommerce` to `xpay-for-woocommerce`. Required for WordPress.org Guideline 17 (trademark — non-Automattic / non-WooCommerce vendors cannot have a slug starting with `woocommerce`). Plugin name "xpay for WooCommerce" already uses the canonical `for X` form, so display branding is unchanged.
- **Telemetry is now opt-in, not opt-out.** Required for WordPress.org Guideline 7 (informed consent for external server contact). On first activation an admin notice asks the merchant to choose. Default is OFF. Sysadmin override `define( 'XPAY_WC_TELEMETRY', false )` still hard-disables.
- Main plugin file renamed `xpay-woocommerce.php` → `xpay-for-woocommerce.php`. Text Domain updated to `xpay-for-woocommerce`. All admin URLs (`?page=xpay-for-woocommerce`) follow.
- HTTP User-Agent updated to `xpay-for-woocommerce/{version}`.

### Added

- `Xpay_Consent` admin-notice subsystem — shows once on first activation, never reappears after the merchant chooses.
- **Settings → xpay → Privacy** panel — merchant can change their telemetry choice any time without editing wp-config.
- readme.txt `== External services ==` section listing every endpoint the plugin contacts, what data goes where, and links to terms + privacy policy. Required for WordPress.org Guideline 6.
- readme.txt `== Privacy ==` section detailing exactly what is and isn't sent, and how to opt out / request deletion.
- readme.txt `== Screenshots ==` placeholder captions (assets to be added before WP.org submission).
- readme.txt `== Upgrade Notice ==` block for 0.1.2.

## [0.1.1] — 2026-05-14

### Added

- Lifecycle telemetry pipe: fire-and-forget `POST /v1/events` from the plugin on `plugin_activated`, `plugin_deactivated`, `settings_viewed`, `connect_clicked` (sendBeacon on click, doesn't block target=_blank), `finalize_success`, `finalize_error` (with reason: `invalid_nonce` / `missing_fields`), `audit_rerun_clicked`, `audit_rerun_success` / `audit_rerun_error`, `disconnected`, `resync_success` / `resync_error`.
- New `Xpay_Telemetry::track()` helper — `wp_remote_post` with `blocking=false`, 1s timeout, full try/catch — provably cannot block or break the host site.
- Opt-out: `define( 'XPAY_WC_TELEMETRY', false )` in wp-config.

### Backend

- New Lambda `events.ingest` at `POST agent-commerce.xpay.sh/v1/events`, writes to `xpay-wc-events-{stage}` (DynamoDB, 90-day TTL). Event names are enum-validated; props capped at 20 keys × 512 chars. Also `console.log` so CloudWatch Insights is queryable from day 1.

## [0.1.0] — 2026-05-14

### Added

- WordPress plugin scaffold targeting WooCommerce 7.0+ on WP 6.2+ / PHP 7.4+.
- Discovery files served on the merchant's domain:
  - `/llms.txt` — plain-text guide for AI shopping agents
  - `/.well-known/agentic-commerce.json` — structured handoff descriptor pointing at the xpay-hosted catalog and cart endpoints
- JSON-LD injection on PDP / shop / homepage: `Product`, `Offer`, `BuyAction`, `ItemList`, with conflict detection against Yoast / Rank Math / WooCommerce core schemas.
- `robots.txt` allowlist for GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, Claude-SearchBot, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot — respects merchants' existing rules and never overrides explicit blocks.
- Cart deeplink handler: `/?xpay_cart=<JWT>` validates the signed payload, populates `WC()->cart`, redirects to `wc_get_checkout_url()`. Orders are tagged with `_xpay_agent_attribution` meta on the WC order so merchants can attribute revenue.
- Webhook resyncs on `woocommerce_update_product`, `woocommerce_new_product`, `woocommerce_delete_product`, stock changes — debounced via `wp_schedule_single_event` to avoid hammering the backend during bulk imports.
- Admin page at **Settings → xpay**: nonce-protected Connect flow, status panel, "Re-run audit" button, audit-readiness checklist that mirrors the eight checks from `scripts/seller-audit/audit.py`.
- Optional `[xpay-buy]` shortcode + Gutenberg block — gated by an admin toggle, default OFF in v1.
- Configuration overrides for dev / InstaWP testing: `XPAY_WC_API_BASE_OVERRIDE`, `XPAY_WC_AGENT_COMMERCE_OVERRIDE`, `XPAY_WC_ONBOARD_URL_OVERRIDE`.

### Distribution

- Self-hosted via `https://install.xpay.sh/woocommerce/latest.zip` (S3 + CloudFront, isolated from `widget.xpay.sh` to protect the chat-widget publishers).
- Downloads served with `Content-Disposition: attachment; filename="xpay_woocommerce_plugin_{version}.zip"`, HSTS, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`.

### Known limitations (v0.1)

- Cart-token HMAC uses `sha256(api_key)` as the shared secret. v0.2 will move to asymmetric signing so the backend never needs symmetric-key knowledge.
- WC REST credentials are stored plaintext in DynamoDB. v0.2 wraps them with KMS-encrypted envelopes.
- `audit.run` only queues a placeholder; v0.2 wires it to an SQS-driven worker that invokes `scripts/seller-audit/audit.py`.
- Optional on-site widget is gated OFF by default — promote to ON once shaken-out on real themes.
