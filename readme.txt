=== xpay for WooCommerce ===
Contributors: xpaysh
Tags: woocommerce, ai, chatgpt, agentic commerce, llms
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.4
Stable tag: 0.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Put your WooCommerce catalog inside ChatGPT, Claude, Gemini and Perplexity. AI shoppers see live prices and stock, and complete the purchase through your existing checkout.

== Description ==

**Shopping is moving into AI chat.** Buyers ask ChatGPT, Gemini, Claude and Perplexity what to buy long before they touch a search engine — and most WooCommerce stores are invisible in those conversations.

**xpay for WooCommerce fixes that** with one plugin, no theme changes, no replatforming, and no new payment processor.

= What it does =

* **Publishes a public, agent-readable product feed** — your full catalog with live prices and stock, hosted on xpay's CDN (no extra load on your origin).
* **Adds AI-shopping JSON-LD** — `Product`, `Offer`, `AggregateOffer`, `BuyAction` and `ItemList` schemas on product pages, shop archive and home page. Detects existing schema from Yoast / Rank Math / WooCommerce core and only fills the gaps.
* **Serves the agent discovery files** — `/llms.txt` and `/.well-known/agentic-commerce.json` on your own domain. These are the conventions AI shopping agents look for.
* **Allows the right bots** — GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, Claude-SearchBot, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended and CCBot. Never overrides your existing robots.txt rules.
* **Cart deep-link** — AI agents create a one-click "Buy" link that pre-fills your existing WooCommerce cart and lands the buyer on your existing checkout. Orders are tagged with `_xpay_agent_attribution` so you can attribute AI-driven revenue in your existing reporting.
* **Live inventory** — webhook-driven catalog refresh on every product / stock change (debounced 30s), plus an hourly safety-net poll.

= What it doesn't do =

* **It doesn't touch your checkout.** Stripe / WooPayments / PayPal / Square / whatever you already use — payment runs through them, unchanged. Your payout schedule is unchanged.
* **It doesn't see your customers.** No buyer names, emails, addresses, IPs, payment cards, order line items, refunds, or PII of any kind passes through xpay. Ever. The plugin is non-custodial.
* **It doesn't require a new account or contract** to start. Free until your first AI-attributable sale; pricing kicks in after that. [See pricing](https://www.xpay.sh/pricing/).
* **It doesn't slow down your site.** The JSON-LD block is tiny and cached; the catalog feed is served from xpay's CDN, not your origin.

= Five-minute install flow =

1. Install the plugin from this directory or upload the zip.
2. Activate. You'll be taken to **Settings → xpay**.
3. Click **Connect store**. A new tab opens at app.xpay.sh, where you grant a read-only WooCommerce REST API key.
4. Your catalog goes live on AI surfaces within about 10 minutes. The plugin's built-in audit-readiness checklist turns green as each piece confirms.

= Compatibility =

* WooCommerce 7.0+ on WordPress 6.2+ and PHP 7.4+.
* Declares compatibility with WooCommerce High-Performance Order Storage (HPOS) and Cart/Checkout Blocks.
* Works alongside Yoast SEO, Rank Math, WooCommerce Blocks, WooPayments, Stripe for WooCommerce, and the standard Storefront / Astra / Divi / Elementor themes.

= Privacy and consent =

* **Anonymous lifecycle telemetry is off by default.** On first activation a single admin notice asks once. Pick "No thanks" and the plugin never contacts our backend for analytics. Pick "Enable" and you can change your mind any time under **Settings → xpay → Privacy**. System-wide opt-out via `define( 'XPAY_WC_TELEMETRY', false );` in `wp-config.php`.
* **Full data disclosure** at [install.xpay.sh/woocommerce/privacy.html](https://install.xpay.sh/woocommerce/privacy.html) — every byte the plugin sends, when it sends it, how to opt out, how to request deletion.

= Source code and contributing =

The plugin source is published under GPLv2-or-later. The plugin zip you install is unminified and human-readable — you can read, fork, modify and redistribute every line under that license. To report a bug or request a feature, email merchants@xpay.sh.

== Installation ==

= From the WordPress.org plugin directory =

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for "xpay for WooCommerce".
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

Yes. The plugin is GPLv2-or-later and the zip you install is unminified, human-readable source. To request specific files or report an issue, email merchants@xpay.sh.

= How much does this cost? =

Free until your first AI-attributable sale. After that, pricing starts at 1% of AI-attributable order value. See [www.xpay.sh/pricing/](https://www.xpay.sh/pricing/).

= Does xpay work with WooCommerce Subscriptions / WooCommerce Bookings / WooCommerce Memberships? =

The plugin publishes simple, variable, and grouped products in v0.1. Subscriptions, bookings, and memberships are on the roadmap.

= I have a question that isn't answered here. =

Email merchants@xpay.sh.

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
2. Eight audit checks, all green: catalog feed, JSON-LD, llms.txt, well-known, robots, BuyAction, cart deeplink, fresh inventory.
3. Your catalog, live on agent-feed.xpay.sh — real prices, real stock, refreshed within 30 seconds of any product change.
4. AI chat → your existing checkout: ChatGPT or Claude surfaces your product, buyer lands on your cart pre-filled.
5. JSON-LD on every product page including BuyAction — view-source proof.

== Upgrade Notice ==

= 0.1.6 =
Removed GitHub link references from readme.txt to avoid a broken-link impression for reviewers (source repo is currently private). Source is still GPLv2-or-later via the unminified plugin zip. Will re-add public repo link in a future release when the repo flips public.

= 0.1.5 =
Adds /?xpay_route=acp query-arg fallback for the discovery file on hosts that intercept /.well-known/. Post-activation redirect now also fires for upgrades where the store hasn't connected yet. WC HPOS compatibility declared. Privacy + terms pages live.

== Changelog ==

The full machine-readable changelog lives at [install.xpay.sh/woocommerce/CHANGELOG.md](https://install.xpay.sh/woocommerce/CHANGELOG.md) (Keep-a-Changelog format). The summary below is the WP.org-required mirror.

= 0.1.6 =
* Removed GitHub repo link references from readme.txt to avoid a broken-link impression for reviewers (the source repo is currently private). The plugin is still GPLv2-or-later — the zip you install is the canonical, unminified, human-readable source. Will re-add the public repo link in a future release once the repo flips public.

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
