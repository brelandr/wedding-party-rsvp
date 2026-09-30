<?php
/**
 * PHPUnit bootstrap — WordPress test library + plugin activation.
 *
 * @package Wedding_Party_RSVP
 */

$wgrsvp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $wgrsvp_tests_dir ) || '' === $wgrsvp_tests_dir ) {
	$wgrsvp_vendor_tests = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	if ( file_exists( $wgrsvp_vendor_tests . '/includes/functions.php' ) ) {
		$wgrsvp_tests_dir = $wgrsvp_vendor_tests;
	} else {
		$wgrsvp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
	}
}

if ( ! file_exists( $wgrsvp_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"WordPress test library not found at {$wgrsvp_tests_dir}.\n" .
		"Run `composer install` then `bash bin/install-wp-tests.sh wgrsvp_phpunit root '' localhost latest`.\n" .
		"Or set WP_TESTS_DIR to a wordpress-develop tests/phpunit directory.\n"
	);
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) && file_exists( __DIR__ . '/wp-tests-config.php' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

$wgrsvp_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $wgrsvp_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $wgrsvp_polyfills );
}
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

require_once $wgrsvp_tests_dir . '/includes/functions.php';

/**
 * Load and activate Wedding Party RSVP as a must-use plugin for tests.
 *
 * @return void
 */
function wgrsvp_tests_manually_load_plugin() {
	require dirname( __DIR__ ) . '/wedding-party-rsvp.php';
}

tests_add_filter( 'muplugins_loaded', 'wgrsvp_tests_manually_load_plugin' );

/**
 * After tables exist, ensure guestbook + RSVP schema are installed.
 *
 * The plugin constructor hooks maybe_upgrade / maybe_install to plugins_loaded.
 * Activation also seeds coordinator capabilities used by REST tests.
 *
 * @return void
 */
function wgrsvp_tests_activate_plugin() {
	if ( class_exists( 'WGRSVP_Guestbook', false ) ) {
		WGRSVP_Guestbook::activate();
	}
	if ( class_exists( 'WGRSVP_Coordinator_Role', false ) ) {
		WGRSVP_Coordinator_Role::sync_on_activation();
	}
}

tests_add_filter( 'plugins_loaded', 'wgrsvp_tests_activate_plugin', 50 );

require $wgrsvp_tests_dir . '/includes/bootstrap.php';
