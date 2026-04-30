<?php
/**
 * Follow-up queue and day-of desk (large-touch lookup, optional manual arrival).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Ops_Center' ) ) {

	/**
	 * Admin screen: prioritized follow-up + day-of search.
	 */
	class WGRSVP_Ops_Center {

		const PAGE_SLUG = 'wedding-rsvp-ops';

		/**
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 110 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		}

		/**
		 * @return string
		 */
		private static function table_name() {
			global $wpdb;

			return $wpdb->prefix . 'wedding_rsvps';
		}

		/**
		 * @return void
		 */
		public static function register_menu() {
			add_submenu_page(
				'wedding-rsvp-main',
				__( 'Follow-up & day-of', 'wedding-party-rsvp' ),
				__( 'Follow-up & day-of', 'wedding-party-rsvp' ),
				WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD,
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * @param string $hook_suffix Hook suffix.
		 * @return void
		 */
		public static function enqueue_assets( $hook_suffix ) {
			if ( 'wedding-rsvp_page_' . self::PAGE_SLUG !== $hook_suffix ) {
				return;
			}
			$css = '
				.wgrsvp-ops-tabs { margin: 12px 0 20px; border-bottom: 1px solid #c3c4c7; }
				.wgrsvp-ops-tabs a { display: inline-block; padding: 10px 16px; text-decoration: none; margin-bottom: -1px; }
				.wgrsvp-ops-tabs a.wgrsvp-ops-tab-active { border: 1px solid #c3c4c7; border-bottom-color: #fff; background: #fff; font-weight: 600; }
				.wgrsvp-ops-statgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
				.wgrsvp-ops-statbox { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 14px; text-align: center; }
				.wgrsvp-ops-statbox a { text-decoration: none; font-size: 28px; font-weight: 700; display: block; color: #1d2327; }
				.wgrsvp-ops-statbox span { font-size: 12px; color: #646970; }
				.wgrsvp-ops-dayof-search { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 16px; }
				.wgrsvp-ops-dayof-search input[type="search"] { min-width: 260px; font-size: 18px; padding: 10px 12px; height: auto; }
				.wgrsvp-ops-dayof-search .button-primary { font-size: 16px; padding: 10px 18px; height: auto; }
				.wgrsvp-ops-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
				.wgrsvp-ops-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
				.wgrsvp-ops-card h3 { margin: 0 0 8px; font-size: 20px; line-height: 1.2; }
				.wgrsvp-ops-card .wgrsvp-ops-meta { color: #646970; font-size: 13px; margin-bottom: 10px; }
				.wgrsvp-ops-card .wgrsvp-ops-actions { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
				.wgrsvp-ops-card .wgrsvp-ops-actions .button { min-height: 40px; line-height: 38px; }
				.wgrsvp-ops-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
				.wgrsvp-ops-badge-ok { background: #d5f0dd; color: #1e4620; }
				.wgrsvp-ops-badge-warn { background: #fcf0c3; color: #614200; }
				@media (max-width: 782px) {
					.wgrsvp-ops-dayof-search input[type="search"] { width: 100%; min-width: 0; }
				}
			';
			wp_register_style( 'wgrsvp-ops-center', false, array(), '8.0.1' );
			wp_enqueue_style( 'wgrsvp-ops-center' );
			wp_add_inline_style( 'wgrsvp-ops-center', $css );
		}

		/**
		 * @param object $row Guest row.
		 * @return array{0:string,1:string} Source pro|free or empty, ISO-ish mysql datetime.
		 */
		private static function arrival_state( $row ) {
			if ( ! is_object( $row ) ) {
				return array( '', '' );
			}
			if ( isset( $row->wpr_pro_attended_at ) && is_string( $row->wpr_pro_attended_at ) && '' !== trim( $row->wpr_pro_attended_at ) ) {
				return array( 'pro', trim( $row->wpr_pro_attended_at ) );
			}
			if ( isset( $row->wgrsvp_arrived_at ) && is_string( $row->wgrsvp_arrived_at ) && '' !== trim( $row->wgrsvp_arrived_at ) ) {
				return array( 'free', trim( $row->wgrsvp_arrived_at ) );
			}

			return array( '', '' );
		}

		/**
		 * @param string $mysql_utc MySQL datetime UTC.
		 * @return string
		 */
		private static function format_arrival_time( $mysql_utc ) {
			if ( '' === $mysql_utc || ! is_string( $mysql_utc ) ) {
				return '';
			}
			$ts = strtotime( $mysql_utc . ' UTC' );
			if ( false === $ts ) {
				return $mysql_utc;
			}

			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		}

		/**
		 * @return void
		 */
		public static function render_page() {
			if ( ! current_user_can( WGRSVP_Coordinator_Role::CAP_VIEW_GUEST_DASHBOARD ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ) );
			}

			$tab = isset( $_GET['wgrsvp_ops_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['wgrsvp_ops_tab'] ) ) : 'followup'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'dayof' !== $tab ) {
				$tab = 'followup';
			}

			$main_url = admin_url( 'admin.php?page=wedding-rsvp-main' );
			$ops_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Follow-up & day-of', 'wedding-party-rsvp' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Work pending replies and stragglers, or use the day-of desk for fast name lookup at the door.', 'wedding-party-rsvp' ); ?>
				</p>

				<div class="wgrsvp-ops-tabs">
					<a href="<?php echo esc_url( add_query_arg( 'wgrsvp_ops_tab', 'followup', $ops_url ) ); ?>" class="<?php echo 'followup' === $tab ? 'wgrsvp-ops-tab-active' : ''; ?>"><?php esc_html_e( 'Follow-up queue', 'wedding-party-rsvp' ); ?></a>
					<a href="<?php echo esc_url( add_query_arg( 'wgrsvp_ops_tab', 'dayof', $ops_url ) ); ?>" class="<?php echo 'dayof' === $tab ? 'wgrsvp-ops-tab-active' : ''; ?>"><?php esc_html_e( 'Day-of desk', 'wedding-party-rsvp' ); ?></a>
				</div>

				<?php
				if ( 'dayof' === $tab ) {
					self::render_dayof_tab( $ops_url );
				} else {
					self::render_followup_tab( $main_url );
				}
				?>

				<p style="margin-top:24px;">
					<a href="<?php echo esc_url( $main_url ); ?>"><?php esc_html_e( '← Wedding Dashboard', 'wedding-party-rsvp' ); ?></a>
				</p>
			</div>
			<?php
		}

		/**
		 * @param string $main_url Guest list URL.
		 * @return void
		 */
		private static function render_followup_tab( $main_url ) {
			global $wpdb;

			$table = self::table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$n_pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rsvp_status = %s', $table, 'Pending' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$n_no_email = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE (email IS NULL OR TRIM(COALESCE(email, %s)) = %s)', $table, '', '' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$n_no_phone = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE (phone IS NULL OR TRIM(COALESCE(phone, %s)) = %s)', $table, '', '' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$n_pend_nc = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE rsvp_status = %s AND (email IS NULL OR TRIM(COALESCE(email, '')) = '') AND (phone IS NULL OR TRIM(COALESCE(phone, '')) = '')",
					$table,
					'Pending'
				)
			);

			$u_pending  = add_query_arg(
				array(
					'filter_status' => 'Pending',
					'wgrsvp_group'  => '1',
				),
				$main_url
			);
			$u_no_email = add_query_arg( array( 'wgrsvp_gap' => 'no_email' ), $main_url );
			$u_no_phone = add_query_arg( array( 'wgrsvp_gap' => 'no_phone' ), $main_url );
			$u_pend_nc  = add_query_arg( array( 'wgrsvp_gap' => 'pending_no_contact' ), $main_url );

			?>
			<div class="wgrsvp-ops-statgrid">
				<div class="wgrsvp-ops-statbox">
					<a href="<?php echo esc_url( $u_pending ); ?>"><?php echo esc_html( (string) $n_pending ); ?></a>
					<span><?php esc_html_e( 'Pending RSVPs', 'wedding-party-rsvp' ); ?></span>
				</div>
				<div class="wgrsvp-ops-statbox">
					<a href="<?php echo esc_url( $u_pend_nc ); ?>"><?php echo esc_html( (string) $n_pend_nc ); ?></a>
					<span><?php esc_html_e( 'Pending & no email/phone', 'wedding-party-rsvp' ); ?></span>
				</div>
				<div class="wgrsvp-ops-statbox">
					<a href="<?php echo esc_url( $u_no_email ); ?>"><?php echo esc_html( (string) $n_no_email ); ?></a>
					<span><?php esc_html_e( 'Rows missing email', 'wedding-party-rsvp' ); ?></span>
				</div>
				<div class="wgrsvp-ops-statbox">
					<a href="<?php echo esc_url( $u_no_phone ); ?>"><?php echo esc_html( (string) $n_no_phone ); ?></a>
					<span><?php esc_html_e( 'Rows missing phone', 'wedding-party-rsvp' ); ?></span>
				</div>
			</div>

			<h2><?php esc_html_e( 'Parties with mixed replies', 'wedding-party-rsvp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Someone in the party accepted and someone is still pending—open the grouped list filtered to that Party ID.', 'wedding-party-rsvp' ); ?></p>
			<?php
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$mixed = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT party_id FROM %i GROUP BY party_id HAVING SUM(CASE WHEN rsvp_status = %s THEN 1 ELSE 0 END) > 0 AND SUM(CASE WHEN rsvp_status = %s THEN 1 ELSE 0 END) > 0 LIMIT 40',
					$table,
					'Pending',
					'Accepted'
				)
			);
			if ( ! is_array( $mixed ) || empty( $mixed ) ) {
				echo '<p>' . esc_html__( 'None right now.', 'wedding-party-rsvp' ) . '</p>';
			} else {
				echo '<ul style="list-style:disc; margin-left:1.2em;">';
				foreach ( $mixed as $pid ) {
					$pid = (string) $pid;
					$u   = add_query_arg(
						array(
							's'            => $pid,
							'wgrsvp_group' => '1',
						),
						$main_url
					);
					echo '<li><a href="' . esc_url( $u ) . '">' . esc_html( $pid ) . '</a></li>';
				}
				echo '</ul>';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$pending_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, party_id, guest_name, email, phone, rsvp_status FROM %i WHERE rsvp_status = %s ORDER BY party_id ASC, guest_name ASC LIMIT 200', $table, 'Pending' ) );
			if ( ! is_array( $pending_rows ) ) {
				$pending_rows = array();
			}
			?>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Pending guests (first 200)', 'wedding-party-rsvp' ); ?></h2>
			<table class="widefat striped" style="max-width:920px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Party', 'wedding-party-rsvp' ); ?></th>
						<th><?php esc_html_e( 'Name', 'wedding-party-rsvp' ); ?></th>
						<th><?php esc_html_e( 'Contact', 'wedding-party-rsvp' ); ?></th>
						<th><?php esc_html_e( 'Open', 'wedding-party-rsvp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $pending_rows ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No pending rows.', 'wedding-party-rsvp' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $pending_rows as $pr ) : ?>
							<?php
							$has_em = ! empty( $pr->email );
							$has_ph = ! empty( $pr->phone );
							$row_u  = add_query_arg(
								array(
									's'            => (string) $pr->party_id,
									'wgrsvp_group' => '1',
								),
								$main_url
							);
							?>
							<tr>
								<td><?php echo esc_html( (string) $pr->party_id ); ?></td>
								<td><?php echo esc_html( (string) $pr->guest_name ); ?></td>
								<td>
									<?php if ( $has_em || $has_ph ) : ?>
										<?php if ( $has_em ) : ?>
											<span class="dashicons dashicons-email" title="<?php esc_attr_e( 'Has email', 'wedding-party-rsvp' ); ?>"></span>
										<?php endif; ?>
										<?php if ( $has_ph ) : ?>
											<span class="dashicons dashicons-smartphone" title="<?php esc_attr_e( 'Has phone', 'wedding-party-rsvp' ); ?>"></span>
										<?php endif; ?>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'No email/phone', 'wedding-party-rsvp' ); ?></span>
									<?php endif; ?>
								</td>
								<td><a class="button button-small" href="<?php echo esc_url( $row_u ); ?>"><?php esc_html_e( 'Guest list', 'wedding-party-rsvp' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * @param string $ops_url This screen URL (no query).
		 * @return void
		 */
		private static function render_dayof_tab( $ops_url ) {
			global $wpdb;

			$table = self::table_name();
			$q     = isset( $_GET['wgrsvp_dayof_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_dayof_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$scope = isset( $_GET['wgrsvp_dayof_scope'] ) ? sanitize_key( wp_unslash( (string) $_GET['wgrsvp_dayof_scope'] ) ) : 'accepted'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'all' !== $scope ) {
				$scope = 'accepted';
			}

			$dayof_base = add_query_arg( 'wgrsvp_ops_tab', 'dayof', $ops_url );
			$can_mark   = current_user_can( 'manage_options' );

			?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wgrsvp-ops-dayof-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="wgrsvp_ops_tab" value="dayof">
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Search', 'wedding-party-rsvp' ); ?></span>
					<input type="search" name="wgrsvp_dayof_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Name or Party ID…', 'wedding-party-rsvp' ); ?>" autocomplete="off">
				</label>
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Scope', 'wedding-party-rsvp' ); ?></span>
					<select name="wgrsvp_dayof_scope">
						<option value="accepted" <?php selected( 'accepted', $scope ); ?>><?php esc_html_e( 'Attending only', 'wedding-party-rsvp' ); ?></option>
						<option value="all" <?php selected( 'all', $scope ); ?>><?php esc_html_e( 'All statuses', 'wedding-party-rsvp' ); ?></option>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'wedding-party-rsvp' ); ?></button>
			</form>

			<?php
			if ( strlen( $q ) < 2 ) {
				echo '<p class="description">' . esc_html__( 'Enter at least two characters to search.', 'wedding-party-rsvp' ) . '</p>';
				return;
			}

			$like = '%' . $wpdb->esc_like( $q ) . '%';
			if ( 'all' === $scope ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE (guest_name LIKE %s OR party_id LIKE %s) ORDER BY party_id ASC, guest_name ASC LIMIT 60',
						$table,
						$like,
						$like
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE rsvp_status = %s AND (guest_name LIKE %s OR party_id LIKE %s) ORDER BY party_id ASC, guest_name ASC LIMIT 60',
						$table,
						'Accepted',
						$like,
						$like
					)
				);
			}
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}

			if ( empty( $rows ) ) {
				echo '<p>' . esc_html__( 'No matching guests for this search.', 'wedding-party-rsvp' ) . '</p>';
				return;
			}

			echo '<div class="wgrsvp-ops-cards">';
			foreach ( $rows as $guest ) {
				list( $arr_src, $arr_at ) = self::arrival_state( $guest );
				$meal                     = isset( $guest->menu_choice ) ? (string) $guest->menu_choice : '';
				$diet                     = isset( $guest->dietary_restrictions ) ? trim( (string) $guest->dietary_restrictions ) : '';
				$allg                     = isset( $guest->allergies ) ? trim( (string) $guest->allergies ) : '';
				$rsvp                     = isset( $guest->rsvp_status ) ? (string) $guest->rsvp_status : '';

				echo '<div class="wgrsvp-ops-card">';
				echo '<h3>' . esc_html( (string) $guest->guest_name ) . '</h3>';
				echo '<div class="wgrsvp-ops-meta">';
				echo esc_html( (string) $guest->party_id );
				echo ' · ';
				echo esc_html( $rsvp );
				if ( '' !== $meal ) {
					echo '<br>' . esc_html__( 'Meal:', 'wedding-party-rsvp' ) . ' ' . esc_html( $meal );
				}
				if ( '' !== $diet || '' !== $allg ) {
					echo '<br><span class="description">';
					if ( '' !== $diet ) {
						echo esc_html__( 'Dietary:', 'wedding-party-rsvp' ) . ' ' . esc_html( $diet );
					}
					if ( '' !== $allg ) {
						echo ( '' !== $diet ? ' · ' : '' ) . esc_html__( 'Allergies:', 'wedding-party-rsvp' ) . ' ' . esc_html( $allg );
					}
					echo '</span>';
				}
				echo '</div>';

				if ( '' !== $arr_src ) {
					echo '<p class="wgrsvp-ops-badge wgrsvp-ops-badge-ok">';
					if ( 'pro' === $arr_src ) {
						printf(
							/* translators: %s: formatted datetime */
							esc_html__( 'Checked in (Pro): %s', 'wedding-party-rsvp' ),
							esc_html( self::format_arrival_time( $arr_at ) )
						);
					} else {
						printf(
							/* translators: %s: formatted datetime */
							esc_html__( 'Marked arrived: %s', 'wedding-party-rsvp' ),
							esc_html( self::format_arrival_time( $arr_at ) )
						);
					}
					echo '</p>';
				} else {
					echo '<p class="wgrsvp-ops-badge wgrsvp-ops-badge-warn">' . esc_html__( 'Not marked arrived yet', 'wedding-party-rsvp' ) . '</p>';
				}

				echo '<div class="wgrsvp-ops-actions">';
				if ( $can_mark ) {
					if ( '' === $arr_src ) {
						echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
						wp_nonce_field( 'wgrsvp_dayof_arrival', 'wgrsvp_dayof_arrival_nonce' );
						echo '<input type="hidden" name="action" value="wgrsvp_dayof_arrival">';
						echo '<input type="hidden" name="wgrsvp_arrival_guest_id" value="' . esc_attr( (string) (int) $guest->id ) . '">';
						echo '<input type="hidden" name="wgrsvp_arrival_do" value="mark">';
						echo '<input type="hidden" name="wgrsvp_dayof_q" value="' . esc_attr( $q ) . '">';
						echo '<input type="hidden" name="wgrsvp_dayof_scope" value="' . esc_attr( $scope ) . '">';
						echo '<button type="submit" class="button button-primary">' . esc_html__( 'Mark arrived', 'wedding-party-rsvp' ) . '</button>';
						echo '</form>';
					} elseif ( 'free' === $arr_src ) {
						echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
						wp_nonce_field( 'wgrsvp_dayof_arrival', 'wgrsvp_dayof_arrival_nonce' );
						echo '<input type="hidden" name="action" value="wgrsvp_dayof_arrival">';
						echo '<input type="hidden" name="wgrsvp_arrival_guest_id" value="' . esc_attr( (string) (int) $guest->id ) . '">';
						echo '<input type="hidden" name="wgrsvp_arrival_do" value="clear">';
						echo '<input type="hidden" name="wgrsvp_dayof_q" value="' . esc_attr( $q ) . '">';
						echo '<input type="hidden" name="wgrsvp_dayof_scope" value="' . esc_attr( $scope ) . '">';
						echo '<button type="submit" class="button">' . esc_html__( 'Undo arrival', 'wedding-party-rsvp' ) . '</button>';
						echo '</form>';
					}
				} else {
					echo '<span class="description">' . esc_html__( 'Administrators can mark arrivals from this screen.', 'wedding-party-rsvp' ) . '</span>';
				}
				echo '</div>';
				echo '</div>';
			}
			echo '</div>';

			if ( $can_mark ) {
				echo '<p class="description">' . esc_html__( 'Manual arrival is stored on each guest row. Wedding Party RSVP Pro can also record check-ins via QR; those appear as “Checked in (Pro)”.', 'wedding-party-rsvp' ) . '</p>';
			}
		}
	}
}
