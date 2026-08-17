<?php
/**
 * Public .ics (iCalendar) download for “Add to calendar” after RSVP.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_ICS' ) ) {

	/**
	 * Build and serve calendar invites from shared general settings.
	 */
	class WGRSVP_ICS {

		const QUERY_FLAG  = 'wgrsvp_ics';
		const THANKS_FLAG = 'wgrsvp_thanks';

		/**
		 * Register front-end download handler.
		 *
		 * @return void
		 */
		public static function init_hooks() {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_ics' ), 1 );
		}

		/**
		 * Whether calendar feature is on and required fields exist.
		 *
		 * @param array $s wgrsvp_general_settings.
		 * @return bool
		 */
		public static function is_configured( array $s ) {
			if ( isset( $s['enable_add_to_calendar'] ) && ! $s['enable_add_to_calendar'] ) {
				return false;
			}
			$start = isset( $s['event_start'] ) ? trim( (string) $s['event_start'] ) : '';
			if ( '' === $start ) {
				return false;
			}
			$title = isset( $s['event_title'] ) ? trim( (string) $s['event_title'] ) : '';
			if ( '' === $title ) {
				$title = isset( $s['welcome_title'] ) ? trim( (string) $s['welcome_title'] ) : '';
			}
			return '' !== $title;
		}

		/**
		 * Public download URL for a party (nonce scoped to party).
		 *
		 * @param string $party_id Party ID.
		 * @return string
		 */
		public static function get_download_url( $party_id ) {
			$party_id = sanitize_text_field( (string) $party_id );
			if ( '' === $party_id ) {
				return '';
			}
			$nonce = wp_create_nonce( 'wgrsvp_ics_' . $party_id );
			return add_query_arg(
				array(
					self::QUERY_FLAG   => '1',
					'party_id'         => $party_id,
					'wgrsvp_ics_nonce' => $nonce,
				),
				home_url( '/' )
			);
		}

		/**
		 * Append signed thank-you query args to a base URL (custom Redirect URL or current page).
		 *
		 * Enables `[wgrsvp_guest_hub]` / Pro `[wpr_pro_rsvp_status]` on a custom thank-you page.
		 *
		 * @param string $url      Base URL.
		 * @param string $party_id Party ID.
		 * @return string Empty if URL or party ID is missing.
		 */
		public static function with_thank_you_args( $url, $party_id ) {
			$url      = esc_url_raw( (string) $url );
			$party_id = sanitize_text_field( (string) $party_id );
			if ( '' === $url || '' === $party_id ) {
				return '';
			}
			$nonce = wp_create_nonce( 'wgrsvp_thanks_' . $party_id );
			return add_query_arg(
				array(
					self::THANKS_FLAG     => '1',
					'party_id'            => $party_id,
					'wgrsvp_thanks_nonce' => $nonce,
				),
				$url
			);
		}

		/**
		 * Redirect URL for thank-you state (same page + query args).
		 *
		 * @param string $party_id Party ID.
		 * @return string
		 */
		public static function get_thank_you_redirect_url( $party_id ) {
			$party_id = sanitize_text_field( (string) $party_id );
			if ( '' === $party_id ) {
				return '';
			}
			$url = wp_get_referer();
			if ( ! is_string( $url ) || '' === $url ) {
				$url = home_url( '/' );
			}
			return self::with_thank_you_args( $url, $party_id );
		}

		/**
		 * Verify thank-you query args (short-lived display gate).
		 *
		 * @param string $party_id Party ID.
		 * @param string $nonce    Nonce string.
		 * @return bool
		 */
		public static function verify_thanks_nonce( $party_id, $nonce ) {
			return ( '' !== (string) $party_id && '' !== (string) $nonce && wp_verify_nonce( (string) $nonce, 'wgrsvp_thanks_' . (string) $party_id ) );
		}

		/**
		 * Serve .ics file if query matches.
		 *
		 * @return void
		 */
		public static function maybe_serve_ics() {
			if ( ! isset( $_GET[ self::QUERY_FLAG ] ) || '1' !== sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_FLAG ] ) ) ) {
				return;
			}
			$party_id = isset( $_GET['party_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['party_id'] ) ) : '';
			$nonce    = isset( $_GET['wgrsvp_ics_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_ics_nonce'] ) ) : '';
			if ( '' === $party_id || '' === $nonce || ! wp_verify_nonce( $nonce, 'wgrsvp_ics_' . $party_id ) ) {
				wp_die( esc_html__( 'Invalid calendar link.', 'wedding-party-rsvp' ), esc_html__( 'Calendar', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
			}

			$s = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $s ) || ! self::is_configured( $s ) ) {
				wp_die( esc_html__( 'Calendar is not available.', 'wedding-party-rsvp' ), esc_html__( 'Calendar', 'wedding-party-rsvp' ), array( 'response' => 404 ) );
			}

			$body = self::build_ics( $s, $party_id );
			if ( '' === $body ) {
				wp_die( esc_html__( 'Calendar is not available.', 'wedding-party-rsvp' ), esc_html__( 'Calendar', 'wedding-party-rsvp' ), array( 'response' => 404 ) );
			}

			nocache_headers();
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="event.ics"' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary/text calendar body; no HTML.
			echo $body;
			exit;
		}

		/**
		 * Escape ICS text content.
		 *
		 * @param string $t Raw.
		 * @return string
		 */
		private static function escape_ics_text( $t ) {
			$t = str_replace( array( '\\', ';', ',', "\r\n", "\n", "\r" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n', '\\n' ), (string) $t );
			return $t;
		}

		/**
		 * Fold long lines per RFC 5545 (75 octets).
		 *
		 * @param string $line Line without CRLF.
		 * @return string
		 */
		private static function fold_line( $line ) {
			$max = 73;
			if ( strlen( $line ) <= $max ) {
				return $line;
			}
			$out = '';
			$len = strlen( $line );
			while ( $len > $max ) {
				$out .= substr( $line, 0, $max ) . "\r\n ";
				$line = substr( $line, $max );
				$len  = strlen( $line );
			}
			return $out . $line;
		}

		/**
		 * Parse event start/end as UTC DateTimeImmutable.
		 *
		 * @param array $s Settings.
		 * @return array{0:\DateTimeImmutable|null,1:\DateTimeImmutable|null} Start and end UTC.
		 */
		private static function get_event_range_utc( array $s ) {
			$tz  = wp_timezone();
			$raw = isset( $s['event_start'] ) ? trim( (string) $s['event_start'] ) : '';
			if ( '' === $raw ) {
				return array( null, null );
			}
			try {
				$start_local = date_create_immutable_from_format( 'Y-m-d H:i:s', $raw, $tz );
				if ( false === $start_local ) {
					$start_local = date_create_immutable( $raw, $tz );
				}
				if ( false === $start_local ) {
					return array( null, null );
				}
			} catch ( \Exception $e ) {
				return array( null, null );
			}

			$start_utc = $start_local->setTimezone( new DateTimeZone( 'UTC' ) );

			$end_raw = isset( $s['event_end'] ) ? trim( (string) $s['event_end'] ) : '';
			if ( '' !== $end_raw ) {
				try {
					$end_local = date_create_immutable_from_format( 'Y-m-d H:i:s', $end_raw, $tz );
					if ( false === $end_local ) {
						$end_local = date_create_immutable( $end_raw, $tz );
					}
					if ( false !== $end_local ) {
						return array( $start_utc, $end_local->setTimezone( new DateTimeZone( 'UTC' ) ) );
					}
				} catch ( \Exception $e ) {
					// Fall through to default duration.
					unset( $e );
				}
			}

			$end_utc = $start_utc->modify( '+2 hours' );
			return array( $start_utc, $end_utc );
		}

		/**
		 * Build full ICS document.
		 *
		 * @param array  $s        Settings.
		 * @param string $party_id Party ID for description context.
		 * @return string
		 */
		private static function build_ics( array $s, $party_id ) {
			list( $start, $end ) = self::get_event_range_utc( $s );
			if ( null === $start || null === $end ) {
				return '';
			}

			$title = isset( $s['event_title'] ) ? trim( (string) $s['event_title'] ) : '';
			if ( '' === $title ) {
				$title = isset( $s['welcome_title'] ) ? trim( (string) $s['welcome_title'] ) : '';
			}
			if ( '' === $title ) {
				return '';
			}

			$loc        = isset( $s['event_location'] ) ? trim( (string) $s['event_location'] ) : '';
			$desc_parts = array();
			if ( ! empty( $s['event_description'] ) ) {
				$desc_parts[] = trim( (string) $s['event_description'] );
			}
			/* translators: %s: Party ID. */
			$desc_parts[] = sprintf( __( 'Invitation party ID: %s', 'wedding-party-rsvp' ), $party_id );

			$uid = md5( home_url( '/' ) . '|' . $title . '|' . $start->format( 'c' ) . '|' . $party_id ) . '@wedding-party-rsvp';

			$dtstamp = gmdate( 'Ymd\THis\Z' );
			$dtstart = $start->format( 'Ymd\THis\Z' );
			$dtend   = $end->format( 'Ymd\THis\Z' );

			$lines = array(
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Wedding Party RSVP//EN',
				'CALSCALE:GREGORIAN',
				'METHOD:PUBLISH',
				'BEGIN:VEVENT',
				'UID:' . self::escape_ics_text( $uid ),
				'DTSTAMP:' . $dtstamp,
				'DTSTART:' . $dtstart,
				'DTEND:' . $dtend,
				'SUMMARY:' . self::escape_ics_text( $title ),
			);
			if ( '' !== $loc ) {
				$lines[] = 'LOCATION:' . self::escape_ics_text( $loc );
			}
			$lines[] = 'DESCRIPTION:' . self::escape_ics_text( implode( "\n", $desc_parts ) );
			$lines[] = 'END:VEVENT';
			$lines[] = 'END:VCALENDAR';

			$out = '';
			foreach ( $lines as $line ) {
				$out .= self::fold_line( $line ) . "\r\n";
			}
			return $out;
		}

		/**
		 * Human-readable event start for Guest Hub (site timezone).
		 *
		 * @param array $s wgrsvp_general_settings.
		 * @return string Empty when missing or not parseable; caller may fall back to raw settings text.
		 */
		public static function format_event_start_for_display( array $s ) {
			$tz  = wp_timezone();
			$raw = isset( $s['event_start'] ) ? trim( (string) $s['event_start'] ) : '';
			if ( '' === $raw ) {
				return '';
			}

			try {
				$start_local = date_create_immutable_from_format( 'Y-m-d H:i:s', $raw, $tz );
				if ( false === $start_local ) {
					$start_local = date_create_immutable( $raw, $tz );
				}
				if ( false === $start_local ) {
					return '';
				}
			} catch ( \Exception $e ) {
				return '';
			}

			/**
			 * PHP date()-style format for wp_date() on the Guest Hub “When” line.
			 *
			 * @since 7.3.25
			 * @param string $format Default: site date + time format options.
			 * @param array  $s      General settings.
			 */
			$format = apply_filters( 'wgrsvp_guest_hub_event_start_format', get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $s );
			if ( ! is_string( $format ) || '' === $format ) {
				$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			}

			return wp_date( $format, $start_local->getTimestamp(), $tz );
		}
	}
}

if ( ! function_exists( 'wgrsvp_get_ics_download_url' ) ) {
	/**
	 * Public ICS download URL (requires Free plugin loaded).
	 *
	 * @param string $party_id Party ID.
	 * @return string
	 */
	function wgrsvp_get_ics_download_url( $party_id ) {
		if ( class_exists( 'WGRSVP_ICS' ) ) {
			return WGRSVP_ICS::get_download_url( (string) $party_id );
		}
		return '';
	}
}

if ( ! function_exists( 'wgrsvp_calendar_is_configured' ) ) {
	/**
	 * Whether “Add to calendar” should be offered for general settings.
	 *
	 * @param mixed $settings wgrsvp_general_settings.
	 * @return bool
	 */
	function wgrsvp_calendar_is_configured( $settings ) {
		return is_array( $settings ) && class_exists( 'WGRSVP_ICS' ) && WGRSVP_ICS::is_configured( $settings );
	}
}
