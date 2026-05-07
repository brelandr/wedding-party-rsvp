<?php
/**
 * Read-only RSVP summary for couples / planners via signed URL (no WordPress login).
 *
 * Public URLs authenticate with a high-entropy secret in the query string (HMAC-stored server-side), not a
 * WordPress nonce. Treat links like passwords: serve over HTTPS, do not log or cache URLs, rotate on leak,
 * and use revoke/expiry in wp-admin. Rate limiting applies per IP on the public endpoint.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Client_Summary_Portal' ) ) {

	/**
	 * Magic-link client summary (high-level counts only; no guest names).
	 */
	class WGRSVP_Client_Summary_Portal {

		const OPTION_STATE   = 'wgrsvp_client_summary_portal_state';
		const ADMIN_SLUG     = 'wedding-rsvp-client-summary';
		const RATE_TRANSIENT = 'wedding-party-rsvp_client_summary_rl_';

		/**
		 * Bootstrap hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 110 );
			add_action( 'template_redirect', array( __CLASS__, 'maybe_render_public_summary' ), 1 );
		}

		/**
		 * Admin submenu.
		 *
		 * @return void
		 */
		public static function register_admin_menu() {
			if ( ! wgrsvp_admin_module_enabled( 'client_summary' ) ) {
				return;
			}
			add_submenu_page(
				'wedding-rsvp-main',
				__( 'Client summary link', 'wedding-party-rsvp' ),
				__( 'Client summary', 'wedding-party-rsvp' ),
				'manage_options',
				self::ADMIN_SLUG,
				array( __CLASS__, 'render_admin_page' )
			);
		}

		/**
		 * Default portal state.
		 *
		 * @return array<string,mixed>
		 */
		private static function default_state() {
			return array(
				'token_hmac' => '',
				'expires_at' => 0,
				'revoked'    => 0,
			);
		}

		/**
		 * HMAC a plain portal token for storage (never store the raw secret).
		 *
		 * @param string $token Plain token string.
		 * @return string Hex digest from hash_hmac().
		 */
		private static function hash_token( $token ) {
			return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
		}

		/**
		 * Verify rate limit for an IP.
		 *
		 * @param string $ip IP address.
		 * @return bool True if allowed.
		 */
		private static function rate_limit_allow( $ip ) {
			$key   = self::RATE_TRANSIENT . md5( $ip );
			$count = (int) get_transient( $key );
			if ( $count > 60 ) {
				return false;
			}
			set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
			return true;
		}

		/**
		 * When the settings form is posted, verify the nonce and capability, then persist portal options.
		 *
		 * @param array<string,mixed> $state Merged option state before save.
		 * @return array{0: array<string,mixed>, 1: bool} New state and whether a save ran.
		 */
		private static function save_if_form_posted( array $state ) {
			if ( ! isset( $_POST['wgrsvp_client_summary_nonce'] ) ) {
				return array( $state, false );
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_client_summary_nonce'] ) ), 'wgrsvp_client_summary_save' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
			}

			if ( ! wgrsvp_admin_module_enabled( 'client_summary' ) ) {
				wp_die( esc_html__( 'This feature is disabled.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
			}

			$regen = isset( $_POST['wgrsvp_client_summary_regenerate'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_client_summary_regenerate'] ) );
			if ( $regen ) {
				$plain               = wp_generate_password( 48, false, false );
				$state['token_hmac'] = self::hash_token( $plain );
				$state['revoked']    = 0;
				set_transient(
					'wedding-party-rsvp_client_summary_new_token',
					array(
						'token' => $plain,
						'until' => time() + 300,
					),
					300
				);
			}
			if ( isset( $_POST['wgrsvp_client_summary_revoke'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_client_summary_revoke'] ) ) ) {
				$state['revoked'] = 1;
			}
			$exp_raw = isset( $_POST['wgrsvp_client_summary_expires'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_client_summary_expires'] ) ) : '';
			if ( '' !== $exp_raw ) {
				try {
					$d                   = new DateTimeImmutable( $exp_raw, wp_timezone() );
					$state['expires_at'] = $d->getTimestamp();
				} catch ( Exception $e ) {
					$state['expires_at'] = 0;
				}
			} else {
				$state['expires_at'] = 0;
			}
			update_option( self::OPTION_STATE, $state, false );

			return array( $state, true );
		}

		/**
		 * Admin UI + save handler.
		 *
		 * @return void
		 */
		public static function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ) );
			}

			wgrsvp_require_admin_module_or_die( 'client_summary' );

			$state = get_option( self::OPTION_STATE, array() );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
			$state = array_merge( self::default_state(), $state );

			list( $state, $did_save ) = self::save_if_form_posted( $state );
			if ( $did_save ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Client summary settings saved.', 'wedding-party-rsvp' ) . '</p></div>';
			}

			$new_tok = get_transient( 'wedding-party-rsvp_client_summary_new_token' );
			if ( is_array( $new_tok ) && isset( $new_tok['token'] ) && is_string( $new_tok['token'] ) && strlen( $new_tok['token'] ) > 20 ) {
				$url = add_query_arg( 'wgrsvp_client_summary_token', rawurlencode( $new_tok['token'] ), home_url( '/' ) );
				echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Copy this link now — it will not be shown again:', 'wedding-party-rsvp' ) . '</strong></p>';
				echo '<p><input type="text" readonly class="large-text code wgrsvp-select-on-click" value="' . esc_attr( $url ) . '" /></p></div>';
			}

			$exp_display = '';
			if ( ! empty( $state['expires_at'] ) ) {
				$exp_display = wp_date( 'Y-m-d\TH:i', (int) $state['expires_at'] );
			}

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Client summary (magic link)', 'wedding-party-rsvp' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Share a read-only summary with your couple or planning team — guest counts, household RSVP progress, and meal totals. No WordPress login required. The page does not list guest names.', 'wedding-party-rsvp' ); ?>
					<?php if ( function_exists( 'wgrsvp_is_pro_plugin_active' ) && wgrsvp_is_pro_plugin_active() ) : ?>
						<?php esc_html_e( 'Sub-events and seating details still live in wp-admin; this page is a high-level RSVP snapshot only.', 'wedding-party-rsvp' ); ?>
					<?php endif; ?>
				</p>

				<form method="post" style="max-width:720px; margin-top:16px;">
					<?php wp_nonce_field( 'wgrsvp_client_summary_save', 'wgrsvp_client_summary_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Link access', 'wedding-party-rsvp' ); ?></th>
							<td>
								<label><input type="checkbox" name="wgrsvp_client_summary_regenerate" value="1"> <?php esc_html_e( 'Regenerate secret link (invalidates the previous URL)', 'wedding-party-rsvp' ); ?></label>
								<br><br>
								<label><input type="checkbox" name="wgrsvp_client_summary_revoke" value="1"> <?php esc_html_e( 'Revoke access', 'wedding-party-rsvp' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wgrsvp_client_summary_expires"><?php esc_html_e( 'Expiry (UTC, optional)', 'wedding-party-rsvp' ); ?></label></th>
							<td>
								<input type="datetime-local" id="wgrsvp_client_summary_expires" name="wgrsvp_client_summary_expires" value="<?php echo esc_attr( $exp_display ); ?>">
								<p class="description"><?php esc_html_e( 'Leave blank for no expiry.', 'wedding-party-rsvp' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save', 'wedding-party-rsvp' ) ); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * On template_redirect, render the signed-link client summary HTML or no-op.
		 *
		 * Validates token, rate limit, and expiry; aggregates RSVP stats without listing guest names.
		 *
		 * @return void
		 */
		public static function maybe_render_public_summary() {
			if ( ! isset( $_GET['wgrsvp_client_summary_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public share link uses secret token, not a WP nonce.
				return;
			}

			if ( ! wgrsvp_admin_module_enabled( 'client_summary' ) ) {
				wp_die( esc_html__( 'This feature is disabled.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public share link uses secret token + HMAC.
			$token = isset( $_GET['wgrsvp_client_summary_token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_client_summary_token'] ) ) : '';
			if ( strlen( $token ) < 20 ) {
				wp_die( esc_html__( 'Invalid link.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
			}

			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( '' === $ip ) {
				$ip = 'unknown';
			}
			if ( ! self::rate_limit_allow( $ip ) ) {
				wp_die( esc_html__( 'Too many requests. Try again shortly.', 'wedding-party-rsvp' ), '', array( 'response' => 429 ) );
			}

			$state = get_option( self::OPTION_STATE, array() );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
			$state = array_merge( self::default_state(), $state );
			if ( ! empty( $state['revoked'] ) || empty( $state['token_hmac'] ) ) {
				wp_die( esc_html__( 'This link is no longer valid.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
			}
			if ( ! empty( $state['expires_at'] ) && time() > (int) $state['expires_at'] ) {
				wp_die( esc_html__( 'This link has expired.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
			}
			if ( ! hash_equals( (string) $state['token_hmac'], self::hash_token( $token ) ) ) {
				wp_die( esc_html__( 'Invalid link.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'wedding_rsvps';

			$inst = isset( $GLOBALS['wgrsvp_wedding_rsvp_instance'] ) && is_object( $GLOBALS['wgrsvp_wedding_rsvp_instance'] ) ? $GLOBALS['wgrsvp_wedding_rsvp_instance'] : null;
			$agg  = ( $inst && method_exists( $inst, 'get_aggregated_rsvp_stats' ) ) ? $inst->get_aggregated_rsvp_stats() : array();

			$diet_n = 0;
			$all_n  = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Single aggregate; table via prepare %i.
			$diet_n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE dietary_restrictions IS NOT NULL AND TRIM(COALESCE(dietary_restrictions, \'\')) <> \'\'', $table ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$all_n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE allergies IS NOT NULL AND TRIM(COALESCE(allergies, \'\')) <> \'\'', $table ) );

			$settings     = get_option( 'wgrsvp_general_settings', array() );
			$event_title  = is_array( $settings ) && ! empty( $settings['event_title'] ) ? (string) $settings['event_title'] : '';
			$welcome      = is_array( $settings ) && ! empty( $settings['welcome_title'] ) ? (string) $settings['welcome_title'] : '';
			$display_head = '' !== $event_title ? $event_title : $welcome;

			$h_total = isset( $agg['households_total'] ) ? (int) $agg['households_total'] : 0;
			$h_done  = isset( $agg['households_fully_replied'] ) ? (int) $agg['households_fully_replied'] : 0;
			$h_pct   = ( $h_total > 0 ) ? (int) round( 100 * $h_done / $h_total ) : 0;

			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );

			$title = __( 'RSVP summary', 'wedding-party-rsvp' );
			echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
			echo '<title>' . esc_html( $title ) . '</title>';
			echo '<style>body{font-family:system-ui,sans-serif;margin:1.25rem;line-height:1.45;max-width:42rem;color:#1d2327;} h1{font-size:1.35rem;} .muted{color:#50575e;font-size:.9rem;} .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin:1rem 0;} .card{border:1px solid #c3c4c7;border-radius:6px;padding:12px;background:#fff;} .card strong{display:block;font-size:1.4rem;} .bar{height:10px;background:#dcdcde;border-radius:5px;overflow:hidden;margin:8px 0;} .bar > i{display:block;height:100%;background:#2271b1;}</style>';
			echo '</head><body>';
			echo '<h1>' . esc_html( $title ) . '</h1>';
			if ( '' !== $display_head ) {
				echo '<p class="muted">' . esc_html( $display_head ) . '</p>';
			}
			$summary_generated = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() );
			echo '<p class="muted">' . esc_html( get_bloginfo( 'name' ) ) . ' · ' . esc_html( $summary_generated ) . '</p>';

			echo '<h2>' . esc_html__( 'Guest counts', 'wedding-party-rsvp' ) . '</h2>';
			echo '<div class="grid">';
			echo '<div class="card"><strong>' . esc_html( (string) (int) ( $agg['total_accepted'] ?? 0 ) ) . '</strong><span>' . esc_html__( 'Attending', 'wedding-party-rsvp' ) . '</span></div>';
			echo '<div class="card"><strong>' . esc_html( (string) (int) ( $agg['total_declined'] ?? 0 ) ) . '</strong><span>' . esc_html__( 'Declined', 'wedding-party-rsvp' ) . '</span></div>';
			echo '<div class="card"><strong>' . esc_html( (string) (int) ( $agg['total_pending'] ?? 0 ) ) . '</strong><span>' . esc_html__( 'Pending', 'wedding-party-rsvp' ) . '</span></div>';
			$total_guests = (int) ( $agg['total_guests'] ?? 0 );
			// translators: Label under total guest count on the client summary page.
			$total_label = _n( 'Total guest', 'Total guests', $total_guests, 'wedding-party-rsvp' );
			echo '<div class="card"><strong>' . esc_html( (string) $total_guests ) . '</strong><span>' . esc_html( $total_label ) . '</span></div>';
			echo '</div>';

			echo '<h2>' . esc_html__( 'Household RSVP progress', 'wedding-party-rsvp' ) . '</h2>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: households fully replied, 2: total distinct Party IDs, 3: percentage */
					__( '%1$d of %2$d households have everyone replied (no pending) — %3$d%%.', 'wedding-party-rsvp' ),
					$h_done,
					$h_total,
					$h_pct
				)
			) . '</p>';
			if ( $h_total > 0 ) {
				echo '<div class="bar" role="img" aria-label="' . esc_attr( (string) $h_pct . '%' ) . '"><i style="width:' . esc_attr( (string) min( 100, $h_pct ) ) . '%"></i></div>';
			}

			echo '<h2>' . esc_html__( 'Meals (attending)', 'wedding-party-rsvp' ) . '</h2>';
			$menu_stats = isset( $agg['menu_stats_adult'] ) && is_array( $agg['menu_stats_adult'] ) ? $agg['menu_stats_adult'] : array();
			if ( empty( $menu_stats ) ) {
				echo '<p class="muted">' . esc_html__( 'No entrée selections recorded yet for attending guests.', 'wedding-party-rsvp' ) . '</p>';
			} else {
				echo '<ul>';
				foreach ( $menu_stats as $row ) {
					if ( ! is_object( $row ) ) {
						continue;
					}
					$lab = isset( $row->menu_choice ) ? (string) $row->menu_choice : '';
					$cnt = isset( $row->count ) ? (int) $row->count : 0;
					if ( '' === $lab ) {
						continue;
					}
					echo '<li>' . esc_html( $lab ) . ' — ' . esc_html( (string) $cnt ) . '</li>';
				}
				echo '</ul>';
			}

			echo '<h2>' . esc_html__( 'Dietary & allergies (counts only)', 'wedding-party-rsvp' ) . '</h2>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: guests with dietary notes, 2: guests with allergy notes */
					__( '%1$d guests have dietary notes on file. %2$d guests have allergy notes on file.', 'wedding-party-rsvp' ),
					$diet_n,
					$all_n
				)
			) . '</p>';

			echo '<p class="muted">' . esc_html__( 'Confidential summary — do not forward publicly.', 'wedding-party-rsvp' ) . '</p>';
			echo '</body></html>';
			exit;
		}
	}
}
