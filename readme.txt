=== Agentic Commerce for WooCommerce ===
Contributors: xpaysh
Tags: woocommerce, ai, chatgpt, agentic commerce, llms
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Put your WooCommerce catalog inside ChatGPT, Claude, Gemini and Perplexity — buyers complete checkout on your existing WooCommerce gateway.

== Description ==

**Your next customer is asking ChatGPT, not Google.** They're shopping by typing "find me a cordless drill under $80 that ships in 2 days" into a chat box — and quietly walking away from any store the AI can't see. Right now, that's most WooCommerce stores.

**Agentic Commerce for WooCommerce (by xpay) makes your store visible to ChatGPT, Claude, Gemini and Perplexity** in five minutes flat — no theme changes, no replatforming, no new payment processor. Your existing checkout stays exactly as it is; xpay just makes sure you're the answer the AI gives.

📘 **Full setup guide with screenshots:** [docs.xpay.sh/merchants/woocommerce](https://docs.xpay.sh/merchants/woocommerce)
🌐 **Plugin home:** [www.xpay.sh/merchants/woocommerce/](https://www.xpay.sh/merchants/woocommerce/)
🔓 **Source on GitHub:** [github.com/xpaysh/agentic-commerce-for-woocommerce](https://github.com/xpaysh/agentic-commerce-for-woocommerce)

= What it does =

* **Publishes a public, agent-readable product feed** — your full catalog with live prices and stock, hosted on xpay's CDN (no extra load on your origin).
* **Adds AI-shopping JSON-LD** — `Product`, `Offer`, `AggregateOffer`, `BuyAction` and `ItemList` schemas on product pages, shop archive and home page. Detects existing schema from Yoast / Rank Math / WooCommerce core and only fills the gaps.
* **Serves the real AI shopping standards on your own domain** — `/llms.txt` ([llmstxt.org](https://llmstxt.org)), `schema.org` `Product`/`Offer`/`BuyAction` JSON-LD on every product page, and an explicit `robots.txt` allowlist for AI user-agents. Optional watchlist emitters for `/.well-known/oauth-protected-resource` (RFC 9728, when UCP OAuth identity linking is on) and `/.well-known/agent-card.json` (A2A 1.0, off by default). The discovery layer is registry-based so new standards plug in cleanly.
* **Allows the right bots** — GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, Claude-SearchBot, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended and CCBot. Never overrides your existing robots.txt rules.
* **Cart deep-link** — AI agents create a one-click "Buy" link that pre-fills your existing WooCommerce cart and lands the buyer on your existing checkout. Orders are tagged with `_xpay_agent_attribution` so you can attribute AI-driven revenue in your existing reporting.
* **Live inventory** — webhook-driven catalog refresh on every product / stock change (debounced 30s), plus an hourly safety-net poll.

= What it doesn't do =

* **It doesn't touch your checkout.** Stripe / WooPayments / PayPal / Square / whatever you already use — payment runs through them, unchanged. Your payout schedule is unchanged.
* **It doesn't see your customers.** No buyer names, emails, addresses, IPs, payment cards, order line items, refunds, or PII of any kind passes through xpay. Ever. The plugin is non-custodial.
* **It doesn't require a new account or contract** to start. Free until your first AI-attributable sale; pricing kicks in after that. [See pricing](https://www.xpay.sh/pricing/?tab=agentic-commerce).
* **It doesn't slow down your site.** The JSON-LD block is tiny and cached; the catalog feed is served from xpay's CDN, not your origin.

= Five-minute install flow =

1. Install the plugin from this directory or upload the zip. ([detailed walk-through](https://docs.xpay.sh/merchants/woocommerce/installing))
2. Activate. You'll be taken to **Settings → xpay**.
3. Click **Connect store**. A new tab opens at app.xpay.sh, where you grant a read-only WooCommerce REST API key. ([how to generate one](https://docs.xpay.sh/merchants/woocommerce/rest-api-keys))
4. Your catalog goes live on AI surfaces within about 10 minutes. The plugin's built-in audit-readiness checklist ([what each row means](https://docs.xpay.sh/merchants/woocommerce/audit-readiness)) turns green as each piece confirms.

Stuck on any step? [Troubleshooting guide](https://docs.xpay.sh/merchants/woocommerce/troubleshooting).

= Compatibility =

* WooCommerce 7.0+ on WordPress 6.2+ and PHP 7.4+.
* Declares compatibility with WooCommerce High-Performance Order Storage (HPOS) and Cart/Checkout Blocks.
* Works alongside Yoast SEO, Rank Math, WooCommerce Blocks, WooPayments, Stripe for WooCommerce, and the standard Storefront / Astra / Divi / Elementor themes.

= Privacy and consent =

* **Anonymous lifecycle telemetry is off by default.** On first activation a single admin notice asks once. Pick "No thanks" and the plugin never contacts our backend for analytics. Pick "Enable" and you can change your mind any time under **Settings → xpay → Privacy**. System-wide opt-out via `define( 'XPAY_WC_TELEMETRY', false );` in `wp-config.php`.
* **Full data disclosure** at [install.xpay.sh/woocommerce/privacy.html](https://install.xpay.sh/woocommerce/privacy.html) — every byte the plugin sends, when it sends it, how to opt out, how to request deletion. Plain-English version: [docs.xpay.sh/merchants/woocommerce/privacy-telemetry](https://docs.xpay.sh/merchants/woocommerce/privacy-telemetry).

= Source code and contributing =

The plugin source is published under GPLv2-or-later. Public repo and issue tracker: [github.com/xpaysh/agentic-commerce-for-woocommerce](https://github.com/xpaysh/agentic-commerce-for-woocommerce). You can fork, modify, redistribute, and self-host without paying anything.

== Installation ==

= From the WordPress.org plugin directory =

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for "Agentic Commerce for WooCommerce".
3. Click **Install Now**, then **Activate**.
4. You'll be redirected to **Settings → xpay**. Click **Connect store**.
5. Approve the WooCommerce REST API permissions in the new tab that opens at app.xpay.sh.

= From a zip file =

1. Download the latest release from [install.xpay.sh/woocommerce/latest.zip](https://install.xpay.sh/woocommerce/latest.zip).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip file and click **Install Now**, then **Activate**.
4. Continue from step 4 above.

= System requirements =

* WordPress 6.2 or higher
* WooCommerce 7.0 or higher
* PHP 7.4 or higher
* SSL (`https://`) on the store domain — required for the agent discovery files to be honored by AI surfaces

== Frequently Asked Questions ==

= Does this slow down my site? =

No. The plugin emits a small `<script type="application/ld+json">` block in `<head>` on product pages, shop and home (only when no other plugin has already emitted equivalent schema). The discovery files are served from `wp-content` via a single rewrite rule with no DB query. The catalog feed itself is hosted on xpay's CDN, not your origin — so AI shoppers reading it never load your origin.

= Does xpay see my customers' payment info? =

No. Payment runs through your existing WooCommerce gateway (Stripe / WooPayments / PayPal / Square / etc.). xpay never touches your checkout, your cards, your buyer PII, or your refund flow.

= What if I already have Yoast SEO / Rank Math emitting Product schema? =

xpay detects the existing schema at runtime and only adds the bits it's missing — typically `BuyAction` on product pages and `ItemList` on the homepage. No duplicate schema is emitted.

= What does the plugin send to xpay's servers, and when? =

Two data paths:

1. **Catalog sync (required after Connect)** — your public product fields (name, description, price, currency, stock state, image URLs, categories, SKU). No customer or order data. Used to publish your catalog at `agent-feed.xpay.sh/catalog/{your-slug}.json` so AI shoppers can read it.
2. **Anonymous lifecycle telemetry (opt-in, off by default)** — lifecycle event names (`plugin_activated`, `settings_viewed`, `resync_error`, etc.) tagged with your site URL and plugin/WP/WC/PHP versions. No customer data, no order data, no PII.

Full disclosure: [install.xpay.sh/woocommerce/privacy.html](https://install.xpay.sh/woocommerce/privacy.html).

= How do I uninstall cleanly? =

**Plugins → Deactivate → Delete**. The bundled `uninstall.php` removes every option the plugin wrote. To also delete the catalog feed from xpay's CDN, email privacy@xpay.sh from your admin email with your merchant slug.

= How do I opt out of anonymous telemetry after I already enabled it? =

**Settings → xpay → Privacy → Turn off**. Or define `XPAY_WC_TELEMETRY` to `false` in `wp-config.php` for a system-wide hard disable.

= My host blocks /.well-known/ — can the discovery file still work? =

Yes. The plugin also serves the discovery file at `https://yoursite.com/?xpay_route=acp`. AI shoppers that respect the `Link` header find this fallback automatically. If your host is interfering, contact them — many hosts (especially those that handle ACME challenges themselves) intercept `/.well-known/*` before WordPress sees it.

= I have multiple WooCommerce stores. Do I install xpay on each? =

Yes. Each store gets its own merchant slug and its own catalog feed. Pricing applies per store.

= Is the source code available? =

Yes. GPLv2-or-later, public repo at [github.com/xpaysh/agentic-commerce-for-woocommerce](https://github.com/xpaysh/agentic-commerce-for-woocommerce).

= How much does this cost? =

Free until your first AI-attributable sale. After that, pricing starts at 1% of AI-attributable order value. See [www.xpay.sh/pricing/](https://www.xpay.sh/pricing/?tab=agentic-commerce).

= Does xpay work with WooCommerce Subscriptions / WooCommerce Bookings / WooCommerce Memberships? =

The plugin publishes simple, variable, and grouped products in v0.1. Subscriptions, bookings, and memberships are on the roadmap — track progress in [the GitHub repo](https://github.com/xpaysh/agentic-commerce-for-woocommerce).

= I have a question that isn't answered here. =

Email merchants@xpay.sh or open an issue at [github.com/xpaysh/agentic-commerce-for-woocommerce/issues](https://github.com/xpaysh/agentic-commerce-for-woocommerce/issues).

== External services ==

This plugin connects to the following xpay-operated services to deliver its core function. Every endpoint and its purpose is documented; full payload disclosure is in the [Privacy](https://install.xpay.sh/woocommerce/privacy.html) section.

1. **agent-feed.xpay.sh** — Public CDN that hosts your AI-readable catalog feed at `https://agent-feed.xpay.sh/catalog/{your-slug}.json`. The plugin does not contact this URL directly; the xpay backend writes it from your WooCommerce REST API after you click **Connect store**.

2. **agent-commerce.xpay.sh** — The agent-side API that AI shopping agents call to surface and buy from your products. The plugin contacts this host at two paths: (a) `POST /v1/onboard/woocommerce/start` to register a one-time nonce when you click **Connect store**; (b) `POST /v1/merchants/{slug}/resync` to trigger a fresh catalog ingest after a product or stock change.

3. **app.xpay.sh/onboard/woocommerce** — The merchant-side onboarding page opened in a new tab when you click **Connect store**. You sign in or sign up on xpay and grant the WooCommerce REST API permission there.

4. **install.xpay.sh** — Auto-update channel (manifest + zip) for sites that installed the plugin from xpay's website instead of WordPress.org. WordPress.org installs use WP.org's native update system and never contact this host.

5. **agent-commerce.xpay.sh/v1/events** — Optional anonymous telemetry. Disabled by default; only contacted if you explicitly opt in via the first-activation admin notice or **Settings → xpay → Privacy**. Full payload disclosure in the Privacy section.

Terms of use: [install.xpay.sh/woocommerce/terms.html](https://install.xpay.sh/woocommerce/terms.html)
Privacy policy: [install.xpay.sh/woocommerce/privacy.html](https://install.xpay.sh/woocommerce/privacy.html)

== Privacy ==

xpay is built non-custodially: we never see your customers, your orders, or any payment data. Concretely:

* **Always sent after you click Connect store** (required for the plugin to work): your site URL, your WooCommerce REST API consumer key/secret (so xpay can read the product catalog), and your public product fields (name, description, price, stock, image URLs, categories). No customer data. No order data. No payment data.

* **Optionally sent if you opt in to anonymous telemetry** (default OFF): lifecycle event names tagged with your site URL, plugin version, WP version, WC version, PHP version, locale. No customer data, no order data, no PII.

* **Opt out of anonymous telemetry**: **Settings → xpay → Privacy → Turn off**. Or define `XPAY_WC_TELEMETRY` to `false` in `wp-config.php` for a system-wide hard disable that overrides any UI choice.

* **Request data deletion**: email privacy@xpay.sh from your admin email with your merchant slug. We process within 7 business days.

Full data-handling disclosure: [install.xpay.sh/woocommerce/privacy.html](https://install.xpay.sh/woocommerce/privacy.html).

== Screenshots ==

1. One-click connect — your existing WooCommerce checkout stays untouched.
2. Eight audit checks, all green: catalog feed, JSON-LD, llms.txt, per-protocol endpoints, robots allowlist, BuyAction, cart deeplink, fresh inventory.
3. Your catalog, live on agent-feed.xpay.sh — real prices, real stock, refreshed within 30 seconds of any product change.
4. AI chat → your existing checkout: ChatGPT or Claude surfaces your product, buyer lands on your cart pre-filled.
5. JSON-LD on every product page including BuyAction — view-source proof.

== Upgrade Notice ==

= 0.2.1 =
Tighter alignment between what /llms.txt advertises and what's actually serving. The `Commerce protocols` section is now backend-driven: only protocols the xpay backend has confirmed live for your store appear; the catalog feed and cart deeplink (live today) always show up. Companion change on the xpay backend: protocol-prefixed URLs at agent-commerce.xpay.sh now answer with a spec-shaped 501 Not Implemented envelope (with `retry_after_seconds` and a `docs` link) instead of a bare 404 — agents following a URL get a structured signal that the service is provisioned but not yet accepting requests.

= 0.2.0 =
Aligned with the real commerce standards (ACP, UCP, AP2) and the real discovery conventions (llms.txt, schema.org JSON-LD, robots.txt allowlist). NEW: serves `/.well-known/ucp` — the UCP business profile (spec 2026-04-08) that Google, Shopify, Etsy, Wayfair, Target and Walmart fetch for capability negotiation. Discovery layer is now an extensible registry: new emitters plug in without touching the rest of the plugin. Optional watchlist emitters added for /.well-known/oauth-protected-resource (RFC 9728) and /.well-known/agent-card.json (A2A 1.0) — off by default. Per-protocol endpoints (ACP / UCP / AP2 / MCP) are advertised in /llms.txt and hosted on xpay infra so checkout reach grows as agents adopt each protocol.

= 0.1.12 =
Plugin RENAMED to "Agentic Commerce for WooCommerce" (slug `agentic-commerce-for-woocommerce`). The previous name "xpay for WooCommerce" was rejected by WordPress.org as too similar to the long-established "Nexi XPay" payment plugin. New name describes the actual category clearly and avoids any trademark/similarity concern. Same product, same brand owner (xpay), same code. Main plugin file, text domain, admin URLs, .pot file path all updated.

= 0.1.11 =
Plugin Check (PCP) follow-up: cleared the four remaining PrefixAllGlobals warnings. uninstall.php now runs in a closure (no top-level globals). schema renderer uses a local $xpay_product var without touching WC's template global. WooCommerce-active check uses raw option lookup + class_exists (no apply_filters on a core hook). No functional changes.

= 0.1.10 =
Plugin Check (PCP) submission-readiness pass: tested up to WP 6.9; short description trimmed to ≤150 chars; removed deprecated load_plugin_textdomain() call (WP auto-loads WP.org-hosted translations since 4.6); excluded non-canonical markdown files from the plugin zip. No functional changes.

= 0.1.9 =
Docs moved from docs.xpay.sh/products/woocommerce → docs.xpay.sh/merchants/woocommerce so the path matches the actual audience (merchants, not products). Future Shopify / BigCommerce integrations will live as siblings under /merchants/. No code changes; URL-only.

= 0.1.8 =
Pricing link updated. Punchier opening pitch in the Description. Full setup walkthroughs with screenshots published at docs.xpay.sh/merchants/woocommerce — readme now backlinks them at the right moments (install / REST API keys / connect / audit / troubleshooting / privacy).

= 0.1.7 =
Source repo at github.com/xpaysh/agentic-commerce-for-woocommerce is now public. Restored repo link references in readme.txt FAQ and source-code section so reviewers and merchants can browse the source directly.

= 0.1.6 =
Removed GitHub link references from readme.txt to avoid a broken-link impression for reviewers (source repo is currently private). Source is still GPLv2-or-later via the unminified plugin zip.

= 0.1.5 =
Adds /?xpay_route=acp query-arg fallback for the discovery file on hosts that intercept /.well-known/. Post-activation redirect now also fires for upgrades where the store hasn't connected yet. WC HPOS compatibility declared. Privacy + terms pages live.

== Changelog ==

The full machine-readable changelog lives at [install.xpay.sh/woocommerce/CHANGELOG.md](https://install.xpay.sh/woocommerce/CHANGELOG.md) (Keep-a-Changelog format). The summary below is the WP.org-required mirror.

= 0.2.1 =
* `/llms.txt` `## Commerce protocols` section is now gated on the `xpay_wc_protocol_endpoints` wp_option (backend-pushed during Connect). Agents that follow a URL from `/llms.txt` reach a working service or a structured 501 — never a bare 404.
* Companion: backend stubs at `agent-commerce.xpay.sh/{ucp,acp,ap2,mcp}/...` now return a 501 Not Implemented envelope with `protocol`, `spec`, `merchant_slug`, `status`, `retry_after_seconds`, and a `docs` link.
* Filter `xpay_wc_protocol_endpoints` lets a mu-plugin override.

= 0.2.0 =
* **Aligned with the open commerce standards.** Per-protocol surfaces (ACP, UCP, AP2, MCP) are now advertised in `/llms.txt` and hosted on xpay infrastructure. The plugin keeps the merchant's domain to what genuinely belongs there: discovery files, JSON-LD, robots.txt allowlist.
* **NEW: `/.well-known/ucp` (UCP business profile, spec 2026-04-08).** This is the file Google, Shopify, Etsy, Wayfair, Target and Walmart fetch to negotiate capabilities with your store. The plugin generates a sensible default profile pointing at xpay-hosted UCP service endpoints; commercial-tier merchants can override the body + inject JWK signing keys via the `xpay_wc_ucp_profile` and `xpay_wc_ucp_signing_keys` options.
* **Discovery layer is an extensible emitter registry.** Each standard (`/llms.txt`, `/.well-known/ucp`, RFC 9728 OAuth metadata, A2A agent-card) is a registered emitter with a default-on/default-off flag and per-merchant override. Adding a new standard means adding a new emitter — no changes elsewhere in the plugin.
* **Added watchlist emitters (off by default):**
  * `/.well-known/oauth-protected-resource` (RFC 9728) — turns on automatically when UCP OAuth Identity Linking is enabled for the merchant on the xpay side.
  * `/.well-known/agent-card.json` (A2A 1.0, IANA-registered 2025-08-01) — opt-in via the `xpay_wc_emit_agent_card` option once A2A adoption matures in commerce.
* **`/llms.txt` content refresh.** Now links the agent-readable catalog, the per-protocol endpoints (ACP / UCP / AP2 / MCP), the cart-deeplink template, and top product categories. Markdown structure follows the llmstxt.org convention.
* **Admin readiness checklist updated** to reflect the standards-based architecture — the "AI assistants know where to send a buyer" row now points at the per-protocol endpoints listed in `/llms.txt`, not at a single discovery file.
* **No breaking changes for merchants.** Cart deeplink, catalog feed, JSON-LD injection, robots.txt allowlist, telemetry pipe and the WC REST onboarding flow are unchanged. The audit-readiness pills continue to all turn green after Connect.

= 0.1.12 =
* **Plugin renamed to "Agentic Commerce for WooCommerce"** (slug `agentic-commerce-for-woocommerce`).
  * Why: the previous name "xpay for WooCommerce" was rejected at WordPress.org submission as too similar to **Nexi XPay** (an established Italian payment-gateway plugin for WC by Nexi Payments, ~6,000 installs since 2017). WordPress.org's similarity check matches on the brand string regardless of category, and Nexi has prior art.
  * What changed: Plugin Name header, Text Domain (`agentic-commerce-for-woocommerce`), main file name (`agentic-commerce-for-woocommerce.php`), `/languages/agentic-commerce-for-woocommerce.pot`, admin page slug, plugin folder name inside the zip. User-Agent header for outbound HTTP. Settings page H1.
  * What didn't change: the product, the architecture, the xpay brand identity (still the author + still in admin nav as "xpay"), backend services (`agent-feed.xpay.sh`, `agent-commerce.xpay.sh`, etc.), or anything else functional.

= 0.1.11 =
* Cleared 4 PCP `PrefixAllGlobals` warnings:
  * `uninstall.php` now runs in an anonymous-closure IIFE — no top-level `$option_keys` / `$key` globals.
  * `class-xpay-schema.php :: render_product()` uses a local `$xpay_product` and skips the `global $product` declaration entirely. Direct `wc_get_product(get_the_ID())` works on PDPs without WC's template-loop side effect.
  * `class-xpay-plugin.php :: woocommerce_active()` uses raw `get_option('active_plugins')` + multisite merge + `class_exists('WooCommerce')` instead of filtering WP core's `active_plugins` hook. Same behavior, no false-positive.

= 0.1.10 =
* **Tested up to WordPress 6.9** (PCP flagged 6.7 as below current). No code changes — verified compatibility on a real WC 9.x install.
* **Short description trimmed** to 141 chars (PCP cap is 150).
* **Removed `load_plugin_textdomain()` call.** Discouraged since WP 4.6 — WordPress.org-hosted plugins get translations loaded automatically by core via the plugin slug.
* **Excluded non-canonical markdown files** (`INSTAWP_TEST_WALKTHROUGH.md`, `README.md`) from the release zip. The plugin zip should only contain files needed at runtime; READMEs and walkthroughs are repo-only.

= 0.1.9 =
* Documentation URLs migrated `docs.xpay.sh/products/woocommerce/*` → `docs.xpay.sh/merchants/woocommerce/*`. Merchants is the bucket; WooCommerce is one (of many future) integrations inside it. Future Shopify / BigCommerce docs will live as siblings.
* No plugin functionality changed — readme + admin-UI links updated.

= 0.1.8 =
* Punchier Description hero — leads with the buyer-side framing ("Your next customer is asking ChatGPT, not Google") instead of an abstract claim.
* Pricing link updated everywhere to `https://www.xpay.sh/pricing/?tab=agentic-commerce`.
* New documentation site at [docs.xpay.sh/merchants/woocommerce](https://docs.xpay.sh/merchants/woocommerce) — multi-page walkthrough covering install, WC REST API key generation, connect flow, privacy & telemetry, audit readiness checklist, and a troubleshooting guide. readme backlinks the docs at the right moments.
* GitHub backlinks throughout the readme + FAQ (issue tracker, source browse).

= 0.1.7 =
* `xpaysh/xpay-for-woocommerce` GitHub repo flipped public. Restored repo link references in readme.txt FAQ and "Source code" section so reviewers and merchants can browse the unminified source directly. GPLv2-or-later unchanged.

= 0.1.6 =
* Removed GitHub repo link references from readme.txt to avoid a broken-link impression for reviewers (the source repo was private). Plugin is still GPLv2-or-later — the zip is the canonical, unminified source.

= 0.1.5 =
* Query-arg fallback for the discovery file: hosts that intercept `/.well-known/` (some shared hosts, CDN edges, ACME setups) can now serve the discovery file at `/?xpay_route=acp`. Discoverable via the `Link` header on the home page.
* Post-activation redirect to **Settings → xpay** now fires on any activation when the store hasn't connected yet, not only on the very first activation. Skipped on bulk-activate.

= 0.1.4 =
* WC HPOS + Cart/Checkout Blocks compatibility declared.
* First-activation redirect to Settings → xpay.
* Privacy + Terms pages at install.xpay.sh/woocommerce/{privacy,terms}.html.
* Plugin URI: xpay.sh/sellers/woocommerce → www.xpay.sh/merchants/woocommerce/.

= 0.1.3 =
* PHPCS WordPress-standard clean: 0 errors / 1 cosmetic warning.
* `phpcs.xml.dist` ruleset added.
* `languages/xpay-for-woocommerce.pot` generated.
* WP.org listing assets (banner / icon / 5 screenshots).

= 0.1.2 =
* Slug renamed `xpay-woocommerce` → `xpay-for-woocommerce` (Guideline 17).
* Telemetry now opt-in via first-activation admin notice; default OFF (Guideline 7).
* Settings → xpay → Privacy toggle.
* readme.txt External services and Privacy sections added.

= 0.1.1 =
* Fire-and-forget lifecycle telemetry pipe (was always-opt-out; reworked to opt-in in 0.1.2).

= 0.1.0 =
* Initial release. WP plugin scaffold; /llms.txt and /.well-known/agentic-commerce.json; JSON-LD on PDP / shop / home; robots.txt allowlist; cart-deeplink handler; webhook-driven resync; admin page with connect flow and audit-readiness checklist.
