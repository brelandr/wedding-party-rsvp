<?php
/**
 * Stateless HMAC tokens for personalized ("magic") RSVP links.
 *
 * Reuses the caterer-portal signing approach: hash_hmac with wp_salt( 'auth' ),
 * so tokens need no DB storage and survive guest edits. Plain ?party_id= links
 * keep working; the token only adds a verified, personalized landing state.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Magic_Link' ) ) {

	/**
	 * Per-party signed RSVP link helpers.
	 */
	class WGRSVP_Magic_Link {

		const QUERY_ARG = 'wgrsvp_t';

		const TOKEN_LENGTH = 20;

		/**
		 * Deterministic token for a party (empty string when party ID is blank).
		 *
		 * @param string $party_id Party ID.
		 * @return string
		 */
		public static function token_for_party( $party_id ) {
			$party_id = trim( (string) $party_id );
			if ( '' === $party_id ) {
				return '';
			}
			return substr( hash_hmac( 'sha256', 'rsvp-link|' . $party_id, wp_salt( 'auth' ) ), 0, self::TOKEN_LENGTH );
		}

		/**
		 * Constant-time token check.
		 *
		 * @param string $party_id Party ID.
		 * @param string $token    Candidate token.
		 * @return bool
		 */
		public static function verify( $party_id, $token ) {
			$expected = self::token_for_party( $party_id );
			return ( '' !== $expected && is_string( $token ) && hash_equals( $expected, $token ) );
		}

		/**
		 * Append the signed token to a party RSVP URL.
		 *
		 * @param string $url      RSVP URL (already carries party_id).
		 * @param string $party_id Party ID.
		 * @return string
		 */
		public static function sign_url( $url, $party_id ) {
			$party_id = (string) $party_id;
			$token    = self::token_for_party( $party_id );
			if ( '' === $token ) {
				return (string) $url;
			}
			$url = add_query_arg( self::QUERY_ARG, $token, (string) $url );

			/**
			 * Signed (magic-link) RSVP URL for a party.
			 *
			 * @since 8.2.0
			 * @param string $url      Full signed URL.
			 * @param string $party_id Party ID.
			 */
			return (string) apply_filters( 'wgrsvp_magic_link_url', $url, $party_id );
		}

		/**
		 * Whether the current request carries a valid token for this party.
		 *
		 * @param string $party_id Party ID.
		 * @return bool
		 */
		public static function request_has_valid_token( $party_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public magic link; the HMAC token is the credential.
			if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
			$token = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_ARG ] ) );

			return self::verify( (string) $party_id, $token );
		}
	}
}
