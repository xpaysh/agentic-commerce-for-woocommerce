#!/usr/bin/env bash
# Refuse to publish if anything shippable names a customer.
#
#   ./scripts/check-no-customer-names.sh
#
# WHY THIS EXISTS
# ---------------
# This repo is PUBLIC (GitHub + the WordPress.org SVN). Code comments here are
# read by anyone, and `js/*.js` is served to every shopper's browser on every
# merchant storefront. On 2026-07-14 we found 18 comments across 8 files naming
# real merchants, two people, their order volumes and their incident dates —
# shipped in 0.5.3 and 0.6.0, i.e. published to the world and served on other
# customers' storefronts. Nothing blocked it, so it recurred for two releases.
#
# The engineering rationale in a comment is worth keeping. The identity never
# is: "a store on LiteSpeed" carries exactly the same meaning as naming them.
#
# THE DENY-LIST IS DELIBERATELY NOT IN THIS REPO
# ----------------------------------------------
# A list of customer names, committed to a public repo, would leak the very
# thing it guards. It lives in a gitignored file instead:
#
#   scripts/.release-denylist    — one grep -E pattern per line; # comments ok
#
# This script HARD-FAILS when that file is missing. An absent deny-list must
# never read as "nothing to check" — that is how a guard silently rots.

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
DENYLIST=${XPAY_RELEASE_DENYLIST:-"$PLUGIN_DIR/scripts/.release-denylist"}

red()   { printf "\033[31m%s\033[0m\n" "$*" >&2; }
green() { printf "\033[32m%s\033[0m\n" "$*"; }
fail()  { red "✗ $*"; exit 1; }

[[ -f "$DENYLIST" ]] || fail "no deny-list at $DENYLIST — refusing to publish.
   Create it (one regex per line: merchant slugs, domains, contact first names)
   or point XPAY_RELEASE_DENYLIST at it. It is gitignored on purpose."

# Strip comments/blank lines, join into one alternation.
PATTERN=$(grep -vE '^\s*(#|$)' "$DENYLIST" | paste -sd '|' -)
[[ -n "$PATTERN" ]] || fail "deny-list at $DENYLIST is empty — refusing to publish."

cd "$PLUGIN_DIR"

# Everything tracked, minus what never ships. `scripts/` is excluded from both
# the .zip and the SVN rsync, and this file necessarily discusses the problem.
# CHANGELOG.md IS checked: release.sh uploads it to the public CDN and embeds
# the version's section into manifest.json, which renders inside merchants'
# wp-admin update screen.
HITS=$(git grep -niE "$PATTERN" -- . \
        ':(exclude)scripts/' \
        ':(exclude)assets/screenshots-src/' \
        ':(exclude).gitignore' || true)

if [[ -n "$HITS" ]]; then
  red "✗ customer-identifying content in shippable files — REFUSING TO PUBLISH:"
  red ""
  printf '%s\n' "$HITS" >&2
  red ""
  red "  Keep the reasoning, drop the identity:"
  red "    \"proven on <merchant>\"        → \"observed on a live LiteSpeed store\""
  red "    \"<merchant> has 78 orders\"    → \"virtually no order carries a ref record\""
  red "    \"reported by <person>\"        → (just delete it)"
  exit 1
fi

green "✓ no customer-identifying content in shippable files"
