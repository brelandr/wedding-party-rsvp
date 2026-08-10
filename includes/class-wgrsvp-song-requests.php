<?php
/**
 * Admin report: song requests submitted on the RSVP form.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Song_Requests' ) ) {

	/**
	 * Song requests playlist screen and CSV export.
	 */
	class WGRSVP_Song_Requests {

		public const PAGE_SLUG = 'wedding-rsvp-song-requests';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'admin_init', array( __CLASS__, 'handle_export_csv' ), 1 );
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 110 );
		}

		/**
		 * Submenu under Wedding RSVP.
		 *
		 * @return void
		 */
		public static function register_admin_menu() {
			if ( ! wgrsvp_admin_module_enabled( 'song_requests' ) ) {
				return;
			}
			add_submenu_page(
				'wedding-rsvp-main',
				__( 'Song requests', 'wedding-party-rsvp' ),
				__( 'Song requests', 'wedding-party-rsvp' ),
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
		 * Guests with a non-empty song request.
		 *
		 * @param string $search Optional name/party search.
		 * @return array<int,object>
		 */
		private static function get_rows( $search = '' ) {
			global $wpdb;
			$t      = self::table_name();
			$search = is_string( $search ) ? trim( $search ) : '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin report; table via %i.
			if ( '' !== $search ) {
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, party_id, guest_name, rsvp_status, song_request
						FROM %i
						WHERE song_request IS NOT NULL AND TRIM(song_request) <> %s
						AND ( guest_name LIKE %s OR party_id LIKE %s OR song_request LIKE %s )
						ORDER BY song_request ASC, party_id ASC, guest_name ASC',
						$t,
						'',
						$like,
						$like,
						$like
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, party_id, guest_name, rsvp_status, song_request
						FROM %i
						WHERE song_request IS NOT NULL AND TRIM(song_request) <> %s
						ORDER BY song_request ASC, party_id ASC, guest_name ASC',
						$t,
						''
					)
				);
			}

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * CSV export (admin_init).
		 *
		 * @return void
		 */
		public static function handle_export_csv() {
			if ( ! isset( $_POST['wgrsvp_song_requests_export_csv'] ) ) {
				return;
			}
			if ( ! isset( $_POST['wgrsvp_export_song_requests_nonce'] )
				|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_export_song_requests_nonce'] ) ), 'wgrsvp_export_song_requests' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ) );
			}
			if ( ! current_user_can( WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}
			wgrsvp_require_admin_module_or_die( 'song_requests' );

			$search = isset( $_POST['wgrsvp_song_search'] ) ? sanitize_text_field( wp_unslash( $_POST['wgrsvp_song_search'] ) ) : '';
			$rows   = self::get_rows( $search );
			$fname  = 'wedding-rsvp-song-requests-' . gmdate( 'Y-m-d' ) . '.csv';

			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $fname );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV stream to browser (same pattern as gifts report).
			$out = fopen( 'php://output', 'w' );
			if ( false === $out ) {
				wp_die( esc_html__( 'Could not open export stream.', 'wedding-party-rsvp' ) );
			}
			fputcsv( $out, array( 'Party ID', 'Guest name', 'RSVP status', 'Song request' ) );
			foreach ( $rows as $r ) {
				fputcsv(
					$out,
					array(
						isset( $r->party_id ) ? (string) $r->party_id : '',
						isset( $r->guest_name ) ? (string) $r->guest_name : '',
						isset( $r->rsvp_status ) ? (string) $r->rsvp_status : '',
						isset( $r->song_request ) ? (string) $r->song_request : '',
					)
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out );
			exit;
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
			wgrsvp_require_admin_module_or_die( 'song_requests' );

			$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			$rows   = self::get_rows( $search );
			$url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Song requests', 'wedding-party-rsvp' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Songs guests submitted on the RSVP form. Share this playlist with your DJ or band.', 'wedding-party-rsvp' ); ?>
				</p>

				<form method="get" action="<?php echo esc_url( $url ); ?>" style="margin:16px 0;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<label for="wgrsvp_song_search"><?php esc_html_e( 'Search', 'wedding-party-rsvp' ); ?></label>
					<input type="search" name="s" id="wgrsvp_song_search" value="<?php echo esc_attr( $search ); ?>" class="regular-text" />
					<?php submit_button( __( 'Apply', 'wedding-party-rsvp' ), 'secondary', '', false ); ?>
				</form>

				<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" style="margin:0 0 16px;">
					<?php wp_nonce_field( 'wgrsvp_export_song_requests', 'wgrsvp_export_song_requests_nonce' ); ?>
					<input type="hidden" name="wgrsvp_song_search" value="<?php echo esc_attr( $search ); ?>" />
					<input type="submit" name="wgrsvp_song_requests_export_csv" class="button" value="<?php esc_attr_e( 'Export CSV', 'wedding-party-rsvp' ); ?>" />
				</form>
				<?php endif; ?>

				<p>
					<?php
					printf(
						/* translators: %d: number of song requests */
						esc_html( _n( '%d song request', '%d song requests', count( $rows ), 'wedding-party-rsvp' ) ),
						(int) count( $rows )
					);
					?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Song request', 'wedding-party-rsvp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Guest', 'wedding-party-rsvp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Party ID', 'wedding-party-rsvp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'RSVP', 'wedding-party-rsvp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr>
								<td colspan="4"><?php esc_html_e( 'No song requests yet.', 'wedding-party-rsvp' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<td><strong><?php echo esc_html( (string) $r->song_request ); ?></strong></td>
									<td><?php echo esc_html( (string) $r->guest_name ); ?></td>
									<td><?php echo esc_html( (string) $r->party_id ); ?></td>
									<td><?php echo esc_html( (string) $r->rsvp_status ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
	}
}
