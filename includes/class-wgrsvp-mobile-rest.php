<?php
/**
 * Companion mobile REST API for free installs (coordinator + guest Party ID).
 *
 * Mirrors the request and response shapes of the Pro companion API under the
 * `wgrsvp/v1` namespace so the same mobile app can talk to a free wedding site.
 * Pro-only fields are present but empty so the app never has to branch on tier.
 *
 * When Wedding Party RSVP Pro is installed it registers the richer
 * `wpr-pro/v1/mobile/*` routes, so this module does not boot at all and the
 * companion uses the Pro namespace instead.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free companion mobile REST API.
 */
class WGRSVP_Mobile_REST {

	/**
	 * Guest companion sessions last a year; the Party ID remains the credential.
	 */
	const GUEST_TOKEN_TTL = 31536000;

	/**
	 * Transient prefix for legacy opaque guest tokens.
	 */
	const GUEST_TOKEN_PREFIX = 'wgrsvp_gst_';

	/**
	 * Accepted RSVP statuses.
	 */
	const STATUSES = array( 'Pending', 'Accepted', 'Declined' );

	/**
	 * Boot hooks unless Pro already serves the companion mobile API.
	 *
	 * @return void
	 */
	public static function init() {
		if ( class_exists( 'WPR_Pro_Mobile_REST' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'nocache_mobile_rest' ), 10, 3 );
	}

	/**
	 * Stop CDNs and proxies caching authenticated companion responses.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function nocache_mobile_rest( $response, $server, $request ) {
		unset( $server );
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( 0 !== strpos( (string) $request->get_route(), '/' . WGRSVP_App_Config::REST_NAMESPACE . '/mobile' ) ) {
			return $response;
		}
		if ( $response instanceof WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
		}

		return $response;
	}

	/**
	 * Register companion routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$namespace   = WGRSVP_App_Config::REST_NAMESPACE;
		$coordinator = array( __CLASS__, 'permission_coordinator' );
		$guest       = array( __CLASS__, 'permission_public_guest_session' );

		register_rest_route(
			$namespace,
			'/mobile/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_dashboard' ),
				'permission_callback' => $coordinator,
			)
		);

		register_rest_route(
			$namespace,
			'/mobile/guests',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_list_guests' ),
					'permission_callback' => $coordinator,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_create_guest' ),
					'permission_callback' => $coordinator,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/mobile/guests/(?P<id>\d+)',
			array(
				'args' => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_positive_int' ),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get_guest' ),
					'permission_callback' => $coordinator,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'rest_patch_guest' ),
					'permission_callback' => $coordinator,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'rest_delete_guest' ),
					'permission_callback' => $coordinator,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/mobile/stragglers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_stragglers' ),
				'permission_callback' => $coordinator,
			)
		);

		register_rest_route(
			$namespace,
			'/mobile/guest/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_guest_session' ),
				'permission_callback' => $guest,
			)
		);

		register_rest_route(
			$namespace,
			'/mobile/guest/hub',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_guest_hub' ),
				'permission_callback' => $guest,
			)
		);

		register_rest_route(
			$namespace,
			'/mobile/guest/form-state',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_guest_form_state' ),
				'permission_callback' => $guest,
			)
		);

		register_rest_route(
			$namespace,
			'/mobile/guest/rsvp',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_guest_rsvp' ),
				'permission_callback' => $guest,
			)
		);
	}

	/**
	 * Validate a positive integer route argument.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public static function validate_positive_int( $value ) {
		return is_numeric( $value ) && (int) $value > 0;
	}

	/**
	 * Coordinator capability check for companion write and read routes.
	 *
	 * @return true|WP_Error
	 */
	public static function permission_coordinator() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wgrsvp_rest_auth',
				__( 'Could not authenticate your coordinator session. Sign in on the website again, or ask your host to pass the Authorization header to WordPress.', 'wedding-party-rsvp' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( self::coordinator_capability() ) ) {
			return new WP_Error(
				'wgrsvp_rest_forbidden',
				__( 'Your WordPress account is signed in, but it does not have permission to manage the guest list.', 'wedding-party-rsvp' ),
				array( 'status' => 403 )
			);
		}

		return true;
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
	 * Permission for the public companion guest routes.
	 *
	 * Intentionally public. Wedding guests are not WordPress users, so there is
	 * no account, cookie or nonce to verify. Authorization is the signed guest
	 * session token resolved by resolve_guest_session() inside every callback,
	 * which is issued only after a valid Party ID is presented to
	 * `POST /mobile/guest/session` (itself rate limited per IP). Requests with a
	 * missing, tampered or expired token are rejected with 401 by the callback,
	 * and every query is scoped to the token's own Party ID.
	 *
	 * @return true
	 */
	public static function permission_public_guest_session() {
		return true;
	}

	/**
	 * Guest table name.
	 *
	 * @return string
	 */
	private static function guests_table() {
		global $wpdb;

		return $wpdb->prefix . 'wedding_rsvps';
	}

	/**
	 * Whether a column exists on the guest table.
	 *
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function column_exists( $column ) {
		static $cache = array();

		$column = (string) $column;
		if ( isset( $cache[ $column ] ) ) {
			return $cache[ $column ];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe cached per request.
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::guests_table(), $column )
		);

		$cache[ $column ] = ( null !== $found && '' !== $found );

		return $cache[ $column ];
	}

	/**
	 * Attendance timestamp column for this install, or an empty string.
	 *
	 * Free installs use `wgrsvp_arrived_at`; sites that previously ran Pro may
	 * still carry `wpr_pro_attended_at`.
	 *
	 * @return string
	 */
	private static function attendance_column() {
		static $column = null;
		if ( null !== $column ) {
			return $column;
		}

		$column = '';
		foreach ( array( 'wgrsvp_arrived_at', 'wpr_pro_attended_at' ) as $candidate ) {
			if ( self::column_exists( $candidate ) ) {
				$column = $candidate;
				break;
			}
		}

		return $column;
	}

	/**
	 * Bust cached aggregate guest stats after a companion write.
	 *
	 * @return void
	 */
	private static function bust_guest_caches() {
		do_action( 'wgrsvp_invalidate_guest_caches' );
	}

	/**
	 * GET /mobile/dashboard — coordinator summary counts.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_dashboard() {
		global $wpdb;
		$table = self::guests_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live coordinator dashboard counts.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT rsvp_status, COUNT(*) AS total FROM %i GROUP BY rsvp_status', $table )
		);

		$counts = array(
			'Accepted' => 0,
			'Declined' => 0,
			'Pending'  => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->rsvp_status ) ) {
				continue;
			}
			$status = (string) $row->rsvp_status;
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row->total;
			}
		}

		return new WP_REST_Response(
			array(
				'attending'      => $counts['Accepted'],
				'regrets'        => $counts['Declined'],
				'pending'        => $counts['Pending'],
				'selfAdded'      => 0,
				'meals'          => self::get_meal_counts(),
				'siteName'       => (string) get_bloginfo( 'name' ),
				'households'     => self::get_household_progress(),
				'checkIn'        => self::get_checkin_progress(),
				'galleryPending' => 0,
			)
		);
	}

	/**
	 * Accepted meal choices grouped by menu selection.
	 *
	 * @return array<int, array{menu_choice:string,count:int}>
	 */
	private static function get_meal_counts() {
		global $wpdb;
		$table = self::guests_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live coordinator dashboard counts.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT menu_choice, COUNT(*) AS total
				FROM %i
				WHERE rsvp_status = %s AND TRIM(COALESCE(menu_choice, '')) <> ''
				GROUP BY menu_choice
				ORDER BY total DESC",
				$table,
				'Accepted'
			)
		);

		$segments = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->menu_choice ) ) {
				continue;
			}
			$label = (string) $row->menu_choice;
			if ( '' === $label ) {
				continue;
			}
			$segments[] = array(
				'label' => $label,
				'count' => (int) $row->total,
			);
		}

		/**
		 * Meal breakdown segments shown on dashboards (shared with the admin screen).
		 *
		 * @param array<int, array{label:string,count:int}> $segments Meal segments.
		 * @param array<string, mixed>                      $stats    Raw stats context.
		 */
		$segments = apply_filters( 'wgrsvp_dashboard_meal_stats', $segments, array() );
		if ( ! is_array( $segments ) ) {
			$segments = array();
		}

		$meals = array();
		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}
			$label = '';
			if ( isset( $segment['label'] ) ) {
				$label = (string) $segment['label'];
			} elseif ( isset( $segment['menu_choice'] ) ) {
				$label = (string) $segment['menu_choice'];
			}
			$meals[] = array(
				'menu_choice' => $label,
				'count'       => isset( $segment['count'] ) ? (int) $segment['count'] : 0,
			);
		}

		return $meals;
	}

	/**
	 * Household (party) RSVP progress.
	 *
	 * @return array{total:int,responded:int,pending:int}
	 */
	private static function get_household_progress() {
		global $wpdb;
		$table = self::guests_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live coordinator dashboard counts.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT party_id, SUM(CASE WHEN rsvp_status = %s THEN 1 ELSE 0 END) AS pending_count
				FROM %i
				WHERE party_id IS NOT NULL AND party_id != %s
				GROUP BY party_id',
				'Pending',
				$table,
				''
			)
		);

		$total     = 0;
		$responded = 0;
		$pending   = 0;
		foreach ( (array) $rows as $row ) {
			++$total;
			if ( isset( $row->pending_count ) && (int) $row->pending_count > 0 ) {
				++$pending;
			} else {
				++$responded;
			}
		}

		return array(
			'total'     => $total,
			'responded' => $responded,
			'pending'   => $pending,
		);
	}

	/**
	 * Accepted guests checked in versus expected.
	 *
	 * @return array{accepted:int,checkedIn:int,awaiting:int}
	 */
	private static function get_checkin_progress() {
		global $wpdb;
		$table = self::guests_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live coordinator dashboard counts.
		$accepted = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s', $table, 'Accepted' )
		);

		$checked_in = 0;
		$column     = self::attendance_column();
		if ( '' !== $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live coordinator dashboard counts.
			$checked_in = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND %i IS NOT NULL AND %i != %s',
					$table,
					'Accepted',
					$column,
					$column,
					''
				)
			);
		}

		return array(
			'accepted'  => $accepted,
			'checkedIn' => $checked_in,
			'awaiting'  => max( 0, $accepted - $checked_in ),
		);
	}

	/**
	 * GET /mobile/guests — paginated, filterable guest list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_list_guests( $request ) {
		global $wpdb;
		$table = self::guests_table();

		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status   = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$party_id = sanitize_text_field( (string) $request->get_param( 'party_id' ) );
		$dietary  = sanitize_text_field( (string) $request->get_param( 'dietary' ) );
		$allergy  = sanitize_text_field( (string) $request->get_param( 'allergy' ) );
		$has_flag = sanitize_key( (string) $request->get_param( 'has_flag' ) );

		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$per_page = min( 100, max( 1, $per_page > 0 ? $per_page : 50 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(guest_name LIKE %s OR email LIKE %s OR party_id LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $status ) {
			$where[]  = 'rsvp_status = %s';
			$params[] = $status;
		}
		if ( '' !== $party_id ) {
			$where[]  = 'party_id = %s';
			$params[] = $party_id;
		}
		if ( '' !== $dietary ) {
			$where[]  = 'dietary_restrictions LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $dietary ) . '%';
		}
		if ( '' !== $allergy ) {
			$where[]  = 'allergies LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $allergy ) . '%';
		}
		if ( in_array( $has_flag, array( '1', 'yes', 'true' ), true ) ) {
			$where[] = "(TRIM(COALESCE(dietary_restrictions, '')) <> '' OR TRIM(COALESCE(allergies, '')) <> '')";
		}

		$sql_where = implode( ' AND ', $where );

		$count_args = array_merge( array( $table ), $params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WHERE is built from literal fragments with placeholders; every value is bound through prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $sql_where, ...$count_args ) );

		$list_args = array_merge( array( $table ), $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above; placeholder count is dynamic because the filter set is.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . $sql_where . ' ORDER BY guest_name ASC LIMIT %d OFFSET %d', ...$list_args ) );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::serialize_guest( $row );
		}

		return new WP_REST_Response(
			array(
				'guests'   => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Fetch a single guest row.
	 *
	 * @param int $id Guest ID.
	 * @return object|null
	 */
	private static function get_guest_row( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id < 1 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single guest lookup for a companion request.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::guests_table(), $id )
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Serialize a guest row for the companion app.
	 *
	 * Pro-only fields are included as empty values so the app can use one shape
	 * across both tiers.
	 *
	 * @param object $row Guest row.
	 * @return array<string, mixed>
	 */
	public static function serialize_guest( $row ) {
		$attendance_column = self::attendance_column();
		$attended_at       = '';
		if ( '' !== $attendance_column && isset( $row->{$attendance_column} ) ) {
			$attended_at = (string) $row->{$attendance_column};
		}

		return array(
			'id'                 => isset( $row->id ) ? (int) $row->id : 0,
			'name'               => (string) ( $row->guest_name ?? '' ),
			'email'              => (string) ( $row->email ?? '' ),
			'phone'              => (string) ( $row->phone ?? '' ),
			'partyId'            => (string) ( $row->party_id ?? '' ),
			'status'             => (string) ( $row->rsvp_status ?? '' ),
			'menuChoice'         => (string) ( $row->menu_choice ?? '' ),
			'childMenuChoice'    => (string) ( $row->child_menu_choice ?? '' ),
			'dietaryNotes'       => (string) ( $row->dietary_restrictions ?? '' ),
			'adminNotes'         => (string) ( $row->admin_notes ?? '' ),
			'allergies'          => (string) ( $row->allergies ?? '' ),
			'giftReceived'       => (string) ( $row->gift_received ?? '' ),
			'thankYouCardSentOn' => (string) ( $row->thankyou_card_sent_on ?? '' ),
			'address'            => (string) ( $row->address ?? '' ),
			'songRequest'        => (string) ( $row->song_request ?? '' ),
			'isChild'            => ! empty( $row->is_child ),
			'attendedAt'         => $attended_at,
			'tableId'            => null,
			'tableName'          => (string) ( $row->table_number ?? '' ),
			// Pro-only data: present and empty so the companion needs no tier branch.
			'plannerTags'        => array(),
			'aiNoteTags'         => array(),
			'subEventInvites'    => array(),
			'customAnswers'      => array(),
			'arrivalFlight'      => '',
			'hotelName'          => '',
			'checkinUrl'         => '',
		);
	}

	/**
	 * GET /mobile/guests/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get_guest( $request ) {
		$row = self::get_guest_row( absint( $request['id'] ) );
		if ( ! $row ) {
			return new WP_Error(
				'wgrsvp_not_found',
				__( 'Guest not found.', 'wedding-party-rsvp' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( self::serialize_guest( $row ) );
	}

	/**
	 * PATCH /mobile/guests/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_patch_guest( $request ) {
		global $wpdb;

		$id = absint( $request['id'] );
		if ( $id < 1 ) {
			return new WP_Error(
				'wgrsvp_bad_id',
				__( 'Invalid guest.', 'wedding-party-rsvp' ),
				array( 'status' => 400 )
			);
		}
		if ( ! self::get_guest_row( $id ) ) {
			return new WP_Error(
				'wgrsvp_not_found',
				__( 'Guest not found.', 'wedding-party-rsvp' ),
				array( 'status' => 404 )
			);
		}

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$map = array(
			'status'             => array( 'rsvp_status', 'sanitize_text_field' ),
			'menuChoice'         => array( 'menu_choice', 'sanitize_text_field' ),
			'childMenuChoice'    => array( 'child_menu_choice', 'sanitize_text_field' ),
			'dietaryNotes'       => array( 'dietary_restrictions', 'sanitize_textarea_field' ),
			'adminNotes'         => array( 'admin_notes', 'sanitize_textarea_field' ),
			'allergies'          => array( 'allergies', 'sanitize_textarea_field' ),
			'giftReceived'       => array( 'gift_received', 'sanitize_textarea_field' ),
			'thankYouCardSentOn' => array( 'thankyou_card_sent_on', 'sanitize_text_field' ),
			'email'              => array( 'email', 'sanitize_email' ),
			'phone'              => array( 'phone', 'sanitize_text_field' ),
			'name'               => array( 'guest_name', 'sanitize_text_field' ),
			'address'            => array( 'address', 'sanitize_textarea_field' ),
			'songRequest'        => array( 'song_request', 'sanitize_text_field' ),
			'partyId'            => array( 'party_id', 'sanitize_text_field' ),
			'tableName'          => array( 'table_number', 'sanitize_text_field' ),
		);

		$data   = array();
		$format = array();
		foreach ( $map as $json_key => $meta ) {
			if ( ! array_key_exists( $json_key, $params ) ) {
				continue;
			}
			$data[ $meta[0] ] = call_user_func( $meta[1], wp_unslash( (string) $params[ $json_key ] ) );
			$format[]         = '%s';
		}
		if ( array_key_exists( 'isChild', $params ) ) {
			$data['is_child'] = empty( $params['isChild'] ) ? 0 : 1;
			$format[]         = '%d';
		}
		if ( isset( $data['rsvp_status'] ) && ! in_array( $data['rsvp_status'], self::STATUSES, true ) ) {
			$data['rsvp_status'] = 'Pending';
		}

		if ( array() === $data ) {
			return new WP_Error(
				'wgrsvp_no_fields',
				__( 'No fields to update.', 'wedding-party-rsvp' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Companion guest edit.
		$updated = $wpdb->update( self::guests_table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
		if ( false === $updated ) {
			return new WP_Error(
				'wgrsvp_update_failed',
				__( 'Could not update guest.', 'wedding-party-rsvp' ),
				array( 'status' => 500 )
			);
		}

		self::bust_guest_caches();

		/**
		 * After the companion app updates a guest.
		 *
		 * @since 8.4.0
		 * @param int                  $id   Guest ID.
		 * @param array<string, mixed> $data Updated columns.
		 */
		do_action( 'wgrsvp_mobile_guest_updated', $id, $data );

		$row = self::get_guest_row( $id );

		return new WP_REST_Response( $row ? self::serialize_guest( $row ) : array( 'id' => $id ) );
	}

	/**
	 * POST /mobile/guests — create a guest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_create_guest( $request ) {
		global $wpdb;

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$name     = sanitize_text_field( (string) ( $params['name'] ?? '' ) );
		$party_id = sanitize_text_field( (string) ( $params['partyId'] ?? $params['party_id'] ?? '' ) );
		if ( '' === $name || '' === $party_id ) {
			return new WP_Error(
				'wgrsvp_guest_required',
				__( 'Name and Party ID are required.', 'wedding-party-rsvp' ),
				array( 'status' => 400 )
			);
		}

		$status = sanitize_text_field( (string) ( $params['status'] ?? 'Pending' ) );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'Pending';
		}

		$data = array(
			'guest_name'           => $name,
			'party_id'             => $party_id,
			'email'                => sanitize_email( (string) ( $params['email'] ?? '' ) ),
			'phone'                => sanitize_text_field( (string) ( $params['phone'] ?? '' ) ),
			'rsvp_status'          => $status,
			'menu_choice'          => sanitize_text_field( (string) ( $params['menuChoice'] ?? '' ) ),
			'child_menu_choice'    => sanitize_text_field( (string) ( $params['childMenuChoice'] ?? '' ) ),
			'dietary_restrictions' => sanitize_textarea_field( (string) ( $params['dietaryNotes'] ?? '' ) ),
			'allergies'            => sanitize_textarea_field( (string) ( $params['allergies'] ?? '' ) ),
			'admin_notes'          => sanitize_textarea_field( (string) ( $params['adminNotes'] ?? '' ) ),
			'address'              => sanitize_textarea_field( (string) ( $params['address'] ?? '' ) ),
			'song_request'         => sanitize_text_field( (string) ( $params['songRequest'] ?? '' ) ),
			'is_child'             => empty( $params['isChild'] ) ? 0 : 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Companion guest creation.
		$inserted = $wpdb->insert(
			self::guests_table(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( false === $inserted ) {
			return new WP_Error(
				'wgrsvp_create_failed',
				__( 'Could not create guest.', 'wedding-party-rsvp' ),
				array( 'status' => 500 )
			);
		}

		$id = (int) $wpdb->insert_id;
		self::bust_guest_caches();

		/**
		 * After the companion app creates a guest.
		 *
		 * @since 8.4.0
		 * @param int                  $id   Guest ID.
		 * @param array<string, mixed> $data Inserted columns.
		 */
		do_action( 'wgrsvp_mobile_guest_created', $id, $data );

		$row = self::get_guest_row( $id );

		return new WP_REST_Response( $row ? self::serialize_guest( $row ) : array( 'id' => $id ), 201 );
	}

	/**
	 * DELETE /mobile/guests/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete_guest( $request ) {
		global $wpdb;

		$id = absint( $request['id'] );
		if ( $id < 1 ) {
			return new WP_Error(
				'wgrsvp_bad_id',
				__( 'Invalid guest.', 'wedding-party-rsvp' ),
				array( 'status' => 400 )
			);
		}
		if ( ! self::get_guest_row( $id ) ) {
			return new WP_Error(
				'wgrsvp_not_found',
				__( 'Guest not found.', 'wedding-party-rsvp' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Companion guest deletion.
		$deleted = $wpdb->delete( self::guests_table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error(
				'wgrsvp_delete_failed',
				__( 'Could not delete guest.', 'wedding-party-rsvp' ),
				array( 'status' => 500 )
			);
		}

		self::bust_guest_caches();

		/**
		 * After the companion app deletes a guest.
		 *
		 * @since 8.4.0
		 * @param int $id Guest ID.
		 */
		do_action( 'wgrsvp_mobile_guest_deleted', $id );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * GET /mobile/stragglers — follow-up counts for the coordinator.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_stragglers() {
		global $wpdb;
		$table = self::guests_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live follow-up counts.
		$missing_email         = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (email IS NULL OR TRIM(COALESCE(email, '')) = '')", $table )
		);
		$missing_phone         = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (phone IS NULL OR TRIM(COALESCE(phone, '')) = '')", $table )
		);
		$missing_address       = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (address IS NULL OR TRIM(COALESCE(address, '')) = '')", $table )
		);
		$pending               = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s', $table, 'Pending' )
		);
		$missing_meal          = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND TRIM(COALESCE(menu_choice, '')) = '' AND TRIM(COALESCE(child_menu_choice, '')) = ''",
				$table,
				'Pending'
			)
		);
		$accepted_missing_meal = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND TRIM(COALESCE(menu_choice, '')) = '' AND TRIM(COALESCE(child_menu_choice, '')) = ''",
				$table,
				'Accepted'
			)
		);
		// phpcs:enable

		return new WP_REST_Response(
			array(
				'missingEmail'        => $missing_email,
				'missingPhone'        => $missing_phone,
				'missingAddress'      => $missing_address,
				'pending'             => $pending,
				'missingMeal'         => $missing_meal,
				'acceptedMissingMeal' => $accepted_missing_meal,
			)
		);
	}

	/**
	 * Issue a signed guest token (no server-side record required).
	 *
	 * Format: g1.{base64url(party_id|expires|nonce)}.{hmac_hex}
	 *
	 * @param string $party_id Party ID.
	 * @return string
	 */
	public static function issue_guest_token( $party_id ) {
		$party_id  = strtoupper( sanitize_text_field( (string) $party_id ) );
		$expires   = time() + self::GUEST_TOKEN_TTL;
		$nonce     = wp_generate_password( 16, false, false );
		$payload   = $party_id . '|' . $expires . '|' . $nonce;
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding for a signed token, not obfuscation.
		$encoded = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );

		return 'g1.' . $encoded . '.' . $signature;
	}

	/**
	 * Parse a signed (or legacy transient) guest token.
	 *
	 * @param string $token Raw token.
	 * @return array{party_id:string}|null
	 */
	public static function parse_guest_token( $token ) {
		$token = (string) $token;

		if ( 0 === strpos( $token, 'g1.' ) ) {
			$parts = explode( '.', $token );
			if ( 3 !== count( $parts ) ) {
				return null;
			}
			$encoded = strtr( $parts[1], '-_', '+/' );
			$padding = strlen( $encoded ) % 4;
			if ( $padding ) {
				$encoded .= str_repeat( '=', 4 - $padding );
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own signed token payload.
			$payload = base64_decode( $encoded, true );
			if ( ! is_string( $payload ) || '' === $payload ) {
				return null;
			}
			$expected = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
			if ( ! hash_equals( $expected, $parts[2] ) ) {
				return null;
			}
			$bits = explode( '|', $payload );
			if ( 3 !== count( $bits ) ) {
				return null;
			}
			$party_id = strtoupper( sanitize_text_field( $bits[0] ) );
			$expires  = (int) $bits[1];
			if ( '' === $party_id || $expires < time() ) {
				return null;
			}

			return array( 'party_id' => $party_id );
		}

		$data = get_transient( self::GUEST_TOKEN_PREFIX . md5( $token ) );
		if ( ! is_array( $data ) || empty( $data['party_id'] ) ) {
			return null;
		}

		return array( 'party_id' => sanitize_text_field( (string) $data['party_id'] ) );
	}

	/**
	 * Resolve the guest session from the Authorization or guest-token header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{party_id:string}|WP_Error
	 */
	public static function resolve_guest_session( $request ) {
		$token = '';
		$auth  = $request->get_header( 'authorization' );
		if ( is_string( $auth ) && preg_match( '/^Bearer\s+(\S+)/i', $auth, $matches ) ) {
			$token = $matches[1];
		}
		if ( '' === $token ) {
			$token = (string) $request->get_header( 'x-wprsvp-guest-token' );
		}

		// Signed tokens must not be passed through sanitize_text_field, which can
		// alter valid characters; the allow-list below is the sanitizer instead.
		$token = trim( (string) wp_unslash( $token ) );
		$token = (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', $token );

		if ( strlen( $token ) < 16 ) {
			return new WP_Error(
				'wgrsvp_guest_auth',
				__( 'Guest session required.', 'wedding-party-rsvp' ),
				array( 'status' => 401 )
			);
		}

		$data = self::parse_guest_token( $token );
		if ( ! is_array( $data ) || empty( $data['party_id'] ) ) {
			return new WP_Error(
				'wgrsvp_guest_expired',
				__( 'Guest session expired. Enter your Party ID again.', 'wedding-party-rsvp' ),
				array( 'status' => 401 )
			);
		}

		return array( 'party_id' => sanitize_text_field( (string) $data['party_id'] ) );
	}

	/**
	 * POST /mobile/guest/session — exchange a Party ID for a session token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_guest_session( $request ) {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : $request->get_params();
		$party_id = isset( $params['partyId'] ) ? sanitize_text_field( (string) $params['partyId'] ) : '';
		$party_id = strtoupper( trim( $party_id ) );

		if ( strlen( $party_id ) < 3 ) {
			return new WP_Error(
				'wgrsvp_bad_party',
				__( 'Enter a valid Party ID.', 'wedding-party-rsvp' ),
				array( 'status' => 400 )
			);
		}

		$rate = self::guest_session_rate_limit();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Party ID lookup for a guest sign-in.
		$found = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE party_id = %s', self::guests_table(), $party_id )
		);
		if ( $found < 1 ) {
			return new WP_Error(
				'wgrsvp_party_not_found',
				__( 'We could not find that Party ID.', 'wedding-party-rsvp' ),
				array( 'status' => 404 )
			);
		}

		$token = self::issue_guest_token( $party_id );

		return new WP_REST_Response(
			array(
				'token'     => $token,
				'partyId'   => $party_id,
				'expiresIn' => self::GUEST_TOKEN_TTL,
			),
			201
		);
	}

	/**
	 * Rate-limit guest session creation per IP.
	 *
	 * @return true|WP_Error
	 */
	private static function guest_session_rate_limit() {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( '' === $ip ) {
			$ip = 'unknown';
		}

		/**
		 * Maximum guest Party ID sign-in attempts per IP per hour.
		 *
		 * @since 8.4.0
		 * @param int $max Attempt ceiling.
		 */
		$max = (int) apply_filters( 'wgrsvp_guest_session_rate_limit', 30 );
		if ( $max < 1 ) {
			$max = 30;
		}

		$key   = self::GUEST_TOKEN_PREFIX . 'rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new WP_Error(
				'wgrsvp_rate_limited',
				__( 'Too many attempts. Try again later.', 'wedding-party-rsvp' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * GET /mobile/guest/hub.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_guest_hub( $request ) {
		$session = self::resolve_guest_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return new WP_REST_Response( self::build_guest_hub( $session['party_id'] ) );
	}

	/**
	 * GET /mobile/guest/form-state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_guest_form_state( $request ) {
		$session = self::resolve_guest_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return new WP_REST_Response( self::build_guest_hub( $session['party_id'] ) );
	}

	/**
	 * Build the party-scoped guest hub payload.
	 *
	 * @param string $party_id Party ID.
	 * @return array<string, mixed>
	 */
	private static function build_guest_hub( $party_id ) {
		global $wpdb;

		$party_id = sanitize_text_field( (string) $party_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Party-scoped guest hub.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, guest_name, rsvp_status, menu_choice, child_menu_choice, appetizer_choice, hors_doeuvre_choice, dessert_choice, dietary_restrictions, allergies, song_request, address, is_child
				FROM %i
				WHERE party_id = %s
				ORDER BY id ASC',
				self::guests_table(),
				$party_id
			)
		);

		$guests = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			$guests[] = array(
				'id'                => (int) $row->id,
				'name'              => (string) ( $row->guest_name ?? '' ),
				'status'            => (string) ( $row->rsvp_status ?? '' ),
				'menuChoice'        => (string) ( $row->menu_choice ?? '' ),
				'childMenuChoice'   => (string) ( $row->child_menu_choice ?? '' ),
				'appetizerChoice'   => (string) ( $row->appetizer_choice ?? '' ),
				'horsDoeuvreChoice' => (string) ( $row->hors_doeuvre_choice ?? '' ),
				'dessertChoice'     => (string) ( $row->dessert_choice ?? '' ),
				'dietaryNotes'      => (string) ( $row->dietary_restrictions ?? '' ),
				'allergies'         => (string) ( $row->allergies ?? '' ),
				'songRequest'       => (string) ( $row->song_request ?? '' ),
				'address'           => (string) ( $row->address ?? '' ),
				'isChild'           => ! empty( $row->is_child ),
				// Pro-only data: present and empty so the companion needs no tier branch.
				'arrivalFlight'     => '',
				'hotelName'         => '',
				'subEvents'         => array(),
			);
		}

		$settings = get_option( 'wgrsvp_general_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$event_title = isset( $settings['event_title'] ) ? trim( (string) $settings['event_title'] ) : '';
		if ( '' === $event_title && isset( $settings['welcome_title'] ) ) {
			$event_title = trim( (string) $settings['welcome_title'] );
		}
		if ( '' === $event_title ) {
			$event_title = (string) get_bloginfo( 'name' );
		}

		$location = isset( $settings['event_location'] ) ? trim( (string) $settings['event_location'] ) : '';
		$maps_url = '' !== $location
			? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $location )
			: '';

		$payload = array(
			'partyId'             => $party_id,
			'eventTitle'          => $event_title,
			'eventStart'          => isset( $settings['event_start'] ) ? trim( (string) $settings['event_start'] ) : '',
			'eventLocation'       => $location,
			'mapsUrl'             => $maps_url,
			'menuOptions'         => self::menu_options_list( 'wgrsvp_menu_options' ),
			'childMenuOptions'    => self::menu_options_list( 'wgrsvp_child_menu_options' ),
			'showTransportFields' => false,
			'guests'              => $guests,
		);

		/**
		 * Companion guest hub payload for a free install.
		 *
		 * @since 8.4.0
		 * @param array<string, mixed> $payload  Hub data returned to the app.
		 * @param string               $party_id Party ID.
		 */
		$payload = apply_filters( 'wgrsvp_mobile_guest_hub', $payload, $party_id );

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Read a stored menu option list for the companion pickers.
	 *
	 * @param string $option_name Option name.
	 * @return array<int, string>
	 */
	private static function menu_options_list( $option_name ) {
		$raw = get_option( $option_name, '' );
		if ( ! is_array( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', (string) $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$options = array();
		foreach ( $raw as $line ) {
			if ( is_array( $line ) ) {
				$line = isset( $line['label'] ) ? $line['label'] : reset( $line );
			}
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( '' !== $line ) {
				$options[] = $line;
			}
		}

		return array_values( array_unique( $options ) );
	}

	/**
	 * POST /mobile/guest/rsvp — save RSVP answers for the session's party.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_guest_rsvp( $request ) {
		$session = self::resolve_guest_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$party_id = $session['party_id'];

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$guests = isset( $params['guests'] ) && is_array( $params['guests'] ) ? $params['guests'] : array();
		if ( array() === $guests ) {
			return new WP_Error(
				'wgrsvp_no_guests',
				__( 'No guest updates provided.', 'wedding-party-rsvp' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;
		$table   = self::guests_table();
		$updated = 0;

		foreach ( $guests as $guest ) {
			if ( ! is_array( $guest ) || empty( $guest['id'] ) ) {
				continue;
			}
			$id = absint( $guest['id'] );
			if ( $id < 1 ) {
				continue;
			}

			// A guest session may only edit rows inside its own party.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership check for a guest-scoped write.
			$belongs = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d AND party_id = %s', $table, $id, $party_id )
			);
			if ( $belongs < 1 ) {
				continue;
			}

			$data = array();
			if ( isset( $guest['status'] ) ) {
				$status = sanitize_text_field( (string) $guest['status'] );
				if ( in_array( $status, self::STATUSES, true ) ) {
					$data['rsvp_status'] = $status;
				}
			}
			if ( isset( $guest['menuChoice'] ) ) {
				$data['menu_choice'] = sanitize_text_field( (string) $guest['menuChoice'] );
			}
			if ( isset( $guest['childMenuChoice'] ) ) {
				$data['child_menu_choice'] = sanitize_text_field( (string) $guest['childMenuChoice'] );
			}
			if ( isset( $guest['appetizerChoice'] ) ) {
				$data['appetizer_choice'] = sanitize_text_field( (string) $guest['appetizerChoice'] );
			}
			if ( isset( $guest['horsDoeuvreChoice'] ) ) {
				$data['hors_doeuvre_choice'] = sanitize_text_field( (string) $guest['horsDoeuvreChoice'] );
			}
			if ( isset( $guest['dessertChoice'] ) ) {
				$data['dessert_choice'] = sanitize_text_field( (string) $guest['dessertChoice'] );
			}
			if ( isset( $guest['dietaryNotes'] ) ) {
				$data['dietary_restrictions'] = sanitize_textarea_field( (string) $guest['dietaryNotes'] );
			}
			if ( isset( $guest['allergies'] ) ) {
				$data['allergies'] = sanitize_textarea_field( (string) $guest['allergies'] );
			}
			if ( isset( $guest['songRequest'] ) ) {
				$data['song_request'] = sanitize_text_field( (string) $guest['songRequest'] );
			}
			if ( isset( $guest['address'] ) ) {
				$data['address'] = sanitize_textarea_field( (string) $guest['address'] );
			}

			if ( array() === $data ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guest RSVP save.
			$wpdb->update( $table, $data, array( 'id' => $id ), array_fill( 0, count( $data ), '%s' ), array( '%d' ) );
			++$updated;
		}

		self::bust_guest_caches();

		/**
		 * After the companion app saves guest RSVP answers.
		 *
		 * @since 8.4.0
		 * @param string $party_id Party ID.
		 * @param int    $updated  Rows updated.
		 */
		do_action( 'wgrsvp_mobile_guest_rsvp_saved', $party_id, $updated );

		/**
		 * Shared party save hook, also fired by the website RSVP form.
		 *
		 * @param string $party_id Party ID.
		 */
		do_action( 'wgrsvp_after_rsvp_save', $party_id );

		return new WP_REST_Response(
			array(
				'updated' => $updated,
				'hub'     => self::build_guest_hub( $party_id ),
			)
		);
	}
}
