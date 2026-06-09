#!/usr/bin/env bash
# Create distribution zip for Wedding Party RSVP (free) for WordPress.org / SVN.
# Uses rsync into a temp dir (excludes hidden / dotfiles — Plugin Check: hidden_files)
# then zips. Run from the plugin root: ./create-plugin-zip.sh
#
# The zip MUST have a single top-level folder named after the plugin slug (e.g.
# wedding-party-rsvp/wedding-party-rsvp.php). A flat zip (main file at archive root)
# causes WordPress uploads to install as a second folder (e.g. wedding-party-rsvp-1)
# when the correctly named folder already exists.

set -euo pipefail

PLUGIN_SLUG="wedding-party-rsvp"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="${SCRIPT_DIR}/Dist"
TEMP_DIR="${SCRIPT_DIR}/.zip-temp-$$"
TEMP_PLUGIN="${TEMP_DIR}/${PLUGIN_SLUG}"
trap 'rm -rf "${TEMP_DIR}"' EXIT

# Version for the zip filename only (archive folder stays wedding-party-rsvp/).
wgrsvp_resolve_zip_version() {
	local ver=""
	if [[ -f "${SCRIPT_DIR}/.release" ]]; then
		ver="$(grep -E '^VERSION=' "${SCRIPT_DIR}/.release" 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"'" | xargs)"
	fi
	if [[ -z "${ver}" && -f "${SCRIPT_DIR}/wedding-party-rsvp.php" ]]; then
		ver="$(grep -m1 -E '^\s*Version:' "${SCRIPT_DIR}/wedding-party-rsvp.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r' | xargs)"
	fi
	if [[ -z "${ver}" ]]; then
		echo "✗ Error: could not determine plugin version (.release or plugin header)." >&2
		exit 1
	fi
	echo "${ver}"
}

PLUGIN_VERSION="$(wgrsvp_resolve_zip_version)"
ZIP_NAME="${PLUGIN_SLUG}-${PLUGIN_VERSION}.zip"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"

if ! command -v rsync >/dev/null 2>&1; then
	echo "✗ Error: rsync is required (install Xcode CLT or rsync)." >&2
	exit 1
fi
if ! command -v zip >/dev/null 2>&1; then
	echo "✗ Error: zip is required" >&2
	exit 1
fi

echo "Creating distribution zip: Dist/${ZIP_NAME} (folder inside: ${PLUGIN_SLUG}/)"

mkdir -p "${DIST_DIR}"
rm -f "${ZIP_PATH}"
mkdir -p "${TEMP_PLUGIN}"

# All dot-prefixed files/dirs (Plugin Check: hidden_files — not permitted in the zip).
# .[!.]* excludes names like .git, .distignore, but not .. or single-dot entries.
#
# vendor/ — never ship in the free plugin zip. It is only for local PHPCS/WPCS
# (see composer.json require-dev). Omitting it avoids Plugin Check:
# missing_composer_json_file / orphaned vendor in releases.
#
# assets/js and assets/css are required at runtime (plugins_url(..., __FILE__)).
# Ship assets/blueprints/blueprint.json for WordPress.org plugin directory / Playground.
# Exclude nested blueprint dev tree only (may contain .svn).
rsync -a \
	--exclude='Dist/' \
	--exclude='.[!.]*' \
	--exclude='.zip-temp-*' \
	--exclude='*.DS_Store' \
	--exclude='node_modules/' \
	--exclude='vendor/' \
	--exclude='vendor' \
	--exclude='dist/' \
	--exclude='src/' \
	--exclude='svn-checkout/' \
	--exclude='assets/blueprints/trunk/' \
	--exclude='wedding-party-assets/' \
	--exclude='*.log' \
	--exclude='*.tmp' \
	--exclude='*.temp' \
	--exclude='*.swp' \
	--exclude='*.swo' \
	--exclude='*~' \
	--exclude='*.zip' \
	--exclude='*.sh' \
	--exclude='deploy.yml' \
	--exclude='phpcs.xml' \
	--exclude='phpcs.xml.dist' \
	--exclude='*.md' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='package.json' \
	--exclude='package-lock.json' \
	--exclude='PLUGIN-IDENTIFIER.txt' \
	--exclude='publish-assets.sh' \
	"${SCRIPT_DIR}/" "${TEMP_PLUGIN}/"

(
	cd "${TEMP_DIR}" || exit 1
	zip -qr "${ZIP_PATH}" "${PLUGIN_SLUG}"
)

echo "Created: ${ZIP_PATH} ($(ls -lh "${ZIP_PATH}" | awk '{print $5}'))"
echo "Verifying exclusions..."
ZIP_LISTING=$( unzip -l "${ZIP_PATH}" 2>/dev/null || true )

if echo "${ZIP_LISTING}" | grep -qF "${PLUGIN_SLUG}/vendor/"; then
	echo "✗ ERROR: vendor/ must not be in the distribution zip (dev-only; see composer.json require-dev)." >&2
	exit 1
fi
echo "OK: No vendor/ in zip."

if ! echo "${ZIP_LISTING}" | grep -qF "${PLUGIN_SLUG}/assets/js/rsvp-interactivity.js"; then
	echo "✗ ERROR: assets/js (e.g. rsvp-interactivity.js) must be in the distribution zip." >&2
	exit 1
fi
if ! echo "${ZIP_LISTING}" | grep -qF "${PLUGIN_SLUG}/assets/css/"; then
	echo "✗ ERROR: assets/css/ must be in the distribution zip." >&2
	exit 1
fi
echo "OK: Runtime assets (assets/js, assets/css) present."

if ! echo "${ZIP_LISTING}" | grep -qF "${PLUGIN_SLUG}/assets/blueprints/blueprint.json"; then
	echo "✗ ERROR: assets/blueprints/blueprint.json must be in the distribution zip (Playground / plugin directory)." >&2
	exit 1
fi
if echo "${ZIP_LISTING}" | grep -qF "${PLUGIN_SLUG}/assets/blueprints/trunk/"; then
	echo "✗ ERROR: assets/blueprints/trunk/ must not be in the distribution zip (dev tree)." >&2
	exit 1
fi
echo "OK: assets/blueprints/blueprint.json present; assets/blueprints/trunk excluded."

if echo "${ZIP_LISTING}" | grep -qE '(\.cursorrules|\.DS_Store|\.git/|\.github|create-plugin-zip\.sh|[^ ]+\.sh|deploy\.yml|\.md|phpcs\.xml|\.distignore)'; then
	echo "WARNING: Some excluded paths may still be present."
	echo "${ZIP_LISTING}" | grep -E '(\.cursorrules|\.DS_Store|\.git/|\.github|create-plugin-zip|[^ ]+\.sh|deploy\.yml|\.md|phpcs\.xml|\.distignore)' || true
else
	echo "OK: Excluded patterns not found in zip."
fi

if echo "${ZIP_LISTING}" | grep -qE '\.svn/|svn-checkout/'; then
	echo "WARNING: SVN metadata (.svn/) or svn-checkout/ must not be in the zip (Plugin Check: vcs_present)."
	echo "${ZIP_LISTING}" | grep -E '\.svn/|svn-checkout/' || true
else
	echo "OK: No .svn/ or svn-checkout/ in zip."
fi

if command -v zipinfo >/dev/null 2>&1; then
	if zipinfo -1 "${ZIP_PATH}" 2>/dev/null | grep -qF "${PLUGIN_SLUG}/Dist/"; then
		echo "✗ ERROR: Dist/ must not be in the distribution zip." >&2
		exit 1
	fi
	echo "OK: No Dist/ in zip."
	if zipinfo -1 "${ZIP_PATH}" 2>/dev/null | grep -qE '(^|/)\.[^/]+'; then
		echo "WARNING: Hidden (dot-prefixed) paths in zip — Plugin Check: hidden_files."
		zipinfo -1 "${ZIP_PATH}" 2>/dev/null | grep -E '(^|/)\.[^/]+' || true
	else
		echo "OK: No hidden dotfile paths in zip."
	fi
else
	echo " (Install zipinfo for hidden-file verification, or check unzip listing manually.)"
fi
