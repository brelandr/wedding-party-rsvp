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
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		static $text_supported = null;
		if ( null !== $text_supported ) {
			return $text_supported;
		}
		$text_supported = false;
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		try {
			$builder = wp_ai_client_prompt();
			if ( is_object( $builder ) ) {
				// Routed through __call; do not use method_exists().
				$text_supported = (bool) $builder->is_supported_for_text_generation();
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Probe only.
			$text_supported = true;
		}
		return $text_supported;
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

if ( ! function_exists( 'wgrsvp_wp70_get_ai_model_preference' ) ) {
	/**
	 * Optional AI model preference slug (empty = core default).
	 *
	 * @return string
	 */
	function wgrsvp_wp70_get_ai_model_preference() {
		$pref = get_option( 'wgrsvp_ai_model_preference', '' );
		return is_string( $pref ) ? sanitize_key( $pref ) : '';
	}
}

if ( ! function_exists( 'wgrsvp_wp70_apply_model_preference' ) ) {
	/**
	 * Apply stored model preference to a prompt builder when supported.
	 *
	 * @param object $builder WP_AI_Client_Prompt_Builder instance.
	 * @return object
	 */
	function wgrsvp_wp70_apply_model_preference( $builder ) {
		$pref = wgrsvp_wp70_get_ai_model_preference();
		if ( '' === $pref || ! is_object( $builder ) ) {
			return $builder;
		}
		if ( is_callable( array( $builder, 'using_model_preference' ) ) ) {
			$builder->using_model_preference( $pref );
		}
		return $builder;
	}
}

if ( ! function_exists( 'wgrsvp_wp70_generate_text' ) ) {
	/**
	 * Generate text via the WordPress AI Client with optional model preference.
	 *
	 * @param string $prompt Full prompt.
	 * @param string $bucket Optional rate-limit bucket label for future use.
	 * @return string|\WP_Error
	 */
	function wgrsvp_wp70_generate_text( $prompt, $bucket = 'generic' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		unset( $bucket );
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'wgrsvp_ai_unavailable', __( 'WordPress AI Client is not available.', 'wedding-party-rsvp' ) );
		}
		try {
			$builder = wp_ai_client_prompt( (string) $prompt );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return new WP_Error( 'wgrsvp_ai_start', __( 'AI client could not start.', 'wedding-party-rsvp' ) );
		}
		if ( ! is_object( $builder ) ) {
			return new WP_Error( 'wgrsvp_ai_api', __( 'AI client API is not supported on this site.', 'wedding-party-rsvp' ) );
		}
		$builder = wgrsvp_wp70_apply_model_preference( $builder );
		$out     = $builder->generate_text();
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		if ( ! is_string( $out ) ) {
			return new WP_Error( 'wgrsvp_ai_bad_response', __( 'Unexpected AI response.', 'wedding-party-rsvp' ) );
		}
		return $out;
	}
}
