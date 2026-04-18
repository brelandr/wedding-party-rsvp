<?php
/**
 * Privacy tools: suggested policy text, personal data export, and erase-by-email.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Privacy' ) ) {

	/**
	 * Privacy helpers for WordPress Privacy tools and the site Privacy Policy.
	 */
	class WGRSVP_Privacy {

		/**
		 * Hook registration.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'admin_init', array( __CLASS__, 'register_privacy_policy_content' ), 20 );
			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ), 10 );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ), 10 );
		}

		/**
		 * Adds suggested copy to Settings → Privacy → Policy guide (WordPress 4.9.6+).
		 *
		 * @return void
		 */
		public static function register_privacy_policy_content() {
			static $did = false;
			if ( $did ) {
				return;
			}
			$did = true;

			if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
				return;
			}

			$suggested  = '<div class="wp-suggested-text">';
			$suggested .= '<p>' . esc_html__( 'Wedding Party RSVP stores guest and RSVP information that you or your guests provide in the WordPress database (typically a table named like wp_wedding_rsvps, with your site’s table prefix).', 'wedding-party-rsvp' ) . '</p>';
			$suggested .= '<p><strong>' . esc_html__( 'What may be stored per guest', 'wedding-party-rsvp' ) . '</strong></p>';
			$suggested .= '<ul>';
			$suggested .= '<li>' . esc_html__( 'Party ID (invite code), guest name, RSVP status, adult meal choice', 'wedding-party-rsvp' ) . '</li>';
			$suggested .= '<li>' . esc_html__( 'Optional contact fields you collect in the form (e.g. email, phone)', 'wedding-party-rsvp' ) . '</li>';
			$suggested .= '<li>' . esc_html__( 'Dietary restrictions, allergies, song request, guest message, and address if entered on the public RSVP form', 'wedding-party-rsvp' ) . '</li>';
			$suggested .= '</ul>';
			$suggested .= '<p><strong>' . esc_html__( 'Who has access', 'wedding-party-rsvp' ) . '</strong> ' . esc_html__( 'Users with permission to manage this plugin’s settings in WordPress (usually Administrators) can view and edit guest records in the dashboard.', 'wedding-party-rsvp' ) . '</p>';
			$suggested .= '<p><strong>' . esc_html__( 'Export and erase', 'wedding-party-rsvp' ) . '</strong> ' . esc_html__( 'This plugin registers a personal data exporter and eraser for Tools → Export Personal Data and Tools → Erase Personal Data. Export and erase match guest rows by the email address stored on the guest record.', 'wedding-party-rsvp' ) . '</p>';
			$suggested .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Erasing data for an email address removes all guest rows that use that email from the RSVP table. Confirm this matches your event’s obligations before completing erase requests.', 'wedding-party-rsvp' ) . '</p>';
			$suggested .= '</div>';

			wp_add_privacy_policy_content(
				__( 'Wedding Party RSVP', 'wedding-party-rsvp' ),
				wp_kses_post( $suggested )
			);
		}

		/**
		 * Register the personal data exporter with core.
		 *
		 * @param array<string,array> $exporters Exporters.
		 * @return array<string,array>
		 */
		public static function register_exporter( $exporters ) {
			if ( ! is_array( $exporters ) ) {
				$exporters = array();
			}
			$exporters['wgrsvp-guests'] = array(
				'exporter_friendly_name' => __( 'Wedding Party RSVP Guest List', 'wedding-party-rsvp' ),
				'callback'               => array( __CLASS__, 'export_guest_data' ),
			);
			return $exporters;
		}

		/**
		 * Register the personal data eraser with core.
		 *
		 * @param array<string,array> $erasers Erasers.
		 * @return array<string,array>
		 */
		public static function register_eraser( $erasers ) {
			if ( ! is_array( $erasers ) ) {
				$erasers = array();
			}
			$erasers['wgrsvp-guests'] = array(
				'eraser_friendly_name' => __( 'Wedding Party RSVP Guest List', 'wedding-party-rsvp' ),
				'callback'             => array( __CLASS__, 'erase_guest_data' ),
			);
			return $erasers;
		}

		/**
		 * Export guest rows matching an email address.
		 *
		 * @param string $email_address Email.
		 * @param int    $page          Page (1-based).
		 * @return array{data: array<int,array>, done: bool}
		 */
		public static function export_guest_data( $email_address, $page = 1 ) {
			$email_address = sanitize_email( (string) $email_address );
			$page          = max( 1, (int) $page );
			$per_page      = 50;
			$offset        = ( $page - 1 ) * $per_page;

			if ( '' === $email_address || ! is_email( $email_address ) ) {
				return array(
					'data' => array(),
					'done' => true,
				);
			}

			global $wpdb;
			$table = $wpdb->prefix . 'wedding_rsvps';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table via %i + $wpdb->prefix; values bound in nested prepare().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d',
					$table,
					$email_address,
					$per_page,
					$offset
				)
			);

			if ( empty( $rows ) ) {
				return array(
					'data' => array(),
					'done' => true,
				);
			}

			$export_items = array();
			foreach ( $rows as $row ) {
				$data = array();
				foreach ( (array) $row as $key => $value ) {
					if ( 'id' === $key ) {
						continue;
					}
					$data[] = array(
						'name'  => (string) $key,
						'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
					);
				}
				$export_items[] = array(
					'group_id'    => 'wgrsvp_guests',
					'group_label' => __( 'RSVP Guest Record', 'wedding-party-rsvp' ),
					'item_id'     => 'guest-' . (int) $row->id,
					'data'        => $data,
				);
			}

			$done = count( $rows ) < $per_page;

			return array(
				'data' => $export_items,
				'done' => $done,
			);
		}

		/**
		 * Remove guest rows for an email (Erase Personal Data).
		 *
		 * @param string $email_address Email.
		 * @param int    $page          Page (unused; all matching rows removed in one pass).
		 * @return array{items_removed: bool, items_retained: bool, messages: array<int,string>, done: bool}
		 */
		public static function erase_guest_data( $email_address, $page = 1 ) {
			unset( $page );
			$email_address = sanitize_email( (string) $email_address );

			if ( '' === $email_address || ! is_email( $email_address ) ) {
				return array(
					'items_removed'  => false,
					'items_retained' => false,
					'messages'       => array(),
					'done'           => true,
				);
			}

			global $wpdb;
			$table = $wpdb->prefix . 'wedding_rsvps';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table via %i + $wpdb->prefix; email bound in nested prepare().
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE email = %s',
					$table,
					$email_address
				)
			);

			if ( $deleted > 0 ) {
				do_action( 'wgrsvp_after_guest_privacy_erase', $email_address, $deleted );
			}

			$messages = array();
			if ( $deleted > 0 ) {
				$messages[] = sprintf(
					/* translators: %d: number of database rows removed. */
					esc_html__( 'Removed %d guest record(s) associated with this email address from Wedding Party RSVP.', 'wedding-party-rsvp' ),
					$deleted
				);
			} else {
				$messages[] = esc_html__( 'No Wedding Party RSVP guest records were found for that email address.', 'wedding-party-rsvp' );
			}

			return array(
				'items_removed'  => $deleted > 0,
				'items_retained' => false,
				'messages'       => $messages,
				'done'           => true,
			);
		}
	}
}
