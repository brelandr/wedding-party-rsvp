#!/usr/bin/env bash
# Development distribution zips for Wedding Party RSVP (free) and Pro.
#
# Same payload as create-both-plugin-zips.sh, but:
#   - Writes *-dev-YYYYMMDD.zip filenames (not the release zip names).
#   - Does NOT git commit, tag, push, or publish to WordPress.org SVN.
#   - Does NOT create a GitHub Release (so deploy.yml never runs).
#
# WordPress.org releases still use the normal flow:
#   bump version → commit → tag → gh release create (published) → deploy.yml → SVN.
#
# Usage (from free plugin root):
#   ./create-dev-build-zips.sh
#
# Optional:
#   WGRSVP_DEV_DATE=20260609 ./create-dev-build-zips.sh   # fixed date suffix
#   WGRSVP_ZIP_SUFFIX=-alpha WPR_PRO_ZIP_SUFFIX=-alpha ./create-dev-build-zips.sh
#
# Output:
#   Dist/wedding-party-rsvp-{version}-dev-{date}.zip
#   Dist/wedding-party-rsvp-pro-{version}-dev-{date}.zip

set -euo pipefail

FREE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEV_DATE="${WGRSVP_DEV_DATE:-$(date +%Y%m%d)}"
DEV_SUFFIX="${WGRSVP_ZIP_SUFFIX:--dev-${DEV_DATE}}"

export WGRSVP_ZIP_SUFFIX="${DEV_SUFFIX}"
export WPR_PRO_ZIP_SUFFIX="${WPR_PRO_ZIP_SUFFIX:-${DEV_SUFFIX}}"

echo "==> Development build (local zips only — no SVN / no GitHub Release)"
echo "    Suffix: ${DEV_SUFFIX}"
echo ""

bash "${FREE_ROOT}/create-both-plugin-zips.sh"

echo ""
echo "Development zips ready under Dist/."
echo "These filenames are for testing; they are NOT published to WordPress.org."
echo "For an org release, use create-both-plugin-zips.sh and publish a GitHub Release."
