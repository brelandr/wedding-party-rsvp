<?php
/**
 * Admin menu module visibility for free-plugin screens (gifts, portals, etc.).
 *
 * When Wedding Party RSVP Pro is active with an effective license, visibility is
 * read from the Pro option via {@see wpr_pro_admin_module_enabled()} so one Settings
 * screen controls all keys.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wgrsvp_admin_module_keys' ) ) {
	/**
	 * Module keys stored in the free option `wgrsvp_admin_modules` (free-only installs).
	 *
	 * @since 8.0.2
	 * @return string[]
	 */
	function wgrsvp_admin_module_keys() {
		return array(
			'paste_guests',
			'menu_options',
			'gifts_report',
			'song_requests',
			'thankyou_tracker',
			'guestbook',
			'client_summary',
			'vendor_packet',
			'ops_center',
			'caterer_portal',
			'audit_log',
		);
	}
}

if ( ! function_exists( 'wgrsvp_admin_module_enabled' ) ) {
	/**
	 * Whether a free-managed admin module is on (default: enabled).
	 *
	 * @since 8.0.2
	 * @param string $key Module key.
	 * @return bool
	 */
	function wgrsvp_admin_module_enabled( $key ) {
		$key = sanitize_key( (string) $key );

		if ( function_exists( 'wpr_pro_admin_module_enabled' ) && function_exists( 'wpr_pro_effective_license_is_valid' ) && wpr_pro_effective_license_is_valid() ) {
			return wpr_pro_admin_module_enabled( $key );
		}

		$allowed = wgrsvp_admin_module_keys();
		if ( ! in_array( $key, $allowed, true ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Documented `wgrsvp_*` API.
			return (bool) apply_filters( 'wgrsvp_admin_module_enabled', true, $key );
		}

		$opt = get_option( 'wgrsvp_admin_modules', null );
		if ( null === $opt || ! is_array( $opt ) || ! array_key_exists( $key, $opt ) ) {
			$on = true;
		} else {
			$on = ! empty( $opt[ $key ] );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Documented `wgrsvp_*` API.
		return (bool) apply_filters( 'wgrsvp_admin_module_enabled', $on, $key );
	}
}

if ( ! function_exists( 'wgrsvp_require_admin_module_or_die' ) ) {
	/**
	 * Stop load of an admin screen when a module is disabled.
	 *
	 * @since 8.0.2
	 * @param string $key Module key.
	 * @return void
	 */
	function wgrsvp_require_admin_module_or_die( $key ) {
		if ( wgrsvp_admin_module_enabled( $key ) ) {
			return;
		}

		wp_die(
			esc_html__( 'This feature is disabled. Re-enable it under Settings → Admin menu visibility.', 'wedding-party-rsvp' ),
			'',
			array( 'response' => 403 )
		);
	}
}

if ( ! function_exists( 'wgrsvp_store_admin_modules_option' ) ) {
	/**
	 * Persist free-plugin admin module checkboxes (`wgrsvp_admin_modules`).
	 *
	 * @since 8.0.2
	 * @param array<string, mixed> $posted Checkbox values keyed by module slug (e.g. from map_deep + sanitize_text_field).
	 * @return void
	 */
	function wgrsvp_store_admin_modules_option( array $posted ) {
		$out = array();
		foreach ( wgrsvp_admin_module_keys() as $k ) {
			$out[ $k ] = ! empty( $posted[ $k ] ) ? 1 : 0;
		}
		update_option( 'wgrsvp_admin_modules', $out, false );
	}
}
