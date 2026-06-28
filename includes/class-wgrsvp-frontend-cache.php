<?php
/**
 * Prevent full-page caching on RSVP frontend routes (nonces expire; stale HTML breaks submissions).
 *
 * Hosts such as Hostinger + LiteSpeed Cache serve cached HTML with expired `_wpnonce` fields,
 * which produces "Security check failed." for a subset of guests.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Frontend_Cache' ) ) {
	/**
	 * RSVP frontend cache control.
	 */
	class WGRSVP_Frontend_Cache {

		const RSVP_SHORTCODE = 'wedding_rsvp_form';

		const RSVP_BLOCK = 'wedding-party-rsvp/rsvp-form';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_nocache_rsvp_page' ), 0 );
			add_action( 'wgrsvp_before_rsvp_block_render', array( __CLASS__, 'send_nocache_headers' ) );
		}

		/**
		 * Disable page cache when the current request is the public RSVP flow.
		 *
		 * @return void
		 */
		public static function maybe_nocache_rsvp_page() {
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
				return;
			}

			if ( ! self::current_request_is_rsvp_frontend() ) {
				return;
			}

			/**
			 * Whether to send no-cache headers and opt out of full-page cache plugins on RSVP pages.
			 *
			 * @param bool $should_nocache Default true when the RSVP shortcode/block or configured RSVP URL matches.
			 */
			if ( ! apply_filters( 'wgrsvp_should_nocache_rsvp_page', true ) ) {
				return;
			}

			self::send_nocache_headers();
		}

		/**
		 * Mark this response as non-cacheable for WordPress, LiteSpeed, and common cache plugins.
		 *
		 * @return void
		 */
		public static function send_nocache_headers() {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			// LiteSpeed Cache (Hostinger default).
			if ( defined( 'LSCWP_V' ) ) {
				do_action( 'litespeed_control_set_nocache', 'wgrsvp-rsvp-form' );
			}

			// WP Super Cache.
			global $wp_cache_not_logged_in;
			if ( isset( $wp_cache_not_logged_in ) ) {
				$wp_cache_not_logged_in = 2; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WPSC documented bypass.
			}

			if ( ! headers_sent() ) {
				nocache_headers();
			}
		}

		/**
		 * Whether the current front-end request should bypass full-page cache.
		 *
		 * @return bool
		 */
		public static function current_request_is_rsvp_frontend() {
			if ( self::request_is_rsvp_form_post() ) {
				return true;
			}

			if ( self::current_url_matches_configured_rsvp_page() ) {
				return true;
			}

			if ( is_singular() ) {
				$post = get_queried_object();
				if ( $post instanceof WP_Post && self::post_contains_rsvp_form( $post ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * RSVP form POST (classic submit or party lookup).
		 *
		 * @return bool
		 */
		private static function request_is_rsvp_form_post() {
			$request_method = 'GET';
			if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
				$request_method = strtoupper( (string) sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
			}
			if ( 'POST' !== $request_method ) {
				return false;
			}

			$post_keys = array(
				'wpr_submit_rsvp',
				'wgrsvp_front_party_login_submit',
				'wpr_pro_name_lookup',
				'wpr_pro_pick_party',
			);

			foreach ( $post_keys as $key ) {
				if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence only; identifies RSVP route for cache bypass.
					return true;
				}
			}

			return false;
		}

		/**
		 * Match General Settings → RSVP Page URL against the current request.
		 *
		 * @return bool
		 */
		private static function current_url_matches_configured_rsvp_page() {
			$settings = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $settings ) ) {
				return false;
			}

			$configured = isset( $settings['rsvp_page_url'] ) ? trim( (string) $settings['rsvp_page_url'] ) : '';
			if ( '' === $configured ) {
				return false;
			}

			$post_id = url_to_postid( esc_url_raw( $configured ) );
			if ( $post_id > 0 && is_page( $post_id ) ) {
				return true;
			}

			$configured_path = wp_parse_url( $configured, PHP_URL_PATH );
			$current_path    = wp_parse_url( ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '' ), PHP_URL_PATH );
			if ( is_string( $configured_path ) && is_string( $current_path ) && '' !== $configured_path ) {
				$configured_path = untrailingslashit( $configured_path );
				$current_path    = untrailingslashit( $current_path );
				if ( $configured_path === $current_path ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Detect RSVP shortcode or block in post content (including reusable blocks is out of scope).
		 *
		 * @param WP_Post $post Post object.
		 * @return bool
		 */
		private static function post_contains_rsvp_form( WP_Post $post ) {
			if ( has_shortcode( $post->post_content, self::RSVP_SHORTCODE ) ) {
				return true;
			}

			if ( function_exists( 'has_block' ) && has_block( self::RSVP_BLOCK, $post ) ) {
				return true;
			}

			return false;
		}
	}
}
