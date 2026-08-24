<?php
/**
 * Public companion app-config REST endpoint (free tier).
 *
 * Always registered, even when Wedding Party RSVP Pro is active: the companion
 * hub reads `wgrsvp/v1/app-config` to verify that a wedding site is reachable
 * and to discover branding plus feature flags. When Pro is installed the
 * companion prefers the richer `wpr-pro/v1/app-config` payload, so both
 * namespaces can safely coexist.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Companion app-config for free installs.
 */
class WGRSVP_App_Config {

	/**
	 * REST namespace for all free companion routes.
	 */
	const REST_NAMESPACE = 'wgrsvp/v1';

	/**
	 * Optional brand overrides (unset on most sites).
	 */
	const OPT_PRIMARY_COLOR   = 'wgrsvp_app_primary_color';
	const OPT_SECONDARY_COLOR = 'wgrsvp_app_secondary_color';
	const OPT_LOGO_URL        = 'wgrsvp_app_logo_url';
	const OPT_MIN_VERSION     = 'wgrsvp_app_min_version';
	const OPT_MIN_IOS_BUILD   = 'wgrsvp_app_min_ios_build';
	const OPT_MIN_ANDROID     = 'wgrsvp_app_min_android_version_code';

	/**
	 * Companion hub used when Pro's event network is not available.
	 */
	const HUB_JOIN_URL = 'https://weddingrsvp.pro/app/join';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the public app-config route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/app-config',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get_config' ),
				'permission_callback' => array( __CLASS__, 'permission_public_app_config' ),
			)
		);
	}

	/**
	 * Permission for GET /wgrsvp/v1/app-config.
	 *
	 * Intentionally public and read-only. The companion app and the wedding hub
	 * must read branding, feature flags and the connect URL before any
	 * coordinator session or guest Party ID session exists, so no cookie or
	 * Application Password is available yet. The payload contains only public
	 * site metadata that is already visible on the wedding website — no guest
	 * records, no personal data, no secrets, and nothing writable.
	 *
	 * @return true
	 */
	public static function permission_public_app_config() {
		return true;
	}

	/**
	 * REST callback.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get_config() {
		return new WP_REST_Response( self::get_config(), 200 );
	}

	/**
	 * Build the public app-config payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_config() {
		$site_url = untrailingslashit( home_url() );
		$settings = get_option( 'wgrsvp_general_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$name = isset( $settings['event_title'] ) ? trim( sanitize_text_field( (string) $settings['event_title'] ) ) : '';
		if ( '' === $name && isset( $settings['welcome_title'] ) ) {
			$name = trim( sanitize_text_field( (string) $settings['welcome_title'] ) );
		}
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$config = array(
			'name'                  => $name,
			'partner1'              => self::get_partner_name( 1, $settings ),
			'partner2'              => self::get_partner_name( 2, $settings ),
			'siteUrl'               => $site_url,
			'logoUrl'               => self::get_logo_url(),
			'primaryColor'          => self::get_hex_color_option( self::OPT_PRIMARY_COLOR ),
			'secondaryColor'        => self::get_hex_color_option( self::OPT_SECONDARY_COLOR ),
			'minAppVersion'         => self::sanitize_min_app_version( get_option( self::OPT_MIN_VERSION, '' ) ),
			'minIosBuild'           => self::sanitize_min_ios_build( get_option( self::OPT_MIN_IOS_BUILD, '' ) ),
			'minAndroidVersionCode' => self::sanitize_min_android_version_code( get_option( self::OPT_MIN_ANDROID, 0 ) ),
			'appStoreUrl'           => self::get_store_url( 'ios' ),
			'playStoreUrl'          => self::get_store_url( 'android' ),
			'tier'                  => 'free',
			'apiNamespace'          => self::REST_NAMESPACE,
			'features'              => array(
				'coordinator'         => true,
				'guestPartyId'        => true,
				'reminders'           => false,
				'push'                => false,
				'companionSelfSignup' => false,
				'tier'                => 'free',
			),
			'deepLink'              => 'wprsvp://event?url=' . rawurlencode( $site_url ),
			'joinUrl'               => self::get_join_url( $site_url ),
			'connectUrl'            => self::get_connect_url(),
		);

		/**
		 * Public companion app-config payload for a free install.
		 *
		 * @since 8.4.0
		 * @param array<string, mixed> $config   Payload sent to the companion app.
		 * @param string               $site_url Untrailingslashed site URL.
		 */
		$config = apply_filters( 'wgrsvp_app_config', $config, $site_url );

		return is_array( $config ) ? $config : array();
	}

	/**
	 * Public connect URL for this site.
	 *
	 * @return string
	 */
	public static function get_connect_url() {
		if ( class_exists( 'WPR_Pro_App_Connect' ) && method_exists( 'WPR_Pro_App_Connect', 'connect_url' ) ) {
			return (string) WPR_Pro_App_Connect::connect_url();
		}
		if ( class_exists( 'WGRSVP_App_Connect' ) ) {
			return WGRSVP_App_Connect::connect_url();
		}
		return trailingslashit( home_url( '/app/connect' ) );
	}

	/**
	 * Hub join URL for this site.
	 *
	 * @param string $site_url Untrailingslashed site URL.
	 * @return string
	 */
	private static function get_join_url( $site_url ) {
		if ( class_exists( 'WPR_Pro_Event_Network' ) && method_exists( 'WPR_Pro_Event_Network', 'join_url_for_site' ) ) {
			return (string) WPR_Pro_Event_Network::join_url_for_site( $site_url );
		}
		return self::HUB_JOIN_URL . '?url=' . rawurlencode( $site_url );
	}

	/**
	 * Optional partner display name.
	 *
	 * @param int                  $index    1 or 2.
	 * @param array<string, mixed> $settings General settings array.
	 * @return string
	 */
	private static function get_partner_name( $index, array $settings ) {
		$index = 2 === (int) $index ? 2 : 1;
		$key   = 'partner_' . $index;
		if ( isset( $settings[ $key ] ) ) {
			$name = trim( sanitize_text_field( (string) $settings[ $key ] ) );
			if ( '' !== $name ) {
				return $name;
			}
		}
		return trim( sanitize_text_field( (string) get_option( 'wgrsvp_wedding_partner_' . $index, '' ) ) );
	}

	/**
	 * App store URL (empty by default; filterable for hub-managed builds).
	 *
	 * @param string $platform Either `ios` or `android`.
	 * @return string
	 */
	private static function get_store_url( $platform ) {
		$platform = 'android' === $platform ? 'android' : 'ios';

		/**
		 * Companion store listing URL advertised to the app.
		 *
		 * @since 8.4.0
		 * @param string $url      Store URL (empty string = not published).
		 * @param string $platform Either `ios` or `android`.
		 */
		$url = apply_filters( 'wgrsvp_app_store_url', '', $platform );

		return esc_url_raw( (string) $url );
	}

	/**
	 * Site logo or icon URL.
	 *
	 * @return string
	 */
	private static function get_logo_url() {
		$override = esc_url_raw( (string) get_option( self::OPT_LOGO_URL, '' ) );
		if ( '' !== $override ) {
			return $override;
		}

		$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id > 0 ) {
			$url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
			if ( $url ) {
				return (string) $url;
			}
		}

		$icon = get_site_icon_url( 192 );

		return $icon ? (string) $icon : '';
	}

	/**
	 * Read and validate a stored hex color.
	 *
	 * @param string $option_name Option name.
	 * @return string
	 */
	private static function get_hex_color_option( $option_name ) {
		$color = sanitize_hex_color( (string) get_option( $option_name, '' ) );

		return is_string( $color ) ? $color : '';
	}

	/**
	 * Sanitize the marketing semver floor (empty = no force-update).
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public static function sanitize_min_app_version( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^\d{1,3}\.\d{1,3}(\.\d{1,3})?$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitize the iOS native build floor (numeric string or empty).
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public static function sanitize_min_ios_build( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{1,9}$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^\d{1,3}\.\d{1,3}(\.\d{1,3})?$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Sanitize the Android versionCode floor (0 = none).
	 *
	 * A hard cap keeps a mistyped value from locking every install out.
	 *
	 * @param mixed $value Raw option value.
	 * @return int
	 */
	public static function sanitize_min_android_version_code( $value ) {
		$code = absint( $value );

		/**
		 * Highest accepted Android versionCode floor.
		 *
		 * @since 8.4.0
		 * @param int $max Maximum accepted value.
		 */
		$max = (int) apply_filters( 'wgrsvp_app_min_android_version_code_max', 100000 );
		if ( $max < 1 ) {
			$max = 100000;
		}
		if ( $code > $max ) {
			return 0;
		}

		return $code;
	}
}
