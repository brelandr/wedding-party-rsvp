<?php
/**
 * Red pending-count badges on Wedding RSVP admin menu items.
 *
 * Uses core `.awaiting-mod` styles (same pattern as Comments).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decorates $menu / $submenu titles with pending-approval counts.
 */
class WGRSVP_Admin_Menu_Badges {

	public const TRANSIENT_KEY = 'wgrsvp_admin_menu_badges';

	/**
	 * Register late admin_menu decoration.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'decorate_menus' ), 9999 );
	}

	/**
	 * Bust cached badge counts (call after moderation / new pending items).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Map of menu slug => pending count (only counts > 0).
	 *
	 * @return array<string,int>
	 */
	public static function get_badges() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$badges = array();

		if ( class_exists( 'WGRSVP_Guestbook', false ) && method_exists( 'WGRSVP_Guestbook', 'count_pending' ) ) {
			if ( ! function_exists( 'wgrsvp_admin_module_enabled' ) || wgrsvp_admin_module_enabled( 'guestbook' ) ) {
				$n = (int) WGRSVP_Guestbook::count_pending();
				if ( $n > 0 ) {
					$badges['wgrsvp-guestbook'] = $n;
				}
			}
		}

		/**
		 * Filter pending-approval counts keyed by admin menu slug (page= or post_type=).
		 *
		 * @param array<string,int> $badges Slug => count (positive integers only).
		 */
		$filtered = apply_filters( 'wgrsvp_admin_menu_badges', $badges );
		if ( ! is_array( $filtered ) ) {
			$filtered = $badges;
		}

		$out = array();
		foreach ( $filtered as $slug => $count ) {
			$slug  = sanitize_key( (string) $slug );
			$count = (int) $count;
			if ( '' === $slug || $count < 1 ) {
				continue;
			}
			$out[ $slug ] = $count;
		}

		set_transient( self::TRANSIENT_KEY, $out, 60 );
		return $out;
	}

	/**
	 * HTML fragment for a red count bubble.
	 *
	 * @param int $count Pending items.
	 * @return string
	 */
	public static function badge_html( $count ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return '';
		}

		$formatted = number_format_i18n( $count );
		/* translators: %s: number of items awaiting approval */
		$sr = sprintf( __( '%s awaiting approval', 'wedding-party-rsvp' ), $formatted );

		return sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			$count,
			esc_html( $formatted ),
			esc_html( $sr )
		);
	}

	/**
	 * Append badges to submenu rows and top-level Wedding RSVP label.
	 *
	 * @return void
	 */
	public static function decorate_menus() {
		global $menu, $submenu;

		$badges = self::get_badges();
		if ( empty( $badges ) ) {
			return;
		}

		$parent = 'wedding-rsvp-main';
		if ( ! empty( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) {
			foreach ( $submenu[ $parent ] as $i => $item ) {
				if ( empty( $item[2] ) || ! is_string( $item[2] ) ) {
					continue;
				}
				$slug = self::normalize_menu_slug( $item[2] );
				if ( '' === $slug || ! isset( $badges[ $slug ] ) ) {
					continue;
				}
				$title = isset( $item[0] ) ? (string) $item[0] : '';
				if ( false !== strpos( $title, 'awaiting-mod' ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional menu badge decoration.
				$submenu[ $parent ][ $i ][0] = $title . self::badge_html( $badges[ $slug ] );
			}
		}

		$total = array_sum( $badges );
		if ( $total < 1 || ! is_array( $menu ) ) {
			return;
		}

		foreach ( $menu as $i => $item ) {
			if ( ! isset( $item[2] ) || $parent !== $item[2] ) {
				continue;
			}
			$title = isset( $item[0] ) ? (string) $item[0] : '';
			if ( false !== strpos( $title, 'awaiting-mod' ) ) {
				break;
			}
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional menu badge decoration.
			$menu[ $i ][0] = $title . self::badge_html( $total );
			break;
		}
	}

	/**
	 * Normalize submenu file/slug to a badge key.
	 *
	 * @param string $raw Submenu slug (page slug or edit.php?post_type=…).
	 * @return string
	 */
	private static function normalize_menu_slug( $raw ) {
		$raw = (string) $raw;
		if ( false !== strpos( $raw, 'post_type=' ) ) {
			$parts = wp_parse_url( $raw );
			if ( is_array( $parts ) && ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
				if ( ! empty( $query['post_type'] ) ) {
					return sanitize_key( (string) $query['post_type'] );
				}
			}
		}
		if ( false !== strpos( $raw, 'page=' ) ) {
			$parts = wp_parse_url( $raw );
			if ( is_array( $parts ) && ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
				if ( ! empty( $query['page'] ) ) {
					return sanitize_key( (string) $query['page'] );
				}
			}
		}
		return sanitize_key( $raw );
	}
}
