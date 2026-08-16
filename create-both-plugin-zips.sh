#!/usr/bin/env bash
# Build distribution zips for Wedding Party RSVP (free) and Wedding Party RSVP Pro.
# Expects this repo and wedding-party-rsvp-pro as sibling directories (same parent folder).
#
# Child scripts use rsync rules that exclude dot-prefixed paths (e.g. .distignore, .git, .cursor)
# so Plugin Check does not report hidden_files in the zip.
#
# Free plugin: vendor/ is excluded from the zip (Composer dev deps only; composer.json at repo root).
# Pro plugin: ships vendor/ (runtime QR) and composer.json; composer.lock stays out of the zip.
# Pro plugin: NEVER ships companion/, docs/, scripts/, src/, or secrets — those break WP updates.
#
# Usage (from free plugin root):
#   ./create-both-plugin-zips.sh
#
# Development / test zips only (no SVN): ./create-dev-build-zips.sh
#
# Output (both zips in the free repo Dist/ folder; version in filename only):
#   <this-repo>/Dist/wedding-party-rsvp-{version}.zip
#   <this-repo>/Dist/wedding-party-rsvp-pro-{version}.zip
# Extracted folders remain wedding-party-rsvp/ and wedding-party-rsvp-pro/ (no version suffix).
#
# Pro zip: LMFWC / GitHub injection still uses env vars or .lmfwc-credentials.local /
# .github-pat.local in the Pro repo — see wedding-party-rsvp-pro/create-plugin-zip.sh

set -euo pipefail

FREE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PRO_ROOT="$(cd "${FREE_ROOT}/../wedding-party-rsvp-pro" && pwd)"

FREE_ZIP_SCRIPT="${FREE_ROOT}/create-plugin-zip.sh"
PRO_ZIP_SCRIPT="${PRO_ROOT}/create-plugin-zip.sh"
PRO_PACKAGING_HELPER="${PRO_ROOT}/scripts/wpr-pro-dist-packaging.sh"

if [[ ! -f "${FREE_ZIP_SCRIPT}" ]]; then
	echo "✗ Missing free zip script: ${FREE_ZIP_SCRIPT}" >&2
	exit 1
fi

if [[ ! -f "${PRO_ZIP_SCRIPT}" ]]; then
	echo "✗ Missing Pro zip script (is wedding-party-rsvp-pro next to this folder?): ${PRO_ZIP_SCRIPT}" >&2
	echo "  Expected layout: .../wedding-party-rsvp/ and .../wedding-party-rsvp-pro/" >&2
	exit 1
fi

if [[ ! -f "${PRO_PACKAGING_HELPER}" ]]; then
	echo "✗ Missing Pro packaging helper: ${PRO_PACKAGING_HELPER}" >&2
	exit 1
fi

# shellcheck source=../wedding-party-rsvp-pro/scripts/wpr-pro-dist-packaging.sh
source "${PRO_PACKAGING_HELPER}"

echo "==> Free plugin: ${FREE_ROOT}"
( cd "${FREE_ROOT}" && bash "${FREE_ZIP_SCRIPT}" )

echo ""
echo "==> Pro plugin: ${PRO_ROOT}"
echo "    Pro zip will be written to: ${FREE_ROOT}/Dist/ (on success)"
( cd "${PRO_ROOT}" && bash "${PRO_ZIP_SCRIPT}" )

# Resolve Pro version for the Dist path (same logic as Pro create-plugin-zip.sh).
PRO_VERSION="$(
	grep -m1 -E '^\s*\*?\s*Version:' "${PRO_ROOT}/wedding-party-rsvp-pro.php" \
		| sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r' | xargs
)"
PRO_ZIP_SUFFIX="${WPR_PRO_ZIP_SUFFIX:-}"
PRO_ZIP_PATH="${FREE_ROOT}/Dist/wedding-party-rsvp-pro-${PRO_VERSION}${PRO_ZIP_SUFFIX}.zip"

echo ""
echo "==> Final Pro zip gate (companion/docs/scripts must not be present)"
wpr_pro_verify_wp_install_zip "${PRO_ZIP_PATH}"

echo ""
echo "Done. Distribution zips (use these paths — do not zip the Pro repo folder):"
FREE_VERSION="$(
	grep -m1 -E '^\s*Version:' "${FREE_ROOT}/wedding-party-rsvp.php" \
		| sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r' | xargs
)"
FREE_ZIP_SUFFIX="${WGRSVP_ZIP_SUFFIX:-}"
FREE_ZIP_PATH="${FREE_ROOT}/Dist/wedding-party-rsvp-${FREE_VERSION}${FREE_ZIP_SUFFIX}.zip"
for z in "${FREE_ZIP_PATH}" "${PRO_ZIP_PATH}"; do
	if [[ -f "${z}" ]]; then
		ls -lh "${z}" | awk '{print "  " $9 " (" $5 ")"}'
	else
		echo "  ✗ missing: ${z}" >&2
		exit 1
	fi
done
echo ""
echo "  Pro install/update file:"
echo "  ${PRO_ZIP_PATH}"
