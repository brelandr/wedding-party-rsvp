#!/usr/bin/env bash
# Download WordPress core + the official test library for PHPUnit.
# Usage: bash bin/install-wp-tests.sh [db-name] [db-user] [db-pass] [db-host] [wp-version]
# Example: bash bin/install-wp-tests.sh wgrsvp_phpunit root '' localhost latest

set -euo pipefail

DB_NAME="${1:-wgrsvp_phpunit}"
DB_USER="${2:-root}"
DB_PASS="${3:-}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"

TMPDIR="${TMPDIR:-/tmp}"
WP_CORE_DIR="${WP_CORE_DIR:-${TMPDIR}/wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR:-${TMPDIR}/wordpress-tests-lib}"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sSL "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

install_wp() {
	if [ -f "${WP_CORE_DIR}/wp-settings.php" ]; then
		echo "WordPress core already present at ${WP_CORE_DIR}"
		return
	fi
	mkdir -p "${WP_CORE_DIR}"
	echo "Downloading WordPress ${WP_VERSION}..."
	if [ "latest" = "${WP_VERSION}" ]; then
		download https://wordpress.org/latest.tar.gz "${TMPDIR}/wordpress.tar.gz"
	else
		download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "${TMPDIR}/wordpress.tar.gz"
	fi
	tar -xzf "${TMPDIR}/wordpress.tar.gz" -C "${TMPDIR}"
	# tarball extracts to ${TMPDIR}/wordpress — already WP_CORE_DIR default.
	if [ "${WP_CORE_DIR}" != "${TMPDIR}/wordpress" ]; then
		rsync -a "${TMPDIR}/wordpress/" "${WP_CORE_DIR}/"
	fi
	echo "WordPress core installed at ${WP_CORE_DIR}"
}

install_test_suite() {
	if [ -f "${WP_TESTS_DIR}/includes/functions.php" ]; then
		echo "Test library already present at ${WP_TESTS_DIR}"
		return
	fi
	mkdir -p "${WP_TESTS_DIR}"
	echo "Downloading wordpress-develop test library..."
	download https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "${TMPDIR}/wp-tests-includes.tgz" || true
	# Prefer GitHub archive of wordpress-develop tests/phpunit
	download https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.tar.gz "${TMPDIR}/wordpress-develop.tar.gz"
	tar -xzf "${TMPDIR}/wordpress-develop.tar.gz" -C "${TMPDIR}"
	DEV_DIR="$(find "${TMPDIR}" -maxdepth 1 -type d -name 'wordpress-develop-*' | head -1)"
	rsync -a "${DEV_DIR}/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes/"
	rsync -a "${DEV_DIR}/tests/phpunit/data/" "${WP_TESTS_DIR}/data/"
	echo "Test library installed at ${WP_TESTS_DIR}"
}

install_db() {
	local EXTRA=()
	if [ -n "${DB_PASS}" ]; then
		EXTRA+=(-p"${DB_PASS}")
	fi
	echo "Creating database ${DB_NAME} if needed..."
	mysqladmin create "${DB_NAME}" --user="${DB_USER}" "${EXTRA[@]}" --host="${DB_HOST}" 2>/dev/null || true
	mysql --user="${DB_USER}" "${EXTRA[@]}" --host="${DB_HOST}" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
	echo "Database ${DB_NAME} ready."
}

install_wp
install_test_suite
install_db

echo "Done. Run: WP_TESTS_DIR=${WP_TESTS_DIR} WP_CORE_DIR=${WP_CORE_DIR} vendor/bin/phpunit --testdox"
