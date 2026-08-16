<?php
/**
 * Collapsible groups for the Wedding RSVP admin sidebar submenu.
 *
 * WordPress $submenu is flat; this enhances #adminmenu with CSS/JS only.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues accordion assets and exposes a filterable group map.
 */
class WGRSVP_Admin_Menu_Groups {

	/**
	 * Register admin enqueue hook.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Default pinned slugs (always visible; never folded into a group).
	 *
	 * @return string[]
	 */
	public static function default_pinned_slugs() {
		return array(
			'wedding-rsvp-main',
			'wedding-rsvp-guest-table',
			'wedding-rsvp-add',
			'wpr-pro-mobile-app',
			'wedding-rsvp-comm',
			'wedding-rsvp-email',
			'wedding-rsvp-sms',
			'wedding-rsvp-settings',
			'wgrsvp-setup-guide',
			'wedding-rsvp-documentation',
			'wedding-rsvp-help',
		);
	}

	/**
	 * Default collapsible groups (id => label + slugs).
	 *
	 * @return array<string, array{label: string, slugs: string[]}>
	 */
	public static function default_groups() {
		return array(
			'guests_seating'     => array(
				'label' => __( 'Guests & seating', 'wedding-party-rsvp' ),
				'slugs' => array(
					'wedding-rsvp-paste-guests',
					'wedding-rsvp-menu',
					'wedding-rsvp-seating',
					'wpr-pro-custom-questions',
					'wedding-rsvp-sub-events',
					'wedding-rsvp-sub-event-reports',
				),
			),
			'planning'           => array(
				'label' => __( 'Planning', 'wedding-party-rsvp' ),
				'slugs' => array(
					'wedding-rsvp-budget',
					'wedding-rsvp-budget-reports',
					'wedding-rsvp-vendors',
					'wedding-rsvp-checklist',
					'wedding-rsvp-registry-hub',
					'wedding-rsvp-photo-gallery',
					'wpr-pro-travel-info',
					'wgrsvp-travel-public',
				),
			),
			'guest_experience'   => array(
				'label' => __( 'Guest experience & reports', 'wedding-party-rsvp' ),
				'slugs' => array(
					'wgrsvp-guestbook',
					'wedding-rsvp-song-requests',
					'wedding-rsvp-gifts-report',
					'wedding-rsvp-thankyou-tracker',
					'wedding-rsvp-caterer-portal',
					'wedding-rsvp-client-summary',
					'wedding-rsvp-ops',
					'wpr-pro-address-campaign',
					'wpr-pro-guest-push',
					'wedding-rsvp-vendor-packet',
					'wedding-rsvp-audit-log',
					'wpr_pro_net_event',
					'wpr_pro_network_event',
				),
			),
		);
	}

	/**
	 * Groups after filter `wgrsvp_admin_menu_groups`.
	 *
	 * @return array<string, array{label: string, slugs: string[]}>
	 */
	public static function get_groups() {
		$groups = self::default_groups();

		/**
		 * Filter collapsible Wedding RSVP admin menu groups.
		 *
		 * @param array<string, array{label: string, slugs: string[]}> $groups Group map keyed by id.
		 */
		$filtered = apply_filters( 'wgrsvp_admin_menu_groups', $groups );

		return is_array( $filtered ) ? $filtered : $groups;
	}

	/**
	 * Whether the Wedding RSVP top-level menu is registered for this request.
	 *
	 * @return bool
	 */
	public static function menu_is_visible() {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return false;
		}

		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && 'wedding-rsvp-main' === $item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue CSS/JS on every admin screen when the menu is present.
	 *
	 * @param string $hook_suffix Current admin page hook (unused; menu presence gates load).
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		unset( $hook_suffix );

		if ( ! self::menu_is_visible() ) {
			return;
		}

		$plugin_file = defined( 'WGRSVP_PLUGIN_FILE' ) ? WGRSVP_PLUGIN_FILE : dirname( __DIR__ ) . '/wedding-party-rsvp.php';
		$ver         = '8.3.8';
		if ( defined( 'WGRSVP_VERSION' ) ) {
			$ver = WGRSVP_VERSION;
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$css_path = plugin_dir_path( $plugin_file ) . 'assets/css/wgrsvp-admin-menu-groups.css';
			if ( is_readable( $css_path ) ) {
				$ver = (string) filemtime( $css_path );
			}
		}

		wp_enqueue_style(
			'wgrsvp-admin-menu-groups',
			plugins_url( 'assets/css/wgrsvp-admin-menu-groups.css', $plugin_file ),
			array(),
			$ver
		);

		wp_enqueue_script(
			'wgrsvp-admin-menu-groups',
			plugins_url( 'assets/js/wgrsvp-admin-menu-groups.js', $plugin_file ),
			array(),
			$ver,
			true
		);

		$groups_out = array();
		foreach ( self::get_groups() as $id => $group ) {
			if ( ! is_array( $group ) || empty( $group['slugs'] ) || ! is_array( $group['slugs'] ) ) {
				continue;
			}
			$label = isset( $group['label'] ) ? (string) $group['label'] : (string) $id;
			$slugs = array();
			foreach ( $group['slugs'] as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' !== $slug ) {
					$slugs[] = $slug;
				}
			}
			if ( empty( $slugs ) ) {
				continue;
			}
			$groups_out[] = array(
				'id'    => sanitize_key( (string) $id ),
				'label' => $label,
				'slugs' => array_values( array_unique( $slugs ) ),
			);
		}

		wp_localize_script(
			'wgrsvp-admin-menu-groups',
			'wgrsvpAdminMenuGroups',
			array(
				'toplevelId'   => 'toplevel_page_wedding-rsvp-main',
				'storageKey'   => 'wgrsvp_admin_menu_groups',
				'pinnedSlugs'  => array_values( array_map( 'sanitize_key', self::default_pinned_slugs() ) ),
				'groups'       => $groups_out,
			)
		);
	}
}
