=== xpay for WooCommerce ===
Contributors: xpay
Tags: woocommerce, ai, chatgpt, agentic commerce, llms.txt
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.1
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

1. Upload the `xpay-woocommerce` folder to `/wp-content/plugins/` (or install via WP admin > Plugins > Add New).
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

== Changelog ==

The full machine-readable changelog lives at <https://install.xpay.sh/woocommerce/CHANGELOG.md>
(Keep-a-Changelog format). The summary below is the WP.org-required mirror.

= 0.1.1 =
* Fire-and-forget lifecycle telemetry: activate, deactivate, settings_viewed, connect_clicked, finalize_success/error, audit_rerun_success/error, disconnected, resync_success/error. Opt-out via `define( 'XPAY_WC_TELEMETRY', false )` in wp-config.

= 0.1.0 =
* WordPress plugin scaffold targeting WC 7.0+ / WP 6.2+ / PHP 7.4+.
* Serves /llms.txt and /.well-known/agentic-commerce.json on the merchant's domain.
* Injects Product / Offer / BuyAction / ItemList JSON-LD on PDP, shop, and homepage; detects and respects pre-existing schemas from Yoast / Rank Math / WC core.
* robots.txt allowlist for GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended and similar (never overrides explicit merchant blocks).
* Cart-deeplink handler (`?xpay_cart=`) populates WC()->cart from a signed JWT and redirects to wc_get_checkout_url(); orders tagged with _xpay_agent_attribution meta.
* Webhook-driven catalog resync on product / stock changes, debounced 30s.
* Admin page at Settings → xpay: connect flow, status panel, re-run audit, audit-readiness checklist.
* Optional [xpay-buy] shortcode + Gutenberg block (off by default).
