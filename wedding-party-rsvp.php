<?php
/*
Plugin Name: Wedding Party RSVP – Guest List, Invitation & Event Manager
Description: Simple and secure RSVP system. Manage guest lists and adult meal choices.
Version: 7.3.11
Author: Land Tech Web Designs, Corp
Author URI: https://landtechwebdesigns.com
Plugin URI: https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/
Requires at least: 6.2
Tested up to: 6.9
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

/**
 * Guest list, public RSVP form shortcode, admin tools, CSV, privacy, and coordinator role.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wgrsvp_is_pro_plugin_active' ) ) {
	/**
	 * Whether Wedding Party RSVP Pro is active (hide free-plugin upgrade teasers when co-installed).
	 *
	 * @return bool
	 */
	function wgrsvp_is_pro_plugin_active() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$cached = is_plugin_active( 'wedding-party-rsvp-pro/wedding-party-rsvp-pro.php' );
		return (bool) apply_filters( 'wgrsvp_is_pro_plugin_active', $cached );
	}
}

if ( ! function_exists( 'wgrsvp_trusted_showcase_license_host' ) ) {
	/**
	 * Demo host treated as licensed when Pro helpers are not loaded (mirrors Pro trusted list).
	 *
	 * @return bool
	 */
	function wgrsvp_trusted_showcase_license_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = wp_parse_url( site_url(), PHP_URL_HOST );
		}
		$host = strtolower( (string) $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return 'wedding-rsvp.landtechsvc.com' === $host;
	}
}

if ( ! function_exists( 'wgrsvp_is_pro_license_effectively_valid' ) ) {
	/**
	 * Whether Pro is considered licensed for free-plugin UI (includes trusted showcase host).
	 *
	 * @return bool
	 */
	function wgrsvp_is_pro_license_effectively_valid() {
		if ( function_exists( 'wpr_pro_effective_license_is_valid' ) ) {
			return wpr_pro_effective_license_is_valid();
		}
		if ( wgrsvp_trusted_showcase_license_host() ) {
			return true;
		}

		return 'valid' === get_option( 'wpr_pro_license_status', '' );
	}
}

if ( ! function_exists( 'wgrsvp_mask_license_key_for_display' ) ) {
	/**
	 * Mask a license key for settings UI (activated / on file).
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	function wgrsvp_mask_license_key_for_display( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		if ( strlen( $key ) < 8 ) {
			return '••••••••';
		}

		return substr( $key, 0, 4 ) . '…' . substr( $key, -4 );
	}
}

if ( ! class_exists( 'WGRSVP_Wedding_RSVP' ) ) :

	/**
	 * Main plugin controller: activates schema, registers hooks, renders admin and front RSVP UI.
	 *
	 * @package Wedding_Party_RSVP
	 */
	class WGRSVP_Wedding_RSVP {

		/**
		 * Guest rows table name including `$wpdb->prefix` (assigned in constructor).
		 *
		 * @var string
		 */
		private $table_name;

		/**
		 * Option name: adult entrée choices (array of strings).
		 *
		 * @var string
		 */
		private $opt_menu_adult = 'wgrsvp_menu_options';

		/**
		 * Option name: welcome title, RSVP URL, deadline, redirect, etc.
		 *
		 * @var string
		 */
		private $opt_settings = 'wgrsvp_general_settings';

		/**
		 * Option name: legacy free-plugin license / support key field.
		 *
		 * @var string
		 */
		private $opt_license = 'wgrsvp_license_key';

		/**
		 * Transient key for cached aggregated admin RSVP stats.
		 */
		private const TRANSIENT_AGGREGATED_STATS = 'wedding-party-rsvp_aggregated_stats';

		/**
		 * Option incremented when guest data changes so object-cache keys for read queries miss.
		 */
		private const OPTION_QUERY_CACHE_GEN = 'wgrsvp_query_cache_generation';

		/**
		 * Registers hooks, loads dependency classes, and boots the setup wizard and coordinator role.
		 *
		 * @return void
		 */
		public function __construct() {
			global $wpdb;
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-coordinator-role.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-setup-wizard.php';

			$this->table_name = $wpdb->prefix . 'wedding_rsvps';

			register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
			register_deactivation_hook( __FILE__, array( 'WGRSVP_Coordinator_Role', 'remove_on_deactivation' ) );

			// Init hook for Form Processing (Redirects)
			add_action( 'init', array( $this, 'process_frontend_submissions' ) );

			add_action( 'wp_ajax_wgrsvp_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
			add_action( 'wp_ajax_nopriv_wgrsvp_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );

			add_action( 'wp_loaded', array( $this, 'maybe_redirect_legacy_wedding_rsvp_admin_slug' ), 0 );
			add_action( 'admin_menu', array( $this, 'create_admin_menu' ) );
			add_action( 'admin_menu', array( $this, 'maybe_remove_redundant_comm_submenus' ), 999 );
			add_shortcode( 'wedding_rsvp_form', array( $this, 'render_frontend_form' ) );
			add_action( 'admin_init', array( $this, 'handle_csv_export' ) );

			// Load CSS
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_pro_teaser_assets' ), 20, 1 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );

			// Growth / onboarding (admin).
			add_action( 'admin_init', array( $this, 'maybe_handle_growth_dismiss' ), 1 );
			add_action( 'admin_notices', array( $this, 'render_growth_admin_notices' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'filter_plugin_action_links' ) );
			add_filter( 'plugin_row_meta', array( $this, 'filter_plugin_row_meta' ), 10, 2 );
			add_action( 'wp_dashboard_setup', array( $this, 'maybe_register_dashboard_widget' ) );
			add_action( 'init', array( $this, 'register_block_patterns' ), 9 );
			add_action( 'admin_init', array( $this, 'load_privacy_exporters' ), 5 );

			add_action( 'wgrsvp_after_guest_privacy_erase', array( $this, 'bust_stats_cache_after_privacy_erase' ) );

			// Expose instance for Wedding Party RSVP Pro (merged admin menu when both active).
			$GLOBALS['wgrsvp_wedding_rsvp_instance'] = $this;

			$wizard = new WGRSVP_Setup_Wizard( $this );
			$wizard->init();

			WGRSVP_Coordinator_Role::init_hooks();
			add_action( 'plugins_loaded', array( 'WGRSVP_Coordinator_Role', 'maybe_upgrade_role' ), 5 );
		}

		/**
		 * Option name for general RSVP settings (Welcome title, RSVP page URL, etc.).
		 *
		 * @return string
		 */
		public function get_general_settings_option_name() {
			return $this->opt_settings;
		}

		/**
		 * Invalidate dashboard RSVP stats cache (guest counts, menu totals).
		 *
		 * @return void
		 */
		public function clear_dashboard_stats_cache() {
			$this->clear_stats_cache();
		}

		/**
		 * Whether the Interactivity API script module can be loaded (WordPress 6.5+).
		 *
		 * @return bool
		 */
		private function interactivity_module_available() {
			return function_exists( 'wp_enqueue_script_module' )
				&& function_exists( 'wp_interactivity_state' );
		}

		/**
		 * Enqueue frontend Interactivity module for the RSVP form (no-op on older WordPress).
		 *
		 * @return bool True if the module was enqueued.
		 */
		private function enqueue_rsvp_interactivity_module() {
			if ( ! $this->interactivity_module_available() ) {
				return false;
			}

			wp_enqueue_script_module(
				'wgrsvp-rsvp-interactivity',
				plugins_url( 'assets/js/rsvp-interactivity.js', __FILE__ ),
				array( '@wordpress/interactivity' ),
				'7.3.11'
			);

			return true;
		}

		/**
		 * Initial data-wp-context payload for the interactive RSVP region.
		 *
		 * @return array<string,mixed>
		 */
		private function get_rsvp_interactivity_context() {
			return array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'submitting'   => __( 'Sending…', 'wedding-party-rsvp' ),
					'success'      => __( 'Thank you! Your RSVP has been updated.', 'wedding-party-rsvp' ),
					'error'        => __( 'Could not save your RSVP. Please try again.', 'wedding-party-rsvp' ),
					'networkError' => __( 'Network error. Please try again.', 'wedding-party-rsvp' ),
				),
			);
		}

		/**
		 * Persist RSVP guest rows from the current request (POST).
		 *
		 * @param string $party_id Party ID.
		 * @return void
		 */
		private function save_rsvp_guest_updates_for_party( $party_id ) {
			global $wpdb;

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only called after nonce verified: ajax_submit_rsvp(), process_frontend_submissions().
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guest[] unslashed here; each column sanitized in-loop (textarea, email, checkboxes differ).
			if ( ! isset( $_POST['guest'] ) || ! is_array( $_POST['guest'] ) ) {
				return;
			}

			$guest_post = wp_unslash( $_POST['guest'] );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );

			$allowed_rsvp = array( 'Pending', 'Accepted', 'Declined' );
			foreach ( $guest_post as $id_raw => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}
				$gid = absint( $id_raw );
				if ( $gid < 1 ) {
					continue;
				}
				$name      = sanitize_text_field( wp_unslash( (string) ( $data['name_edit'] ?? $data['name_hidden'] ?? '' ) ) );
				$allergies = '';
				if ( isset( $data['allergies'] ) && is_array( $data['allergies'] ) ) {
					$allergy_bits = array_map(
						static function ( $v ) {
							return sanitize_text_field( wp_unslash( (string) $v ) );
						},
						$data['allergies']
					);
					$allergy_bits = array_filter( $allergy_bits );
					$allergies    = implode( ', ', $allergy_bits );
				}

				$rsvp_raw = sanitize_text_field( wp_unslash( $data['rsvp'] ?? 'Pending' ) );
				$rsvp     = in_array( $rsvp_raw, $allowed_rsvp, true ) ? $rsvp_raw : 'Pending';

				/**
				 * MANUAL REVIEW REQUIRED — `guest_message` and `address` use `sanitize_textarea_field()` (no HTML).
				 * If the product should allow limited HTML or links in “Message to Couple”, use `wp_kses_post()`
				 * (or a custom allowed-tag set) and match output (e.g. `wp_kses_post` / `wpautop`) instead of
				 * `esc_html` in admin lists and the RSVP form. Pasted map/address HTML is stripped; confirm plain
				 * multiline text is sufficient.
				 */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP row update; object cache bust above + clear_stats_cache() after batch.
				$wpdb->update(
					$this->table_name,
					array(
						'guest_name'           => $name,
						'rsvp_status'          => $rsvp,
						'menu_choice'          => sanitize_text_field( wp_unslash( $data['menu'] ?? '' ) ),
						'dietary_restrictions' => sanitize_text_field( wp_unslash( $data['dietary'] ?? '' ) ),
						'allergies'            => $allergies,
						'song_request'         => sanitize_text_field( wp_unslash( $data['song'] ?? '' ) ),
						'guest_message'        => sanitize_textarea_field( wp_unslash( $data['message'] ?? '' ) ),
						'email'                => sanitize_email( wp_unslash( $data['email'] ?? '' ) ),
						'phone'                => sanitize_text_field( wp_unslash( $data['phone'] ?? '' ) ),
						'address'              => sanitize_textarea_field( wp_unslash( $data['address'] ?? '' ) ),
					),
					array(
						'id'       => $gid,
						'party_id' => $party_id,
					)
				);
			}

			$this->clear_stats_cache();

			do_action( 'wgrsvp_after_rsvp_save', $party_id );
		}

		/**
		 * JSON handler for the Interactivity/API frontend RSVP submit (`action=wgrsvp_submit_rsvp`).
		 *
		 * Expects `check_ajax_referer( 'wgrsvp_front_rsvp_submit', '_wpnonce' )` — verified first.
		 *
		 * @return void
		 */
		public function ajax_submit_rsvp() {
			if ( ! check_ajax_referer( 'wgrsvp_front_rsvp_submit', '_wpnonce', false ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Security check failed.', 'wedding-party-rsvp' ) ),
					403
				);
			}

			$honey = isset( $_POST['wpr_honey'] ) ? sanitize_text_field( wp_unslash( $_POST['wpr_honey'] ) ) : '';
			if ( '' !== $honey ) {
				wp_send_json_success(
					array(
						'message' => __( 'Thank you! Your RSVP has been updated.', 'wedding-party-rsvp' ),
					)
				);
			}

			$party_id = isset( $_POST['party_id'] ) ? sanitize_text_field( wp_unslash( $_POST['party_id'] ) ) : '';
			if ( '' === $party_id ) {
				wp_send_json_error(
					array( 'message' => __( 'Missing party information.', 'wedding-party-rsvp' ) ),
					400
				);
			}

			$this->save_rsvp_guest_updates_for_party( $party_id );

			$settings = get_option( $this->opt_settings, array() );
			if ( ! empty( $settings['redirect_url'] ) ) {
				wp_send_json_success(
					array(
						'message'  => '',
						'redirect' => esc_url_raw( $settings['redirect_url'] ),
					)
				);
			}

			wp_send_json_success(
				array(
					'message' => __( 'Thank you! Your RSVP has been updated.', 'wedding-party-rsvp' ),
				)
			);
		}

		public function activate_plugin() {
			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$sql = "CREATE TABLE $this->table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				party_id varchar(50) NOT NULL,
				guest_name varchar(100) NOT NULL,
				is_child tinyint(1) DEFAULT 0,
				rsvp_status varchar(20) DEFAULT 'Pending',
				menu_choice varchar(100) DEFAULT '',
				child_menu_choice varchar(100) DEFAULT '',
				appetizer_choice varchar(100) DEFAULT '',
				hors_doeuvre_choice varchar(100) DEFAULT '',
				phone varchar(20) DEFAULT '',
				email varchar(100) DEFAULT '',
				address text DEFAULT '',
				dietary_restrictions text DEFAULT '',
				allergies text DEFAULT '',
				song_request text DEFAULT '',
				guest_message text DEFAULT '',
				admin_notes text DEFAULT '',
				table_number varchar(20) DEFAULT '',
				PRIMARY KEY  (id),
				KEY party_id (party_id)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			if ( class_exists( 'WGRSVP_Setup_Wizard' ) ) {
				WGRSVP_Setup_Wizard::flag_activation_redirect();
			}

			if ( class_exists( 'WGRSVP_Coordinator_Role' ) ) {
				WGRSVP_Coordinator_Role::sync_on_activation();
			}
		}

		// --- HELPER: Cache Clearing ---
		private function clear_stats_cache() {
			delete_transient( self::TRANSIENT_AGGREGATED_STATS );
			wp_cache_delete( 'wgrsvp_wizard_guest_count', 'wedding_rsvp' );
			// Bump so CSV export object-cache keys (see handle_csv_export) miss after guest/table changes.
			update_option(
				'wgrsvp_csv_cache_generation',
				(int) get_option( 'wgrsvp_csv_cache_generation', 1 ) + 1,
				false
			);
			$this->wgrsvp_bust_query_object_cache();
		}

		/**
		 * Invalidate object-cached SELECT results for the guest table (see wgrsvp_query_cache_* helpers).
		 *
		 * @return void
		 */
		private function wgrsvp_bust_query_object_cache() {
			update_option(
				self::OPTION_QUERY_CACHE_GEN,
				(int) get_option( self::OPTION_QUERY_CACHE_GEN, 1 ) + 1,
				false
			);
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				wp_cache_flush_group( 'wgrsvp_queries' );
			}
		}

		/**
		 * Run get_results with object cache. Calls $wpdb->prepare() inline (no intermediate SQL variable) for static analysis.
		 *
		 * @param string               $query SQL with placeholders.
		 * @param array<int,mixed>     $prepare_args Values for $wpdb->prepare() (spread in order).
		 * @param string               $output_mode ARRAY_A or OBJECT (wpdb constant).
		 * @return array<int,object>|array<int,array<string,mixed>>
		 */
		private function wgrsvp_query_cache_get_results( $query, array $prepare_args, $output_mode = OBJECT ) {
			global $wpdb;
			$gen       = (int) get_option( self::OPTION_QUERY_CACHE_GEN, 1 );
			$cache_key = 'wgrsvp_' . md5( (string) $gen . '|' . (string) $query . wp_json_encode( $prepare_args, JSON_UNESCAPED_UNICODE ) );
			$cached    = wp_cache_get( $cache_key, 'wgrsvp_queries' );
			if ( false !== $cached ) {
				return $cached;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query + $prepare_args bound only via nested $wpdb->prepare(); cache key uses same inputs.
			$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$prepare_args ), $output_mode );
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
			wp_cache_set( $cache_key, $rows, 'wgrsvp_queries', HOUR_IN_SECONDS );
			return $rows;
		}

		/**
		 * Aggregate guest counts and menu breakdown for admin dashboard (expensive queries; cached 24 hours).
		 *
		 * @return array<string,mixed> Keys: total_accepted, total_declined, total_pending, total_guests (int), menu_stats_adult (array).
		 */
		private function get_aggregated_rsvp_stats() {
			$cached = get_transient( self::TRANSIENT_AGGREGATED_STATS );
			if ( is_array( $cached )
				&& isset( $cached['total_accepted'], $cached['total_declined'], $cached['total_pending'], $cached['total_guests'], $cached['menu_stats_adult'] ) ) {
				return $cached;
			}

			global $wpdb;
			$table = $this->table_name;
			$gen   = (int) get_option( self::OPTION_QUERY_CACHE_GEN, 1 );

			$key_acc = 'wgrsvp_' . md5( (string) $gen . '|stat_accepted|' . $table );
			$cached  = wp_cache_get( $key_acc, 'wgrsvp_queries' );
			if ( false !== $cached ) {
				$total_accepted = (int) $cached;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Nested prepare(); values are %i table + %s status.
				$total_accepted = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s', $table, 'Accepted' ) );
				wp_cache_set( $key_acc, $total_accepted, 'wgrsvp_queries', HOUR_IN_SECONDS );
			}

			$key_dec = 'wgrsvp_' . md5( (string) $gen . '|stat_declined|' . $table );
			$cached  = wp_cache_get( $key_dec, 'wgrsvp_queries' );
			if ( false !== $cached ) {
				$total_declined = (int) $cached;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$total_declined = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s', $table, 'Declined' ) );
				wp_cache_set( $key_dec, $total_declined, 'wgrsvp_queries', HOUR_IN_SECONDS );
			}

			$key_pen = 'wgrsvp_' . md5( (string) $gen . '|stat_pending|' . $table );
			$cached  = wp_cache_get( $key_pen, 'wgrsvp_queries' );
			if ( false !== $cached ) {
				$total_pending = (int) $cached;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$total_pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s', $table, 'Pending' ) );
				wp_cache_set( $key_pen, $total_pending, 'wgrsvp_queries', HOUR_IN_SECONDS );
			}

			$key_all = 'wgrsvp_' . md5( (string) $gen . '|stat_total_guests|' . $table );
			$cached  = wp_cache_get( $key_all, 'wgrsvp_queries' );
			if ( false !== $cached ) {
				$total_guests = (int) $cached;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$total_guests = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
				wp_cache_set( $key_all, $total_guests, 'wgrsvp_queries', HOUR_IN_SECONDS );
			}

			$key_menu = 'wgrsvp_' . md5( (string) $gen . '|stat_menu_adult|' . $table );
			$cached   = wp_cache_get( $key_menu, 'wgrsvp_queries' );
			if ( false !== $cached ) {
				$menu_stats_adult = is_array( $cached ) ? $cached : array();
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$menu_stats_adult = $wpdb->get_results( $wpdb->prepare( 'SELECT menu_choice, COUNT(*) as count FROM %i WHERE rsvp_status = %s AND menu_choice != %s GROUP BY menu_choice', $table, 'Accepted', '' ) );
				if ( ! is_array( $menu_stats_adult ) ) {
					$menu_stats_adult = array();
				}
				wp_cache_set( $key_menu, $menu_stats_adult, 'wgrsvp_queries', HOUR_IN_SECONDS );
			}

			$out = array(
				'total_accepted'   => $total_accepted,
				'total_declined'   => $total_declined,
				'total_pending'    => $total_pending,
				'total_guests'     => $total_guests,
				'menu_stats_adult' => $menu_stats_adult,
			);

			set_transient( self::TRANSIENT_AGGREGATED_STATS, $out, DAY_IN_SECONDS );

			return $out;
		}

		/**
		 * Flush dashboard stats cache after a privacy erase request (hook callback).
		 *
		 * @return void
		 */
		public function bust_stats_cache_after_privacy_erase() {
			$this->clear_stats_cache();
		}

		/**
		 * Legacy Pro-only menu slug `wedding-rsvp` is not registered when this plugin is active (canonical hub is
		 * `wedding-rsvp-main`). Bookmarks to `admin.php?page=wedding-rsvp` would otherwise fail in menu.php before
		 * `admin_init`. Redirect early on `wp_loaded` (before admin menu access checks).
		 *
		 * @return void
		 */
		public function maybe_redirect_legacy_wedding_rsvp_admin_slug() {
			if ( ! is_admin() ) {
				return;
			}
			if ( wp_doing_ajax() ) {
				return;
			}
			if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) {
				return;
			}
			if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
				return;
			}
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET redirect; full query sanitized before redirect.
			if ( ! empty( $_GET['page'] )
				&& 'wedding-rsvp' === sanitize_key( wp_unslash( $_GET['page'] ) )
				&& ( current_user_can( 'manage_options' ) || current_user_can( WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD ) )
			) {
				$new_query = array();
				if ( ! empty( $_GET ) && is_array( $_GET ) ) {
					$new_query = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
				}
				$new_query['page'] = 'wedding-rsvp-main';
				wp_safe_redirect( add_query_arg( $new_query, admin_url( 'admin.php' ) ) );
				exit;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		public function create_admin_menu() {
			add_menu_page( 'Wedding RSVP', 'Wedding RSVP', WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD, 'wedding-rsvp-main', array( $this, 'admin_page_guests' ), 'dashicons-groups', 6 );
			add_submenu_page( 'wedding-rsvp-main', 'Menu Options', 'Menu Options', 'manage_options', 'wedding-rsvp-menu', array( $this, 'admin_page_menu' ) );
			add_submenu_page( 'wedding-rsvp-main', 'Settings', 'Settings', 'manage_options', 'wedding-rsvp-settings', array( $this, 'admin_page_settings' ) );
			add_submenu_page( 'wedding-rsvp-main', 'Email Invites', 'Email Invites', 'manage_options', 'wedding-rsvp-email', array( $this, 'admin_page_email' ) );
			add_submenu_page( 'wedding-rsvp-main', 'SMS Invites', 'SMS Invites', 'manage_options', 'wedding-rsvp-sms', array( $this, 'admin_page_sms' ) );
		}

		private function get_sort_link( $col, $current_by, $current_order ) {
			$new_order = ( $current_by === $col && $current_order === 'ASC' ) ? 'DESC' : 'ASC';
			$args      = array(
				'page'    => 'wedding-rsvp-main',
				'orderby' => $col,
				'order'   => $new_order,
			);
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin list sort link; every $_GET read unslashed + sanitized.
			$s_val = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			if ( '' !== $s_val ) {
				$args['s'] = $s_val;
			}
			$fs_val = isset( $_GET['filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) ) : '';
			if ( '' !== $fs_val ) {
				$args['filter_status'] = $fs_val;
			}
			$fm_val = isset( $_GET['filter_menu'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_menu'] ) ) : '';
			if ( '' !== $fm_val ) {
				$args['filter_menu'] = $fm_val;
			}
			$g_val = isset( $_GET['wgrsvp_group'] ) ? sanitize_text_field( wp_unslash( $_GET['wgrsvp_group'] ) ) : '';
			if ( '1' === $g_val ) {
				$args['wgrsvp_group'] = '1';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			return add_query_arg( $args, admin_url( 'admin.php' ) );
		}

		/**
		 * Marketing URL for Pro (UTM tagged for funnel analytics).
		 *
		 * @return string
		 */
		private function get_pro_marketing_url() {
			return apply_filters(
				'wgrsvp_pro_marketing_url',
				'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/?utm_source=wp-plugin-free&utm_medium=admin&utm_campaign=get-pro'
			);
		}

		/**
		 * When Wedding Party RSVP Pro is active with a valid license, the free Email/SMS placeholder pages should not show.
		 *
		 * @return bool
		 */
		private function should_redirect_to_pro_communications_screen() {
			$candidate = (
				function_exists( 'wpr_pro_is_free_plugin_active' )
				&& wpr_pro_is_free_plugin_active()
				&& class_exists( 'WPR_Pro_Admin' )
				&& function_exists( 'wgrsvp_is_pro_license_effectively_valid' )
				&& wgrsvp_is_pro_license_effectively_valid()
			);

			return (bool) apply_filters( 'wgrsvp_redirect_free_email_sms_to_pro_comm', $candidate );
		}

		/**
		 * Drop free-only Email/SMS submenu entries when Pro’s licensed UI should be used (avoids duplicate menu items).
		 *
		 * @return void
		 */
		public function maybe_remove_redundant_comm_submenus() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( ! $this->should_redirect_to_pro_communications_screen() ) {
				return;
			}
			remove_submenu_page( 'wedding-rsvp-main', 'wedding-rsvp-email' );
			remove_submenu_page( 'wedding-rsvp-main', 'wedding-rsvp-sms' );
		}

		/**
		 * Enqueue Settings screen assets for inactive Pro preview controls + upgrade modal.
		 *
		 * @param string $hook_suffix Current admin screen id.
		 * @return void
		 */
		public function enqueue_settings_pro_teaser_assets( $hook_suffix ) {
			if ( 'wedding-rsvp-main_page_wedding-rsvp-settings' !== $hook_suffix ) {
				return;
			}

			$layout_css = '
				.wgrsvp-settings-layout{display:flex;flex-wrap:wrap;align-items:flex-start;gap:20px;margin-top:10px;}
				.wgrsvp-settings-layout__main{flex:1 1 480px;min-width:0;}
				.wgrsvp-settings-layout__aside{flex:0 1 300px;min-width:260px;max-width:100%;}
				.wgrsvp-landtech-cross-promo.postbox{margin-bottom:0;}
				.wgrsvp-landtech-cross-promo .postbox-header{border-bottom:1px solid #c3c4c7;}
				.wgrsvp-landtech-cross-promo .inside{padding:12px;margin:0;}
				.wgrsvp-landtech-cross-promo .description{font-size:13px;line-height:1.5;color:#646970;margin:0;}
				.wgrsvp-landtech-cross-promo__list{margin:12px 0 0;padding-left:18px;list-style:disc;}
				.wgrsvp-landtech-cross-promo__list li{margin-bottom:10px;line-height:1.45;}
				.wgrsvp-landtech-cross-promo__link{display:inline;font-weight:600;}
			';

			if ( wgrsvp_is_pro_plugin_active() ) {
				wp_register_style( 'wgrsvp-settings-layout-base', false, array(), '7.3.11' );
				wp_enqueue_style( 'wgrsvp-settings-layout-base' );
				wp_add_inline_style( 'wgrsvp-settings-layout-base', wp_strip_all_tags( $layout_css ) );

				return;
			}

			$base  = plugin_dir_url( __FILE__ );
			$path  = plugin_dir_path( __FILE__ );
			$css   = $path . 'assets/css/wgrsvp-settings-pro-teaser.css';
			$js    = $path . 'assets/js/wgrsvp-settings-pro-teaser.js';
			$v_css = is_readable( $css ) ? (string) filemtime( $css ) : '1';
			$v_js  = is_readable( $js ) ? (string) filemtime( $js ) : '1';

			wp_enqueue_style(
				'wgrsvp-settings-pro-teaser',
				$base . 'assets/css/wgrsvp-settings-pro-teaser.css',
				array(),
				$v_css
			);
			wp_enqueue_script(
				'wgrsvp-settings-pro-teaser',
				$base . 'assets/js/wgrsvp-settings-pro-teaser.js',
				array(),
				$v_js,
				true
			);

			wp_localize_script(
				'wgrsvp-settings-pro-teaser',
				'wgrsvpProTeaser',
				array(
					'upgradeUrl' => esc_url_raw( $this->get_pro_marketing_url() ),
					'i18n'       => array(
						'sms'     => array(
							'title' => __( 'SMS reminders', 'wedding-party-rsvp' ),
							'body'  => __( 'Reduce no-shows with automated SMS nudges before your RSVP deadline. Pro connects Twilio so guests get timely reminders—without manual follow-up.', 'wedding-party-rsvp' ),
							'cta'   => __( 'Upgrade to Wedding Party RSVP Pro', 'wedding-party-rsvp' ),
						),
						'seating' => array(
							'title' => __( 'Advanced seating charts', 'wedding-party-rsvp' ),
							'body'  => __( 'Assign tables, track placement notes, and export seating-ready lists from one guest source of truth—instead of juggling spreadsheets the week of your event.', 'wedding-party-rsvp' ),
							'cta'   => __( 'Upgrade to Wedding Party RSVP Pro', 'wedding-party-rsvp' ),
						),
					),
				)
			);

			wp_add_inline_style( 'wgrsvp-settings-pro-teaser', wp_strip_all_tags( $layout_css ) );
		}

		/**
		 * Settings page: inactive Pro preview (Messaging / Seating tabs).
		 *
		 * @return void
		 */
		private function render_settings_pro_teaser_section() {
			if ( wgrsvp_is_pro_plugin_active() ) {
				return;
			}
			?>
			<div class="wgrsvp-pro-teaser-wrap" style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px;">
				<h3 style="margin-top:0;">
					<?php esc_html_e( 'Premium tools preview', 'wedding-party-rsvp' ); ?>
					<span class="wgrsvp-pro-badge" aria-hidden="true"><?php esc_html_e( 'Pro', 'wedding-party-rsvp' ); ?></span>
				</h3>
				<p class="wgrsvp-pro-teaser-intro description">
					<?php esc_html_e( 'Preview features included with Wedding Party RSVP Pro. Controls stay off in the free plugin—click a preview control to learn more.', 'wedding-party-rsvp' ); ?>
				</p>

				<div class="nav-tab-wrapper wgrsvp-pro-teaser-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Premium feature categories', 'wedding-party-rsvp' ); ?>">
					<button type="button" class="nav-tab nav-tab-active wgrsvp-pro-teaser-tab" role="tab" aria-selected="true" data-wgrsvp-target="wgrsvp-pro-teaser-panel-messaging">
						<?php esc_html_e( 'Messaging', 'wedding-party-rsvp' ); ?>
					</button>
					<button type="button" class="nav-tab wgrsvp-pro-teaser-tab" role="tab" aria-selected="false" data-wgrsvp-target="wgrsvp-pro-teaser-panel-seating">
						<?php esc_html_e( 'Seating & layout', 'wedding-party-rsvp' ); ?>
					</button>
				</div>

				<div id="wgrsvp-pro-teaser-panel-messaging" class="wgrsvp-pro-teaser-panel is-active" role="tabpanel">
					<button type="button" class="wgrsvp-pro-teaser-hit" data-wgrsvp-pro-feature="sms">
						<span class="wgrsvp-pro-teaser-hit__main">
							<span class="wgrsvp-pro-teaser-hit__title">
								<?php esc_html_e( 'SMS reminders', 'wedding-party-rsvp' ); ?>
								<span class="wgrsvp-pro-badge" aria-hidden="true"><?php esc_html_e( 'Pro', 'wedding-party-rsvp' ); ?></span>
							</span>
							<span class="wgrsvp-pro-teaser-hit__desc">
								<?php esc_html_e( 'Automated text reminders before your RSVP deadline—requires Pro and Twilio.', 'wedding-party-rsvp' ); ?>
							</span>
						</span>
						<span class="wgrsvp-pro-teaser-switch" aria-hidden="true"></span>
					</button>
				</div>

				<div id="wgrsvp-pro-teaser-panel-seating" class="wgrsvp-pro-teaser-panel" role="tabpanel" hidden>
					<button type="button" class="wgrsvp-pro-teaser-hit" data-wgrsvp-pro-feature="seating">
						<span class="wgrsvp-pro-teaser-hit__main">
							<span class="wgrsvp-pro-teaser-hit__title">
								<?php esc_html_e( 'Advanced seating charts', 'wedding-party-rsvp' ); ?>
								<span class="wgrsvp-pro-badge" aria-hidden="true"><?php esc_html_e( 'Pro', 'wedding-party-rsvp' ); ?></span>
							</span>
							<span class="wgrsvp-pro-teaser-hit__desc">
								<?php esc_html_e( 'Table assignments, planner notes, and exports aligned with your guest list—available in Pro.', 'wedding-party-rsvp' ); ?>
							</span>
						</span>
						<span class="wgrsvp-pro-teaser-switch" aria-hidden="true"></span>
					</button>
				</div>

				<?php do_action( 'wgrsvp_after_settings_pro_teaser_section', $this ); ?>
			</div>
			<?php
		}

		/**
		 * Settings page: lightweight upgrade modal (filled via localized strings in JS).
		 *
		 * @return void
		 */
		private function render_settings_pro_teaser_modal() {
			if ( wgrsvp_is_pro_plugin_active() ) {
				return;
			}
			?>
			<div id="wgrsvp-pro-teaser-modal" class="wgrsvp-pro-teaser-modal" hidden aria-hidden="true">
				<div class="wgrsvp-pro-teaser-modal__backdrop" tabindex="-1"></div>
				<div class="wgrsvp-pro-teaser-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wgrsvp-pro-teaser-modal-heading">
					<button type="button" class="wgrsvp-pro-teaser-modal__close" aria-label="<?php esc_attr_e( 'Close dialog', 'wedding-party-rsvp' ); ?>">&times;</button>
					<h2 id="wgrsvp-pro-teaser-modal-heading" data-wgrsvp-modal-title></h2>
					<p data-wgrsvp-modal-body></p>
					<p style="margin-bottom:0;">
						<a href="#" class="button button-primary" data-wgrsvp-modal-cta target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade to Wedding Party RSVP Pro', 'wedding-party-rsvp' ); ?></a>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Sidebar widget on Settings: related LandTech products (neutral wp-admin styling).
		 *
		 * @return void
		 */
		private function render_settings_landtech_cross_promo() {
			if ( wgrsvp_is_pro_plugin_active() ) {
				return;
			}

			$items = apply_filters(
				'wgrsvp_landtech_cross_promo_items',
				array(
					array(
						'label'       => __( 'AdFusion', 'wedding-party-rsvp' ),
						'url'         => 'https://landtechwebdesigns.com/?utm_source=wedding-party-rsvp-free&utm_medium=admin-settings&utm_campaign=cross-promo-adfusion',
						'description' => __( 'Advertising and sponsor placement workflows for WordPress publishers.', 'wedding-party-rsvp' ),
					),
					array(
						'label'       => __( 'Member management tools', 'wedding-party-rsvp' ),
						'url'         => 'https://landtechwebdesigns.com/?utm_source=wedding-party-rsvp-free&utm_medium=admin-settings&utm_campaign=cross-promo-members',
						'description' => __( 'Directories, rosters, and membership flows for associations and clubs.', 'wedding-party-rsvp' ),
					),
				)
			);

			if ( ! is_array( $items ) ) {
				$items = array();
			}
			?>
			<aside class="wgrsvp-settings-layout__aside" aria-label="<?php esc_attr_e( 'More from LandTech Web Designs', 'wedding-party-rsvp' ); ?>">
				<div class="postbox wgrsvp-landtech-cross-promo">
					<div class="postbox-header">
						<h2 class="hndle"><?php esc_html_e( 'More from LandTech Web Designs', 'wedding-party-rsvp' ); ?></h2>
					</div>
					<div class="inside">
						<p class="description"><?php esc_html_e( 'We also build tools for publishers and member-driven organizations—alongside event and RSVP products like this one.', 'wedding-party-rsvp' ); ?></p>
						<?php if ( ! empty( $items ) ) : ?>
							<ul class="wgrsvp-landtech-cross-promo__list">
								<?php
								foreach ( $items as $row ) :
									if ( ! is_array( $row ) ) {
										continue;
									}
									$lab = isset( $row['label'] ) ? (string) $row['label'] : '';
									$url = isset( $row['url'] ) ? esc_url( $row['url'] ) : '';
									$des = isset( $row['description'] ) ? (string) $row['description'] : '';
									if ( '' === $lab || '' === $url ) {
										continue;
									}
									?>
									<li>
										<a class="wgrsvp-landtech-cross-promo__link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $lab ); ?></a>
										<?php if ( '' !== $des ) : ?>
											<span class="description"> — <?php echo esc_html( $des ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p class="description" style="margin-top:12px;margin-bottom:0;">
							<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/?utm_source=wedding-party-rsvp-free&utm_medium=admin-settings&utm_campaign=cross-promo-home' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'landtechwebdesigns.com', 'wedding-party-rsvp' ); ?></a>
						</p>
						<?php do_action( 'wgrsvp_after_landtech_cross_promo_widget', $items ); ?>
					</div>
				</div>
			</aside>
			<?php
		}

		/**
		 * Public RSVP URL for a party (for admin "copy link").
		 *
		 * @param string $party_id Party ID.
		 * @return string
		 */
		public function get_public_party_rsvp_url( $party_id ) {
			$party_id = sanitize_text_field( (string) $party_id );
			$settings = get_option( $this->opt_settings, array() );
			$base     = ! empty( $settings['rsvp_page_url'] ) ? $settings['rsvp_page_url'] : home_url( '/' );
			return add_query_arg( 'party_id', rawurlencode( $party_id ), $base );
		}

		/**
		 * Handles GET requests from “Dismiss” links on growth/admin notices (`wgrsvp_dismiss_notice`).
		 *
		 * Validates `check_admin_referer( 'wgrsvp_dismiss_growth_notice', '_wpnonce' )` before updating options.
		 *
		 * @return void
		 */
		public function maybe_handle_growth_dismiss() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( ! isset( $_GET['wgrsvp_dismiss_notice'] ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked before option updates; dismiss key only parsed after success.
			if ( ! check_admin_referer( 'wgrsvp_dismiss_growth_notice', '_wpnonce', false ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsed after nonce verification above.
			$which = sanitize_key( wp_unslash( $_GET['wgrsvp_dismiss_notice'] ) );
			if ( 'activation' === $which ) {
				update_option( 'wgrsvp_activation_welcome_dismissed', 1, false );
			} elseif ( 'milestone' === $which ) {
				update_option( 'wgrsvp_milestone_notice_dismissed', 1, false );
			}
			wp_safe_redirect( remove_query_arg( array( 'wgrsvp_dismiss_notice', '_wpnonce' ) ) );
			exit;
		}

		/**
		 * Activation checklist + milestone CTA (plugin / dashboard / plugins screen).
		 *
		 * @return void
		 */
		public function render_growth_admin_notices() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
			$rsvp_admin_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( 'wedding-rsvp-seating' === $rsvp_admin_page ) {
				return;
			}

			$screen              = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$on_wedding_admin    = $screen && false !== strpos( (string) $screen->id, 'wedding-rsvp' );
			$global_pages        = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
			$show_activation_ctx = ( 'index.php' === $global_pages || 'plugins.php' === $global_pages || $on_wedding_admin );

			if ( $show_activation_ctx && ! get_option( 'wgrsvp_activation_welcome_dismissed' ) ) {
				$dismiss = wp_nonce_url(
					add_query_arg( 'wgrsvp_dismiss_notice', 'activation' ),
					'wgrsvp_dismiss_growth_notice'
				);
				echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Wedding Party RSVP — quick setup', 'wedding-party-rsvp' ) . '</strong></p><ol>';
				echo '<li>' . esc_html__( 'Create a WordPress page for your RSVP.', 'wedding-party-rsvp' ) . '</li>';
				echo '<li>' . esc_html__( 'Add the shortcode [wedding_rsvp_form] to that page (Shortcode block or classic shortcode).', 'wedding-party-rsvp' ) . '</li>';
				echo '<li>' . esc_html__( 'In Wedding RSVP → Settings, set the RSVP page URL to that page.', 'wedding-party-rsvp' ) . '</li>';
				echo '</ol><p>';
				echo '<a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=page' ) ) . '">' . esc_html__( 'New page', 'wedding-party-rsvp' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=wedding-rsvp-settings' ) ) . '">' . esc_html__( 'Settings', 'wedding-party-rsvp' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( 'https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/brelandr/wedding-party-rsvp/main/blueprint.json' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Try Playground', 'wedding-party-rsvp' ) . '</a>';
				if ( ! wgrsvp_is_pro_plugin_active() ) {
					echo ' <a class="button" href="' . esc_url( $this->get_pro_marketing_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Pro', 'wedding-party-rsvp' ) . '</a>';
				}
				echo '</p>';
				echo '<p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss this message', 'wedding-party-rsvp' ) . '</a></p></div>';
			}

			if ( get_option( 'wgrsvp_milestone_notice_dismissed' ) || ! $on_wedding_admin ) {
				return;
			}

			$milestone_stats = $this->get_aggregated_rsvp_stats();
			$accepted        = (int) $milestone_stats['total_accepted'];
			$total           = (int) $milestone_stats['total_guests'];
			$threshold       = (int) apply_filters( 'wgrsvp_milestone_guest_threshold', 5 );

			if ( $accepted < 1 && $total < $threshold ) {
				return;
			}

			$dismiss_m = wp_nonce_url(
				add_query_arg( 'wgrsvp_dismiss_notice', 'milestone' ),
				'wgrsvp_dismiss_growth_notice'
			);
			echo '<div class="notice notice-success"><p>';
			if ( wgrsvp_is_pro_plugin_active() ) {
				echo esc_html__( 'You are collecting real RSVPs — keep managing guests under Wedding RSVP.', 'wedding-party-rsvp' );
			} else {
				echo esc_html__( 'You are collecting real RSVPs. Pro adds batch email and SMS, child guests, seating notes, and more.', 'wedding-party-rsvp' );
				echo ' <a href="' . esc_url( $this->get_pro_marketing_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn about Pro', 'wedding-party-rsvp' ) . '</a>';
			}
			echo '</p><p><a href="' . esc_url( $dismiss_m ) . '">' . esc_html__( 'Dismiss', 'wedding-party-rsvp' ) . '</a></p></div>';
		}

		/**
		 * Plugins list action link.
		 *
		 * @param array<string,string> $links Links.
		 * @return array<string,string>
		 */
		public function filter_plugin_action_links( $links ) {
			if ( ! is_array( $links ) ) {
				$links = array();
			}
			if ( ! wgrsvp_is_pro_plugin_active() ) {
				$links['wgrsvp_get_pro'] = '<a href="' . esc_url( $this->get_pro_marketing_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Pro', 'wedding-party-rsvp' ) . '</a>';
			}
			return $links;
		}

		/**
		 * Plugins list row meta.
		 *
		 * @param array<string,string> $links Links.
		 * @param string               $file  Plugin basename.
		 * @return array<string,string>
		 */
		public function filter_plugin_row_meta( $links, $file ) {
			if ( plugin_basename( __FILE__ ) !== $file || ! is_array( $links ) ) {
				return $links;
			}
			if ( ! wgrsvp_is_pro_plugin_active() ) {
				$links[] = '<a href="' . esc_url( $this->get_pro_marketing_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Pro', 'wedding-party-rsvp' ) . '</a>';
			}
			return $links;
		}

		/**
		 * Core dashboard widget (disable with filter wgrsvp_register_dashboard_widget).
		 *
		 * @return void
		 */
		public function maybe_register_dashboard_widget() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( ! apply_filters( 'wgrsvp_register_dashboard_widget', true ) ) {
				return;
			}
			wp_add_dashboard_widget(
				'wgrsvp_dashboard_overview',
				esc_html__( 'Wedding RSVP', 'wedding-party-rsvp' ),
				array( $this, 'render_dashboard_widget' )
			);
		}

		/**
		 * Dashboard widget markup.
		 *
		 * @return void
		 */
		public function render_dashboard_widget() {
			$dash_stats = $this->get_aggregated_rsvp_stats();
			$pending    = (int) $dash_stats['total_pending'];
			$yes        = (int) $dash_stats['total_accepted'];
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: number attending, 2: number pending */
					__( 'Attending: %1$d · Pending: %2$d', 'wedding-party-rsvp' ),
					$yes,
					$pending
				)
			) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ) . '">' . esc_html__( 'Open guest list', 'wedding-party-rsvp' ) . '</a></p>';
			if ( ! wgrsvp_is_pro_plugin_active() ) {
				echo '<p><a href="' . esc_url( $this->get_pro_marketing_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade for email & SMS (Pro)', 'wedding-party-rsvp' ) . '</a></p>';
			}
		}

		/**
		 * Block pattern for shortcode discovery.
		 *
		 * @return void
		 */
		public function register_block_patterns() {
			if ( ! function_exists( 'register_block_pattern_category' ) || ! function_exists( 'register_block_pattern' ) ) {
				return;
			}
			register_block_pattern_category(
				'wgrsvp',
				array( 'label' => __( 'Wedding Party RSVP', 'wedding-party-rsvp' ) )
			);
			register_block_pattern(
				'wgrsvp/rsvp-form-shortcode',
				array(
					'title'       => __( 'RSVP form (shortcode)', 'wedding-party-rsvp' ),
					'categories'  => array( 'wgrsvp' ),
					'description' => __( 'Inserts the public RSVP form shortcode with a short intro line.', 'wedding-party-rsvp' ),
					'content'     => '<!-- wp:paragraph --><p>' . esc_html__( 'Your guests can RSVP below using their Party ID.', 'wedding-party-rsvp' ) . '</p><!-- /wp:paragraph --><!-- wp:shortcode -->[wedding_rsvp_form]<!-- /wp:shortcode -->',
				)
			);
		}

		/**
		 * Load privacy tools integration.
		 *
		 * @return void
		 */
		public function load_privacy_exporters() {
			$file = plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-privacy.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			if ( class_exists( 'WGRSVP_Privacy' ) ) {
				WGRSVP_Privacy::register_hooks();
			}
		}

		// --- CSS HANDLERS ---
		public function enqueue_admin_styles() {
			wp_register_style( 'wgrsvp-admin-style', false, array(), '7.2' );
			wp_enqueue_style( 'wgrsvp-admin-style' );
			$css = $this->get_custom_css();
			wp_add_inline_style( 'wgrsvp-admin-style', wp_strip_all_tags( $css ) );
		}

		public function enqueue_frontend_styles() {
			wp_register_style( 'wgrsvp-front-style', false, array(), '7.2' );
			wp_enqueue_style( 'wgrsvp-front-style' );
			$css = $this->get_custom_css();
			wp_add_inline_style( 'wgrsvp-front-style', wp_strip_all_tags( $css ) );
		}

		private function get_custom_css() {
			$s     = get_option( $this->opt_settings, array() );
			$color = isset( $s['primary_color'] ) && ! empty( $s['primary_color'] ) ? $s['primary_color'] : '#333';
			$font  = isset( $s['font_size'] ) && ! empty( $s['font_size'] ) ? $s['font_size'] : '16';

			return '
				/* FRONTEND STYLES */
				.wpr-wrapper { max-width: 600px; margin: 0 auto; font-size: ' . esc_attr( $font ) . 'px; }
				.wpr-guest-card { border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; background: #f9f9f9; border-radius:5px; }
				.wpr-field { margin-bottom: 12px; }
				.wpr-field label { display: block; font-weight: bold; margin-bottom: 5px; }
				.wpr-field input[type=text], .wpr-field input[type=email], .wpr-field select, .wpr-field textarea { width: 100%; padding: 8px; border:1px solid #ccc; border-radius:3px; }
				.wpr-button { background: ' . esc_attr( $color ) . '; color: #fff; padding: 12px 25px; border: none; cursor: pointer; font-size:1.1em; border-radius:3px; }
				.wpr-button:hover { opacity: 0.9; }
				.wpr-checkbox-group label { display:inline-block; margin-right:10px; font-weight:normal; }
				.wpr-honey { display:none !important; visibility:hidden; }
				.wgrsvp-rsvp-feedback span { display: block; min-height: 1em; }
				.wgrsvp-rsvp-feedback:not(:empty) span:not(:empty) { padding: 12px; border-radius: 4px; border: 1px solid #c3c4c7; background: #f6f7f7; }
				.wpr-button.wgrsvp-is-busy, .wpr-button:disabled { cursor: wait; opacity: 0.88; }
				
				/* --- PRO PLACEHOLDERS --- */
				.wpr-pro-placeholder {
					background: #f0f0f1;
					color: #8c8f94;
					font-size: 10px;
					text-align: center;
					padding: 5px;
					border: 1px dashed #c3c4c7;
					border-radius: 4px;
					width: 100%;
					box-sizing: border-box;
					display: block;
					margin-top: 2px;
				}
				.wpr-pro-placeholder a { text-decoration:none; color:inherit; }
				.wpr-pro-link { font-size: 11px; margin-left: 5px; color: #2271b1; text-decoration: none; }
				
				/* --- ADMIN DASHBOARD GRID --- */
				.wpr-dashboard-grid {
					display: grid !important;
					grid-template-columns: 1fr 1fr 1fr !important;
					gap: 20px !important;
					width: 100% !important;
					box-sizing: border-box !important;
					margin-bottom: 30px !important;
				}
				
				.wpr-stat-box { 
					display: flex !important;
					flex-direction: column !important;
					align-items: center !important;
					justify-content: center !important;
					padding: 40px 20px !important;
					border-radius: 6px !important; 
					text-align: center !important; 
					text-decoration: none !important;
					box-shadow: 0 4px 6px rgba(0,0,0,0.1) !important;
					transition: transform 0.2s ease !important;
					box-sizing: border-box !important;
					min-height: 160px !important;
				}
				
				.wpr-stat-box:hover { transform: translateY(-3px) !important; opacity: 0.95 !important; }

				.wpr-stat-box h2 { 
					display: block !important;
					width: 100% !important;
					margin: 0 0 5px 0 !important; 
					padding: 0 !important;
					font-size: 56px !important; 
					line-height: 1 !important;
					font-weight: 800 !important;
					color: inherit !important;
				}
				
				.wpr-stat-box small { 
					display: block !important;
					font-size: 16px !important; 
					font-weight: 600 !important; 
					text-transform: uppercase !important; 
					letter-spacing: 1px !important;
					color: inherit !important;
					opacity: 0.9 !important;
				}
				
				.wpr-meal-tag { display:inline-block; margin:2px; padding:6px 10px; background:#f0f0f1; border:1px solid #ccc; border-radius:12px; font-size:12px; text-decoration:none; color:#333; }
				.wpr-meal-tag:hover { background:#fff; border-color:#0073aa; color:#0073aa; }
				.wpr-meal-tag.active { background:#0073aa; color:#fff; border-color:#0073aa; }

				/* Flex Helpers */
				.wpr-flex-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
				.wpr-justify-between { justify-content: space-between; }
				
				/* Mobile */
				@media (max-width: 782px) {
					.wpr-dashboard-grid { grid-template-columns: 1fr !important; gap: 10px !important; }
					.wpr-flex-row { flex-direction: column !important; align-items: stretch !important; }
					.wpr-flex-row input[type=text], .wpr-flex-row input[type=search], .wpr-flex-row select { width: 100% !important; height: 40px; margin-bottom: 5px; }
					.wpr-flex-row .button { width: 100% !important; padding: 10px !important; text-align: center; margin-bottom: 5px; }
					
					.wp-list-table.widefat { border: 0 !important; box-shadow: none !important; background: transparent !important; }
					.wp-list-table thead { display: none; }
					.wp-list-table tbody tr { display: block; background: #fff; border: 1px solid #ccc; margin-bottom: 15px; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
					.wp-list-table tbody td { display: block; text-align: left; padding: 5px 0 !important; border-bottom: 1px solid #eee !important; }
					.wp-list-table tbody td:last-child { border-bottom: none !important; display: flex; gap: 10px; margin-top: 10px; padding-top: 15px !important; justify-content: space-between; }
					.wp-list-table input, .wp-list-table select, .wp-list-table textarea { width: 100% !important; height: 40px; font-size: 16px !important; margin-bottom: 5px; }
					.wp-list-table td:last-child button { flex: 1; height: 40px; }
				}';
		}

		/**
		 * Column names for admin guest list sort (key => SQL identifier). Keys must match allowed sort URL params.
		 *
		 * @return array<string, string>
		 */
		private function wgrsvp_get_admin_guest_list_order_by_map() {
			return array(
				'party_id'     => 'party_id',
				'guest_name'   => 'guest_name',
				'id'           => 'id',
				'table_number' => 'table_number',
				'is_child'     => 'is_child',
				'rsvp_status'  => 'rsvp_status',
				'menu_choice'  => 'menu_choice',
			);
		}

		/**
		 * Build guest list query and prepare args: %i table, %s for filters; ORDER BY from allowlist only.
		 *
		 * @param string $search_query   Sanitized search.
		 * @param string $filter_status  Sanitized.
		 * @param string $filter_menu    Sanitized.
		 * @param bool   $group_by_party Whether to fix ORDER BY party, then name.
		 * @param string $orderby_key    Key in wgrsvp_get_admin_guest_list_order_by_map().
		 * @param string $order          ASC or DESC.
		 * @return array{0: string, 1: array<int, mixed>} Tuple: SQL with placeholders, args for wgrsvp_query_cache_get_results().
		 */
		private function wgrsvp_build_admin_guest_list_query( $search_query, $filter_status, $filter_menu, $group_by_party, $orderby_key, $order ) {
			global $wpdb;
			$order_map    = $this->wgrsvp_get_admin_guest_list_order_by_map();
			$order_column = isset( $order_map[ $orderby_key ] ) ? $order_map[ $orderby_key ] : 'party_id';
			$order_dir    = ( 'DESC' === $order ) ? 'DESC' : 'ASC';
			$sql_args     = array();
			$sql_where    = array();
			if ( $search_query ) {
				$sql_where[] = '(guest_name LIKE %s OR party_id LIKE %s)';
				$like        = '%' . $wpdb->esc_like( $search_query ) . '%';
				$sql_args[]  = $like;
				$sql_args[]  = $like;
			}
			if ( $filter_status ) {
				$sql_where[] = 'rsvp_status = %s';
				$sql_args[]  = $filter_status;
			}
			if ( $filter_menu ) {
				$sql_where[] = 'menu_choice = %s';
				$sql_args[]  = $filter_menu;
			}
			$query = 'SELECT * FROM %i';
			if ( ! empty( $sql_where ) ) {
				$query .= ' WHERE ' . implode( ' AND ', $sql_where );
			}
			if ( $group_by_party ) {
				$query .= ' ORDER BY party_id ASC, guest_name ASC';
			} else {
				// Order column and direction are from the allowlist or a boolean check only, not user SQL.
				$query .= ' ORDER BY ' . $order_column . ' ' . $order_dir;
			}
			$prepare_args = array_merge( array( $this->table_name ), $sql_args );
			return array( $query, $prepare_args );
		}

		/**
		 * Guest list dashboard: filters, sort, CSV import/export, coordinator-friendly inline actions.
		 *
		 * @return void
		 */
		public function admin_page_guests() {
			if ( ! current_user_can( WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			$can_manage_rsvp                = current_user_can( 'manage_options' );
			$wgrsvp_guestlist_get_form_attr = ( ! $can_manage_rsvp ) ? ( ' style="' . esc_attr( 'flex-grow:1' ) . '"' ) : '';

			if ( $can_manage_rsvp ) {
				add_thickbox();
			}

			$dash_js = plugins_url( 'assets/js/wgrsvp-admin-dashboard.js', __FILE__ );
			wp_register_script( 'wgrsvp-admin-dashboard', $dash_js, array(), '7.3.11', true );
			wp_enqueue_script( 'wgrsvp-admin-dashboard' );

			// Actions (full editors only).
			$this->handle_admin_actions();

			if ( $can_manage_rsvp && isset( $_POST['wgrsvp_import_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_import_nonce', 'wgrsvp_import_nonce' );
				if ( isset( $_POST['wgrsvp_import_csv'] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$csv_file = isset( $_FILES['csv_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['csv_file']['tmp_name'] ) ) : '';
					if ( ! empty( $csv_file ) ) {
						$this->handle_csv_import( $csv_file );
					}
				}
			}

			// Stats (24h transient; cleared on guest changes via clear_stats_cache).
			$agg              = $this->get_aggregated_rsvp_stats();
			$total_accepted   = (int) $agg['total_accepted'];
			$total_declined   = (int) $agg['total_declined'];
			$total_pending    = (int) $agg['total_pending'];
			$total_guests     = (int) $agg['total_guests'];
			$menu_stats_adult = $agg['menu_stats_adult'];

			// Filters.
			$search_query   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			$filter_status  = isset( $_GET['filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) ) : '';
			$filter_menu    = isset( $_GET['filter_menu'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_menu'] ) ) : '';
			$group_by_party = isset( $_GET['wgrsvp_group'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wgrsvp_group'] ) );

			$orderby        = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'party_id';
			$order          = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'ASC';
			$allowed_orders = array_keys( $this->wgrsvp_get_admin_guest_list_order_by_map() );
			if ( ! in_array( $orderby, $allowed_orders, true ) ) {
				$orderby = 'party_id';
			}
			$order = ( 'DESC' === $order ) ? 'DESC' : 'ASC';

			list( $query, $guest_query_prepare_args ) = $this->wgrsvp_build_admin_guest_list_query( $search_query, $filter_status, $filter_menu, $group_by_party, $orderby, $order );

			$guests = $this->wgrsvp_query_cache_get_results( $query, $guest_query_prepare_args );

			$menus_adult      = get_option( $this->opt_menu_adult, array() );
			$settings         = get_option( $this->opt_settings, array() );
			$demo_dismissed   = get_option( 'wgrsvp_demo_guests_dismissed' );
			$group_toggle_on  = admin_url( 'admin.php?page=wedding-rsvp-main&wgrsvp_group=1' );
			$group_toggle_off = admin_url( 'admin.php?page=wedding-rsvp-main' );
			if ( $search_query ) {
				$group_toggle_on  = add_query_arg( 's', $search_query, $group_toggle_on );
				$group_toggle_off = add_query_arg( 's', $search_query, $group_toggle_off );
			}
			if ( $filter_status ) {
				$group_toggle_on  = add_query_arg( 'filter_status', $filter_status, $group_toggle_on );
				$group_toggle_off = add_query_arg( 'filter_status', $filter_status, $group_toggle_off );
			}
			if ( $filter_menu ) {
				$group_toggle_on  = add_query_arg( 'filter_menu', $filter_menu, $group_toggle_on );
				$group_toggle_off = add_query_arg( 'filter_menu', $filter_menu, $group_toggle_off );
			}

			?>
			<div class="wrap">
				<h1 style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
					<?php esc_html_e( 'Wedding Dashboard', 'wedding-party-rsvp' ); ?>
					<span style="background:#46b450; color:#fff; font-size:12px; padding:3px 8px; border-radius:10px;">Unlimited Guests</span>
				</h1>

				<?php if ( ! $can_manage_rsvp ) : ?>
					<div class="notice notice-info"><p><?php esc_html_e( 'Coordinator mode: you can review the guest list and meal counts. Only administrators can edit guests, import or export data, or change plugin settings.', 'wedding-party-rsvp' ); ?></p></div>
				<?php endif; ?>

				<?php do_action( 'wgrsvp_guest_list_after_title', $can_manage_rsvp, $this ); ?>

				<div class="wpr-dashboard-grid">
					<a href="?page=wedding-rsvp-main&filter_status=Accepted" class="wpr-stat-box" style="background:#46b450; color:#fff;">
						<h2><?php echo esc_html( $total_accepted ); ?></h2>
						<small><?php esc_html_e( 'Attending', 'wedding-party-rsvp' ); ?></small>
					</a>
					<a href="?page=wedding-rsvp-main&filter_status=Declined" class="wpr-stat-box" style="background:#dc3232; color:#fff;">
						<h2><?php echo esc_html( $total_declined ); ?></h2>
						<small><?php esc_html_e( 'Regrets', 'wedding-party-rsvp' ); ?></small>
					</a>
					<a href="?page=wedding-rsvp-main&filter_status=Pending" class="wpr-stat-box" style="background:#ffb900; color:#23282d;">
						<h2><?php echo esc_html( $total_pending ); ?></h2>
						<small><?php esc_html_e( 'Pending', 'wedding-party-rsvp' ); ?></small>
					</a>
				</div>

				<?php if ( ! empty( $menu_stats_adult ) ) : ?>
				<div style="background:#fff; border:1px solid #ccd0d4; padding:10px; margin-bottom:20px;">
					<strong><?php esc_html_e( 'Menu Breakdown:', 'wedding-party-rsvp' ); ?></strong><br>
					<div style="margin-top:5px;">
						<?php
						foreach ( $menu_stats_adult as $stat ) :
							$active = ( $filter_menu === $stat->menu_choice ) ? 'active' : '';
							?>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wedding-rsvp-main', 'filter_menu' => $stat->menu_choice ), admin_url( 'admin.php' ) ) ); ?>" class="wpr-meal-tag <?php echo esc_attr( $active ); ?>"><?php echo esc_html( $stat->menu_choice ); ?> (<?php echo intval( $stat->count ); ?>)</a>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( $can_manage_rsvp && ! $demo_dismissed && (int) $total_guests < 1 ) : ?>
				<div class="notice notice-info" style="margin:15px 0;">
					<p><?php esc_html_e( 'Your guest list is empty. Load a few sample guests to explore the dashboard, or dismiss this tip.', 'wedding-party-rsvp' ); ?></p>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( 'wgrsvp_seed_demo', 'wgrsvp_seed_demo' ); ?>
						<input type="submit" name="wgrsvp_seed_demo_guests" class="button button-primary" value="<?php esc_attr_e( 'Load sample guests', 'wedding-party-rsvp' ); ?>">
					</form>
					<form method="post" style="display:inline; margin-left:8px;">
						<?php wp_nonce_field( 'wgrsvp_seed_demo', 'wgrsvp_seed_demo' ); ?>
						<input type="submit" name="wgrsvp_dismiss_demo_box" class="button" value="<?php esc_attr_e( 'Dismiss', 'wedding-party-rsvp' ); ?>">
					</form>
				</div>
				<?php endif; ?>
				
				<div class="wpr-flex-row wpr-justify-between" style="margin-bottom:10px;">
					<?php if ( $can_manage_rsvp ) : ?>
					<div style="background:#fff; padding:10px; border:1px solid #ccd0d4; flex-grow:1;">
						<form method="post" class="wpr-flex-row">
							<?php wp_nonce_field( 'wgrsvp_add_guest', 'wgrsvp_add_guest' ); ?>
							<strong><?php esc_html_e( 'Add Guest:', 'wedding-party-rsvp' ); ?></strong>
							<input type="text" name="party_id" required placeholder="<?php esc_attr_e( 'Party ID', 'wedding-party-rsvp' ); ?>" style="width:100px;">
							<input type="text" name="guest_name" required placeholder="<?php esc_attr_e( 'Name', 'wedding-party-rsvp' ); ?>" style="width:120px;">
							<?php if ( ! wgrsvp_is_pro_plugin_active() ) : ?>
							<div class="wpr-pro-placeholder" style="width:60px; display:inline-block; margin:0 5px;">Kid (Pro)</div>
							<?php endif; ?>
							<input type="submit" name="wgrsvp_add_guest_btn" class="button button-primary" value="<?php esc_attr_e( 'Add', 'wedding-party-rsvp' ); ?>">
						</form>
					</div>
					<?php endif; ?>
					<form method="get" class="wpr-flex-row"<?php echo $wgrsvp_guestlist_get_form_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed in admin_page_guests() using esc_attr() and static CSS. ?>>
						<input type="hidden" name="page" value="wedding-rsvp-main">
						<?php if ( $group_by_party ) : ?>
							<input type="hidden" name="wgrsvp_group" value="1">
						<?php endif; ?>
						<?php
						if ( $filter_status ) :
							?>
							<input type="hidden" name="filter_status" value="<?php echo esc_attr( $filter_status ); ?>"><?php endif; ?>
						<?php if ( $filter_menu ) : ?>
							<input type="hidden" name="filter_menu" value="<?php echo esc_attr( $filter_menu ); ?>">
						<?php endif; ?>
						<input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'wedding-party-rsvp' ); ?>">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'wedding-party-rsvp' ); ?>">
						<a class="button" href="<?php echo esc_url( $group_by_party ? $group_toggle_off : $group_toggle_on ); ?>"><?php
						if ( $group_by_party ) {
							esc_html_e( 'Ungroup list', 'wedding-party-rsvp' );
						} else {
							esc_html_e( 'Group by party', 'wedding-party-rsvp' );
						}
						?></a>
						<?php if ( $search_query || $filter_status || $filter_menu || $group_by_party ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'wedding-party-rsvp' ); ?></a>
						<?php endif; ?>
					</form>
				</div>

				<?php if ( $can_manage_rsvp ) : ?>
				<div class="wpr-flex-row wpr-justify-between" style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin-bottom:20px;">
					<form method="post" enctype="multipart/form-data" class="wpr-flex-row">
						<?php wp_nonce_field( 'wgrsvp_import_nonce', 'wgrsvp_import_nonce' ); ?>
						<strong><?php esc_html_e( 'CSV Import:', 'wedding-party-rsvp' ); ?></strong>
						<input type="file" name="csv_file" accept=".csv" required>
						<input type="submit" name="wgrsvp_import_csv" class="button" value="<?php esc_attr_e( 'Upload', 'wedding-party-rsvp' ); ?>">
					</form>
					<form method="post">
						<?php wp_nonce_field( 'wgrsvp_export_nonce', 'wgrsvp_export_nonce' ); ?>
						<input type="hidden" name="export_s" value="<?php echo esc_attr( $search_query ); ?>">
						<input type="hidden" name="export_filter_status" value="<?php echo esc_attr( $filter_status ); ?>">
						<input type="hidden" name="export_filter_menu" value="<?php echo esc_attr( $filter_menu ); ?>">
						<input type="hidden" name="export_orderby" value="<?php echo esc_attr( $orderby ); ?>">
						<input type="hidden" name="export_order" value="<?php echo esc_attr( $order ); ?>">
						<input type="hidden" name="export_wgrsvp_group" value="<?php echo esc_attr( $group_by_party ? '1' : '' ); ?>">
						<p class="description" style="margin:0 0 8px 0;"><?php esc_html_e( 'CSV export matches the search and filters above.', 'wedding-party-rsvp' ); ?></p>
						<input type="submit" name="wgrsvp_export_csv" class="button button-secondary" value="<?php esc_attr_e( 'Export CSV', 'wedding-party-rsvp' ); ?>">
					</form>
				</div>
				<?php endif; ?>

				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<th width="8%"><a href="<?php echo esc_url( $this->get_sort_link( 'party_id', $orderby, $order ) ); ?>"><?php esc_html_e( 'Party ID', 'wedding-party-rsvp' ); ?></a></th>
						<th width="15%"><a href="<?php echo esc_url( $this->get_sort_link( 'guest_name', $orderby, $order ) ); ?>"><?php esc_html_e( 'Name', 'wedding-party-rsvp' ); ?></a></th>
						<th width="3%"><?php esc_html_e( 'Kid', 'wedding-party-rsvp' ); ?></th>
						<th width="8%"><a href="<?php echo esc_url( $this->get_sort_link( 'rsvp_status', $orderby, $order ) ); ?>"><?php esc_html_e( 'RSVP', 'wedding-party-rsvp' ); ?></a></th>
						<th width="12%"><?php esc_html_e( 'Menu', 'wedding-party-rsvp' ); ?></th>
						<th width="5%"><?php esc_html_e( 'Tbl', 'wedding-party-rsvp' ); ?></th>
						<th width="18%"><?php esc_html_e( 'Contact/Info', 'wedding-party-rsvp' ); ?></th>
						<th width="15%"><?php esc_html_e( 'Admin Notes', 'wedding-party-rsvp' ); ?></th>
						<th width="16%"><?php esc_html_e( 'Actions', 'wedding-party-rsvp' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( $can_manage_rsvp ) : ?>
							<?php
							foreach ( $guests as $guest ) :
								?>
								<tr><form method="post">
									<input type="hidden" name="id" value="<?php echo esc_attr( $guest->id ); ?>">
									<?php wp_nonce_field( 'wgrsvp_edit_guest', 'wgrsvp_edit_guest' ); ?>

									<td><input type="text" name="party_id" value="<?php echo esc_attr( $guest->party_id ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'Party ID', 'wedding-party-rsvp' ); ?>"></td>
									<td><input type="text" name="guest_name" value="<?php echo esc_attr( $guest->guest_name ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'Name', 'wedding-party-rsvp' ); ?>"></td>

									<td style="text-align:center;">
										<?php if ( wgrsvp_is_pro_plugin_active() ) : ?>
											<span class="description"><?php echo esc_html( ! empty( $guest->is_child ) ? __( 'Yes', 'wedding-party-rsvp' ) : __( 'No', 'wedding-party-rsvp' ) ); ?></span>
										<?php else : ?>
											<div class="wpr-pro-placeholder">Pro</div>
										<?php endif; ?>
									</td>

									<td><select name="rsvp_status" style="width:100%"><option value="Pending" <?php selected( $guest->rsvp_status, 'Pending' ); ?>>?</option><option value="Accepted" <?php selected( $guest->rsvp_status, 'Accepted' ); ?>><?php esc_html_e( 'Yes', 'wedding-party-rsvp' ); ?></option><option value="Declined" <?php selected( $guest->rsvp_status, 'Declined' ); ?>><?php esc_html_e( 'No', 'wedding-party-rsvp' ); ?></option></select></td>

									<td>
										<select name="menu_choice" style="width:100%; margin-bottom:2px; font-size:11px;">
											<option value=""><?php esc_html_e( '(Adult)', 'wedding-party-rsvp' ); ?></option>
											<?php
											foreach ( $menus_adult as $m ) {
												echo '<option value="' . esc_attr( $m ) . '" ' . selected( $guest->menu_choice, $m, false ) . '>' . esc_html( $m ) . '</option>';
											}
											?>
										</select>

										<?php if ( ! wgrsvp_is_pro_plugin_active() ) : ?>
										<div class="wpr-pro-placeholder" style="margin-bottom:2px;">Child Menu (Available in Pro)</div>
										<div style="display:flex; gap:2px;">
											<div class="wpr-pro-placeholder">Appetizer (Pro)</div>
											<div class="wpr-pro-placeholder">Hors (Pro)</div>
										</div>
										<?php elseif ( ! empty( $guest->child_menu_choice ) || ! empty( $guest->appetizer_choice ) || ! empty( $guest->hors_doeuvre_choice ) ) : ?>
											<div style="font-size:10px;color:#646970;margin-top:4px;">
												<?php if ( ! empty( $guest->child_menu_choice ) ) : ?>
													<div><?php echo esc_html( $guest->child_menu_choice ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $guest->appetizer_choice ) ) : ?>
													<div><?php echo esc_html( $guest->appetizer_choice ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $guest->hors_doeuvre_choice ) ) : ?>
													<div><?php echo esc_html( $guest->hors_doeuvre_choice ); ?></div>
												<?php endif; ?>
											</div>
										<?php endif; ?>
									</td>

									<td>
										<?php if ( wgrsvp_is_pro_plugin_active() ) : ?>
											<?php
											$tbl = isset( $guest->table_number ) ? trim( (string) $guest->table_number ) : '';
											echo '' !== $tbl ? '<span class="description">' . esc_html( $tbl ) . '</span>' : '<span class="description">' . esc_html__( '—', 'wedding-party-rsvp' ) . '</span>';
											?>
										<?php else : ?>
											<div class="wpr-pro-placeholder"># (Pro)</div>
										<?php endif; ?>
									</td>

									<td>
										<input type="text" name="email" value="<?php echo esc_attr( $guest->email ); ?>" placeholder="<?php esc_attr_e( 'Email', 'wedding-party-rsvp' ); ?>" style="width:100%; margin-bottom:2px; font-size:11px;">
										<input type="text" name="phone" value="<?php echo esc_attr( $guest->phone ); ?>" placeholder="<?php esc_attr_e( 'Phone', 'wedding-party-rsvp' ); ?>" style="width:100%; font-size:11px;">
										<div style="font-size:10px; color:#666; margin-top:3px;">
											<?php
											if ( ! empty( $guest->allergies ) ) {
												echo '! ' . esc_html( $guest->allergies ) . '<br>';
											}
											if ( ! empty( $guest->guest_message ) ) {
												echo '&#9993; "' . esc_html( substr( $guest->guest_message, 0, 20 ) ) . '..."';
											}
											?>
										</div>
									</td>

									<td>
										<?php if ( wgrsvp_is_pro_plugin_active() && current_user_can( 'manage_options' ) ) : ?>
											<?php
											$pro_notes_url = wp_nonce_url( admin_url( 'admin.php?page=wedding-rsvp-edit&id=' . absint( $guest->id ) ), 'wpr_pro_view_edit_guest', 'wpr_pro_edit' ) . '#wpr-admin-notes';
											?>
											<a href="<?php echo esc_url( $pro_notes_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( ! empty( $guest->admin_notes ) ? __( 'Edit admin notes', 'wedding-party-rsvp' ) : __( 'Admin notes', 'wedding-party-rsvp' ) ); ?></a>
										<?php elseif ( wgrsvp_is_pro_plugin_active() ) : ?>
											<span class="description"><?php esc_html_e( '—', 'wedding-party-rsvp' ); ?></span>
										<?php else : ?>
											<div class="wpr-pro-placeholder" style="height:50px; line-height:50px;">Admin Notes (Available in Pro)</div>
										<?php endif; ?>
									</td>

									<td style="white-space:nowrap;">
										<?php
										$party_rsvp_url = $this->get_public_party_rsvp_url( $guest->party_id );
										?>
										<a class="button button-small" href="<?php echo esc_url( $party_rsvp_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open RSVP', 'wedding-party-rsvp' ); ?></a>
										<button type="button" class="button button-small wgrsvp-copy-rsvp" data-url="<?php echo esc_attr( $party_rsvp_url ); ?>" data-label="<?php echo esc_attr__( 'Copy link', 'wedding-party-rsvp' ); ?>" data-copied="<?php echo esc_attr__( 'Copied!', 'wedding-party-rsvp' ); ?>"><?php esc_html_e( 'Copy link', 'wedding-party-rsvp' ); ?></button>
										<button type="submit" name="wgrsvp_update_guest" class="button button-primary button-small" title="Save"><span class="dashicons dashicons-saved"></span> Save</button>
										<button type="submit" name="wgrsvp_delete_guest" class="button button-small button-link-delete" title="<?php esc_attr_e( 'Delete', 'wedding-party-rsvp' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this guest row?', 'wedding-party-rsvp' ) ); ?>')"><span class="dashicons dashicons-trash"></span></button>
									</td>
								</form></tr>
							<?php endforeach; ?>
						<?php else : ?>
							<?php foreach ( $guests as $guest ) : ?>
								<tr>
									<td><?php echo esc_html( $guest->party_id ); ?></td>
									<td><?php echo esc_html( $guest->guest_name ); ?></td>
									<td style="text-align:center;"><?php echo esc_html( ! empty( $guest->is_child ) ? __( 'Yes', 'wedding-party-rsvp' ) : __( 'No', 'wedding-party-rsvp' ) ); ?></td>
									<td><?php echo esc_html( $guest->rsvp_status ); ?></td>
									<td><?php echo esc_html( $guest->menu_choice ); ?></td>
									<td><?php echo esc_html( $guest->table_number ); ?></td>
									<td>
										<div style="font-size:12px;">
											<?php if ( ! empty( $guest->email ) ) : ?>
												<div><?php echo esc_html( $guest->email ); ?></div>
											<?php endif; ?>
											<?php if ( ! empty( $guest->phone ) ) : ?>
												<div><?php echo esc_html( $guest->phone ); ?></div>
											<?php endif; ?>
											<?php if ( ! empty( $guest->allergies ) ) : ?>
												<div style="font-size:10px; color:#666;"><?php echo esc_html( $guest->allergies ); ?></div>
											<?php endif; ?>
											<?php if ( ! empty( $guest->guest_message ) ) : ?>
												<div style="font-size:10px; color:#666;"><?php echo esc_html( wp_trim_words( $guest->guest_message, 12, '…' ) ); ?></div>
											<?php endif; ?>
										</div>
									</td>
									<td>
										<?php if ( wgrsvp_is_pro_plugin_active() && current_user_can( 'manage_options' ) ) : ?>
											<?php
											$pro_notes_url = wp_nonce_url( admin_url( 'admin.php?page=wedding-rsvp-edit&id=' . absint( $guest->id ) ), 'wpr_pro_view_edit_guest', 'wpr_pro_edit' ) . '#wpr-admin-notes';
											?>
											<a href="<?php echo esc_url( $pro_notes_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( ! empty( $guest->admin_notes ) ? __( 'Edit admin notes', 'wedding-party-rsvp' ) : __( 'Admin notes', 'wedding-party-rsvp' ) ); ?></a>
										<?php else : ?>
											<span class="description"><?php esc_html_e( '—', 'wedding-party-rsvp' ); ?></span>
										<?php endif; ?>
									</td>
									<td style="white-space:nowrap;">
										<?php
										$party_rsvp_url = $this->get_public_party_rsvp_url( $guest->party_id );
										?>
										<a class="button button-small" href="<?php echo esc_url( $party_rsvp_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open RSVP', 'wedding-party-rsvp' ); ?></a>
										<button type="button" class="button button-small wgrsvp-copy-rsvp" data-url="<?php echo esc_attr( $party_rsvp_url ); ?>" data-label="<?php echo esc_attr__( 'Copy link', 'wedding-party-rsvp' ); ?>" data-copied="<?php echo esc_attr__( 'Copied!', 'wedding-party-rsvp' ); ?>"><?php esc_html_e( 'Copy link', 'wedding-party-rsvp' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * General settings, license field (legacy), danger-zone factory reset.
		 *
		 * @return void
		 */
		public function admin_page_settings() {
			// Security: Check user capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			if ( isset( $_POST['wgrsvp_reset_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_reset_nonce', 'wgrsvp_reset_nonce' );
				if ( isset( $_POST['wgrsvp_factory_reset'] ) ) {
					global $wpdb;
					$wp_version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0';
					if ( version_compare( $wp_version, '6.2', '>=' ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
						$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $this->table_name ) );
					} else {
						$table_safe = '`' . str_replace( '`', '``', $this->table_name ) . '`';
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from $wpdb->prefix + literal name.
						$wpdb->query( "TRUNCATE TABLE {$table_safe}" );
					}

					delete_option( $this->opt_menu_adult );
					delete_option( $this->opt_settings );
					delete_option( $this->opt_license );

					$this->clear_stats_cache();

					echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'System Reset Complete. All data and settings have been cleared.', 'wedding-party-rsvp' ) . '</strong></p></div>';
				}
			}

			if ( isset( $_POST['wgrsvp_settings_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_settings_nonce', 'wgrsvp_settings_nonce' );
				if ( isset( $_POST['wgrsvp_save_settings'] ) ) {
					$prev = get_option( $this->opt_settings, array() );
					if ( ! is_array( $prev ) ) {
						$prev = array();
					}
					$settings = array_merge(
						$prev,
						array(
							'rsvp_page_url'           => isset( $_POST['rsvp_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['rsvp_page_url'] ) ) : '',
							'deadline_date'           => isset( $_POST['deadline_date'] ) ? sanitize_text_field( wp_unslash( $_POST['deadline_date'] ) ) : '',
							'redirect_url'            => isset( $_POST['redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) ) : '',
							/**
							 * MANUAL REVIEW REQUIRED — `welcome_title` is plain (`sanitize_text_field`) while
							 * `deadline_closed_message` uses `wp_kses_post`. The front end prints the welcome in
							 * `<h2>` with `esc_html()`, so stored markup would not render without coordinated UI
							 * and output changes (e.g. textarea + `wp_kses_post`).
							 */
							'welcome_title'           => isset( $_POST['welcome_title'] ) ? sanitize_text_field( wp_unslash( $_POST['welcome_title'] ) ) : '',
							'deadline_closed_message' => isset( $_POST['deadline_closed_message'] ) ? wp_kses_post( wp_unslash( $_POST['deadline_closed_message'] ) ) : '',
						)
					);
					update_option( $this->opt_settings, $settings );

					$new_key  = isset( $_POST['wgrsvp_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wgrsvp_license_key'] ) ) : '';
					$prev_lic = get_option( $this->opt_license, '' );
					$keep_lic = ( '' === $new_key && wgrsvp_is_pro_license_effectively_valid() && is_string( $prev_lic ) && '' !== $prev_lic );
					if ( ! $keep_lic ) {
						update_option( $this->opt_license, $new_key );
					}

					echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings Saved.', 'wedding-party-rsvp' ) . '</p></div>';
				}
			}

			$s        = get_option( $this->opt_settings, array() );
			$lic      = get_option( $this->opt_license, '' );
			$lic_show = $lic;
			$lic_ph   = '';
			if ( wgrsvp_is_pro_license_effectively_valid() && '' !== $lic ) {
				$lic_show = '';
				$lic_ph   = wgrsvp_mask_license_key_for_display( $lic );
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'General Settings', 'wedding-party-rsvp' ); ?></h1>
				<div class="wgrsvp-settings-layout">
				<div class="wgrsvp-settings-layout__main">
				<form method="post">
					<?php wp_nonce_field( 'wgrsvp_settings_nonce', 'wgrsvp_settings_nonce' ); ?>
					
					<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px; border-left:4px solid #666;">
						<h3><?php esc_html_e( 'License / Support', 'wedding-party-rsvp' ); ?></h3>
						<?php if ( wgrsvp_is_pro_plugin_active() ) : ?>
							<p class="description"><?php esc_html_e( 'Premium licensing and support are managed in Wedding Party RSVP Pro (Settings & Licensing). This field is only needed for legacy free-plugin data.', 'wedding-party-rsvp' ); ?></p>
							<?php if ( wgrsvp_is_pro_license_effectively_valid() && '' !== $lic ) : ?>
								<p class="description"><?php esc_html_e( 'License key on file is masked. Leave blank to keep it, or enter a new key to replace.', 'wedding-party-rsvp' ); ?></p>
							<?php endif; ?>
							<input type="text" name="wgrsvp_license_key" value="<?php echo esc_attr( $lic_show ); ?>" style="width:100%; max-width:400px;" placeholder="<?php echo esc_attr( '' !== $lic_ph ? $lic_ph : __( 'Optional', 'wedding-party-rsvp' ) ); ?>" autocomplete="off">
						<?php else : ?>
							<p><?php esc_html_e( 'Enter your license key below for Priority Support and to unlock Pro features.', 'wedding-party-rsvp' ); ?></p>

							<p style="margin-bottom:15px;">
								<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" class="button"><?php esc_html_e( 'Purchase License Key', 'wedding-party-rsvp' ); ?></a>
							</p>

							<?php if ( wgrsvp_is_pro_license_effectively_valid() && '' !== $lic ) : ?>
								<p class="description"><?php esc_html_e( 'License key on file is masked. Leave blank to keep it, or enter a new key to replace.', 'wedding-party-rsvp' ); ?></p>
							<?php endif; ?>
							<input type="text" name="wgrsvp_license_key" value="<?php echo esc_attr( $lic_show ); ?>" style="width:100%; max-width:400px;" placeholder="<?php echo esc_attr( '' !== $lic_ph ? $lic_ph : __( 'License Key', 'wedding-party-rsvp' ) ); ?>" autocomplete="off">
						<?php endif; ?>
					</div>

					<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px;">
						<h3><?php esc_html_e( 'Frontend Display', 'wedding-party-rsvp' ); ?></h3>
						<p><label><strong><?php esc_html_e( 'Custom Welcome Title:', 'wedding-party-rsvp' ); ?></strong></label><br>
						<input type="text" name="welcome_title" value="<?php echo esc_attr( $s['welcome_title'] ?? '' ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'e.g. Welcome to Sarah & John\'s Wedding!', 'wedding-party-rsvp' ); ?>"><br>
						<small><?php esc_html_e( 'Replaces the default "Party: [ID]" title.', 'wedding-party-rsvp' ); ?></small></p>
					</div>

					<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px;">
						<h3><?php esc_html_e( 'Logistics', 'wedding-party-rsvp' ); ?></h3>
						<p><label><strong><?php esc_html_e( 'RSVP Page URL:', 'wedding-party-rsvp' ); ?></strong></label><br><input type="text" name="rsvp_page_url" value="<?php echo esc_url( $s['rsvp_page_url'] ?? '' ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'e.g. https://mysite.com/rsvp', 'wedding-party-rsvp' ); ?>"></p>
						<p><label><strong><?php esc_html_e( 'RSVP Deadline:', 'wedding-party-rsvp' ); ?></strong></label><br><input type="date" name="deadline_date" value="<?php echo esc_attr( $s['deadline_date'] ?? '' ); ?>"></p>
						<p><label><strong><?php esc_html_e( 'Message when RSVP is closed (optional):', 'wedding-party-rsvp' ); ?></strong></label><br>
						<textarea name="deadline_closed_message" rows="4" style="width:100%;" placeholder="<?php esc_attr_e( 'Shown instead of the default closed text after the deadline. Basic HTML allowed.', 'wedding-party-rsvp' ); ?>"><?php echo esc_textarea( $s['deadline_closed_message'] ?? '' ); ?></textarea></p>
						<p><label><strong><?php esc_html_e( 'Redirect Success URL:', 'wedding-party-rsvp' ); ?></strong></label><br><input type="text" name="redirect_url" value="<?php echo esc_url( $s['redirect_url'] ?? '' ); ?>" style="width:100%"></p>
					</div>

					<?php $this->render_settings_pro_teaser_section(); ?>

					<?php if ( ! wgrsvp_is_pro_plugin_active() ) : ?>
					<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px;">
						<h3><?php esc_html_e( 'Appearance Settings', 'wedding-party-rsvp' ); ?></h3>
						<div class="wpr-pro-placeholder" style="padding:20px;">
							<p><?php esc_html_e( 'Button Colors and Font Sizes are available in the Pro version.', 'wedding-party-rsvp' ); ?></p>
							<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" class="wpr-pro-link"><?php esc_html_e( 'Upgrade Now', 'wedding-party-rsvp' ); ?></a>
						</div>
					</div>

					<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px;">
						<h3><?php esc_html_e( 'Visibility Toggles', 'wedding-party-rsvp' ); ?></h3>
						<div class="wpr-pro-placeholder" style="padding:20px;">
							<p><?php esc_html_e( 'Options to hide Song Requests and Meal Courses are available in the Pro version.', 'wedding-party-rsvp' ); ?></p>
							<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" class="wpr-pro-link"><?php esc_html_e( 'Upgrade Now', 'wedding-party-rsvp' ); ?></a>
						</div>
					</div>
					<?php endif; ?>

					<div style="display:flex; gap:10px;">
						<input type="submit" name="wgrsvp_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'wedding-party-rsvp' ); ?>">
					</div>
				</form>
				
				<form method="post" style="margin-top:50px;">
					<?php wp_nonce_field( 'wgrsvp_reset_nonce', 'wgrsvp_reset_nonce' ); ?>
					<div style="background:#fff; padding:20px; border:1px solid #dc3232; border-left:4px solid #dc3232;">
						<h3 style="color:#dc3232; margin-top:0;"><?php esc_html_e( 'Danger Zone: Factory Reset', 'wedding-party-rsvp' ); ?></h3>
						<p><?php esc_html_e( 'This will DELETE ALL GUESTS, RESET ALL SETTINGS, and REMOVE THE LICENSE KEY. This action cannot be undone.', 'wedding-party-rsvp' ); ?></p>
						<input type="submit" name="wgrsvp_factory_reset" class="button button-link-delete" style="color:red; text-decoration:none; border:1px solid red; padding:5px 15px;" value="<?php esc_attr_e( 'Reset Program to Default', 'wedding-party-rsvp' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'WARNING: Are you sure you want to delete ALL data and reset the plugin?', 'wedding-party-rsvp' ) ); ?>');">
					</div>
				</form>
				</div>

				<?php $this->render_settings_landtech_cross_promo(); ?>
				</div>

				<?php $this->render_settings_pro_teaser_modal(); ?>
			</div>
			<?php
		}

		/**
		 * Adult entrée list (one option per line) for the public RSVP form.
		 *
		 * @return void
		 */
		public function admin_page_menu() {
			// Security: Check user capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			if ( isset( $_POST['wgrsvp_menu_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_menu_nonce', 'wgrsvp_menu_nonce' );
				if ( isset( $_POST['wgrsvp_save_menu'] ) ) {
					/**
					 * MANUAL REVIEW REQUIRED — Entrée lines use `sanitize_textarea_field()` (no HTML). If public
					 * dropdown labels must include markup or a different encoding, define policy and sanitizer
					 * explicitly; do not assume HTML here.
					 */
					$menu_options_raw = isset( $_POST['menu_options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['menu_options'] ) ) : '';
					$this->save_menu_option( $this->opt_menu_adult, $menu_options_raw );
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Adult Menu Options Saved.', 'wedding-party-rsvp' ) . '</p></div>';
				}
			}

			$curr_adult = get_option( $this->opt_menu_adult, array() );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Manage Menu Options', 'wedding-party-rsvp' ); ?></h1>
				<form method="post">
					<?php wp_nonce_field( 'wgrsvp_menu_nonce', 'wgrsvp_menu_nonce' ); ?>
					<div style="display:flex; gap:20px; flex-wrap:wrap;">
						<div style="flex:1; min-width:250px;"><h3><?php esc_html_e( 'Adult Entrées', 'wedding-party-rsvp' ); ?></h3><textarea name="menu_options" rows="8" style="width:100%;"><?php echo esc_textarea( implode( "\n", $curr_adult ) ); ?></textarea></div>
						<?php if ( ! wgrsvp_is_pro_plugin_active() ) : ?>
						<div style="flex:1; min-width:250px;">
							<h3><?php esc_html_e( 'Child Menu Options', 'wedding-party-rsvp' ); ?></h3>
							<div class="wpr-pro-placeholder" style="height:150px; display:flex; align-items:center; justify-content:center;">
								<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" class="wpr-pro-link"><?php esc_html_e( 'Upgrade to manage Child Menus', 'wedding-party-rsvp' ); ?></a>
							</div>
						</div>
						<?php endif; ?>
					</div>
					<?php if ( ! wgrsvp_is_pro_plugin_active() ) : ?>
					<div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:20px;">
						<div style="flex:1; min-width:250px;">
							<h3><?php esc_html_e( 'Appetizers', 'wedding-party-rsvp' ); ?></h3>
							<div class="wpr-pro-placeholder" style="height:150px; display:flex; align-items:center; justify-content:center;">
								<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" class="wpr-pro-link"><?php esc_html_e( 'Upgrade to manage Appetizers', 'wedding-party-rsvp' ); ?></a>
							</div>
						</div>
						<div style="flex:1; min-width:250px;">
							<h3><?php esc_html_e( 'Hors d\'oeuvres', 'wedding-party-rsvp' ); ?></h3>
							<div class="wpr-pro-placeholder" style="height:150px; display:flex; align-items:center; justify-content:center;">
								<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" class="wpr-pro-link"><?php esc_html_e( 'Upgrade to manage Hors d\'oeuvres', 'wedding-party-rsvp' ); ?></a>
							</div>
						</div>
					</div>
					<?php endif; ?>
					<br><input type="submit" name="wgrsvp_save_menu" class="button button-primary button-large" value="<?php esc_attr_e( 'Save Adult Options', 'wedding-party-rsvp' ); ?>">
				</form>
			</div>
			<?php
		}
		/**
		 * Persist newline-separated menu lines to an option array.
		 *
		 * @param string $key        Option name.
		 * @param string $clean_raw  Already-sanitized textarea contents.
		 * @return void
		 */
		private function save_menu_option( $key, $clean_raw ) {
			update_option( $key, array_filter( array_map( 'trim', explode( "\n", $clean_raw ) ) ) );
		}

		/**
		 * POST handlers on the guest list screen: add/update/delete guest, demo seed, demo dismiss.
		 *
		 * Requires `manage_options`. Nonce verified per action before reading body fields.
		 *
		 * @return void
		 */
		private function handle_admin_actions() {
			// Security: Check user capabilities for all admin actions.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			global $wpdb;

			if ( isset( $_POST['wgrsvp_add_guest'] ) ) {
				check_admin_referer( 'wgrsvp_add_guest', 'wgrsvp_add_guest' );
				wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );
				if ( isset( $_POST['wgrsvp_add_guest_btn'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP insert; clear_stats_cache() busts query object cache after.
					$wpdb->insert(
						$this->table_name,
						array(
							'party_id'   => isset( $_POST['party_id'] ) ? sanitize_text_field( wp_unslash( $_POST['party_id'] ) ) : '',
							'guest_name' => isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '',
						)
					);

					$this->clear_stats_cache();
				}
			}

			if ( isset( $_POST['wgrsvp_edit_guest'] ) ) {
				check_admin_referer( 'wgrsvp_edit_guest', 'wgrsvp_edit_guest' );
				wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );
				if ( isset( $_POST['wgrsvp_update_guest'] ) ) {
					$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

					$allowed_rsvp = array( 'Pending', 'Accepted', 'Declined' );
					$rsvp_raw     = isset( $_POST['rsvp_status'] ) ? sanitize_text_field( wp_unslash( $_POST['rsvp_status'] ) ) : 'Pending';
					$rsvp_status  = in_array( $rsvp_raw, $allowed_rsvp, true ) ? $rsvp_raw : 'Pending';

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP update; clear_stats_cache() busts query object cache after.
					$wpdb->update(
						$this->table_name,
						array(
							'party_id'    => isset( $_POST['party_id'] ) ? sanitize_text_field( wp_unslash( $_POST['party_id'] ) ) : '',
							'guest_name'  => isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '',
							'rsvp_status' => $rsvp_status,
							'menu_choice' => isset( $_POST['menu_choice'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_choice'] ) ) : '',
							'email'       => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
							'phone'       => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
						),
						array( 'id' => $id )
					);

					$this->clear_stats_cache();

					$party_after = isset( $_POST['party_id'] ) ? sanitize_text_field( wp_unslash( $_POST['party_id'] ) ) : '';
					do_action( 'wgrsvp_after_rsvp_save', $party_after );
				} elseif ( isset( $_POST['wgrsvp_delete_guest'] ) ) {
					$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP delete; clear_stats_cache() busts query object cache after.
					$wpdb->delete( $this->table_name, array( 'id' => $id ) );
					$this->clear_stats_cache();
				}
			}

			if ( isset( $_POST['wgrsvp_seed_demo'] ) ) {
				check_admin_referer( 'wgrsvp_seed_demo', 'wgrsvp_seed_demo' );
				wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );
				if ( isset( $_POST['wgrsvp_dismiss_demo_box'] ) ) {
					update_option( 'wgrsvp_demo_guests_dismissed', 1, false );
				} elseif ( isset( $_POST['wgrsvp_seed_demo_guests'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One-off COUNT; table via %i + $wpdb->prefix.
					$guest_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table_name ) );
					if ( $guest_count < 1 ) {
						$demo_rows = array(
							array(
								'party_id'   => 'DEMO-JONES',
								'guest_name' => __( 'Alex Jones', 'wedding-party-rsvp' ),
							),
							array(
								'party_id'   => 'DEMO-JONES',
								'guest_name' => __( 'Sam Jones', 'wedding-party-rsvp' ),
							),
							array(
								'party_id'   => 'DEMO-LEE',
								'guest_name' => __( 'River Lee', 'wedding-party-rsvp' ),
							),
						);
						foreach ( $demo_rows as $row ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
							$wpdb->insert( $this->table_name, $row );
						}
						$this->clear_stats_cache();
					}
				}
			}
		}

		/**
		 * Streams a CSV download when the export form is posted (`admin_init`).
		 *
		 * Bails immediately unless the export form was submitted, so non-export admin requests
		 * do not run `check_admin_referer()` or capability checks.
		 *
		 * @return void
		 */
		public function handle_csv_export() {
			// Only run for our export form POST so capability/nonce are not evaluated on every admin request.
			if ( ! isset( $_POST['wgrsvp_export_csv'], $_POST['wgrsvp_export_nonce'] ) ) {
				return;
			}
			check_admin_referer( 'wgrsvp_export_nonce', 'wgrsvp_export_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			global $wpdb;

			$search_query  = isset( $_POST['export_s'] ) ? sanitize_text_field( wp_unslash( $_POST['export_s'] ) ) : '';
			$filter_status = isset( $_POST['export_filter_status'] ) ? sanitize_text_field( wp_unslash( $_POST['export_filter_status'] ) ) : '';
			$filter_menu   = isset( $_POST['export_filter_menu'] ) ? sanitize_text_field( wp_unslash( $_POST['export_filter_menu'] ) ) : '';
			$orderby       = isset( $_POST['export_orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['export_orderby'] ) ) : 'party_id';
			$order         = isset( $_POST['export_order'] ) ? sanitize_text_field( wp_unslash( $_POST['export_order'] ) ) : 'ASC';
			$group_export  = isset( $_POST['export_wgrsvp_group'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['export_wgrsvp_group'] ) );

			$allowed_orders = array( 'party_id', 'guest_name', 'id', 'table_number', 'is_child', 'rsvp_status', 'menu_choice' );
			if ( ! in_array( $orderby, $allowed_orders, true ) ) {
				$orderby = 'party_id';
			}
			$order = ( 'DESC' === $order ) ? 'DESC' : 'ASC';

			$sql_args  = array();
			$sql_where = array();
			if ( $search_query ) {
				$sql_where[] = '(guest_name LIKE %s OR party_id LIKE %s)';
				$like        = '%' . $wpdb->esc_like( $search_query ) . '%';
				$sql_args[]  = $like;
				$sql_args[]  = $like;
			}
			if ( $filter_status ) {
				$sql_where[] = 'rsvp_status = %s';
				$sql_args[]  = $filter_status;
			}
			if ( $filter_menu ) {
				$sql_where[] = 'menu_choice = %s';
				$sql_args[]  = $filter_menu;
			}

			$where_sql = '';
			if ( ! empty( $sql_where ) ) {
				$where_sql = ' WHERE ' . implode( ' AND ', $sql_where );
			}

			$orderby_safe = in_array( $orderby, $allowed_orders, true ) ? $orderby : 'party_id';
			$order_dir    = ( 'DESC' === $order ) ? 'DESC' : 'ASC';
			if ( $group_export ) {
				$order_sql = ' ORDER BY party_id ASC, guest_name ASC';
			} else {
				$order_sql = ' ORDER BY `' . str_replace( '`', '', $orderby_safe ) . '` ' . $order_dir;
			}

			$cache_group = 'wedding_rsvp';
			$cache_gen   = (int) get_option( 'wgrsvp_csv_cache_generation', 1 );
			$cache_key   = 'wgrsvp_csv_guests_' . $cache_gen . '_' . md5(
				wp_json_encode(
					array(
						'table'   => $this->table_name,
						'where'   => $where_sql,
						'order'   => $order_sql,
						'args'    => $sql_args,
						'search'  => $search_query,
						'fst'     => $filter_status,
						'fmenu'   => $filter_menu,
						'orderby' => $orderby_safe,
						'grp'     => $group_export,
					)
				)
			);

			$cached_guests = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached_guests && is_array( $cached_guests ) ) {
				$guests = $cached_guests;
			} else {
				$prepare_args = array_merge( array( $this->table_name ), $sql_args );
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where_sql / $order_sql: fixed %s fragments + whitelist identifiers only; see handle_csv_export.
				$guests = $this->wgrsvp_query_cache_get_results(
					'SELECT * FROM %i' . $where_sql . $order_sql,
					$prepare_args,
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

				wp_cache_set( $cache_key, $guests, $cache_group, 2 * MINUTE_IN_SECONDS );
			}
			header( 'Content-Type: text/csv' );
			header( 'Content-Disposition: attachment; filename="wedding-rsvp-export.csv"' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$f = fopen( 'php://output', 'w' );
			fputcsv(
				$f,
				array(
					__( 'Party ID', 'wedding-party-rsvp' ),
					__( 'Name', 'wedding-party-rsvp' ),
					__( 'Child', 'wedding-party-rsvp' ),
					__( 'Table', 'wedding-party-rsvp' ),
					__( 'RSVP', 'wedding-party-rsvp' ),
					__( 'Menu', 'wedding-party-rsvp' ),
					__( 'Child Menu', 'wedding-party-rsvp' ),
					__( 'Appetizer', 'wedding-party-rsvp' ),
					__( 'Hors', 'wedding-party-rsvp' ),
					__( 'Dietary', 'wedding-party-rsvp' ),
					__( 'Allergies', 'wedding-party-rsvp' ),
					__( 'Song', 'wedding-party-rsvp' ),
					__( 'Message', 'wedding-party-rsvp' ),
					__( 'Notes', 'wedding-party-rsvp' ),
					__( 'Email', 'wedding-party-rsvp' ),
					__( 'Phone', 'wedding-party-rsvp' ),
				)
			);
			foreach ( $guests as $r ) {
				fputcsv(
					$f,
					array(
						$r['party_id'],
						$r['guest_name'],
						$r['is_child'],
						$r['table_number'],
						$r['rsvp_status'],
						$r['menu_choice'],
						$r['child_menu_choice'],
						$r['appetizer_choice'],
						$r['hors_doeuvre_choice'],
						$r['dietary_restrictions'],
						$r['allergies'],
						$r['song_request'],
						$r['guest_message'],
						$r['admin_notes'],
						$r['email'],
						$r['phone'],
					)
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $f );
			exit;
		}
		/**
		 * Imports Party ID, Name, Email, Phone columns from a CSV upload (header row skipped).
		 *
		 * @param string $csv_filepath Absolute path to the uploaded temp file.
		 * @return void
		 */
		private function handle_csv_import( $csv_filepath ) {
			if ( ! empty( $csv_filepath ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				$file = fopen( $csv_filepath, 'r' );
				if ( false !== $file ) {
					wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );
					fgetcsv( $file ); // Skip header
					while ( ( $row = fgetcsv( $file ) ) !== false ) {
						if ( isset( $row[0] ) ) {

							// Removed Guest Limit Check

							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP insert; clear_stats_cache() busts query object cache after import.
							$wpdb->insert(
								$this->table_name,
								array(
									'party_id'   => sanitize_text_field( $row[0] ),
									'guest_name' => sanitize_text_field( $row[1] ),
									'email'      => isset( $row[2] ) ? sanitize_email( $row[2] ) : '',
									'phone'      => isset( $row[3] ) ? sanitize_text_field( $row[3] ) : '',
									// is_child removed (Pro)
								)
							);
						}
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $file );
					$this->clear_stats_cache(); // Clear cache on import
				}
			}
		}

		/**
		 * Classic (non-AJAX) RSVP form POST: verifies nonce, updates guest rows, redirects with success or redirect URL.
		 *
		 * @return void
		 */
		public function process_frontend_submissions() {

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				return;
			}

			// Licensed Pro registers [wedding_rsvp_form] and verifies `wpr_pro_front_rsvp_submit`. This handler expects `wgrsvp_front_rsvp_submit`; running both would reject valid Pro submissions on init before the shortcode runs.
			if ( function_exists( 'wgrsvp_is_pro_plugin_active' ) && wgrsvp_is_pro_plugin_active() && wgrsvp_is_pro_license_effectively_valid() ) {
				return;
			}

			$request_method = 'GET';
			if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
				$request_method = strtoupper( (string) sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
			}
			if ( 'POST' !== $request_method ) {
				return;
			}

			if ( ! isset( $_POST['wpr_submit_rsvp'] ) ) {
				return;
			}

			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wgrsvp_front_rsvp_submit' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'RSVP', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
			}

			$party_id = isset( $_POST['party_id'] ) ? sanitize_text_field( wp_unslash( $_POST['party_id'] ) ) : '';

			// Honeypot (must be empty).
			$honey = isset( $_POST['wpr_honey'] ) ? sanitize_text_field( wp_unslash( $_POST['wpr_honey'] ) ) : '';
			if ( '' !== $honey ) {
				return;
			}

			$this->save_rsvp_guest_updates_for_party( $party_id );

			$settings = get_option( $this->opt_settings, array() );
			if ( ! empty( $settings['redirect_url'] ) ) {
				wp_safe_redirect( $settings['redirect_url'] );
				exit;
			}

			set_transient( 'wgrsvp_success_msg', '1', 60 );
			wp_safe_redirect( remove_query_arg( 'wpr_submit_rsvp' ) );
			exit;
		}

		/**
		 * Placeholder map for future email/SMS integrations.
		 *
		 * @param object $guest Guest row from the database.
		 * @return array<string,string>
		 */
		private function get_replacement_tags( $guest ) {
			$gen_settings = get_option( $this->opt_settings, array() );
			$base_url     = ! empty( $gen_settings['rsvp_page_url'] ) ? $gen_settings['rsvp_page_url'] : home_url( '/' );

			$rsvp_link = add_query_arg( 'party_id', rawurlencode( (string) $guest->party_id ), $base_url );

			return array(
				'{name}'      => $guest->guest_name,
				'{party_id}'  => $guest->party_id,
				'{rsvp_link}' => $rsvp_link,
			);
		}

		/**
		 * Shortcode output: party lookup, RSVP form, deadline / success states.
		 *
		 * @return string HTML.
		 */
		public function render_frontend_form() {
			global $wpdb;
			$settings = get_option( $this->opt_settings, array() );

			if ( ! empty( $settings['deadline_date'] ) && current_time( 'Y-m-d' ) > $settings['deadline_date'] ) {
				$closed_msg = isset( $settings['deadline_closed_message'] ) ? trim( (string) $settings['deadline_closed_message'] ) : '';
				if ( '' !== $closed_msg ) {
					return '<div class="wpr-wrapper"><div class="wpr-guest-card wgrsvp-rsvp-closed-message">' . wp_kses_post( wpautop( $closed_msg ) ) . '</div></div>';
				}
				return '<div class="wpr-wrapper"><div class="wpr-guest-card" style="text-align:center;color:red;"><h3>' . esc_html__( 'RSVPs are now Closed', 'wedding-party-rsvp' ) . '</h3><p>' . esc_html__( 'Please contact the couple directly.', 'wedding-party-rsvp' ) . '</p></div></div>';
			}

			// Check for Success Message from Init Redirect
			if ( get_transient( 'wgrsvp_success_msg' ) ) {
				delete_transient( 'wgrsvp_success_msg' );
				return '<div class="wpr-wrapper"><div style="color:green;border:1px solid green;padding:15px;margin-bottom:20px;background:#eaffea;">' . esc_html__( 'Thank you! Your RSVP has been updated.', 'wedding-party-rsvp' ) . '</div></div>';
			}

			$output = '<div class="wpr-wrapper">';

			// --- LOGIN FORM CHECK ---
			$party_id = '';

			// 1. Check POST Login (verify nonce before reading party ID).
			if ( isset( $_POST['wgrsvp_front_party_login_nonce'] ) ) {
				if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_front_party_login_nonce'] ) ), 'wgrsvp_front_party_login' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'RSVP', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
				}
				if ( isset( $_POST['wgrsvp_front_party_login_submit'] ) && isset( $_POST['wpr_party_id'] ) ) {
					$party_id = sanitize_text_field( wp_unslash( $_POST['wpr_party_id'] ) );
				}
			}

			// 2. Check URL Param
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( empty( $party_id ) && isset( $_GET['party_id'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$party_id = sanitize_text_field( wp_unslash( $_GET['party_id'] ) );
			}

			// 3. Query Guests (SQL Safe)
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$guests = $party_id ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE party_id = %s", $party_id ) ) : array();

			if ( empty( $guests ) ) {
				$output .= '<form method="post">';
				$output .= wp_nonce_field( 'wgrsvp_front_party_login', 'wgrsvp_front_party_login_nonce', true, false );
				$output .= '<div class="wpr-field"><label>' . esc_html__( 'Party ID:', 'wedding-party-rsvp' ) . '</label><input type="text" name="wpr_party_id" required></div><button name="wgrsvp_front_party_login_submit" class="wpr-button">' . esc_html__( 'Find Invitation', 'wedding-party-rsvp' ) . '</button></form>';
			} else {
				$use_ia = $this->enqueue_rsvp_interactivity_module();

				$menus_adult = get_option( $this->opt_menu_adult, array() );
				/* translators: %s: Party ID / invitation code. */
				$welcome_title = ! empty( $settings['welcome_title'] ) ? stripslashes( $settings['welcome_title'] ) : sprintf( __( 'Party: %s', 'wedding-party-rsvp' ), $party_id );

				if ( $use_ia ) {
					$ctx_json = wp_json_encode(
						$this->get_rsvp_interactivity_context(),
						JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
					);
					$output  .= '<div class="wgrsvp-rsvp-interactive" data-wp-interactive="wedding-party-rsvp/rsvp" data-wp-context="' . esc_attr( $ctx_json ) . '">';
					$output  .= '<div class="wgrsvp-rsvp-feedback" role="status" aria-live="polite"><span data-wp-text="state.feedback"></span></div>';
				}

				$form_open = '<form method="post"';
				if ( $use_ia ) {
					$form_open .= ' data-wp-on--submit="actions.submitRsvp"';
					$form_open .= ' data-wp-bind--inert="state.isSubmitting"';
				}
				$form_open .= '>';

				$output .= $form_open . wp_nonce_field( 'wgrsvp_front_rsvp_submit', '_wpnonce', true, false ) . '<input type="hidden" name="party_id" value="' . esc_attr( $party_id ) . '">';
				$output .= '<h2>' . esc_html( $welcome_title ) . '</h2>';
				$output .= '<input type="text" name="wpr_honey" class="wpr-honey" autocomplete="off" tabindex="-1">';

				foreach ( $guests as $g ) {
					$output        .= '<div class="wpr-guest-card">';
					$is_placeholder = in_array( strtolower( $g->guest_name ), array( 'guest', 'plus one', '+1' ), true );
					if ( $is_placeholder ) {
						$output .= '<div class="wpr-field"><label>' . esc_html__( 'Guest Name:', 'wedding-party-rsvp' ) . '</label><input type="text" name="guest[' . absint( $g->id ) . '][name_edit]" value="' . esc_attr( $g->guest_name ) . '"></div>';
					} else {
						$output .= '<h3>' . esc_html( $g->guest_name ) . '</h3>';
						$output .= '<input type="hidden" name="guest[' . absint( $g->id ) . '][name_hidden]" value="' . esc_attr( $g->guest_name ) . '">';
					}

					// Table Display Removed (Pro)

					$output .= '<div class="wpr-field"><label>' . esc_html__( 'Attending?', 'wedding-party-rsvp' ) . '</label><select name="guest[' . absint( $g->id ) . '][rsvp]" required>';
					$output .= '<option value="Pending" ' . selected( $g->rsvp_status, 'Pending', false ) . '>' . esc_html__( 'Select...', 'wedding-party-rsvp' ) . '</option>';
					$output .= '<option value="Accepted" ' . selected( $g->rsvp_status, 'Accepted', false ) . '>' . esc_html__( 'Delighted to attend', 'wedding-party-rsvp' ) . '</option>';
					$output .= '<option value="Declined" ' . selected( $g->rsvp_status, 'Declined', false ) . '>' . esc_html__( 'Unable to attend', 'wedding-party-rsvp' ) . '</option></select></div>';

					// Only render Adult Menu in Free Version
					$output .= '<div class="wpr-field"><label>' . esc_html__( 'Entrée', 'wedding-party-rsvp' ) . '</label><select name="guest[' . absint( $g->id ) . '][menu]"><option value="">' . esc_html__( 'Select...', 'wedding-party-rsvp' ) . '</option>';
					foreach ( $menus_adult as $m ) {
						$output .= '<option value="' . esc_attr( $m ) . '" ' . selected( $g->menu_choice, $m, false ) . '>' . esc_html( $m ) . '</option>';
					}
					$output .= '</select></div>';

					$output         .= '<div class="wpr-field"><label>' . esc_html__( 'Dietary Restrictions', 'wedding-party-rsvp' ) . '</label>';
					$allergy_options = array(
						'Gluten Free' => __( 'Gluten Free', 'wedding-party-rsvp' ),
						'Dairy Free'  => __( 'Dairy Free', 'wedding-party-rsvp' ),
						'Vegetarian'  => __( 'Vegetarian', 'wedding-party-rsvp' ),
						'Vegan'       => __( 'Vegan', 'wedding-party-rsvp' ),
						'Nut Allergy' => __( 'Nut Allergy', 'wedding-party-rsvp' ),
					);
					$saved_allergies = explode( ', ', $g->allergies );
					$output         .= '<div class="wpr-checkbox-group">';
					foreach ( $allergy_options as $allergy_key => $allergy_label ) {
						$output .= '<label><input type="checkbox" name="guest[' . absint( $g->id ) . '][allergies][]" value="' . esc_attr( $allergy_key ) . '" ' . checked( in_array( $allergy_key, $saved_allergies, true ), true, false ) . '> ' . esc_html( $allergy_label ) . '</label>';
					}
					$output .= '</div><input type="text" name="guest[' . absint( $g->id ) . '][dietary]" value="' . esc_attr( $g->dietary_restrictions ) . '" placeholder="' . esc_attr__( 'Other...', 'wedding-party-rsvp' ) . '"></div>';

					$output .= '<div class="wpr-field"><label>' . esc_html__( 'I promise to dance if you play:', 'wedding-party-rsvp' ) . '</label><input type="text" name="guest[' . absint( $g->id ) . '][song]" value="' . esc_attr( $g->song_request ) . '"></div>';

					$output .= '<div class="wpr-field"><label>' . esc_html__( 'Message to Couple:', 'wedding-party-rsvp' ) . '</label><textarea name="guest[' . absint( $g->id ) . '][message]" rows="2" placeholder="' . esc_attr__( 'Note to the bride & groom…', 'wedding-party-rsvp' ) . '">' . esc_textarea( $g->guest_message ) . '</textarea></div>';

					$output .= '<div class="wpr-field"><label>' . esc_html__( 'Email', 'wedding-party-rsvp' ) . '</label><input type="email" name="guest[' . absint( $g->id ) . '][email]" value="' . esc_attr( $g->email ) . '"></div>';
					if ( empty( $settings['hide_phone'] ) ) {
						$output .= '<div class="wpr-field"><label>' . esc_html__( 'Phone', 'wedding-party-rsvp' ) . '</label><input type="text" name="guest[' . absint( $g->id ) . '][phone]" value="' . esc_attr( $g->phone ) . '"></div>';
					}
					$output .= '<div class="wpr-field"><label>' . esc_html__( 'Mailing Address', 'wedding-party-rsvp' ) . '</label><textarea name="guest[' . absint( $g->id ) . '][address]">' . esc_textarea( $g->address ) . '</textarea></div>';

					$output .= '</div>';
				}
				if ( $use_ia ) {
					$output .= '<button type="submit" name="wpr_submit_rsvp" class="wpr-button wgrsvp-rsvp-submit" value="1" data-wp-bind--disabled="state.isSubmitting" data-wp-bind--aria-busy="state.isSubmitting" data-wp-class--wgrsvp-is-busy="state.isSubmitting">' . esc_html__( 'Submit RSVP', 'wedding-party-rsvp' ) . '</button></form>';
				} else {
					$output .= '<button type="submit" name="wpr_submit_rsvp" class="wpr-button" value="1">' . esc_html__( 'Submit RSVP', 'wedding-party-rsvp' ) . '</button></form>';
				}

				if ( $use_ia ) {
					$output .= '</div>';
				}
			}
			return $output . '</div>';
		}

		/**
		 * Email invites admin page (Pro upsell / redirect when Pro manages communications).
		 *
		 * @return void
		 */
		public function admin_page_email() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}
			if ( $this->should_redirect_to_pro_communications_screen() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-comm' ) );
				exit;
			}
			if ( wgrsvp_is_pro_plugin_active() ) {
				?>
				<div class="wrap">
					<h1><?php esc_html_e( 'Email Invites', 'wedding-party-rsvp' ); ?></h1>
					<p><?php esc_html_e( 'Email invitations are configured in Wedding Party RSVP Pro.', 'wedding-party-rsvp' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-settings' ) ); ?>"><?php esc_html_e( 'Open Pro settings', 'wedding-party-rsvp' ); ?></a>
						<?php if ( wgrsvp_is_pro_license_effectively_valid() ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-comm' ) ); ?>"><?php esc_html_e( 'Email & SMS', 'wedding-party-rsvp' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
				<?php
				return;
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Email Invites', 'wedding-party-rsvp' ); ?></h1>
				<div style="background:#fff; border:1px solid #ccc; padding:30px; text-align:center; max-width:600px; margin-top:20px;">
					<h2><?php esc_html_e( 'Send Invites Directly', 'wedding-party-rsvp' ); ?></h2>
					<p><?php esc_html_e( 'The Pro version includes a complete Email Invitation system. Send customized invites to your guests with one click.', 'wedding-party-rsvp' ); ?></p>
					<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" class="button button-primary button-large"><?php esc_html_e( 'Upgrade to Pro', 'wedding-party-rsvp' ); ?></a>
				</div>
			</div>
			<?php
		}

		/**
		 * SMS admin page (Pro upsell / redirect when Pro manages communications).
		 *
		 * @return void
		 */
		public function admin_page_sms() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}
			if ( $this->should_redirect_to_pro_communications_screen() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-comm' ) );
				exit;
			}
			if ( wgrsvp_is_pro_plugin_active() ) {
				?>
				<div class="wrap">
					<h1><?php esc_html_e( 'SMS Invites', 'wedding-party-rsvp' ); ?></h1>
					<p><?php esc_html_e( 'SMS invitations are configured in Wedding Party RSVP Pro (Twilio).', 'wedding-party-rsvp' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-settings' ) ); ?>"><?php esc_html_e( 'Open Pro settings', 'wedding-party-rsvp' ); ?></a>
						<?php if ( wgrsvp_is_pro_license_effectively_valid() ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-comm' ) ); ?>"><?php esc_html_e( 'Email & SMS', 'wedding-party-rsvp' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
				<?php
				return;
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'SMS Invites', 'wedding-party-rsvp' ); ?></h1>
				<div style="background:#fff; border:1px solid #ccc; padding:30px; text-align:center; max-width:600px; margin-top:20px;">
					<h2><?php esc_html_e( 'Text Your Guests', 'wedding-party-rsvp' ); ?></h2>
					<p><?php esc_html_e( 'Upgrade to the Pro version to integrate with Twilio and send SMS invitations directly to your guest list.', 'wedding-party-rsvp' ); ?></p>
					<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" class="button button-primary button-large"><?php esc_html_e( 'Upgrade to Pro', 'wedding-party-rsvp' ); ?></a>
				</div>
			</div>
			<?php
		}
	}

	new WGRSVP_Wedding_RSVP();

	// Load and run review request (admin only, after 7 days).
	add_action(
		'admin_init',
		function () {
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-review-request.php';
			new WGRSVP_Review_Request();
		}
	);

endif;
