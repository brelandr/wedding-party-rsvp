<?php
/**
 * Prevent full-page caching on RSVP frontend routes (nonces expire; stale HTML breaks submissions).
 *
 * Hosts such as Hostinger, GoDaddy + Cloudflare, and LiteSpeed Cache serve cached HTML with
 * expired `_wpnonce` fields, which produces "Security check failed." for a subset of guests.
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
		 * Whether CDN / HTTP no-cache headers were already sent this request.
		 *
		 * @var bool
		 */
		private static $http_nocache_sent = false;

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'init', array( __CLASS__, 'maybe_flag_nocache_early' ), 0 );
			add_action( 'send_headers', array( __CLASS__, 'maybe_nocache_rsvp_page' ), 0 );
			add_action( 'template_redirect', array( __CLASS__, 'maybe_nocache_rsvp_page' ), 0 );
			add_action( 'wgrsvp_before_rsvp_block_render', array( __CLASS__, 'send_nocache_headers' ) );
		}

		/**
		 * Set DONOTCACHEPAGE before full-page cache plugins run (uses REQUEST_URI; no query yet).
		 *
		 * @return void
		 */
		public static function maybe_flag_nocache_early() {
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
				return;
			}

			if ( ! self::should_nocache_current_request() ) {
				return;
			}

			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			if ( defined( 'LSCWP_V' ) ) {
				do_action( 'litespeed_control_set_nocache', 'wgrsvp-rsvp-form' );
			}

			global $wp_cache_not_logged_in;
			if ( isset( $wp_cache_not_logged_in ) ) {
				$wp_cache_not_logged_in = 2; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WPSC documented bypass.
			}
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

			if ( ! self::should_nocache_current_request() ) {
				return;
			}

			self::send_nocache_headers();
		}

		/**
		 * Whether no-cache should apply (filterable).
		 *
		 * @return bool
		 */
		private static function should_nocache_current_request() {
			if ( ! self::current_request_is_rsvp_frontend() ) {
				return false;
			}

			/**
			 * Whether to send no-cache headers and opt out of full-page cache plugins on RSVP pages.
			 *
			 * @param bool $should_nocache Default true when the RSVP shortcode/block or configured RSVP URL matches.
			 */
			return (bool) apply_filters( 'wgrsvp_should_nocache_rsvp_page', true );
		}

		/**
		 * Mark this response as non-cacheable for WordPress, LiteSpeed, CDNs, and common cache plugins.
		 *
		 * @return void
		 */
		public static function send_nocache_headers() {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			if ( defined( 'LSCWP_V' ) ) {
				do_action( 'litespeed_control_set_nocache', 'wgrsvp-rsvp-form' );
			}

			global $wp_cache_not_logged_in;
			if ( isset( $wp_cache_not_logged_in ) ) {
				$wp_cache_not_logged_in = 2; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WPSC documented bypass.
			}

			if ( self::$http_nocache_sent || headers_sent() ) {
				return;
			}

			nocache_headers();
			header( 'CDN-Cache-Control: no-store', true );
			header( 'Cloudflare-CDN-Cache-Control: no-store', true );
			header( 'Surrogate-Control: no-store', true );
			self::$http_nocache_sent = true;
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

			if ( self::current_path_matches_known_rsvp_page() ) {
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
		 * Current request path (no query string), untrailingslashit.
		 *
		 * @return string
		 */
		private static function current_request_path() {
			if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
				return '';
			}
			$uri  = sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
			$path = wp_parse_url( $uri, PHP_URL_PATH );
			if ( ! is_string( $path ) || '' === $path ) {
				return '';
			}

			return untrailingslashit( $path );
		}

		/**
		 * Match a published page whose permalink path equals $path.
		 *
		 * @param string $path Request path.
		 * @return bool
		 */
		private static function path_matches_known_rsvp_page( $path ) {
			$path = untrailingslashit( (string) $path );
			if ( '' === $path ) {
				return false;
			}

			/**
			 * Page slugs checked when RSVP Page URL is unset (common `/rsvp/` installs).
			 *
			 * @param string[] $slugs Default `rsvp`.
			 */
			$slugs = apply_filters( 'wgrsvp_rsvp_page_path_slugs', array( 'rsvp' ) );
			if ( ! is_array( $slugs ) ) {
				return false;
			}

			foreach ( $slugs as $slug ) {
				$slug = sanitize_title( (string) $slug );
				if ( '' === $slug ) {
					continue;
				}

				$page = get_page_by_path( $slug, OBJECT, 'page' );
				if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
					continue;
				}

				$page_path = wp_parse_url( get_permalink( $page ), PHP_URL_PATH );
				if ( ! is_string( $page_path ) || '' === $page_path ) {
					continue;
				}

				if ( untrailingslashit( $page_path ) === $path ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether the current URI path matches a known RSVP page (configured URL or `/rsvp/` slug).
		 *
		 * @return bool
		 */
		private static function current_path_matches_known_rsvp_page() {
			return self::path_matches_known_rsvp_page( self::current_request_path() );
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
			$current_path    = self::current_request_path();
			if ( is_string( $configured_path ) && '' !== $configured_path && '' !== $current_path ) {
				if ( untrailingslashit( $configured_path ) === $current_path ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Detect RSVP shortcode, block, or Elementor widget data on a page.
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

			if ( self::elementor_data_contains_rsvp_form( (int) $post->ID ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Elementor stores shortcodes in `_elementor_data` JSON, not in post_content.
		 *
		 * @param int $post_id Page ID.
		 * @return bool
		 */
		private static function elementor_data_contains_rsvp_form( $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id < 1 ) {
				return false;
			}

			$raw = get_post_meta( $post_id, '_elementor_data', true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return false;
			}

			if ( false !== strpos( $raw, self::RSVP_SHORTCODE ) ) {
				return true;
			}

			if ( false !== strpos( $raw, 'wedding_rsvp_form' ) ) {
				return true;
			}

			if ( false !== strpos( $raw, self::RSVP_BLOCK ) ) {
				return true;
			}

			return false;
		}
	}
}
