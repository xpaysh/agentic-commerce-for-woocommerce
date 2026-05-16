# Changelog

All notable changes to **xpay for WooCommerce** are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The latest version always lives at <https://install.xpay.sh/woocommerce/latest.zip>;
versioned downloads at <https://install.xpay.sh/woocommerce/xpay-woocommerce-{version}.zip>;
release metadata at <https://install.xpay.sh/woocommerce/manifest.json>.

## [Unreleased]

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
