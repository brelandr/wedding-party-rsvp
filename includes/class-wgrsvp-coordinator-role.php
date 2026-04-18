<?php
/**
 * Wedding Coordinator role: view guest list & meal reports without full admin access.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Coordinator_Role' ) ) {

	/**
	 * Registers capabilities, restricted admin menus, and URL guards.
	 */
	class WGRSVP_Coordinator_Role {

		const ROLE_SLUG = 'wedding_coordinator';

		/**
		 * Capability for the Wedding RSVP dashboard (guest list + meal stats).
		 */
		const CAP_VIEW_GUEST_DASHBOARD = 'wgrsvp_view_guest_dashboard';

		/**
		 * Create/update role and ensure administrators retain dashboard access.
		 *
		 * @return void
		 */
		public static function sync_on_activation() {
			if ( ! get_role( self::ROLE_SLUG ) ) {
				add_role(
					self::ROLE_SLUG,
					__( 'Wedding Coordinator', 'wedding-party-rsvp' ),
					array(
						'read'                         => true,
						self::CAP_VIEW_GUEST_DASHBOARD => true,
					)
				);
			} else {
				$coord = get_role( self::ROLE_SLUG );
				if ( $coord ) {
					$coord->add_cap( 'read' );
					$coord->add_cap( self::CAP_VIEW_GUEST_DASHBOARD );
				}
			}

			$admin = get_role( 'administrator' );
			if ( $admin && ! $admin->has_cap( self::CAP_VIEW_GUEST_DASHBOARD ) ) {
				$admin->add_cap( self::CAP_VIEW_GUEST_DASHBOARD );
			}
		}

		/**
		 * Remove custom role and capability mapping on plugin deactivation.
		 *
		 * @return void
		 */
		public static function remove_on_deactivation() {
			remove_role( self::ROLE_SLUG );

			$admin = get_role( 'administrator' );
			if ( $admin ) {
				$admin->remove_cap( self::CAP_VIEW_GUEST_DASHBOARD );
			}
		}

		/**
		 * Ensure role and administrator cap exist after plugin updates (activation does not always re-run).
		 *
		 * @return void
		 */
		public static function maybe_upgrade_role() {
			$coord = get_role( self::ROLE_SLUG );
			$admin = get_role( 'administrator' );
			if ( ! $coord || ! $admin || ! $admin->has_cap( self::CAP_VIEW_GUEST_DASHBOARD ) ) {
				self::sync_on_activation();
			}
		}

		/**
		 * The Wedding RSVP top-level admin menu uses CAP_VIEW_GUEST_DASHBOARD. WordPress core denies
		 * `admin.php?page=…` access when the user fails `current_user_can()` on the parent menu capability.
		 * After activation or DB restore, administrators must have this cap *before* menu.php runs; refreshing
		 * the user cache here avoids a stale "Sorry, you are not allowed to access this page" on first load.
		 *
		 * @return void
		 */
		public static function ensure_administrator_has_view_dashboard_cap() {
			$admin_role = get_role( 'administrator' );
			if ( ! $admin_role ) {
				return;
			}
			if ( $admin_role->has_cap( self::CAP_VIEW_GUEST_DASHBOARD ) ) {
				return;
			}

			$admin_role->add_cap( self::CAP_VIEW_GUEST_DASHBOARD );

			if ( is_user_logged_in() ) {
				clean_user_cache( get_current_user_id() );
				if ( function_exists( 'wp_set_current_user' ) ) {
					wp_set_current_user( get_current_user_id() );
				}
			}
		}

		/**
		 * User has coordinator dashboard access but not site administration.
		 *
		 * @return bool
		 */
		public static function is_coordinator_only() {
			return is_user_logged_in()
				&& current_user_can( self::CAP_VIEW_GUEST_DASHBOARD )
				&& ! current_user_can( 'manage_options' );
		}

		/**
		 * Bootstrap admin restrictions for coordinator-only users.
		 *
		 * @return void
		 */
		public static function init_hooks() {
			add_action( 'init', array( __CLASS__, 'ensure_administrator_has_view_dashboard_cap' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'redirect_from_forbidden_screens' ), 1 );
			add_action( 'admin_menu', array( __CLASS__, 'strip_core_admin_menus' ), 999 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'trim_admin_bar' ), 999 );
		}

		/**
		 * Send coordinators away from wp-admin screens other than RSVP dashboard and profile.
		 *
		 * @return void
		 */
		public static function redirect_from_forbidden_screens() {
			if ( ! self::is_coordinator_only() ) {
				return;
			}
			if ( wp_doing_ajax() ) {
				return;
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return;
			}
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return;
			}
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return;
			}

			global $pagenow;

			if ( 'profile.php' === $pagenow ) {
				return;
			}

			if ( 'user-edit.php' === $pagenow ) {
				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				$edit_uid = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				if ( get_current_user_id() === $edit_uid ) {
					return;
				}
			}

			if ( 'admin.php' === $pagenow ) {
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
				$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				$allowed_pages = array( 'wedding-rsvp-main', 'wedding-rsvp-seating' );
				if ( in_array( $page, $allowed_pages, true ) ) {
					return;
				}
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-main' ) );
				exit;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-main' ) );
			exit;
		}

		/**
		 * Hide standard WordPress admin menus (coordinator-only).
		 *
		 * @return void
		 */
		public static function strip_core_admin_menus() {
			if ( ! self::is_coordinator_only() ) {
				return;
			}

			$remove = array(
				'index.php',
				'edit.php',
				'upload.php',
				'edit.php?post_type=page',
				'edit-comments.php',
				'themes.php',
				'plugins.php',
				'tools.php',
				'options-general.php',
			);

			foreach ( $remove as $slug ) {
				remove_menu_page( $slug );
			}
		}

		/**
		 * Reduce top toolbar clutter for coordinators.
		 *
		 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
		 * @return void
		 */
		public static function trim_admin_bar( $wp_admin_bar ) {
			if ( ! self::is_coordinator_only() || ! is_object( $wp_admin_bar ) ) {
				return;
			}

			$wp_admin_bar->remove_node( 'wp-logo' );
			$wp_admin_bar->remove_node( 'site-name' );
			$wp_admin_bar->remove_node( 'customize' );
			$wp_admin_bar->remove_node( 'comments' );
			$wp_admin_bar->remove_node( 'new-content' );
			$wp_admin_bar->remove_node( 'updates' );
		}
	}
}
