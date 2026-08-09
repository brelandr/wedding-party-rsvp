<?php
/**
 * RSVP deadline reminder emails (WP-Cron, daily) + manual "send now" trigger.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Deadline_Nudges' ) ) {

	/**
	 * Schedules and sends reminder emails N days before the RSVP deadline.
	 */
	class WGRSVP_Deadline_Nudges {

		const CRON_HOOK = 'wgrsvp_rsvp_deadline_nudge';

		const MANUAL_LOCK_TRANSIENT = 'wedding-party-rsvp_manual_nudge_lock';

		const MANUAL_LOCK_SECONDS = 6 * HOUR_IN_SECONDS;

		/**
		 * Register cron hook and schedule.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
			add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
			add_action( 'admin_post_wgrsvp_send_nudges_now', array( __CLASS__, 'handle_send_now' ) );
		}

		/**
		 * Ensure daily event exists.
		 *
		 * @return void
		 */
		public static function maybe_schedule() {
			self::schedule_if_missing();
		}

		/**
		 * Schedule daily hook once (activation + init fallback).
		 *
		 * @return void
		 */
		public static function schedule_if_missing() {
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				return;
			}
			wp_schedule_event( time() + wp_rand( 120, 900 ), 'daily', self::CRON_HOOK );
		}

		/**
		 * Parse "7,3,1" style day offsets.
		 *
		 * @param string $raw Raw setting.
		 * @return int[]
		 */
		private static function parse_offsets( $raw ) {
			$raw = is_string( $raw ) ? $raw : '';
			$out = array();
			foreach ( explode( ',', $raw ) as $part ) {
				$n = absint( trim( $part ) );
				if ( $n > 0 && $n <= 365 ) {
					$out[] = $n;
				}
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * Build RSVP URL with party ID.
		 *
		 * @param array  $settings General settings.
		 * @param string $party_id Party ID.
		 * @return string
		 */
		private static function rsvp_url_for_party( $settings, $party_id ) {
			$base = '';
			if ( ! empty( $settings['rsvp_page_url'] ) ) {
				$base = $settings['rsvp_page_url'];
			}
			if ( ! is_string( $base ) || '' === trim( $base ) ) {
				$base = home_url( '/' );
			}
			$args = array(
				'party_id' => rawurlencode( (string) $party_id ),
			);
			$segment = isset( $settings['deadline_nudge_segment'] )
				? sanitize_key( (string) $settings['deadline_nudge_segment'] )
				: 'pending';
			if ( 'missing_address' === $segment ) {
				// Shared query args with Pro address campaign focus script.
				$args['wpr_details'] = '1';
				$args['wpr_focus']   = 'address';
			}
			$url = add_query_arg( $args, $base );
			if ( class_exists( 'WGRSVP_Magic_Link' ) ) {
				$url = WGRSVP_Magic_Link::sign_url( $url, (string) $party_id );
			}
			/**
			 * Filter the RSVP deep link used in deadline nudge emails/SMS.
			 *
			 * @since 7.3.40
			 * @param string $url      URL.
			 * @param string $party_id Party ID.
			 * @param array  $settings General settings.
			 */
			return (string) apply_filters( 'wgrsvp_deadline_nudge_rsvp_url', $url, (string) $party_id, $settings );
		}

		/**
		 * Transient key: one send per guest per deadline per offset (cron path).
		 *
		 * @param string $deadline Y-m-d.
		 * @param int    $offset   Days before deadline.
		 * @param int    $guest_id Row ID.
		 * @return string
		 */
		private static function guest_sent_transient_key( $deadline, $offset, $guest_id ) {
			return 'wedding-party-rsvp_dnu_' . md5( (string) $deadline . '|' . (int) $offset . '|' . (int) $guest_id );
		}

		/**
		 * Transient key for the manual ("send now") path, separate from cron keys
		 * so a manual blast does not block scheduled offset sends.
		 *
		 * @param string $deadline Y-m-d.
		 * @param int    $guest_id Row ID.
		 * @return string
		 */
		private static function guest_manual_transient_key( $deadline, $guest_id ) {
			return 'wedding-party-rsvp_dnu_man_' . md5( (string) $deadline . '|' . (int) $guest_id );
		}

		/**
		 * General settings array.
		 *
		 * @return array
		 */
		private static function get_settings() {
			$settings = get_option( 'wgrsvp_general_settings', array() );
			return is_array( $settings ) ? $settings : array();
		}

		/**
		 * Deadline date context (validated) or null.
		 *
		 * @param array $settings General settings.
		 * @return array{deadline:string,d_deadline:DateTimeImmutable,today:DateTimeImmutable,deadline_fmt:string}|null
		 */
		private static function get_deadline_context( $settings ) {
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

			$deadline_fmt = $deadline;
			if ( function_exists( 'wp_date' ) ) {
				try {
					$deadline_fmt = wp_date( get_option( 'date_format' ), $d_deadline->getTimestamp() );
				} catch ( Exception $e ) {
					$deadline_fmt = $deadline;
				}
			}

			return array(
				'deadline'     => $deadline,
				'd_deadline'   => $d_deadline,
				'today'        => $today,
				'deadline_fmt' => $deadline_fmt,
			);
		}

		/**
		 * Guest rows eligible for a nudge (Pending; optionally Declined), filtered.
		 *
		 * @param array  $settings General settings.
		 * @param string $deadline Deadline Y-m-d (for the recipients filter).
		 * @return object[]
		 */
		private static function fetch_recipients( $settings, $deadline ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wedding_rsvps';

			$segment = isset( $settings['deadline_nudge_segment'] )
				? sanitize_key( (string) $settings['deadline_nudge_segment'] )
				: 'pending';
			$allowed = array( 'pending', 'missing_meal', 'missing_address', 'missing_phone', 'accepted_missing_meal', 'accepted_sub_event_pending' );
			if ( ! in_array( $segment, $allowed, true ) ) {
				$segment = 'pending';
			}

			$status_in = array( 'Pending' );
			if ( in_array( $segment, array( 'accepted_missing_meal', 'accepted_sub_event_pending' ), true ) ) {
				$status_in = array( 'Accepted' );
			} elseif ( ! empty( $settings['deadline_nudge_include_declined'] ) ) {
				$status_in[] = 'Declined';
			}

			$in_formats = implode( ',', array_fill( 0, count( $status_in ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- %i; IN list built from %s tokens; values spread.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE rsvp_status IN (' . $in_formats . ')',
					...array_merge( array( $table ), $status_in )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( empty( $rows ) || ! is_array( $rows ) ) {
				return array();
			}

			$filtered = array();
			foreach ( $rows as $guest ) {
				if ( ! is_object( $guest ) ) {
					continue;
				}
				if ( ! self::guest_matches_segment( $guest, $segment ) ) {
					continue;
				}
				$filtered[] = $guest;
			}
			$rows = $filtered;

			/**
			 * Guest rows selected for deadline nudge emails (modify or replace).
			 *
			 * @since 7.3.23
			 * @param object[] $rows     Guest row objects.
			 * @param string   $deadline Deadline Y-m-d.
			 * @param array    $settings General settings.
			 */
			$rows = apply_filters( 'wgrsvp_deadline_nudge_recipients', $rows, $deadline, $settings );

			return ( is_array( $rows ) && ! empty( $rows ) ) ? $rows : array();
		}

		/**
		 * Whether a guest matches the configured nudge segment.
		 *
		 * @param object $guest   Guest row.
		 * @param string $segment Segment key.
		 * @return bool
		 */
		private static function guest_matches_segment( $guest, $segment ) {
			$meal    = isset( $guest->menu_choice ) ? trim( (string) $guest->menu_choice ) : '';
			$child   = isset( $guest->child_menu_choice ) ? trim( (string) $guest->child_menu_choice ) : '';
			$address = isset( $guest->address ) ? trim( (string) $guest->address ) : '';
			$phone   = isset( $guest->phone ) ? trim( (string) $guest->phone ) : '';
			$has_meal = ( '' !== $meal || '' !== $child );

			switch ( $segment ) {
				case 'missing_meal':
				case 'accepted_missing_meal':
					return ! $has_meal;
				case 'missing_address':
					return '' === $address;
				case 'missing_phone':
					return '' === $phone;
				case 'accepted_sub_event_pending':
					/**
					 * Whether an Accepted guest still has an unanswered invited sub-event.
					 * Pro hooks this when sub-events are active.
					 *
					 * @since 7.3.24
					 * @param bool   $has_pending Default false.
					 * @param object $guest       Guest row.
					 */
					return (bool) apply_filters( 'wgrsvp_guest_has_unanswered_sub_event', false, $guest );
				case 'pending':
				default:
					return true;
			}
		}

		/**
		 * Append a lightweight audit row (no PII beyond guest id / party).
		 *
		 * @param string $context cron|manual.
		 * @param int    $sent_n  Emails sent.
		 * @param string $segment Segment key.
		 * @param int    $offset  Days offset.
		 * @return void
		 */
		private static function append_audit( $context, $sent_n, $segment, $offset ) {
			$log = get_option( 'wgrsvp_deadline_nudge_audit', array() );
			if ( ! is_array( $log ) ) {
				$log = array();
			}
			$log[] = array(
				'at'      => gmdate( 'c' ),
				'context' => sanitize_key( (string) $context ),
				'sent'    => absint( $sent_n ),
				'segment' => sanitize_key( (string) $segment ),
				'offset'  => absint( $offset ),
			);
			if ( count( $log ) > 40 ) {
				$log = array_slice( $log, -40 );
			}
			update_option( 'wgrsvp_deadline_nudge_audit', $log, false );
		}

		/**
		 * Count non-responders with a usable email address (for the admin preview).
		 *
		 * @return int
		 */
		public static function count_recipients() {
			$settings = self::get_settings();
			$count    = 0;
			$ctx      = self::get_deadline_context( $settings );
			$deadline = null !== $ctx ? $ctx['deadline'] : '';
			foreach ( self::fetch_recipients( $settings, $deadline ) as $guest ) {
				if ( ! is_object( $guest ) ) {
					continue;
				}
				$email = isset( $guest->email ) ? sanitize_email( (string) $guest->email ) : '';
				if ( '' !== $email && is_email( $email ) ) {
					++$count;
				}
			}
			return $count;
		}

		/**
		 * Render templates + send to a set of guest rows. Shared by cron and manual paths.
		 *
		 * @param object[] $rows     Guest rows.
		 * @param array    $settings General settings.
		 * @param array    $ctx      Deadline context (see get_deadline_context()).
		 * @param int      $offset   Days-before value (0 for manual sends).
		 * @param string   $context  'cron' or 'manual'.
		 * @return int Number of emails sent.
		 */
		private static function send_to_rows( $rows, $settings, $ctx, $offset, $context ) {
			$deadline     = $ctx['deadline'];
			$d_deadline   = $ctx['d_deadline'];
			$today        = $ctx['today'];
			$deadline_fmt = $ctx['deadline_fmt'];

			$subject_tpl = isset( $settings['deadline_nudge_subject'] ) && '' !== trim( (string) $settings['deadline_nudge_subject'] )
				? (string) $settings['deadline_nudge_subject']
				: __( 'Reminder: RSVP by {deadline}', 'wedding-party-rsvp' );
			$body_tpl    = isset( $settings['deadline_nudge_body'] ) && '' !== trim( (string) $settings['deadline_nudge_body'] )
				? (string) $settings['deadline_nudge_body']
				: __( "Hello {guest_name},\n\nPlease submit or update your RSVP by {deadline}.\n\n{rsvp_url}\n\nThank you!", 'wedding-party-rsvp' );

			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
			$sent_n  = 0;

			foreach ( $rows as $guest ) {
				if ( ! is_object( $guest ) ) {
					continue;
				}
				$gid = isset( $guest->id ) ? absint( $guest->id ) : 0;
				if ( $gid < 1 ) {
					continue;
				}
				$email = isset( $guest->email ) ? sanitize_email( (string) $guest->email ) : '';
				if ( '' === $email || ! is_email( $email ) ) {
					continue;
				}

				$tkey = ( 'manual' === $context )
					? self::guest_manual_transient_key( $deadline, $gid )
					: self::guest_sent_transient_key( $deadline, $offset, $gid );
				if ( get_transient( $tkey ) ) {
					continue;
				}

				$party_id = isset( $guest->party_id ) ? (string) $guest->party_id : '';
				$name     = isset( $guest->guest_name ) ? (string) $guest->guest_name : '';
				$rsvp_url = self::rsvp_url_for_party( $settings, $party_id );

				$repl = array(
					'{guest_name}' => $name,
					'{party_id}'   => $party_id,
					'{rsvp_url}'   => $rsvp_url,
					'{deadline}'   => $deadline_fmt,
				);

				$subject = str_replace( array_keys( $repl ), array_values( $repl ), $subject_tpl );
				$body    = str_replace( array_keys( $repl ), array_values( $repl ), $body_tpl );

				/**
				 * Filter recipients for deadline nudge (remove or add rows).
				 *
				 * @since 7.3.23
				 * @param object   $guest  Guest row.
				 * @param string   $deadline Y-m-d deadline.
				 * @param int      $offset Days-before value for this run (0 = manual send).
				 * @param array    $settings General settings.
				 */
				$skip = (bool) apply_filters( 'wgrsvp_deadline_nudge_skip_guest', false, $guest, $deadline, $offset, $settings );
				if ( $skip ) {
					continue;
				}

				$sent = wp_mail( $email, $subject, $body, $headers );

				if ( $sent ) {
					++$sent_n;
					if ( 'manual' === $context ) {
						set_transient( $tkey, 1, self::MANUAL_LOCK_SECONDS );
					} else {
						$ttl = max( DAY_IN_SECONDS, (int) ( $d_deadline->getTimestamp() - $today->getTimestamp() ) + 7 * DAY_IN_SECONDS );
						set_transient( $tkey, 1, $ttl );
					}
					/**
					 * After a deadline nudge email was sent to a guest.
					 *
					 * @since 7.3.23
					 * @param object $guest    Guest row.
					 * @param string $deadline Deadline Y-m-d.
					 * @param int    $offset   Days-before offset (0 = manual "send now").
					 * @param array  $settings General settings.
					 */
					do_action( 'wgrsvp_deadline_nudge_sent_email', $guest, $deadline, $offset, $settings );
				}
			}

			$segment = isset( $settings['deadline_nudge_segment'] ) ? (string) $settings['deadline_nudge_segment'] : 'pending';
			if ( ! empty( $settings['deadline_nudge_audit'] ) ) {
				self::append_audit( $context, $sent_n, $segment, $offset );
			}

			return $sent_n;
		}

		/**
		 * Quiet-hours gate for cron (site timezone). Manual sends always allowed.
		 *
		 * @param array $settings Settings.
		 * @return bool True when sending is allowed.
		 */
		private static function within_send_window( $settings ) {
			if ( ! isset( $settings['deadline_nudge_quiet_start'], $settings['deadline_nudge_quiet_end'] ) ) {
				return true;
			}
			$start = (int) $settings['deadline_nudge_quiet_start'];
			$end   = (int) $settings['deadline_nudge_quiet_end'];
			if ( $start < 0 || $end < 0 || $start > 23 || $end > 23 || $start === $end ) {
				return true;
			}
			$hour = absint( wp_date( 'G' ) );
			// Quiet when inside [start, end); overnight when start > end (e.g. 21 → 8).
			$in_quiet = ( $start < $end )
				? ( $hour >= $start && $hour < $end )
				: ( $hour >= $start || $hour < $end );
			return ! $in_quiet;
		}

		/**
		 * Public: guests matching a reminder segment (for drip enrollment).
		 *
		 * @param string $segment          Segment key.
		 * @param bool   $include_declined Include Declined for pending-family segments.
		 * @return object[]
		 */
		public static function get_segment_guest_rows( $segment, $include_declined = false ) {
			$settings                                   = self::get_settings();
			$settings['deadline_nudge_segment']          = sanitize_key( (string) $segment );
			$settings['deadline_nudge_include_declined'] = $include_declined ? 1 : 0;
			$ctx                                        = self::get_deadline_context( $settings );
			$deadline                                   = null !== $ctx ? $ctx['deadline'] : '';
			return self::fetch_recipients( $settings, $deadline );
		}

		/**
		 * Public: whether cron is inside the quiet-hours window (false = quiet).
		 *
		 * @param array|null $settings Optional settings; defaults to general settings.
		 * @return bool True when sending is allowed.
		 */
		public static function is_within_send_window( $settings = null ) {
			if ( ! is_array( $settings ) ) {
				$settings = self::get_settings();
			}
			return self::within_send_window( $settings );
		}

		/**
		 * Public: RSVP URL for a party (shared with drip templates).
		 *
		 * @param string $party_id Party ID.
		 * @return string
		 */
		public static function build_rsvp_url_for_party( $party_id ) {
			return self::rsvp_url_for_party( self::get_settings(), (string) $party_id );
		}

		/**
		 * Cron callback.
		 *
		 * @return void
		 */
		public static function run_cron() {
			$settings = self::get_settings();
			if ( empty( $settings['deadline_nudges_enabled'] ) ) {
				return;
			}
			// Multi-step drip supersedes legacy deadline cron when a drip journey is enabled.
			if ( class_exists( 'WGRSVP_Drip', false ) && WGRSVP_Drip::journey_supersedes_deadline_cron() ) {
				return;
			}
			if ( ! self::within_send_window( $settings ) ) {
				return;
			}
			$ctx = self::get_deadline_context( $settings );
			if ( null === $ctx ) {
				return;
			}

			$today_ymd = $ctx['today']->format( 'Y-m-d' );
			// Stop after deadline day.
			if ( $today_ymd > $ctx['deadline'] ) {
				return;
			}

			$offsets = self::parse_offsets( isset( $settings['deadline_nudge_days'] ) ? (string) $settings['deadline_nudge_days'] : '7,3,1' );
			if ( empty( $offsets ) ) {
				return;
			}

			$matching = array();
			foreach ( $offsets as $n ) {
				try {
					$send_on = $ctx['d_deadline']->modify( '-' . (int) $n . ' days' )->format( 'Y-m-d' );
				} catch ( Exception $e ) {
					continue;
				}
				if ( $send_on === $today_ymd ) {
					$matching[] = (int) $n;
				}
			}
			if ( empty( $matching ) ) {
				return;
			}

			$rows = self::fetch_recipients( $settings, $ctx['deadline'] );
			if ( empty( $rows ) ) {
				return;
			}

			foreach ( $matching as $offset ) {
				self::send_to_rows( $rows, $settings, $ctx, $offset, 'cron' );
			}
		}

		/**
		 * Seconds remaining on the manual-send throttle lock (0 = unlocked).
		 *
		 * @return int
		 */
		public static function manual_lock_remaining() {
			$lock = get_transient( self::MANUAL_LOCK_TRANSIENT );
			if ( ! $lock ) {
				return 0;
			}
			$expires = absint( $lock );
			$now     = time();
			return ( $expires > $now ) ? ( $expires - $now ) : 0;
		}

		/**
		 * Send a reminder immediately to all non-responders (manual trigger).
		 *
		 * @return array{sent:int,error:string}
		 */
		public static function send_now() {
			$settings = self::get_settings();
			$ctx      = self::get_deadline_context( $settings );
			if ( null === $ctx ) {
				return array(
					'sent'  => 0,
					'error' => __( 'Set an RSVP deadline date under Settings before sending reminders.', 'wedding-party-rsvp' ),
				);
			}
			if ( $ctx['today']->format( 'Y-m-d' ) > $ctx['deadline'] ) {
				return array(
					'sent'  => 0,
					'error' => __( 'The RSVP deadline has already passed, so reminders were not sent.', 'wedding-party-rsvp' ),
				);
			}
			if ( self::manual_lock_remaining() > 0 ) {
				return array(
					'sent'  => 0,
					'error' => __( 'A manual reminder blast was already sent recently. Please wait before sending again.', 'wedding-party-rsvp' ),
				);
			}

			$rows = self::fetch_recipients( $settings, $ctx['deadline'] );
			if ( empty( $rows ) ) {
				return array(
					'sent'  => 0,
					'error' => '',
				);
			}

			set_transient( self::MANUAL_LOCK_TRANSIENT, time() + self::MANUAL_LOCK_SECONDS, self::MANUAL_LOCK_SECONDS );

			$sent = self::send_to_rows( $rows, $settings, $ctx, 0, 'manual' );

			/**
			 * After a manual "send reminder now" blast completed.
			 *
			 * @since 8.2.0
			 * @param int    $sent     Emails sent.
			 * @param string $deadline Deadline Y-m-d.
			 * @param array  $settings General settings.
			 */
			do_action( 'wgrsvp_manual_nudge_blast_sent', $sent, $ctx['deadline'], $settings );

			return array(
				'sent'  => $sent,
				'error' => '',
			);
		}

		/**
		 * Admin-post handler for the "Send reminder now" button.
		 *
		 * @return void
		 */
		public static function handle_send_now() {
			if ( ! isset( $_POST['wgrsvp_send_nudges_now_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_send_nudges_now_nonce'] ) ), 'wgrsvp_send_nudges_now' ) ) {
				wp_die( esc_html__( 'Invalid security token.', 'wedding-party-rsvp' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wedding-party-rsvp' ) );
			}

			$result = self::send_now();

			$redirect = add_query_arg(
				array(
					'page'              => 'wedding-rsvp-ops',
					'wgrsvp_ops_tab'    => 'followup',
					'wgrsvp_nudge_sent' => (int) $result['sent'],
					'wgrsvp_nudge_err'  => '' !== $result['error'] ? $result['error'] : false,
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $redirect );
			exit;
		}
	}
}
