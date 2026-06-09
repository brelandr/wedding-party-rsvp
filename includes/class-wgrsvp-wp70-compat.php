<?php
/**
 * WordPress 7.0+ compatibility helpers (AI Client, Connectors screen).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wgrsvp_wp70_ai_available' ) ) {
	/**
	 * Whether the WordPress AI Client is available.
	 *
	 * @return bool
	 */
	function wgrsvp_wp70_ai_available() {
		return function_exists( 'wp_ai_client_prompt' );
	}
}

if ( ! function_exists( 'wgrsvp_wp70_connectors_available' ) ) {
	/**
	 * Whether the WordPress 7.0 Connectors API is available.
	 *
	 * @return bool
	 */
	function wgrsvp_wp70_connectors_available() {
		return function_exists( 'wp_get_connectors' );
	}
}

if ( ! function_exists( 'wgrsvp_wp70_connections_admin_url' ) ) {
	/**
	 * Admin URL for the Connectors settings screen (WP 7.0+).
	 *
	 * @return string Empty when Connectors UI is unavailable.
	 */
	function wgrsvp_wp70_connections_admin_url() {
		if ( ! wgrsvp_wp70_connectors_available() ) {
			return '';
		}
		if ( function_exists( 'wp_options_connectors_render_page' ) ) {
			return admin_url( 'options-connectors.php' );
		}
		return admin_url( 'options-general.php?page=connectors-wp-admin' );
	}
}

if ( ! function_exists( 'wgrsvp_wp70_ai_setup_notice_text' ) ) {
	/**
	 * Plain-text notice when AI is unavailable.
	 *
	 * @return string
	 */
	function wgrsvp_wp70_ai_setup_notice_text() {
		if ( wgrsvp_wp70_ai_available() ) {
			return '';
		}
		$connectors = wgrsvp_wp70_connections_admin_url();
		if ( '' !== $connectors ) {
			return __( 'WordPress 7.0+ is required. Configure an AI provider under Settings → Connectors, then try again.', 'wedding-party-rsvp' );
		}
		return __( 'Requires WordPress 7.0+ with the AI Client and a site-configured provider.', 'wedding-party-rsvp' );
	}
}

if ( ! function_exists( 'wgrsvp_wp70_ai_setup_notice_html' ) ) {
	/**
	 * HTML notice with optional Connectors link.
	 *
	 * @return string
	 */
	function wgrsvp_wp70_ai_setup_notice_html() {
		if ( wgrsvp_wp70_ai_available() ) {
			return '';
		}
		$connectors = wgrsvp_wp70_connections_admin_url();
		if ( '' !== $connectors ) {
			return sprintf(
				/* translators: %s: Settings → Connectors admin URL. */
				__( 'WordPress 7.0+ is required. <a href="%s">Open Settings → Connectors</a> to configure an AI provider, then try again.', 'wedding-party-rsvp' ),
				esc_url( $connectors )
			);
		}
		return esc_html__( 'Requires WordPress 7.0+ with the AI Client and a site-configured provider.', 'wedding-party-rsvp' );
	}
}
