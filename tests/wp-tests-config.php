<?php
/**
 * WordPress PHPUnit config for local MariaDB / MySQL.
 *
 * Override with WP_TESTS_DB_* and WP_CORE_DIR environment variables.
 *
 * @package Wedding_Party_RSVP
 */

$wgrsvp_core_dir = getenv( 'WP_CORE_DIR' );
if ( ! is_string( $wgrsvp_core_dir ) || '' === $wgrsvp_core_dir ) {
	$wgrsvp_core_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress/';
}
$wgrsvp_core_dir = rtrim( $wgrsvp_core_dir, '/\\' ) . '/';

define( 'ABSPATH', $wgrsvp_core_dir );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'wgrsvp_phpunit' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
$wgrsvp_db_pass = getenv( 'WP_TESTS_DB_PASS' );
define( 'DB_PASSWORD', false !== $wgrsvp_db_pass ? $wgrsvp_db_pass : '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'wgrsvp-test-auth-key' );
define( 'SECURE_AUTH_KEY', 'wgrsvp-test-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'wgrsvp-test-logged-in-key' );
define( 'NONCE_KEY', 'wgrsvp-test-nonce-key' );
define( 'AUTH_SALT', 'wgrsvp-test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'wgrsvp-test-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'wgrsvp-test-logged-in-salt' );
define( 'NONCE_SALT', 'wgrsvp-test-nonce-salt' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Wedding Party RSVP Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
