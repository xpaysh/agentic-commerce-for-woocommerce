# Changelog

All notable changes to **xpay for WooCommerce** are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The latest version always lives at <https://install.xpay.sh/woocommerce/latest.zip>;
versioned downloads at <https://install.xpay.sh/woocommerce/xpay-woocommerce-{version}.zip>;
release metadata at <https://install.xpay.sh/woocommerce/manifest.json>.

## [Unreleased]

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
