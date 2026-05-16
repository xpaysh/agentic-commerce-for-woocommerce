# Agentic Commerce for WooCommerce

WordPress plugin. Puts a WooCommerce store inside ChatGPT, Claude, Gemini, and Perplexity — and routes the sale through the merchant's existing WC checkout. No theme changes, no new payment processor.

Multi-protocol from day one — speaks **[ACP](https://github.com/agentic-commerce-protocol/agentic-commerce-protocol)**, **[UCP](https://github.com/Universal-Commerce-Protocol/ucp)**, and **[AP2](https://github.com/google-agentic-commerce/AP2)** alongside the discovery standards every AI agent reads ([llms.txt](https://llmstxt.org), schema.org JSON-LD, `robots.txt` for real AI crawlers). Rail-agnostic — your existing WC payment gateway (Stripe / WooPayments / PayPal / Square / etc.) handles the money.

This plugin is the **reference implementation** for the broader [`agentic-commerce-for-*`](https://github.com/xpaysh?q=agentic-commerce-for-) family. The shared [plugin template](https://github.com/xpaysh/agentic-commerce-plugin-template) is being extracted from this codebase for sibling platforms (BigCommerce, commercetools, Magento, Shopify-app, Salesforce Commerce, and a long-tail of community plugins). See the curated [awesome-agentic-commerce](https://github.com/xpaysh/awesome-agentic-commerce) registry for the ecosystem.

## What ships in v0.1

The plugin is built around the checks in `scripts/seller-audit/audit.py` (the audit emailed to prospects). Each subsystem flips one or more checks to green.

| File | Audit checks satisfied |
|---|---|
| `includes/class-xpay-rest.php` | `ai_guide` (`/llms.txt`) |
| `includes/class-xpay-robots.php` | `agents_allowed` (GPTBot / ClaudeBot / Google-Extended / PerplexityBot / CCBot / OAI-SearchBot / Amazonbot allow blocks) |
| `includes/class-xpay-schema.php` | `live_pricing` (`Product` + `Offer`), `direct_buy` (`BuyAction`) on PDP / shop / home |
| `includes/class-xpay-cart.php` | `in_chat_checkout` (signed-JWT cart deeplink → pre-filled WC cart → checkout) |
| `includes/class-xpay-webhooks.php` | `fresh_inventory` (resync on product / stock change, 30s debounce) |
| `includes/class-xpay-settings.php` | Connect / status / re-run audit; mirrors audit checklist in admin UI |
| `includes/class-xpay-widget.php` | Optional `[xpay-buy]` shortcode + Gutenberg block (default OFF) |

The remaining check, `product_feed`, is satisfied by the backend at `agent-feed.xpay.sh/catalog/{slug}.json` — see `../backend/wc-plugin-setup/`.

## Activation flow

1. Merchant installs the plugin (zip from `xpay.sh/install/woocommerce` or WP.org once listed).
2. Settings → xpay → **Connect store**. Opens `app.xpay.sh/onboard/woocommerce` with a nonce.
3. Merchant approves on app.xpay.sh; xpay backend calls the plugin's `/wp-json/xpay/v1/finalize` route with `merchant_slug` + `api_key`.
4. Plugin pings `api.xpay.sh/v1/merchants/{slug}/resync` to seed the catalog feed.
5. Every audit row in **Settings → xpay → Audit readiness** flips to ✓ Ready.

## Local development

```bash
# Inside a WP dev environment:
ln -s /Users/sri/Documents/Dev/mvp/xpay-woocommerce /path/to/wp-content/plugins/xpay-woocommerce
# In wp-config.php (dev only):
define( 'XPAY_WC_API_BASE_OVERRIDE', 'https://api.xpay.sh' );  # or http://localhost:4000
define( 'XPAY_WC_AGENT_COMMERCE_OVERRIDE', 'https://agent-commerce.xpay.sh' );
```

Verify after activation:

```bash
curl -s https://your-site.test/llms.txt
curl -s https://your-site.test/robots.txt | grep -iE 'GPTBot|ClaudeBot|Google-Extended|PerplexityBot|CCBot'
# Product JSON-LD on a PDP:
curl -s https://your-site.test/product/some-slug/ | grep -A1 'application/ld\+json' | head
# After connect:
python /path/to/mvp/scripts/seller-audit/audit.py --url https://your-site.test
# All audit checks should report pass.
```

## Distribution

CDN: `install.xpay.sh` (S3 + CloudFront, isolated from `widget.xpay.sh` so the
existing chat-widget publishers are unaffected by any plugin-release activity).

```
https://install.xpay.sh/woocommerce/latest.zip                    ← stable alias
https://install.xpay.sh/woocommerce/xpay-woocommerce-{ver}.zip    ← versioned
https://install.xpay.sh/woocommerce/manifest.json                 ← auto-update channel
```

Per `docs/may-13/appstore-distribution-strategy.md`:

1. Self-host zip via the URLs above from day 1 (no review queue). Marketing
   landing page at `xpay.sh/sellers/woocommerce` links to `latest.zip`.
2. Submit to wordpress.org plugin directory in parallel (2–4 weeks review).
3. Defer woocommerce.com Marketplace (5% rev share) until post-validation.

## Releasing a new version

```bash
# from repo root
cd /Users/sri/Documents/Dev/mvp
VERSION=0.1.1  # bump in xpay-woocommerce.php Plugin header AND readme.txt Stable tag first
rm -f /tmp/xpay-woocommerce.zip
zip -qr /tmp/xpay-woocommerce.zip xpay-woocommerce \
  -x 'xpay-woocommerce/.git*' -x 'xpay-woocommerce/node_modules/*' -x 'xpay-woocommerce/.DS_Store'

aws --profile agentically s3 cp /tmp/xpay-woocommerce.zip \
  s3://xpay-install/woocommerce/xpay-woocommerce-${VERSION}.zip \
  --content-type application/zip --cache-control 'public, max-age=300'
aws --profile agentically s3 cp /tmp/xpay-woocommerce.zip \
  s3://xpay-install/woocommerce/latest.zip \
  --content-type application/zip --cache-control 'public, max-age=60'

# update manifest.json with the new version, then:
aws --profile agentically cloudfront create-invalidation \
  --distribution-id E17RH4LQHPUH1Q \
  --paths '/woocommerce/latest.zip' '/woocommerce/manifest.json'
```

## Versioning

Semver. The bundled `xpay-woocommerce.php` header carries `Version:`. WP.org
reads it; self-host installs read it via `https://install.xpay.sh/woocommerce/manifest.json`
for the auto-update channel.

## See also

- [Plugin template](https://github.com/xpaysh/agentic-commerce-plugin-template) — shared core for the `agentic-commerce-for-*` family
- [awesome-agentic-commerce](https://github.com/xpaysh/awesome-agentic-commerce) — curated ecosystem registry
- [ACP vs UCP vs AP2 — Technical Comparison](https://docs.xpay.sh/agentic-commerce-protocols/comparison) on docs.xpay.sh
- Setup guide on docs.xpay.sh: [/merchants/woocommerce](https://docs.xpay.sh/merchants/woocommerce)
- xpay.sh commercial overview: [/merchants/woocommerce/](https://www.xpay.sh/merchants/woocommerce/)
