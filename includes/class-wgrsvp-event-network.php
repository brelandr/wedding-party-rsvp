<?php
/**
 * Register this free wedding site with the Wedding RSVP App Network hub.
 *
 * Posts directory metadata to weddingrsvp.pro when an administrator clicks
 * Connect on Wedding RSVP → Mobile App. Guest lists are never sent. The hub
 * verifies this site via wgrsvp/v1/app-config and stores tier=free.
 *
 * When Wedding Party RSVP Pro is active, Pro owns the Connect UI instead.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free spoke → hub network registration.
 */
class WGRSVP_Event_Network {

	/**
	 * Default hub base URL.
	 */
	const DEFAULT_HUB = 'https://weddingrsvp.pro';

	/**
	 * Local connect status (autoload no).
	 */
	const OPT_STATUS = 'wgrsvp_network_status';

	/**
	 * Wedding directory fields (autoload no).
	 */
	const OPT_PARTY_NAME = 'wgrsvp_wedding_party_name';
	const OPT_PARTNER_1  = 'wgrsvp_wedding_partner_1';
	const OPT_PARTNER_2  = 'wgrsvp_wedding_partner_2';
	const OPT_CITY       = 'wgrsvp_wedding_city';
	const OPT_STATE      = 'wgrsvp_wedding_state';
	const OPT_ZIP        = 'wgrsvp_wedding_zip';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_wgrsvp_network_connect', array( __CLASS__, 'handle_connect' ) );
	}

	/**
	 * Whether Pro owns App Network connect on this site.
	 *
	 * @return bool
	 */
	public static function pro_owns_connect() {
		return class_exists( 'WPR_Pro_Event_Network', false );
	}

	/**
	 * Hub base URL (filterable).
	 *
	 * @return string
	 */
	public static function hub_url() {
		$url = (string) apply_filters( 'wgrsvp_network_hub_url', self::DEFAULT_HUB );
		$url = untrailingslashit( esc_url_raw( $url ) );
		return $url ? $url : self::DEFAULT_HUB;
	}

	/**
	 * Local connection status array.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_local_status() {
		$status = get_option( self::OPT_STATUS, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Handle Connect form POST.
	 *
	 * @return void
	 */
	public static function handle_connect() {
		if ( ! isset( $_POST['wgrsvp_network_connect_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_network_connect_nonce'] ) ), 'wgrsvp_network_connect' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
		}

		if ( self::pro_owns_connect() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpr-pro-mobile-app&wpr_pro_network=1' ) );
			exit;
		}

		$party_name = isset( $_POST['wgrsvp_wedding_party_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_wedding_party_name'] ) ) : '';
		$partner_1  = isset( $_POST['wgrsvp_wedding_partner_1'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_wedding_partner_1'] ) ) : '';
		$partner_2  = isset( $_POST['wgrsvp_wedding_partner_2'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_wedding_partner_2'] ) ) : '';
		$city       = isset( $_POST['wgrsvp_wedding_city'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_wedding_city'] ) ) : '';
		$state      = isset( $_POST['wgrsvp_wedding_state'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_wedding_state'] ) ) : '';
		$zip        = isset( $_POST['wgrsvp_wedding_zip'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_wedding_zip'] ) ) : '';
		$token      = isset( $_POST['wgrsvp_hub_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_hub_token'] ) ) : '';

		if ( ! $party_name ) {
			update_option(
				self::OPT_STATUS,
				array(
					'state'   => 'error',
					'message' => __( 'Wedding / party name is required so guests can find the right listing.', 'wedding-party-rsvp' ),
					'at'      => time(),
				),
				false
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . WGRSVP_Mobile_Admin::MENU_SLUG . '&wgrsvp_network=1' ) );
			exit;
		}

		update_option( self::OPT_PARTY_NAME, $party_name, false );
		update_option( self::OPT_PARTNER_1, $partner_1, false );
		update_option( self::OPT_PARTNER_2, $partner_2, false );
		update_option( self::OPT_CITY, $city, false );
		update_option( self::OPT_STATE, $state, false );
		update_option( self::OPT_ZIP, $zip, false );

		$config = class_exists( 'WGRSVP_App_Config', false ) ? WGRSVP_App_Config::get_config() : array();
		$body   = array(
			'name'     => $party_name,
			'siteUrl'  => isset( $config['siteUrl'] ) ? $config['siteUrl'] : untrailingslashit( home_url() ),
			'logoUrl'  => isset( $config['logoUrl'] ) ? $config['logoUrl'] : '',
			'partner1' => $partner_1,
			'partner2' => $partner_2,
			'city'     => $city,
			'state'    => $state,
			'zip'      => $zip,
		);
		if ( $token ) {
			$body['token'] = $token;
		}

		$response = wp_safe_remote_post(
			self::hub_url() . '/wp-json/wpr-pro/v1/network/register',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			update_option(
				self::OPT_STATUS,
				array(
					'state'   => 'error',
					'message' => sanitize_text_field( $response->get_error_message() ),
					'at'      => time(),
				),
				false
			);
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
				update_option(
					self::OPT_STATUS,
					array(
						'state'   => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'active',
						'message' => '',
						'at'      => time(),
						'hubId'   => isset( $data['id'] ) ? (int) $data['id'] : 0,
						'tier'    => isset( $data['tier'] ) ? sanitize_key( (string) $data['tier'] ) : 'free',
					),
					false
				);
			} else {
				$msg = is_array( $data ) && ! empty( $data['message'] )
					? sanitize_text_field( (string) $data['message'] )
					: __( 'Registration failed.', 'wedding-party-rsvp' );
				update_option(
					self::OPT_STATUS,
					array(
						'state'   => 'error',
						'message' => $msg,
						'at'      => time(),
					),
					false
				);
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . WGRSVP_Mobile_Admin::MENU_SLUG . '&wgrsvp_network=1' ) );
		exit;
	}
}
