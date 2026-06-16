=== Agentic Commerce for WooCommerce ===
Contributors: xpaysh
Tags: woocommerce, ai, chatgpt, agentic commerce, llms
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.8.1
Stable tag: 0.3.6
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
3. Click **Connect store**. You're redirected to app.xpay.sh, where you grant a read-only WooCommerce REST API key. ([how to generate one](https://docs.xpay.sh/merchants/woocommerce/rest-api-keys))
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
5. Approve the WooCommerce REST API permissions on app.xpay.sh, then you'll be redirected back to your store.

= System requirements =

* WordPress 6.2 or higher
* WooCommerce 7.0 or higher
* PHP 7.4 or higher
* SSL (`https://`) on the store domain — required for the agent discovery files to be honored by AI surfaces

== Frequently Asked Questions ==

= Does this slow down my site? =

No. The plugin emits a small `<script type="application/ld+json">` block in `<head>` on product pages, shop and home (only when no other plugin has already emitted equivalent schema). The discovery files (`/llms.txt`, `/.well-known/ucp`, `/.well-known/oauth-protected-resource`, `/.well-known/agent.json`) are served through WordPress rewrite rules with no database query. The catalog feed itself is hosted on xpay's CDN, not your origin — so AI shoppers reading it never load your origin.

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

2. **agent-commerce.xpay.sh** — The agent-side API that AI shopping agents call to surface and buy from your products. The plugin contacts this host at the following paths: (a) `POST /v1/onboard/woocommerce/wc-auth-callback` is the WooCommerce OAuth callback target (WordPress itself calls this on your behalf, server-to-server, after you approve the one-click connect prompt); (b) `GET /v1/onboard/woocommerce/status?nonce=…` is polled by the xpay onboarding page while the handshake finishes; (c) `POST /v1/merchants/{slug}/resync` triggers a fresh catalog ingest after a product or stock change; (d) `GET /v1/merchants/{slug}` is called when **Settings → xpay** verifies the current connection state; (e) `PATCH /v1/merchants/{slug}/products/{sku}` pushes a single-product delta when a WooCommerce product/stock webhook fires; (f) `DELETE /v1/merchants/{slug}` is sent (non-blocking) when you click **Disconnect** so xpay marks your account as disconnected and archives the cached catalog. The hostname is also the publicly advertised target for `POST /mcp/{slug}` (the JSON-RPC commerce MCP endpoint AI agents talk to) — the plugin itself does not call this URL but lists it in the `/.well-known/ucp` manifest.

3. **app.xpay.sh/onboard/woocommerce** — The merchant-side onboarding page. When you click **Connect store**, the plugin redirects your browser here with three query-string parameters: your site URL, your administrator email address, and a one-time random nonce generated locally. No data is sent to xpay before you click the button. You sign in or sign up on xpay and grant the WooCommerce REST API permission there.

4. **agent-commerce.xpay.sh/v1/events** — Optional anonymous lifecycle telemetry. Disabled by default; only contacted if you explicitly opt in via the first-activation admin notice or **Settings → xpay → Privacy**. Full payload disclosure in the Privacy section.

8. **agent-commerce.xpay.sh/v1/agent-analytics** — Optional anonymous AI-bot crawl analytics. Disabled by default; shares the same opt-in as item 4 (and respects a separate `define( 'XPAY_WC_AGENT_ANALYTICS', false )` hard-off). When enabled, the plugin counts requests from *known AI bots only* (e.g. GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot, Google-Extended) — recording the bot name, a coarse page type (home/product/category/discovery-file/sitemap/other), the HTTP status, and whether we routed the bot to your structured catalog. It also sends an aggregate daily count of human pageviews (a number only — no user-agent, no URLs, no per-visit data) as the AI-vs-human denominator. Events are buffered locally and sent in the background by WP-Cron, never on a page load. Cart, checkout, account, admin and REST paths are never recorded. No per-visit human data, no IP addresses, no customer, order, or personal data.

5. **audit.xpay.sh** — Merchant-facing audit dashboard. The plugin emits a link to `audit.xpay.sh/{your-slug}` on the Settings page so you can review the live agent-readiness score xpay computed from your catalog; the plugin itself does not fetch from this host. Opening the link from your browser sends standard browser headers to xpay.
6. **auth.xpay.sh** — Public OAuth-protected-resource discovery target. The plugin publishes `auth.xpay.sh` as the `authorization_servers[0]` entry in `/.well-known/oauth-protected-resource` (an RFC 9728 metadata document AI agents fetch to learn where to obtain a token). The plugin does not contact this host server-to-server; it is referenced for agent-side discovery only.
7. **install.xpay.sh/woocommerce/{terms,privacy}.html** — Static legal documents linked from this readme and from the Settings privacy panel. The plugin itself does not fetch these URLs; clicking the links opens them in your browser.

Terms of use: [install.xpay.sh/woocommerce/terms.html](https://install.xpay.sh/woocommerce/terms.html)
Privacy policy: [install.xpay.sh/woocommerce/privacy.html](https://install.xpay.sh/woocommerce/privacy.html)

== Privacy ==

xpay is built non-custodially: we never see your customers, your orders, or any payment data. Concretely:

* **Nothing leaves your site before you click Connect store.** The Settings → xpay page is pure markup — no outbound HTTP, no analytics ping, no nonce pre-registration.

* **Sent only after you click Connect store** (required for the plugin to work): your site URL, your administrator email address, a one-time random nonce, your WooCommerce REST API consumer key/secret (so xpay can read the product catalog), and your public product fields (name, description, price, stock, image URLs, categories). No customer data. No order data. No payment data.

* **Optionally sent if you opt in to anonymous telemetry** (default OFF): lifecycle event names tagged with your site URL, plugin version, WP version, WC version, PHP version, locale. No customer data, no order data, no customer PII.

* **Optionally sent if you opt in (default OFF), as part of the same telemetry consent — AI-bot crawl analytics**: for requests from *known AI bots only*, the bot name, a coarse page type (home/product/category/discovery-file/sitemap/other), the HTTP status, and whether the bot was routed to your structured catalog — tagged with your site URL. Also sent: an aggregate daily count of human pageviews (a number only — the AI-vs-human denominator). Never recorded: per-visit human data, IP addresses, query strings, cart/checkout/account/admin/REST paths, or any customer, order, or personal data. Hard-disable just this (while keeping lifecycle telemetry) with `define( 'XPAY_WC_AGENT_ANALYTICS', false );`.

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

= 0.3.6 =
New AI-bot crawl analytics: see which AI crawlers (GPTBot, ClaudeBot, Perplexity…) discover your store and whether they reach your discovery files, on your xpay dashboard. Opt-in, off by default, shares the telemetry consent. Known AI bots only — no human or customer data.

= 0.3.5 =
New /agents.md skill tells AI shopping agents exactly how to browse your live catalog and build a cart. Safer llms.txt merge that ignores non-text pages (no stray HTML from headless storefronts). Optional shopping-bot routing to your structured catalog, off until you enable it.

= 0.3.4 =
Friendlier Connect screen: plain-language value, no jargon, and an upfront promise that it's safe to try — the plugin writes no files to your site and fully reverts on deactivate. New panel lists every external service the plugin contacts.

= 0.3.3 =
New Tools → Run site diagnostics button checks that AI agents and xpay can reach your store (Permalinks, REST API, /.well-known/ discovery files) and tells you exactly what to fix if something is being blocked.

= 0.3.2 =
Plays nicely with any other AI/SEO plugin you already have. If your site already publishes its own `llms.txt`, this update adds our agent-shopping sections at the end instead of replacing your content. Smoother connect flow and a few quality-of-life polish items.

= 0.3.1 =
WP.org review fix: REST endpoints constructed via `rest_url()` instead of hardcoded `/wp-json`. Plus PCP polish (sanitization, i18n, uninstall cleanup) and expanded External services disclosure. See Changelog for full details.

= 0.3.0 =
Privacy hardening: Settings → xpay no longer contacts the xpay backend on load; outbound requests fire only after you click **Connect store**. Declares WC dependency via the WP 6.5 `Requires Plugins` header. See Changelog for full details.

= 0.2.4 =
Settings → xpay reorganised into five tabs (General, Capabilities, Payments, Links, Tools). Toggle UCP shopping capabilities, map your gateways to `payment_handlers[]`, and override the URLs in `ucp.links`. See Changelog for full details.

= 0.2.1 =
Tighter alignment between what /llms.txt advertises and what's actually serving — the Commerce protocols section is now backend-driven. See Changelog for the full backend-side changes.

= 0.2.0 =
Aligned with ACP / UCP / AP2 commerce standards and the real discovery conventions (llms.txt, schema.org JSON-LD, robots.txt allowlist). Serves `/.well-known/ucp` for capability negotiation. See Changelog for full details.

= 0.1.12 =
Plugin RENAMED to "Agentic Commerce for WooCommerce" (slug `agentic-commerce-for-woocommerce`). Same product, same code, name change only — previous name was rejected by WP.org as too similar to "Nexi XPay". See Changelog for full details.

= 0.1.11 =
Plugin Check (PCP) follow-up: cleared the four remaining PrefixAllGlobals warnings. No functional changes. See Changelog for full details.

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

= 0.3.5 =
* **New: `/agents.md` agent skill.** A dedicated, machine-readable skill served at `yourstore.com/agents.md` that tells AI shopping agents (and skill-using assistants) exactly how to connect to your store: browse the live catalog over MCP or REST, look products up, and build a cart that hands the shopper off to your existing checkout. This is purpose-written connect-and-transact instructions — not a copy of `/llms.txt`. Served only once your store is connected; like our other discovery files it skips quietly if you already publish your own `/agents.md`.
* **Safer `/llms.txt` merge (bug fix).** When appending to an existing `/llms.txt`, the plugin now ignores non-text responses. Hybrid/headless storefronts and single-page themes that answer every URL with an HTML page no longer get that page pulled in as bogus "existing content" above our agent-shopping sections.
* **Catalog link points at your branded agent surface.** The `/llms.txt` catalog link now uses your store's own agent-commerce surface instead of the shared feed host (same data, your brand).
* **Optional AI shopping-bot routing.** Realtime AI *shopping* assistants can be transparently routed to your structured catalog surface for cleaner, faster product data, while search/indexing crawlers stay on your store for citations. Centrally controlled and **off by default** — nothing changes for your site until xpay enables it for your store; it never affects humans, logged-in users, your cart, or checkout.

= 0.3.4 =
* **Friendlier Connect screen.** The pre-connect panel now leads with what you get — your products discoverable to ChatGPT, Claude, Gemini and Perplexity, with no code and no payment change — instead of a wall of acronyms. The technical detail (llms.txt, schema.org, ACP/UCP/AP2/MCP) is still there, moved to a small footnote.
* **Upfront safety promise.** The Connect screen now states plainly what this update verified end-to-end: the plugin never writes files to your site, doesn't touch your theme or payments, appends to any existing `/llms.txt` rather than replacing it, and fully reverts the moment you deactivate.
* **New transparency panel.** Once connected, the General tab lists every external service the plugin contacts (`agent-feed.xpay.sh`, `agent-commerce.xpay.sh`, `app.xpay.sh`) and what each receives, alongside the Terms and Privacy links.
* **Clearer telemetry opt-in.** The anonymous-diagnostics prompt now explains the benefit to you — we can catch a silently broken AI connection (failed sync, blocked endpoint) before your products drop out of ChatGPT, Claude and Perplexity. Still off by default, still no customer or order data, still changeable any time.

= 0.3.3 =
* **New: Run site diagnostics (Tools tab).** One click loopback-checks the three things that silently block a connection at the web-server layer before WordPress even runs: the WordPress REST API (Pretty Permalinks), the plugin's own REST routes, and the `/.well-known/` discovery files. Each check shows a pass/fail with the HTTP status, and a failure gives you the exact fix — e.g. switch Permalinks off "Plain", or (on Apache/ACME hosts that reserve `/.well-known/`) the query-arg fallback URL that agents can still use.
* **No behaviour change to the connect flow itself.** Diagnostics are network-only on the explicit button click; the Settings page still makes zero outbound calls on render.

= 0.3.2 =
* **Good neighbour with other AI/SEO plugins.** If your site already publishes its own `llms.txt` (for example via Yoast SEO AI, RankMath AI, AIOSEO, or your own setup), we now add our agent-shopping sections at the end of your file instead of replacing it. Everything you wrote is preserved exactly as you wrote it.
* **Automatic detection.** The plugin checks once a day whether another tool has started publishing one of the same discovery files we do. When it sees one, we step aside or append cleanly — no merchant action needed.
* **Smoother connect experience.** A handful of polish items in the Connect flow so the handshake is quicker and any stray retry doesn't show up as a misleading error.
* **Live updates from xpay.** Once you're connected, xpay can fine-tune small parts of your discovery setup (like an extra section of seasonal promotions on your `llms.txt`) without you needing to install another plugin update. Fully optional and reversible — nothing changes unless you tell us to or we both agree.

= 0.3.1 =
* **REST endpoints constructed via `rest_url()`.** The fallback UCP manifest now calls `rest_url('xpay/ucp/v1')` / `rest_url('xpay/mcp')` instead of hardcoding `home_url('/wp-json/...')`, so the plugin respects sites that customize the REST prefix. Per the WP.org Determining Locations guideline.
* **Tested against WordPress 7.0 + WooCommerce 10.8.1** on a clean sandbox with `WP_DEBUG=true`.

= 0.3.0 =
* **Privacy: no outbound calls on Settings page load.** The Connect panel no longer pre-registers a nonce with the xpay backend when the page renders. The nonce is generated, the attempt is stamped, and the merchant is redirected to the xpay onboarding flow only after the **Connect store** button is clicked. Matches WordPress.org's no-phoning-home guideline.
* **`Requires Plugins: woocommerce` header.** Declares the WooCommerce dependency via the WP 6.5 plugin-dependencies mechanism so the plugin won't activate without WooCommerce present. Existing manual activation check remains as defence-in-depth for pre-6.5 sites.
* **Inline admin script removed.** The Connect button no longer emits an inline `<script>` tag; click-time telemetry is recorded server-side in the redirect handler instead.

= 0.2.4 =
* **Tabbed Settings → xpay UI.** Five tabs replace the single-screen layout: **General** (status + slug + last sync + disconnect + telemetry opt-in), **Capabilities** (per-UCP-capability toggles), **Payments** (map enabled WC gateways to UCP `payment_handlers[]`), **Links** (auto-detect privacy/TOS/about/contact/shipping with per-row override), **Tools** (view UCP profile, view full audit, test connection, refresh catalog now, telemetry debug log toggle). URL is bookmarkable via `?tab=`.
* **Capability toggles wired into `/.well-known/ucp`.** Switching off any of `checkout` / `fulfillment` / `discount` / `order` removes the entry from the emitted UCP manifest. Default (no option set) = all enabled, so existing installs don't regress on upgrade.
* **`payment_handlers[]` now populated from the Payments tab.** Each enabled gateway emits as `{id, label, type:"merchant_gateway"}` so UCP-aware agents can negotiate payment surfaces against the methods you actually accept.
* **`ucp.links` array.** Privacy, TOS, About, Contact, Shipping URLs are auto-detected (WordPress privacy_policy_url + common page slugs) and overridable on the Links tab; emitted in the manifest as `{rel, href}` pairs.

= 0.2.3 =
* **MCP transport advertised in `/.well-known/ucp`.** Native MCP-speaking agents (Claude, ChatGPT Operator, Shopify AI Toolkit) discover the endpoint at `agent-commerce.xpay.sh/mcp/{slug}` without further configuration. Three tools available: `search_catalog` (BM25-ranked over title + description), `get_product` (lookup by SKU or numeric product ID), `create_cart` (returns a signed deeplink that pre-populates checkout on your store).
* **One-click connect via WooCommerce's `/wc-auth/v1/authorize` OAuth.** When you click **Connect store**, the xpay onboarding page opens WooCommerce's built-in approval popup. You approve there once and WordPress hands xpay read-only API credentials directly — no Settings → Advanced → REST API trip, no copy-paste. The manual paste flow remains available as a fallback.
* **Disconnect notifies the backend.** Clicking **Disconnect** now fires a non-blocking `DELETE /v1/merchants/{slug}` so your account is marked disconnected and the cached agent-feed catalog is archived. Local cleanup happens regardless of whether the backend acks.
* **UCP manifest fixes for strict-validator compliance:** `extends` is now an array (`["dev.ucp.shopping.checkout"]`) rather than a string per the 2026-04-08 spec; capability `spec` URLs are uniformly date-prefixed; `payment_handlers: []` placeholder added for parity with the ecosystem.
* **Clear apology + guidance when a Connect attempt fails.** If you start a Connect flow but the handshake doesn't complete, the Settings → xpay page now shows an explicit apology and a clear "click Connect again" CTA, and our ops team is notified automatically so we can recover if anything weird happens.

= 0.2.2 =
* **Fixed onboarding-finalize 500/timeout that left merchants in a half-provisioned state.** The Lambda now has a 15-second timeout and an 8s `AbortSignal` on the callback fetch into your WordPress site. The nonce is no longer burned until the plugin acknowledges receipt, so a re-click is always safe within the 30-minute TTL. The plugin-side `rest_finalize` is now idempotent on `(slug, api_key)` replay. Initial catalog resync moved to `wp_schedule_single_event` + non-blocking `wp_remote_post` so the REST response returns in under a second on hosts whose outbound HTTPS to xpay is slow.

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
