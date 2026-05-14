=== xpay for WooCommerce ===
Contributors: xpay
Tags: ai, chatgpt, agentic commerce, llms-txt, catalog feed
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Put your WooCommerce catalog inside ChatGPT, Claude, Gemini, and Perplexity. AI shoppers find your products, see live prices and stock, and complete the purchase through your existing checkout.

== Description ==

Shopping has moved into AI chat. Buyers ask ChatGPT, Gemini, Claude and Perplexity what to buy long before they touch a search engine — and most WooCommerce stores are invisible in those conversations.

xpay is the WordPress plugin that fixes that.

* Publishes a public, agent-readable product feed (your full catalog, with live prices and stock)
* Adds the `Product`, `Offer`, and `BuyAction` JSON-LD that AI shopping agents look for
* Serves `/llms.txt` and `/.well-known/agentic-commerce.json` — the discovery files every modern AI shopper checks
* Allows GPTBot, ClaudeBot, PerplexityBot and OAI-SearchBot through `robots.txt`
* Lets AI agents create a pre-filled cart deeplink that lands the buyer on your existing WooCommerce checkout
* Your existing payment gateway (Stripe, WooPayments, PayPal, Square) handles payment — payouts arrive exactly as they do for a normal online sale

What it doesn't do: touch your theme files, replace your checkout, hold your money, or require a new payment processor.

== Installation ==

1. Upload the `xpay-for-woocommerce` folder to `/wp-content/plugins/` (or install via WP admin > Plugins > Add New).
2. Activate the plugin.
3. Go to **Settings → xpay** and click **Connect store**.
4. Approve the WooCommerce REST API permissions when prompted.
5. Your catalog goes live across AI surfaces within ~10 minutes.

== Frequently Asked Questions ==

= Does this slow down my site? =
No. The plugin emits a small `<script type="application/ld+json">` block in `<head>` and a `/llms.txt` route. Both are cached. The catalog feed is hosted on xpay's CDN, not your origin.

= Does xpay see my customers' payment info? =
No. Payment runs through your existing WooCommerce gateway. xpay never touches checkout.

= What if I already have Yoast / Rank Math emitting Product schema? =
xpay detects the existing schema and only adds the bits it's missing (typically `BuyAction` on PDPs and `ItemList` on the homepage).

= Does the plugin send any data to xpay? =
Only what you opt in to. (a) The catalog feed sync sends your public product data (names, prices, stock, images) to xpay's CDN so AI surfaces can read it — this is required for the plugin's core function and is enabled when you click **Connect store**. (b) Optional anonymous telemetry (plugin activated, store connected, sync errors, etc.) is **off by default**. We ask once via an admin notice; you can change the choice any time under **Settings → xpay → Privacy**. No customer data, order data, or PII is ever sent. See the **External services** and **Privacy** sections below for the full list.

== External services ==

This plugin connects to xpay-operated services to deliver its core function. Every endpoint is documented here.

1. **agent-feed.xpay.sh** — The public, AI-readable catalog feed for your store at `https://agent-feed.xpay.sh/catalog/{your-slug}.json`. The plugin does **not** contact this URL directly; the xpay backend writes it from your WooCommerce REST API after you click **Connect store**. This URL is referenced from `/.well-known/agentic-commerce.json` on your domain so AI shoppers can discover it.

2. **agent-commerce.xpay.sh** — The agent-side API that AI shopping agents call when they want to surface or buy from your products. The plugin contacts `https://agent-commerce.xpay.sh/v1/{slug}/resync` to trigger a fresh catalog ingest after a product/stock change. The plugin also receives signed cart-deeplink redirects at `/?xpay_cart=<JWT>` which lands the buyer on your existing checkout.

3. **app.xpay.sh/onboard/woocommerce** — The merchant-side onboarding screen the **Connect store** button opens in a new tab. You sign in or sign up on xpay and approve the connection there.

4. **install.xpay.sh** — Auto-update channel (manifest + zip) for sites that installed the plugin from `xpay.sh/install/woocommerce` instead of WordPress.org. WordPress.org installs use WP.org's update system and never contact this host.

5. **agent-commerce.xpay.sh/v1/events** — Optional anonymous telemetry endpoint. Disabled by default. Only contacted if you explicitly opt in. See **Privacy** below for the exact payload.

Terms of use: https://xpay.sh/terms
Privacy policy: https://xpay.sh/privacy

== Privacy ==

xpay is built non-custodially: we never see your customers, your orders, or any payment data. Concretely:

* **What the plugin always sends** (after you click **Connect store** — required for the plugin to work): your site URL, your WooCommerce REST API consumer key/secret (so xpay can read the product catalog), and the public product fields (name, description, price, stock, image URLs, categories). No customer data. No order data. No payment data.

* **What the plugin optionally sends** (only if you opt in to the **anonymous telemetry** prompt — default OFF): lifecycle events tagged with your site URL, plugin version, WP version, WC version, PHP version, and locale. Event names include: `plugin_activated`, `plugin_deactivated`, `settings_viewed`, `connect_clicked`, `finalize_success`, `finalize_error`, `audit_rerun_clicked`, `audit_rerun_success`, `audit_rerun_error`, `disconnected`, `resync_success`, `resync_error`. No customer data, no order data, no PII.

* **How to opt out of anonymous telemetry:** open **Settings → xpay → Privacy** and click **Turn off**. Or define `XPAY_WC_TELEMETRY` to `false` in `wp-config.php` for a system-wide hard disable.

* **How to delete data:** Click **Disconnect** under **Settings → xpay**, then deactivate and delete the plugin. The plugin's `uninstall.php` removes its local options. To request deletion of catalog data from xpay's CDN, email privacy@xpay.sh.

== Screenshots ==

1. One-click connect — your existing checkout stays untouched.
2. Eight audit checks, all green: catalog feed, JSON-LD, llms.txt, well-known, robots, BuyAction, cart deeplink, fresh inventory.
3. Your catalog, live on agent-feed.xpay.sh — real prices, real stock.
4. AI chat → your existing checkout: ChatGPT/Claude surface your product, buyer lands on your cart pre-filled.
5. JSON-LD on every PDP including BuyAction — view-source proof.

== Upgrade Notice ==

= 0.1.3 =
Code quality pass for WordPress.org submission: PHPCS WordPress-standard clean, translation-ready (.pot), bundled banner / icon / screenshots. No functional changes.

= 0.1.2 =
Plugin slug renamed to `xpay-for-woocommerce` for WordPress.org submission compliance. Anonymous lifecycle telemetry is now opt-in (was opt-out). Reactivate the plugin after upgrade and choose your preference in the admin notice or under Settings → xpay → Privacy.

== Changelog ==

The full machine-readable changelog lives at <https://install.xpay.sh/woocommerce/CHANGELOG.md>
(Keep-a-Changelog format). The summary below is the WP.org-required mirror.

= 0.1.3 =
* WordPress-standard PHPCS pass: 0 errors / 1 cosmetic warning (down from 143/71). Real findings fixed: unslash + sanitize on `$_SERVER['REQUEST_URI']`, escape on /llms.txt output, short-ternary removal, Yoda conditions, nonce-verification annotations on the cart-deeplink + ajax beacon paths (both authenticated by signed JWT / capability check respectively).
* Added `phpcs.xml.dist` ruleset (WordPress standard, custom-cap allowlist for `manage_woocommerce`, text-domain pinned to `xpay-for-woocommerce`).
* Added `languages/xpay-for-woocommerce.pot` (generated via `wp i18n make-pot`).
* Added WP.org `assets/`: `banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png`, `icon-256x256.png`, `screenshot-1.png` through `screenshot-5.png`. Excluded from the plugin zip (live in SVN `assets/` after WP.org approval).

= 0.1.2 =
* Renamed plugin slug from `xpay-woocommerce` to `xpay-for-woocommerce` to comply with WordPress.org Guideline 17 (trademark — non-WooCommerce vendors cannot have a slug starting with "woocommerce").
* Anonymous telemetry is now **opt-in** (was opt-out) — required for WordPress.org Guideline 7 (informed consent for external server contact). First-activation admin notice asks for consent; default is off.
* Added Settings → xpay → Privacy toggle so merchants can change their telemetry choice any time.
* Added `== External services ==` and `== Privacy ==` sections to readme per Guideline 6.

= 0.1.1 =
* Fire-and-forget lifecycle telemetry: activate, deactivate, settings_viewed, connect_clicked, finalize_success/error, audit_rerun_success/error, disconnected, resync_success/error.

= 0.1.0 =
* WordPress plugin scaffold targeting WC 7.0+ / WP 6.2+ / PHP 7.4+.
* Serves /llms.txt and /.well-known/agentic-commerce.json on the merchant's domain.
* Injects Product / Offer / BuyAction / ItemList JSON-LD on PDP, shop, and homepage; detects and respects pre-existing schemas from Yoast / Rank Math / WC core.
* robots.txt allowlist for GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended and similar (never overrides explicit merchant blocks).
* Cart-deeplink handler (`?xpay_cart=`) populates WC()->cart from a signed JWT and redirects to wc_get_checkout_url(); orders tagged with _xpay_agent_attribution meta.
* Webhook-driven catalog resync on product / stock changes, debounced 30s.
* Admin page at Settings → xpay: connect flow, status panel, re-run audit, audit-readiness checklist.
* Optional [xpay-buy] shortcode + Gutenberg block (off by default).
