#!/usr/bin/env bash
# Release a new version of xpay-woocommerce.
#
#   ./scripts/release.sh 0.1.1
#
# Before running, the new version MUST already be present at the top of
# CHANGELOG.md as `## [VERSION] — YYYY-MM-DD`, and the Version: / Stable tag:
# lines in xpay-woocommerce.php and readme.txt must already match.
#
# The script:
#   1. Validates that all three version strings (PHP header, readme.txt, changelog) agree.
#   2. Extracts the changelog section for this version.
#   3. Builds the zip.
#   4. Uploads versioned + latest copies to s3://xpay-install/woocommerce/.
#   5. Regenerates manifest.json with the new version + changelog excerpt.
#   6. Issues a CloudFront invalidation for /woocommerce/latest.zip + manifest.json.
#
# Idempotent on re-run — uploads overwrite, invalidations are cheap.

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "usage: $0 <version>" >&2
  exit 64
fi
VERSION=$1
PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
REPO_ROOT=$(cd "$PLUGIN_DIR/.." && pwd)
CF_ID=${XPAY_INSTALL_CF_ID:-E17RH4LQHPUH1Q}
BUCKET=${XPAY_INSTALL_BUCKET:-xpay-install}
AWS_PROFILE=${AWS_PROFILE_OVERRIDE:-agentically}

green() { printf "\033[32m%s\033[0m\n" "$*"; }
red()   { printf "\033[31m%s\033[0m\n" "$*" >&2; }
fail()  { red "✗ $*"; exit 1; }

# 1) Version consistency check
PHP_FILE="$PLUGIN_DIR/agentic-commerce-for-woocommerce.php"
[[ -f "$PHP_FILE" ]] || PHP_FILE="$PLUGIN_DIR/xpay-for-woocommerce.php" # fallback for v0.1.2-0.1.11
[[ -f "$PHP_FILE" ]] || PHP_FILE="$PLUGIN_DIR/xpay-woocommerce.php" # fallback for pre-0.1.2
PHP_VERSION=$(awk '/^[[:space:]]*\*[[:space:]]*Version:/ { for (i=1;i<=NF;i++) if ($i ~ /^[0-9]+(\.[0-9]+)*$/) { print $i; exit } }' "$PHP_FILE")
README_VERSION=$(awk '/^[[:space:]]*Stable tag:/ { for (i=1;i<=NF;i++) if ($i ~ /^[0-9]+(\.[0-9]+)*$/) { print $i; exit } }' "$PLUGIN_DIR/readme.txt")

[[ "$PHP_VERSION" == "$VERSION" ]] || fail "$(basename "$PHP_FILE") Version: is $PHP_VERSION, expected $VERSION"
[[ "$README_VERSION" == "$VERSION" ]] || fail "readme.txt Stable tag: is $README_VERSION, expected $VERSION"

# 2) Extract the changelog section for this version
CHANGELOG_FILE="$PLUGIN_DIR/CHANGELOG.md"
awk -v ver="$VERSION" '
  /^## \[/ {
    if (cap) exit
    if ($0 ~ "^## \\[" ver "\\]") { cap=1; print; next }
  }
  cap { print }
' "$CHANGELOG_FILE" > /tmp/xpay-changelog-section.md
[[ -s /tmp/xpay-changelog-section.md ]] || fail "no section found for [$VERSION] in CHANGELOG.md"

green "✓ Version $VERSION agrees across php header, readme.txt, CHANGELOG.md"

# 3) Build zip
# The zip's inner folder name = the WordPress plugin slug. Stage the source
# into a temp dir named `xpay-for-woocommerce/` regardless of where the local
# checkout lives.
SLUG=agentic-commerce-for-woocommerce
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
rsync -a --exclude='.git' --exclude='node_modules' --exclude='.DS_Store' \
  --exclude='scripts' --exclude='.serverless' --exclude='assets' \
  --exclude='phpcs.xml.dist' --exclude='.gitignore' \
  --exclude='INSTAWP_TEST_WALKTHROUGH.md' --exclude='README.md' \
  "$PLUGIN_DIR/" "$STAGE/$SLUG/"

ZIP=/tmp/${SLUG}-${VERSION}.zip
rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" )
green "✓ Built $ZIP ($(du -h "$ZIP" | cut -f1))"

# 4) Upload to S3
aws --profile "$AWS_PROFILE" s3 cp "$ZIP" "s3://$BUCKET/woocommerce/${SLUG}-${VERSION}.zip" \
  --content-type application/zip \
  --content-disposition "attachment; filename=\"xpay_woocommerce_plugin_${VERSION}.zip\"" \
  --cache-control 'public, max-age=300' >/dev/null
aws --profile "$AWS_PROFILE" s3 cp "$ZIP" "s3://$BUCKET/woocommerce/latest.zip" \
  --content-type application/zip \
  --content-disposition "attachment; filename=\"xpay_woocommerce_plugin_latest.zip\"" \
  --cache-control 'public, max-age=60' >/dev/null
green "✓ Uploaded versioned + latest"

# 5) Regenerate manifest.json
NOW_UTC=$(date -u +%Y-%m-%dT%H:%M:%SZ)
CHANGELOG_JSON=$(jq -Rs . < /tmp/xpay-changelog-section.md)
cat > /tmp/manifest.json <<JSON
{
  "name": "xpay for WooCommerce",
  "slug": "${SLUG}",
  "version": "${VERSION}",
  "download_url": "https://install.xpay.sh/woocommerce/${SLUG}-${VERSION}.zip",
  "latest_download_url": "https://install.xpay.sh/woocommerce/latest.zip",
  "changelog_url": "https://install.xpay.sh/woocommerce/CHANGELOG.md",
  "requires": "6.2",
  "requires_php": "7.4",
  "wc_requires": "7.0",
  "tested": "6.7",
  "wc_tested": "9.4",
  "homepage": "https://xpay.sh/sellers/woocommerce",
  "sections": {
    "description": "Put your WooCommerce catalog inside ChatGPT, Claude, Gemini, and Perplexity. Live prices, live stock, cart deeplinks into your existing checkout. Free until your first AI-driven sale.",
    "changelog": ${CHANGELOG_JSON}
  },
  "released_at": "${NOW_UTC}"
}
JSON
aws --profile "$AWS_PROFILE" s3 cp /tmp/manifest.json "s3://$BUCKET/woocommerce/manifest.json" \
  --content-type application/json --cache-control 'public, max-age=60' >/dev/null

# Also publish the full changelog for direct linking
aws --profile "$AWS_PROFILE" s3 cp "$CHANGELOG_FILE" "s3://$BUCKET/woocommerce/CHANGELOG.md" \
  --content-type 'text/markdown; charset=utf-8' --cache-control 'public, max-age=60' >/dev/null
green "✓ Manifest + CHANGELOG.md published"

# 6) Invalidate CloudFront for the moving pieces
aws --profile "$AWS_PROFILE" cloudfront create-invalidation --distribution-id "$CF_ID" \
  --paths '/woocommerce/latest.zip' '/woocommerce/manifest.json' '/woocommerce/CHANGELOG.md' \
  --query 'Invalidation.{Id:Id,Status:Status}' >/dev/null
green "✓ CloudFront invalidation issued"

echo
echo "Released ${SLUG} v${VERSION}"
echo "  https://install.xpay.sh/woocommerce/${SLUG}-${VERSION}.zip"
echo "  https://install.xpay.sh/woocommerce/latest.zip"
echo "  https://install.xpay.sh/woocommerce/manifest.json"
echo "  https://install.xpay.sh/woocommerce/CHANGELOG.md"
