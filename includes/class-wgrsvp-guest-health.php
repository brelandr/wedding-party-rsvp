<?php
/**
 * Guest list health tiles on the Wedding Dashboard (actionable counts + deep links).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Guest_Health' ) ) {

	/**
	 * Surfaces exception counts for planners (mixed households, meal gaps, etc.).
	 */
	class WGRSVP_Guest_Health {

		/**
		 * Cached dashboard health metrics (expensive aggregate queries).
		 */
		private const TRANSIENT_METRICS = 'wedding-party-rsvp_guest_health_metrics';

		/**
		 * Bootstrap.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'wgrsvp_guest_list_after_title', array( __CLASS__, 'render_tiles' ), 7, 2 );
		}

		/**
		 * Guest list table name (with prefix).
		 *
		 * @return string
		 */
		private static function guests_table() {
			global $wpdb;

			return $wpdb->prefix . 'wedding_rsvps';
		}

		/**
		 * Run aggregate SQL for dashboard health counts.
		 *
		 * @return array<string,int>
		 */
		private static function compute_metrics() {
			global $wpdb;
			$t = self::guests_table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Dashboard aggregate; table via %i.
			$mixed_parties = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM (
						SELECT party_id FROM %i
						GROUP BY party_id
						HAVING SUM(CASE WHEN rsvp_status = %s THEN 1 ELSE 0 END) > 0
						AND SUM(CASE WHEN rsvp_status <> %s THEN 1 ELSE 0 END) > 0
					) wgrsvp_mixed',
					$t,
					'Pending',
					'Pending'
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$accepted_missing_meal = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND (
						( ( is_child IS NULL OR is_child = 0 ) AND ( menu_choice IS NULL OR TRIM(menu_choice) = %s ) )
						OR ( is_child = 1 AND ( child_menu_choice IS NULL OR TRIM(child_menu_choice) = %s ) )
					)',
					$t,
					'Accepted',
					'',
					''
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$pending_no_contact = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND (
						( email IS NULL OR TRIM(email) = %s )
						AND ( phone IS NULL OR TRIM(phone) = %s )
					)',
					$t,
					'Pending',
					'',
					''
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- allergies OR dietary (guests often put notes in either field).
			$accepted_with_allergies = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND (
						( allergies IS NOT NULL AND TRIM(allergies) <> %s )
						OR ( dietary_restrictions IS NOT NULL AND TRIM(dietary_restrictions) <> %s )
					)',
					$t,
					'Accepted',
					'',
					''
				)
			);

			$sub_event_pending = 0;
			if ( class_exists( 'WPR_Pro_Sub_Events', false ) ) {
				$inv = WPR_Pro_Sub_Events::full_table_name( WPR_Pro_Sub_Events::TABLE_SUFFIX_INVITES );
				$rsv = WPR_Pro_Sub_Events::full_table_name( WPR_Pro_Sub_Events::TABLE_SUFFIX_RSVPS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$inv_ok = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $inv ) ) === (string) $inv;
				if ( $inv_ok ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$sub_event_pending = (int) $wpdb->get_var(
						$wpdb->prepare(
							'SELECT COUNT(*) FROM %i i INNER JOIN %i r ON i.guest_id = r.guest_id AND i.sub_event_id = r.sub_event_id WHERE r.rsvp_status = %s',
							$inv,
							$rsv,
							'Pending'
						)
					);
				}
			}

			return array(
				'mixed_parties'           => $mixed_parties,
				'accepted_missing_meal'   => $accepted_missing_meal,
				'pending_no_contact'      => $pending_no_contact,
				'accepted_with_allergies' => $accepted_with_allergies,
				'sub_event_pending'       => $sub_event_pending,
			);
		}

		/**
		 * Metrics with 24h transient (invalidated when guest data changes).
		 *
		 * @return array<string,int>
		 */
		private static function get_cached_metrics() {
			$cached = get_transient( self::TRANSIENT_METRICS );
			if ( is_array( $cached ) ) {
				$required = array( 'mixed_parties', 'accepted_missing_meal', 'pending_no_contact', 'accepted_with_allergies', 'sub_event_pending' );
				foreach ( $required as $key ) {
					if ( ! array_key_exists( $key, $cached ) ) {
						$cached = null;
						break;
					}
				}
			} else {
				$cached = null;
			}
			if ( is_array( $cached ) ) {
				return array(
					'mixed_parties'           => (int) $cached['mixed_parties'],
					'accepted_missing_meal'   => (int) $cached['accepted_missing_meal'],
					'pending_no_contact'      => (int) $cached['pending_no_contact'],
					'accepted_with_allergies' => (int) $cached['accepted_with_allergies'],
					'sub_event_pending'       => (int) $cached['sub_event_pending'],
				);
			}

			$metrics = self::compute_metrics();
			set_transient( self::TRANSIENT_METRICS, $metrics, DAY_IN_SECONDS );

			return $metrics;
		}

		/**
		 * Drop health metrics cache (e.g. after RSVP / guest list changes).
		 *
		 * @return void
		 */
		public static function bust_metrics_cache() {
			delete_transient( self::TRANSIENT_METRICS );
		}

		/**
		 * Output health tiles on the guest list admin screen.
		 *
		 * @param bool   $can_manage_rsvp Admin may edit.
		 * @param object $plugin          Main plugin instance.
		 * @return void
		 */
		public static function render_tiles( $can_manage_rsvp, $plugin ) {
			unset( $can_manage_rsvp );
			if ( ! is_object( $plugin ) || ! current_user_can( WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD ) ) {
				return;
			}

			$m   = self::get_cached_metrics();
			$any = ( $m['mixed_parties'] + $m['accepted_missing_meal'] + $m['pending_no_contact'] + $m['sub_event_pending'] ) > 0;

			// Always deep-link to the guest list (Ops Center may be hidden via Admin menu visibility).
			$url_mixed           = add_query_arg(
				array(
					'page'         => 'wedding-rsvp-main',
					'wgrsvp_gap'   => 'mixed_household',
					'wgrsvp_group' => '1',
				),
				admin_url( 'admin.php' )
			);
			$url_meal_gap        = add_query_arg(
				array(
					'page'          => 'wedding-rsvp-main',
					'filter_status' => 'Accepted',
					'wgrsvp_gap'    => 'accepted_meal_not_set',
				),
				admin_url( 'admin.php' )
			);
			$url_pending_contact = admin_url( 'admin.php?page=wedding-rsvp-main&wgrsvp_gap=pending_no_contact' );
			$url_allergies       = add_query_arg(
				array(
					'page'          => 'wedding-rsvp-main',
					'filter_status' => 'Accepted',
					'wgrsvp_gap'    => 'accepted_with_allergies',
				),
				admin_url( 'admin.php' )
			);
			$sub_reports         = admin_url( 'admin.php?page=wedding-rsvp-sub-event-reports' );

			?>
			<div class="wgrsvp-guest-health wgrsvp-guest-health-grid" role="region" aria-label="<?php echo esc_attr__( 'Guest list health', 'wedding-party-rsvp' ); ?>">
				<h2 class="wgrsvp-guest-health-heading"><?php esc_html_e( 'Guest list health', 'wedding-party-rsvp' ); ?></h2>
				<p class="description wgrsvp-guest-health-intro"><?php esc_html_e( 'Quick checks so nothing slips through before you talk to catering or the venue.', 'wedding-party-rsvp' ); ?></p>
				<div class="wgrsvp-guest-health-tiles">
					<a class="<?php echo esc_attr( 'wgrsvp-health-tile ' . ( $m['mixed_parties'] > 0 ? 'wgrsvp-health-tile-warn' : 'wgrsvp-health-tile-ok' ) ); ?>" href="<?php echo esc_url( $url_mixed ); ?>">
						<span class="wgrsvp-health-tile-count"><?php echo esc_html( (string) (int) $m['mixed_parties'] ); ?></span>
						<span class="wgrsvp-health-tile-label"><?php esc_html_e( 'Mixed households', 'wedding-party-rsvp' ); ?></span>
						<span class="wgrsvp-health-tile-hint"><?php esc_html_e( 'Party has both Pending and answered guests', 'wedding-party-rsvp' ); ?></span>
					</a>
					<a class="<?php echo esc_attr( 'wgrsvp-health-tile ' . ( $m['accepted_missing_meal'] > 0 ? 'wgrsvp-health-tile-warn' : 'wgrsvp-health-tile-ok' ) ); ?>" href="<?php echo esc_url( $url_meal_gap ); ?>">
						<span class="wgrsvp-health-tile-count"><?php echo esc_html( (string) (int) $m['accepted_missing_meal'] ); ?></span>
						<span class="wgrsvp-health-tile-label"><?php esc_html_e( 'Attending, meal not set', 'wedding-party-rsvp' ); ?></span>
						<span class="wgrsvp-health-tile-hint"><?php esc_html_e( 'Accepted but entrée / child meal blank', 'wedding-party-rsvp' ); ?></span>
					</a>
					<a class="<?php echo esc_attr( 'wgrsvp-health-tile ' . ( $m['pending_no_contact'] > 0 ? 'wgrsvp-health-tile-warn' : 'wgrsvp-health-tile-ok' ) ); ?>" href="<?php echo esc_url( $url_pending_contact ); ?>">
						<span class="wgrsvp-health-tile-count"><?php echo esc_html( (string) (int) $m['pending_no_contact'] ); ?></span>
						<span class="wgrsvp-health-tile-label"><?php esc_html_e( 'Pending, no email or phone', 'wedding-party-rsvp' ); ?></span>
						<span class="wgrsvp-health-tile-hint"><?php esc_html_e( 'Harder to nudge — add contact or reach out manually', 'wedding-party-rsvp' ); ?></span>
					</a>
					<a class="wgrsvp-health-tile wgrsvp-health-tile-info" href="<?php echo esc_url( $url_allergies ); ?>">
						<span class="wgrsvp-health-tile-count"><?php echo esc_html( (string) (int) $m['accepted_with_allergies'] ); ?></span>
						<span class="wgrsvp-health-tile-label"><?php esc_html_e( 'Attending with allergies noted', 'wedding-party-rsvp' ); ?></span>
						<span class="wgrsvp-health-tile-hint"><?php esc_html_e( 'Allergies or dietary notes on Accepted guests — cross-check catering', 'wedding-party-rsvp' ); ?></span>
					</a>
					<?php if ( class_exists( 'WPR_Pro_Sub_Events', false ) ) : ?>
					<a class="<?php echo esc_attr( 'wgrsvp-health-tile ' . ( $m['sub_event_pending'] > 0 ? 'wgrsvp-health-tile-warn' : 'wgrsvp-health-tile-ok' ) ); ?>" href="<?php echo esc_url( $sub_reports ); ?>">
						<span class="wgrsvp-health-tile-count"><?php echo esc_html( (string) (int) $m['sub_event_pending'] ); ?></span>
						<span class="wgrsvp-health-tile-label"><?php esc_html_e( 'Side-event replies pending', 'wedding-party-rsvp' ); ?></span>
						<span class="wgrsvp-health-tile-hint"><?php esc_html_e( 'Rehearsal, brunch, etc. (Pro)', 'wedding-party-rsvp' ); ?></span>
					</a>
					<?php endif; ?>
				</div>
				<?php if ( ! $any ) : ?>
					<p class="description wgrsvp-guest-health-allclear"><?php esc_html_e( 'No mixed households, meal gaps, or hard-to-reach pending guests flagged right now.', 'wedding-party-rsvp' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
