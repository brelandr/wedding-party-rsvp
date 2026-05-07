<?php
/**
 * Printable vendor & venue summary (headcounts, meals, allergies, kids).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Vendor_Packet' ) ) {

	/**
	 * Admin screen: single print-friendly packet for caterer / venue.
	 */
	class WGRSVP_Vendor_Packet {

		public const PAGE_SLUG = 'wedding-rsvp-vendor-packet';

		/**
		 * Cached schema + seated count for packet headcounts (busted when guest data changes).
		 */
		private const TRANSIENT_SEATING_SNAPSHOT = 'wgrsvp_vendor_packet_seating_snapshot';

		/**
		 * Clear cached vendor-packet DB snapshots after guest list changes.
		 *
		 * @return void
		 */
		public static function bust_vendor_packet_transients() {
			delete_transient( self::TRANSIENT_SEATING_SNAPSHOT );
		}

		/**
		 * Run SHOW COLUMNS + seated COUNT at most once per day unless cache is busted.
		 *
		 * @param wpdb   $wpdb  WordPress DB object.
		 * @param string $table RSVP table name (prefixed).
		 * @return array{has_table_id:bool,seated:int}
		 */
		private static function get_seating_snapshot( $wpdb, $table ) {
			$cached = get_transient( self::TRANSIENT_SEATING_SNAPSHOT );
			if ( is_array( $cached ) && isset( $cached['has_table_id'], $cached['seated'] ) ) {
				return array(
					'has_table_id' => (bool) $cached['has_table_id'],
					'seated'       => (int) $cached['seated'],
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema probe; transient cached; table via %i.
			$col_field        = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'table_id' ) );
			$has_table_id_col = '' !== (string) $col_field;

			$seated = 0;
			if ( $has_table_id_col ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Count cached via transient.
				$seated = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND table_id IS NOT NULL', $table, 'Accepted' ) );
			}

			$snapshot = array(
				'has_table_id' => $has_table_id_col,
				'seated'       => $seated,
			);
			set_transient( self::TRANSIENT_SEATING_SNAPSHOT, $snapshot, DAY_IN_SECONDS );

			return $snapshot;
		}

		/**
		 * Register admin menu hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 110 );
		}

		/**
		 * Parent slug: coexists with Pro-merged menu.
		 *
		 * @return string
		 */
		private static function parent_slug() {
			global $submenu;
			if ( isset( $submenu['wedding-rsvp-main'] ) ) {
				return 'wedding-rsvp-main';
			}
			return 'wedding-rsvp';
		}

		/**
		 * Add the Vendor & venue packet submenu when the module is enabled.
		 *
		 * @return void
		 */
		public static function register_menu() {
			if ( ! wgrsvp_admin_module_enabled( 'vendor_packet' ) ) {
				return;
			}
			add_submenu_page(
				self::parent_slug(),
				__( 'Vendor & venue packet', 'wedding-party-rsvp' ),
				__( 'Vendor & venue packet', 'wedding-party-rsvp' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Render the printable admin page.
		 *
		 * @return void
		 */
		public static function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			wgrsvp_require_admin_module_or_die( 'vendor_packet' );

			global $wpdb, $wgrsvp_wedding_rsvp_instance;
			$table = $wpdb->prefix . 'wedding_rsvps';

			$agg = array();
			if ( is_object( $wgrsvp_wedding_rsvp_instance ) && method_exists( $wgrsvp_wedding_rsvp_instance, 'get_aggregated_rsvp_stats' ) ) {
				$agg = $wgrsvp_wedding_rsvp_instance->get_aggregated_rsvp_stats();
			}

			$accepted = isset( $agg['total_accepted'] ) ? (int) $agg['total_accepted'] : 0;
			$declined = isset( $agg['total_declined'] ) ? (int) $agg['total_declined'] : 0;
			$pending  = isset( $agg['total_pending'] ) ? (int) $agg['total_pending'] : 0;
			$total    = isset( $agg['total_guests'] ) ? (int) $agg['total_guests'] : 0;
			$hh_tot   = isset( $agg['households_total'] ) ? (int) $agg['households_total'] : 0;
			$hh_done  = isset( $agg['households_fully_replied'] ) ? (int) $agg['households_fully_replied'] : 0;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$kids = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND is_child = 1', $table, 'Accepted' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$allergy_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT guest_name, party_id, allergies FROM %i WHERE rsvp_status = %s AND allergies IS NOT NULL AND TRIM(allergies) <> %s ORDER BY party_id ASC, guest_name ASC LIMIT 80',
					$table,
					'Accepted',
					''
				),
				ARRAY_A
			);
			if ( ! is_array( $allergy_rows ) ) {
				$allergy_rows = array();
			}

			$meal_lines = array();
			if ( ! empty( $agg['menu_stats_adult'] ) && is_array( $agg['menu_stats_adult'] ) ) {
				foreach ( $agg['menu_stats_adult'] as $row ) {
					if ( is_object( $row ) && isset( $row->menu_choice, $row->count ) ) {
						$meal_lines[] = array(
							'label' => (string) $row->menu_choice,
							'count' => (int) $row->count,
						);
					}
				}
			}

			$seating          = self::get_seating_snapshot( $wpdb, $table );
			$seated_note      = '';
			$has_table_id_col = $seating['has_table_id'];
			if ( $has_table_id_col ) {
				$seated_note = sprintf(
					/* translators: %d: guests assigned to a seating table ID */
					__( 'Accepted guests with seating-chart table assignment: %d', 'wedding-party-rsvp' ),
					$seating['seated']
				);
			}

			$gen_time = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
			$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$list_url = admin_url( 'admin.php?page=wedding-rsvp-main' );

			?>
			<div class="wrap wgrsvp-vendor-packet-wrap">
				<div class="wgrsvp-vendor-packet-actions no-print">
					<h1><?php esc_html_e( 'Vendor & venue packet', 'wedding-party-rsvp' ); ?></h1>
					<p>
						<button type="button" class="button button-primary wgrsvp-trigger-print"><?php esc_html_e( 'Print or save as PDF', 'wedding-party-rsvp' ); ?></button>
						<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Back to guest list', 'wedding-party-rsvp' ); ?></a>
					</p>
					<p class="description"><?php esc_html_e( 'Use this page as a single handoff for catering and the venue. For spreadsheet exports, use Export CSV and caterer summary from the guest list.', 'wedding-party-rsvp' ); ?></p>
				</div>

				<div class="wgrsvp-vendor-packet-sheet">
					<header class="wgrsvp-vp-header">
						<h2><?php echo esc_html( $site ); ?></h2>
						<p class="wgrsvp-vp-meta"><?php echo esc_html( $gen_time ); ?></p>
					</header>

					<section class="wgrsvp-vp-section">
						<h3><?php esc_html_e( 'Headcounts', 'wedding-party-rsvp' ); ?></h3>
						<ul class="wgrsvp-vp-list">
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Total guests on list: %d', 'wedding-party-rsvp' ), $total ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Attending (Accepted): %d', 'wedding-party-rsvp' ), $accepted ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Regrets (Declined): %d', 'wedding-party-rsvp' ), $declined ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Pending reply: %d', 'wedding-party-rsvp' ), $pending ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Attending children (marked child): %d', 'wedding-party-rsvp' ), $kids ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: 1: households complete, 2: total households */ __( 'Households fully replied: %1$d of %2$d', 'wedding-party-rsvp' ), $hh_done, $hh_tot ) ); ?></li>
							<?php if ( '' !== $seated_note ) : ?>
							<li><?php echo esc_html( $seated_note ); ?></li>
							<?php endif; ?>
						</ul>
					</section>

					<section class="wgrsvp-vp-section">
						<h3><?php esc_html_e( 'Meal counts (attending)', 'wedding-party-rsvp' ); ?></h3>
						<?php if ( empty( $meal_lines ) ) : ?>
							<p><?php esc_html_e( 'No entrée breakdown yet (or no accepted meals recorded).', 'wedding-party-rsvp' ); ?></p>
						<?php else : ?>
							<table class="wgrsvp-vp-table widefat striped">
								<thead><tr><th><?php esc_html_e( 'Choice', 'wedding-party-rsvp' ); ?></th><th><?php esc_html_e( 'Count', 'wedding-party-rsvp' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $meal_lines as $ml ) : ?>
									<tr><td><?php echo esc_html( $ml['label'] ); ?></td><td><?php echo esc_html( (string) (int) $ml['count'] ); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</section>

					<section class="wgrsvp-vp-section">
						<h3><?php esc_html_e( 'Allergies (attending, excerpt)', 'wedding-party-rsvp' ); ?></h3>
						<?php if ( empty( $allergy_rows ) ) : ?>
							<p><?php esc_html_e( 'No allergy text recorded for attending guests.', 'wedding-party-rsvp' ); ?></p>
						<?php else : ?>
							<table class="wgrsvp-vp-table widefat striped">
								<thead><tr><th><?php esc_html_e( 'Guest', 'wedding-party-rsvp' ); ?></th><th><?php esc_html_e( 'Invitation code', 'wedding-party-rsvp' ); ?></th><th><?php esc_html_e( 'Allergies', 'wedding-party-rsvp' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $allergy_rows as $ar ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $ar['guest_name'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $ar['party_id'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $ar['allergies'] ?? '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
							<?php if ( count( $allergy_rows ) >= 80 ) : ?>
								<p class="description"><?php esc_html_e( 'Showing first 80 rows — full detail is in the guest list export.', 'wedding-party-rsvp' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</section>
				</div>
			</div>
			<style>
				.wgrsvp-vendor-packet-sheet { max-width: 900px; background: #fff; padding: 24px; border: 1px solid #c3c4c7; margin-top: 16px; }
				.wgrsvp-vp-header h2 { margin: 0 0 4px; }
				.wgrsvp-vp-meta { color: #646970; margin: 0 0 20px; }
				.wgrsvp-vp-section { margin-bottom: 28px; page-break-inside: avoid; }
				.wgrsvp-vp-section h3 { margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 6px; }
				.wgrsvp-vp-list { margin: 0; padding-left: 1.2em; }
				.wgrsvp-vp-table { width: 100%; border-collapse: collapse; }
				@media print {
					.no-print { display: none !important; }
					.wgrsvp-vendor-packet-sheet { border: none; padding: 0; }
					a { color: #000; text-decoration: none; }
				}
			</style>
			<?php
		}
	}
}
