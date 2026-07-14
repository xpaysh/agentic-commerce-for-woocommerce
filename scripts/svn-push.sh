#!/usr/bin/env bash
# Publish a version of agentic-commerce-for-woocommerce to the WordPress.org SVN.
#
#   ./scripts/svn-push.sh 0.3.6
#
# Mirrors what release.sh does for the self-hosted install.xpay.sh channel, but
# for the public WP.org directory (separate channel, separate 24h review window).
#
# Prereqs:
#   - Version already consistent across the PHP header + readme.txt Stable tag
#     (same gate as release.sh).
#   - WP.org SVN checked out at $SVN_DIR (default ~/wporg/acfw).
#   - You know the xpaysh WP.org SVN password (separate from GitHub); svn will
#     prompt for it at commit time.
#
# Steps: version check -> svn up -> rsync trunk from the plugin source ->
# stage adds/deletes -> verify Stable tag -> svn cp trunk tags/<version> ->
# show the diff -> confirm -> svn ci.
#
# Nothing is committed until you type 'y' at the prompt. Re-runnable: it aborts
# if tags/<version> already exists on the server.

set -euo pipefail

VERSION=${1:-}
[[ -n "$VERSION" ]] || { echo "usage: $0 <version>   (e.g. $0 0.3.6)" >&2; exit 64; }

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
SVN_DIR=${SVN_DIR:-$HOME/wporg/acfw}
SVN_USER=${SVN_USER:-xpaysh}
# Neutral by default and derived from $VERSION. It used to default to a hardcoded
# 0.6.0 feature list, which every later release would have carried — and an SVN
# commit message is permanent. Pass COMMIT_MSG= to say something more specific.
COMMIT_MSG=${COMMIT_MSG:-"Release ${VERSION}"}

green() { printf "\033[32m%s\033[0m\n" "$*"; }
red()   { printf "\033[31m%s\033[0m\n" "$*" >&2; }
fail()  { red "✗ $*"; exit 1; }

command -v svn >/dev/null || fail "svn not installed"
command -v rsync >/dev/null || fail "rsync not installed"
[[ -d "$SVN_DIR/.svn" ]] || fail "no SVN checkout at $SVN_DIR (set SVN_DIR=/path/to/checkout)"

# 0) Customer-name gate. A WP.org SVN commit is PERMANENT — the revision stays
#    browsable on plugins.trac.wordpress.org forever, even if we overwrite the
#    file later. There is no taking this one back, so it runs before anything.
"$PLUGIN_DIR/scripts/check-no-customer-names.sh" || fail "customer-name gate failed"

# 1) Version consistency (php header + readme Stable tag)
PHP_FILE="$PLUGIN_DIR/agentic-commerce-for-woocommerce.php"
PHP_VERSION=$(awk '/^[[:space:]]*\*[[:space:]]*Version:/ {for(i=1;i<=NF;i++) if($i ~ /^[0-9]+(\.[0-9]+)*$/){print $i; exit}}' "$PHP_FILE")
README_VERSION=$(awk '/^[[:space:]]*Stable tag:/ {for(i=1;i<=NF;i++) if($i ~ /^[0-9]+(\.[0-9]+)*$/){print $i; exit}}' "$PLUGIN_DIR/readme.txt")
[[ "$PHP_VERSION"    == "$VERSION" ]] || fail "PHP header Version is '$PHP_VERSION', expected '$VERSION'"
[[ "$README_VERSION" == "$VERSION" ]] || fail "readme.txt Stable tag is '$README_VERSION', expected '$VERSION'"
green "✓ version $VERSION agrees (PHP header + readme Stable tag)"

cd "$SVN_DIR"
green "→ svn up $SVN_DIR"
svn up -q
[[ -e "tags/$VERSION" ]] && fail "tags/$VERSION already exists in the checkout — is it already released?"

# 2) Sync trunk from the plugin source (same file set as the released .zip)
green "→ syncing trunk/ from $PLUGIN_DIR"
rsync -a --delete \
  --exclude='.git' --exclude='node_modules' --exclude='.DS_Store' \
  --exclude='scripts' --exclude='.serverless' --exclude='assets' \
  --exclude='phpcs.xml.dist' --exclude='.gitignore' \
  --exclude='INSTAWP_TEST_WALKTHROUGH.md' --exclude='README.md' \
  --exclude='CHANGELOG.md' --exclude='.svn' \
  "$PLUGIN_DIR/" trunk/

# 3) Stage adds (new files) + deletes (removed files)
svn add --force trunk >/dev/null
svn status trunk | awk '/^!/{print $2}' | while read -r f; do svn delete "$f" >/dev/null; done

# 4) Verify trunk's Stable tag matches (the line WP.org actually reads)
grep -q "^Stable tag: ${VERSION}$" trunk/readme.txt \
  || fail "trunk/readme.txt 'Stable tag' is not '${VERSION}' after sync"
green "✓ trunk/readme.txt Stable tag = ${VERSION}"

# 5) Tag the release (copy trunk → tags/<version>)
svn cp trunk "tags/$VERSION"
green "✓ staged tags/$VERSION"

# 6) Review + confirm before the irreversible publish
echo
echo "Pending SVN changes:"
svn status | sed 's/^/  /'
echo
echo "Commit message: ${COMMIT_MSG}"
echo "SVN user:       ${SVN_USER}  (you'll be prompted for the WP.org SVN password)"
echo
read -r -p "Publish ${VERSION} to WordPress.org now? [y/N] " ans
[[ "${ans:-}" == "y" || "${ans:-}" == "Y" ]] || { red "aborted — nothing committed (run again to retry)"; exit 1; }

# 7) Commit
svn ci -m "$COMMIT_MSG" --username "$SVN_USER"
green "✓ committed ${VERSION} to WP.org SVN"
echo "WP.org review window is ~24h before it reaches users."
echo "  https://wordpress.org/plugins/agentic-commerce-for-woocommerce/"
