<?php
/**
 * Companion account deactivation and deletion requests.
 *
 * Required by App Store Guideline 5.1.1(v): an app that creates an account must
 * let the user delete it from inside the app. The request revokes every
 * companion Application Password, marks the WordPress account as pending
 * deletion so it can no longer sign in, and emails the site administrator.
 *
 * The wedding site keeps ownership of guest list data, so the WordPress user is
 * not hard-deleted automatically; the administrator completes removal.
 *
 * Wedding Party RSVP Pro ships the same endpoint, so this module does not boot
 * when the Pro class is present.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free companion account deletion endpoint.
 */
class WGRSVP_Account {

	/**
	 * User meta recording the deletion state.
	 */
	const META_STATUS = '_wgrsvp_account_status';

	/**
	 * User meta recording when deletion was requested.
	 */
	const META_REQUESTED = '_wgrsvp_account_deletion_requested_at';

	/**
	 * Value stored in META_STATUS once deletion is requested.
	 */
	const STATUS_PENDING = 'pending_deletion';

	/**
	 * Boot hooks unless Pro already serves the endpoint.
	 *
	 * @return void
	 */
	public static function init() {
		if ( class_exists( 'WPR_Pro_Account' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_pending_deletion_login' ), 20, 2 );
	}

	/**
	 * Register the account deletion route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			WGRSVP_App_Config::REST_NAMESPACE,
			'/account/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_delete_account' ),
				'permission_callback' => array( __CLASS__, 'permission_signed_in' ),
				'args'                => array(
					'confirm' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Any authenticated WordPress user may delete their own account.
	 *
	 * The endpoint always acts on the authenticated user, so no capability is
	 * required beyond a valid session (an Application Password from the
	 * companion app, or a cookie).
	 *
	 * @return true|WP_Error
	 */
	public static function permission_signed_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wgrsvp_rest_auth',
				__( 'You must be signed in to delete your account.', 'wedding-party-rsvp' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Whether a user has requested deletion.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_pending_deletion( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}

		return self::STATUS_PENDING === (string) get_user_meta( $user_id, self::META_STATUS, true );
	}

	/**
	 * Block password and Application Password login for a pending deletion.
	 *
	 * @param WP_User|WP_Error $user     User or error from earlier filters.
	 * @param string           $password Unused.
	 * @return WP_User|WP_Error
	 */
	public static function block_pending_deletion_login( $user, $password = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required filter signature.
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $user;
		}
		if ( self::is_pending_deletion( (int) $user->ID ) ) {
			return new WP_Error(
				'wgrsvp_account_pending_deletion',
				__( 'This account is pending deletion and can no longer sign in. Contact the site administrator if you need help.', 'wedding-party-rsvp' )
			);
		}

		return $user;
	}

	/**
	 * POST /wgrsvp/v1/account/delete — body { confirm: "DELETE" }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete_account( $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : $request->get_params();
		$confirm = isset( $params['confirm'] ) ? trim( sanitize_text_field( (string) $params['confirm'] ) ) : '';

		if ( 'DELETE' !== $confirm ) {
			return new WP_Error(
				'wgrsvp_confirm_required',
				__( 'Send confirm: "DELETE" to start account deletion.', 'wedding-party-rsvp' ),
				array( 'status' => 400 )
			);
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new WP_Error(
				'wgrsvp_forbidden',
				__( 'You must be signed in to delete your account.', 'wedding-party-rsvp' ),
				array( 'status' => 401 )
			);
		}

		$result = self::request_deletion( $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Revoke Application Passwords, mark pending deletion, notify the administrator.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function request_deletion( $user_id ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'wgrsvp_user_missing',
				__( 'User not found.', 'wedding-party-rsvp' ),
				array( 'status' => 404 )
			);
		}

		$revoked = false;
		if ( class_exists( 'WP_Application_Passwords' ) && method_exists( 'WP_Application_Passwords', 'delete_all_application_passwords' ) ) {
			WP_Application_Passwords::delete_all_application_passwords( $user_id );
			$revoked = true;
		}

		update_user_meta( $user_id, self::META_STATUS, self::STATUS_PENDING );
		update_user_meta( $user_id, self::META_REQUESTED, time() );

		self::email_admin_deletion_request( $user );

		/**
		 * After a companion account deletion request.
		 *
		 * @since 8.4.0
		 * @param int     $user_id User ID.
		 * @param WP_User $user    User object.
		 */
		do_action( 'wgrsvp_account_deletion_requested', $user_id, $user );

		return array(
			'status'              => self::STATUS_PENDING,
			'revokedAppPasswords' => $revoked,
			'message'             => __( 'Your account is deactivated for the Wedding RSVP app and a deletion request was emailed to the site administrator. Application Passwords for this account were revoked. Guest list data stays on the wedding website until the administrator removes it.', 'wedding-party-rsvp' ),
		);
	}

	/**
	 * Email the site administrator about a deletion request.
	 *
	 * @param WP_User $user Requesting user.
	 * @return void
	 */
	private static function email_admin_deletion_request( $user ) {
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email || ! is_email( (string) $admin_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Companion account deletion request', 'wedding-party-rsvp' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = sprintf(
			/* translators: 1: username, 2: email address, 3: user ID. */
			__( "A coordinator requested account deletion from the Wedding RSVP companion app.\n\nUsername: %1\$s\nEmail: %2\$s\nUser ID: %3\$d\n\nApplication Passwords were revoked and the account is marked pending_deletion, so it can no longer sign in. Guest list data was not deleted automatically — remove or reassign this user in WordPress if needed.", 'wedding-party-rsvp' ),
			$user->user_login,
			$user->user_email,
			(int) $user->ID
		);

		wp_mail( $admin_email, $subject, $body );
	}
}
