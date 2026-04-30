<?php
/**
 * Admin report: gifts received and thank-you card tracking; CSV/PDF export.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Gifts_Report' ) ) {

	/**
	 * Gifts & thank-you report screen, print view, and CSV/PDF exports (handlers run on admin_init).
	 */
	class WGRSVP_Gifts_Report {

		public const PAGE_SLUG = 'wedding-rsvp-gifts-report';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'admin_init', array( __CLASS__, 'handle_row_save' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'handle_bulk_mark_sent' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'handle_print_mailing' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'handle_export_csv' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'handle_export_pdf' ), 1 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
			// After Premium rebuilds the Wedding RSVP menu (admin_menu priority 100), re-attach free-only screens.
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 110 );
		}

		/**
		 * Scripts for the gifts report screen (no inline JS in PHP templates).
		 *
		 * @param string $hook_suffix Current admin screen hook suffix.
		 * @return void
		 */
		public static function enqueue_admin_scripts( $hook_suffix ) {
			if ( 'wedding-rsvp-main_page_' . self::PAGE_SLUG !== $hook_suffix ) {
				return;
			}

			$plugin_file = dirname( __DIR__ ) . '/wedding-party-rsvp.php';
			$src         = plugins_url( 'assets/js/wgrsvp-gifts-report-admin.js', $plugin_file );
			wp_enqueue_script(
				'wgrsvp-gifts-report-admin',
				$src,
				array(),
				'8.0.1',
				true
			);
			if ( function_exists( 'wgrsvp_set_script_translations' ) ) {
				wgrsvp_set_script_translations( 'wgrsvp-gifts-report-admin' );
			}
		}

		/**
		 * Submenu under Wedding RSVP.
		 *
		 * @return void
		 */
		public static function register_admin_menu() {
			add_submenu_page(
				'wedding-rsvp-main',
				__( 'Gifts & thank-you', 'wedding-party-rsvp' ),
				__( 'Gifts & thank-you', 'wedding-party-rsvp' ),
				WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD,
				self::PAGE_SLUG,
				array( __CLASS__, 'render_admin_page' )
			);
		}

		/**
		 * Guest table name.
		 *
		 * @return string
		 */
		private static function table_name() {
			global $wpdb;

			return $wpdb->prefix . 'wedding_rsvps';
		}

		/**
		 * Sanitize thank-you card date or null when empty / invalid.
		 *
		 * @param string $raw Raw Y-m-d or empty.
		 * @return string|null
		 */
		private static function sanitize_thankyou_date( $raw ) {
			$raw = is_string( $raw ) ? trim( $raw ) : '';
			if ( '' === $raw ) {
				return null;
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
				return null;
			}

			return $raw;
		}

		/**
		 * Allowed list filters.
		 *
		 * @return string[]
		 */
		private static function allowed_filters() {
			return array( 'pending', 'thankyou_not_sent', 'with_gift', 'all' );
		}

		/**
		 * Current filter from request.
		 *
		 * @return string
		 */
		private static function current_filter() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			$f = isset( $_GET['wgrsvp_gifts_filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['wgrsvp_gifts_filter'] ) ) : 'pending';
			if ( ! in_array( $f, self::allowed_filters(), true ) ) {
				return 'pending';
			}

			return $f;
		}

		/**
		 * Search string from request.
		 *
		 * @return string
		 */
		private static function current_search() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
			return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		}

		/**
		 * Rows for report / export.
		 *
		 * @param string $filter  pending|thankyou_not_sent|with_gift|all.
		 * @param string $search  Name or party substring.
		 * @return array<int,object>
		 */
		public static function get_rows( $filter, $search ) {
			global $wpdb;

			$table = self::table_name();
			$where = array();
			$args  = array( $table );

			if ( '' !== $search ) {
				$where[] = '(guest_name LIKE %s OR party_id LIKE %s)';
				$like    = '%' . $wpdb->esc_like( $search ) . '%';
				$args[]  = $like;
				$args[]  = $like;
			}

			if ( 'pending' === $filter ) {
				$where[] = "thankyou_card_sent_on IS NULL AND gift_received IS NOT NULL AND gift_received <> ''";
			} elseif ( 'thankyou_not_sent' === $filter ) {
				$where[] = 'thankyou_card_sent_on IS NULL';
			} elseif ( 'with_gift' === $filter ) {
				$where[] = "gift_received IS NOT NULL AND gift_received <> ''";
			}

			$sql = 'SELECT * FROM %i';
			if ( ! empty( $where ) ) {
				$sql .= ' WHERE ' . implode( ' AND ', $where );
			}
			$sql .= ' ORDER BY party_id ASC, guest_name ASC';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is fixed template + %i; $where uses placeholders only.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Persist a single gifts/thank-you row from the report screen (admin_init).
		 *
		 * Verifies the row nonce before the capability check, then redirects back to the list or referer.
		 *
		 * @return void
		 */
		public static function handle_row_save() {
			if ( ! isset( $_POST['wgrsvp_gifts_save'], $_POST['wgrsvp_save_gifts_report_row_nonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_save_gifts_report_row_nonce'] ) ), 'wgrsvp_save_gifts_report_row' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$gid = isset( $_POST['wgrsvp_gifts_guest_id'] ) ? absint( wp_unslash( (string) $_POST['wgrsvp_gifts_guest_id'] ) ) : 0;
			if ( $gid < 1 ) {
				return;
			}

			$gift = isset( $_POST['wgrsvp_gifts_received'] ) ? wp_kses_post( wp_unslash( (string) $_POST['wgrsvp_gifts_received'] ) ) : '';
			$ty   = isset( $_POST['wgrsvp_gifts_thankyou_sent'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_gifts_thankyou_sent'] ) ) : '';
			$ty   = self::sanitize_thankyou_date( $ty );

			global $wpdb;
			$table = self::table_name();

			if ( null === $ty ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table %i; gift + id bound.
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET gift_received = %s, thankyou_card_sent_on = NULL WHERE id = %d', $table, $gift, $gid ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET gift_received = %s, thankyou_card_sent_on = %s WHERE id = %d', $table, $gift, $ty, $gid ) );
			}

			do_action( 'wgrsvp_after_gift_thankyou_saved', $gid );

			if ( class_exists( 'WPR_Pro_DB', false ) ) {
				WPR_Pro_DB::bump_wgrsvp_query_cache_gen();
			}

			if ( ! empty( $GLOBALS['wgrsvp_wedding_rsvp_instance'] ) && is_object( $GLOBALS['wgrsvp_wedding_rsvp_instance'] ) && method_exists( $GLOBALS['wgrsvp_wedding_rsvp_instance'], 'wgrsvp_invalidate_guest_caches' ) ) {
				$GLOBALS['wgrsvp_wedding_rsvp_instance']->wgrsvp_invalidate_guest_caches();
			}

			$ref = wp_get_referer();
			if ( is_string( $ref ) && '' !== $ref ) {
				wp_safe_redirect( $ref );
				exit;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		/**
		 * Redirect after bulk thank-you update (preserves list filter).
		 *
		 * @return void
		 */
		private static function redirect_after_bulk() {
			// Nonce verified in handle_bulk_mark_sent() before this runs.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$filter = isset( $_POST['wgrsvp_gifts_bulk_filter'] ) ? sanitize_key( wp_unslash( (string) $_POST['wgrsvp_gifts_bulk_filter'] ) ) : 'pending';
			if ( ! in_array( $filter, self::allowed_filters(), true ) ) {
				$filter = 'pending';
			}
			$search = isset( $_POST['wgrsvp_gifts_bulk_search'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_gifts_bulk_search'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$url = add_query_arg(
				array(
					'page'                => self::PAGE_SLUG,
					'wgrsvp_gifts_filter' => $filter,
					's'                   => $search,
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $url );
			exit;
		}

		/**
		 * Bulk-set thank-you sent date to today for selected guest rows (admin_init).
		 *
		 * Nonce is verified before capability; empty ID lists still redirect with list context preserved.
		 *
		 * @return void
		 */
		public static function handle_bulk_mark_sent() {
			if ( ! isset( $_POST['wgrsvp_gifts_bulk_mark_sent'], $_POST['wgrsvp_gifts_bulk_mark_thankyou_sent_nonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_gifts_bulk_mark_thankyou_sent_nonce'] ) ), 'wgrsvp_gifts_bulk_mark_thankyou_sent' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized to positive integers via map_deep( absint ).
			$raw_ids = isset( $_POST['wgrsvp_gifts_bulk_ids'] ) ? wp_unslash( $_POST['wgrsvp_gifts_bulk_ids'] ) : array();
			if ( ! is_array( $raw_ids ) ) {
				$raw_ids = array();
			}
			$raw_ids = map_deep( $raw_ids, 'absint' );
			$ids     = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
			if ( empty( $ids ) ) {
				self::redirect_after_bulk();
			}

			$today = current_time( 'Y-m-d' );
			global $wpdb;
			$table = self::table_name();

			$in_list = implode( ',', $ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $ids are non-negative integers; table bound via %i.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET thankyou_card_sent_on = %s WHERE id IN (' . $in_list . ')', $table, $today ) );

			foreach ( $ids as $gid ) {
				do_action( 'wgrsvp_after_gift_thankyou_saved', $gid );
			}

			if ( class_exists( 'WPR_Pro_DB', false ) ) {
				WPR_Pro_DB::bump_wgrsvp_query_cache_gen();
			}

			if ( ! empty( $GLOBALS['wgrsvp_wedding_rsvp_instance'] ) && is_object( $GLOBALS['wgrsvp_wedding_rsvp_instance'] ) && method_exists( $GLOBALS['wgrsvp_wedding_rsvp_instance'], 'wgrsvp_invalidate_guest_caches' ) ) {
				$GLOBALS['wgrsvp_wedding_rsvp_instance']->wgrsvp_invalidate_guest_caches();
			}

			self::redirect_after_bulk();
		}

		/**
		 * Output a minimal HTML print view for the current filter and search (admin_init).
		 *
		 * Requires the mailing-sheet nonce and coordinator capability to view the guest dashboard.
		 *
		 * @return void
		 */
		public static function handle_print_mailing() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			$print_flag = isset( $_GET['wgrsvp_gifts_print'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_gifts_print'] ) ) : '';
			if ( '1' !== $print_flag ) {
				return;
			}

			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wgrsvp_print_gifts_mailing_sheet' ) ) {
				wp_die( esc_html__( 'Invalid security token.', 'wedding-party-rsvp' ) );
			}

			if ( ! current_user_can( WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			$filter = isset( $_GET['wgrsvp_gifts_filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['wgrsvp_gifts_filter'] ) ) : 'pending';
			if ( ! in_array( $filter, self::allowed_filters(), true ) ) {
				$filter = 'pending';
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
			$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';

			$rows = self::get_rows( $filter, $search );

			nocache_headers();
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

			$title = __( 'Thank-you / mailing sheet', 'wedding-party-rsvp' );
			?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $title ); ?> — <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<style>
		body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 24px; color: #1d2327; }
		h1 { font-size: 1.25rem; margin: 0 0 16px; }
		.meta { font-size: 12px; color: #50575e; margin-bottom: 24px; }
		table { width: 100%; border-collapse: collapse; }
		th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #c3c4c7; vertical-align: top; }
		th { font-weight: 600; }
		.addr { white-space: pre-wrap; }
		.gift { font-size: 12px; max-width: 280px; }
		@media print {
			body { margin: 12mm; }
			a[href]:after { content: ""; }
		}
	</style>
</head>
<body>
	<h1><?php echo esc_html( $title ); ?></h1>
	<div class="meta">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: filter slug. 2: search text or em dash. */
					__( 'Filter: %1$s. Search: %2$s.', 'wedding-party-rsvp' ),
					$filter,
					'' !== $search ? $search : '—'
				)
			);
			?>
	</div>
	<table>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Name', 'wedding-party-rsvp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Mailing address', 'wedding-party-rsvp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Party', 'wedding-party-rsvp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Gift (reference)', 'wedding-party-rsvp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Thank-you sent', 'wedding-party-rsvp' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php if ( ! is_object( $r ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<?php
				$ty_disp = '—';
				if ( isset( $r->thankyou_card_sent_on ) && is_string( $r->thankyou_card_sent_on ) && '' !== trim( $r->thankyou_card_sent_on ) ) {
					if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $r->thankyou_card_sent_on, $m ) ) {
						$ty_disp = $m[1];
					}
				}
				?>
			<tr>
				<td><?php echo esc_html( isset( $r->guest_name ) ? (string) $r->guest_name : '' ); ?></td>
				<td class="addr"><?php echo esc_html( isset( $r->address ) ? (string) $r->address : '' ); ?></td>
				<td><?php echo esc_html( isset( $r->party_id ) ? (string) $r->party_id : '' ); ?></td>
				<td class="gift"><?php echo wp_kses_post( isset( $r->gift_received ) ? (string) $r->gift_received : '' ); ?></td>
				<td><?php echo esc_html( $ty_disp ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="meta"><?php esc_html_e( 'Use your browser\'s print dialog to print or save as PDF.', 'wedding-party-rsvp' ); ?></p>
</body>
</html>
			<?php
			exit;
		}

		/**
		 * Send gifts/thank-you report as a CSV download (admin_init).
		 *
		 * Export nonce is verified before capability; output is sent to php://output.
		 *
		 * @return void
		 */
		public static function handle_export_csv() {
			if ( ! isset( $_POST['wgrsvp_gifts_export_csv'], $_POST['wgrsvp_export_gifts_thankyou_report_nonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_export_gifts_thankyou_report_nonce'] ) ), 'wgrsvp_export_gifts_thankyou_report' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$filter = isset( $_POST['wgrsvp_gifts_filter'] ) ? sanitize_key( wp_unslash( (string) $_POST['wgrsvp_gifts_filter'] ) ) : 'pending';
			if ( ! in_array( $filter, self::allowed_filters(), true ) ) {
				$filter = 'pending';
			}
			$search = isset( $_POST['wgrsvp_gifts_search'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_gifts_search'] ) ) : '';

			$rows = self::get_rows( $filter, $search );

			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="wedding-rsvp-gifts-thankyou.csv"' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$f = fopen( 'php://output', 'w' );
			fputcsv(
				$f,
				array(
					__( 'Party ID', 'wedding-party-rsvp' ),
					__( 'Name', 'wedding-party-rsvp' ),
					__( 'Email', 'wedding-party-rsvp' ),
					__( 'Phone', 'wedding-party-rsvp' ),
					__( 'Address', 'wedding-party-rsvp' ),
					__( 'Gift received', 'wedding-party-rsvp' ),
					__( 'Thank-you card sent', 'wedding-party-rsvp' ),
				)
			);
			foreach ( $rows as $r ) {
				if ( ! is_object( $r ) ) {
					continue;
				}
				$ty = isset( $r->thankyou_card_sent_on ) && is_string( $r->thankyou_card_sent_on ) && '' !== trim( $r->thankyou_card_sent_on )
					? preg_match( '/^(\d{4}-\d{2}-\d{2})/', $r->thankyou_card_sent_on, $m ) ? $m[1] : trim( $r->thankyou_card_sent_on )
					: '';
				fputcsv(
					$f,
					array(
						isset( $r->party_id ) ? (string) $r->party_id : '',
						isset( $r->guest_name ) ? (string) $r->guest_name : '',
						isset( $r->email ) ? (string) $r->email : '',
						isset( $r->phone ) ? (string) $r->phone : '',
						isset( $r->address ) ? (string) $r->address : '',
						isset( $r->gift_received ) ? wp_strip_all_tags( (string) $r->gift_received ) : '',
						$ty,
					)
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $f );
			exit;
		}

		/**
		 * Send gifts/thank-you report as a PDF via WGRSVP_Gifts_PDF (admin_init).
		 *
		 * Uses the same export nonce and capability order as handle_export_csv().
		 *
		 * @return void
		 */
		public static function handle_export_pdf() {
			if ( ! isset( $_POST['wgrsvp_gifts_export_pdf'], $_POST['wgrsvp_export_gifts_thankyou_report_nonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_export_gifts_thankyou_report_nonce'] ) ), 'wgrsvp_export_gifts_thankyou_report' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$filter = isset( $_POST['wgrsvp_gifts_filter'] ) ? sanitize_key( wp_unslash( (string) $_POST['wgrsvp_gifts_filter'] ) ) : 'pending';
			if ( ! in_array( $filter, self::allowed_filters(), true ) ) {
				$filter = 'pending';
			}
			$search = isset( $_POST['wgrsvp_gifts_search'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_gifts_search'] ) ) : '';

			$rows = self::get_rows( $filter, $search );
			$as_a = array();
			foreach ( $rows as $r ) {
				if ( ! is_object( $r ) ) {
					continue;
				}
				$as_a[] = get_object_vars( $r );
			}

			require_once plugin_dir_path( __FILE__ ) . 'class-wgrsvp-gifts-pdf.php';
			WGRSVP_Gifts_PDF::stream_rows( $as_a, null );
		}

		/**
		 * Admin UI.
		 *
		 * @return void
		 */
		public static function render_admin_page() {
			if ( ! current_user_can( WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			$filter = self::current_filter();
			$search = self::current_search();
			$rows   = self::get_rows( $filter, $search );
			$can_ed = current_user_can( 'manage_options' );

			$list_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
			$print_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'                => self::PAGE_SLUG,
						'wgrsvp_gifts_print'  => '1',
						'wgrsvp_gifts_filter' => $filter,
						's'                   => $search,
					),
					admin_url( 'admin.php' )
				),
				'wgrsvp_print_gifts_mailing_sheet'
			);
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Gifts & thank-you', 'wedding-party-rsvp' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Track gifts received and whether a thank-you card was sent. Data is stored on each guest row (mailing address comes from the guest record when collected on the RSVP form).', 'wedding-party-rsvp' ); ?>
				</p>

				<form method="get" action="<?php echo esc_url( $list_url ); ?>" style="margin:16px 0;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<label for="wgrsvp_gifts_filter"><?php esc_html_e( 'Show', 'wedding-party-rsvp' ); ?></label>
					<select name="wgrsvp_gifts_filter" id="wgrsvp_gifts_filter">
						<option value="pending" <?php selected( $filter, 'pending' ); ?>><?php esc_html_e( 'Thank-you cards still to send (gift recorded, not marked sent)', 'wedding-party-rsvp' ); ?></option>
						<option value="thankyou_not_sent" <?php selected( $filter, 'thankyou_not_sent' ); ?>><?php esc_html_e( 'Thank-you not sent yet (any guest, with or without gift)', 'wedding-party-rsvp' ); ?></option>
						<option value="with_gift" <?php selected( $filter, 'with_gift' ); ?>><?php esc_html_e( 'Everyone with a gift recorded', 'wedding-party-rsvp' ); ?></option>
						<option value="all" <?php selected( $filter, 'all' ); ?>><?php esc_html_e( 'All guests', 'wedding-party-rsvp' ); ?></option>
					</select>
					<label for="wgrsvp_gifts_search" style="margin-left:12px;"><?php esc_html_e( 'Search name or party', 'wedding-party-rsvp' ); ?></label>
					<input type="search" name="s" id="wgrsvp_gifts_search" value="<?php echo esc_attr( $search ); ?>" class="regular-text" />
					<?php submit_button( __( 'Apply', 'wedding-party-rsvp' ), 'secondary', '', false ); ?>
				</form>

				<?php if ( $can_ed && ! empty( $rows ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" id="wgrsvp_gifts_bulk_form" style="margin:0 0 16px;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<?php wp_nonce_field( 'wgrsvp_gifts_bulk_mark_thankyou_sent', 'wgrsvp_gifts_bulk_mark_thankyou_sent_nonce' ); ?>
					<input type="hidden" name="wgrsvp_gifts_bulk_mark_sent" value="1" />
					<input type="hidden" name="wgrsvp_gifts_bulk_filter" value="<?php echo esc_attr( $filter ); ?>" />
					<input type="hidden" name="wgrsvp_gifts_bulk_search" value="<?php echo esc_attr( $search ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Mark selected thank-you cards as sent today', 'wedding-party-rsvp' ); ?></button>
				</form>
				<?php endif; ?>

				<?php if ( $can_ed ) : ?>
				<form method="post" style="display:inline-block;margin:0 8px 16px 0;">
					<?php wp_nonce_field( 'wgrsvp_export_gifts_thankyou_report', 'wgrsvp_export_gifts_thankyou_report_nonce' ); ?>
					<input type="hidden" name="wgrsvp_gifts_filter" value="<?php echo esc_attr( $filter ); ?>" />
					<input type="hidden" name="wgrsvp_gifts_search" value="<?php echo esc_attr( $search ); ?>" />
					<input type="submit" name="wgrsvp_gifts_export_csv" class="button" value="<?php esc_attr_e( 'Export CSV', 'wedding-party-rsvp' ); ?>" />
					<input type="submit" name="wgrsvp_gifts_export_pdf" class="button" value="<?php esc_attr_e( 'Export PDF', 'wedding-party-rsvp' ); ?>" />
				</form>
				<?php endif; ?>
				<a class="button" style="margin:0 8px 16px 0;" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print mailing sheet', 'wedding-party-rsvp' ); ?></a>

				<table class="widefat striped">
					<thead>
						<tr>
							<?php if ( $can_ed ) : ?>
							<th scope="col" class="check-column" style="width:2.2em;">
								<input type="checkbox" id="wgrsvp-gifts-select-all" aria-label="<?php esc_attr_e( 'Select all on this page', 'wedding-party-rsvp' ); ?>" />
							</th>
							<th scope="col" colspan="6"><?php esc_html_e( 'Guests (edit gift and thank-you date below)', 'wedding-party-rsvp' ); ?></th>
							<?php else : ?>
							<th scope="col"><?php esc_html_e( 'Party ID', 'wedding-party-rsvp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Name', 'wedding-party-rsvp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Contact', 'wedding-party-rsvp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Gift received', 'wedding-party-rsvp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Thank-you sent', 'wedding-party-rsvp' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="<?php echo esc_attr( $can_ed ? '7' : '5' ); ?>"><?php esc_html_e( 'No rows match this filter.', 'wedding-party-rsvp' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $r ) : ?>
								<?php if ( ! is_object( $r ) || ! isset( $r->id ) ) : ?>
									<?php continue; ?>
								<?php endif; ?>
								<?php if ( $can_ed ) : ?>
								<tr class="wgrsvp-gifts-report-editable">
									<td colspan="7" style="padding:0;">
										<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;padding:10px;border-bottom:1px solid #c3c4c7;">
											<div style="flex:0 0 auto;align-self:center;">
												<input type="checkbox" class="wgrsvp-gifts-bulk-cb" name="wgrsvp_gifts_bulk_ids[]" value="<?php echo esc_attr( (string) absint( $r->id ) ); ?>" form="wgrsvp_gifts_bulk_form" />
											</div>
										<form method="post" class="wgrsvp-gifts-report-row-form" style="display:flex;flex:1 1 auto;flex-wrap:wrap;gap:10px;align-items:flex-start;min-width:0;">
											<?php wp_nonce_field( 'wgrsvp_save_gifts_report_row', 'wgrsvp_save_gifts_report_row_nonce' ); ?>
											<input type="hidden" name="wgrsvp_gifts_save" value="1" />
											<input type="hidden" name="wgrsvp_gifts_guest_id" value="<?php echo esc_attr( (string) absint( $r->id ) ); ?>" />
											<div style="flex:0 0 100px;min-width:80px;">
												<strong><?php esc_html_e( 'Party', 'wedding-party-rsvp' ); ?></strong><br />
												<?php echo esc_html( isset( $r->party_id ) ? (string) $r->party_id : '' ); ?>
											</div>
											<div style="flex:0 0 120px;min-width:100px;">
												<strong><?php esc_html_e( 'Name', 'wedding-party-rsvp' ); ?></strong><br />
												<?php echo esc_html( isset( $r->guest_name ) ? (string) $r->guest_name : '' ); ?>
											</div>
											<div style="flex:1 1 200px;min-width:180px;">
												<strong><?php esc_html_e( 'Contact / address', 'wedding-party-rsvp' ); ?></strong>
												<div style="font-size:12px;margin-top:4px;">
													<?php if ( ! empty( $r->email ) ) : ?>
														<div><?php echo esc_html( (string) $r->email ); ?></div>
													<?php endif; ?>
													<?php if ( ! empty( $r->phone ) ) : ?>
														<div><?php echo esc_html( (string) $r->phone ); ?></div>
													<?php endif; ?>
													<?php if ( ! empty( $r->address ) ) : ?>
														<div style="margin-top:4px;white-space:pre-wrap;"><?php echo esc_html( (string) $r->address ); ?></div>
													<?php endif; ?>
												</div>
											</div>
											<div style="flex:1 1 220px;min-width:200px;">
												<strong><?php esc_html_e( 'Gift received', 'wedding-party-rsvp' ); ?></strong><br />
												<textarea name="wgrsvp_gifts_received" rows="2" class="large-text" style="width:100%;margin-top:4px;"><?php echo esc_textarea( isset( $r->gift_received ) ? (string) $r->gift_received : '' ); ?></textarea>
											</div>
											<div style="flex:0 0 140px;">
												<strong><?php esc_html_e( 'Thank-you sent', 'wedding-party-rsvp' ); ?></strong><br />
												<?php
												$ty_val = '';
												if ( isset( $r->thankyou_card_sent_on ) && is_string( $r->thankyou_card_sent_on ) && '' !== trim( $r->thankyou_card_sent_on ) ) {
													if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $r->thankyou_card_sent_on, $m ) ) {
														$ty_val = $m[1];
													}
												}
												?>
												<input type="date" name="wgrsvp_gifts_thankyou_sent" value="<?php echo esc_attr( $ty_val ); ?>" style="margin-top:4px;" />
												<p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'Clear date = not sent.', 'wedding-party-rsvp' ); ?></p>
											</div>
											<div style="flex:0 0 auto;align-self:center;">
												<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'wedding-party-rsvp' ); ?></button>
											</div>
										</form>
										</div>
									</td>
								</tr>
								<?php else : ?>
								<tr>
									<td><?php echo esc_html( isset( $r->party_id ) ? (string) $r->party_id : '' ); ?></td>
									<td><?php echo esc_html( isset( $r->guest_name ) ? (string) $r->guest_name : '' ); ?></td>
									<td>
										<div style="font-size:12px;">
											<?php if ( ! empty( $r->email ) ) : ?>
												<div><?php echo esc_html( (string) $r->email ); ?></div>
											<?php endif; ?>
											<?php if ( ! empty( $r->phone ) ) : ?>
												<div><?php echo esc_html( (string) $r->phone ); ?></div>
											<?php endif; ?>
										</div>
									</td>
									<td><?php echo wp_kses_post( isset( $r->gift_received ) ? (string) $r->gift_received : '' ); ?></td>
									<td>
										<?php
										$ty_disp = '—';
										if ( isset( $r->thankyou_card_sent_on ) && is_string( $r->thankyou_card_sent_on ) && '' !== trim( $r->thankyou_card_sent_on ) ) {
											if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $r->thankyou_card_sent_on, $m ) ) {
												$ty_disp = $m[1];
											}
										}
										echo esc_html( $ty_disp );
										?>
									</td>
								</tr>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p class="description">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ); ?>"><?php esc_html_e( '← Back to guest list', 'wedding-party-rsvp' ); ?></a>
				</p>
			</div>
			<?php
		}
	}
}
