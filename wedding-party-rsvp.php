<?php
/*
Plugin Name: Wedding Party RSVP – Guest List, Invitation & Event Manager
Description: Simple and secure RSVP system. Manage guest lists and adult meal choices.
Version: 8.0.6
Author: Land Tech Web Designs, Corp
Author URI: https://landtechwebdesigns.com
Plugin URI: https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wedding-party-rsvp
Domain Path: /languages
*/

/**
 * Guest list, public RSVP form shortcode, admin tools, CSV, privacy, and coordinator role.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WGRSVP_PLUGIN_FILE' ) ) {
	define( 'WGRSVP_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WGRSVP_PLUGIN_DIR' ) ) {
	define( 'WGRSVP_PLUGIN_DIR', plugin_dir_path( WGRSVP_PLUGIN_FILE ) );
}

require_once WGRSVP_PLUGIN_DIR . 'includes/wgrsvp-admin-modules.php';
require_once WGRSVP_PLUGIN_DIR . 'includes/class-wgrsvp-gift-registries.php';

if ( ! function_exists( 'wgrsvp_set_script_translations' ) ) {
	/**
	 * Map JSON language packs to a script handle (`wp i18n make-json`).
	 *
	 * @param string $handle Registered script or script module handle.
	 * @return void
	 */
	function wgrsvp_set_script_translations( $handle ) {
		if ( ! function_exists( 'wp_set_script_translations' ) ) {
			return;
		}
		wp_set_script_translations( $handle, 'wedding-party-rsvp', WGRSVP_PLUGIN_DIR . 'languages' );
	}
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

if ( ! function_exists( 'wgrsvp_sanitize_redirect_url_setting' ) ) {
	/**
	 * Sanitize "Redirect URL" for storage (full http(s) URL or site-relative path expanded with home_url).
	 *
	 * @param mixed $raw Raw posted value.
	 * @return string
	 */
	function wgrsvp_sanitize_redirect_url_setting( $raw ) {
		$raw = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $raw ) ) {
			return esc_url_raw( $raw );
		}
		// Root-relative path (not protocol-relative //).
		if ( strlen( $raw ) > 0 && '/' === $raw[0] && ( strlen( $raw ) < 2 || '/' !== $raw[1] ) ) {
			return esc_url_raw( home_url( $raw ) );
		}

		return esc_url_raw( $raw );
	}
}

if ( ! function_exists( 'wgrsvp_resolve_stored_redirect_url' ) ) {
	/**
	 * Resolve stored redirect URL for browser/server redirects (same rules as Pro frontend).
	 *
	 * @param string $stored Value from wgrsvp_general_settings['redirect_url'].
	 * @return string Empty if unusable.
	 */
	function wgrsvp_resolve_stored_redirect_url( $stored ) {
		$stored = trim( (string) $stored );
		if ( '' === $stored ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $stored ) ) {
			return esc_url_raw( $stored );
		}
		if ( strlen( $stored ) > 0 && '/' === $stored[0] && ( strlen( $stored ) < 2 || '/' !== $stored[1] ) ) {
			return esc_url_raw( home_url( $stored ) );
		}
		$might = esc_url_raw( $stored );
		if ( '' !== $might ) {
			return $might;
		}

		return esc_url_raw( home_url( '/' . ltrim( $stored, '/' ) ) );
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

if ( ! function_exists( 'wgrsvp_get_pro_live_demo_url' ) ) {
	/**
	 * InstaWP launch URL for a temporary Wedding Party RSVP Pro demo site (browser only).
	 *
	 * @return string Escaped URL.
	 */
	function wgrsvp_get_pro_live_demo_url() {
		$default = 'https://app.instawp.io/launch?s=wedding-rsvp-pro-demo&d=v2';
		/**
		 * URL for the hosted Pro live demo (InstaWP or successor).
		 *
		 * @param string $default Default InstaWP launch URL.
		 */
		return esc_url_raw( (string) apply_filters( 'wgrsvp_pro_live_demo_url', $default ) );
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
		 * Short-lived flash after classic (non-AJAX) RSVP submit on the frontend.
		 */
		private const TRANSIENT_RSVP_FORM_SUCCESS_FLASH = 'wgrsvp_rsvp_form_success_flash';

		/**
		 * Option incremented when guest data changes so object-cache keys for read queries miss.
		 */
		private const OPTION_QUERY_CACHE_GEN = 'wgrsvp_query_cache_generation';

		/**
		 * Stored guest table schema revision (columns added via dbDelta on upgrade).
		 */
		private const OPT_WEDDING_RSVPS_SCHEMA = 'wgrsvp_wedding_rsvps_schema';

		/**
		 * Current `wedding_rsvps` table schema version (bump when adding columns).
		 */
		private const WEDDING_RSVPS_SCHEMA_VERSION = 3;

		/**
		 * Registers hooks, loads dependency classes, and boots the setup wizard and coordinator role.
		 *
		 * @return void
		 */
		public function __construct() {
			global $wpdb;
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-coordinator-role.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-setup-wizard.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-ics.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-paste-import.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-thankyou-tracker.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-gifts-report.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-deadline-nudges.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-caterer-portal.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-client-summary-portal.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-ops-center.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-audit-trail.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-growth-checklist.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-guest-health.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-vendor-packet.php';
			WGRSVP_ICS::init_hooks();
			WGRSVP_ThankYou_Tracker::register_hooks();
			WGRSVP_Gifts_Report::register_hooks();
			if ( class_exists( 'WGRSVP_Deadline_Nudges', false ) ) {
				WGRSVP_Deadline_Nudges::register_hooks();
			}
			if ( class_exists( 'WGRSVP_Caterer_Portal', false ) ) {
				WGRSVP_Caterer_Portal::register_hooks();
			}
			if ( class_exists( 'WGRSVP_Client_Summary_Portal', false ) ) {
				WGRSVP_Client_Summary_Portal::register_hooks();
			}
			if ( class_exists( 'WGRSVP_Ops_Center', false ) ) {
				WGRSVP_Ops_Center::register_hooks();
			}
			if ( class_exists( 'WGRSVP_Audit_Trail', false ) ) {
				WGRSVP_Audit_Trail::register_hooks();
			}

			$this->table_name = $wpdb->prefix . 'wedding_rsvps';

			add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_wedding_rsvps_table' ), 5 );
			add_action( 'wgrsvp_invalidate_guest_caches', array( $this, 'wgrsvp_invalidate_guest_caches' ) );

			register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
			register_deactivation_hook( __FILE__, array( 'WGRSVP_Coordinator_Role', 'remove_on_deactivation' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate_deadline_nudges_cron' ) );

			// Init hook for form processing (redirects).
			add_action( 'init', array( $this, 'process_frontend_submissions' ) );

			add_action( 'wp_ajax_wgrsvp_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
			add_action( 'wp_ajax_nopriv_wgrsvp_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
			add_action( 'wp_ajax_wgrsvp_preview_paste_import', array( $this, 'ajax_preview_paste_import' ) );
			add_action( 'wp_ajax_wgrsvp_ai_wording', array( $this, 'ajax_ai_wording' ) );

			add_action( 'wp_loaded', array( $this, 'maybe_redirect_legacy_wedding_rsvp_admin_slug' ), 0 );
			add_action( 'admin_menu', array( $this, 'create_admin_menu' ) );
			add_action( 'admin_menu', array( $this, 'maybe_remove_redundant_comm_submenus' ), 999 );
			add_shortcode( 'wedding_rsvp_form', array( $this, 'render_frontend_form' ) );
			add_shortcode( 'wgrsvp_guest_hub', array( $this, 'render_guest_hub_shortcode' ) );
			add_action( 'admin_post_wgrsvp_save_guest_list_segment', array( $this, 'handle_save_guest_list_segment' ) );
			add_action( 'admin_action_wgrsvp_delete_guest_list_segment', array( $this, 'handle_delete_guest_list_segment' ) );
			add_action( 'admin_post_wgrsvp_dayof_arrival', array( $this, 'handle_dayof_arrival' ) );
			add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
			add_action( 'admin_init', array( $this, 'handle_checkin_pdf_export' ) );
			add_action( 'admin_init', array( $this, 'handle_catering_summary_csv_export' ) );
			add_action( 'admin_init', array( $this, 'handle_catering_summary_pdf_export' ) );

			// Load CSS.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin_ui_script' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_paste_import_script' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_pro_teaser_assets' ), 20, 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_settings_ai_wording_assets' ), 21, 1 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );

			// Growth / onboarding (admin).
			add_action( 'admin_init', array( $this, 'maybe_handle_growth_dismiss' ), 1 );
			add_action( 'admin_notices', array( $this, 'render_growth_admin_notices' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'filter_plugin_action_links' ) );
			add_filter( 'plugin_row_meta', array( $this, 'filter_plugin_row_meta' ), 10, 2 );
			add_action( 'wp_dashboard_setup', array( $this, 'maybe_register_dashboard_widget' ) );
			add_action( 'init', array( $this, 'register_block_patterns' ), 9 );
			add_action( 'init', array( $this, 'register_rsvp_form_block' ), 11 );
			add_action( 'init', array( $this, 'register_guest_hub_block' ), 12 );
			add_action( 'init', array( 'WGRSVP_ThankYou_Tracker', 'register_block' ), 11 );
			add_action( 'rest_api_init', array( $this, 'register_party_preview_rest_route' ) );
			add_action( 'rest_api_init', array( $this, 'register_admin_guest_rows_rest_route' ) );
			add_action( 'admin_init', array( $this, 'load_privacy_exporters' ), 5 );

			add_action( 'wgrsvp_after_guest_privacy_erase', array( $this, 'bust_stats_cache_after_privacy_erase' ) );

			// Expose instance for Wedding Party RSVP Pro (merged admin menu when both active).
			$GLOBALS['wgrsvp_wedding_rsvp_instance'] = $this;

			$wizard = new WGRSVP_Setup_Wizard( $this );
			$wizard->init();

			WGRSVP_Growth_Checklist::init();
			WGRSVP_Guest_Health::register_hooks();
			WGRSVP_Vendor_Packet::register_hooks();

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
				'8.0.6'
			);
			wgrsvp_set_script_translations( 'wgrsvp-rsvp-interactivity' );

			return true;
		}

		/**
		 * Enqueue party-ID lookup hints module (login step).
		 *
		 * @return bool
		 */
		private function enqueue_party_lookup_interactivity_module() {
			if ( ! $this->interactivity_module_available() ) {
				return false;
			}

			wp_enqueue_script_module(
				'wgrsvp-party-lookup-interactivity',
				plugins_url( 'assets/js/party-lookup-interactivity.js', __FILE__ ),
				array( '@wordpress/interactivity' ),
				'8.0.6'
			);
			wgrsvp_set_script_translations( 'wgrsvp-party-lookup-interactivity' );

			return true;
		}

		/**
		 * Context for party lookup preview (REST is rate-limited; see wgrsvp_party_preview_rate_limit_max).
		 *
		 * @return array<string,mixed>
		 */
		private function get_party_lookup_interactivity_context() {
			return array(
				'restUrl' => rest_url( 'wgrsvp/v1/party-preview' ),
				'i18n'    => array(
					'loading'      => __( 'Checking invitation…', 'wedding-party-rsvp' ),
					'notFound'     => __( 'No invitation matches that Party ID yet.', 'wedding-party-rsvp' ),
					/* translators: 1: guest count, 2: comma-separated first names. */
					'foundSummary' => __( 'Found %1$d guest(s) on this invitation. First names: %2$s', 'wedding-party-rsvp' ),
					'rateLimited'  => __( 'Too many checks. Please wait a moment and try again.', 'wedding-party-rsvp' ),
				),
			);
		}

		/**
		 * Register public party-preview REST route (unauthenticated; IP rate-limited in callback).
		 *
		 * Party ID is sanitized via `sanitize_text_field` in route args. No session nonce: endpoint is
		 * read-only and bounded. For stricter setups, use `wgrsvp_party_preview_rate_limit_max` or
		 * remove the route via `rest_endpoints` filter.
		 *
		 * @since 7.3.12
		 * @return void
		 */
		public function register_party_preview_rest_route() {
			register_rest_route(
				'wgrsvp/v1',
				'/party-preview',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_serve_party_preview' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'party_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);
		}

		/**
		 * Register dynamic block: same output as [wedding_rsvp_form]; supports editor visibility when available.
		 *
		 * @return void
		 */
		public function register_rsvp_form_block() {
			if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
				return;
			}
			$block_dir = plugin_dir_path( __FILE__ ) . 'blocks/rsvp';
			if ( ! is_readable( $block_dir . '/block.json' ) ) {
				return;
			}
			register_block_type_from_metadata( $block_dir );
		}

		/**
		 * Register Guest Hub block (thank-you summary; same output as [wgrsvp_guest_hub]).
		 *
		 * @return void
		 */
		public function register_guest_hub_block() {
			if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
				return;
			}
			$block_dir = plugin_dir_path( __FILE__ ) . 'blocks/guest-hub';
			if ( ! is_readable( $block_dir . '/block.json' ) ) {
				return;
			}
			register_block_type_from_metadata( $block_dir );
		}

		/**
		 * Register admin-only guest-rows REST route (coordinator or equivalent capability).
		 *
		 * @since 7.3.17
		 * @return void
		 */
		public function register_admin_guest_rows_rest_route() {
			register_rest_route(
				'wgrsvp/v1',
				'/guest-rows',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get_admin_guest_rows' ),
					'permission_callback' => array( $this, 'rest_permission_admin_guest_rows' ),
					'args'                => array(
						'page'             => array(
							'default'           => 1,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'per_page'         => array(
							'default'           => 50,
							'type'              => 'integer',
							'sanitize_callback' => static function ( $v ) {
								return min( 100, max( 1, absint( $v ) ) );
							},
						),
						'search'           => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'rsvp_status'      => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => static function ( $v ) {
								$v = sanitize_text_field( (string) $v );
								$allowed = array( 'Pending', 'Accepted', 'Declined' );
								return in_array( $v, $allowed, true ) ? $v : '';
							},
						),
						'orderby'          => array(
							'default'           => 'id',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'order'            => array(
							'default'           => 'asc',
							'type'              => 'string',
							'sanitize_callback' => static function ( $v ) {
								$v = strtolower( sanitize_text_field( (string) $v ) );
								return 'desc' === $v ? 'desc' : 'asc';
							},
						),
						'menu_choice'      => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'dietary_contains' => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'allergy_contains' => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'has_table'        => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => static function ( $v ) {
								$v = sanitize_text_field( (string) $v );
								return '1' === $v ? '1' : '';
							},
						),
						'wpr_attended'     => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => static function ( $v ) {
								$v = sanitize_text_field( (string) $v );
								if ( '1' === $v || '0' === $v ) {
									return $v;
								}
								return '';
							},
						),
						'wpr_planner_tag'  => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => static function ( $v ) {
								return sanitize_key( sanitize_text_field( (string) $v ) );
							},
						),
					),
				)
			);
		}

		/**
		 * Whether the current user may read the admin guest-rows REST endpoint.
		 *
		 * @return bool
		 */
		public function rest_permission_admin_guest_rows() {
			return current_user_can( WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD );
		}

		/**
		 * Return a paginated slice of guest rows for the DataViews spike (read-only).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public function rest_get_admin_guest_rows( $request ) {
			global $wpdb;
			$page        = max( 1, (int) $request->get_param( 'page' ) );
			$per         = (int) $request->get_param( 'per_page' );
			$search      = $request->get_param( 'search' );
			$search      = is_string( $search ) ? trim( $search ) : '';
			$rsvp_filter = $request->get_param( 'rsvp_status' );
			$rsvp_filter = is_string( $rsvp_filter ) ? $rsvp_filter : '';
			$orderby_key = $request->get_param( 'orderby' );
			$orderby_key = is_string( $orderby_key ) ? $orderby_key : 'id';
			$order_low   = $request->get_param( 'order' );
			$order_low   = is_string( $order_low ) ? $order_low : 'asc';

			/**
			 * Extend guest-rows REST ORDER BY allowlist (keys must map to real columns).
			 *
			 * @since 7.3.17
			 * @param array<string, string> $order_map Key from request `orderby` => SQL column name.
			 */
			$order_map = apply_filters( 'wgrsvp_guest_rows_rest_order_by_map', $this->wgrsvp_get_admin_guest_list_order_by_map() );
			if ( ! is_array( $order_map ) ) {
				$order_map = $this->wgrsvp_get_admin_guest_list_order_by_map();
			}
			$order_column = isset( $order_map[ $orderby_key ] ) ? $order_map[ $orderby_key ] : 'id';
			$order_dir    = ( 'desc' === strtolower( $order_low ) ) ? 'DESC' : 'ASC';

			$offset = ( $page - 1 ) * $per;
			$table  = $this->table_name;

			$sql_where = array();
			$sql_args  = array();
			if ( '' !== $search ) {
				$sql_where[] = '(guest_name LIKE %s OR party_id LIKE %s OR email LIKE %s)';
				$like        = '%' . $wpdb->esc_like( $search ) . '%';
				$sql_args[]  = $like;
				$sql_args[]  = $like;
				$sql_args[]  = $like;
			}
			if ( '' !== $rsvp_filter ) {
				$sql_where[] = 'rsvp_status = %s';
				$sql_args[]  = $rsvp_filter;
			}
			$menu_f = $request->get_param( 'menu_choice' );
			$menu_f = is_string( $menu_f ) ? trim( $menu_f ) : '';
			if ( '' !== $menu_f ) {
				$sql_where[] = 'menu_choice = %s';
				$sql_args[]  = $menu_f;
			}
			$diet_sub = $request->get_param( 'dietary_contains' );
			$diet_sub = is_string( $diet_sub ) ? trim( $diet_sub ) : '';
			if ( '' !== $diet_sub ) {
				$sql_where[] = 'dietary_restrictions LIKE %s';
				$sql_args[]  = '%' . $wpdb->esc_like( $diet_sub ) . '%';
			}
			$all_sub = $request->get_param( 'allergy_contains' );
			$all_sub = is_string( $all_sub ) ? trim( $all_sub ) : '';
			if ( '' !== $all_sub ) {
				$sql_where[] = 'allergies LIKE %s';
				$sql_args[]  = '%' . $wpdb->esc_like( $all_sub ) . '%';
			}
			$has_table = $request->get_param( 'has_table' );
			$has_table = is_string( $has_table ) ? $has_table : '';
			if ( '1' === $has_table ) {
				$sql_where[] = "(TRIM(COALESCE(table_number, '')) <> '')";
			}
			$query_parts = array(
				'where' => $sql_where,
				'args'  => $sql_args,
			);
			/**
			 * Extend guest-rows REST WHERE clauses (e.g. Pro attended filter).
			 *
			 * @since 7.3.17
			 * @param array{where: string[], args: array<int, mixed>} $query_parts 'where' SQL fragments (no user input), 'args' for $wpdb->prepare.
			 * @param WP_REST_Request $request Request.
			 */
			$query_parts = apply_filters( 'wgrsvp_guest_rows_rest_query_parts', $query_parts, $request );
			if ( is_array( $query_parts ) && isset( $query_parts['where'], $query_parts['args'] ) && is_array( $query_parts['where'] ) && is_array( $query_parts['args'] ) ) {
				$sql_where = $query_parts['where'];
				$sql_args  = $query_parts['args'];
			}
			$where_sql = '';
			if ( ! empty( $sql_where ) ) {
				$where_sql = ' WHERE ' . implode( ' AND ', $sql_where );
			}

			$select_cols = 'id, party_id, guest_name, email, phone, rsvp_status, menu_choice, child_menu_choice, dietary_restrictions, allergies, table_number';
			/**
			 * Comma-separated column list for guest-rows SELECT (must stay allowlisted / trusted).
			 *
			 * @since 7.3.17
			 * @param string           $select_cols Base columns from core.
			 * @param WP_REST_Request $request      Request.
			 */
			$select_cols     = (string) apply_filters( 'wgrsvp_guest_rows_rest_select_columns', $select_cols, $request );
			$select_fallback = 'id, party_id, guest_name, email, phone, rsvp_status, menu_choice, child_menu_choice, dietary_restrictions, allergies, table_number';
			$select_cols     = $this->wgrsvp_sanitize_guest_rows_rest_select_columns( $select_cols, $select_fallback );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Guest-rows REST: %i + spread; WHERE from placeholders + allowlisted fragments; ORDER BY allowlisted.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i' . $where_sql,
					...array_merge( array( $table ), $sql_args )
				)
			);

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ' . $select_cols . ' FROM %i' . $where_sql . ' ORDER BY ' . $order_column . ' ' . $order_dir . ' LIMIT %d OFFSET %d',
					...array_merge( array( $table ), $sql_args, array( $per, $offset ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
			return new WP_REST_Response(
				array(
					'total'  => $total,
					'page'   => $page,
					'guests' => $rows,
				),
				200
			);
		}

		/**
		 * Serve party preview JSON for interactive “lookup invitation” UI.
		 *
		 * Applies per-IP transient rate limiting before querying the database. Returns only counts and
		 * up to three first-name tokens; no email or full PII.
		 *
		 * @since 7.3.12
		 * @param WP_REST_Request $request Request with `party_id`.
		 * @return WP_REST_Response|WP_Error JSON payload or 429 rate-limit error.
		 */
		public function rest_serve_party_preview( $request ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '0';
			if ( '' === $ip ) {
				$ip = '0';
			}
			$rl_key = 'wedding-party-rsvp_party_preview_rl_' . md5( $ip );
			$hits   = (int) get_transient( $rl_key );
			/**
			 * Max party-preview REST requests per IP per minute (anti-enumeration).
			 *
			 * @since 7.3.12
			 * @param int $max Default 40.
			 */
			$max = (int) apply_filters( 'wgrsvp_party_preview_rate_limit_max', 40 );
			if ( $max < 1 ) {
				$max = 40;
			}
			if ( $hits >= $max ) {
				return new WP_Error(
					'wgrsvp_rate_limited',
					__( 'Too many lookups. Try again in a minute.', 'wedding-party-rsvp' ),
					array( 'status' => 429 )
				);
			}
			set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

			$party_id = $request->get_param( 'party_id' );
			$party_id = is_string( $party_id ) ? trim( $party_id ) : '';
			if ( strlen( $party_id ) < 2 || strlen( $party_id ) > 50 ) {
				return new WP_REST_Response(
					array(
						'found'         => false,
						'guest_count'   => 0,
						'preview_names' => array(),
					),
					200
				);
			}
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9\-_]{0,49}$/', $party_id ) ) {
				return new WP_REST_Response(
					array(
						'found'         => false,
						'guest_count'   => 0,
						'preview_names' => array(),
					),
					200
				);
			}

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE party_id = %s', $this->table_name, $party_id ) );
			if ( $count < 1 ) {
				return new WP_REST_Response(
					array(
						'found'         => false,
						'guest_count'   => 0,
						'preview_names' => array(),
					),
					200
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$names = $wpdb->get_col( $wpdb->prepare( 'SELECT guest_name FROM %i WHERE party_id = %s ORDER BY id ASC LIMIT 5', $this->table_name, $party_id ) );
			if ( ! is_array( $names ) ) {
				$names = array();
			}

			$preview = array();
			foreach ( $names as $nm ) {
				$nm = sanitize_text_field( (string) $nm );
				if ( '' === $nm ) {
					continue;
				}
				$parts     = preg_split( '/\s+/u', $nm, 2 );
				$preview[] = ( isset( $parts[0] ) && '' !== $parts[0] ) ? $parts[0] : $nm;
				if ( count( $preview ) >= 3 ) {
					break;
				}
			}

			return new WP_REST_Response(
				array(
					'found'         => true,
					'guest_count'   => $count,
					'preview_names' => $preview,
				),
				200
			);
		}

		/**
		 * Initial data-wp-context payload for the interactive RSVP region.
		 *
		 * @return array<string,mixed>
		 */
		private function get_rsvp_interactivity_context( $party_id = '', $settings = null ) {
			if ( null === $settings ) {
				$settings = get_option( $this->opt_settings, array() );
			}
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			$party_id = sanitize_text_field( (string) $party_id );
			$gen_cal  = get_option( 'wgrsvp_general_settings', array() );
			$show_cal = ( '' !== $party_id && class_exists( 'WGRSVP_ICS' ) && WGRSVP_ICS::is_configured( is_array( $gen_cal ) ? $gen_cal : array() ) );
			$cal_url  = $show_cal ? WGRSVP_ICS::get_download_url( $party_id ) : '';

			return array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'showCalendar' => false,
				'calendarUrl'  => $cal_url,
				'i18n'         => array(
					'submitting'      => __( 'Sending…', 'wedding-party-rsvp' ),
					'success'         => __( 'Thank you! Your RSVP has been updated.', 'wedding-party-rsvp' ),
					'error'           => __( 'Could not save your RSVP. Please try again.', 'wedding-party-rsvp' ),
					'networkError'    => __( 'Network error. Please try again.', 'wedding-party-rsvp' ),
					'addToCalendar'   => __( 'Add to calendar', 'wedding-party-rsvp' ),
					'emailOk'         => __( 'Looks good', 'wedding-party-rsvp' ),
					'emailBad'        => __( 'Please check this email address', 'wedding-party-rsvp' ),
					'householdPrompt' => __( 'Your party has more than one invitation. If anyone is still marked Pending, you can complete their RSVP on this same page.', 'wedding-party-rsvp' ),
					'householdScroll' => __( 'Scroll to next pending guest', 'wedding-party-rsvp' ),
					'dismiss'         => __( 'Dismiss', 'wedding-party-rsvp' ),
					'hubTitle'        => __( 'Your guest summary', 'wedding-party-rsvp' ),
					'hubWhen'         => __( 'When:', 'wedding-party-rsvp' ),
					'hubWhere'        => __( 'Where:', 'wedding-party-rsvp' ),
					'hubMeal'         => __( 'Meal:', 'wedding-party-rsvp' ),
					'hubChildMeal'    => __( 'Child meal:', 'wedding-party-rsvp' ),
					'hubAppetizer'    => __( 'Appetizer:', 'wedding-party-rsvp' ),
					'hubHors'         => __( 'Hors d\'oeuvres:', 'wedding-party-rsvp' ),
					'hubMaps'         => __( 'Open venue in Google Maps', 'wedding-party-rsvp' ),
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

			$old_by_id = array();
			if ( class_exists( 'WGRSVP_Audit_Trail', false ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$old_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE party_id = %s', $this->table_name, $party_id ) );
				if ( is_array( $old_rows ) ) {
					foreach ( $old_rows as $or ) {
						$old_by_id[ (int) $or->id ] = $or;
					}
				}
			}
			$audit_batch_req = class_exists( 'WGRSVP_Audit_Trail', false ) ? strtolower( wp_generate_password( 8, false, false ) ) : '';

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

				$update_data = array(
					'guest_name'           => $name,
					'rsvp_status'          => $rsvp,
					'menu_choice'          => sanitize_text_field( wp_unslash( $data['menu'] ?? '' ) ),
					// Dietary notes: single-line field on the public RSVP form (`input`); use `sanitize_textarea_field` only if the UI becomes a textarea.
					'dietary_restrictions' => sanitize_text_field( wp_unslash( $data['dietary'] ?? '' ) ),
					'allergies'            => $allergies,
					'song_request'         => sanitize_text_field( wp_unslash( $data['song'] ?? '' ) ),
					'guest_message'        => sanitize_textarea_field( wp_unslash( $data['message'] ?? '' ) ),
					'email'                => sanitize_email( wp_unslash( $data['email'] ?? '' ) ),
					'phone'                => sanitize_text_field( wp_unslash( $data['phone'] ?? '' ) ),
					'address'              => sanitize_textarea_field( wp_unslash( $data['address'] ?? '' ) ),
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP row update; object cache bust above + clear_stats_cache() after batch.
				$upd = $wpdb->update(
					$this->table_name,
					$update_data,
					array(
						'id'       => $gid,
						'party_id' => $party_id,
					)
				);

				if ( class_exists( 'WGRSVP_Audit_Trail', false ) && false !== $upd && $wpdb->rows_affected > 0 && isset( $old_by_id[ $gid ] ) ) {
					$changes = WGRSVP_Audit_Trail::diff_assoc( $old_by_id[ $gid ], $update_data );
					if ( ! empty( $changes ) ) {
						WGRSVP_Audit_Trail::log(
							array(
								'guest_id'      => $gid,
								'party_id'      => (string) $party_id,
								'action'        => 'update',
								'actor_type'    => 'guest',
								'actor_user_id' => null,
								'source'        => 'public_form',
								'changes'       => $changes,
								'request_id'    => $audit_batch_req,
							)
						);
					}
				}
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

			$settings    = get_option( $this->opt_settings, array() );
			$hub_payload = $this->wgrsvp_build_guest_hub_payload( $party_id );
			$redirect_go = '';
			if ( is_array( $settings ) && ! empty( $settings['redirect_url'] ) ) {
				$redirect_go = function_exists( 'wgrsvp_resolve_stored_redirect_url' )
					? wgrsvp_resolve_stored_redirect_url( (string) $settings['redirect_url'] )
					: esc_url_raw( (string) $settings['redirect_url'] );
				$redirect_go = esc_url_raw( (string) $redirect_go );
			}
			if ( '' !== $redirect_go ) {
				wp_send_json_success(
					array(
						'message'  => '',
						'redirect' => $redirect_go,
					)
				);
			}

			$payload = array(
				'message' => __( 'Thank you! Your RSVP has been updated.', 'wedding-party-rsvp' ),
			);
			if ( is_array( $hub_payload ) ) {
				$payload['guest_hub'] = $hub_payload;
			}
			$gen_for_cal = get_option( 'wgrsvp_general_settings', array() );
			if ( class_exists( 'WGRSVP_ICS' ) && WGRSVP_ICS::is_configured( is_array( $gen_for_cal ) ? $gen_for_cal : array() ) ) {
				$payload['show_calendar'] = true;
				$payload['ics_url']       = WGRSVP_ICS::get_download_url( $party_id );
			}

			$household = $this->wgrsvp_household_prompt_payload_for_party( $party_id );
			if ( is_array( $household ) ) {
				$payload['household_prompt'] = $household;
			}

			wp_send_json_success( $payload );
		}

		/**
		 * Data for “complete the rest of your party” prompt after AJAX RSVP save.
		 *
		 * @param string $party_id Party ID.
		 * @return array<string, mixed>|null
		 */
		private function wgrsvp_household_prompt_payload_for_party( $party_id ) {
			$party_id = sanitize_text_field( (string) $party_id );
			if ( '' === $party_id ) {
				return null;
			}
			/**
			 * Limit which parties receive the household prompt (return null = no filter; empty array = none).
			 *
			 * @since 7.3.16
			 * @param string[]|null $allowed Null for all parties with 2+ guests.
			 * @param string        $party_id Current party ID.
			 */
			$allowed = apply_filters( 'wgrsvp_household_prompt_party_ids', null, $party_id );
			if ( is_array( $allowed ) && ! in_array( $party_id, $allowed, true ) ) {
				return null;
			}
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, guest_name, rsvp_status FROM %i WHERE party_id = %s ORDER BY id ASC', $this->table_name, $party_id ) );
			if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
				return null;
			}
			$pending_ids = array();
			foreach ( $rows as $row ) {
				if ( ! is_object( $row ) || ! isset( $row->id, $row->rsvp_status ) ) {
					continue;
				}
				if ( 'Pending' === (string) $row->rsvp_status ) {
					$pending_ids[] = (int) $row->id;
				}
			}
			if ( array() === $pending_ids ) {
				return null;
			}
			return array(
				'show'                   => true,
				'first_pending_guest_id' => (int) $pending_ids[0],
				'pending_count'          => count( $pending_ids ),
			);
		}

		/**
		 * Party-scoped guest hub data after RSVP (no admin-only fields).
		 *
		 * @param string $party_id Party ID.
		 * @return array<string, mixed>|null
		 */
		private function wgrsvp_build_guest_hub_payload( $party_id ) {
			global $wpdb;

			$party_id = sanitize_text_field( (string) $party_id );
			if ( '' === $party_id ) {
				return null;
			}

			$gen = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $gen ) ) {
				$gen = array();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Party-scoped hub; table from plugin property.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT guest_name, rsvp_status, menu_choice, child_menu_choice, appetizer_choice, hors_doeuvre_choice, dietary_restrictions, allergies FROM %i WHERE party_id = %s ORDER BY id ASC',
					$this->table_name,
					$party_id
				)
			);
			if ( ! is_array( $rows ) || array() === $rows ) {
				return null;
			}

			$guests = array();
			foreach ( $rows as $r ) {
				if ( ! is_object( $r ) ) {
					continue;
				}
				$guests[] = array(
					'name'         => isset( $r->guest_name ) ? (string) $r->guest_name : '',
					'rsvp'         => isset( $r->rsvp_status ) ? (string) $r->rsvp_status : '',
					'meal'         => isset( $r->menu_choice ) ? (string) $r->menu_choice : '',
					'child_meal'   => isset( $r->child_menu_choice ) ? (string) $r->child_menu_choice : '',
					'appetizer'    => isset( $r->appetizer_choice ) ? (string) $r->appetizer_choice : '',
					'hors_doeuvre' => isset( $r->hors_doeuvre_choice ) ? (string) $r->hors_doeuvre_choice : '',
					'dietary'      => isset( $r->dietary_restrictions ) ? (string) $r->dietary_restrictions : '',
					'allergies'    => isset( $r->allergies ) ? (string) $r->allergies : '',
				);
			}

			$title = isset( $gen['event_title'] ) ? trim( (string) $gen['event_title'] ) : '';
			if ( '' === $title && isset( $gen['welcome_title'] ) ) {
				$title = trim( (string) $gen['welcome_title'] );
			}
			$start_raw = isset( $gen['event_start'] ) ? trim( (string) $gen['event_start'] ) : '';
			$start     = $start_raw;
			if ( class_exists( 'WGRSVP_ICS', false ) ) {
				$formatted = WGRSVP_ICS::format_event_start_for_display( $gen );
				if ( '' !== $formatted ) {
					$start = $formatted;
				}
			}
			$location = isset( $gen['event_location'] ) ? trim( (string) $gen['event_location'] ) : '';
			$maps_url = '' !== $location
				? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $location )
				: '';

			$payload = array(
				'party_id'        => $party_id,
				'guests'          => $guests,
				'event_title'     => $title,
				'event_start'     => $start,
				'event_start_raw' => $start_raw,
				'event_location'  => $location,
				'maps_url'        => $maps_url,
			);

			/**
			 * Guest Hub JSON/HTML payload (AJAX + thank-you page).
			 *
			 * @since 7.3.25
			 * @param array  $payload  Hub data.
			 * @param string $party_id Party ID.
			 */
			return apply_filters( 'wgrsvp_guest_hub_payload', $payload, $party_id );
		}

		/**
		 * Markup for guest hub (thank-you URL or shortcode).
		 *
		 * @param string $party_id Party ID.
		 * @return string HTML.
		 */
		private function wgrsvp_render_guest_hub_markup( $party_id ) {
			$hub = $this->wgrsvp_build_guest_hub_payload( $party_id );
			if ( null === $hub || empty( $hub['guests'] ) ) {
				return '';
			}

			$gen_settings = get_option( 'wgrsvp_general_settings', array() );

			ob_start();
			echo '<div class="wgrsvp-guest-hub wpr-guest-card">';
			echo '<h3 class="wgrsvp-guest-hub__heading">' . esc_html__( 'Your guest summary', 'wedding-party-rsvp' ) . '</h3>';
			if ( ! empty( $hub['event_title'] ) ) {
				echo '<p class="wgrsvp-guest-hub__event"><strong>' . esc_html( $hub['event_title'] ) . '</strong></p>';
			}
			if ( ! empty( $hub['event_start'] ) ) {
				echo '<p class="wgrsvp-guest-hub__time">' . esc_html__( 'When:', 'wedding-party-rsvp' ) . ' ' . esc_html( $hub['event_start'] ) . '</p>';
			}
			if ( ! empty( $hub['event_location'] ) ) {
				echo '<p class="wgrsvp-guest-hub__where">' . esc_html__( 'Where:', 'wedding-party-rsvp' ) . ' ' . esc_html( $hub['event_location'] ) . '</p>';
			}
			if ( ! empty( $hub['maps_url'] ) ) {
				echo '<p class="wgrsvp-guest-hub__maps"><a class="wpr-button" href="' . esc_url( $hub['maps_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open venue in Google Maps', 'wedding-party-rsvp' ) . '</a></p>';
			}
			if ( class_exists( 'WGRSVP_ICS' ) && WGRSVP_ICS::is_configured( is_array( $gen_settings ) ? $gen_settings : array() ) ) {
				$href = WGRSVP_ICS::get_download_url( $party_id );
				if ( '' !== $href ) {
					echo '<p class="wgrsvp-guest-hub__calendar"><a class="wpr-button" href="' . esc_url( $href ) . '">' . esc_html__( 'Add to calendar', 'wedding-party-rsvp' ) . '</a></p>';
				}
			}
			echo '<ul class="wgrsvp-guest-hub__guests">';
			foreach ( $hub['guests'] as $g ) {
				if ( ! is_array( $g ) ) {
					continue;
				}
				$meal       = isset( $g['meal'] ) ? trim( (string) $g['meal'] ) : '';
				$child_meal = isset( $g['child_meal'] ) ? trim( (string) $g['child_meal'] ) : '';
				$app        = isset( $g['appetizer'] ) ? trim( (string) $g['appetizer'] ) : '';
				$hors       = isset( $g['hors_doeuvre'] ) ? trim( (string) $g['hors_doeuvre'] ) : '';
				$diet       = isset( $g['dietary'] ) ? trim( (string) $g['dietary'] ) : '';
				$all        = isset( $g['allergies'] ) ? trim( (string) $g['allergies'] ) : '';
				echo '<li><strong>' . esc_html( isset( $g['name'] ) ? (string) $g['name'] : '' ) . '</strong>';
				echo ' — ' . esc_html( isset( $g['rsvp'] ) ? (string) $g['rsvp'] : '' );
				if ( '' !== $meal ) {
					echo '<br><span class="wgrsvp-guest-hub__meal">' . esc_html__( 'Meal:', 'wedding-party-rsvp' ) . ' ' . esc_html( $meal ) . '</span>';
				}
				if ( '' !== $child_meal ) {
					echo '<br><span class="wgrsvp-guest-hub__meal wgrsvp-guest-hub__child-meal">' . esc_html__( 'Child meal:', 'wedding-party-rsvp' ) . ' ' . esc_html( $child_meal ) . '</span>';
				}
				if ( '' !== $app ) {
					echo '<br><span class="wgrsvp-guest-hub__course">' . esc_html__( 'Appetizer:', 'wedding-party-rsvp' ) . ' ' . esc_html( $app ) . '</span>';
				}
				if ( '' !== $hors ) {
					echo '<br><span class="wgrsvp-guest-hub__course">' . esc_html__( 'Hors d\'oeuvres:', 'wedding-party-rsvp' ) . ' ' . esc_html( $hors ) . '</span>';
				}
				if ( '' !== $diet || '' !== $all ) {
					$note = trim( $diet . ( '' !== $diet && '' !== $all ? '; ' : '' ) . $all );
					if ( '' !== $note ) {
						echo '<br><span class="wgrsvp-guest-hub__diet">' . esc_html( $note ) . '</span>';
					}
				}
				echo '</li>';
			}
			echo '</ul></div>';

			return ob_get_clean();
		}

		/**
		 * Shortcode: guest hub (thank-you state or placeholder).
		 *
		 * @return string HTML.
		 */
		public function render_guest_hub_shortcode() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public read-only hub; verified when thanks params present.
			if ( isset( $_GET['wgrsvp_thanks'], $_GET['party_id'], $_GET['wgrsvp_thanks_nonce'] ) && class_exists( 'WGRSVP_ICS' ) ) {
				$ty_pid = sanitize_text_field( wp_unslash( (string) $_GET['party_id'] ) );
				$ty_non = sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_thanks_nonce'] ) );
				$ty_ok  = sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_thanks'] ) );
				if ( '1' === $ty_ok && WGRSVP_ICS::verify_thanks_nonce( $ty_pid, $ty_non ) ) {
					$po_notes = class_exists( 'WPR_Pro_Frontend', false ) ? WPR_Pro_Frontend::flush_plus_one_notices_html( $ty_pid ) : '';
					$markup   = $this->wgrsvp_render_guest_hub_markup( $ty_pid );
					if ( '' !== $markup ) {
						return $po_notes . $markup;
					}
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			return '<p class="wgrsvp-guest-hub-placeholder description">' . esc_html__( 'Your personalized summary appears here after you submit your RSVP on this site (or open the link the couple sent you with your Party ID).', 'wedding-party-rsvp' ) . '</p>';
		}

		/**
		 * Admin AJAX: preview parsed rows for paste import (matches server parser).
		 *
		 * @return void
		 */
		public function ajax_preview_paste_import() {
			if ( ! check_ajax_referer( 'wgrsvp_preview_paste', 'nonce', false ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Security check failed.', 'wedding-party-rsvp' ) ),
					403
				);
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to preview imports.', 'wedding-party-rsvp' ) ),
					403
				);
			}
			if ( ! class_exists( 'WGRSVP_Paste_Import' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Paste import is unavailable.', 'wedding-party-rsvp' ) ),
					500
				);
			}

			$blob    = isset( $_POST['blob'] ) ? sanitize_textarea_field( wp_unslash( $_POST['blob'] ) ) : '';
			$default = isset( $_POST['default_party'] ) ? sanitize_text_field( wp_unslash( $_POST['default_party'] ) ) : '';

			$rows  = WGRSVP_Paste_Import::parse_block( $blob, $default );
			$total = count( $rows );
			$prev  = array_slice( $rows, 0, 50 );

			wp_send_json_success(
				array(
					'rows'  => $prev,
					'total' => $total,
					'max'   => WGRSVP_Paste_Import::MAX_ROWS,
				)
			);
		}

		/**
		 * Enqueue General Settings AI wording script (WordPress AI Client when available).
		 *
		 * @param string $hook_suffix Current admin screen id.
		 * @return void
		 */
		public function maybe_enqueue_settings_ai_wording_assets( $hook_suffix ) {
			if ( 'wedding-rsvp-main_page_wedding-rsvp-settings' !== $hook_suffix ) {
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$path = plugin_dir_path( __FILE__ ) . 'assets/js/wgrsvp-admin-ai-wording.js';
			$ver  = is_readable( $path ) ? (string) filemtime( $path ) : '8.0.6';

			wp_enqueue_script(
				'wgrsvp-admin-ai-wording',
				plugins_url( 'assets/js/wgrsvp-admin-ai-wording.js', __FILE__ ),
				array( 'jquery' ),
				$ver,
				true
			);
			wgrsvp_set_script_translations( 'wgrsvp-admin-ai-wording' );
			wp_localize_script(
				'wgrsvp-admin-ai-wording',
				'wgrsvpAiWording',
				array(
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'action'        => 'wgrsvp_ai_wording',
					'nonce'         => wp_create_nonce( 'wgrsvp_ai_wording' ),
					'has_ai_client' => function_exists( 'wp_ai_client_prompt' ),
					'i18n'          => array(
						'busy'        => __( 'Generating…', 'wedding-party-rsvp' ),
						'done'        => __( 'Suggestion inserted. Review before saving.', 'wedding-party-rsvp' ),
						'need_wp7'    => __( 'Requires WordPress 7.0+ with the AI Client and a site-configured provider.', 'wedding-party-rsvp' ),
						'ajax_failed' => __( 'Request failed.', 'wedding-party-rsvp' ),
						'promptGoals' => __( 'Optional: extra guidance for the assistant (tone, formality, date, venue). Leave blank to skip.', 'wedding-party-rsvp' ),
					),
				)
			);
		}

		/**
		 * AJAX: AI-assisted wording via WordPress AI Client (WP 7.0+). No guest PII is sent.
		 *
		 * @return void
		 */
		public function ajax_ai_wording() {
			if ( ! check_ajax_referer( 'wgrsvp_ai_wording', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wedding-party-rsvp' ) ), 403 );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wedding-party-rsvp' ) ), 403 );
			}
			if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'WordPress AI Client is not available. Install WordPress 7.0+ and configure a provider.', 'wedding-party-rsvp' ),
					),
					400
				);
			}

			$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( (string) $_POST['context'] ) ) : '';
			$goals   = isset( $_POST['goals'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['goals'] ) ) : '';

			$task = __( 'Write concise, warm wedding-event copy for guests.', 'wedding-party-rsvp' );
			if ( 'welcome_title' === $context ) {
				$task = __( 'Write a short welcome headline for the public RSVP page (plain text, no HTML, under 80 characters if possible).', 'wedding-party-rsvp' );
			} elseif ( 'deadline_closed_message' === $context ) {
				$task = __( 'Write a polite message for when the RSVP deadline has passed. Use simple HTML only: p, strong, em, br, a (href only if the site owner adds a real URL in their guidance).', 'wedding-party-rsvp' );
			} elseif ( 'save_the_date' === $context ) {
				$task = __( 'Write a short “save the date” paragraph for email or a site block (plain text, 2–4 sentences). Do not invent URLs or dates unless given in the extra guidance.', 'wedding-party-rsvp' );
			} elseif ( 'rsvp_deadline_reminder' === $context ) {
				$task = __( 'Write a short RSVP deadline reminder paragraph guests might see on the site or in email (plain text). Mention replying by the deadline without inventing a specific date unless provided in guidance.', 'wedding-party-rsvp' );
			} else {
				wp_send_json_error( array( 'message' => __( 'Unknown assistant context.', 'wedding-party-rsvp' ) ), 400 );
			}

			$prompt  = $task . "\n\n";
			$prompt .= __( 'Do not include markdown fences. Do not invent URLs. Output only the final copy.', 'wedding-party-rsvp' ) . "\n";
			if ( '' !== $goals ) {
				$prompt .= __( 'Extra guidance from the site owner:', 'wedding-party-rsvp' ) . ' ' . $goals . "\n";
			}

			/**
			 * Filter the full prompt sent to `wp_ai_client_prompt` (avoid adding guest PII).
			 *
			 * @since 7.3.14
			 * @param string $prompt  Prompt text.
			 * @param string $context Context key.
			 */
			$prompt = (string) apply_filters( 'wgrsvp_ai_wording_prompt', $prompt, $context );

			try {
				$builder = wp_ai_client_prompt( $prompt );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				wp_send_json_error( array( 'message' => __( 'AI client could not start.', 'wedding-party-rsvp' ) ), 500 );
				return;
			}

			if ( ! is_object( $builder ) || ! method_exists( $builder, 'generate_text' ) ) {
				wp_send_json_error( array( 'message' => __( 'AI client API is not supported on this site.', 'wedding-party-rsvp' ) ), 500 );
				return;
			}

			$out = $builder->generate_text();
			if ( is_wp_error( $out ) ) {
				wp_send_json_error( array( 'message' => $out->get_error_message() ), 500 );
				return;
			}
			if ( ! is_string( $out ) ) {
				wp_send_json_error( array( 'message' => __( 'Unexpected AI response.', 'wedding-party-rsvp' ) ), 500 );
				return;
			}

			if ( 'deadline_closed_message' === $context ) {
				$text = wp_kses_post( $out );
			} elseif ( 'welcome_title' === $context ) {
				$text = sanitize_text_field( $out );
			} else {
				$text = sanitize_textarea_field( $out );
			}

			wp_send_json_success( array( 'text' => $text ) );
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
				gift_received text DEFAULT '',
				thankyou_card_sent_on date DEFAULT NULL,
				table_number varchar(20) DEFAULT '',
				wgrsvp_arrived_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY party_id (party_id)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
			update_option( self::OPT_WEDDING_RSVPS_SCHEMA, self::WEDDING_RSVPS_SCHEMA_VERSION, false );

			if ( class_exists( 'WGRSVP_Setup_Wizard' ) ) {
				WGRSVP_Setup_Wizard::flag_activation_redirect();
			}

			if ( class_exists( 'WGRSVP_Coordinator_Role' ) ) {
				WGRSVP_Coordinator_Role::sync_on_activation();
			}

			if ( class_exists( 'WGRSVP_ThankYou_Tracker' ) ) {
				WGRSVP_ThankYou_Tracker::activate();
			}

			if ( class_exists( 'WGRSVP_Audit_Trail', false ) ) {
				WGRSVP_Audit_Trail::activate();
			}

			if ( class_exists( 'WGRSVP_Deadline_Nudges', false ) ) {
				WGRSVP_Deadline_Nudges::schedule_if_missing();
			}
		}

		/**
		 * Clear deadline nudge cron on plugin deactivation.
		 *
		 * @return void
		 */
		public function deactivate_deadline_nudges_cron() {
			if ( class_exists( 'WGRSVP_Deadline_Nudges', false ) ) {
				wp_clear_scheduled_hook( WGRSVP_Deadline_Nudges::CRON_HOOK );
			}
		}

		/**
		 * Add missing `wedding_rsvps` columns after plugin updates (dbDelta).
		 *
		 * @return void
		 */
		public function maybe_upgrade_wedding_rsvps_table() {
			$ver = (int) get_option( self::OPT_WEDDING_RSVPS_SCHEMA, 0 );
			if ( $ver >= self::WEDDING_RSVPS_SCHEMA_VERSION ) {
				return;
			}
			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();
			$table           = $this->table_name;
			$sql             = "CREATE TABLE $table (
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
				gift_received text DEFAULT '',
				thankyou_card_sent_on date DEFAULT NULL,
				table_number varchar(20) DEFAULT '',
				wgrsvp_arrived_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY party_id (party_id)
			) $charset_collate;";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
			update_option( self::OPT_WEDDING_RSVPS_SCHEMA, self::WEDDING_RSVPS_SCHEMA_VERSION, false );
		}

		/**
		 * Bust guest list / export caches (hook target for other classes).
		 *
		 * @return void
		 */
		public function wgrsvp_invalidate_guest_caches() {
			$this->clear_stats_cache();
		}

		// --- HELPER: Cache Clearing ---
		private function clear_stats_cache() {
			delete_transient( self::TRANSIENT_AGGREGATED_STATS );
			if ( class_exists( 'WGRSVP_Guest_Health', false ) ) {
				WGRSVP_Guest_Health::bust_metrics_cache();
			}
			if ( class_exists( 'WGRSVP_Vendor_Packet', false ) ) {
				WGRSVP_Vendor_Packet::bust_vendor_packet_transients();
			}
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
		 * Store event datetime as Y-m-d H:i:s for wp_timezone() parsing in ICS.
		 *
		 * @param string $date Y-m-d or empty.
		 * @param string $time H:i or empty.
		 * @return string Datetime or empty when date invalid / both empty.
		 */
		private function wgrsvp_combine_event_datetime_for_save( $date, $time ) {
			$date = is_string( $date ) ? trim( $date ) : '';
			$time = is_string( $time ) ? trim( $time ) : '';
			if ( '' === $date && '' === $time ) {
				return '';
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return '';
			}
			if ( '' === $time || ! preg_match( '/^\d{1,2}:\d{2}$/', $time ) ) {
				$time = '16:00';
			}
			$parts = explode( ':', $time, 2 );
			$h     = isset( $parts[0] ) ? max( 0, min( 23, (int) $parts[0] ) ) : 16;
			$m     = isset( $parts[1] ) ? max( 0, min( 59, (int) $parts[1] ) ) : 0;

			return sprintf( '%s %02d:%02d:00', $date, $h, $m );
		}

		/**
		 * Split stored Y-m-d H:i:s for date/time inputs.
		 *
		 * @param string $stored Settings value.
		 * @return array{date:string,time:string}
		 */
		private function wgrsvp_split_event_datetime_for_inputs( $stored ) {
			$stored = is_string( $stored ) ? trim( $stored ) : '';
			if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})\s+(\d{2}):(\d{2})/', $stored, $m ) ) {
				return array(
					'date' => '',
					'time' => '',
				);
			}

			return array(
				'date' => $m[1],
				'time' => $m[2] . ':' . $m[3],
			);
		}

		/**
		 * Guest rows matching export form POST (shared by CSV and check-in PDF).
		 *
		 * @return array<int,array<string,mixed>>
		 */
		private function wgrsvp_get_guest_rows_for_list_export_from_post() {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer( wgrsvp_export_guest_list ) runs in handle_*_export before this method.
			global $wpdb;

			$search_query  = isset( $_POST['export_s'] ) ? sanitize_text_field( wp_unslash( $_POST['export_s'] ) ) : '';
			$filter_status = isset( $_POST['export_filter_status'] ) ? sanitize_text_field( wp_unslash( $_POST['export_filter_status'] ) ) : '';
			$filter_menu   = isset( $_POST['export_filter_menu'] ) ? sanitize_text_field( wp_unslash( $_POST['export_filter_menu'] ) ) : '';
			$filter_gap    = isset( $_POST['export_wgrsvp_gap'] ) ? sanitize_key( wp_unslash( (string) $_POST['export_wgrsvp_gap'] ) ) : '';
			$filter_gap    = $this->wgrsvp_sanitize_guest_list_gap( $filter_gap );
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
			$gap_sql = $this->wgrsvp_guest_list_gap_sql_clause( $filter_gap );
			if ( '' !== $gap_sql ) {
				$sql_where[] = $gap_sql;
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
						'fgap'    => $filter_gap,
						'orderby' => $orderby_safe,
						'grp'     => $group_export,
					)
				)
			);

			$cached_guests = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached_guests && is_array( $cached_guests ) ) {
				// phpcs:enable WordPress.Security.NonceVerification.Missing
				return $cached_guests;
			}

			$prepare_args = array_merge( array( $this->table_name ), $sql_args );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where_sql / $order_sql: fixed %s fragments + whitelist identifiers only; see handle_csv_export.
			$guests = $this->wgrsvp_query_cache_get_results(
				'SELECT * FROM %i' . $where_sql . $order_sql,
				$prepare_args,
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			wp_cache_set( $cache_key, $guests, $cache_group, 2 * MINUTE_IN_SECONDS );

			// phpcs:enable WordPress.Security.NonceVerification.Missing
			return $guests;
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
		 * @param string           $query SQL with placeholders.
		 * @param array<int,mixed> $prepare_args Values for $wpdb->prepare() (spread in order).
		 * @param string           $output_mode ARRAY_A or OBJECT (wpdb constant).
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
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $query built only in wgrsvp_build_* / export helpers; values bound via prepare spread; cache key matches.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$prepare_args ), $output_mode );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
			wp_cache_set( $cache_key, $rows, 'wgrsvp_queries', HOUR_IN_SECONDS );
			return $rows;
		}

		/**
		 * Aggregate guest counts and menu breakdown for admin dashboard (expensive queries; cached 24 hours).
		 *
		 * Public so companion classes (e.g. growth checklist) can reuse the same cache.
		 *
		 * @return array<string,mixed> Keys: total_accepted, total_declined, total_pending, total_guests (int), menu_stats_adult (array), households_total (int), households_fully_replied (int).
		 */
		public function get_aggregated_rsvp_stats() {
			$cached = get_transient( self::TRANSIENT_AGGREGATED_STATS );
			if ( is_array( $cached )
				&& isset( $cached['total_accepted'], $cached['total_declined'], $cached['total_pending'], $cached['total_guests'], $cached['menu_stats_adult'], $cached['households_total'], $cached['households_fully_replied'] ) ) {
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

			$key_hh_tot = 'wgrsvp_' . md5( (string) $gen . '|households_distinct|' . $table );
			$cached     = wp_cache_get( $key_hh_tot, 'wgrsvp_queries' );
			if ( false !== $cached ) {
				$households_total = (int) $cached;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$households_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT party_id) FROM %i', $table ) );
				wp_cache_set( $key_hh_tot, $households_total, 'wgrsvp_queries', HOUR_IN_SECONDS );
			}

			$key_hh_done = 'wgrsvp_' . md5( (string) $gen . '|households_fully_replied|' . $table );
			$cached      = wp_cache_get( $key_hh_done, 'wgrsvp_queries' );
			if ( false !== $cached ) {
				$households_fully_replied = (int) $cached;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Subquery; placeholders only.
				$households_fully_replied = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ( SELECT party_id FROM %i GROUP BY party_id HAVING SUM(CASE WHEN rsvp_status = %s THEN 1 ELSE 0 END) = 0 ) AS wgrsvp_hh_done',
						$table,
						'Pending'
					)
				);
				wp_cache_set( $key_hh_done, $households_fully_replied, 'wgrsvp_queries', HOUR_IN_SECONDS );
			}

			$out = array(
				'total_accepted'           => $total_accepted,
				'total_declined'           => $total_declined,
				'total_pending'            => $total_pending,
				'total_guests'             => $total_guests,
				'menu_stats_adult'         => $menu_stats_adult,
				'households_total'         => $households_total,
				'households_fully_replied' => $households_fully_replied,
			);

			$out = apply_filters( 'wgrsvp_aggregated_rsvp_stats', $out );

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

		/**
		 * When Pro is active with the merged admin hub, Pro registers `wedding-rsvp-settings` (tabbed Settings & Licensing).
		 * Avoid a duplicate submenu that makes WordPress load this plugin's long free settings screen instead.
		 *
		 * @return bool
		 */
		private function wgrsvp_pro_owns_merged_settings_screen() {
			return class_exists( 'WPR_Pro_Admin', false )
				&& function_exists( 'wpr_pro_should_merge_with_free_admin_menu' )
				&& wpr_pro_should_merge_with_free_admin_menu();
		}

		/**
		 * Register top-level Wedding RSVP menu and core submenus.
		 *
		 * @return void
		 */
		public function create_admin_menu() {
			add_menu_page( __( 'Wedding RSVP', 'wedding-party-rsvp' ), __( 'Wedding RSVP', 'wedding-party-rsvp' ), WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD, 'wedding-rsvp-main', array( $this, 'admin_page_guests' ), 'dashicons-groups', 6 );
			if ( wgrsvp_admin_module_enabled( 'menu_options' ) ) {
				add_submenu_page( 'wedding-rsvp-main', __( 'Menu Options', 'wedding-party-rsvp' ), __( 'Menu Options', 'wedding-party-rsvp' ), 'manage_options', 'wedding-rsvp-menu', array( $this, 'admin_page_menu' ) );
			}
			if ( wgrsvp_admin_module_enabled( 'paste_guests' ) ) {
				add_submenu_page( 'wedding-rsvp-main', __( 'Paste Guest List', 'wedding-party-rsvp' ), __( 'Paste Guest List', 'wedding-party-rsvp' ), 'manage_options', 'wedding-rsvp-paste-guests', array( $this, 'admin_page_paste_guest_list' ) );
			}
			if ( ! $this->wgrsvp_pro_owns_merged_settings_screen() ) {
				add_submenu_page( 'wedding-rsvp-main', __( 'Settings', 'wedding-party-rsvp' ), __( 'Settings', 'wedding-party-rsvp' ), 'manage_options', 'wedding-rsvp-settings', array( $this, 'admin_page_settings' ) );
			}
			add_submenu_page( 'wedding-rsvp-main', __( 'Email Invites', 'wedding-party-rsvp' ), __( 'Email Invites', 'wedding-party-rsvp' ), 'manage_options', 'wedding-rsvp-email', array( $this, 'admin_page_email' ) );
			add_submenu_page( 'wedding-rsvp-main', __( 'SMS Invites', 'wedding-party-rsvp' ), __( 'SMS Invites', 'wedding-party-rsvp' ), 'manage_options', 'wedding-rsvp-sms', array( $this, 'admin_page_sms' ) );
		}

		/**
		 * Build a sorted guest-list URL preserving current filters.
		 *
		 * @param string $col          Column key for orderby.
		 * @param string $current_by   Active orderby from the list request.
		 * @param string $current_order Active sort direction (ASC|DESC).
		 * @return string Admin URL with sort query args.
		 */
		private function get_sort_link( $col, $current_by, $current_order ) {
			$new_order = ( $col === $current_by && 'ASC' === $current_order ) ? 'DESC' : 'ASC';
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
			$gap_val = isset( $_GET['wgrsvp_gap'] ) ? sanitize_key( wp_unslash( (string) $_GET['wgrsvp_gap'] ) ) : '';
			$gap_ok  = $this->wgrsvp_sanitize_guest_list_gap( $gap_val );
			if ( '' !== $gap_ok ) {
				$args['wgrsvp_gap'] = $gap_ok;
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
				wp_register_style( 'wgrsvp-settings-layout-base', false, array(), '8.0.6' );
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
			wgrsvp_set_script_translations( 'wgrsvp-settings-pro-teaser' );

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
		 * Whether Pro per-guest email/SMS actions may appear on the free guest list (Pro active + licensed).
		 *
		 * @return bool
		 */
		private function wgrsvp_may_use_pro_guest_list_comm_links() {
			return (
				current_user_can( 'manage_options' )
				&& wgrsvp_is_pro_plugin_active()
				&& function_exists( 'wgrsvp_is_pro_license_effectively_valid' )
				&& wgrsvp_is_pro_license_effectively_valid()
			);
		}

		/**
		 * Nonce URL: Pro sends invitation email template to one guest; returns to the main Wedding RSVP list.
		 *
		 * @param int $guest_id Guest row ID.
		 * @return string Empty if invalid ID.
		 */
		private function wgrsvp_pro_single_guest_email_send_url( $guest_id ) {
			$guest_id = absint( $guest_id );
			if ( $guest_id < 1 ) {
				return '';
			}
			$base = add_query_arg(
				array(
					'page'       => 'wedding-rsvp-main',
					'action'     => 'send_single_email',
					'id'         => $guest_id,
					'wpr_return' => 'wedding-rsvp-main',
				),
				admin_url( 'admin.php' )
			);
			return wp_nonce_url( $base, 'wpr_send_email', 'wpr_email_nonce' );
		}

		/**
		 * Nonce URL: Pro sends SMS invite to one guest; returns to the main Wedding RSVP list.
		 *
		 * @param int $guest_id Guest row ID.
		 * @return string Empty if invalid ID.
		 */
		private function wgrsvp_pro_single_guest_sms_send_url( $guest_id ) {
			$guest_id = absint( $guest_id );
			if ( $guest_id < 1 ) {
				return '';
			}
			$base = add_query_arg(
				array(
					'page'       => 'wedding-rsvp-main',
					'action'     => 'send_single_sms',
					'id'         => $guest_id,
					'wpr_return' => 'wedding-rsvp-main',
				),
				admin_url( 'admin.php' )
			);
			return wp_nonce_url( $base, 'wpr_send_sms', 'wpr_sms_nonce' );
		}

		/**
		 * Handles GET requests from “Dismiss” links on growth/admin notices (`wgrsvp_dismiss_notice`).
		 *
		 * Validates `check_admin_referer( 'wgrsvp_dismiss_growth_notice', '_wpnonce' )`, then `manage_options`, before updating options.
		 *
		 * @return void
		 */
		public function maybe_handle_growth_dismiss() {
			if ( ! isset( $_GET['wgrsvp_dismiss_notice'] ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified before reads and capability.
			check_admin_referer( 'wgrsvp_dismiss_growth_notice', '_wpnonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsed after nonce verification above.
			$which = sanitize_key( wp_unslash( $_GET['wgrsvp_dismiss_notice'] ) );
			if ( 'activation' === $which ) {
				update_option( 'wgrsvp_activation_welcome_dismissed', 1, false );
			} elseif ( 'milestone' === $which ) {
				update_option( 'wgrsvp_milestone_notice_dismissed', 1, false );
			} elseif ( 'getting_started_panel' === $which ) {
				update_option( WGRSVP_Growth_Checklist::OPT_PANEL_DISMISSED, 1, false );
			} elseif ( 'next_steps' === $which ) {
				update_user_meta( get_current_user_id(), 'wgrsvp_next_steps_notice_dismissed', 1 );
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
					echo ' <a class="button" href="' . esc_url( wgrsvp_get_pro_live_demo_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Try Premium', 'wedding-party-rsvp' ) . '</a>';
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
		 * Save current guest list filters as a named shortcut (per-user, administrators only).
		 *
		 * @return void
		 */
		public function handle_save_guest_list_segment() {
			check_admin_referer( 'wgrsvp_save_guest_list_segment', 'wgrsvp_save_guest_list_segment_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			$label = isset( $_POST['wgrsvp_segment_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_segment_label'] ) ) : '';
			if ( '' === $label ) {
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wedding-rsvp-main' ) );
				exit;
			}

			$seg               = array(
				'id'            => wp_generate_password( 10, false, false ),
				'label'         => $label,
				's'             => isset( $_POST['wgrsvp_seg_s'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_seg_s'] ) ) : '',
				'filter_status' => isset( $_POST['wgrsvp_seg_filter_status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_seg_filter_status'] ) ) : '',
				'filter_menu'   => isset( $_POST['wgrsvp_seg_filter_menu'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_seg_filter_menu'] ) ) : '',
				'wgrsvp_gap'    => isset( $_POST['wgrsvp_seg_gap'] ) ? sanitize_key( wp_unslash( (string) $_POST['wgrsvp_seg_gap'] ) ) : '',
				'wgrsvp_group'  => isset( $_POST['wgrsvp_seg_group'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_seg_group'] ) ) ? '1' : '',
			);
			$seg['wgrsvp_gap'] = $this->wgrsvp_sanitize_guest_list_gap( $seg['wgrsvp_gap'] );

			$list = get_user_meta( get_current_user_id(), 'wgrsvp_guest_list_segments', true );
			if ( ! is_array( $list ) ) {
				$list = array();
			}
			array_unshift( $list, $seg );
			$list = array_slice( $list, 0, 15 );
			update_user_meta( get_current_user_id(), 'wgrsvp_guest_list_segments', $list );

			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wedding-rsvp-main' ) );
			exit;
		}

		/**
		 * Remove one saved filter shortcut.
		 *
		 * @return void
		 */
		public function handle_delete_guest_list_segment() {
			if ( ! isset( $_GET['wgrsvp_seg_id'], $_GET['_wpnonce'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-main' ) );
				exit;
			}
			$id = sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_seg_id'] ) );
			if ( '' === $id ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-main' ) );
				exit;
			}
			check_admin_referer( 'wgrsvp_delete_guest_list_segment_' . $id );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			$list = get_user_meta( get_current_user_id(), 'wgrsvp_guest_list_segments', true );
			if ( ! is_array( $list ) ) {
				$list = array();
			}
			$new = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['id'] ) || (string) $row['id'] !== $id ) {
					$new[] = $row;
				}
			}
			update_user_meta( get_current_user_id(), 'wgrsvp_guest_list_segments', $new );

			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wedding-rsvp-main' ) );
			exit;
		}

		/**
		 * Day-of desk: mark or clear manual arrival timestamp (`wgrsvp_arrived_at`).
		 *
		 * @return void
		 */
		public function handle_dayof_arrival() {
			check_admin_referer( 'wgrsvp_dayof_arrival', 'wgrsvp_dayof_arrival_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			$guest_id = isset( $_POST['wgrsvp_arrival_guest_id'] ) ? absint( wp_unslash( (string) $_POST['wgrsvp_arrival_guest_id'] ) ) : 0;
			$do       = isset( $_POST['wgrsvp_arrival_do'] ) ? sanitize_key( wp_unslash( (string) $_POST['wgrsvp_arrival_do'] ) ) : '';
			$q        = isset( $_POST['wgrsvp_dayof_q'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_dayof_q'] ) ) : '';
			$scope    = isset( $_POST['wgrsvp_dayof_scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['wgrsvp_dayof_scope'] ) ) : 'accepted';

			$redirect_base = admin_url( 'admin.php' );
			$redirect_args = array(
				'page'           => 'wedding-rsvp-ops',
				'wgrsvp_ops_tab' => 'dayof',
			);
			if ( '' !== $q ) {
				$redirect_args['wgrsvp_dayof_q'] = $q;
			}
			if ( 'all' === $scope ) {
				$redirect_args['wgrsvp_dayof_scope'] = 'all';
			}

			if ( $guest_id < 1 || ( 'mark' !== $do && 'clear' !== $do ) ) {
				wp_safe_redirect( add_query_arg( $redirect_args, $redirect_base ) );
				exit;
			}

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d LIMIT 1', $this->table_name, $guest_id ) );
			if ( $exists !== $guest_id ) {
				wp_safe_redirect( add_query_arg( $redirect_args, $redirect_base ) );
				exit;
			}

			wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$dayof_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table_name, $guest_id ) );

			if ( 'mark' === $do ) {
				$now = current_time( 'mysql', true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$upd_d = $wpdb->update(
					$this->table_name,
					array( 'wgrsvp_arrived_at' => $now ),
					array( 'id' => $guest_id ),
					array( '%s' ),
					array( '%d' )
				);
				if ( class_exists( 'WGRSVP_Audit_Trail', false ) && false !== $upd_d && $wpdb->rows_affected > 0 && is_object( $dayof_row ) ) {
					$changes = WGRSVP_Audit_Trail::diff_assoc( $dayof_row, array( 'wgrsvp_arrived_at' => $now ) );
					if ( ! empty( $changes ) ) {
						WGRSVP_Audit_Trail::log(
							array(
								'guest_id'      => $guest_id,
								'party_id'      => (string) $dayof_row->party_id,
								'action'        => 'update',
								'actor_type'    => 'user',
								'actor_user_id' => get_current_user_id(),
								'source'        => 'dayof_arrival',
								'changes'       => $changes,
							)
						);
					}
				}
				/**
				 * After a guest row is marked arrived from the free plugin day-of desk.
				 *
				 * @param int    $guest_id Guest row ID.
				 * @param string $now      MySQL datetime (UTC).
				 */
				do_action( 'wgrsvp_guest_marked_arrived', $guest_id, $now );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$clr = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET wgrsvp_arrived_at = NULL WHERE id = %d', $this->table_name, $guest_id ) );
				if ( class_exists( 'WGRSVP_Audit_Trail', false ) && false !== $clr && $wpdb->rows_affected > 0 && is_object( $dayof_row ) ) {
					$changes = WGRSVP_Audit_Trail::diff_assoc( $dayof_row, array( 'wgrsvp_arrived_at' => null ) );
					if ( ! empty( $changes ) ) {
						WGRSVP_Audit_Trail::log(
							array(
								'guest_id'      => $guest_id,
								'party_id'      => (string) $dayof_row->party_id,
								'action'        => 'update',
								'actor_type'    => 'user',
								'actor_user_id' => get_current_user_id(),
								'source'        => 'dayof_arrival',
								'changes'       => $changes,
							)
						);
					}
				}
				do_action( 'wgrsvp_guest_cleared_arrived', $guest_id );
			}

			$this->clear_stats_cache();

			wp_safe_redirect( add_query_arg( $redirect_args, $redirect_base ) );
			exit;
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
			$links['wgrsvp_playground'] = '<a href="' . esc_url( 'https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/brelandr/wedding-party-rsvp/main/blueprint.json' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Live demo', 'wedding-party-rsvp' ) . '</a>';
			if ( ! wgrsvp_is_pro_plugin_active() ) {
				$links['wgrsvp_try_premium'] = '<a href="' . esc_url( wgrsvp_get_pro_live_demo_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Try Premium', 'wedding-party-rsvp' ) . '</a>';
				$links['wgrsvp_get_pro']     = '<a href="' . esc_url( $this->get_pro_marketing_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Pro', 'wedding-party-rsvp' ) . '</a>';
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
			$links[] = '<a href="' . esc_url( 'https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/brelandr/wedding-party-rsvp/main/blueprint.json' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Live demo', 'wedding-party-rsvp' ) . '</a>';
			if ( ! wgrsvp_is_pro_plugin_active() ) {
				$links[] = '<a href="' . esc_url( wgrsvp_get_pro_live_demo_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Try Premium', 'wedding-party-rsvp' ) . '</a>';
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

			$meal_segments = array();
			if ( ! empty( $dash_stats['menu_stats_adult'] ) && is_array( $dash_stats['menu_stats_adult'] ) ) {
				foreach ( $dash_stats['menu_stats_adult'] as $row ) {
					if ( ! is_object( $row ) || empty( $row->menu_choice ) ) {
						continue;
					}
					$meal_segments[] = array(
						'label' => (string) $row->menu_choice,
						'count' => (int) $row->count,
					);
				}
			}
			/**
			 * Meal counts for the core dashboard widget (adult entrées from free schema).
			 *
			 * @since 7.3.12
			 * @param array<int,array{label:string,count:int}> $meal_segments Segments for the chart.
			 * @param array<string,mixed>                     $dash_stats    Full aggregated stats.
			 */
			$meal_segments = apply_filters( 'wgrsvp_dashboard_meal_stats', $meal_segments, $dash_stats );

			$meal_total = 0;
			foreach ( $meal_segments as $seg ) {
				$meal_total += isset( $seg['count'] ) ? max( 0, (int) $seg['count'] ) : 0;
			}

			if ( $meal_total > 0 && ! empty( $meal_segments ) ) {
				$sr_bits = array();
				foreach ( $meal_segments as $seg ) {
					$lbl = isset( $seg['label'] ) ? (string) $seg['label'] : '';
					$cnt = isset( $seg['count'] ) ? (int) $seg['count'] : 0;
					if ( '' === $lbl || $cnt < 1 ) {
						continue;
					}
					$sr_bits[] = sprintf(
						/* translators: 1: meal name, 2: guest count */
						__( '%1$s: %2$d', 'wedding-party-rsvp' ),
						$lbl,
						$cnt
					);
				}
				echo '<p class="screen-reader-text">' . esc_html(
					sprintf(
						/* translators: %s: list of meal counts for screen readers */
						__( 'Entrée choices among attending guests: %s', 'wedding-party-rsvp' ),
						implode( '; ', $sr_bits )
					)
				) . '</p>';

				$palette = array( '#2271b1', '#00a32a', '#dba617', '#d63638', '#826eb4', '#135e96' );
				echo '<p><strong>' . esc_html__( 'Meal mix (attending)', 'wedding-party-rsvp' ) . '</strong></p>';
				echo '<div class="wgrsvp-meal-widget-bars" style="display:flex;height:28px;border-radius:6px;overflow:hidden;border:1px solid #c3c4c7;margin:0 0 8px 0;" role="presentation">';
				$pi = 0;
				foreach ( $meal_segments as $seg ) {
					$lbl = isset( $seg['label'] ) ? (string) $seg['label'] : '';
					$cnt = isset( $seg['count'] ) ? (int) $seg['count'] : 0;
					if ( '' === $lbl || $cnt < 1 ) {
						continue;
					}
					$flex = max( 1, $cnt );
					$col  = $palette[ $pi % count( $palette ) ];
					++$pi;
					/* translators: 1: meal name, 2: count, 3: percent */
					$tip = sprintf( __( '%1$s: %2$d (%3$d%%)', 'wedding-party-rsvp' ), $lbl, $cnt, (int) round( 100 * $cnt / $meal_total ) );
					echo '<span style="flex:' . (int) $flex . ' 1 auto;background:' . esc_attr( $col ) . ';min-width:4px;" title="' . esc_attr( $tip ) . '"></span>';
				}
				echo '</div><ul style="margin:0 0 8px 1.2em;font-size:12px;line-height:1.5;">';
				$pi = 0;
				foreach ( $meal_segments as $seg ) {
					$lbl = isset( $seg['label'] ) ? (string) $seg['label'] : '';
					$cnt = isset( $seg['count'] ) ? (int) $seg['count'] : 0;
					if ( '' === $lbl || $cnt < 1 ) {
						continue;
					}
					$col = $palette[ $pi % count( $palette ) ];
					++$pi;
					echo '<li><span style="color:' . esc_attr( $col ) . ';font-weight:600;">' . esc_html( '●' ) . '</span> ' . esc_html( $lbl . ' — ' . $cnt ) . '</li>';
				}
				echo '</ul>';
			}

			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ) . '">' . esc_html__( 'Open guest list', 'wedding-party-rsvp' ) . '</a></p>';
			if ( ! wgrsvp_is_pro_plugin_active() ) {
				echo '<p><a class="button button-secondary" href="' . esc_url( wgrsvp_get_pro_live_demo_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Try Premium', 'wedding-party-rsvp' ) . '</a></p>';
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
			wp_register_style( 'wgrsvp-admin-style', false, array(), '8.0.6' );
			wp_enqueue_style( 'wgrsvp-admin-style' );
			$css = $this->get_custom_css();
			wp_add_inline_style( 'wgrsvp-admin-style', wp_strip_all_tags( $css ) );
			if ( is_rtl() ) {
				wp_enqueue_style(
					'wgrsvp-admin-rtl',
					plugins_url( 'assets/css/admin-rtl.css', __FILE__ ),
					array( 'wgrsvp-admin-style' ),
					'8.0.6'
				);
			}
		}

		/**
		 * Shared admin behaviors: confirm prompts, select-on-click inputs, print control (no inline JS).
		 *
		 * @param string $hook_suffix Current admin screen id.
		 * @return void
		 */
		public function maybe_enqueue_admin_ui_script( $hook_suffix ) {
			if ( false === strpos( (string) $hook_suffix, 'wedding-rsvp' ) ) {
				return;
			}

			$path = plugin_dir_path( __FILE__ ) . 'assets/js/wgrsvp-admin-ui.js';
			$ver  = is_readable( $path ) ? (string) filemtime( $path ) : '8.0.6';

			wp_enqueue_script(
				'wgrsvp-admin-ui',
				plugins_url( 'assets/js/wgrsvp-admin-ui.js', __FILE__ ),
				array(),
				$ver,
				true
			);
			wgrsvp_set_script_translations( 'wgrsvp-admin-ui' );
			wp_localize_script(
				'wgrsvp-admin-ui',
				'wgrsvpAdminUi',
				array(
					'strings' => array(
						'confirmDeleteGuest'        => __( 'Delete this guest row?', 'wedding-party-rsvp' ),
						'confirmFactoryReset'       => __( 'This permanently deletes all guests and resets the plugin. Continue?', 'wedding-party-rsvp' ),
						'confirmRemoveThankyouTask' => __( 'Remove this task?', 'wedding-party-rsvp' ),
					),
				)
			);
		}

		/**
		 * Guest list: paste-import preview script (admin only).
		 *
		 * @param string $hook_suffix Current admin page.
		 * @return void
		 */
		public function maybe_enqueue_paste_import_script( $hook_suffix ) {
			if ( 'wedding-rsvp-main_page_wedding-rsvp-paste-guests' !== $hook_suffix ) {
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			wp_enqueue_script(
				'wgrsvp-paste-import',
				plugins_url( 'assets/js/wgrsvp-paste-import.js', __FILE__ ),
				array(),
				'8.0.6',
				true
			);
			wgrsvp_set_script_translations( 'wgrsvp-paste-import' );
			wp_localize_script(
				'wgrsvp-paste-import',
				'wgrsvpPasteImport',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wgrsvp_preview_paste' ),
					'i18n'    => array(
						'previewError' => __( 'Could not load preview.', 'wedding-party-rsvp' ),
						'previewEmpty' => __( 'Nothing to preview yet.', 'wedding-party-rsvp' ),
						/* translators: 1: rows shown in preview, 2: total parsed rows, 3: max rows per import. */
						'previewNote'  => __( 'Showing first %1$d of %2$d parsed row(s). Import still applies the full list (max %3$d).', 'wedding-party-rsvp' ),
					),
				)
			);
		}

		public function enqueue_frontend_styles() {
			wp_register_style( 'wgrsvp-front-style', false, array(), '8.0.6' );
			wp_enqueue_style( 'wgrsvp-front-style' );
			$css = $this->get_custom_css();
			// Late hardening: inline style must not contain tags; values inside get_custom_css() are sanitized scalars only.
			wp_add_inline_style( 'wgrsvp-front-style', wp_strip_all_tags( $css ) );
			if ( is_rtl() ) {
				wp_enqueue_style(
					'wgrsvp-front-rtl',
					plugins_url( 'assets/css/frontend-rtl.css', __FILE__ ),
					array( 'wgrsvp-front-style' ),
					'8.0.6'
				);
			}
		}

		private function get_custom_css() {
			$s = get_option( $this->opt_settings, array() );
			if ( ! is_array( $s ) ) {
				$s = array();
			}
			// Sanitize here for safe CSS; do not use esc_* while concatenating. Output is passed through wp_strip_all_tags() in enqueue_frontend_styles().
			$color = '';
			if ( isset( $s['primary_color'] ) && is_string( $s['primary_color'] ) && '' !== trim( $s['primary_color'] ) ) {
				$color = sanitize_hex_color( trim( $s['primary_color'] ) );
			}
			if ( '' === $color ) {
				$color = '#333333';
			}
			$font = 16;
			if ( isset( $s['font_size'] ) && '' !== (string) $s['font_size'] ) {
				$font = absint( $s['font_size'] );
			}
			if ( $font < 10 ) {
				$font = 10;
			} elseif ( $font > 32 ) {
				$font = 32;
			}

			return '
				/* FRONTEND STYLES */
				.wpr-wrapper { max-width: 600px; margin: 0 auto; font-size: ' . (string) (int) $font . 'px; }
				.wpr-guest-card { border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; background: #f9f9f9; border-radius:5px; }
				.wpr-field { margin-bottom: 12px; }
				.wpr-field label { display: block; font-weight: bold; margin-bottom: 5px; }
				.wpr-field input[type=text], .wpr-field input[type=email], .wpr-field select, .wpr-field textarea { width: 100%; padding: 8px; border:1px solid #ccc; border-radius:3px; }
				.wpr-button { background: ' . $color . '; color: #fff; padding: 12px 25px; border: none; cursor: pointer; font-size:1.1em; border-radius:3px; }
				.wpr-button:hover { opacity: 0.9; }
				.wpr-checkbox-group label { display:inline-block; margin-inline-end:10px; font-weight:normal; }
				.wpr-honey { display:none !important; visibility:hidden; }
				.wgrsvp-rsvp-feedback span { display: block; min-height: 1em; }
				.wgrsvp-rsvp-feedback:not(:empty) span:not(:empty) { padding: 12px; border-radius: 4px; border: 1px solid #c3c4c7; background: #f6f7f7; }
				.wgrsvp-party-lookup-hint { min-height: 1.25em; }
				.wgrsvp-rsvp-interactive.wgrsvp-rsvp-is-busy { opacity: 0.92; transition: opacity 0.2s ease; }
				.wgrsvp-menu-extra-dietary { margin-top: 8px; }
				.wgrsvp-email-hint { font-weight: 600; color: #1d2327; }
				.wgrsvp-gift-registries { margin: 0 0 1.15rem; padding: 0.75rem 0 0; border-top: 1px solid #ddd; }
				.wgrsvp-gift-registries__heading { margin: 0 0 0.5rem; font-size: 1.05em; }
				.wgrsvp-gift-registries__list { margin: 0; padding-left: 1.2rem; }
				.wgrsvp-gift-registries__list li { margin-bottom: 0.35rem; }
				.wgrsvp-guest-hub { margin-top: 1rem; }
				.wgrsvp-guest-hub__heading { margin-top: 0; }
				.wgrsvp-guest-hub__guests { margin: 0.75rem 0 0; padding-left: 1.2rem; }
				.wgrsvp-guest-hub__guests li { margin-bottom: 0.5rem; }
				.wgrsvp-guest-hub-root { margin-top: 1rem; }
				@media (prefers-reduced-motion: reduce) {
					.wgrsvp-party-lookup-hint { transition: none; }
					.wgrsvp-rsvp-interactive.wgrsvp-rsvp-is-busy { transition: none; }
				}
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

				/* Guest list: keep Notes column compact (link only; editing on Edit Guest screen). */
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat th.wgrsvp-col-admin-notes,
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat td.wgrsvp-col-admin-notes {
					width: 4.5rem;
					max-width: 6rem;
					padding-left: 6px;
					padding-right: 6px;
					white-space: nowrap;
					vertical-align: middle;
					text-align: center;
					box-sizing: border-box;
				}
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat th.wgrsvp-col-actions,
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat td.wgrsvp-col-actions {
					width: auto;
					min-width: 12rem;
					vertical-align: middle;
				}
				body.toplevel_page_wedding-rsvp-main .wgrsvp-admin-notes-link {
					display: inline-block;
					font-size: 12px;
					line-height: 1.25;
					text-align: center;
				}

				/* Guest list: RSVP column — only as wide as Yes / No / ? (read-only rows show status word). */
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat th.wgrsvp-col-rsvp,
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat td.wgrsvp-col-rsvp {
					width: 5.5rem;
					max-width: 7rem;
					min-width: 4.25rem;
					padding-left: 6px;
					padding-right: 6px;
					vertical-align: middle;
					text-align: center;
					box-sizing: border-box;
				}
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat td.wgrsvp-col-rsvp {
					font-size: 12px;
					line-height: 1.3;
				}
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat .wgrsvp-rsvp-select {
					width: auto;
					min-width: 3.25rem;
					max-width: 6.5rem;
					margin: 0 auto;
					display: block;
					font-size: 12px;
					padding: 2px 6px;
					box-sizing: border-box;
				}

				/* Guest list: Name column — narrower than default % so Menu / Contact get more room. */
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat th.wgrsvp-col-guest-name,
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat td.wgrsvp-col-guest-name {
					width: 11%;
					max-width: 14rem;
					min-width: 6.5rem;
					box-sizing: border-box;
					vertical-align: middle;
				}
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat td.wgrsvp-col-guest-name {
					overflow-wrap: anywhere;
					word-break: break-word;
				}
				body.toplevel_page_wedding-rsvp-main .wp-list-table.widefat td.wgrsvp-col-guest-name input[type="text"] {
					width: 100%;
					max-width: 100%;
					box-sizing: border-box;
					font-size: 12px;
				}
				
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
				}

				/* Guest list health tiles (planner dashboard). */
				.wgrsvp-guest-health { margin: 0 0 20px; padding: 0; }
				.wgrsvp-guest-health-heading { font-size: 1.1em; margin: 0 0 6px; }
				.wgrsvp-guest-health-intro { margin: 0 0 12px !important; }
				.wgrsvp-guest-health-tiles {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
					gap: 12px;
				}
				.wgrsvp-health-tile {
					display: flex;
					flex-direction: column;
					gap: 4px;
					padding: 14px 12px;
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 6px;
					text-decoration: none;
					color: #1d2327;
					box-shadow: 0 1px 2px rgba(0,0,0,0.04);
					min-height: 110px;
					box-sizing: border-box;
				}
				.wgrsvp-health-tile:hover { border-color: #2271b1; color: #1d2327; }
				.wgrsvp-health-tile-count { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
				.wgrsvp-health-tile-label { font-weight: 600; font-size: 13px; }
				.wgrsvp-health-tile-hint { font-size: 12px; color: #646970; line-height: 1.35; }
				.wgrsvp-health-tile-ok { border-left: 4px solid #46b450; }
				.wgrsvp-health-tile-warn { border-left: 4px solid #dba617; background: #fcf9e8; }
				.wgrsvp-health-tile-info { border-left: 4px solid #2271b1; }
				.wgrsvp-guest-health-allclear { margin-top: 12px !important; }
				@media (max-width: 782px) {
					.wgrsvp-guest-health-tiles { grid-template-columns: 1fr; }
					.wgrsvp-health-tile { min-height: 0; padding: 16px 14px; }
					.wgrsvp-health-tile-count { font-size: 2rem; }
					body.wedding-rsvp_page_wedding-rsvp-ops .wgrsvp-ops-statgrid { grid-template-columns: 1fr !important; }
					body.wedding-rsvp_page_wedding-rsvp-ops .wgrsvp-ops-card { padding: 18px 14px; }
					body.wedding-rsvp_page_wedding-rsvp-ops .wgrsvp-ops-card .button { min-height: 44px; padding: 0 14px; }
				}
				';
		}

		/**
		 * Column names for admin guest list sort (key => SQL identifier). Keys must match allowed sort URL params.
		 *
		 * @return array<string, string>
		 */
		/**
		 * Reduce guest-rows REST SELECT column list to an allowlisted set (filters cannot inject SQL).
		 *
		 * @param string $select_cols Comma-separated column names (may come from filter).
		 * @param string $fallback    Default list if none valid.
		 * @return string Comma-separated column names safe for SQL.
		 */
		private function wgrsvp_sanitize_guest_rows_rest_select_columns( $select_cols, $fallback ) {
			$default_allow = array(
				'id',
				'party_id',
				'guest_name',
				'is_child',
				'rsvp_status',
				'menu_choice',
				'child_menu_choice',
				'appetizer_choice',
				'hors_doeuvre_choice',
				'phone',
				'email',
				'address',
				'dietary_restrictions',
				'allergies',
				'song_request',
				'guest_message',
				'admin_notes',
				'gift_received',
				'thankyou_card_sent_on',
				'table_number',
				'wgrsvp_arrived_at',
			);
			/**
			 * Columns allowed in guest-rows REST SELECT (extend for Pro-only columns).
			 *
			 * @since 7.3.34
			 * @param array<int, string> $default_allow Base columns from core schema.
			 */
			$allowed = apply_filters( 'wgrsvp_guest_rows_rest_select_column_allowlist', $default_allow );
			if ( ! is_array( $allowed ) ) {
				$allowed = $default_allow;
			}
			$allowed_lc = array();
			$canonical  = array();
			foreach ( $allowed as $col ) {
				if ( ! is_string( $col ) || '' === $col ) {
					continue;
				}
				if ( ! preg_match( '/^[a-z0-9_]+$/i', $col ) ) {
					continue;
				}
				$lc                = strtolower( $col );
				$allowed_lc[ $lc ] = true;
				$canonical[ $lc ]  = $col;
			}
			$parts = explode( ',', (string) $select_cols );
			$out   = array();
			foreach ( $parts as $p ) {
				$p = trim( $p );
				if ( '' === $p ) {
					continue;
				}
				$lc = strtolower( $p );
				if ( isset( $allowed_lc[ $lc ] ) ) {
					$out[] = $canonical[ $lc ];
				}
			}
			$out = array_values( array_unique( $out ) );
			if ( empty( $out ) ) {
				return $fallback;
			}
			return implode( ', ', $out );
		}

		private function wgrsvp_get_admin_guest_list_order_by_map() {
			return array(
				'party_id'             => 'party_id',
				'guest_name'           => 'guest_name',
				'id'                   => 'id',
				'table_number'         => 'table_number',
				'is_child'             => 'is_child',
				'rsvp_status'          => 'rsvp_status',
				'menu_choice'          => 'menu_choice',
				'email'                => 'email',
				'phone'                => 'phone',
				'child_menu_choice'    => 'child_menu_choice',
				'dietary_restrictions' => 'dietary_restrictions',
				'allergies'            => 'allergies',
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
		 * @param string $filter_gap     Optional straggler filter: no_email, no_phone, no_address, pending_no_contact, accepted_meal_not_set, accepted_with_allergies.
		 * @return array{0: string, 1: array<int, mixed>} Tuple: SQL with placeholders, args for wgrsvp_query_cache_get_results().
		 */
		private function wgrsvp_build_admin_guest_list_query( $search_query, $filter_status, $filter_menu, $group_by_party, $orderby_key, $order, $filter_gap = '' ) {
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
			$gap_sql = $this->wgrsvp_guest_list_gap_sql_clause( $filter_gap );
			if ( '' !== $gap_sql ) {
				$sql_where[] = $gap_sql;
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
		 * Allowlisted “straggler” filter for the admin guest list (SQL fragment only; no placeholders).
		 *
		 * @param string $raw Raw request value.
		 * @return string Normalized key or empty.
		 */
		private function wgrsvp_sanitize_guest_list_gap( $raw ) {
			$key     = is_string( $raw ) ? sanitize_key( $raw ) : '';
			$allowed = array(
				'no_email',
				'no_phone',
				'no_address',
				'pending_no_contact',
				'accepted_meal_not_set',
				'accepted_with_allergies',
			);
			return in_array( $key, $allowed, true ) ? $key : '';
		}

		/**
		 * SQL WHERE fragment for straggler filters (fixed strings; combined with AND).
		 *
		 * @param string $gap Value from wgrsvp_sanitize_guest_list_gap().
		 * @return string SQL or empty.
		 */
		private function wgrsvp_guest_list_gap_sql_clause( $gap ) {
			$gap = $this->wgrsvp_sanitize_guest_list_gap( $gap );
			if ( '' === $gap ) {
				return '';
			}
			switch ( $gap ) {
				case 'no_email':
					return "(email IS NULL OR TRIM(COALESCE(email, '')) = '')";
				case 'no_phone':
					return "(phone IS NULL OR TRIM(COALESCE(phone, '')) = '')";
				case 'no_address':
					return "(address IS NULL OR TRIM(COALESCE(address, '')) = '')";
				case 'pending_no_contact':
					return "(rsvp_status = 'Pending' AND (email IS NULL OR TRIM(COALESCE(email, '')) = '') AND (phone IS NULL OR TRIM(COALESCE(phone, '')) = ''))";
				case 'accepted_meal_not_set':
					// Must combine with filter_status=Accepted; matches WGRSVP_Guest_Health::compute_metrics() meal logic.
					return "(((is_child IS NULL OR is_child = 0) AND (menu_choice IS NULL OR TRIM(COALESCE(menu_choice, '')) = '')) OR (is_child = 1 AND (child_menu_choice IS NULL OR TRIM(COALESCE(child_menu_choice, '')) = '')))";
				case 'accepted_with_allergies':
					// Must combine with filter_status=Accepted; matches WGRSVP_Guest_Health::compute_metrics().
					return "(allergies IS NOT NULL AND TRIM(COALESCE(allergies, '')) <> '')";
				default:
					return '';
			}
		}

		/**
		 * Admin screen: paste unstructured guest text and import (administrators only).
		 *
		 * @return void
		 */
		public function admin_page_paste_guest_list() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			wgrsvp_require_admin_module_or_die( 'paste_guests' );

			$wgrsvp_paste_notice = array();
			if ( isset( $_POST['wgrsvp_import_paste'], $_POST['wgrsvp_import_guests_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_import_guests', 'wgrsvp_import_guests_nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
				wgrsvp_require_admin_module_or_die( 'paste_guests' );
				$wgrsvp_paste_notice = $this->handle_paste_import();
			}

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Paste Guest List', 'wedding-party-rsvp' ); ?></h1>

				<?php if ( ! empty( $wgrsvp_paste_notice ) && isset( $wgrsvp_paste_notice[0], $wgrsvp_paste_notice[1] ) ) : ?>
					<div class="notice notice-<?php echo esc_attr( 'success' === $wgrsvp_paste_notice[0] ? 'success' : 'error' ); ?> is-dismissible"><p><?php echo esc_html( $wgrsvp_paste_notice[1] ); ?></p></div>
				<?php endif; ?>

				<?php do_action( 'wgrsvp_paste_guest_list_after_title', $this ); ?>

				<p class="description">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ); ?>"><?php esc_html_e( '← Back to Wedding Dashboard', 'wedding-party-rsvp' ); ?></a>
				</p>

				<div style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin:20px 0; max-width:960px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Paste guest list', 'wedding-party-rsvp' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Paste names and emails from a note or document—no CSV required. Use one invitation code (Party ID) for everyone, or add a line like Party: SMITH-01 before each group. Up to 200 guests per import.', 'wedding-party-rsvp' ); ?></p>
					<form method="post" class="wgrsvp-paste-import-form">
						<?php wp_nonce_field( 'wgrsvp_import_guests', 'wgrsvp_import_guests_nonce' ); ?>
						<p>
							<label><strong><?php esc_html_e( 'Default invitation code (Party ID)', 'wedding-party-rsvp' ); ?></strong>
								<input type="text" name="wgrsvp_paste_default_party" id="wgrsvp_paste_default_party" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. JONES-2026', 'wedding-party-rsvp' ); ?>">
							</label>
						</p>
						<p>
							<label for="wgrsvp_paste_blob"><strong><?php esc_html_e( 'Guest lines', 'wedding-party-rsvp' ); ?></strong></label>
							<textarea name="wgrsvp_paste_blob" id="wgrsvp_paste_blob" rows="10" class="large-text code" placeholder="<?php esc_attr_e( "Jane Doe jane@example.com\nJohn Doe, john@example.com,555-0100", 'wedding-party-rsvp' ); ?>"></textarea>
						</p>
						<p>
							<button type="button" class="button" id="wgrsvp-paste-preview-btn"><?php esc_html_e( 'Preview', 'wedding-party-rsvp' ); ?></button>
							<input type="submit" name="wgrsvp_import_paste" class="button button-primary" style="margin-left:6px;" value="<?php esc_attr_e( 'Import pasted guests', 'wedding-party-rsvp' ); ?>">
						</p>
					</form>
					<div id="wgrsvp-paste-preview-wrap" style="display:none; margin-top:12px; overflow:auto;">
						<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Invitation code', 'wedding-party-rsvp' ); ?></th><th><?php esc_html_e( 'Name', 'wedding-party-rsvp' ); ?></th><th><?php esc_html_e( 'Email', 'wedding-party-rsvp' ); ?></th><th><?php esc_html_e( 'Phone', 'wedding-party-rsvp' ); ?></th></tr></thead><tbody id="wgrsvp-paste-preview-body"></tbody></table>
						<p class="description" id="wgrsvp-paste-preview-note"></p>
					</div>
				</div>
			</div>
			<?php
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

			$dataview_spike = isset( $_GET['wgrsvp_dataview'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_dataview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI toggle.

			$can_manage_rsvp = current_user_can( 'manage_options' );

			if ( $can_manage_rsvp ) {
				add_thickbox();
			}

			$dash_js = plugins_url( 'assets/js/wgrsvp-admin-dashboard.js', __FILE__ );
			wp_register_script( 'wgrsvp-admin-dashboard', $dash_js, array(), '8.0.6', true );
			wp_enqueue_script( 'wgrsvp-admin-dashboard' );
			wgrsvp_set_script_translations( 'wgrsvp-admin-dashboard' );

			if ( $dataview_spike ) {
				$build_js  = plugin_dir_path( __FILE__ ) . 'build/index.js';
				$build_css = plugin_dir_path( __FILE__ ) . 'build/style-index.css';
				$asset_php = plugin_dir_path( __FILE__ ) . 'build/index.asset.php';
				if ( is_readable( $build_js ) && is_readable( $asset_php ) ) {
					$asset = include $asset_php;
					if ( ! is_array( $asset ) ) {
						$asset = array(
							'dependencies' => array(),
							'version'      => '8.0.6',
						);
					}
					wp_enqueue_script(
						'wgrsvp-guest-dataviews',
						plugins_url( 'build/index.js', __FILE__ ),
						isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
						isset( $asset['version'] ) ? $asset['version'] : '8.0.6',
						true
					);
					wgrsvp_set_script_translations( 'wgrsvp-guest-dataviews' );
					if ( is_readable( $build_css ) ) {
						wp_enqueue_style(
							'wgrsvp-guest-dataviews',
							plugins_url( 'build/style-index.css', __FILE__ ),
							array(),
							isset( $asset['version'] ) ? $asset['version'] : '8.0.6'
						);
					}
					$meal_elements = array();
					foreach ( (array) get_option( $this->opt_menu_adult, array() ) as $m ) {
						$m = trim( (string) $m );
						if ( '' !== $m ) {
							$meal_elements[] = array(
								'label' => $m,
								'value' => $m,
							);
						}
					}
					$meal_elements = apply_filters( 'wgrsvp_guest_dataviews_meal_filter_elements', $meal_elements );
					wp_localize_script(
						'wgrsvp-guest-dataviews',
						'wgrsvpGuestDataviews',
						array(
							'listUrl'      => admin_url( 'admin.php?page=wedding-rsvp-main' ),
							'mealElements' => $meal_elements,
							'proDataviews' => (
								function_exists( 'wgrsvp_is_pro_plugin_active' )
								&& wgrsvp_is_pro_plugin_active()
								&& function_exists( 'wgrsvp_is_pro_license_effectively_valid' )
								&& wgrsvp_is_pro_license_effectively_valid()
							),
							'i18n'         => array(
								'error'            => __( 'Could not load guest data.', 'wedding-party-rsvp' ),
								'filterMeal'       => __( 'Meal (filter)', 'wedding-party-rsvp' ),
								'filterDietary'    => __( 'Dietary contains', 'wedding-party-rsvp' ),
								'filterAllergies'  => __( 'Allergies contain', 'wedding-party-rsvp' ),
								'filterHasTable'   => __( 'Only guests with a table number', 'wedding-party-rsvp' ),
								'filterApplyNote'  => __( 'These filters apply to the table below (combined with column filters).', 'wedding-party-rsvp' ),
								'filterPlannerTag' => __( 'Planner tag (slug)', 'wedding-party-rsvp' ),
								'proFiltersNote'   => __( 'Check-in and planner tag filters, and the extra columns, require Wedding Party RSVP Pro with an active license.', 'wedding-party-rsvp' ),
							),
						)
					);
				} else {
					$spike_js = plugins_url( 'assets/js/wgrsvp-dataviews-spike.js', __FILE__ );
					wp_enqueue_script(
						'wgrsvp-dataviews-spike',
						$spike_js,
						array( 'wp-api-fetch' ),
						'8.0.6',
						true
					);
					wgrsvp_set_script_translations( 'wgrsvp-dataviews-spike' );
					wp_localize_script(
						'wgrsvp-dataviews-spike',
						'wgrsvpDataviewsSpike',
						array(
							'i18n' => array(
								'title'   => __( 'Read-only guest list (DataViews spike)', 'wedding-party-rsvp' ),
								'loading' => __( 'Loading read-only preview…', 'wedding-party-rsvp' ),
								'error'   => __( 'Could not load guest data.', 'wedding-party-rsvp' ),
								'note'    => __( 'This panel loads via the wgrsvp/v1/guest-rows REST route. Run npm run build in the plugin folder to enable the full DataViews UI.', 'wedding-party-rsvp' ),
							),
						)
					);
				}
			}

			// Actions (full editors only).
			$this->handle_admin_actions();

			if ( $can_manage_rsvp && isset( $_POST['wgrsvp_import_guests_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_import_guests', 'wgrsvp_import_guests_nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
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
			$filter_gap     = isset( $_GET['wgrsvp_gap'] ) ? sanitize_key( wp_unslash( (string) $_GET['wgrsvp_gap'] ) ) : '';
			$filter_gap     = $this->wgrsvp_sanitize_guest_list_gap( $filter_gap );
			$group_by_party = isset( $_GET['wgrsvp_group'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wgrsvp_group'] ) );

			$raw_saved_guest_segments  = get_user_meta( get_current_user_id(), 'wgrsvp_guest_list_segments', true );
			$saved_guest_list_segments = is_array( $raw_saved_guest_segments ) ? $raw_saved_guest_segments : array();

			$orderby        = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'party_id';
			$order          = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'ASC';
			$allowed_orders = array_keys( $this->wgrsvp_get_admin_guest_list_order_by_map() );
			if ( ! in_array( $orderby, $allowed_orders, true ) ) {
				$orderby = 'party_id';
			}
			$order = ( 'DESC' === $order ) ? 'DESC' : 'ASC';

			list( $query, $guest_query_prepare_args ) = $this->wgrsvp_build_admin_guest_list_query( $search_query, $filter_status, $filter_menu, $group_by_party, $orderby, $order, $filter_gap );

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
			if ( $filter_gap ) {
				$group_toggle_on  = add_query_arg( 'wgrsvp_gap', $filter_gap, $group_toggle_on );
				$group_toggle_off = add_query_arg( 'wgrsvp_gap', $filter_gap, $group_toggle_off );
			}

			?>
			<div class="wrap">
				<h1 style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
					<?php esc_html_e( 'Wedding Dashboard', 'wedding-party-rsvp' ); ?>
					<span style="background:#46b450; color:#fff; font-size:12px; padding:3px 8px; border-radius:10px;"><?php esc_html_e( 'Unlimited Guests', 'wedding-party-rsvp' ); ?></span>
				</h1>

				<?php if ( ! $can_manage_rsvp ) : ?>
					<div class="notice notice-info"><p><?php esc_html_e( 'Coordinator mode: you can review the guest list and meal counts. Only administrators can edit guests, import or export data, or change plugin settings.', 'wedding-party-rsvp' ); ?></p></div>
				<?php endif; ?>

				<?php do_action( 'wgrsvp_guest_list_after_title', $can_manage_rsvp, $this ); ?>

				<p class="description" style="margin:-4px 0 14px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-ops' ) ); ?>"><?php esc_html_e( 'Follow-up & day-of', 'wedding-party-rsvp' ); ?></a>
					— <?php esc_html_e( 'pending queue, mixed-household list, and a large-touch door search.', 'wedding-party-rsvp' ); ?>
					<?php if ( $can_manage_rsvp ) : ?>
						| <a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-vendor-packet' ) ); ?>"><?php esc_html_e( 'Vendor & venue packet', 'wedding-party-rsvp' ); ?></a>
						— <?php esc_html_e( 'one printable summary for catering.', 'wedding-party-rsvp' ); ?>
					<?php endif; ?>
				</p>

				<?php if ( $dataview_spike ) : ?>
					<div class="notice notice-info"><p>
						<?php esc_html_e( 'DataViews: read-only guest table (REST-driven). Use row actions to open a guest in the standard list below.', 'wedding-party-rsvp' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ); ?>"><?php esc_html_e( 'Return to standard guest list', 'wedding-party-rsvp' ); ?></a>
					</p></div>
					<div id="wgrsvp-dataviews-spike-root" class="wgrsvp-dataviews-spike-root" style="margin-bottom:24px;"></div>
				<?php else : ?>
					<p class="description" style="margin:-8px 0 16px;">
						<a href="<?php echo esc_url( add_query_arg( 'wgrsvp_dataview', '1', admin_url( 'admin.php?page=wedding-rsvp-main' ) ) ); ?>"><?php esc_html_e( 'Open read-only DataViews table (sort, filter, paginate via REST)', 'wedding-party-rsvp' ); ?></a>
					</p>
				<?php endif; ?>

				<div class="wpr-dashboard-grid">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main&filter_status=Accepted' ) ); ?>" class="wpr-stat-box" style="background:#46b450; color:#fff;">
						<h2><?php echo esc_html( (string) $total_accepted ); ?></h2>
						<small><?php esc_html_e( 'Attending', 'wedding-party-rsvp' ); ?></small>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main&filter_status=Declined' ) ); ?>" class="wpr-stat-box" style="background:#dc3232; color:#fff;">
						<h2><?php echo esc_html( (string) $total_declined ); ?></h2>
						<small><?php esc_html_e( 'Regrets', 'wedding-party-rsvp' ); ?></small>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main&filter_status=Pending' ) ); ?>" class="wpr-stat-box" style="background:#ffb900; color:#23282d;">
						<h2><?php echo esc_html( (string) $total_pending ); ?></h2>
						<small><?php esc_html_e( 'Pending', 'wedding-party-rsvp' ); ?></small>
					</a>
				</div>

				<?php
				$households_total         = isset( $agg['households_total'] ) ? (int) $agg['households_total'] : 0;
				$households_fully_replied = isset( $agg['households_fully_replied'] ) ? (int) $agg['households_fully_replied'] : 0;
				$households_pct           = ( $households_total > 0 ) ? (int) round( ( 100 * $households_fully_replied ) / $households_total ) : 0;
				$households_pct           = min( 100, max( 0, $households_pct ) );
				$pending_households_url   = add_query_arg(
					array(
						'page'          => 'wedding-rsvp-main',
						'filter_status' => 'Pending',
						'wgrsvp_group'  => '1',
					),
					admin_url( 'admin.php' )
				);
				?>
				<div style="background:#fff; border:1px solid #ccd0d4; padding:12px 14px; margin-bottom:20px;">
					<strong><?php esc_html_e( 'Household RSVP progress', 'wedding-party-rsvp' ); ?></strong>
					<p class="description" style="margin:4px 0 10px;">
						<?php
						printf(
							/* translators: 1: households with no pending members, 2: total distinct party IDs. */
							esc_html__( '%1$d of %2$d households have fully replied (no guest in the party is still Pending).', 'wedding-party-rsvp' ),
							(int) $households_fully_replied,
							(int) $households_total
						);
						?>
					</p>
					<?php if ( $households_total > 0 ) : ?>
					<div style="background:#f0f0f1; border-radius:4px; height:10px; overflow:hidden; max-width:420px;">
						<div style="background:#2271b1; height:100%; width:<?php echo esc_attr( (string) $households_pct ); ?>%;"></div>
					</div>
					<?php endif; ?>
					<p style="margin:10px 0 0;">
						<a class="button button-secondary" href="<?php echo esc_url( $pending_households_url ); ?>"><?php esc_html_e( 'Review Pending guests (grouped)', 'wedding-party-rsvp' ); ?></a>
					</p>
				</div>

				<?php if ( ! empty( $menu_stats_adult ) ) : ?>
				<div style="background:#fff; border:1px solid #ccd0d4; padding:10px; margin-bottom:20px;">
					<strong><?php esc_html_e( 'Menu Breakdown:', 'wedding-party-rsvp' ); ?></strong><br>
					<div style="margin-top:5px;">
						<?php
						foreach ( $menu_stats_adult as $stat ) :
							$active = ( $filter_menu === $stat->menu_choice ) ? 'active' : '';
							?>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'page'        => 'wedding-rsvp-main',
										'filter_menu' => $stat->menu_choice,
									),
									admin_url( 'admin.php' )
								)
							);
							?>
							" class="wpr-meal-tag <?php echo esc_attr( $active ); ?>"><?php echo esc_html( $stat->menu_choice ); ?> (<?php echo intval( $stat->count ); ?>)</a>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>

				<div style="background:#fff; border:1px solid #ccd0d4; padding:10px; margin-bottom:16px;">
					<strong><?php esc_html_e( 'Stragglers & data gaps:', 'wedding-party-rsvp' ); ?></strong>
					<span class="description" style="margin-left:6px;"><?php esc_html_e( 'Quick filters for follow-up (combine with search above).', 'wedding-party-rsvp' ); ?></span>
					<div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
						<?php
						$gap_base = array( 'page' => 'wedding-rsvp-main' );
						if ( $search_query ) {
							$gap_base['s'] = $search_query;
						}
						if ( $filter_status ) {
							$gap_base['filter_status'] = $filter_status;
						}
						if ( $filter_menu ) {
							$gap_base['filter_menu'] = $filter_menu;
						}
						if ( $group_by_party ) {
							$gap_base['wgrsvp_group'] = '1';
						}
						$gaps = array(
							'no_email'                => __( 'No email on file', 'wedding-party-rsvp' ),
							'no_phone'                => __( 'No phone on file', 'wedding-party-rsvp' ),
							'no_address'              => __( 'No mailing address', 'wedding-party-rsvp' ),
							'pending_no_contact'      => __( 'Pending & no email/phone', 'wedding-party-rsvp' ),
							'accepted_meal_not_set'   => __( 'Attending, meal not set', 'wedding-party-rsvp' ),
							'accepted_with_allergies' => __( 'Attending with allergies noted', 'wedding-party-rsvp' ),
						);
						foreach ( $gaps as $gk => $glabel ) {
							if ( 'accepted_meal_not_set' === $gk || 'accepted_with_allergies' === $gk ) {
								$gap_url_base                  = $gap_base;
								$gap_url_base['filter_status'] = 'Accepted';
								$u                             = add_query_arg( array_merge( $gap_url_base, array( 'wgrsvp_gap' => $gk ) ), admin_url( 'admin.php' ) );
							} else {
								$u = add_query_arg( array_merge( $gap_base, array( 'wgrsvp_gap' => $gk ) ), admin_url( 'admin.php' ) );
							}
							$on = ( $filter_gap === $gk );
							?>
							<a href="<?php echo esc_url( $u ); ?>" class="<?php echo esc_attr( 'button' . ( $on ? ' button-primary' : '' ) ); ?>"><?php echo esc_html( $glabel ); ?></a>
							<?php
						}
						if ( $filter_gap ) {
							$clear_gap = add_query_arg( $gap_base, admin_url( 'admin.php' ) );
							?>
							<a href="<?php echo esc_url( $clear_gap ); ?>" class="button"><?php esc_html_e( 'Clear straggler filter', 'wedding-party-rsvp' ); ?></a>
							<?php
						}
						?>
					</div>
				</div>

				<?php if ( ! empty( $saved_guest_list_segments ) || ( current_user_can( 'manage_options' ) && $can_manage_rsvp ) ) : ?>
				<div style="background:#fff; border:1px solid #ccd0d4; padding:10px; margin-bottom:20px;">
					<strong><?php esc_html_e( 'Saved filter shortcuts', 'wedding-party-rsvp' ); ?></strong>
					<span class="description" style="margin-left:6px;"><?php esc_html_e( 'Personal quick links for your WordPress user.', 'wedding-party-rsvp' ); ?></span>
					<?php if ( ! empty( $saved_guest_list_segments ) ) : ?>
					<div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
						<?php
						foreach ( $saved_guest_list_segments as $seg_row ) {
							if ( ! is_array( $seg_row ) || ! isset( $seg_row['id'], $seg_row['label'] ) ) {
								continue;
							}
							$seg_q = array( 'page' => 'wedding-rsvp-main' );
							if ( ! empty( $seg_row['s'] ) ) {
								$seg_q['s'] = (string) $seg_row['s'];
							}
							if ( ! empty( $seg_row['filter_status'] ) ) {
								$seg_q['filter_status'] = (string) $seg_row['filter_status'];
							}
							if ( ! empty( $seg_row['filter_menu'] ) ) {
								$seg_q['filter_menu'] = (string) $seg_row['filter_menu'];
							}
							if ( ! empty( $seg_row['wgrsvp_gap'] ) ) {
								$seg_q['wgrsvp_gap'] = (string) $seg_row['wgrsvp_gap'];
							}
							if ( ! empty( $seg_row['wgrsvp_group'] ) && '1' === (string) $seg_row['wgrsvp_group'] ) {
								$seg_q['wgrsvp_group'] = '1';
							}
							$seg_url = add_query_arg( $seg_q, admin_url( 'admin.php' ) );
							$seg_id  = (string) $seg_row['id'];
							?>
							<span style="display:inline-flex; align-items:center; gap:4px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; padding:2px 8px;">
								<a href="<?php echo esc_url( $seg_url ); ?>"><?php echo esc_html( (string) $seg_row['label'] ); ?></a>
								<?php if ( current_user_can( 'manage_options' ) ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=wgrsvp_delete_guest_list_segment&wgrsvp_seg_id=' . rawurlencode( $seg_id ) ), 'wgrsvp_delete_guest_list_segment_' . $seg_id ) ); ?>" aria-label="<?php esc_attr_e( 'Remove shortcut', 'wedding-party-rsvp' ); ?>" style="text-decoration:none; color:#b32d2e;">×</a>
								<?php endif; ?>
							</span>
							<?php
						}
						?>
					</div>
					<?php endif; ?>
					<?php if ( current_user_can( 'manage_options' ) && $can_manage_rsvp ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
						<?php wp_nonce_field( 'wgrsvp_save_guest_list_segment', 'wgrsvp_save_guest_list_segment_nonce' ); ?>
						<input type="hidden" name="action" value="wgrsvp_save_guest_list_segment">
						<input type="hidden" name="wgrsvp_seg_s" value="<?php echo esc_attr( $search_query ); ?>">
						<input type="hidden" name="wgrsvp_seg_filter_status" value="<?php echo esc_attr( $filter_status ); ?>">
						<input type="hidden" name="wgrsvp_seg_filter_menu" value="<?php echo esc_attr( $filter_menu ); ?>">
						<input type="hidden" name="wgrsvp_seg_gap" value="<?php echo esc_attr( $filter_gap ); ?>">
						<?php if ( $group_by_party ) : ?>
							<input type="hidden" name="wgrsvp_seg_group" value="1">
						<?php endif; ?>
						<label style="display:inline-flex; align-items:center; gap:6px;">
							<span class="screen-reader-text"><?php esc_html_e( 'Shortcut name', 'wedding-party-rsvp' ); ?></span>
							<input type="text" name="wgrsvp_segment_label" style="min-width:200px;" placeholder="<?php esc_attr_e( 'Name this filter…', 'wedding-party-rsvp' ); ?>" maxlength="80">
						</label>
						<button type="submit" class="button"><?php esc_html_e( 'Save current filters', 'wedding-party-rsvp' ); ?></button>
					</form>
					<p class="description" style="margin:6px 0 0;"><?php esc_html_e( 'Stores search, RSVP status, meal, straggler filter, and group-by for one-click return.', 'wedding-party-rsvp' ); ?></p>
					<?php endif; ?>
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
							<input type="text" name="party_id" required placeholder="<?php esc_attr_e( 'Code', 'wedding-party-rsvp' ); ?>" style="width:100px;" title="<?php echo esc_attr__( 'Invitation code (Party ID)', 'wedding-party-rsvp' ); ?>">
							<input type="text" name="guest_name" required placeholder="<?php esc_attr_e( 'Name', 'wedding-party-rsvp' ); ?>" style="width:120px;">
							<?php if ( ! wgrsvp_is_pro_plugin_active() ) : ?>
							<div class="wpr-pro-placeholder" style="width:60px; display:inline-block; margin:0 5px;"><?php esc_html_e( 'Kid (Pro)', 'wedding-party-rsvp' ); ?></div>
							<?php endif; ?>
							<input type="submit" name="wgrsvp_add_guest_btn" class="button button-primary" value="<?php esc_attr_e( 'Add', 'wedding-party-rsvp' ); ?>">
						</form>
					</div>
					<?php endif; ?>
					<form method="get" class="wpr-flex-row"<?php echo ( ! $can_manage_rsvp ) ? ' style="' . esc_attr( 'flex-grow:1' ) . '"' : ''; ?>>
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
						<?php if ( $filter_gap ) : ?>
							<input type="hidden" name="wgrsvp_gap" value="<?php echo esc_attr( $filter_gap ); ?>">
						<?php endif; ?>
						<input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'wedding-party-rsvp' ); ?>">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'wedding-party-rsvp' ); ?>">
						<a class="button" href="<?php echo esc_url( $group_by_party ? $group_toggle_off : $group_toggle_on ); ?>">
						<?php
						if ( $group_by_party ) {
							esc_html_e( 'Ungroup list', 'wedding-party-rsvp' );
						} else {
							esc_html_e( 'Group by party', 'wedding-party-rsvp' );
						}
						?>
						</a>
						<?php if ( $search_query || $filter_status || $filter_menu || $group_by_party || $filter_gap ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'wedding-party-rsvp' ); ?></a>
						<?php endif; ?>
					</form>
				</div>

				<?php if ( $can_manage_rsvp ) : ?>
				<div style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin-bottom:20px;">
					<div class="wpr-flex-row wpr-justify-between">
					<form method="post" enctype="multipart/form-data" class="wpr-flex-row">
						<?php wp_nonce_field( 'wgrsvp_import_guests', 'wgrsvp_import_guests_nonce' ); ?>
						<strong><?php esc_html_e( 'CSV Import:', 'wedding-party-rsvp' ); ?></strong>
						<input type="file" name="csv_file" accept=".csv" required>
						<input type="submit" name="wgrsvp_import_csv" class="button" value="<?php esc_attr_e( 'Upload', 'wedding-party-rsvp' ); ?>">
					</form>
					<form method="post">
						<?php wp_nonce_field( 'wgrsvp_export_guest_list', 'wgrsvp_export_guest_list_nonce' ); ?>
						<input type="hidden" name="export_s" value="<?php echo esc_attr( $search_query ); ?>">
						<input type="hidden" name="export_filter_status" value="<?php echo esc_attr( $filter_status ); ?>">
						<input type="hidden" name="export_filter_menu" value="<?php echo esc_attr( $filter_menu ); ?>">
						<input type="hidden" name="export_wgrsvp_gap" value="<?php echo esc_attr( $filter_gap ); ?>">
						<input type="hidden" name="export_orderby" value="<?php echo esc_attr( $orderby ); ?>">
						<input type="hidden" name="export_order" value="<?php echo esc_attr( $order ); ?>">
						<input type="hidden" name="export_wgrsvp_group" value="<?php echo esc_attr( $group_by_party ? '1' : '' ); ?>">
						<p class="description" style="margin:0 0 8px 0;"><?php esc_html_e( 'Exports match the search and filters above.', 'wedding-party-rsvp' ); ?></p>
						<input type="submit" name="wgrsvp_export_csv" class="button button-secondary" value="<?php esc_attr_e( 'Export CSV', 'wedding-party-rsvp' ); ?>">
						<input type="submit" name="wgrsvp_export_checkin_pdf" class="button button-secondary" style="margin-left:6px;" value="<?php esc_attr_e( 'Export check-in PDF', 'wedding-party-rsvp' ); ?>">
						<input type="submit" name="wgrsvp_export_catering_pdf" class="button button-secondary" style="margin-left:6px;" value="<?php esc_attr_e( 'Caterer summary (PDF)', 'wedding-party-rsvp' ); ?>">
						<input type="submit" name="wgrsvp_export_catering_csv" class="button button-secondary" style="margin-left:6px;" value="<?php esc_attr_e( 'Caterer summary (CSV)', 'wedding-party-rsvp' ); ?>">
						<label style="margin-left:12px;vertical-align:middle;"><input type="checkbox" name="wgrsvp_catering_include_non_accepted" value="1"> <?php esc_html_e( 'Include non-Accepted rows in counts', 'wedding-party-rsvp' ); ?></label>
					</form>
					</div>
					<p class="description" style="margin:12px 0 0;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-paste-guests' ) ); ?>"><?php esc_html_e( 'Paste Guest List', 'wedding-party-rsvp' ); ?></a>
						— <?php esc_html_e( 'import names and emails from a note without a CSV file.', 'wedding-party-rsvp' ); ?>
					</p>
				</div>
				<?php endif; ?>

				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<th width="8%"><a href="<?php echo esc_url( $this->get_sort_link( 'party_id', $orderby, $order ) ); ?>" title="<?php echo esc_attr__( 'Shared household code — same label as Party ID in CSV files.', 'wedding-party-rsvp' ); ?>"><?php esc_html_e( 'Invitation code', 'wedding-party-rsvp' ); ?></a></th>
						<th class="wgrsvp-col-guest-name" width="11%"><a href="<?php echo esc_url( $this->get_sort_link( 'guest_name', $orderby, $order ) ); ?>"><?php esc_html_e( 'Name', 'wedding-party-rsvp' ); ?></a></th>
						<th width="3%"><?php esc_html_e( 'Kid', 'wedding-party-rsvp' ); ?></th>
						<th class="wgrsvp-col-rsvp"><a href="<?php echo esc_url( $this->get_sort_link( 'rsvp_status', $orderby, $order ) ); ?>"><?php esc_html_e( 'RSVP', 'wedding-party-rsvp' ); ?></a></th>
						<th width="12%"><?php esc_html_e( 'Menu', 'wedding-party-rsvp' ); ?></th>
						<th width="5%"><?php esc_html_e( 'Tbl', 'wedding-party-rsvp' ); ?></th>
						<th width="25%"><?php esc_html_e( 'Contact/Info', 'wedding-party-rsvp' ); ?></th>
						<th class="wgrsvp-col-admin-notes" title="<?php echo esc_attr__( 'Planner-only admin notes (opens Edit Guest)', 'wedding-party-rsvp' ); ?>"><?php esc_html_e( 'Notes', 'wedding-party-rsvp' ); ?></th>
						<th class="wgrsvp-col-actions"><?php esc_html_e( 'Actions', 'wedding-party-rsvp' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( $can_manage_rsvp ) : ?>
							<?php
							foreach ( $guests as $guest ) :
								?>
								<tr id="<?php echo esc_attr( 'wgrsvp-guest-row-' . (string) absint( $guest->id ) ); ?>"><form method="post">
									<input type="hidden" name="id" value="<?php echo esc_attr( $guest->id ); ?>">
									<?php wp_nonce_field( 'wgrsvp_edit_guest', 'wgrsvp_edit_guest' ); ?>

									<td><input type="text" name="party_id" value="<?php echo esc_attr( $guest->party_id ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'e.g. SMITH-01', 'wedding-party-rsvp' ); ?>" title="<?php echo esc_attr__( 'Invitation code (Party ID)', 'wedding-party-rsvp' ); ?>"></td>
									<td class="wgrsvp-col-guest-name"><input type="text" name="guest_name" value="<?php echo esc_attr( $guest->guest_name ); ?>" placeholder="<?php esc_attr_e( 'Name', 'wedding-party-rsvp' ); ?>"></td>

									<td style="text-align:center;">
										<?php if ( wgrsvp_is_pro_plugin_active() ) : ?>
											<span class="description"><?php echo esc_html( ! empty( $guest->is_child ) ? __( 'Yes', 'wedding-party-rsvp' ) : __( 'No', 'wedding-party-rsvp' ) ); ?></span>
										<?php else : ?>
											<div class="wpr-pro-placeholder"><?php esc_html_e( 'Pro', 'wedding-party-rsvp' ); ?></div>
										<?php endif; ?>
									</td>

									<td class="wgrsvp-col-rsvp"><select name="rsvp_status" class="wgrsvp-rsvp-select"><option value="Pending" <?php selected( $guest->rsvp_status, 'Pending' ); ?>><?php esc_html_e( '?', 'wedding-party-rsvp' ); ?></option><option value="Accepted" <?php selected( $guest->rsvp_status, 'Accepted' ); ?>><?php esc_html_e( 'Yes', 'wedding-party-rsvp' ); ?></option><option value="Declined" <?php selected( $guest->rsvp_status, 'Declined' ); ?>><?php esc_html_e( 'No', 'wedding-party-rsvp' ); ?></option></select></td>

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
										<div class="wpr-pro-placeholder" style="margin-bottom:2px;"><?php esc_html_e( 'Child menu (available in Pro)', 'wedding-party-rsvp' ); ?></div>
										<div style="display:flex; gap:2px;">
											<div class="wpr-pro-placeholder"><?php esc_html_e( 'Appetizer (Pro)', 'wedding-party-rsvp' ); ?></div>
											<div class="wpr-pro-placeholder"><?php esc_html_e( 'Hors d\'oeuvres (Pro)', 'wedding-party-rsvp' ); ?></div>
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
											<div class="wpr-pro-placeholder"><?php esc_html_e( 'Table # (Pro)', 'wedding-party-rsvp' ); ?></div>
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

									<td class="wgrsvp-col-admin-notes">
										<?php if ( wgrsvp_is_pro_plugin_active() && current_user_can( 'manage_options' ) ) : ?>
											<?php
											$pro_notes_url = wp_nonce_url( admin_url( 'admin.php?page=wedding-rsvp-edit&id=' . absint( $guest->id ) ), 'wpr_pro_view_edit_guest', 'wpr_pro_edit' ) . '#wpr-admin-notes';
											$notes_title   = ! empty( $guest->admin_notes )
												? __( 'Edit admin notes on Edit Guest', 'wedding-party-rsvp' )
												: __( 'Add admin notes on Edit Guest', 'wedding-party-rsvp' );
											?>
											<a class="wgrsvp-admin-notes-link" href="<?php echo esc_url( $pro_notes_url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $notes_title ); ?>"><?php esc_html_e( 'Notes', 'wedding-party-rsvp' ); ?><?php echo ! empty( $guest->admin_notes ) ? ' <span class="wgrsvp-admin-notes-indicator" aria-hidden="true">•</span>' : ''; ?></a>
										<?php elseif ( wgrsvp_is_pro_plugin_active() ) : ?>
											<span class="description"><?php esc_html_e( '—', 'wedding-party-rsvp' ); ?></span>
										<?php else : ?>
											<span class="description" title="<?php echo esc_attr__( 'Available in Wedding Party RSVP Pro', 'wedding-party-rsvp' ); ?>"><?php esc_html_e( '—', 'wedding-party-rsvp' ); ?></span>
										<?php endif; ?>
									</td>

									<td class="wgrsvp-col-actions" style="white-space:nowrap;">
										<?php
										$party_rsvp_url = $this->get_public_party_rsvp_url( $guest->party_id );
										?>
										<a class="button button-small" href="<?php echo esc_url( $party_rsvp_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open RSVP', 'wedding-party-rsvp' ); ?></a>
										<button type="button" class="button button-small wgrsvp-copy-rsvp" data-url="<?php echo esc_attr( $party_rsvp_url ); ?>" data-label="<?php echo esc_attr__( 'Copy link', 'wedding-party-rsvp' ); ?>" data-copied="<?php echo esc_attr__( 'Copied!', 'wedding-party-rsvp' ); ?>"><?php esc_html_e( 'Copy link', 'wedding-party-rsvp' ); ?></button>
										<?php if ( $this->wgrsvp_may_use_pro_guest_list_comm_links() && is_email( $guest->email ) ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $this->wgrsvp_pro_single_guest_email_send_url( (int) $guest->id ) ); ?>" title="<?php echo esc_attr__( 'Send invitation email to this guest (uses Email & SMS template)', 'wedding-party-rsvp' ); ?>"><span class="dashicons dashicons-email" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Email invite', 'wedding-party-rsvp' ); ?></span></a>
										<?php endif; ?>
										<?php if ( $this->wgrsvp_may_use_pro_guest_list_comm_links() && ! empty( $guest->phone ) ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $this->wgrsvp_pro_single_guest_sms_send_url( (int) $guest->id ) ); ?>" title="<?php echo esc_attr__( 'Send SMS invitation to this guest', 'wedding-party-rsvp' ); ?>"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'SMS invite', 'wedding-party-rsvp' ); ?></span></a>
										<?php endif; ?>
										<button type="submit" name="wgrsvp_update_guest" class="button button-primary button-small" title="<?php esc_attr_e( 'Save', 'wedding-party-rsvp' ); ?>"><span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php esc_html_e( 'Save', 'wedding-party-rsvp' ); ?></button>
										<button type="submit" name="wgrsvp_delete_guest" class="button button-small button-link-delete wgrsvp-admin-confirm" data-wgrsvp-confirm="confirmDeleteGuest" title="<?php esc_attr_e( 'Delete', 'wedding-party-rsvp' ); ?>"><span class="dashicons dashicons-trash"></span></button>
									</td>
								</form></tr>
							<?php endforeach; ?>
						<?php else : ?>
							<?php foreach ( $guests as $guest ) : ?>
								<tr id="<?php echo esc_attr( 'wgrsvp-guest-row-' . (string) absint( $guest->id ) ); ?>">
									<td><?php echo esc_html( $guest->party_id ); ?></td>
									<td class="wgrsvp-col-guest-name"><?php echo esc_html( $guest->guest_name ); ?></td>
									<td style="text-align:center;"><?php echo esc_html( ! empty( $guest->is_child ) ? __( 'Yes', 'wedding-party-rsvp' ) : __( 'No', 'wedding-party-rsvp' ) ); ?></td>
									<td class="wgrsvp-col-rsvp"><?php echo esc_html( $guest->rsvp_status ); ?></td>
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
									<td class="wgrsvp-col-admin-notes">
										<?php if ( wgrsvp_is_pro_plugin_active() && current_user_can( 'manage_options' ) ) : ?>
											<?php
											$pro_notes_url = wp_nonce_url( admin_url( 'admin.php?page=wedding-rsvp-edit&id=' . absint( $guest->id ) ), 'wpr_pro_view_edit_guest', 'wpr_pro_edit' ) . '#wpr-admin-notes';
											$notes_title   = ! empty( $guest->admin_notes )
												? __( 'Edit admin notes on Edit Guest', 'wedding-party-rsvp' )
												: __( 'Add admin notes on Edit Guest', 'wedding-party-rsvp' );
											?>
											<a class="wgrsvp-admin-notes-link" href="<?php echo esc_url( $pro_notes_url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $notes_title ); ?>"><?php esc_html_e( 'Notes', 'wedding-party-rsvp' ); ?><?php echo ! empty( $guest->admin_notes ) ? ' <span class="wgrsvp-admin-notes-indicator" aria-hidden="true">•</span>' : ''; ?></a>
										<?php else : ?>
											<span class="description"><?php esc_html_e( '—', 'wedding-party-rsvp' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="wgrsvp-col-actions" style="white-space:nowrap;">
										<?php
										$party_rsvp_url = $this->get_public_party_rsvp_url( $guest->party_id );
										?>
										<a class="button button-small" href="<?php echo esc_url( $party_rsvp_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open RSVP', 'wedding-party-rsvp' ); ?></a>
										<button type="button" class="button button-small wgrsvp-copy-rsvp" data-url="<?php echo esc_attr( $party_rsvp_url ); ?>" data-label="<?php echo esc_attr__( 'Copy link', 'wedding-party-rsvp' ); ?>" data-copied="<?php echo esc_attr__( 'Copied!', 'wedding-party-rsvp' ); ?>"><?php esc_html_e( 'Copy link', 'wedding-party-rsvp' ); ?></button>
										<?php if ( $this->wgrsvp_may_use_pro_guest_list_comm_links() && is_email( $guest->email ) ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $this->wgrsvp_pro_single_guest_email_send_url( (int) $guest->id ) ); ?>" title="<?php echo esc_attr__( 'Send invitation email to this guest (uses Email & SMS template)', 'wedding-party-rsvp' ); ?>"><span class="dashicons dashicons-email" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Email invite', 'wedding-party-rsvp' ); ?></span></a>
										<?php endif; ?>
										<?php if ( $this->wgrsvp_may_use_pro_guest_list_comm_links() && ! empty( $guest->phone ) ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $this->wgrsvp_pro_single_guest_sms_send_url( (int) $guest->id ) ); ?>" title="<?php echo esc_attr__( 'Send SMS invitation to this guest', 'wedding-party-rsvp' ); ?>"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'SMS invite', 'wedding-party-rsvp' ); ?></span></a>
										<?php endif; ?>
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

			// Pro merged hub: this screen is registered by Wedding Party RSVP Pro only.
			if ( $this->wgrsvp_pro_owns_merged_settings_screen() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-settings' ) );
				exit;
			}

			if ( isset( $_POST['wgrsvp_save_admin_modules_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_save_admin_modules', 'wgrsvp_save_admin_modules_nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
				if ( ! function_exists( 'wpr_pro_effective_license_is_valid' ) || ! wpr_pro_effective_license_is_valid() ) {
					$posted = array();
					if ( isset( $_POST['wgrsvp_admin_modules'] ) && is_array( $_POST['wgrsvp_admin_modules'] ) ) {
						$posted = map_deep( wp_unslash( $_POST['wgrsvp_admin_modules'] ), 'sanitize_text_field' );
					}
					if ( ! is_array( $posted ) ) {
						$posted = array();
					}
					wgrsvp_store_admin_modules_option( $posted );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Admin menu visibility saved.', 'wedding-party-rsvp' ) . '</p></div>';
				}
			}

			if ( isset( $_POST['wgrsvp_factory_reset_plugin_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_factory_reset_plugin', 'wgrsvp_factory_reset_plugin_nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
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

					if ( class_exists( 'WGRSVP_Audit_Trail', false ) ) {
						WGRSVP_Audit_Trail::truncate_table();
					}

					delete_option( $this->opt_menu_adult );
					delete_option( $this->opt_settings );
					delete_option( $this->opt_license );
					delete_option( WGRSVP_Growth_Checklist::OPT_PANEL_DISMISSED );
					delete_option( WGRSVP_Client_Summary_Portal::OPTION_STATE );
					delete_option( 'wgrsvp_admin_modules' );

					$this->clear_stats_cache();

					/**
					 * After the free plugin factory reset (guests, audit, options). Pro and other add-ons may clear their own data.
					 *
					 * @since 7.3.32
					 */
					do_action( 'wgrsvp_after_factory_reset' );

					echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'System Reset Complete. All data and settings have been cleared.', 'wedding-party-rsvp' ) . '</strong></p></div>';
				}
			}

			if ( isset( $_POST['wgrsvp_save_general_settings_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_save_general_settings', 'wgrsvp_save_general_settings_nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
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
							'redirect_url'            => isset( $_POST['redirect_url'] ) ? wgrsvp_sanitize_redirect_url_setting( wp_unslash( $_POST['redirect_url'] ) ) : '',
							// Welcome title: plain text (`esc_html` on the RSVP form). Closed message: limited HTML via `wp_kses_post` + `wp_kses_post`/`wpautop` when deadline passed.
							'welcome_title'           => isset( $_POST['welcome_title'] ) ? sanitize_text_field( wp_unslash( $_POST['welcome_title'] ) ) : '',
							'deadline_closed_message' => isset( $_POST['deadline_closed_message'] ) ? wp_kses_post( wp_unslash( $_POST['deadline_closed_message'] ) ) : '',
							'enable_add_to_calendar'  => isset( $_POST['enable_add_to_calendar'] ) ? 1 : 0,
							'event_title'             => isset( $_POST['event_title'] ) ? sanitize_text_field( wp_unslash( $_POST['event_title'] ) ) : '',
							'event_start'             => $this->wgrsvp_combine_event_datetime_for_save(
								isset( $_POST['event_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['event_start_date'] ) ) : '',
								isset( $_POST['event_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['event_start_time'] ) ) : ''
							),
							'event_end'               => $this->wgrsvp_combine_event_datetime_for_save(
								isset( $_POST['event_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['event_end_date'] ) ) : '',
								isset( $_POST['event_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['event_end_time'] ) ) : ''
							),
							'event_location'          => isset( $_POST['event_location'] ) ? sanitize_text_field( wp_unslash( $_POST['event_location'] ) ) : '',
							// Calendar description and reminder body: plain multiline (HTML stripped); ICS and email consumers treat as text.
							'event_description'       => isset( $_POST['event_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['event_description'] ) ) : '',
							'deadline_nudges_enabled' => isset( $_POST['deadline_nudges_enabled'] ) ? 1 : 0,
							'deadline_nudge_days'     => isset( $_POST['deadline_nudge_days'] ) ? sanitize_text_field( wp_unslash( $_POST['deadline_nudge_days'] ) ) : '7,3,1',
							'deadline_nudge_subject'  => isset( $_POST['deadline_nudge_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['deadline_nudge_subject'] ) ) : '',
							'deadline_nudge_body'     => isset( $_POST['deadline_nudge_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['deadline_nudge_body'] ) ) : '',
							'deadline_nudge_include_declined' => isset( $_POST['deadline_nudge_include_declined'] ) ? 1 : 0,
							'gift_registries'           => class_exists( 'WGRSVP_Gift_Registries', false )
								? WGRSVP_Gift_Registries::sanitize_from_request( (array) wp_unslash( $_POST ) )
								: array(),
							'gift_registry_heading'     => isset( $_POST['gift_registry_heading'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['gift_registry_heading'] ) ) : '',
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
			$ev_start = $this->wgrsvp_split_event_datetime_for_inputs( isset( $s['event_start'] ) ? (string) $s['event_start'] : '' );
			$ev_end   = $this->wgrsvp_split_event_datetime_for_inputs( isset( $s['event_end'] ) ? (string) $s['event_end'] : '' );
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
				<?php if ( ! function_exists( 'wpr_pro_effective_license_is_valid' ) || ! wpr_pro_effective_license_is_valid() ) : ?>
					<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px; border-left:4px solid #50575e;">
						<h3><?php esc_html_e( 'Admin menu visibility', 'wedding-party-rsvp' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Uncheck tools you do not use. This hides admin menus and blocks direct URLs to those screens. Your data is kept in the database. When Wedding Party RSVP Pro is active with a valid license, manage these toggles under Pro Settings instead.', 'wedding-party-rsvp' ); ?></p>
						<form method="post" action="">
							<?php wp_nonce_field( 'wgrsvp_save_admin_modules', 'wgrsvp_save_admin_modules_nonce' ); ?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><?php esc_html_e( 'Features', 'wedding-party-rsvp' ); ?></th>
									<td>
										<fieldset>
											<?php
											$wgrsvp_am_labels = array(
												'paste_guests'      => __( 'Paste Guest List', 'wedding-party-rsvp' ),
												'menu_options'      => __( 'Menu Options', 'wedding-party-rsvp' ),
												'gifts_report'      => __( 'Gifts & thank-you', 'wedding-party-rsvp' ),
												'thankyou_tracker'  => __( 'Thank-you checklist', 'wedding-party-rsvp' ),
												'client_summary'    => __( 'Client summary (admin + public link)', 'wedding-party-rsvp' ),
												'vendor_packet'     => __( 'Vendor & venue packet', 'wedding-party-rsvp' ),
												'ops_center'        => __( 'Follow-up & day-of', 'wedding-party-rsvp' ),
												'caterer_portal'    => __( 'Caterer portal (admin + public link)', 'wedding-party-rsvp' ),
												'audit_log'         => __( 'Audit log', 'wedding-party-rsvp' ),
											);
											foreach ( wgrsvp_admin_module_keys() as $wgrsvp_am_key ) {
												$wgrsvp_am_label = isset( $wgrsvp_am_labels[ $wgrsvp_am_key ] ) ? $wgrsvp_am_labels[ $wgrsvp_am_key ] : $wgrsvp_am_key;
												?>
												<label style="display:block; margin-bottom:6px;">
													<input type="checkbox" name="wgrsvp_admin_modules[<?php echo esc_attr( $wgrsvp_am_key ); ?>]" value="1" <?php checked( wgrsvp_admin_module_enabled( $wgrsvp_am_key ) ); ?>>
													<?php echo esc_html( $wgrsvp_am_label ); ?>
												</label>
												<?php
											}
											?>
										</fieldset>
									</td>
								</tr>
							</table>
							<?php submit_button( __( 'Save menu visibility', 'wedding-party-rsvp' ) ); ?>
						</form>
					</div>
				<?php endif; ?>
				<form method="post">
					<?php wp_nonce_field( 'wgrsvp_save_general_settings', 'wgrsvp_save_general_settings_nonce' ); ?>
					
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
						<p><label for="wgrsvp_welcome_title"><strong><?php esc_html_e( 'Custom Welcome Title:', 'wedding-party-rsvp' ); ?></strong></label><br>
						<input type="text" id="wgrsvp_welcome_title" name="welcome_title" value="<?php echo esc_attr( $s['welcome_title'] ?? '' ); ?>" style="width:100%;max-width:520px;" placeholder="<?php esc_attr_e( 'e.g. Welcome to Sarah & John\'s Wedding!', 'wedding-party-rsvp' ); ?>">
						<button type="button" class="button wgrsvp-ai-wording-btn" data-wgrsvp-ai-context="welcome_title" data-wgrsvp-ai-target="#wgrsvp_welcome_title"><?php esc_html_e( 'AI wording…', 'wedding-party-rsvp' ); ?></button><br>
						<small><?php esc_html_e( 'Replaces the default "Party: [ID]" title. Draft with AI only when WordPress 7.0+ and a provider are configured—review before saving.', 'wedding-party-rsvp' ); ?></small></p>
						<?php
						$wgrsvp_gift_saved = array();
						if ( isset( $s['gift_registries'] ) && is_array( $s['gift_registries'] ) ) {
							$wgrsvp_gift_saved = $s['gift_registries'];
						}
						$wgrsvp_gift_rows = WGRSVP_Gift_Registries::MAX_ITEMS;
						?>
						<hr style="margin:1.25em 0;border:0;border-top:1px solid #ddd;">
						<p><label for="wgrsvp_gift_registry_heading"><strong><?php esc_html_e( 'Gift registry heading (optional)', 'wedding-party-rsvp' ); ?></strong></label><br>
						<input type="text" id="wgrsvp_gift_registry_heading" name="gift_registry_heading" value="<?php echo esc_attr( isset( $s['gift_registry_heading'] ) ? (string) $s['gift_registry_heading'] : '' ); ?>" style="width:100%;max-width:520px;" placeholder="<?php esc_attr_e( 'e.g. Gift registries', 'wedding-party-rsvp' ); ?>">
						<br><small class="description"><?php esc_html_e( 'Shown above registry links on the RSVP page when at least one URL is set. Leave blank for the default label.', 'wedding-party-rsvp' ); ?></small></p>
						<p><strong><?php esc_html_e( 'Gift registry links', 'wedding-party-rsvp' ); ?></strong></p>
						<p class="description"><?php esc_html_e( 'Guests see these links on the invitation RSVP page (after they enter their Party ID). Use full https URLs in any row below (up to 15). Unused blank rows are ignored when you save.', 'wedding-party-rsvp' ); ?></p>
						<table class="widefat striped" style="max-width:720px;" role="presentation">
							<thead><tr><th scope="col"><?php esc_html_e( 'Link label', 'wedding-party-rsvp' ); ?></th><th scope="col"><?php esc_html_e( 'URL', 'wedding-party-rsvp' ); ?></th></tr></thead>
							<tbody>
							<?php
							for ( $wgrsvp_gi = 0; $wgrsvp_gi < $wgrsvp_gift_rows; $wgrsvp_gi++ ) {
								$wgrsvp_gl = '';
								$wgrsvp_gu = '';
								if ( isset( $wgrsvp_gift_saved[ $wgrsvp_gi ] ) && is_array( $wgrsvp_gift_saved[ $wgrsvp_gi ] ) ) {
									$wgrsvp_gl = isset( $wgrsvp_gift_saved[ $wgrsvp_gi ]['label'] ) ? (string) $wgrsvp_gift_saved[ $wgrsvp_gi ]['label'] : '';
									$wgrsvp_gu = isset( $wgrsvp_gift_saved[ $wgrsvp_gi ]['url'] ) ? (string) $wgrsvp_gift_saved[ $wgrsvp_gi ]['url'] : '';
								}
								?>
								<tr>
									<td><input type="text" name="<?php echo esc_attr( WGRSVP_Gift_Registries::POST_LABEL_KEY ); ?>[]" value="<?php echo esc_attr( $wgrsvp_gl ); ?>" class="regular-text" style="width:100%;"></td>
									<td><input type="url" name="<?php echo esc_attr( WGRSVP_Gift_Registries::POST_URL_KEY ); ?>[]" value="<?php echo esc_attr( $wgrsvp_gu ); ?>" class="large-text" style="width:100%;" placeholder="https://"></td>
								</tr>
								<?php
							}
							?>
							</tbody>
						</table>
					</div>

					<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px;">
						<h3 id="wgrsvp-logistics-heading"><?php esc_html_e( 'Logistics', 'wedding-party-rsvp' ); ?></h3>
						<p><label><strong><?php esc_html_e( 'RSVP Page URL:', 'wedding-party-rsvp' ); ?></strong></label><br><input type="text" name="rsvp_page_url" value="<?php echo esc_url( $s['rsvp_page_url'] ?? '' ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'e.g. https://mysite.com/rsvp', 'wedding-party-rsvp' ); ?>"></p>
						<p><label><strong><?php esc_html_e( 'RSVP Deadline:', 'wedding-party-rsvp' ); ?></strong></label><br><input type="date" name="deadline_date" value="<?php echo esc_attr( $s['deadline_date'] ?? '' ); ?>"></p>
						<p><label><input type="checkbox" name="deadline_nudges_enabled" value="1" <?php checked( ! empty( $s['deadline_nudges_enabled'] ) ); ?>> <?php esc_html_e( 'Send automatic RSVP reminder emails (daily check; uses your site email / SMTP)', 'wedding-party-rsvp' ); ?></label></p>
						<p><label><strong><?php esc_html_e( 'Reminder days before deadline (comma-separated):', 'wedding-party-rsvp' ); ?></strong></label><br>
						<input type="text" name="deadline_nudge_days" value="<?php echo esc_attr( $s['deadline_nudge_days'] ?? '7,3,1' ); ?>" class="regular-text" placeholder="7,3,1"></p>
						<p><label><strong><?php esc_html_e( 'Reminder email subject:', 'wedding-party-rsvp' ); ?></strong></label><br>
						<input type="text" name="deadline_nudge_subject" value="<?php echo esc_attr( $s['deadline_nudge_subject'] ?? '' ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Reminder: RSVP by {deadline}', 'wedding-party-rsvp' ); ?>"></p>
						<p><label for="wgrsvp_deadline_nudge_body"><strong><?php esc_html_e( 'Reminder email body (plain text):', 'wedding-party-rsvp' ); ?></strong></label><br>
						<textarea id="wgrsvp_deadline_nudge_body" name="deadline_nudge_body" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Placeholders: {guest_name}, {party_id}, {rsvp_url}, {deadline}', 'wedding-party-rsvp' ); ?>"><?php echo esc_textarea( $s['deadline_nudge_body'] ?? '' ); ?></textarea></p>
						<p><label><input type="checkbox" name="deadline_nudge_include_declined" value="1" <?php checked( ! empty( $s['deadline_nudge_include_declined'] ) ); ?>> <?php esc_html_e( 'Include guests who previously declined (off = pending only)', 'wedding-party-rsvp' ); ?></label></p>
						<?php if ( wgrsvp_is_pro_plugin_active() ) : ?>
							<p class="description"><?php esc_html_e( 'Pro: you can also enable SMS reminders (Twilio) under Wedding RSVP → Settings → General (Pro tab).', 'wedding-party-rsvp' ); ?></p>
						<?php endif; ?>
						<p><label for="wgrsvp_deadline_closed_message"><strong><?php esc_html_e( 'Message when RSVP is closed (optional):', 'wedding-party-rsvp' ); ?></strong></label><br>
						<textarea id="wgrsvp_deadline_closed_message" name="deadline_closed_message" rows="4" style="width:100%;" placeholder="<?php esc_attr_e( 'Shown instead of the default closed text after the deadline. Basic HTML allowed.', 'wedding-party-rsvp' ); ?>"><?php echo esc_textarea( $s['deadline_closed_message'] ?? '' ); ?></textarea>
						<button type="button" class="button wgrsvp-ai-wording-btn" data-wgrsvp-ai-context="deadline_closed_message" data-wgrsvp-ai-target="#wgrsvp_deadline_closed_message"><?php esc_html_e( 'AI wording…', 'wedding-party-rsvp' ); ?></button>
						<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Draft only—review before saving.', 'wedding-party-rsvp' ); ?></span></p>
						<p><label><strong><?php esc_html_e( 'Redirect Success URL:', 'wedding-party-rsvp' ); ?></strong></label><br><input type="text" name="redirect_url" value="<?php echo esc_url( $s['redirect_url'] ?? '' ); ?>" style="width:100%"></p>
						<p><label><input type="checkbox" name="enable_add_to_calendar" value="1" <?php checked( ! isset( $s['enable_add_to_calendar'] ) || ! empty( $s['enable_add_to_calendar'] ) ); ?>> <?php esc_html_e( 'Show “Add to calendar” after RSVP (downloads a .ics file)', 'wedding-party-rsvp' ); ?></label></p>
						<p><label><strong><?php esc_html_e( 'Event title (calendar):', 'wedding-party-rsvp' ); ?></strong></label><br>
						<input type="text" name="event_title" value="<?php echo esc_attr( $s['event_title'] ?? '' ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'e.g. Ceremony & reception', 'wedding-party-rsvp' ); ?>"></p>
						<p><strong><?php esc_html_e( 'Event start (your site timezone):', 'wedding-party-rsvp' ); ?></strong><br>
						<input type="date" name="event_start_date" value="<?php echo esc_attr( $ev_start['date'] ); ?>">
						<input type="time" name="event_start_time" value="<?php echo esc_attr( $ev_start['time'] ); ?>"></p>
						<p><strong><?php esc_html_e( 'Event end (optional):', 'wedding-party-rsvp' ); ?></strong><br>
						<input type="date" name="event_end_date" value="<?php echo esc_attr( $ev_end['date'] ); ?>">
						<input type="time" name="event_end_time" value="<?php echo esc_attr( $ev_end['time'] ); ?>">
						<br><small><?php esc_html_e( 'Leave end blank to default to two hours after start.', 'wedding-party-rsvp' ); ?></small></p>
						<p><label><strong><?php esc_html_e( 'Venue / address:', 'wedding-party-rsvp' ); ?></strong></label><br>
						<input type="text" name="event_location" value="<?php echo esc_attr( $s['event_location'] ?? '' ); ?>" style="width:100%"></p>
						<p><label><strong><?php esc_html_e( 'Calendar notes (optional):', 'wedding-party-rsvp' ); ?></strong></label><br>
						<textarea name="event_description" rows="3" style="width:100%;"><?php echo esc_textarea( $s['event_description'] ?? '' ); ?></textarea></p>
					</div>

					<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-bottom:20px;">
						<h3><?php esc_html_e( 'AI wording snippets (copy to your page or email)', 'wedding-party-rsvp' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Generate draft text with the WordPress AI Client (7.0+). Nothing is saved until you copy it into a block or template and save settings elsewhere.', 'wedding-party-rsvp' ); ?></p>
						<p><label for="wgrsvp_ai_snippet_save_the_date"><strong><?php esc_html_e( 'Save the date (paragraph)', 'wedding-party-rsvp' ); ?></strong></label><br>
						<textarea id="wgrsvp_ai_snippet_save_the_date" rows="4" style="width:100%;" class="large-text" placeholder="<?php esc_attr_e( 'Click the button to generate a draft…', 'wedding-party-rsvp' ); ?>"></textarea><br>
						<button type="button" class="button wgrsvp-ai-wording-btn" data-wgrsvp-ai-context="save_the_date" data-wgrsvp-ai-target="#wgrsvp_ai_snippet_save_the_date"><?php esc_html_e( 'AI wording…', 'wedding-party-rsvp' ); ?></button></p>
						<p><label for="wgrsvp_ai_snippet_rsvp_deadline"><strong><?php esc_html_e( 'RSVP deadline reminder (paragraph)', 'wedding-party-rsvp' ); ?></strong></label><br>
						<textarea id="wgrsvp_ai_snippet_rsvp_deadline" rows="4" style="width:100%;" class="large-text" placeholder="<?php esc_attr_e( 'Click the button to generate a draft…', 'wedding-party-rsvp' ); ?>"></textarea><br>
						<button type="button" class="button wgrsvp-ai-wording-btn" data-wgrsvp-ai-context="rsvp_deadline_reminder" data-wgrsvp-ai-target="#wgrsvp_ai_snippet_rsvp_deadline"><?php esc_html_e( 'AI wording…', 'wedding-party-rsvp' ); ?></button></p>
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
					<?php wp_nonce_field( 'wgrsvp_factory_reset_plugin', 'wgrsvp_factory_reset_plugin_nonce' ); ?>
					<div style="background:#fff; padding:20px; border:1px solid #dc3232; border-left:4px solid #dc3232;">
						<h3 style="color:#dc3232; margin-top:0;"><?php esc_html_e( 'Danger zone: erase all guest data', 'wedding-party-rsvp' ); ?></h3>
						<p><?php esc_html_e( 'This removes every guest row, resets plugin settings, and clears the license key stored in the free plugin. You cannot undo it. Export your list first if you need a backup.', 'wedding-party-rsvp' ); ?></p>
						<input type="submit" name="wgrsvp_factory_reset" class="button button-link-delete wgrsvp-admin-confirm" data-wgrsvp-confirm="confirmFactoryReset" style="color:red; text-decoration:none; border:1px solid red; padding:5px 15px;" value="<?php esc_attr_e( 'Erase all data & reset plugin', 'wedding-party-rsvp' ); ?>">
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

			wgrsvp_require_admin_module_or_die( 'menu_options' );

			if ( isset( $_POST['wgrsvp_save_entree_menu_options_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_save_entree_menu_options', 'wgrsvp_save_entree_menu_options_nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
				wgrsvp_require_admin_module_or_die( 'menu_options' );
				if ( isset( $_POST['wgrsvp_save_menu'] ) ) {
					// Entrée labels are plain text lines (public `<select>` options); HTML is not supported in menu names.
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
					<?php wp_nonce_field( 'wgrsvp_save_entree_menu_options', 'wgrsvp_save_entree_menu_options_nonce' ); ?>
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
		 * Verifies `check_admin_referer` for each action, then `manage_options`, before reading POST fields.
		 *
		 * @return void
		 */
		private function handle_admin_actions() {
			global $wpdb;

			if ( isset( $_POST['wgrsvp_add_guest'] ) ) {
				check_admin_referer( 'wgrsvp_add_guest', 'wgrsvp_add_guest' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
				wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );
				if ( isset( $_POST['wgrsvp_add_guest_btn'] ) ) {
					$ins = array(
						'party_id'   => isset( $_POST['party_id'] ) ? sanitize_text_field( wp_unslash( $_POST['party_id'] ) ) : '',
						'guest_name' => isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '',
					);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP insert; clear_stats_cache() busts query object cache after.
					$wpdb->insert( $this->table_name, $ins );
					$new_id = (int) $wpdb->insert_id;
					if ( $new_id > 0 && class_exists( 'WGRSVP_Audit_Trail', false ) ) {
						$ch = WGRSVP_Audit_Trail::diff_for_insert( $ins );
						WGRSVP_Audit_Trail::log(
							array(
								'guest_id'      => $new_id,
								'party_id'      => (string) $ins['party_id'],
								'action'        => 'insert',
								'actor_type'    => 'user',
								'actor_user_id' => get_current_user_id(),
								'source'        => 'admin_inline',
								'changes'       => $ch,
							)
						);
					}

					$this->clear_stats_cache();
				}
			}

			if ( isset( $_POST['wgrsvp_edit_guest'] ) ) {
				check_admin_referer( 'wgrsvp_edit_guest', 'wgrsvp_edit_guest' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
				wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );
				if ( isset( $_POST['wgrsvp_update_guest'] ) ) {
					$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

					$allowed_rsvp = array( 'Pending', 'Accepted', 'Declined' );
					$rsvp_raw     = isset( $_POST['rsvp_status'] ) ? sanitize_text_field( wp_unslash( $_POST['rsvp_status'] ) ) : 'Pending';
					$rsvp_status  = in_array( $rsvp_raw, $allowed_rsvp, true ) ? $rsvp_raw : 'Pending';

					$old_row = null;
					if ( $id > 0 && class_exists( 'WGRSVP_Audit_Trail', false ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
						$old_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table_name, $id ) );
					}

					$upd_data = array(
						'party_id'    => isset( $_POST['party_id'] ) ? sanitize_text_field( wp_unslash( $_POST['party_id'] ) ) : '',
						'guest_name'  => isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '',
						'rsvp_status' => $rsvp_status,
						'menu_choice' => isset( $_POST['menu_choice'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_choice'] ) ) : '',
						'email'       => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
						'phone'       => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
					);

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP update; clear_stats_cache() busts query object cache after.
					$upd = $wpdb->update(
						$this->table_name,
						$upd_data,
						array( 'id' => $id )
					);

					if ( class_exists( 'WGRSVP_Audit_Trail', false ) && false !== $upd && $wpdb->rows_affected > 0 && is_object( $old_row ) ) {
						$changes = WGRSVP_Audit_Trail::diff_assoc( $old_row, $upd_data );
						if ( ! empty( $changes ) ) {
							WGRSVP_Audit_Trail::log(
								array(
									'guest_id'      => $id,
									'party_id'      => (string) $old_row->party_id,
									'action'        => 'update',
									'actor_type'    => 'user',
									'actor_user_id' => get_current_user_id(),
									'source'        => 'admin_inline',
									'changes'       => $changes,
								)
							);
						}
					}

					$this->clear_stats_cache();

					$party_after = isset( $_POST['party_id'] ) ? sanitize_text_field( wp_unslash( $_POST['party_id'] ) ) : '';
					do_action( 'wgrsvp_after_rsvp_save', $party_after );
				} elseif ( isset( $_POST['wgrsvp_delete_guest'] ) ) {
					$id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
					$del_row = null;
					if ( $id > 0 && class_exists( 'WGRSVP_Audit_Trail', false ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
						$del_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table_name, $id ) );
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP delete; clear_stats_cache() busts query object cache after.
					$wpdb->delete( $this->table_name, array( 'id' => $id ) );
					if ( class_exists( 'WGRSVP_Audit_Trail', false ) && is_object( $del_row ) ) {
						WGRSVP_Audit_Trail::log(
							array(
								'guest_id'      => $id,
								'party_id'      => (string) $del_row->party_id,
								'action'        => 'delete',
								'actor_type'    => 'user',
								'actor_user_id' => get_current_user_id(),
								'source'        => 'admin_inline',
								'changes'       => array(
									'_deleted' => array(
										'old' => '0',
										'new' => '1',
									),
								),
							)
						);
					}
					$this->clear_stats_cache();
				}
			}

			if ( isset( $_POST['wgrsvp_seed_demo'] ) ) {
				check_admin_referer( 'wgrsvp_seed_demo', 'wgrsvp_seed_demo' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
				}
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
							$did = (int) $wpdb->insert_id;
							if ( $did > 0 && class_exists( 'WGRSVP_Audit_Trail', false ) ) {
								WGRSVP_Audit_Trail::log(
									array(
										'guest_id'      => $did,
										'party_id'      => (string) $row['party_id'],
										'action'        => 'insert',
										'actor_type'    => 'user',
										'actor_user_id' => get_current_user_id(),
										'source'        => 'demo_seed',
										'changes'       => WGRSVP_Audit_Trail::diff_for_insert( $row ),
									)
								);
							}
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
		 * Verifies `check_admin_referer`, then `manage_options`, before reading filter fields from `$_POST`.
		 *
		 * @return void
		 */
		public function handle_csv_export() {
			// Only run for our export form POST so capability/nonce are not evaluated on every admin request.
			if ( ! isset( $_POST['wgrsvp_export_csv'], $_POST['wgrsvp_export_guest_list_nonce'] ) ) {
				return;
			}
			check_admin_referer( 'wgrsvp_export_guest_list', 'wgrsvp_export_guest_list_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			$guests = $this->wgrsvp_get_guest_rows_for_list_export_from_post();

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
		 * Streams check-in PDF (same filters as CSV).
		 *
		 * @return void
		 */
		public function handle_checkin_pdf_export() {
			if ( ! isset( $_POST['wgrsvp_export_checkin_pdf'], $_POST['wgrsvp_export_guest_list_nonce'] ) ) {
				return;
			}
			check_admin_referer( 'wgrsvp_export_guest_list', 'wgrsvp_export_guest_list_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			$guests = $this->wgrsvp_get_guest_rows_for_list_export_from_post();
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-checkin-pdf.php';
			WGRSVP_Checkin_PDF::stream_rows( $guests, null );
		}

		/**
		 * Caterer summary CSV (aggregated meals by table).
		 *
		 * @return void
		 */
		public function handle_catering_summary_csv_export() {
			if ( ! isset( $_POST['wgrsvp_export_catering_csv'], $_POST['wgrsvp_export_guest_list_nonce'] ) ) {
				return;
			}
			check_admin_referer( 'wgrsvp_export_guest_list', 'wgrsvp_export_guest_list_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}
			$guests = $this->wgrsvp_get_guest_rows_for_list_export_from_post();
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-vendor-catering-export.php';
			$accepted_only = ! isset( $_POST['wgrsvp_catering_include_non_accepted'] );
			WGRSVP_Vendor_Catering_Export::stream_csv( $guests, $accepted_only, null );
		}

		/**
		 * Caterer summary PDF (aggregated meals by table).
		 *
		 * @return void
		 */
		public function handle_catering_summary_pdf_export() {
			if ( ! isset( $_POST['wgrsvp_export_catering_pdf'], $_POST['wgrsvp_export_guest_list_nonce'] ) ) {
				return;
			}
			check_admin_referer( 'wgrsvp_export_guest_list', 'wgrsvp_export_guest_list_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}
			$guests = $this->wgrsvp_get_guest_rows_for_list_export_from_post();
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgrsvp-vendor-catering-export.php';
			$accepted_only = ! isset( $_POST['wgrsvp_catering_include_non_accepted'] );
			WGRSVP_Vendor_Catering_Export::stream_pdf( $guests, $accepted_only, null );
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

							$ins = array(
								'party_id'   => sanitize_text_field( $row[0] ),
								'guest_name' => sanitize_text_field( $row[1] ),
								'email'      => isset( $row[2] ) ? sanitize_email( $row[2] ) : '',
								'phone'      => isset( $row[3] ) ? sanitize_text_field( $row[3] ) : '',
							);
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- RSVP insert; clear_stats_cache() busts query object cache after import.
							$wpdb->insert( $this->table_name, $ins );
							$cid = (int) $wpdb->insert_id;
							if ( $cid > 0 && class_exists( 'WGRSVP_Audit_Trail', false ) ) {
								WGRSVP_Audit_Trail::log(
									array(
										'guest_id'      => $cid,
										'party_id'      => (string) $ins['party_id'],
										'action'        => 'insert',
										'actor_type'    => 'user',
										'actor_user_id' => get_current_user_id(),
										'source'        => 'admin_import_csv',
										'changes'       => WGRSVP_Audit_Trail::diff_for_insert( $ins ),
									)
								);
							}
						}
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $file );
					$this->clear_stats_cache(); // Clear cache on import
				}
			}
		}

		/**
		 * Import guests from pasted unstructured text (admin).
		 *
		 * @return array{0:string,1:string} Notice type (success|error) and message.
		 */
		private function handle_paste_import() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return array(
					'error',
					__( 'You do not have sufficient permissions to import guests.', 'wedding-party-rsvp' ),
				);
			}
			if ( ! class_exists( 'WGRSVP_Paste_Import' ) ) {
				return array(
					'error',
					__( 'Paste import is unavailable.', 'wedding-party-rsvp' ),
				);
			}

			check_admin_referer( 'wgrsvp_import_guests', 'wgrsvp_import_guests_nonce' );

			$blob          = isset( $_POST['wgrsvp_paste_blob'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wgrsvp_paste_blob'] ) ) : '';
			$default_party = isset( $_POST['wgrsvp_paste_default_party'] ) ? sanitize_text_field( wp_unslash( $_POST['wgrsvp_paste_default_party'] ) ) : '';

			if ( '' === trim( $blob ) ) {
				return array(
					'error',
					__( 'Paste some lines to import.', 'wedding-party-rsvp' ),
				);
			}

			$rows = WGRSVP_Paste_Import::parse_block( $blob, $default_party );
			if ( empty( $rows ) ) {
				return array(
					'error',
					__( 'No guests could be parsed. Set a default Party ID, or start a block with a line like Party: YOUR-ID.', 'wedding-party-rsvp' ),
				);
			}

			global $wpdb;
			wp_cache_delete( 'wgrsvp_query_writes', 'wgrsvp_queries' );

			$imported = 0;
			$skipped  = 0;
			foreach ( $rows as $idx => $row ) {
				if ( ! is_array( $row ) ) {
					++$skipped;
					continue;
				}
				/**
				 * Filter one parsed paste-import row before insert.
				 *
				 * @since 7.3.12
				 * @param array<string,string> $row Row with party_id, guest_name, email, phone.
				 * @param int                  $idx Zero-based index.
				 */
				$filtered = apply_filters( 'wgrsvp_paste_import_row', $row, (int) $idx );
				if ( false === $filtered ) {
					++$skipped;
					continue;
				}
				if ( ! is_array( $filtered ) ) {
					++$skipped;
					continue;
				}
				$row = $filtered;

				$party = isset( $row['party_id'] ) ? sanitize_text_field( (string) $row['party_id'] ) : '';
				$name  = isset( $row['guest_name'] ) ? sanitize_text_field( (string) $row['guest_name'] ) : '';
				if ( '' === $party || '' === $name ) {
					++$skipped;
					continue;
				}

				$email = isset( $row['email'] ) ? sanitize_email( (string) $row['email'] ) : '';
				$phone = isset( $row['phone'] ) ? sanitize_text_field( (string) $row['phone'] ) : '';

				$ins = array(
					'party_id'   => $party,
					'guest_name' => $name,
					'email'      => $email,
					'phone'      => $phone,
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk insert; clear_stats_cache() after loop.
				$wpdb->insert( $this->table_name, $ins );
				$pid_ins = (int) $wpdb->insert_id;
				if ( $pid_ins > 0 && class_exists( 'WGRSVP_Audit_Trail', false ) ) {
					WGRSVP_Audit_Trail::log(
						array(
							'guest_id'      => $pid_ins,
							'party_id'      => (string) $party,
							'action'        => 'insert',
							'actor_type'    => 'user',
							'actor_user_id' => get_current_user_id(),
							'source'        => 'paste_import',
							'changes'       => WGRSVP_Audit_Trail::diff_for_insert( $ins ),
						)
					);
				}
				++$imported;
			}

			$this->clear_stats_cache();

			/**
			 * Fires after a bulk guest import from paste (and CSV can use same hook in future).
			 *
			 * @since 7.3.12
			 * @param array<string,mixed> $stats Keys: source, imported, skipped.
			 */
			do_action(
				'wgrsvp_after_bulk_guest_import',
				array(
					'source'   => 'paste',
					'imported' => $imported,
					'skipped'  => $skipped,
				)
			);

			return array(
				'success',
				sprintf(
					/* translators: 1: imported count, 2: skipped count. */
					__( 'Imported %1$d guest(s). Skipped %2$d line(s).', 'wedding-party-rsvp' ),
					$imported,
					$skipped
				),
			);
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

			$settings  = get_option( $this->opt_settings, array() );
			$raw_redir = is_array( $settings ) && isset( $settings['redirect_url'] ) ? trim( (string) $settings['redirect_url'] ) : '';
			if ( '' !== $raw_redir ) {
				$url = function_exists( 'wgrsvp_resolve_stored_redirect_url' )
					? wgrsvp_resolve_stored_redirect_url( $raw_redir )
					: esc_url_raw( $raw_redir );
				$url = esc_url_raw( (string) $url );
				if ( '' !== $url ) {
					wp_safe_redirect( $url );
					exit;
				}
			}

			if ( class_exists( 'WGRSVP_ICS' ) ) {
				$thanks_url = WGRSVP_ICS::get_thank_you_redirect_url( $party_id );
				if ( '' !== $thanks_url ) {
					wp_safe_redirect( $thanks_url );
					exit;
				}
			}

			set_transient( self::TRANSIENT_RSVP_FORM_SUCCESS_FLASH, '1', 60 );
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

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public thank-you display; nonce verified below.
			if ( isset( $_GET['wgrsvp_thanks'], $_GET['party_id'], $_GET['wgrsvp_thanks_nonce'] ) && class_exists( 'WGRSVP_ICS' ) ) {
				$ty_flag = sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_thanks'] ) );
				$ty_pid  = sanitize_text_field( wp_unslash( (string) $_GET['party_id'] ) );
				$ty_non  = sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_thanks_nonce'] ) );
				if ( '1' === $ty_flag && WGRSVP_ICS::verify_thanks_nonce( $ty_pid, $ty_non ) ) {
					$po_notes = class_exists( 'WPR_Pro_Frontend', false ) ? WPR_Pro_Frontend::flush_plus_one_notices_html( $ty_pid ) : '';
					$out      = '<div class="wpr-wrapper">' . $po_notes . '<div class="wpr-guest-card" style="color:green;border:1px solid green;padding:15px;margin-bottom:20px;background:#eaffea;">';
					$out     .= esc_html__( 'Thank you! Your RSVP has been updated.', 'wedding-party-rsvp' );
					$out     .= '</div>';
					$out     .= $this->wgrsvp_render_guest_hub_markup( $ty_pid );
					$out     .= '</div>';
					return $out;
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			// Check for Success Message from Init Redirect
			if ( get_transient( self::TRANSIENT_RSVP_FORM_SUCCESS_FLASH ) ) {
				delete_transient( self::TRANSIENT_RSVP_FORM_SUCCESS_FLASH );
				return '<div class="wpr-wrapper"><div style="color:green;border:1px solid green;padding:15px;margin-bottom:20px;background:#eaffea;">' . esc_html__( 'Thank you! Your RSVP has been updated.', 'wedding-party-rsvp' ) . '</div></div>';
			}

			$output = '<div class="wpr-wrapper">';

			// --- LOGIN FORM CHECK ---
			$party_id = '';

			// 1. Check POST Login (submit requires nonce; fail closed if the field is missing or invalid).
			if ( isset( $_POST['wgrsvp_front_party_login_submit'] ) ) {
				if ( ! isset( $_POST['wgrsvp_front_party_login_nonce'] ) ) {
					wp_die( esc_html__( 'The sign-in form is missing a security token. Please open this page again and try once more.', 'wedding-party-rsvp' ), esc_html__( 'RSVP', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
				}
				if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_front_party_login_nonce'] ) ), 'wgrsvp_front_party_login' ) ) {
					wp_die( esc_html__( 'Security check failed. Please open this page again and try once more.', 'wedding-party-rsvp' ), esc_html__( 'RSVP', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
				}
				if ( isset( $_POST['wpr_party_id'] ) ) {
					$party_id = sanitize_text_field( wp_unslash( $_POST['wpr_party_id'] ) );
				}
			}

			// 2. Check URL Param
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( empty( $party_id ) && isset( $_GET['party_id'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$party_id = sanitize_text_field( wp_unslash( $_GET['party_id'] ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table identifier via %i (WP 6.2+); party bound with %s.
			$guests = $party_id ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE party_id = %s', $this->table_name, $party_id ) ) : array();

			if ( empty( $guests ) ) {
				$use_party_lookup_ia = $this->enqueue_party_lookup_interactivity_module();
				if ( $use_party_lookup_ia ) {
					$pl_ctx  = wp_json_encode(
						$this->get_party_lookup_interactivity_context(),
						JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
					);
					$output .= '<div class="wgrsvp-party-lookup" data-wp-interactive="wedding-party-rsvp/party-lookup" data-wp-context="' . esc_attr( $pl_ctx ) . '">';
				}
				$output .= '<form method="post">';
				$output .= wp_nonce_field( 'wgrsvp_front_party_login', 'wgrsvp_front_party_login_nonce', true, false );
				$output .= '<div class="wpr-field"><label>' . esc_html__( 'Party ID:', 'wedding-party-rsvp' ) . '</label>';
				if ( $use_party_lookup_ia ) {
					$output .= '<input type="text" name="wpr_party_id" autocomplete="off" required data-wp-on--input="actions.onPartyInput">';
					$output .= '<p class="wgrsvp-party-lookup-hint description" data-wp-bind--hidden="!state.hintVisible" style="margin-top:8px;"><span data-wp-text="state.hint"></span></p>';
				} else {
					$output .= '<input type="text" name="wpr_party_id" required>';
				}
				$output .= '</div><button name="wgrsvp_front_party_login_submit" class="wpr-button">' . esc_html__( 'Find Invitation', 'wedding-party-rsvp' ) . '</button></form>';
				if ( $use_party_lookup_ia ) {
					$output .= '</div>';
				}
			} else {
				$use_ia = $this->enqueue_rsvp_interactivity_module();

				$menus_adult = get_option( $this->opt_menu_adult, array() );
				/* translators: %s: Party ID / invitation code. */
				$welcome_title = ! empty( $settings['welcome_title'] ) ? wp_unslash( (string) $settings['welcome_title'] ) : sprintf( __( 'Party: %s', 'wedding-party-rsvp' ), $party_id );

				if ( $use_ia ) {
					$ctx_json = wp_json_encode(
						$this->get_rsvp_interactivity_context( $party_id, $settings ),
						JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
					);
					$output  .= '<div class="wgrsvp-rsvp-interactive" data-wp-interactive="wedding-party-rsvp/rsvp" data-wp-context="' . esc_attr( $ctx_json ) . '">';
					$output  .= '<div class="wgrsvp-rsvp-feedback" role="status" aria-live="polite"><span data-wp-text="state.feedback"></span></div>';
					$output  .= '<p class="wgrsvp-rsvp-calendar" data-wp-bind--hidden="!state.showCalendar" style="margin-top:12px;"><a class="wpr-button" data-wp-bind--href="state.calendarUrl">' . esc_html__( 'Add to calendar', 'wedding-party-rsvp' ) . '</a></p>';
					$output  .= '<div class="wgrsvp-guest-hub-root" data-wgrsvp-guest-hub-root="1" hidden></div>';
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
				if ( class_exists( 'WGRSVP_Gift_Registries', false ) ) {
					$output .= WGRSVP_Gift_Registries::render( $settings );
				}

				foreach ( $guests as $g ) {
					$veg_detail   = $use_ia && in_array( (string) $g->menu_choice, array( 'Vegetarian', 'Vegan' ), true );
					$guest_row_id = 'wgrsvp-guest-row-' . (string) absint( $g->id );
					if ( $use_ia ) {
						$card_ctx = wp_json_encode(
							array(
								'showMenuDetail' => $veg_detail,
								'emailValid'     => '',
							),
							JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
						);
						$output  .= '<div id="' . esc_attr( $guest_row_id ) . '" class="wpr-guest-card" data-wp-context="' . esc_attr( $card_ctx ) . '">';
					} else {
						$output .= '<div id="' . esc_attr( $guest_row_id ) . '" class="wpr-guest-card">';
					}
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
					$menu_sel  = '<div class="wpr-field"><label>' . esc_html__( 'Entrée', 'wedding-party-rsvp' ) . '</label><select name="guest[' . absint( $g->id ) . '][menu]"';
					$menu_sel .= $use_ia ? ' data-wp-on--change="actions.onGuestMenuChange"' : '';
					$menu_sel .= '><option value="">' . esc_html__( 'Select...', 'wedding-party-rsvp' ) . '</option>';
					foreach ( $menus_adult as $m ) {
						$menu_sel .= '<option value="' . esc_attr( $m ) . '" ' . selected( $g->menu_choice, $m, false ) . '>' . esc_html( $m ) . '</option>';
					}
					$menu_sel .= '</select></div>';
					$output   .= $menu_sel;

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
					$output .= '</div>';
					if ( $use_ia ) {
						$output .= '<div class="wgrsvp-menu-extra-dietary" data-wp-bind--hidden="!context.showMenuDetail">';
					}
					$diet_inp = '<input type="text" name="guest[' . absint( $g->id ) . '][dietary]" value="' . esc_attr( $g->dietary_restrictions ) . '" placeholder="' . esc_attr__( 'Other dietary notes…', 'wedding-party-rsvp' ) . '">';
					$output  .= $diet_inp;
					if ( $use_ia ) {
						$output .= '<p class="description" style="margin-top:6px;">' . esc_html__( 'Shown when Entrée is Vegetarian or Vegan—add details for your caterer.', 'wedding-party-rsvp' ) . '</p></div>';
					}
					$output .= '</div>';

					$output .= '<div class="wpr-field"><label>' . esc_html__( 'I promise to dance if you play:', 'wedding-party-rsvp' ) . '</label><input type="text" name="guest[' . absint( $g->id ) . '][song]" value="' . esc_attr( $g->song_request ) . '"></div>';

					$output .= '<div class="wpr-field"><label>' . esc_html__( 'Message to Couple:', 'wedding-party-rsvp' ) . '</label><textarea name="guest[' . absint( $g->id ) . '][message]" rows="2" placeholder="' . esc_attr__( 'Note to the bride & groom…', 'wedding-party-rsvp' ) . '">' . esc_textarea( $g->guest_message ) . '</textarea></div>';

					if ( $use_ia ) {
						$output .= '<div class="wpr-field wgrsvp-email-field"><label>' . esc_html__( 'Email', 'wedding-party-rsvp' ) . '</label> ';
						$output .= '<span class="wgrsvp-email-hint description" data-wp-bind--hidden="!context.emailValid" style="display:inline-block;margin-left:4px;" aria-live="polite"><span data-wp-text="context.emailValid"></span></span><br>';
						$output .= '<input type="email" name="guest[' . absint( $g->id ) . '][email]" value="' . esc_attr( $g->email ) . '" autocomplete="email" data-wp-on--input="actions.onGuestEmailInput"></div>';
					} else {
						$output .= '<div class="wpr-field"><label>' . esc_html__( 'Email', 'wedding-party-rsvp' ) . '</label><input type="email" name="guest[' . absint( $g->id ) . '][email]" value="' . esc_attr( $g->email ) . '"></div>';
					}
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
				<div style="background:#fff; border:1px solid #ccc; padding:30px; max-width:640px; margin-top:20px;">
					<h2 style="text-align:center;"><?php esc_html_e( 'Send Invites Directly', 'wedding-party-rsvp' ); ?></h2>
					<p style="text-align:center;"><?php esc_html_e( 'The Pro version includes a complete Email Invitation system. Send customized invites to your guests with one click.', 'wedding-party-rsvp' ); ?></p>
					<p class="description"><?php esc_html_e( 'Right now (free plugin): set your RSVP page under Settings, add guests with email addresses, then use “Copy RSVP link” on the guest list to paste into your own email or text. Your data stays on your site.', 'wedding-party-rsvp' ); ?></p>
					<p style="text-align:center; margin-top:18px;">
						<a class="button button-primary button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-settings' ) ); ?>"><?php esc_html_e( 'RSVP settings', 'wedding-party-rsvp' ); ?></a>
						<a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ); ?>"><?php esc_html_e( 'Guest list', 'wedding-party-rsvp' ); ?></a>
					</p>
					<p style="text-align:center; margin-top:16px;">
						<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade to Pro for batch email', 'wedding-party-rsvp' ); ?></a>
					</p>
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
				<div style="background:#fff; border:1px solid #ccc; padding:30px; max-width:640px; margin-top:20px;">
					<h2 style="text-align:center;"><?php esc_html_e( 'Text Your Guests', 'wedding-party-rsvp' ); ?></h2>
					<p style="text-align:center;"><?php esc_html_e( 'Upgrade to the Pro version to integrate with Twilio and send SMS invitations directly to your guest list.', 'wedding-party-rsvp' ); ?></p>
					<p class="description"><?php esc_html_e( 'Until then, share the RSVP link or invitation code by text from your phone — the guest list can copy the link for each household.', 'wedding-party-rsvp' ); ?></p>
					<p style="text-align:center; margin-top:18px;">
						<a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ); ?>"><?php esc_html_e( 'Guest list', 'wedding-party-rsvp' ); ?></a>
					</p>
					<p style="text-align:center; margin-top:16px;">
						<a href="<?php echo esc_url( 'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/' ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary button-large"><?php esc_html_e( 'Upgrade to Pro', 'wedding-party-rsvp' ); ?></a>
					</p>
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
