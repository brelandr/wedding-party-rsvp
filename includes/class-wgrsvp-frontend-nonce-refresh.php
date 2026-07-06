<?php
/**
 * Refresh RSVP form nonces via AJAX so cached HTML (CDN / host page cache) cannot serve expired tokens.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Frontend_Nonce_Refresh' ) ) {
	/**
	 * AJAX nonce refresh for public RSVP forms.
	 */
	class WGRSVP_Frontend_Nonce_Refresh {

		const SCRIPT_HANDLE = 'wgrsvp-nonce-refresh';

		/**
		 * Whether frontend assets were requested this request.
		 *
		 * @var bool
		 */
		private static $assets_requested = false;

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'wp_ajax_wgrsvp_refresh_rsvp_nonces', array( __CLASS__, 'ajax_refresh_nonces' ) );
			add_action( 'wp_ajax_nopriv_wgrsvp_refresh_rsvp_nonces', array( __CLASS__, 'ajax_refresh_nonces' ) );
			add_action( 'wgrsvp_before_rsvp_block_render', array( __CLASS__, 'request_assets' ) );
		}

		/**
		 * Enqueue nonce-refresh script when an RSVP form is rendered (shortcode or block).
		 *
		 * @return void
		 */
		public static function request_assets() {
			if ( self::$assets_requested ) {
				return;
			}

			/**
			 * Whether to load the AJAX nonce refresh script on RSVP pages.
			 *
			 * @param bool $enabled Default true.
			 */
			if ( ! apply_filters( 'wgrsvp_enable_rsvp_nonce_refresh', true ) ) {
				return;
			}

			self::$assets_requested = true;

			if ( did_action( 'wp_enqueue_scripts' ) ) {
				self::enqueue_assets();
				return;
			}

			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 100 );
		}

		/**
		 * Register and enqueue the nonce refresh script.
		 *
		 * @return void
		 */
		public static function enqueue_assets() {
			if ( ! self::$assets_requested ) {
				return;
			}

			$path = WGRSVP_PLUGIN_DIR . 'assets/js/wgrsvp-nonce-refresh.js';
			$ver  = '8.2.4';
			if ( is_readable( $path ) ) {
				$mtime = filemtime( $path );
				if ( false !== $mtime ) {
					$ver = (string) $mtime;
				}
			}

			wp_register_script(
				self::SCRIPT_HANDLE,
				plugins_url( 'assets/js/wgrsvp-nonce-refresh.js', WGRSVP_PLUGIN_FILE ),
				array(),
				$ver,
				true
			);

			wp_enqueue_script( self::SCRIPT_HANDLE );

			wp_localize_script(
				self::SCRIPT_HANDLE,
				'wgrsvpNonceRefresh',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => 'wgrsvp_refresh_rsvp_nonces',
				)
			);

			if ( function_exists( 'wgrsvp_set_script_translations' ) ) {
				wgrsvp_set_script_translations( self::SCRIPT_HANDLE );
			}
		}

		/**
		 * Return fresh frontend RSVP nonces (JSON). Does not verify an existing nonce (cached pages may lack one).
		 *
		 * @return void
		 */
		public static function ajax_refresh_nonces() {
			if ( class_exists( 'WGRSVP_Frontend_Cache', false ) ) {
				WGRSVP_Frontend_Cache::send_nocache_headers();
			} else {
				nocache_headers();
			}

			$party_id = '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public refresh endpoint; party_id is optional context for chat nonce only.
			if ( isset( $_REQUEST['party_id'] ) ) {
				$party_id = sanitize_text_field( wp_unslash( (string) $_REQUEST['party_id'] ) );
			}

			$nonces = self::build_frontend_nonces( $party_id );

			/**
			 * Nonces returned by the public refresh endpoint.
			 *
			 * @param array  $nonces   Field name => nonce value.
			 * @param string $party_id Sanitized party ID from the request, if any.
			 */
			$nonces = apply_filters( 'wgrsvp_rsvp_refresh_nonces', $nonces, $party_id );

			if ( ! is_array( $nonces ) ) {
				$nonces = array();
			}

			wp_send_json_success(
				array(
					'nonces' => $nonces,
				)
			);
		}

		/**
		 * Whether the licensed Pro plugin owns the public RSVP form.
		 *
		 * @return bool
		 */
		private static function uses_pro_frontend_nonces() {
			return function_exists( 'wgrsvp_is_pro_plugin_active' )
				&& wgrsvp_is_pro_plugin_active()
				&& function_exists( 'wgrsvp_is_pro_license_effectively_valid' )
				&& wgrsvp_is_pro_license_effectively_valid();
		}

		/**
		 * Build nonce map for free or Pro frontend forms.
		 *
		 * @param string $party_id Optional party ID for chat nonce.
		 * @return array<string, string>
		 */
		private static function build_frontend_nonces( $party_id = '' ) {
			if ( self::uses_pro_frontend_nonces() ) {
				$nonces = array(
					'_wpnonce'                        => wp_create_nonce( 'wpr_pro_front_rsvp_submit' ),
					'wpr_pro_front_party_login_nonce' => wp_create_nonce( 'wpr_pro_front_party_login' ),
					'wpr_pro_front_name_lookup_nonce' => wp_create_nonce( 'wpr_pro_front_name_lookup' ),
					'wpr_pro_front_pick_party_nonce'  => wp_create_nonce( 'wpr_pro_front_pick_party' ),
				);

				if ( '' !== $party_id ) {
					$nonces['wpr_pro_rsvp_chat'] = wp_create_nonce( 'wpr_pro_rsvp_chat_' . $party_id );
				}

				return $nonces;
			}

			return array(
				'_wpnonce'                       => wp_create_nonce( 'wgrsvp_front_rsvp_submit' ),
				'wgrsvp_front_party_login_nonce' => wp_create_nonce( 'wgrsvp_front_party_login' ),
			);
		}
	}
}
