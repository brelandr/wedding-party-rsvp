<?php
/**
 * Event capacity: extra yes replies become a waitlist.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts accepted guests against an optional event cap.
 */
class WGRSVP_Capacity {

	const STATUS_WAITLIST = 'Waitlist';

	const CONTEXT_GUEST = 'guest';

	const CONTEXT_ADMIN = 'admin';

	/**
	 * Names moved from a requested yes onto the waitlist during the last resolve.
	 *
	 * @var string[]
	 */
	private static $last_waitlisted = array();

	/**
	 * Statuses a guest row may store.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'Pending', 'Accepted', 'Declined', self::STATUS_WAITLIST );
	}

	/**
	 * Configured cap. Zero means the cap is off.
	 *
	 * @return int
	 */
	public static function limit() {
		$settings = get_option( 'wgrsvp_general_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['event_capacity'] ) ) {
			return 0;
		}
		return absint( $settings['event_capacity'] );
	}

	/**
	 * Guest-facing sentence shown when the event is full.
	 *
	 * @return string
	 */
	public static function message() {
		$settings = get_option( 'wgrsvp_general_settings', array() );
		$custom   = '';
		if ( is_array( $settings ) && isset( $settings['event_capacity_message'] ) ) {
			$custom = trim( (string) $settings['event_capacity_message'] );
		}
		if ( '' !== $custom ) {
			return $custom;
		}
		return __( 'This event is at capacity. You can still decline. A yes is added to the waitlist, and the coordinator can offer a seat if one opens.', 'wedding-party-rsvp' );
	}

	/**
	 * Whether accepted guests have reached a positive cap.
	 *
	 * @return bool
	 */
	public static function is_full() {
		$limit = self::limit();
		if ( $limit < 1 ) {
			return false;
		}
		return self::accepted_count() >= $limit;
	}

	/**
	 * Accepted rows in the guest table.
	 *
	 * @return int
	 */
	public static function accepted_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'wedding_rsvps';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Count for a capacity check; object cache is not required for correctness.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s', $table, 'Accepted' ) );
		return (int) $count;
	}

	/**
	 * Names from the most recent guest resolve, then clear them.
	 *
	 * @return string[]
	 */
	public static function consume_waitlisted_names() {
		$names                 = self::$last_waitlisted;
		self::$last_waitlisted = array();
		return $names;
	}

	/**
	 * Resolve one guest. Id 0 is a row that is not stored yet.
	 *
	 * @param int    $guest_id  Guest id, or 0 for a new row.
	 * @param string $requested Requested status.
	 * @param string $current   Current status when the row is not in the table yet.
	 * @param string $context   guest or admin.
	 * @return string
	 */
	public static function resolve_one( $guest_id, $requested, $current, $context ) {
		$resolved = self::resolve_batch(
			array(
				array(
					'id'        => absint( $guest_id ),
					'requested' => $requested,
					'current'   => $current,
				),
			),
			$context
		);
		$guest_id = absint( $guest_id );
		if ( isset( $resolved[ $guest_id ] ) ) {
			return $resolved[ $guest_id ];
		}
		return self::normalize_status( $requested );
	}

	/**
	 * Resolve a household in guest-id order.
	 *
	 * Each item is id, requested, and optional current (used when id is 0).
	 * Admin context stores the requested status. Guest context keeps an existing
	 * yes, always stores a decline, and turns a new yes into Waitlist when the
	 * cap is full.
	 *
	 * @param array<int,array<string,mixed>> $items   Guest decisions.
	 * @param string                          $context guest or admin.
	 * @return array<int,string> Guest id => status to store.
	 */
	public static function resolve_batch( $items, $context ) {
		self::$last_waitlisted = array();
		$context                = self::CONTEXT_ADMIN === $context ? self::CONTEXT_ADMIN : self::CONTEXT_GUEST;
		$prepared               = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$guest_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$prepared[] = array(
				'id'        => $guest_id,
				'requested' => self::normalize_status( isset( $item['requested'] ) ? (string) $item['requested'] : 'Pending' ),
				'current'   => self::normalize_status( isset( $item['current'] ) ? (string) $item['current'] : 'Pending' ),
			);
		}

		usort(
			$prepared,
			static function ( $a, $b ) {
				return $a['id'] <=> $b['id'];
			}
		);

		$stored = self::rows_for_ids( $prepared );
		$limit  = self::limit();
		$count  = self::accepted_count();
		$out    = array();

		foreach ( $prepared as $item ) {
			$guest_id = (int) $item['id'];
			$requested = $item['requested'];
			$current   = $item['current'];
			if ( $guest_id > 0 && isset( $stored[ $guest_id ] ) ) {
				$current = self::normalize_status( (string) $stored[ $guest_id ]['status'] );
			}

			if ( self::CONTEXT_ADMIN === $context ) {
				$resolved = $requested;
			} elseif ( 'Accepted' === $current && 'Accepted' === $requested ) {
				$resolved = 'Accepted';
			} elseif ( 'Accepted' === $current && 'Accepted' !== $requested ) {
				if ( $count > 0 ) {
					--$count;
				}
				$resolved = $requested;
			} elseif ( 'Accepted' === $requested ) {
				if ( $limit < 1 || $count < $limit ) {
					$resolved = 'Accepted';
					++$count;
				} else {
					$resolved = self::STATUS_WAITLIST;
					$name     = '';
					if ( $guest_id > 0 && isset( $stored[ $guest_id ]['name'] ) ) {
						$name = (string) $stored[ $guest_id ]['name'];
					}
					if ( '' !== $name ) {
						self::$last_waitlisted[] = $name;
					}
				}
			} else {
				$resolved = $requested;
			}

			$filtered = apply_filters( 'wgrsvp_event_capacity_status', $resolved, $guest_id, $context );
			if ( is_string( $filtered ) && in_array( $filtered, self::statuses(), true ) ) {
				$resolved = $filtered;
			}
			$out[ $guest_id ] = $resolved;
		}

		return $out;
	}

	/**
	 * Keep only known statuses.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private static function normalize_status( $status ) {
		$status = sanitize_text_field( (string) $status );
		if ( in_array( $status, self::statuses(), true ) ) {
			return $status;
		}
		return 'Pending';
	}

	/**
	 * Current name and status for ids that already exist.
	 *
	 * @param array<int,array<string,mixed>> $items Prepared items.
	 * @return array<int,array{name:string,status:string}>
	 */
	private static function rows_for_ids( $items ) {
		global $wpdb;

		$ids = array();
		foreach ( $items as $item ) {
			if ( (int) $item['id'] > 0 ) {
				$ids[] = (int) $item['id'];
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( array() === $ids ) {
			return array();
		}

		$table        = $wpdb->prefix . 'wedding_rsvps';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $table ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- IN list is integer placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, guest_name, rsvp_status FROM %i WHERE id IN ({$placeholders})", $args ) );
		$out  = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->id ) ) {
				continue;
			}
			$out[ (int) $row->id ] = array(
				'name'   => isset( $row->guest_name ) ? (string) $row->guest_name : '',
				'status' => isset( $row->rsvp_status ) ? (string) $row->rsvp_status : 'Pending',
			);
		}
		return $out;
	}
}
