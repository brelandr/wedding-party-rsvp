#!/usr/bin/env bash
# Build distribution zips for Wedding Party RSVP (free) and Wedding Party RSVP Pro.
# Expects this repo and wedding-party-rsvp-pro as sibling directories (same parent folder).
#
# Child scripts use rsync rules that exclude dot-prefixed paths (e.g. .distignore, .git, .cursor)
# so Plugin Check does not report hidden_files in the zip.
#
# Free plugin: vendor/ is excluded from the zip (Composer dev deps only; composer.json at repo root).
# Pro plugin: ships vendor/ (runtime QR) and composer.json; composer.lock stays out of the zip.
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

if [[ ! -f "${FREE_ZIP_SCRIPT}" ]]; then
	echo "✗ Missing free zip script: ${FREE_ZIP_SCRIPT}" >&2
	exit 1
fi

if [[ ! -f "${PRO_ZIP_SCRIPT}" ]]; then
	echo "✗ Missing Pro zip script (is wedding-party-rsvp-pro next to this folder?): ${PRO_ZIP_SCRIPT}" >&2
	echo "  Expected layout: .../wedding-party-rsvp/ and .../wedding-party-rsvp-pro/" >&2
	exit 1
fi

echo "==> Free plugin: ${FREE_ROOT}"
( cd "${FREE_ROOT}" && bash "${FREE_ZIP_SCRIPT}" )

echo ""
echo "==> Pro plugin: ${PRO_ROOT}"
echo "    Pro zip will be written to: ${FREE_ROOT}/Dist/ (on success)"
( cd "${PRO_ROOT}" && bash "${PRO_ZIP_SCRIPT}" )

echo ""
echo "Done. Distribution zips:"
ls -1 "${FREE_ROOT}/Dist/"*.zip 2>/dev/null || echo "  (none found in ${FREE_ROOT}/Dist/)"
