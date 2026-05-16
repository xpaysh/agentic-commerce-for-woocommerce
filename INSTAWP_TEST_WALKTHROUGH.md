# InstaWP end-to-end test walkthrough

A real-world smoke test of the xpay-woocommerce plugin against a live WP+WC sandbox at InstaWP.

**You'll need:**
- A free InstaWP account (instawp.com — no card required)
- `xpay-app` running locally on `http://localhost:8082` (so the onboard handoff page loads)
- The plugin zip: `/tmp/xpay-woocommerce.zip` (regenerate with the command in §0 if missing)
- Plus your computer's terminal for ~3 CLI calls

Total time: ~12 minutes.

---

## 0. (Optional) Regenerate the plugin zip

```bash
cd /Users/sri/Documents/Dev/mvp
rm -f /tmp/xpay-woocommerce.zip
zip -qr /tmp/xpay-woocommerce.zip xpay-woocommerce \
  -x 'xpay-woocommerce/.git*' -x 'xpay-woocommerce/node_modules/*' -x 'xpay-woocommerce/.DS_Store'
ls -lh /tmp/xpay-woocommerce.zip
```

---

## 1. Spin up InstaWP sandbox (~90 seconds)

1. Go to **https://instawp.com/dashboard/sites**
2. Click **Create New Site → From template**
3. Pick the **WooCommerce** template (pre-installs WC + sample products)
4. Wait ~60s. You'll get:
   - **Site URL**: e.g. `https://store-xyz12.instawp.xyz`
   - **Admin URL**: `https://store-xyz12.instawp.xyz/wp-admin/`
   - **Admin credentials** (auto-filled in the InstaWP UI — click "Magic login")

Click **Magic login** to land in `wp-admin` already authenticated.

---

## 2. Configure plugin for DEV backend

Before installing the plugin, drop two constants into `wp-config.php` so the plugin talks to dev (instead of prod's `app.xpay.sh`):

1. InstaWP dashboard → your site → **File Manager** (or Code Editor)
2. Open `wp-config.php` in the site root
3. Above the line `/* That's all, stop editing! Happy publishing. */`, paste:

```php
/** xpay dev backend (remove these two lines to use prod). */
define( 'XPAY_WC_API_BASE_OVERRIDE', 'https://dev-agent-commerce.xpay.sh' );
define( 'XPAY_WC_AGENT_COMMERCE_OVERRIDE', 'https://dev-agent-commerce.xpay.sh' );
define( 'XPAY_WC_ONBOARD_URL_OVERRIDE', 'http://localhost:8082/onboard/woocommerce' );
```

Save.

---

## 3. Install the plugin

1. WP admin → **Plugins → Add New → Upload Plugin**
2. Choose `/tmp/xpay-woocommerce.zip` from your machine
3. Click **Install Now → Activate Plugin**
4. After activation, head to **Settings → xpay**

You should see:
- A "Connect store" card
- The "Audit readiness" table with most rows already ✓ Ready (llms.txt, robots.txt, JSON-LD, BuyAction — all the discovery checks pass *immediately* without needing a connection)

**Verify before connecting** (open in browser tabs):
- `https://store-xyz12.instawp.xyz/llms.txt` → Markdown discovery doc (llmstxt.org). Pre-Connect: lists store home + categories. Post-Connect: also lists the catalog feed and per-protocol endpoints (ACP / UCP / AP2 / MCP).
- `https://store-xyz12.instawp.xyz/robots.txt` → should contain `User-agent: GPTBot` etc.
- View source on any product page → look for `<script type="application/ld+json">` with a `Product` + `Offer` + `BuyAction`.

---

## 4. Connect (the real test)

1. **Settings → xpay → Connect store**
2. A new tab opens at `http://localhost:8082/onboard/woocommerce?site=...&nonce=...`
3. In another tab, generate WC API keys:
   - InstaWP site → **WooCommerce → Settings → Advanced → REST API → Add key**
   - Description: `xpay`
   - User: your admin account
   - Permissions: **Read**
   - Click **Generate API key**
   - Copy the `ck_…` and `cs_…` values (the secret only shows once)
4. Paste both into the localhost onboard page → **Approve & connect**

**Expected result:** green "Connected ✓" box with your merchant slug (e.g. `store-xyz12-instawp-xyz`) and a feed URL.

Behind the scenes:
- Backend created a row in `xpay-merchants-dev` with your WC creds
- Backend POSTed back to `https://store-xyz12.instawp.xyz/wp-json/xpay/v1/finalize` with the api_key
- Plugin stored the api_key in `wp_options`
- Plugin called `/v1/merchants/{slug}/resync` to trigger first catalog pull

---

## 5. Verify the catalog populated

```bash
SLUG=store-xyz12-instawp-xyz   # replace with your actual slug

# 1. Feed should now contain real products (not the placeholder)
curl -s https://dev-agent-feed.xpay.sh/catalog/$SLUG.json | jq '.products | length'
# → should be > 0 (InstaWP WC template ships ~5-20 sample products)

# 2. Check the agent-commerce API directly
curl -s https://dev-agent-commerce.xpay.sh/v1/$SLUG/products | jq '.count'

# 3. Pick a real SKU and mint a cart deeplink
SKU=$(curl -s https://dev-agent-feed.xpay.sh/catalog/$SLUG.json | jq -r '.products[0].sku')
echo "Testing SKU: $SKU"

curl -s -X POST https://dev-agent-commerce.xpay.sh/v1/$SLUG/cart \
  -H 'Content-Type: application/json' \
  -d "{\"items\":[{\"sku\":\"$SKU\",\"qty\":1}],\"agent\":\"smoke-test\"}" | jq
# → returns a checkout_url like https://store-xyz12.instawp.xyz/?xpay_cart=<JWT>
```

Click the returned `checkout_url` — your browser should land on the InstaWP store's checkout page **with the SKU already in the cart**.

This is the full agent-buy loop: AI surface → cart mint → deeplink → merchant checkout → existing WooPayments / Stripe gateway processes payment.

---

## 6. Re-run the audit to verify all 8 checks pass

```bash
cd /Users/sri/Documents/Dev/mvp
python scripts/seller-audit/audit.py --url https://store-xyz12.instawp.xyz
```

Look at the output — all 8 commerce-readiness checks should be `pass`:

```
product_feed              ✓ pass
live_pricing              ✓ pass
ai_guide                  ✓ pass
agent_checkout_discovery  ✓ pass
agents_allowed            ✓ pass
direct_buy                ✓ pass
in_chat_checkout          ✓ pass
fresh_inventory           ✓ pass
```

If any are `fail`, that's an immediate bug — file an issue with the audit JSON dump.

---

## 7. Cleanup

InstaWP sandboxes auto-expire after 7 days. To clean up manually:

```bash
# Remove the merchant row + catalog object
SLUG=store-xyz12-instawp-xyz
aws --profile agentically dynamodb delete-item --table-name xpay-merchants-dev \
  --key "{\"merchant_slug\":{\"S\":\"$SLUG\"}}"
aws --profile agentically s3 rm s3://xpay-agent-feeds-dev/catalog/$SLUG.json
```

Optional: delete the InstaWP site from your dashboard.

---

## What this test proves

- ✅ Plugin installs cleanly into a vanilla WC store
- ✅ Onboarding nonce dance round-trips (plugin → backend → app.xpay.sh → backend → plugin)
- ✅ Backend pulls products via WC REST API and writes ACP-shape feed to S3
- ✅ Feed serves over CDN with TLS
- ✅ Agent-commerce API mints signed cart deeplinks
- ✅ Cart deeplinks land in the merchant's WC cart pre-filled
- ✅ Audit script reports 8/8 checks pass

If steps 4–6 all work cleanly, the v0.1 product is shippable. Promote to prod by removing the three `XPAY_WC_*_OVERRIDE` lines from `wp-config.php` and reconnecting against the bare `agent-commerce.xpay.sh` (once `app.xpay.sh/onboard/woocommerce` is on Vercel).
