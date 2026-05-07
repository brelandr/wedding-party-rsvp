<?php
/**
 * RSVP deadline reminder emails (WP-Cron, daily).
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

		/**
		 * Register cron hook and schedule.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
			add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
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
			return add_query_arg( 'party_id', rawurlencode( (string) $party_id ), $base );
		}

		/**
		 * Transient key: one send per guest per deadline per offset.
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
		 * Cron callback.
		 *
		 * @return void
		 */
		public static function run_cron() {
			$settings = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			if ( empty( $settings['deadline_nudges_enabled'] ) ) {
				return;
			}
			$deadline = isset( $settings['deadline_date'] ) ? sanitize_text_field( (string) $settings['deadline_date'] ) : '';
			if ( '' === $deadline || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $deadline ) ) {
				return;
			}

			$tz = wp_timezone();
			try {
				$d_deadline = new DateTimeImmutable( $deadline . ' 00:00:00', $tz );
				$today      = new DateTimeImmutable( 'now', $tz );
			} catch ( Exception $e ) {
				return;
			}

			$today_ymd = $today->format( 'Y-m-d' );
			// Stop after deadline day.
			if ( $today_ymd > $deadline ) {
				return;
			}

			$offsets = self::parse_offsets( isset( $settings['deadline_nudge_days'] ) ? (string) $settings['deadline_nudge_days'] : '7,3,1' );
			if ( empty( $offsets ) ) {
				return;
			}

			$matching = array();
			foreach ( $offsets as $n ) {
				try {
					$send_on = $d_deadline->modify( '-' . (int) $n . ' days' )->format( 'Y-m-d' );
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

			global $wpdb;
			$table = $wpdb->prefix . 'wedding_rsvps';

			$status_in = array( 'Pending' );
			if ( ! empty( $settings['deadline_nudge_include_declined'] ) ) {
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
				return;
			}

			/**
			 * Guest rows selected for deadline nudge emails (modify or replace).
			 *
			 * @since 7.3.23
			 * @param object[] $rows     Guest row objects.
			 * @param string   $deadline Deadline Y-m-d.
			 * @param array    $settings General settings.
			 */
			$rows = apply_filters( 'wgrsvp_deadline_nudge_recipients', $rows, $deadline, $settings );
			if ( empty( $rows ) || ! is_array( $rows ) ) {
				return;
			}

			$subject_tpl = isset( $settings['deadline_nudge_subject'] ) && '' !== trim( (string) $settings['deadline_nudge_subject'] )
				? (string) $settings['deadline_nudge_subject']
				: __( 'Reminder: RSVP by {deadline}', 'wedding-party-rsvp' );
			$body_tpl    = isset( $settings['deadline_nudge_body'] ) && '' !== trim( (string) $settings['deadline_nudge_body'] )
				? (string) $settings['deadline_nudge_body']
				: __( "Hello {guest_name},\n\nPlease submit or update your RSVP by {deadline}.\n\n{rsvp_url}\n\nThank you!", 'wedding-party-rsvp' );

			$deadline_fmt = $deadline;
			if ( function_exists( 'wp_date' ) ) {
				try {
					$deadline_fmt = wp_date( get_option( 'date_format' ), $d_deadline->getTimestamp() );
				} catch ( Exception $e ) {
					$deadline_fmt = $deadline;
				}
			}

			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

			foreach ( $matching as $offset ) {
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

					$tkey = self::guest_sent_transient_key( $deadline, $offset, $gid );
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
					 * @param int      $offset Days-before value for this run.
					 * @param array    $settings General settings.
					 */
					$skip = (bool) apply_filters( 'wgrsvp_deadline_nudge_skip_guest', false, $guest, $deadline, $offset, $settings );
					if ( $skip ) {
						continue;
					}

					$sent = wp_mail( $email, $subject, $body, $headers );

					if ( $sent ) {
						$ttl = max( DAY_IN_SECONDS, (int) ( $d_deadline->getTimestamp() - $today->getTimestamp() ) + 7 * DAY_IN_SECONDS );
						set_transient( $tkey, 1, $ttl );
						/**
						 * After a deadline nudge email was sent to a guest.
						 *
						 * @since 7.3.23
						 * @param object $guest    Guest row.
						 * @param string $deadline Deadline Y-m-d.
						 * @param int    $offset   Days-before offset.
						 * @param array  $settings General settings.
						 */
						do_action( 'wgrsvp_deadline_nudge_sent_email', $guest, $deadline, $offset, $settings );
					}
				}
			}
		}
	}
}
