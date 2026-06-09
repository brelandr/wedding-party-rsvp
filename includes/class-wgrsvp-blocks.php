<?php
/**
 * Dynamic block registration (PHP render callbacks; no editor build step).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Blocks' ) ) {
	/**
	 * Central registration for free plugin blocks.
	 */
	class WGRSVP_Blocks {

		/**
		 * @return void
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_all' ), 11 );
		}

		/**
		 * Register RSVP form, guest hub, and thank-you checklist blocks.
		 *
		 * @return void
		 */
		public static function register_all() {
			if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
				return;
			}
			self::register_from_metadata( 'rsvp' );
			self::register_from_metadata( 'guest-hub' );
			if ( class_exists( 'WGRSVP_ThankYou_Tracker', false ) ) {
				WGRSVP_ThankYou_Tracker::register_block();
			} else {
				self::register_from_metadata( 'thankyou-checklist' );
			}
		}

		/**
		 * @param string $slug Block directory slug under blocks/.
		 * @return void
		 */
		private static function register_from_metadata( $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				return;
			}
			$block_dir = WGRSVP_PLUGIN_DIR . 'blocks/' . $slug;
			if ( ! is_readable( $block_dir . '/block.json' ) ) {
				return;
			}
			register_block_type_from_metadata( $block_dir );
		}
	}
}
