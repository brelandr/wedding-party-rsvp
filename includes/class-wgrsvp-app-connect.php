<?php
/**
 * Companion account connect for free installs: website sign-in to one-time code.
 *
 * A coordinator opens `/app/connect` on the wedding site, signs in with their
 * normal WordPress account, and the site issues an Application Password plus a
 * short-lived claim code. The companion app trades that code for the credential
 * through `POST /wgrsvp/v1/auth/claim`, so the website password never leaves the
 * browser.
 *
 * Wedding Party RSVP Pro ships the same flow with additional options. When the
 * Pro class is present this module does not boot at all, so the rewrite rule,
 * the connect page and the claim route are registered exactly once.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free companion connect flow.
 */
class WGRSVP_App_Connect {

	/**
	 * Rewrite-flush marker so the rule is added once per structure change.
	 */
	const OPT_REWRITE = 'wgrsvp_app_connect_rewrite_flushed';

	/**
	 * Current rewrite revision stored in OPT_REWRITE.
	 */
	const REWRITE_VERSION = '1';

	/**
	 * Claim codes are single-use and expire quickly.
	 */
	const CODE_TTL = 300;

	/**
	 * Application Password label shown on the user profile screen.
	 */
	const APP_PASSWORD_NAME = 'Wedding Party RSVP Companion';

	/**
	 * Transient prefix for pending claim codes.
	 */
	const TRANSIENT_PREFIX = 'wgrsvp_auth_';

	/**
	 * Query var set by the `/app/connect` rewrite rule.
	 */
	const QUERY_VAR = 'wgrsvp_app_connect';

	/**
	 * Boot hooks unless Pro already owns the connect flow.
	 *
	 * @return void
	 */
	public static function init() {
		if ( class_exists( 'WPR_Pro_App_Connect' ) ) {
			return;
		}

		// Many hosts strip Authorization before PHP sees it; restore as early as possible.
		if ( did_action( 'plugins_loaded' ) ) {
			self::maybe_restore_authorization_header();
		} else {
			add_action( 'plugins_loaded', array( __CLASS__, 'maybe_restore_authorization_header' ), 1 );
		}

		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_via_companion_header' ), 21 );
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'cors_headers' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_connect_page' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Public connect URL for this site.
	 *
	 * @return string
	 */
	public static function connect_url() {
		// Trailing slash matches the WordPress canonical redirect.
		return trailingslashit( home_url( '/app/connect' ) );
	}

	/**
	 * Whether a user may connect the companion as a coordinator.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can_connect( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}

		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, self::coordinator_capability() );
	}

	/**
	 * Capability granted by the wedding coordinator role.
	 *
	 * Resolved through the role class when it is loaded so the two stay in sync.
	 *
	 * @return string
	 */
	private static function coordinator_capability() {
		if ( class_exists( 'WGRSVP_Coordinator_Role' ) ) {
			return (string) WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD;
		}

		return 'wgrsvp_view_guest_dashboard';
	}

	/**
	 * Allow companion auth headers through the REST CORS allow-list.
	 *
	 * @param string[] $headers Allowed headers.
	 * @return string[]
	 */
	public static function cors_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}
		$headers[] = 'Authorization';
		$headers[] = 'X-WPRSVP-Authorization';
		$headers[] = 'X-WPRSVP-Guest-Token';
		$headers[] = 'X-WPRSVP-Client';

		return array_values( array_unique( $headers ) );
	}

	/**
	 * Copy companion / CGI Authorization fallbacks into PHP_AUTH_*.
	 *
	 * CGI, FastCGI and several nginx configurations drop the Authorization
	 * header, which breaks Application Passwords for the companion REST API.
	 * The companion therefore sends a duplicate `X-WPRSVP-Authorization` header,
	 * which is preferred here because some hosts leave an empty Authorization
	 * header behind that would otherwise populate PHP_AUTH_* with nothing.
	 *
	 * @param bool $force Overwrite existing PHP_AUTH_* from the companion header.
	 * @return void
	 */
	public static function maybe_restore_authorization_header( $force = false ) {
		$candidates = array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Basic auth header parsed and validated below.
		if ( ! empty( $_SERVER['HTTP_X_WPRSVP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
			$candidates[] = (string) wp_unslash( $_SERVER['HTTP_X_WPRSVP_AUTHORIZATION'] );
		}
		if ( ! $force && ( ! empty( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['PHP_AUTH_PW'] ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
			$candidates[] = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
			$candidates[] = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		foreach ( $candidates as $header ) {
			$header = trim( $header );
			if ( ! preg_match( '/^Basic\s+(.+)$/i', $header, $matches ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic auth is base64 by specification.
			$decoded = base64_decode( $matches[1], true );
			if ( ! is_string( $decoded ) || false === strpos( $decoded, ':' ) ) {
				continue;
			}
			list( $user, $pass ) = explode( ':', $decoded, 2 );
			if ( '' === $user || '' === $pass ) {
				continue;
			}
			$_SERVER['PHP_AUTH_USER']      = $user;
			$_SERVER['PHP_AUTH_PW']        = $pass;
			$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . $matches[1];
			return;
		}
	}

	/**
	 * Authenticate an Application Password from companion headers when WordPress did not.
	 *
	 * @param int|false $user_id Current user ID resolved so far.
	 * @return int|false
	 */
	public static function authenticate_via_companion_header( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Presence check only.
		$has_companion = ! empty( $_SERVER['HTTP_X_WPRSVP_AUTHORIZATION'] );
		if ( $has_companion ) {
			self::maybe_restore_authorization_header( true );
		} elseif ( empty( $_SERVER['PHP_AUTH_USER'] ) || empty( $_SERVER['PHP_AUTH_PW'] ) ) {
			self::maybe_restore_authorization_header( false );
		}

		if ( empty( $_SERVER['PHP_AUTH_USER'] ) || empty( $_SERVER['PHP_AUTH_PW'] ) ) {
			return $user_id;
		}
		if ( ! function_exists( 'wp_authenticate_application_password' ) ) {
			return $user_id;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Credential passed verbatim to wp_authenticate_application_password().
		$username = (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above; altering a password would break authentication.
		$password = (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] );
		// Application Passwords are usually copied with the display spaces intact.
		$password = str_replace( ' ', '', $password );

		$authenticated = self::try_application_password( $username, $password );
		if ( $authenticated instanceof WP_User ) {
			return (int) $authenticated->ID;
		}

		// Application Passwords accept a login name; map an email address to one.
		if ( is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
			if ( $user ) {
				$authenticated = self::try_application_password( $user->user_login, $password );
				if ( $authenticated instanceof WP_User ) {
					return (int) $authenticated->ID;
				}
			}
		}

		return $user_id;
	}

	/**
	 * Attempt Application Password authentication as an API request.
	 *
	 * @param string $username Login name.
	 * @param string $password Application Password.
	 * @return WP_User|WP_Error|null
	 */
	private static function try_application_password( $username, $password ) {
		// Ensure WordPress treats this as an API request even before REST_REQUEST is set.
		add_filter( 'application_password_is_api_request', '__return_true', 99 );
		$authenticated = wp_authenticate_application_password( null, $username, $password );
		remove_filter( 'application_password_is_api_request', '__return_true', 99 );

		return $authenticated;
	}

	/**
	 * Register the `/app/connect` rewrite rule.
	 *
	 * @return void
	 */
	public static function register_rewrite() {
		add_rewrite_rule( '^app/connect/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		if ( (string) get_option( self::OPT_REWRITE ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::OPT_REWRITE, self::REWRITE_VERSION, false );
		}
	}

	/**
	 * Allow the connect query var.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Register the claim route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			WGRSVP_App_Config::REST_NAMESPACE,
			'/auth/claim',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_claim_auth_code' ),
				'permission_callback' => array( __CLASS__, 'permission_public_auth_claim' ),
				'args'                => array(
					'code' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_claim_code' ),
					),
				),
			)
		);
	}

	/**
	 * Permission for POST /wgrsvp/v1/auth/claim.
	 *
	 * Intentionally public. The companion app claims its credential before it
	 * owns one, so there is no cookie, nonce or Application Password to check at
	 * this point. Authorization is the single-use claim code itself: it is
	 * generated only for a signed-in coordinator, is stored in a transient that
	 * expires after CODE_TTL seconds, is deleted the first time it is read, and
	 * claim attempts are rate limited per IP inside the callback.
	 *
	 * @param WP_REST_Request $request Request (unused).
	 * @return true
	 */
	public static function permission_public_auth_claim( $request ) {
		unset( $request );

		return true;
	}

	/**
	 * Validate the shape of a claim code before the callback runs.
	 *
	 * @param mixed $value Submitted code.
	 * @return bool
	 */
	public static function validate_claim_code( $value ) {
		return (bool) preg_match( '/^[A-Za-z0-9]{8,32}$/', (string) $value );
	}

	/**
	 * Allow only companion / Expo deep-link return bases, never website URLs.
	 *
	 * @param string $url Candidate return URL supplied by the app.
	 * @return string
	 */
	public static function sanitize_return_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		// The app may send an already-encoded value.
		$url = rawurldecode( $url );

		// Strip anything that could break out of an HTML attribute or add a second scheme.
		$url = preg_replace( '#[^A-Za-z0-9:/?&=._~%+@\-]#', '', $url );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		// Companion custom scheme, or an Expo Go / dev-client URL such as exp://192.168.0.10:8081/--/auth.
		if ( preg_match( '#^wprsvp://#i', $url ) || preg_match( '#^exps?://#i', $url ) ) {
			return $url;
		}

		return '';
	}

	/**
	 * Return URL supplied by the companion (GET `return=` or POST `wgrsvp_return`).
	 *
	 * @return string
	 */
	public static function request_return_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deep-link base only; restricted to app schemes by sanitize_return_url() and the POST action itself is nonce-checked.
		$from_post = isset( $_POST['wgrsvp_return'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_return'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Optional deep-link base on a public page; see above.
		$from_get = isset( $_GET['return'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['return'] ) ) : '';

		return self::sanitize_return_url( '' !== $from_post ? $from_post : $from_get );
	}

	/**
	 * Build the deep link that returns to the companion (or Expo Go).
	 *
	 * @param string $site        Site URL.
	 * @param string $code        Claim code.
	 * @param string $return_base Optional return base supplied by the app.
	 * @return string
	 */
	public static function build_auth_deep_link( $site, $code, $return_base = '' ) {
		$query = 'site=' . rawurlencode( (string) $site ) . '&code=' . rawurlencode( (string) $code );
		$base  = self::sanitize_return_url( $return_base );
		if ( '' !== $base ) {
			$separator = false !== strpos( $base, '?' ) ? '&' : '?';
			return $base . $separator . $query;
		}

		return 'wprsvp://auth?' . $query;
	}

	/**
	 * Generate a claim code.
	 *
	 * @return string
	 */
	public static function generate_code() {
		$code = strtoupper( substr( (string) preg_replace( '/[^A-Z0-9]/i', '', wp_generate_password( 16, false, false ) ), 0, 12 ) );
		if ( strlen( $code ) < 8 ) {
			$code = strtoupper( substr( md5( (string) microtime( true ) ), 0, 12 ) );
		}

		return $code;
	}

	/**
	 * Create an Application Password plus a one-time claim code for a user.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $return_base Optional companion/Expo return base.
	 * @return array{code:string,deepLink:string,expires:int}|WP_Error
	 */
	public static function issue_auth_code_for_user( $user_id, $return_base = '' ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'wgrsvp_user_missing',
				__( 'User not found.', 'wedding-party-rsvp' ),
				array( 'status' => 404 )
			);
		}
		if ( ! self::user_can_connect( $user_id ) ) {
			return new WP_Error(
				'wgrsvp_forbidden',
				__( 'Only wedding coordinators can connect the companion app.', 'wedding-party-rsvp' ),
				array( 'status' => 403 )
			);
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error(
				'wgrsvp_app_passwords_unavailable',
				__( 'Application Passwords are not available on this site. Enable HTTPS and update WordPress.', 'wedding-party-rsvp' ),
				array( 'status' => 500 )
			);
		}

		self::delete_existing_companion_passwords( $user_id );

		$created = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name' => self::APP_PASSWORD_NAME,
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$password = is_array( $created ) && isset( $created[0] ) ? (string) $created[0] : '';
		if ( '' === $password ) {
			return new WP_Error(
				'wgrsvp_app_password_failed',
				__( 'Could not create an Application Password.', 'wedding-party-rsvp' ),
				array( 'status' => 500 )
			);
		}

		$code = self::generate_code();
		$site = untrailingslashit( home_url() );
		set_transient(
			self::TRANSIENT_PREFIX . $code,
			array(
				'siteUrl'     => $site,
				'username'    => (string) $user->user_login,
				'appPassword' => $password,
				'userId'      => $user_id,
			),
			self::CODE_TTL
		);

		/**
		 * After a companion connect code is issued.
		 *
		 * @since 8.4.0
		 * @param int $user_id Coordinator user ID.
		 */
		do_action( 'wgrsvp_app_connect_code_issued', $user_id );

		return array(
			'code'     => $code,
			'deepLink' => self::build_auth_deep_link( $site, $code, $return_base ),
			'expires'  => self::CODE_TTL,
		);
	}

	/**
	 * Remove earlier companion Application Passwords so they do not accumulate.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function delete_existing_companion_passwords( $user_id ) {
		if (
			! method_exists( 'WP_Application_Passwords', 'get_user_application_passwords' )
			|| ! method_exists( 'WP_Application_Passwords', 'delete_application_password' )
		) {
			return;
		}

		$existing = WP_Application_Passwords::get_user_application_passwords( (int) $user_id );
		if ( ! is_array( $existing ) ) {
			return;
		}
		foreach ( $existing as $item ) {
			$name = isset( $item['name'] ) ? (string) $item['name'] : '';
			$uuid = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
			if ( '' !== $uuid && 0 === strcasecmp( $name, self::APP_PASSWORD_NAME ) ) {
				WP_Application_Passwords::delete_application_password( (int) $user_id, $uuid );
			}
		}
	}

	/**
	 * POST /wgrsvp/v1/auth/claim — body { code }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_claim_auth_code( $request ) {
		$rate = self::check_claim_rate_limit();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$code   = isset( $params['code'] ) ? strtoupper( (string) preg_replace( '/[^A-Z0-9]/i', '', (string) $params['code'] ) ) : '';
		if ( strlen( $code ) < 8 ) {
			return new WP_Error(
				'wgrsvp_invalid_code',
				__( 'Enter a valid connect code.', 'wedding-party-rsvp' ),
				array( 'status' => 400 )
			);
		}

		$key  = self::TRANSIENT_PREFIX . $code;
		$data = get_transient( $key );
		// Single use: the code is consumed whether or not it was valid.
		delete_transient( $key );

		if ( ! is_array( $data ) || empty( $data['username'] ) || empty( $data['appPassword'] ) ) {
			return new WP_Error(
				'wgrsvp_code_expired',
				__( 'That connect code is invalid or expired. Sign in on the website again.', 'wedding-party-rsvp' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'siteUrl'     => isset( $data['siteUrl'] ) ? (string) $data['siteUrl'] : untrailingslashit( home_url() ),
				'username'    => (string) $data['username'],
				'appPassword' => (string) $data['appPassword'],
			),
			200
		);
	}

	/**
	 * Rate-limit claim attempts per IP.
	 *
	 * @return true|WP_Error
	 */
	public static function check_claim_rate_limit() {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( '' === $ip ) {
			$ip = 'unknown';
		}

		/**
		 * Maximum companion claim attempts per IP per hour.
		 *
		 * @since 8.4.0
		 * @param int $max Attempt ceiling.
		 */
		$max = (int) apply_filters( 'wgrsvp_auth_claim_rate_limit', 40 );
		if ( $max < 1 ) {
			$max = 40;
		}

		$key   = 'wgrsvp_auth_claim_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new WP_Error(
				'wgrsvp_rate_limited',
				__( 'Too many connect attempts. Try again later.', 'wedding-party-rsvp' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Render `/app/connect` and handle the connect POST.
	 *
	 * @return void
	 */
	public static function maybe_render_connect_page() {
		if ( ! self::is_connect_request() ) {
			return;
		}

		// Coordinators sign in with the standard WordPress login screen.
		if ( ! is_user_logged_in() ) {
			$redirect_to = self::connect_url();
			$return_base = self::request_return_url();
			if ( '' !== $return_base ) {
				$redirect_to = add_query_arg( 'return', rawurlencode( $return_base ), $redirect_to );
			}
			wp_safe_redirect( wp_login_url( $redirect_to ) );
			exit;
		}

		$error  = '';
		$issued = null;

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		if ( 'POST' === $method ) {
			$result = self::handle_connect_post();
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$issued = $result;
			}
		} elseif ( self::request_wants_auto_issue() ) {
			$result = self::issue_auth_code_for_user( get_current_user_id(), self::request_return_url() );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$issued = $result;
			}
		}

		self::render_connect_html( $error, $issued, self::request_return_url() );
		exit;
	}

	/**
	 * Whether the current request targets the connect page.
	 *
	 * @return bool
	 */
	private static function is_connect_request() {
		if ( (bool) get_query_var( self::QUERY_VAR ) ) {
			return true;
		}

		// Fallback for installs whose rewrite rules have not been flushed yet.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against a literal pattern only.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		return (bool) preg_match( '#/app/connect/?(\?|$)#', $uri );
	}

	/**
	 * Whether the app asked the page to issue a code immediately.
	 *
	 * @return bool
	 */
	private static function request_wants_auto_issue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence flag only; the code is issued for the already-authenticated user.
		return isset( $_GET['auto'] );
	}

	/**
	 * Process the connect form submission.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function handle_connect_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified on the next statement.
		$nonce = isset( $_POST['wgrsvp_connect_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_connect_nonce'] ) ) : '';
		if ( ! isset( $_POST['wgrsvp_connect_nonce'] ) || ! wp_verify_nonce( $nonce, 'wgrsvp_app_connect_issue' ) ) {
			return new WP_Error(
				'wgrsvp_bad_nonce',
				__( 'Security check failed. Refresh and try again.', 'wedding-party-rsvp' )
			);
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wgrsvp_login_required',
				__( 'Sign in first.', 'wedding-party-rsvp' )
			);
		}

		return self::issue_auth_code_for_user( get_current_user_id(), self::request_return_url() );
	}

	/**
	 * Output the standalone connect page.
	 *
	 * A minimal standalone document is used instead of a theme template because
	 * the page is opened inside the companion app's in-app browser, which the
	 * active theme is not expected to style. Styles are enqueued through
	 * wp_enqueue_style() so no inline style or script tag is printed.
	 *
	 * @param string                    $error       Error message, if any.
	 * @param array<string, mixed>|null $issued      Issued code payload.
	 * @param string                    $return_base Optional companion/Expo return base.
	 * @return void
	 */
	private static function render_connect_html( $error, $issued, $return_base = '' ) {
		$user        = wp_get_current_user();
		$site_name   = (string) get_bloginfo( 'name' );
		$return_base = self::sanitize_return_url( $return_base );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		wp_enqueue_style(
			'wgrsvp-app-connect',
			plugins_url( 'assets/css/wgrsvp-app-connect.css', WGRSVP_PLUGIN_FILE ),
			array(),
			WGRSVP_VERSION
		);

		echo '<!DOCTYPE html><html ';
		language_attributes();
		echo '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex, nofollow" />';
		echo '<title>' . esc_html__( 'Connect Wedding RSVP', 'wedding-party-rsvp' ) . '</title>';
		wp_print_styles( array( 'wgrsvp-app-connect' ) );
		echo '</head><body class="wgrsvp-connect"><div class="wgrsvp-connect__wrap">';

		echo '<h1>' . esc_html__( 'Connect to Wedding RSVP', 'wedding-party-rsvp' ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: site name. */
				__( 'Connect the Wedding RSVP app to %s. Your website password stays in this browser; the app receives a separate Application Password it can revoke at any time.', 'wedding-party-rsvp' ),
				$site_name
			)
		) . '</p>';

		if ( '' !== $error ) {
			echo '<div class="wgrsvp-connect__error">' . esc_html( $error ) . '</div>';
		}

		if ( is_array( $issued ) && ! empty( $issued['deepLink'] ) ) {
			self::render_issued_code( $issued );
			echo '</div></body></html>';
			return;
		}

		if ( ! self::user_can_connect( (int) $user->ID ) ) {
			echo '<p class="wgrsvp-connect__meta">' . esc_html__( 'This account cannot manage the guest list, so it cannot connect the companion app. Ask the site administrator for a coordinator account, then return here.', 'wedding-party-rsvp' ) . '</p>';
			echo '<p class="wgrsvp-connect__meta"><a href="' . esc_url( wp_logout_url( self::connect_url() ) ) . '">' . esc_html__( 'Use a different account', 'wedding-party-rsvp' ) . '</a></p>';
			echo '</div></body></html>';
			return;
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: username. */
				__( 'Signed in as %s.', 'wedding-party-rsvp' ),
				$user->user_login
			)
		) . '</p>';

		echo '<form method="post" action="' . esc_url( self::connect_url() ) . '">';
		wp_nonce_field( 'wgrsvp_app_connect_issue', 'wgrsvp_connect_nonce' );
		if ( '' !== $return_base ) {
			echo '<input type="hidden" name="wgrsvp_return" value="' . esc_attr( $return_base ) . '" />';
		}
		echo '<button class="wgrsvp-connect__btn wgrsvp-connect__btn--primary" type="submit">' . esc_html__( 'Open Wedding RSVP app', 'wedding-party-rsvp' ) . '</button>';
		echo '</form>';

		$logout_redirect = self::connect_url();
		if ( '' !== $return_base ) {
			$logout_redirect = add_query_arg( 'return', rawurlencode( $return_base ), $logout_redirect );
		}
		echo '<p class="wgrsvp-connect__meta"><a href="' . esc_url( wp_logout_url( $logout_redirect ) ) . '">' . esc_html__( 'Use a different account', 'wedding-party-rsvp' ) . '</a></p>';

		echo '</div></body></html>';
	}

	/**
	 * Output the issued deep link and fallback code.
	 *
	 * @param array<string, mixed> $issued Issued payload.
	 * @return void
	 */
	private static function render_issued_code( array $issued ) {
		// esc_url() would strip the wprsvp:// and exp:// schemes, so the value is
		// restricted to those schemes by sanitize_return_url() / build_auth_deep_link()
		// and escaped for the attribute context here.
		$deep_link = (string) $issued['deepLink'];
		$code      = isset( $issued['code'] ) ? (string) $issued['code'] : '';

		echo '<div class="wgrsvp-connect__ok">' . esc_html__( 'Connected. Open the Wedding RSVP app to finish.', 'wedding-party-rsvp' ) . '</div>';
		echo '<a class="wgrsvp-connect__btn wgrsvp-connect__btn--primary" href="' . esc_attr( $deep_link ) . '">' . esc_html__( 'Open Wedding RSVP app', 'wedding-party-rsvp' ) . '</a>';

		if ( '' !== $code ) {
			echo '<p><strong>' . esc_html__( 'Or paste this connect code in the app:', 'wedding-party-rsvp' ) . '</strong></p>';
			echo '<p class="wgrsvp-connect__code">' . esc_html( $code ) . '</p>';
		}

		echo '<p class="wgrsvp-connect__meta">' . esc_html__( 'If the app does not open, copy the code above, return to Wedding RSVP and tap Claim code. Codes expire after a few minutes.', 'wedding-party-rsvp' ) . '</p>';
	}
}
