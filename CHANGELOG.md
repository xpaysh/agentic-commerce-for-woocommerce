# Changelog

All notable changes to **xpay for WooCommerce** are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The latest version always lives at <https://install.xpay.sh/woocommerce/latest.zip>;
versioned downloads at <https://install.xpay.sh/woocommerce/xpay-woocommerce-{version}.zip>;
release metadata at <https://install.xpay.sh/woocommerce/manifest.json>.

## [Unreleased]

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
