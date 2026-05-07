<?php
/**
 * Read-only caterer summary via signed URL token (no WordPress login).
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

if ( ! class_exists( 'WGRSVP_Caterer_Portal' ) ) {

	/**
	 * Magic-link portal for aggregated meal counts (reuses caterer export aggregation).
	 */
	class WGRSVP_Caterer_Portal {

		const OPTION_STATE   = 'wgrsvp_caterer_portal_state';
		const ADMIN_SLUG     = 'wedding-rsvp-caterer-portal';
		const RATE_TRANSIENT = 'wedding-party-rsvp_caterer_rl_';

		/**
		 * Bootstrap hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 110 );
			add_action( 'template_redirect', array( __CLASS__, 'maybe_render_public_portal' ), 1 );
		}

		/**
		 * Admin submenu.
		 *
		 * @return void
		 */
		public static function register_admin_menu() {
			if ( ! wgrsvp_admin_module_enabled( 'caterer_portal' ) ) {
				return;
			}
			add_submenu_page(
				'wedding-rsvp-main',
				__( 'Caterer portal link', 'wedding-party-rsvp' ),
				__( 'Caterer portal', 'wedding-party-rsvp' ),
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
				'token_hmac'           => '',
				'expires_at'           => 0,
				'revoked'              => 0,
				'show_names'           => 0,
				'include_non_accepted' => 0,
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
			if ( $count > 90 ) {
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
			if ( ! isset( $_POST['wgrsvp_caterer_portal_nonce'] ) ) {
				return array( $state, false );
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgrsvp_caterer_portal_nonce'] ) ), 'wgrsvp_caterer_portal_save' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), array( 'response' => 403 ) );
			}

			if ( ! wgrsvp_admin_module_enabled( 'caterer_portal' ) ) {
				wp_die( esc_html__( 'This feature is disabled.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
			}

			$regen = isset( $_POST['wgrsvp_caterer_regenerate'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_caterer_regenerate'] ) );
			if ( $regen ) {
				$plain               = wp_generate_password( 48, false, false );
				$state['token_hmac'] = self::hash_token( $plain );
				$state['revoked']    = 0;
				set_transient(
					'wedding-party-rsvp_caterer_new_token',
					array(
						'token' => $plain,
						'until' => time() + 300,
					),
					300
				);
			}
			if ( isset( $_POST['wgrsvp_caterer_revoke'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_caterer_revoke'] ) ) ) {
				$state['revoked'] = 1;
			}
			$exp_raw = isset( $_POST['wgrsvp_caterer_expires'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_caterer_expires'] ) ) : '';
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
			$state['show_names']           = isset( $_POST['wgrsvp_caterer_show_names'] ) ? 1 : 0;
			$state['include_non_accepted'] = isset( $_POST['wgrsvp_caterer_include_non_accepted'] ) ? 1 : 0;
			update_option( self::OPTION_STATE, $state, false );

			return array( $state, true );
		}

		/**
		 * Render portal settings and process POST saves (nonce verified before mutating state).
		 *
		 * @return void
		 */
		public static function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ) );
			}

			wgrsvp_require_admin_module_or_die( 'caterer_portal' );

			$state = get_option( self::OPTION_STATE, array() );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
			$state = array_merge( self::default_state(), $state );

			list( $state, $did_save ) = self::save_if_form_posted( $state );
			if ( $did_save ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Caterer portal settings saved.', 'wedding-party-rsvp' ) . '</p></div>';
			}

			$new_tok = get_transient( 'wedding-party-rsvp_caterer_new_token' );
			if ( is_array( $new_tok ) && ! empty( $new_tok['token'] ) ) {
				$url = add_query_arg( 'wgrsvp_caterer_token', rawurlencode( (string) $new_tok['token'] ), home_url( '/' ) );
				echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Copy this link now. It will not be shown again.', 'wedding-party-rsvp' ) . '</strong></p>';
				echo '<p><input type="text" readonly class="large-text code wgrsvp-select-on-click" value="' . esc_attr( $url ) . '"></p></div>';
			}

			$exp_display = '';
			if ( ! empty( $state['expires_at'] ) ) {
				$exp_display = wp_date( 'Y-m-d\TH:i', (int) $state['expires_at'] );
			}

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Caterer portal (magic link)', 'wedding-party-rsvp' ); ?></h1>
				<p class="description"><?php esc_html_e( 'Share a read-only summary of meal counts by table with kitchen staff. No WordPress login is required. Revoke or regenerate the link if it is leaked.', 'wedding-party-rsvp' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'wgrsvp_caterer_portal_save', 'wgrsvp_caterer_portal_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Link status', 'wedding-party-rsvp' ); ?></th>
							<td>
								<?php if ( ! empty( $state['revoked'] ) ) : ?>
									<p><?php esc_html_e( 'Revoked. Regenerate to create a new link.', 'wedding-party-rsvp' ); ?></p>
								<?php elseif ( empty( $state['token_hmac'] ) ) : ?>
									<p><?php esc_html_e( 'No token yet. Check “Regenerate link” and save.', 'wedding-party-rsvp' ); ?></p>
								<?php else : ?>
									<p><?php esc_html_e( 'Active. The full URL is only shown once when you regenerate.', 'wedding-party-rsvp' ); ?></p>
								<?php endif; ?>
								<label><input type="checkbox" name="wgrsvp_caterer_regenerate" value="1"> <?php esc_html_e( 'Regenerate secret link (invalidates the previous URL)', 'wedding-party-rsvp' ); ?></label>
								<br><br>
								<label><input type="checkbox" name="wgrsvp_caterer_revoke" value="1"> <?php esc_html_e( 'Revoke access', 'wedding-party-rsvp' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><label for="wgrsvp_caterer_expires"><?php esc_html_e( 'Expiry (UTC, optional)', 'wedding-party-rsvp' ); ?></label></th>
							<td>
								<input type="datetime-local" id="wgrsvp_caterer_expires" name="wgrsvp_caterer_expires" value="<?php echo esc_attr( $exp_display ); ?>">
								<p class="description"><?php esc_html_e( 'Leave blank for no expiry.', 'wedding-party-rsvp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Privacy', 'wedding-party-rsvp' ); ?></th>
							<td>
								<label><input type="checkbox" name="wgrsvp_caterer_show_names" value="1" <?php checked( ! empty( $state['show_names'] ) ); ?>> <?php esc_html_e( 'Show guest names in the table (off by default)', 'wedding-party-rsvp' ); ?></label>
								<br><br>
								<label><input type="checkbox" name="wgrsvp_caterer_include_non_accepted" value="1" <?php checked( ! empty( $state['include_non_accepted'] ) ); ?>> <?php esc_html_e( 'Include non-accepted RSVPs in counts (like optional caterer export checkbox)', 'wedding-party-rsvp' ); ?></label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save', 'wedding-party-rsvp' ) ); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * On template_redirect, render the signed-link caterer summary HTML or no-op.
		 *
		 * Validates the token, rate limit, and portal state before querying guest rows.
		 *
		 * @return void
		 */
		public static function maybe_render_public_portal() {
			if ( ! isset( $_GET['wgrsvp_caterer_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public portal uses secret token, not a WP nonce.
				return;
			}

			if ( ! wgrsvp_admin_module_enabled( 'caterer_portal' ) ) {
				wp_die( esc_html__( 'This feature is disabled.', 'wedding-party-rsvp' ), '', array( 'response' => 403 ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public portal uses secret token + HMAC.
			$token = isset( $_GET['wgrsvp_caterer_token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_caterer_token'] ) ) : '';
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
			/**
			 * Guest rows before caterer aggregation (e.g. Pro maps table_id to display names).
			 *
			 * @since 7.3.23
			 * @param array<int,array<string,mixed>> $rows Rows.
			 */
			$rows = apply_filters( 'wgrsvp_caterer_portal_rows', $rows );

			$accepted_only = empty( $state['include_non_accepted'] );
			// Admin export loads this on demand; public portal hits template_redirect with only core includes bootstrapped.
			$export_class_file = plugin_dir_path( __FILE__ ) . 'class-wgrsvp-vendor-catering-export.php';
			if ( is_readable( $export_class_file ) ) {
				require_once $export_class_file;
			}
			if ( ! class_exists( 'WGRSVP_Vendor_Catering_Export', false ) ) {
				wp_die( esc_html__( 'Portal unavailable.', 'wedding-party-rsvp' ), '', array( 'response' => 500 ) );
			}
			$agg = WGRSVP_Vendor_Catering_Export::aggregate_by_table( $rows, $accepted_only );
			WGRSVP_Vendor_Catering_Export::sort_table_keys( $agg );

			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );

			$show_names = ! empty( $state['show_names'] );
			echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
			echo '<title>' . esc_html__( 'Catering summary', 'wedding-party-rsvp' ) . '</title>';
			echo '<style>body{font-family:system-ui,sans-serif;margin:1rem;line-height:1.4;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #ccc;padding:8px;text-align:left;} th{background:#f5f5f5;} .muted{color:#666;font-size:.9rem;}</style>';
			echo '</head><body>';
			echo '<h1>' . esc_html__( 'Catering summary', 'wedding-party-rsvp' ) . '</h1>';
			$portal_note = sprintf(
				/* translators: %s: site title */
				__( 'Prepared for kitchen / venue staff — %s', 'wedding-party-rsvp' ),
				get_bloginfo( 'name' )
			);
			echo '<p class="muted">' . esc_html( $portal_note ) . '</p>';
			echo '<table><thead><tr>';
			echo '<th>' . esc_html__( 'Table', 'wedding-party-rsvp' ) . '</th>';
			echo '<th>' . esc_html__( 'Guests', 'wedding-party-rsvp' ) . '</th>';
			echo '<th>' . esc_html__( 'Meals', 'wedding-party-rsvp' ) . '</th>';
			echo '<th>' . esc_html__( 'Dietary', 'wedding-party-rsvp' ) . '</th>';
			echo '<th>' . esc_html__( 'Allergies', 'wedding-party-rsvp' ) . '</th>';
			if ( $show_names ) {
				echo '<th>' . esc_html__( 'Names', 'wedding-party-rsvp' ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $agg as $tbl => $bucket ) {
				$breakdown = array();
				foreach ( $bucket['meals'] as $lab => $n ) {
					$breakdown[] = (int) $n . '× ' . $lab;
				}
				echo '<tr>';
				echo '<td>' . esc_html( $tbl ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $bucket['count'] ) . '</td>';
				echo '<td>' . esc_html( implode( '; ', $breakdown ) ) . '</td>';
				echo '<td>' . esc_html( implode( ' | ', array_values( $bucket['diet'] ) ) ) . '</td>';
				echo '<td>' . esc_html( implode( ' | ', array_values( $bucket['allergy'] ) ) ) . '</td>';
				if ( $show_names ) {
					$names = array();
					foreach ( $rows as $r ) {
						if ( ! is_array( $r ) ) {
							continue;
						}
						$st = isset( $r['rsvp_status'] ) ? (string) $r['rsvp_status'] : '';
						if ( $accepted_only && 'Accepted' !== $st ) {
							continue;
						}
						$tkey = isset( $r['table_number'] ) ? trim( (string) $r['table_number'] ) : '';
						if ( '' === $tkey ) {
							$tkey = '—';
						}
						if ( (string) $tbl !== (string) $tkey ) {
							continue;
						}
						if ( ! empty( $r['guest_name'] ) ) {
							$names[] = sanitize_text_field( (string) $r['guest_name'] );
						}
					}
					echo '<td>' . esc_html( implode( ', ', array_unique( $names ) ) ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="muted">' . esc_html__( 'Confidential — for event staff only.', 'wedding-party-rsvp' ) . '</p>';
			echo '</body></html>';
			exit;
		}
	}
}
