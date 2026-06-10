<?php
/**
 * Dynamic block registration (PHP render callbacks + block editor script).
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

		const SCRIPT_HANDLE = 'wgrsvp-blocks-editor';

		const BLOCK_CATEGORY = 'wgrsvp';

		/**
		 * Register block category, editor script, and block hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'block_categories_all', array( __CLASS__, 'register_block_category' ), 10, 2 );
			add_action( 'init', array( __CLASS__, 'register_editor_script' ), 10 );
			add_action( 'init', array( __CLASS__, 'register_all' ), 11 );
		}

		/**
		 * Inserter category so blocks are not buried in generic Widgets.
		 *
		 * @param array<int,array<string,mixed>> $categories     Registered categories.
		 * @param \WP_Block_Editor_Context       $editor_context Editor context.
		 * @return array<int,array<string,mixed>>
		 */
		public static function register_block_category( $categories, $editor_context ) {
			unset( $editor_context );

			$slug = self::BLOCK_CATEGORY;
			foreach ( $categories as $cat ) {
				if ( is_array( $cat ) && isset( $cat['slug'] ) && $slug === $cat['slug'] ) {
					return $categories;
				}
			}

			return array_merge(
				array(
					array(
						'slug'  => $slug,
						'title' => __( 'Wedding Party RSVP', 'wedding-party-rsvp' ),
						'icon'  => 'groups',
					),
				),
				$categories
			);
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
			self::register_from_metadata( 'thankyou-checklist' );
		}

		/**
		 * Shared editor script (required for inserter discovery in the block editor).
		 *
		 * @return void
		 */
		public static function register_editor_script() {
			$path = WGRSVP_PLUGIN_DIR . 'assets/js/wgrsvp-blocks-editor.js';
			if ( ! is_readable( $path ) ) {
				return;
			}

			$ver = (string) filemtime( $path );

			wp_register_script(
				self::SCRIPT_HANDLE,
				plugins_url( 'assets/js/wgrsvp-blocks-editor.js', WGRSVP_PLUGIN_FILE ),
				array(
					'wp-blocks',
					'wp-element',
					'wp-block-editor',
					'wp-server-side-render',
					'wp-i18n',
				),
				$ver,
				true
			);

			if ( function_exists( 'wgrsvp_set_script_translations' ) ) {
				wgrsvp_set_script_translations( self::SCRIPT_HANDLE );
			}
		}

		/**
		 * Register a single block from its block.json metadata.
		 *
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

			$args = array();
			if ( wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
				$args['editor_script'] = self::SCRIPT_HANDLE;
			}

			register_block_type_from_metadata( $block_dir, $args );
		}
	}
}
