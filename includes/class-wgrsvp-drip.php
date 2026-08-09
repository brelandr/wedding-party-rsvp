<?php
/**
 * Multi-step drip journey engine (enrollment, email steps, cron).
 *
 * Hybrid anchors: relative (enrolled_at + delay_days) or deadline_offsets
 * (legacy deadline nudge migration). SMS steps are skipped on Free unless
 * Pro handles them via the wgrsvp_drip_send_sms filter.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Drip' ) ) {

	/**
	 * Site-wide drip journey: schema, cron tick, admin forms, companion helpers.
	 */
	class WGRSVP_Drip {

		const CRON_HOOK          = 'wgrsvp_drip_tick';
		const OPT_JOURNEY        = 'wgrsvp_drip_journey';
		const OPT_DB             = 'wgrsvp_drip_db_version';
		const DB_VERSION         = '1.0.0';
		const MAX_STEPS          = 5;
		const MAX_SENDS_PER_TICK = 50;
		const JOURNEY_ID         = 'primary';

		/**
		 * Register cron, schema, and admin-post handlers.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
			add_action( 'init', array( __CLASS__, 'maybe_migrate_schema' ), 31 );
			add_action( 'init', array( __CLASS__, 'maybe_auto_migrate_from_deadline' ), 32 );
			add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
			add_action( 'admin_post_wgrsvp_drip_save', array( __CLASS__, 'handle_save_journey' ) );
			add_action( 'admin_post_wgrsvp_drip_migrate', array( __CLASS__, 'handle_migrate_from_deadline' ) );
			add_action( 'admin_post_wgrsvp_drip_run_now', array( __CLASS__, 'handle_run_due_now' ) );
			add_action( 'wgrsvp_after_factory_reset', array( __CLASS__, 'purge_on_factory_reset' ) );
		}

		/**
		 * When no journey option exists yet and legacy deadline nudges are on, seed a journey once.
		 *
		 * @return void
		 */
		public static function maybe_auto_migrate_from_deadline() {
			if ( false !== get_option( self::OPT_JOURNEY, false ) ) {
				return;
			}
			$settings = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $settings ) || empty( $settings['deadline_nudges_enabled'] ) ) {
				return;
			}
			self::migrate_from_deadline_nudge();
		}

		/**
		 * Delete drip state/send rows for guest IDs (privacy erase).
		 *
		 * @param array<int,int|string> $guest_ids Guest IDs.
		 * @return void
		 */
		public static function purge_guest_ids( $guest_ids ) {
			global $wpdb;
			if ( ! is_array( $guest_ids ) || empty( $guest_ids ) ) {
				return;
			}
			$ids = array_values( array_filter( array_map( 'absint', $guest_ids ) ) );
			if ( empty( $ids ) ) {
				return;
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders built from count(ids).
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE guest_id IN (' . $placeholders . ')', array_merge( array( self::state_table() ), $ids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE guest_id IN (' . $placeholders . ')', array_merge( array( self::sends_table() ), $ids ) ) );
		}

		/**
		 * Prefixed drip guest-state table.
		 *
		 * @return string
		 */
		public static function state_table() {
			global $wpdb;
			return $wpdb->prefix . 'wgrsvp_drip_state';
		}

		/**
		 * Prefixed drip send-log table.
		 *
		 * @return string
		 */
		public static function sends_table() {
			global $wpdb;
			return $wpdb->prefix . 'wgrsvp_drip_sends';
		}

		/**
		 * Schedule hourly tick when missing.
		 *
		 * @return void
		 */
		public static function maybe_schedule() {
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				return;
			}
			wp_schedule_event( time() + wp_rand( 60, 600 ), 'hourly', self::CRON_HOOK );
		}

		/**
		 * Clear scheduled drip cron (call from main plugin deactivation).
		 *
		 * @return void
		 */
		public static function clear_cron() {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		/**
		 * Drop drip tables and options after free factory reset.
		 *
		 * @return void
		 */
		public static function purge_on_factory_reset() {
			global $wpdb;
			self::clear_cron();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::state_table() ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::sends_table() ) );
			delete_option( self::OPT_JOURNEY );
			delete_option( self::OPT_DB );
		}

		/**
		 * Create/upgrade custom tables when schema version changes.
		 *
		 * @return void
		 */
		public static function maybe_migrate_schema() {
			$installed = (string) get_option( self::OPT_DB, '' );
			if ( self::DB_VERSION === $installed ) {
				return;
			}
			self::install_db();
			update_option( self::OPT_DB, self::DB_VERSION, false );
		}

		/**
		 * Run dbDelta for drip state + sends tables.
		 *
		 * @return void
		 */
		public static function install_db() {
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();
			$state           = self::state_table();
			$sends           = self::sends_table();

			$sql_state = "CREATE TABLE $state (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				guest_id bigint(20) unsigned NOT NULL,
				journey_id varchar(32) NOT NULL DEFAULT '',
				step_id varchar(32) NOT NULL DEFAULT '',
				enrolled_at datetime NOT NULL,
				last_sent_at datetime DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				exit_reason varchar(40) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY guest_journey (guest_id, journey_id),
				KEY status_enrolled (status, enrolled_at)
			) $charset_collate;";

			$sql_sends = "CREATE TABLE $sends (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				guest_id bigint(20) unsigned NOT NULL,
				journey_id varchar(32) NOT NULL DEFAULT '',
				step_id varchar(32) NOT NULL DEFAULT '',
				channel varchar(20) NOT NULL DEFAULT 'email',
				sent_at datetime NOT NULL,
				success tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY guest_journey_step (guest_id, journey_id, step_id),
				KEY step_sent (step_id, sent_at)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_state );
			dbDelta( $sql_sends );
		}

		/**
		 * Default relative journey for new installs.
		 *
		 * @return array
		 */
		public static function default_relative_journey() {
			return array(
				'enabled'          => false,
				'name'             => 'RSVP follow-up',
				'segment'          => 'pending',
				'include_declined' => false,
				'quiet_start'      => -1,
				'quiet_end'        => -1,
				'mode'             => 'relative',
				'steps'            => array(
					array(
						'id'                   => 's1',
						'delay_days'           => 0,
						'days_before_deadline' => 0,
						'channel'              => 'email',
						'subject'              => __( 'Reminder: please RSVP', 'wedding-party-rsvp' ),
						'body'                 => __( "Hello {name},\n\nPlease submit or update your RSVP.\n\n{rsvp_url}\n\nThank you!", 'wedding-party-rsvp' ),
					),
					array(
						'id'                   => 's2',
						'delay_days'           => 3,
						'days_before_deadline' => 0,
						'channel'              => 'email',
						'subject'              => __( 'Friendly RSVP reminder', 'wedding-party-rsvp' ),
						'body'                 => __( "Hello {name},\n\nJust a friendly reminder to RSVP when you can.\n\n{rsvp_url}\n\nThank you!", 'wedding-party-rsvp' ),
					),
					array(
						'id'                   => 's3',
						'delay_days'           => 2,
						'days_before_deadline' => 0,
						'channel'              => 'email',
						'subject'              => __( 'Last reminder: RSVP', 'wedding-party-rsvp' ),
						'body'                 => __( "Hello {name},\n\nWe would love to hear from you. Please RSVP here:\n\n{rsvp_url}\n\nThank you!", 'wedding-party-rsvp' ),
					),
				),
			);
		}

		/**
		 * Load sanitized journey option (defaults when empty).
		 *
		 * @return array
		 */
		public static function get_journey() {
			$raw = get_option( self::OPT_JOURNEY, null );
			if ( ! is_array( $raw ) || empty( $raw ) ) {
				return self::default_relative_journey();
			}
			return self::sanitize_journey( $raw );
		}

		/**
		 * Persist a sanitized journey (autoload no).
		 *
		 * @param array $raw Raw journey data (POST or programmatic).
		 * @return array Sanitized journey that was saved.
		 */
		public static function save_journey( $raw ) {
			$journey = self::sanitize_journey( is_array( $raw ) ? $raw : array() );
			update_option( self::OPT_JOURNEY, $journey, false );
			return $journey;
		}

		/**
		 * Sanitize journey shape.
		 *
		 * @param array $raw Raw data.
		 * @return array
		 */
		public static function sanitize_journey( $raw ) {
			$defaults = self::default_relative_journey();
			if ( ! is_array( $raw ) ) {
				$raw = array();
			}

			$mode = isset( $raw['mode'] ) ? sanitize_key( (string) $raw['mode'] ) : 'relative';
			if ( ! in_array( $mode, array( 'relative', 'deadline_offsets' ), true ) ) {
				$mode = 'relative';
			}

			$segment = isset( $raw['segment'] ) ? sanitize_key( (string) $raw['segment'] ) : 'pending';
			$allowed = array( 'pending', 'missing_meal', 'missing_address', 'missing_phone', 'accepted_missing_meal', 'accepted_sub_event_pending' );
			if ( ! in_array( $segment, $allowed, true ) ) {
				$segment = 'pending';
			}

			$quiet_start = isset( $raw['quiet_start'] ) ? (int) $raw['quiet_start'] : -1;
			$quiet_end   = isset( $raw['quiet_end'] ) ? (int) $raw['quiet_end'] : -1;
			if ( $quiet_start < -1 || $quiet_start > 23 ) {
				$quiet_start = -1;
			}
			if ( $quiet_end < -1 || $quiet_end > 23 ) {
				$quiet_end = -1;
			}

			$steps_in = isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array();
			$steps    = array();
			$n        = 0;
			foreach ( $steps_in as $step ) {
				if ( $n >= self::MAX_STEPS ) {
					break;
				}
				if ( ! is_array( $step ) ) {
					continue;
				}
				$channel = isset( $step['channel'] ) ? sanitize_key( (string) $step['channel'] ) : 'email';
				if ( ! in_array( $channel, array( 'email', 'sms' ), true ) ) {
					$channel = 'email';
				}
				++$n;
				$sid = isset( $step['id'] ) ? sanitize_key( (string) $step['id'] ) : '';
				if ( '' === $sid ) {
					$sid = 's' . (string) $n;
				}
				$steps[] = array(
					'id'                   => $sid,
					'delay_days'           => max( 0, min( 365, absint( isset( $step['delay_days'] ) ? $step['delay_days'] : 0 ) ) ),
					'days_before_deadline' => max( 0, min( 365, absint( isset( $step['days_before_deadline'] ) ? $step['days_before_deadline'] : 0 ) ) ),
					'channel'              => $channel,
					'subject'              => isset( $step['subject'] ) ? sanitize_text_field( (string) $step['subject'] ) : '',
					'body'                 => isset( $step['body'] ) ? sanitize_textarea_field( (string) $step['body'] ) : '',
				);
			}

			if ( empty( $steps ) ) {
				$steps = $defaults['steps'];
			}

			return array(
				'enabled'          => ! empty( $raw['enabled'] ),
				'name'             => isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : $defaults['name'],
				'segment'          => $segment,
				'include_declined' => ! empty( $raw['include_declined'] ),
				'quiet_start'      => $quiet_start,
				'quiet_end'        => $quiet_end,
				'mode'             => $mode,
				'steps'            => $steps,
			);
		}

		/**
		 * Whether an enabled drip journey should suppress the legacy deadline cron.
		 *
		 * @return bool
		 */
		public static function journey_supersedes_deadline_cron() {
			$journey = self::get_journey();
			if ( empty( $journey['enabled'] ) ) {
				return false;
			}
			return ! empty( $journey['steps'] ) && is_array( $journey['steps'] );
		}

		/**
		 * Build a deadline_offsets journey from legacy deadline_nudge_* settings.
		 * Enables the journey; does not disable the legacy checkbox.
		 *
		 * @return array Saved journey.
		 */
		public static function migrate_from_deadline_nudge() {
			$settings = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			$offsets_raw = isset( $settings['deadline_nudge_days'] ) ? (string) $settings['deadline_nudge_days'] : '7,3,1';
			$offsets     = array();
			foreach ( explode( ',', $offsets_raw ) as $part ) {
				$n = absint( trim( $part ) );
				if ( $n > 0 && $n <= 365 ) {
					$offsets[] = $n;
				}
			}
			$offsets = array_values( array_unique( $offsets ) );
			if ( empty( $offsets ) ) {
				$offsets = array( 7, 3, 1 );
			}
			rsort( $offsets, SORT_NUMERIC );

			$subject = isset( $settings['deadline_nudge_subject'] ) && '' !== trim( (string) $settings['deadline_nudge_subject'] )
				? (string) $settings['deadline_nudge_subject']
				: __( 'Reminder: RSVP by {deadline}', 'wedding-party-rsvp' );
			$body    = isset( $settings['deadline_nudge_body'] ) && '' !== trim( (string) $settings['deadline_nudge_body'] )
				? (string) $settings['deadline_nudge_body']
				: __( "Hello {guest_name},\n\nPlease submit or update your RSVP by {deadline}.\n\n{rsvp_url}\n\nThank you!", 'wedding-party-rsvp' );

			$segment = isset( $settings['deadline_nudge_segment'] )
				? sanitize_key( (string) $settings['deadline_nudge_segment'] )
				: 'pending';

			$quiet_start = isset( $settings['deadline_nudge_quiet_start'] ) ? (int) $settings['deadline_nudge_quiet_start'] : -1;
			$quiet_end   = isset( $settings['deadline_nudge_quiet_end'] ) ? (int) $settings['deadline_nudge_quiet_end'] : -1;

			$steps = array();
			$i     = 0;
			foreach ( $offsets as $days_before ) {
				++$i;
				if ( $i > self::MAX_STEPS ) {
					break;
				}
				$steps[] = array(
					'id'                   => 's' . (string) $i,
					'delay_days'           => 0,
					'days_before_deadline' => (int) $days_before,
					'channel'              => 'email',
					'subject'              => $subject,
					'body'                 => $body,
				);
			}

			$journey = array(
				'enabled'          => true,
				'name'             => __( 'RSVP deadline follow-up', 'wedding-party-rsvp' ),
				'segment'          => $segment,
				'include_declined' => ! empty( $settings['deadline_nudge_include_declined'] ),
				'quiet_start'      => $quiet_start,
				'quiet_end'        => $quiet_end,
				'mode'             => 'deadline_offsets',
				'steps'            => $steps,
			);

			return self::save_journey( $journey );
		}

		/**
		 * Cron / forced tick: enroll, exit, send due steps (cap 50).
		 *
		 * @param bool $force Bypass quiet hours when true.
		 * @return array{sent:int,skipped:int,error:string}
		 */
		public static function tick( $force = false ) {
			$result = array(
				'sent'    => 0,
				'skipped' => 0,
				'error'   => '',
			);

			self::maybe_migrate_schema();

			$journey = self::get_journey();
			if ( empty( $journey['enabled'] ) || empty( $journey['steps'] ) ) {
				$result['error'] = __( 'Drip journey is disabled or has no steps.', 'wedding-party-rsvp' );
				return $result;
			}

			if ( ! $force && ! self::quiet_hours_allow_send( $journey ) ) {
				$result['error'] = __( 'Quiet hours are active; sends were skipped.', 'wedding-party-rsvp' );
				return $result;
			}

			if ( ! class_exists( 'WGRSVP_Deadline_Nudges', false ) ) {
				$result['error'] = __( 'Deadline helpers are unavailable.', 'wedding-party-rsvp' );
				return $result;
			}

			$segment          = isset( $journey['segment'] ) ? (string) $journey['segment'] : 'pending';
			$include_declined = ! empty( $journey['include_declined'] );
			$candidates       = WGRSVP_Deadline_Nudges::get_segment_guest_rows( $segment, $include_declined );
			$candidate_ids    = array();
			$guests_by_id     = array();
			foreach ( $candidates as $guest ) {
				if ( ! is_object( $guest ) || empty( $guest->id ) ) {
					continue;
				}
				$gid                  = absint( $guest->id );
				$candidate_ids[]      = $gid;
				$guests_by_id[ $gid ] = $guest;
			}

			self::enroll_guests( $candidate_ids );
			self::exit_guests_not_in_segment( $candidate_ids );

			$cap = (int) apply_filters( 'wgrsvp_drip_max_sends_per_tick', self::MAX_SENDS_PER_TICK, $journey );
			if ( $cap < 1 ) {
				$cap = self::MAX_SENDS_PER_TICK;
			}

			$mode = isset( $journey['mode'] ) ? (string) $journey['mode'] : 'relative';
			if ( 'deadline_offsets' === $mode ) {
				$tick_result = self::process_deadline_offsets( $journey, $guests_by_id, $cap );
			} else {
				$tick_result = self::process_relative( $journey, $guests_by_id, $cap );
			}

			$result['sent']    = (int) $tick_result['sent'];
			$result['skipped'] = (int) $tick_result['skipped'];
			if ( '' !== $tick_result['error'] ) {
				$result['error'] = $tick_result['error'];
			}

			/**
			 * After a drip tick finishes enrollment/exit/sends.
			 *
			 * @param array $result  Tick result.
			 * @param array $journey Journey config.
			 * @param bool  $force   Whether quiet hours were bypassed.
			 */
			do_action( 'wgrsvp_drip_tick_complete', $result, $journey, (bool) $force );

			return $result;
		}

		/**
		 * Run due steps now (companion / admin). Respects quiet hours unless force.
		 *
		 * @param bool $force Bypass quiet hours.
		 * @return array{sent:int,error:string}
		 */
		public static function run_due_now( $force = true ) {
			$tick = self::tick( (bool) $force );
			return array(
				'sent'  => (int) $tick['sent'],
				'error' => (string) $tick['error'],
			);
		}

		/**
		 * Status summary for admin / companion.
		 *
		 * @return array
		 */
		public static function get_status_summary() {
			global $wpdb;

			self::maybe_migrate_schema();

			$journey = self::get_journey();
			$state   = self::state_table();
			$jid     = self::JOURNEY_ID;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- %i table.
			$active = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE journey_id = %s AND status = %s',
					$state,
					$jid,
					'active'
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$completed = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE journey_id = %s AND status = %s',
					$state,
					$jid,
					'completed'
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$exited = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE journey_id = %s AND status = %s',
					$state,
					$jid,
					'exited'
				)
			);

			$steps_n = isset( $journey['steps'] ) && is_array( $journey['steps'] ) ? count( $journey['steps'] ) : 0;
			$due_n   = self::count_due_now( $journey );

			return array(
				'enabled'       => ! empty( $journey['enabled'] ),
				'name'          => isset( $journey['name'] ) ? (string) $journey['name'] : '',
				'mode'          => isset( $journey['mode'] ) ? (string) $journey['mode'] : 'relative',
				'segment'       => isset( $journey['segment'] ) ? (string) $journey['segment'] : 'pending',
				'steps'         => $steps_n,
				'active'        => $active,
				'completed'     => $completed,
				'exited'        => $exited,
				'due'           => $due_n,
				'supersedes'    => self::journey_supersedes_deadline_cron(),
				'next_cron_gmt' => (int) wp_next_scheduled( self::CRON_HOOK ),
			);
		}

		/**
		 * Admin settings UI: journey fields, migrate + run-now buttons.
		 *
		 * @return void
		 */
		public static function render_settings_section() {
			$journey = self::get_journey();
			$steps   = isset( $journey['steps'] ) && is_array( $journey['steps'] ) ? $journey['steps'] : array();
			while ( count( $steps ) < self::MAX_STEPS ) {
				$n       = count( $steps ) + 1;
				$steps[] = array(
					'id'                   => 's' . (string) $n,
					'delay_days'           => 0,
					'days_before_deadline' => 0,
					'channel'              => 'email',
					'subject'              => '',
					'body'                 => '',
				);
			}
			$steps   = array_slice( $steps, 0, self::MAX_STEPS );
			$summary = self::get_status_summary();
			$seg     = isset( $journey['segment'] ) ? (string) $journey['segment'] : 'pending';
			$mode    = isset( $journey['mode'] ) ? (string) $journey['mode'] : 'relative';

			$segments = array(
				'pending'                   => __( 'Pending RSVP', 'wedding-party-rsvp' ),
				'missing_meal'              => __( 'Missing meal choice', 'wedding-party-rsvp' ),
				'missing_address'           => __( 'Missing address', 'wedding-party-rsvp' ),
				'missing_phone'             => __( 'Missing phone', 'wedding-party-rsvp' ),
				'accepted_missing_meal'     => __( 'Accepted, missing meal', 'wedding-party-rsvp' ),
				'accepted_sub_event_pending'=> __( 'Accepted, sub-event pending', 'wedding-party-rsvp' ),
			);
			?>
			<div class="wgrsvp-drip-settings" id="wgrsvp-drip-settings">
				<h2><?php esc_html_e( 'Multi-step drip journey', 'wedding-party-rsvp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'One active journey per site. Free sends email via your site mailer; SMS steps require Pro + Twilio.', 'wedding-party-rsvp' ); ?>
				</p>
				<p>
					<?php
					printf(
						/* translators: 1: active count, 2: due count, 3: completed count */
						esc_html__( 'Active: %1$d · Due now: %2$d · Completed: %3$d', 'wedding-party-rsvp' ),
						(int) $summary['active'],
						(int) $summary['due'],
						(int) $summary['completed']
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wgrsvp_drip_save">
					<?php wp_nonce_field( 'wgrsvp_drip_save', 'wgrsvp_drip_save_nonce' ); ?>

					<p>
						<label>
							<input type="checkbox" name="wgrsvp_drip_enabled" value="1" <?php checked( ! empty( $journey['enabled'] ) ); ?>>
							<?php esc_html_e( 'Enable drip journey', 'wedding-party-rsvp' ); ?>
						</label>
					</p>

					<p>
						<label for="wgrsvp_drip_name"><strong><?php esc_html_e( 'Journey name', 'wedding-party-rsvp' ); ?></strong></label><br>
						<input type="text" class="regular-text" id="wgrsvp_drip_name" name="wgrsvp_drip_name" value="<?php echo esc_attr( (string) $journey['name'] ); ?>">
					</p>

					<p>
						<label for="wgrsvp_drip_mode"><strong><?php esc_html_e( 'Anchor mode', 'wedding-party-rsvp' ); ?></strong></label><br>
						<select name="wgrsvp_drip_mode" id="wgrsvp_drip_mode">
							<option value="relative" <?php selected( $mode, 'relative' ); ?>><?php esc_html_e( 'Relative (days after previous step)', 'wedding-party-rsvp' ); ?></option>
							<option value="deadline_offsets" <?php selected( $mode, 'deadline_offsets' ); ?>><?php esc_html_e( 'Deadline offsets (days before RSVP deadline)', 'wedding-party-rsvp' ); ?></option>
						</select>
					</p>

					<p>
						<label for="wgrsvp_drip_segment"><strong><?php esc_html_e( 'Audience segment', 'wedding-party-rsvp' ); ?></strong></label><br>
						<select name="wgrsvp_drip_segment" id="wgrsvp_drip_segment">
							<?php foreach ( $segments as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $seg, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>

					<p>
						<label>
							<input type="checkbox" name="wgrsvp_drip_include_declined" value="1" <?php checked( ! empty( $journey['include_declined'] ) ); ?>>
							<?php esc_html_e( 'Include guests who previously declined (pending-family segments)', 'wedding-party-rsvp' ); ?>
						</label>
					</p>

					<p>
						<label for="wgrsvp_drip_quiet_start"><strong><?php esc_html_e( 'Quiet hours (site timezone)', 'wedding-party-rsvp' ); ?></strong></label><br>
						<?php esc_html_e( 'From', 'wedding-party-rsvp' ); ?>
						<input type="number" name="wgrsvp_drip_quiet_start" id="wgrsvp_drip_quiet_start" min="-1" max="23" step="1" value="<?php echo esc_attr( (string) (int) $journey['quiet_start'] ); ?>" style="width:4.5em;">
						<?php esc_html_e( 'to', 'wedding-party-rsvp' ); ?>
						<input type="number" name="wgrsvp_drip_quiet_end" id="wgrsvp_drip_quiet_end" min="-1" max="23" step="1" value="<?php echo esc_attr( (string) (int) $journey['quiet_end'] ); ?>" style="width:4.5em;">
						<span class="description"><?php esc_html_e( 'Use -1 for both to disable. Example: 21 to 8 skips overnight sends.', 'wedding-party-rsvp' ); ?></span>
					</p>

					<h3><?php esc_html_e( 'Steps (up to 5)', 'wedding-party-rsvp' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Placeholders: {name}, {guest_name}, {party_id}, {rsvp_url}, {deadline}. Leave subject and body empty to omit a step slot.', 'wedding-party-rsvp' ); ?>
					</p>

					<?php
					for ( $i = 0; $i < self::MAX_STEPS; $i++ ) {
						$step     = $steps[ $i ];
						$num      = $i + 1;
						$field    = 'wgrsvp_drip_steps[' . $i . ']';
						$delay    = isset( $step['delay_days'] ) ? (int) $step['delay_days'] : 0;
						$before   = isset( $step['days_before_deadline'] ) ? (int) $step['days_before_deadline'] : 0;
						$channel  = isset( $step['channel'] ) ? (string) $step['channel'] : 'email';
						$subject  = isset( $step['subject'] ) ? (string) $step['subject'] : '';
						$body     = isset( $step['body'] ) ? (string) $step['body'] : '';
						$step_id  = isset( $step['id'] ) ? (string) $step['id'] : ( 's' . (string) $num );
						?>
						<fieldset style="border:1px solid #ccd0d4; padding:12px 14px; margin:0 0 14px; max-width:720px;">
							<legend><strong><?php echo esc_html( sprintf( /* translators: %d: step number */ __( 'Step %d', 'wedding-party-rsvp' ), $num ) ); ?></strong></legend>
							<input type="hidden" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( $step_id ); ?>">
							<p>
								<label><?php esc_html_e( 'Delay days (relative mode)', 'wedding-party-rsvp' ); ?>
									<input type="number" name="<?php echo esc_attr( $field ); ?>[delay_days]" min="0" max="365" value="<?php echo esc_attr( (string) $delay ); ?>" style="width:5em;">
								</label>
								&nbsp;
								<label><?php esc_html_e( 'Days before deadline (deadline mode)', 'wedding-party-rsvp' ); ?>
									<input type="number" name="<?php echo esc_attr( $field ); ?>[days_before_deadline]" min="0" max="365" value="<?php echo esc_attr( (string) $before ); ?>" style="width:5em;">
								</label>
							</p>
							<p>
								<label><?php esc_html_e( 'Channel', 'wedding-party-rsvp' ); ?>
									<select name="<?php echo esc_attr( $field ); ?>[channel]">
										<option value="email" <?php selected( $channel, 'email' ); ?>><?php esc_html_e( 'Email', 'wedding-party-rsvp' ); ?></option>
										<option value="sms" <?php selected( $channel, 'sms' ); ?>><?php esc_html_e( 'SMS (Pro)', 'wedding-party-rsvp' ); ?></option>
									</select>
								</label>
							</p>
							<p>
								<label for="wgrsvp_drip_step_<?php echo esc_attr( (string) $i ); ?>_subject"><strong><?php esc_html_e( 'Subject', 'wedding-party-rsvp' ); ?></strong></label><br>
								<input type="text" class="large-text" id="wgrsvp_drip_step_<?php echo esc_attr( (string) $i ); ?>_subject" name="<?php echo esc_attr( $field ); ?>[subject]" value="<?php echo esc_attr( $subject ); ?>">
							</p>
							<p>
								<label for="wgrsvp_drip_step_<?php echo esc_attr( (string) $i ); ?>_body"><strong><?php esc_html_e( 'Body', 'wedding-party-rsvp' ); ?></strong></label><br>
								<textarea class="large-text" rows="4" id="wgrsvp_drip_step_<?php echo esc_attr( (string) $i ); ?>_body" name="<?php echo esc_attr( $field ); ?>[body]"><?php echo esc_textarea( $body ); ?></textarea>
							</p>
						</fieldset>
						<?php
					}
					?>

					<?php submit_button( __( 'Save drip journey', 'wedding-party-rsvp' ), 'primary', 'submit', false ); ?>
				</form>

				<hr>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right:12px;">
					<input type="hidden" name="action" value="wgrsvp_drip_migrate">
					<?php wp_nonce_field( 'wgrsvp_drip_migrate', 'wgrsvp_drip_migrate_nonce' ); ?>
					<?php submit_button( __( 'Migrate deadline nudge → journey', 'wedding-party-rsvp' ), 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="wgrsvp_drip_run_now">
					<?php wp_nonce_field( 'wgrsvp_drip_run_now', 'wgrsvp_drip_run_now_nonce' ); ?>
					<?php submit_button( __( 'Run due steps now', 'wedding-party-rsvp' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Admin-post: save journey from settings form.
		 *
		 * @return void
		 */
		public static function handle_save_journey() {
			if ( ! isset( $_POST['wgrsvp_drip_save_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_drip_save_nonce'] ) ), 'wgrsvp_drip_save' ) ) {
				wp_die( esc_html__( 'Invalid security token.', 'wedding-party-rsvp' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wedding-party-rsvp' ) );
			}

			$steps_raw = array();
			if ( isset( $_POST['wgrsvp_drip_steps'] ) && is_array( $_POST['wgrsvp_drip_steps'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save_journey / sanitize_journey.
				$posted_steps = wp_unslash( $_POST['wgrsvp_drip_steps'] );
				foreach ( $posted_steps as $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}
					$subject = isset( $step['subject'] ) ? sanitize_text_field( (string) $step['subject'] ) : '';
					$body    = isset( $step['body'] ) ? sanitize_textarea_field( (string) $step['body'] ) : '';
					$before  = isset( $step['days_before_deadline'] ) ? absint( $step['days_before_deadline'] ) : 0;
					$delay   = isset( $step['delay_days'] ) ? absint( $step['delay_days'] ) : 0;
					// Omit empty slots (no copy and no deadline offset).
					if ( '' === $subject && '' === $body && 0 === $before && 0 === $delay ) {
						continue;
					}
					$steps_raw[] = array(
						'id'                   => isset( $step['id'] ) ? sanitize_key( (string) $step['id'] ) : '',
						'delay_days'           => $delay,
						'days_before_deadline' => $before,
						'channel'              => isset( $step['channel'] ) ? sanitize_key( (string) $step['channel'] ) : 'email',
						'subject'              => $subject,
						'body'                 => $body,
					);
				}
			}

			$raw = array(
				'enabled'          => isset( $_POST['wgrsvp_drip_enabled'] ) ? 1 : 0,
				'name'             => isset( $_POST['wgrsvp_drip_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wgrsvp_drip_name'] ) ) : '',
				'segment'          => isset( $_POST['wgrsvp_drip_segment'] ) ? sanitize_key( wp_unslash( (string) $_POST['wgrsvp_drip_segment'] ) ) : 'pending',
				'include_declined' => isset( $_POST['wgrsvp_drip_include_declined'] ) ? 1 : 0,
				'quiet_start'      => isset( $_POST['wgrsvp_drip_quiet_start'] ) && '' !== (string) $_POST['wgrsvp_drip_quiet_start'] ? (int) wp_unslash( $_POST['wgrsvp_drip_quiet_start'] ) : -1,
				'quiet_end'        => isset( $_POST['wgrsvp_drip_quiet_end'] ) && '' !== (string) $_POST['wgrsvp_drip_quiet_end'] ? (int) wp_unslash( $_POST['wgrsvp_drip_quiet_end'] ) : -1,
				'mode'             => isset( $_POST['wgrsvp_drip_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['wgrsvp_drip_mode'] ) ) : 'relative',
				'steps'            => $steps_raw,
			);

			self::save_journey( $raw );
			self::maybe_schedule();

			self::redirect_admin( array( 'wgrsvp_drip_saved' => '1' ) );
		}

		/**
		 * Admin-post: migrate legacy deadline nudge into a drip journey.
		 *
		 * @return void
		 */
		public static function handle_migrate_from_deadline() {
			if ( ! isset( $_POST['wgrsvp_drip_migrate_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_drip_migrate_nonce'] ) ), 'wgrsvp_drip_migrate' ) ) {
				wp_die( esc_html__( 'Invalid security token.', 'wedding-party-rsvp' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wedding-party-rsvp' ) );
			}

			self::migrate_from_deadline_nudge();
			self::maybe_schedule();

			self::redirect_admin( array( 'wgrsvp_drip_migrated' => '1' ) );
		}

		/**
		 * Admin-post: force-run due steps.
		 *
		 * @return void
		 */
		public static function handle_run_due_now() {
			if ( ! isset( $_POST['wgrsvp_drip_run_now_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_drip_run_now_nonce'] ) ), 'wgrsvp_drip_run_now' ) ) {
				wp_die( esc_html__( 'Invalid security token.', 'wedding-party-rsvp' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wedding-party-rsvp' ) );
			}

			$result = self::run_due_now( true );

			self::redirect_admin(
				array(
					'wgrsvp_drip_sent' => (int) $result['sent'],
					'wgrsvp_drip_err'  => '' !== $result['error'] ? rawurlencode( $result['error'] ) : false,
				)
			);
		}

		/**
		 * Redirect back to settings/ops after admin-post.
		 *
		 * @param array $args Query args.
		 * @return void
		 */
		private static function redirect_admin( $args ) {
			$referer = wp_get_referer();
			$base    = $referer ? $referer : admin_url( 'admin.php?page=wedding-rsvp-settings' );
			$url     = add_query_arg( $args, $base );
			wp_safe_redirect( esc_url_raw( $url ) );
			exit;
		}

		/**
		 * Quiet-hours gate using journey quiet_* mapped into deadline helper settings.
		 *
		 * @param array $journey Journey.
		 * @return bool True when sending is allowed.
		 */
		private static function quiet_hours_allow_send( $journey ) {
			$start = isset( $journey['quiet_start'] ) ? (int) $journey['quiet_start'] : -1;
			$end   = isset( $journey['quiet_end'] ) ? (int) $journey['quiet_end'] : -1;
			if ( $start < 0 || $end < 0 ) {
				return true;
			}
			if ( ! class_exists( 'WGRSVP_Deadline_Nudges', false ) ) {
				return true;
			}
			$fake = array(
				'deadline_nudge_quiet_start' => $start,
				'deadline_nudge_quiet_end'   => $end,
			);
			return (bool) WGRSVP_Deadline_Nudges::is_within_send_window( $fake );
		}

		/**
		 * Insert active state rows for new segment members.
		 *
		 * @param int[] $guest_ids Guest IDs in segment.
		 * @return void
		 */
		private static function enroll_guests( $guest_ids ) {
			global $wpdb;

			if ( empty( $guest_ids ) ) {
				return;
			}

			$table = self::state_table();
			$jid   = self::JOURNEY_ID;
			$now   = gmdate( 'Y-m-d H:i:s' );

			foreach ( $guest_ids as $gid ) {
				$gid = absint( $gid );
				if ( $gid < 1 ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT id, status FROM %i WHERE guest_id = %d AND journey_id = %s LIMIT 1',
						$table,
						$gid,
						$jid
					)
				);
				if ( $existing ) {
					if ( 'exited' === (string) $existing->status || 'paused' === (string) $existing->status ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
						$wpdb->query(
							$wpdb->prepare(
								'UPDATE %i SET status = %s, exit_reason = %s, enrolled_at = %s, step_id = %s, last_sent_at = NULL WHERE id = %d',
								$table,
								'active',
								'',
								$now,
								'',
								absint( $existing->id )
							)
						);
					}
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$table,
					array(
						'guest_id'    => $gid,
						'journey_id'  => $jid,
						'step_id'     => '',
						'enrolled_at' => $now,
						'status'      => 'active',
						'exit_reason' => '',
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}

		/**
		 * Mark active guests no longer in the segment as exited.
		 *
		 * @param int[] $candidate_ids Current segment guest IDs.
		 * @return void
		 */
		private static function exit_guests_not_in_segment( $candidate_ids ) {
			global $wpdb;

			$table = self::state_table();
			$jid   = self::JOURNEY_ID;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$active_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT guest_id FROM %i WHERE journey_id = %s AND status = %s',
					$table,
					$jid,
					'active'
				)
			);
			if ( ! is_array( $active_ids ) || empty( $active_ids ) ) {
				return;
			}

			$keep = array_map( 'absint', $candidate_ids );
			foreach ( $active_ids as $gid ) {
				$gid = absint( $gid );
				if ( $gid < 1 || in_array( $gid, $keep, true ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'status'      => 'exited',
						'exit_reason' => 'segment_cleared',
					),
					array(
						'guest_id'   => $gid,
						'journey_id' => $jid,
						'status'     => 'active',
					),
					array( '%s', '%s' ),
					array( '%d', '%s', '%s' )
				);
			}
		}

		/**
		 * Relative-mode send loop.
		 *
		 * @param array             $journey      Journey.
		 * @param array<int,object> $guests_by_id Guests keyed by id.
		 * @param int               $cap          Max sends.
		 * @return array{sent:int,skipped:int,error:string}
		 */
		private static function process_relative( $journey, $guests_by_id, $cap ) {
			global $wpdb;

			$out = array(
				'sent'    => 0,
				'skipped' => 0,
				'error'   => '',
			);

			$steps = isset( $journey['steps'] ) && is_array( $journey['steps'] ) ? $journey['steps'] : array();
			if ( empty( $steps ) ) {
				$out['error'] = __( 'No steps configured.', 'wedding-party-rsvp' );
				return $out;
			}

			$table = self::state_table();
			$jid   = self::JOURNEY_ID;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE journey_id = %s AND status = %s ORDER BY enrolled_at ASC LIMIT 500',
					$table,
					$jid,
					'active'
				)
			);
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				return $out;
			}

			foreach ( $rows as $state ) {
				if ( $out['sent'] >= $cap ) {
					break;
				}
				$gid = isset( $state->guest_id ) ? absint( $state->guest_id ) : 0;
				if ( $gid < 1 || ! isset( $guests_by_id[ $gid ] ) ) {
					continue;
				}

				$next = self::next_relative_step( $state, $steps );
				if ( null === $next ) {
					if ( self::has_completed_all_steps( $state, $steps ) ) {
						self::mark_completed( $gid );
					}
					continue;
				}

				if ( ! self::relative_step_is_due( $state, $next, $steps ) ) {
					continue;
				}

				$send = self::send_step( $guests_by_id[ $gid ], $next, $journey );
				if ( 'sent' === $send || 'skipped' === $send ) {
					if ( 'sent' === $send ) {
						++$out['sent'];
					} else {
						++$out['skipped'];
					}
					self::record_step_progress( $gid, $next['id'], 'sent' === $send );
					if ( self::is_last_step( $next['id'], $steps ) ) {
						self::mark_completed( $gid );
					}
				}
			}

			return $out;
		}

		/**
		 * Deadline-offsets send loop.
		 *
		 * @param array             $journey      Journey.
		 * @param array<int,object> $guests_by_id Guests keyed by id.
		 * @param int               $cap          Max sends.
		 * @return array{sent:int,skipped:int,error:string}
		 */
		private static function process_deadline_offsets( $journey, $guests_by_id, $cap ) {
			$out = array(
				'sent'    => 0,
				'skipped' => 0,
				'error'   => '',
			);

			$ctx = self::get_deadline_context();
			if ( null === $ctx ) {
				$out['error'] = __( 'Set an RSVP deadline date under Settings before using deadline-offset drips.', 'wedding-party-rsvp' );
				return $out;
			}

			$today_ymd = $ctx['today']->format( 'Y-m-d' );
			if ( $today_ymd > $ctx['deadline'] ) {
				$out['error'] = __( 'The RSVP deadline has already passed.', 'wedding-party-rsvp' );
				return $out;
			}

			$steps = isset( $journey['steps'] ) && is_array( $journey['steps'] ) ? $journey['steps'] : array();
			$due   = array();
			foreach ( $steps as $step ) {
				$n = isset( $step['days_before_deadline'] ) ? absint( $step['days_before_deadline'] ) : 0;
				if ( $n < 1 ) {
					continue;
				}
				try {
					$send_on = $ctx['d_deadline']->modify( '-' . $n . ' days' )->format( 'Y-m-d' );
				} catch ( Exception $e ) {
					continue;
				}
				if ( $send_on === $today_ymd ) {
					$due[] = $step;
				}
			}

			if ( empty( $due ) ) {
				return $out;
			}

			foreach ( $guests_by_id as $gid => $guest ) {
				if ( $out['sent'] >= $cap ) {
					break;
				}
				$gid = absint( $gid );
				foreach ( $due as $step ) {
					if ( $out['sent'] >= $cap ) {
						break;
					}
					if ( self::has_send_record( $gid, $step['id'] ) ) {
						continue;
					}
					$send = self::send_step( $guest, $step, $journey );
					if ( 'sent' === $send || 'skipped' === $send ) {
						if ( 'sent' === $send ) {
							++$out['sent'];
						} else {
							++$out['skipped'];
						}
						self::record_step_progress( $gid, $step['id'], 'sent' === $send );
						if ( self::is_last_step( $step['id'], $steps ) ) {
							self::mark_completed( $gid );
						}
					}
				}
			}

			return $out;
		}

		/**
		 * Next relative step for a state row, or null if done / unknown.
		 *
		 * @param object $state State row.
		 * @param array  $steps Steps.
		 * @return array|null
		 */
		private static function next_relative_step( $state, $steps ) {
			$current = isset( $state->step_id ) ? sanitize_key( (string) $state->step_id ) : '';
			if ( '' === $current ) {
				return isset( $steps[0] ) && is_array( $steps[0] ) ? $steps[0] : null;
			}
			$count = count( $steps );
			for ( $i = 0; $i < $count; $i++ ) {
				$sid = isset( $steps[ $i ]['id'] ) ? sanitize_key( (string) $steps[ $i ]['id'] ) : '';
				if ( $sid === $current ) {
					$next_i = $i + 1;
					return ( $next_i < $count && is_array( $steps[ $next_i ] ) ) ? $steps[ $next_i ] : null;
				}
			}
			return null;
		}

		/**
		 * Whether relative step is due for this state.
		 *
		 * Step 1: due when enrolled and step_id empty.
		 * Later: days since last_sent_at >= step delay_days.
		 *
		 * @param object $state State row.
		 * @param array  $step  Upcoming step.
		 * @param array  $steps All steps.
		 * @return bool
		 */
		private static function relative_step_is_due( $state, $step, $steps ) {
			$current = isset( $state->step_id ) ? sanitize_key( (string) $state->step_id ) : '';
			$first_id = isset( $steps[0]['id'] ) ? sanitize_key( (string) $steps[0]['id'] ) : '';
			$step_id  = isset( $step['id'] ) ? sanitize_key( (string) $step['id'] ) : '';

			if ( '' === $current && $step_id === $first_id ) {
				return true;
			}

			$delay = isset( $step['delay_days'] ) ? absint( $step['delay_days'] ) : 0;
			$last  = isset( $state->last_sent_at ) ? (string) $state->last_sent_at : '';
			if ( '' === $last || '0000-00-00 00:00:00' === $last ) {
				return ( 0 === $delay );
			}

			try {
				$last_dt = new DateTimeImmutable( $last, new DateTimeZone( 'UTC' ) );
				$now_dt  = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			} catch ( Exception $e ) {
				return false;
			}

			$days = (int) floor( ( $now_dt->getTimestamp() - $last_dt->getTimestamp() ) / DAY_IN_SECONDS );
			return $days >= $delay;
		}

		/**
		 * Whether state has finished every step.
		 *
		 * @param object $state State.
		 * @param array  $steps Steps.
		 * @return bool
		 */
		private static function has_completed_all_steps( $state, $steps ) {
			if ( empty( $steps ) ) {
				return false;
			}
			$last = $steps[ count( $steps ) - 1 ];
			$sid  = isset( $last['id'] ) ? sanitize_key( (string) $last['id'] ) : '';
			$cur  = isset( $state->step_id ) ? sanitize_key( (string) $state->step_id ) : '';
			return ( '' !== $sid && $sid === $cur );
		}

		/**
		 * Whether step id is the last configured step.
		 *
		 * @param string $step_id Step id.
		 * @param array  $steps   Steps.
		 * @return bool
		 */
		private static function is_last_step( $step_id, $steps ) {
			if ( empty( $steps ) ) {
				return false;
			}
			$last = $steps[ count( $steps ) - 1 ];
			return sanitize_key( (string) $step_id ) === sanitize_key( isset( $last['id'] ) ? (string) $last['id'] : '' );
		}

		/**
		 * Send one step (email or SMS via filter).
		 *
		 * @param object $guest   Guest row.
		 * @param array  $step    Step.
		 * @param array  $journey Journey.
		 * @return string 'sent'|'skipped'|'failed'|'noop'
		 */
		private static function send_step( $guest, $step, $journey ) {
			$gid = isset( $guest->id ) ? absint( $guest->id ) : 0;
			if ( $gid < 1 ) {
				return 'noop';
			}

			$step_id = isset( $step['id'] ) ? sanitize_key( (string) $step['id'] ) : '';
			if ( '' === $step_id ) {
				return 'noop';
			}

			if ( self::has_send_record( $gid, $step_id ) ) {
				return 'noop';
			}

			$channel = isset( $step['channel'] ) ? sanitize_key( (string) $step['channel'] ) : 'email';

			if ( 'sms' === $channel ) {
				/**
				 * Let Pro (or another addon) send an SMS drip step.
				 * Return true / 'handled' when the send was handled.
				 *
				 * @param bool|string $handled Default false.
				 * @param object      $guest   Guest row.
				 * @param array       $step    Step config.
				 * @param array       $journey Journey config.
				 */
				$handled = apply_filters( 'wgrsvp_drip_send_sms', false, $guest, $step, $journey );
				if ( true === $handled || 'handled' === $handled ) {
					self::insert_send_log( $gid, $step_id, 'sms', true );
					/**
					 * After a drip step was sent (email or handled SMS).
					 *
					 * @param object $guest   Guest row.
					 * @param array  $step    Step.
					 * @param array  $journey Journey.
					 */
					do_action( 'wgrsvp_drip_step_sent', $guest, $step, $journey );
					return 'sent';
				}
				if ( is_wp_error( $handled ) ) {
					// Do not log a send row — has_send_record would block retries after transient Twilio failures.
					return 'failed';
				}
				// Free / unhandled: log skip and advance so later steps are not blocked.
				self::insert_send_log( $gid, $step_id, 'sms', false );
				return 'skipped';
			}

			$email = isset( $guest->email ) ? sanitize_email( (string) $guest->email ) : '';
			if ( '' === $email || ! is_email( $email ) ) {
				return 'failed';
			}

			$party_id = isset( $guest->party_id ) ? (string) $guest->party_id : '';
			$name     = isset( $guest->guest_name ) ? (string) $guest->guest_name : '';
			$rsvp_url = class_exists( 'WGRSVP_Deadline_Nudges', false )
				? WGRSVP_Deadline_Nudges::build_rsvp_url_for_party( $party_id )
				: home_url( '/' );
			$deadline_fmt = self::format_deadline_for_template();

			$subject_tpl = isset( $step['subject'] ) && '' !== trim( (string) $step['subject'] )
				? (string) $step['subject']
				: __( 'Reminder: please RSVP', 'wedding-party-rsvp' );
			$body_tpl    = isset( $step['body'] ) && '' !== trim( (string) $step['body'] )
				? (string) $step['body']
				: __( "Hello {name},\n\nPlease submit or update your RSVP.\n\n{rsvp_url}\n\nThank you!", 'wedding-party-rsvp' );

			$details_url = $rsvp_url;
			/**
			 * Filter drip {details_url} placeholder (defaults to RSVP URL for the party).
			 *
			 * @param string $details_url Details URL.
			 * @param object $guest       Guest row.
			 * @param array  $step        Step.
			 * @param array  $journey     Journey.
			 */
			$details_url = (string) apply_filters( 'wgrsvp_drip_details_url', $details_url, $guest, $step, $journey );

			$repl = array(
				'{name}'         => $name,
				'{guest_name}'   => $name,
				'{party_id}'     => $party_id,
				'{rsvp_url}'     => $rsvp_url,
				'{details_url}'  => $details_url,
				'{deadline}'     => $deadline_fmt,
			);

			$subject = str_replace( array_keys( $repl ), array_values( $repl ), $subject_tpl );
			$body    = str_replace( array_keys( $repl ), array_values( $repl ), $body_tpl );
			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

			$ok = wp_mail( $email, $subject, $body, $headers );
			self::insert_send_log( $gid, $step_id, 'email', (bool) $ok );

			if ( ! $ok ) {
				return 'failed';
			}

			/**
			 * After a drip email step was sent successfully (Pro may SMS).
			 *
			 * @param object $guest   Guest row.
			 * @param array  $step    Step.
			 * @param array  $journey Journey.
			 */
			do_action( 'wgrsvp_drip_step_sent', $guest, $step, $journey );

			return 'sent';
		}

		/**
		 * Whether a send log row already exists for guest/step.
		 *
		 * @param int    $guest_id Guest ID.
		 * @param string $step_id  Step ID.
		 * @return bool
		 */
		private static function has_send_record( $guest_id, $step_id ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE guest_id = %d AND journey_id = %s AND step_id = %s LIMIT 1',
					self::sends_table(),
					absint( $guest_id ),
					self::JOURNEY_ID,
					sanitize_key( (string) $step_id )
				)
			);
			return ! empty( $id );
		}

		/**
		 * Insert send audit row.
		 *
		 * @param int    $guest_id Guest ID.
		 * @param string $step_id  Step ID.
		 * @param string $channel  Channel.
		 * @param bool   $success  Success flag.
		 * @return void
		 */
		private static function insert_send_log( $guest_id, $step_id, $channel, $success ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->replace(
				self::sends_table(),
				array(
					'guest_id'   => absint( $guest_id ),
					'journey_id' => self::JOURNEY_ID,
					'step_id'    => sanitize_key( (string) $step_id ),
					'channel'    => sanitize_key( (string) $channel ),
					'sent_at'    => gmdate( 'Y-m-d H:i:s' ),
					'success'    => $success ? 1 : 0,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		/**
		 * Update state after a step attempt.
		 *
		 * @param int    $guest_id Guest ID.
		 * @param string $step_id  Completed/skipped step ID.
		 * @param bool   $success  Whether channel send succeeded.
		 * @return void
		 */
		private static function record_step_progress( $guest_id, $step_id, $success ) {
			global $wpdb;

			// $success reserved: always advance last_sent_at so relative delays continue after SMS skips.
			if ( ! is_bool( $success ) ) {
				$success = (bool) $success;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::state_table(),
				array(
					'step_id'      => sanitize_key( (string) $step_id ),
					'last_sent_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array(
					'guest_id'   => absint( $guest_id ),
					'journey_id' => self::JOURNEY_ID,
					'status'     => 'active',
				),
				array( '%s', '%s' ),
				array( '%d', '%s', '%s' )
			);
		}

		/**
		 * Mark guest journey completed.
		 *
		 * @param int $guest_id Guest ID.
		 * @return void
		 */
		private static function mark_completed( $guest_id ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::state_table(),
				array(
					'status'      => 'completed',
					'exit_reason' => '',
				),
				array(
					'guest_id'   => absint( $guest_id ),
					'journey_id' => self::JOURNEY_ID,
				),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			);
		}

		/**
		 * Approximate due count for status summary (best-effort, capped).
		 *
		 * @param array $journey Journey.
		 * @return int
		 */
		private static function count_due_now( $journey ) {
			if ( empty( $journey['enabled'] ) || empty( $journey['steps'] ) ) {
				return 0;
			}
			if ( ! class_exists( 'WGRSVP_Deadline_Nudges', false ) ) {
				return 0;
			}

			$segment          = isset( $journey['segment'] ) ? (string) $journey['segment'] : 'pending';
			$include_declined = ! empty( $journey['include_declined'] );
			$candidates       = WGRSVP_Deadline_Nudges::get_segment_guest_rows( $segment, $include_declined );
			if ( empty( $candidates ) ) {
				return 0;
			}

			$guests_by_id = array();
			foreach ( $candidates as $guest ) {
				if ( ! is_object( $guest ) || empty( $guest->id ) ) {
					continue;
				}
				$guests_by_id[ absint( $guest->id ) ] = $guest;
			}

			$mode = isset( $journey['mode'] ) ? (string) $journey['mode'] : 'relative';
			$due  = 0;

			if ( 'deadline_offsets' === $mode ) {
				$ctx = self::get_deadline_context();
				if ( null === $ctx ) {
					return 0;
				}
				$today_ymd = $ctx['today']->format( 'Y-m-d' );
				$due_steps = array();
				foreach ( $journey['steps'] as $step ) {
					$n = isset( $step['days_before_deadline'] ) ? absint( $step['days_before_deadline'] ) : 0;
					if ( $n < 1 ) {
						continue;
					}
					try {
						$send_on = $ctx['d_deadline']->modify( '-' . $n . ' days' )->format( 'Y-m-d' );
					} catch ( Exception $e ) {
						continue;
					}
					if ( $send_on === $today_ymd ) {
						$due_steps[] = $step;
					}
				}
				foreach ( $guests_by_id as $gid => $guest ) {
					foreach ( $due_steps as $step ) {
						if ( ! self::has_send_record( $gid, $step['id'] ) ) {
							++$due;
						}
					}
				}
				return $due;
			}

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE journey_id = %s AND status = %s LIMIT 500',
					self::state_table(),
					self::JOURNEY_ID,
					'active'
				)
			);
			if ( ! is_array( $rows ) ) {
				return 0;
			}
			$steps = $journey['steps'];
			foreach ( $rows as $state ) {
				$gid = isset( $state->guest_id ) ? absint( $state->guest_id ) : 0;
				if ( $gid < 1 || ! isset( $guests_by_id[ $gid ] ) ) {
					continue;
				}
				$next = self::next_relative_step( $state, $steps );
				if ( null === $next ) {
					continue;
				}
				if ( self::relative_step_is_due( $state, $next, $steps ) && ! self::has_send_record( $gid, $next['id'] ) ) {
					++$due;
				}
			}
			return $due;
		}

		/**
		 * Deadline context from general settings (site timezone).
		 *
		 * @return array{deadline:string,d_deadline:DateTimeImmutable,today:DateTimeImmutable}|null
		 */
		private static function get_deadline_context() {
			$settings = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			$deadline = isset( $settings['deadline_date'] ) ? sanitize_text_field( (string) $settings['deadline_date'] ) : '';
			if ( '' === $deadline || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $deadline ) ) {
				return null;
			}
			$tz = wp_timezone();
			try {
				$d_deadline = new DateTimeImmutable( $deadline . ' 00:00:00', $tz );
				$today      = new DateTimeImmutable( 'now', $tz );
			} catch ( Exception $e ) {
				return null;
			}
			return array(
				'deadline'   => $deadline,
				'd_deadline' => $d_deadline,
				'today'      => $today,
			);
		}

		/**
		 * Formatted deadline string for templates.
		 *
		 * @return string
		 */
		private static function format_deadline_for_template() {
			$ctx = self::get_deadline_context();
			if ( null === $ctx ) {
				return '';
			}
			if ( function_exists( 'wp_date' ) ) {
				try {
					return (string) wp_date( get_option( 'date_format' ), $ctx['d_deadline']->getTimestamp() );
				} catch ( Exception $e ) {
					return $ctx['deadline'];
				}
			}
			return $ctx['deadline'];
		}
	}
}
