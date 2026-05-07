<?php
/**
 * Parse unstructured “paste” guest lists for admin import.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Paste_Import' ) ) {

	/**
	 * Line-by-line parser: party headers, CSV-ish tuples, or “Name email@”.
	 */
	class WGRSVP_Paste_Import {

		const MAX_ROWS = 200;

		/**
		 * Split paste text into guest rows (unsanitized raw; caller sanitizes on insert).
		 *
		 * @param string $text          Full pasted text.
		 * @param string $default_party Party ID when lines omit party (may be empty if headers set).
		 * @return array<int,array<string,string>> List of rows with keys party_id, guest_name, email, phone.
		 */
		public static function parse_block( $text, $default_party = '' ) {
			$text          = is_string( $text ) ? str_replace( array( "\r\n", "\r" ), "\n", $text ) : '';
			$lines         = explode( "\n", $text );
			$current_party = trim( (string) $default_party );
			$out           = array();

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line || ( 0 === strpos( $line, '#' ) ) ) {
					continue;
				}
				if ( preg_match( '/^(?:party|party\s*id)\s*[:#]\s*(.+)$/iu', $line, $m ) ) {
					$current_party = trim( $m[1] );
					continue;
				}
				$row = self::parse_guest_line( $line, $current_party );
				if ( is_array( $row ) && '' !== $row['guest_name'] && '' !== $row['party_id'] ) {
					$out[] = $row;
				}
				if ( count( $out ) >= self::MAX_ROWS ) {
					break;
				}
			}

			return $out;
		}

		/**
		 * Parse one non-header line.
		 *
		 * @param string $line          Trimmed line.
		 * @param string $fallback_party Party from default or last “Party:” header.
		 * @return array<string,string>|null
		 */
		private static function parse_guest_line( $line, $fallback_party ) {
			$fallback_party = trim( (string) $fallback_party );

			// Comma- or tab-separated (mini CSV row).
			if ( false !== strpos( $line, "\t" ) ) {
				$parts = array_map( 'trim', explode( "\t", $line ) );
			} else {
				$parts = str_getcsv( $line );
				$parts = array_map( 'trim', $parts );
			}
			$parts = array_values(
				array_filter(
					$parts,
					static function ( $p ) {
						return '' !== (string) $p;
					}
				)
			);

			$n = count( $parts );
			if ( $n >= 4 && self::looks_like_party_id( $parts[0] ) ) {
				return array(
					'party_id'   => $parts[0],
					'guest_name' => $parts[1],
					'email'      => self::extract_email( $parts[2] ) ? self::extract_email( $parts[2] ) : '',
					'phone'      => isset( $parts[3] ) ? $parts[3] : '',
				);
			}
			if ( 3 === $n ) {
				if ( self::str_contains_at( $parts[1] ) ) {
					// name, email, phone
					return array(
						'party_id'   => $fallback_party,
						'guest_name' => $parts[0],
						'email'      => $parts[1],
						'phone'      => $parts[2],
					);
				}
				if ( self::looks_like_party_id( $parts[0] ) && self::str_contains_at( $parts[2] ) ) {
					return array(
						'party_id'   => $parts[0],
						'guest_name' => $parts[1],
						'email'      => $parts[2],
						'phone'      => '',
					);
				}
			}
			if ( 2 === $n ) {
				if ( self::str_contains_at( $parts[1] ) ) {
					return array(
						'party_id'   => $fallback_party,
						'guest_name' => $parts[0],
						'email'      => $parts[1],
						'phone'      => '',
					);
				}
				if ( self::looks_like_party_id( $parts[0] ) ) {
					return array(
						'party_id'   => $parts[0],
						'guest_name' => $parts[1],
						'email'      => '',
						'phone'      => '',
					);
				}
			}

			// “Name <email>” or free text with email somewhere.
			$email = self::extract_email( $line );
			if ( '' !== $email ) {
				$name_chunk = trim( str_replace( $email, '', $line ) );
				$name_chunk = preg_replace( '/\s+/', ' ', $name_chunk );
				$name_chunk = trim( $name_chunk, '<>,"\' ' );
				$phone      = '';
				if ( preg_match( '/([+()\d][\d\s().-]{6,}\d)/', $line, $pm ) ) {
					$phone = trim( $pm[1] );
				}
				if ( '' === $fallback_party ) {
					return null;
				}
				return array(
					'party_id'   => $fallback_party,
					'guest_name' => '' !== $name_chunk ? $name_chunk : __( 'Guest', 'wedding-party-rsvp' ),
					'email'      => $email,
					'phone'      => $phone,
				);
			}

			// Name only (no email).
			if ( '' !== $fallback_party && '' !== $line ) {
				return array(
					'party_id'   => $fallback_party,
					'guest_name' => $line,
					'email'      => '',
					'phone'      => '',
				);
			}

			return null;
		}

		/**
		 * @param string $s String.
		 * @return bool
		 */
		private static function str_contains_at( $s ) {
			return false !== strpos( (string) $s, '@' );
		}

		/**
		 * @param string $token Token.
		 * @return bool
		 */
		private static function looks_like_party_id( $token ) {
			$token = (string) $token;
			if ( strlen( $token ) < 2 || strlen( $token ) > 50 ) {
				return false;
			}
			return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9\-_]*$/', $token );
		}

		/**
		 * First email-like substring.
		 *
		 * @param string $line Line.
		 * @return string
		 */
		private static function extract_email( $line ) {
			if ( preg_match( '/[\w.%+\-]+@[\w.\-]+\.[A-Za-z]{2,}/', $line, $m ) ) {
				return $m[0];
			}
			return '';
		}
	}
}
