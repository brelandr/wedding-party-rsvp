<?php
/**
 * First-run setup wizard (activation redirect + 3 steps).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Setup_Wizard' ) ) {

	/**
	 * Setup wizard controller.
	 */
	class WGRSVP_Setup_Wizard {

		const OPTION_DONE        = 'wgrsvp_setup_wizard_done';
		const TRANSIENT_REDIRECT = 'wgrsvp_activation_redirect_wizard';
		const OPTION_WIZARD_PAGE = 'wgrsvp_setup_wizard_rsvp_page_id';
		const PAGE_SLUG          = 'wgrsvp-setup-wizard';

		/**
		 * Main plugin instance reference.
		 *
		 * @var WGRSVP_Wedding_RSVP
		 */
		private $plugin;

		/**
		 * Constructor.
		 *
		 * @param WGRSVP_Wedding_RSVP $plugin Main plugin instance (for shared option keys / URLs).
		 */
		public function __construct( $plugin ) {
			$this->plugin = $plugin;
		}

		/**
		 * Bootstrap hooks.
		 *
		 * @return void
		 */
		public function init() {
			add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ), 1 );
			add_action( 'admin_menu', array( $this, 'register_wizard_page' ) );
			add_action( 'admin_init', array( $this, 'handle_wizard_requests' ), 5 );
		}

		/**
		 * Call from activation hook after DB setup.
		 *
		 * @return void
		 */
		public static function flag_activation_redirect() {
			if ( ! self::should_offer_wizard() ) {
				return;
			}
			set_transient( self::TRANSIENT_REDIRECT, '1', 10 * MINUTE_IN_SECONDS );
		}

		/**
		 * Whether the wizard should run (first-time, not CLI/bulk heuristics handled elsewhere).
		 *
		 * @return bool
		 */
		private static function should_offer_wizard() {
			if ( get_option( self::OPTION_DONE, '' ) ) {
				return false;
			}
			if ( self::site_has_existing_plugin_data() ) {
				return false;
			}
			return true;
		}

		/**
		 * Detect existing plugin usage so activation redirect does not interrupt updates or reactivations.
		 *
		 * Checks guest rows, saved general settings, adult menu options, and stored license key.
		 *
		 * @return bool True when the site already has meaningful plugin data.
		 */
		private static function site_has_existing_plugin_data() {
			$guest_count = self::get_cached_guest_table_row_count();
			if ( $guest_count > 0 ) {
				return true;
			}

			$settings = get_option( 'wgrsvp_general_settings', null );
			if ( is_array( $settings ) ) {
				foreach ( $settings as $value ) {
					if ( is_string( $value ) && '' !== trim( $value ) ) {
						return true;
					}
					if ( is_array( $value ) && ! empty( $value ) ) {
						return true;
					}
				}
			}

			$menu = get_option( 'wgrsvp_menu_options', null );
			if ( is_array( $menu ) && ! empty( $menu ) ) {
				return true;
			}

			$lic = get_option( 'wgrsvp_license_key', '' );
			if ( is_string( $lic ) && '' !== trim( $lic ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Guest table row count with object cache (used for activation / wizard gating).
		 *
		 * @return int
		 */
		private static function get_cached_guest_table_row_count() {
			global $wpdb;

			$cache_group = 'wedding_rsvp';
			$cache_key   = 'wgrsvp_wizard_guest_count';

			$cached = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached ) {
				return (int) $cached;
			}

			$table = $wpdb->prefix . 'wedding_rsvps';

			$wp_version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0';
			if ( version_compare( $wp_version, '6.2', '>=' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Next line builds SQL via prepare( %i ).
				$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Result of prepare(); identifier placeholder.
				$count = (int) $wpdb->get_var( $sql );
			} else {
				$table_safe = '`' . str_replace( '`', '``', $table ) . '`';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WP before 6.2 (no %i): table from $wpdb->prefix + literal suffix; COUNT() only.
				$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table_safe );
			}

			wp_cache_set( $cache_key, $count, $cache_group, 5 * MINUTE_IN_SECONDS );

			return $count;
		}

		/**
		 * Redirect once to the wizard after single-plugin activation.
		 *
		 * @return void
		 */
		public function maybe_redirect_after_activation() {
			if ( ! is_admin() ) {
				return;
			}
			if ( wp_doing_ajax() ) {
				return;
			}
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return;
			}
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return;
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( get_option( self::OPTION_DONE, '' ) ) {
				return;
			}
			if ( ! get_transient( self::TRANSIENT_REDIRECT ) ) {
				return;
			}

			// Stale transient (e.g. data imported after activation): do not redirect.
			if ( self::site_has_existing_plugin_data() ) {
				delete_transient( self::TRANSIENT_REDIRECT );
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only core admin query args (bulk activate flag, plugins.php error string, wizard ?page=); not form actions; cannot use wp_verify_nonce() (WordPress core does not attach one to these redirects).
			global $pagenow;
			if ( isset( $_GET['activate-multi'] ) ) {
				delete_transient( self::TRANSIENT_REDIRECT );
				return;
			}

			if ( 'plugins.php' === $pagenow && isset( $_GET['error'] ) ) {
				delete_transient( self::TRANSIENT_REDIRECT );
				return;
			}

			if ( isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
				delete_transient( self::TRANSIENT_REDIRECT );
				return;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

			delete_transient( self::TRANSIENT_REDIRECT );

			wp_safe_redirect(
				admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=1' )
			);
			exit;
		}

		/**
		 * Hidden admin page (no menu item).
		 *
		 * @return void
		 */
		public function register_wizard_page() {
			add_submenu_page(
				null,
				__( 'Wedding RSVP — Setup', 'wedding-party-rsvp' ),
				'',
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_wizard' )
			);
		}

		/**
		 * Handles wizard POST steps and GET skip: nonce is verified first in each branch.
		 *
		 * @return void
		 */
		public function handle_wizard_requests() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonces are verified in each mutating branch below; this is a read-only `page` gate.
			if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonces checked as first line inside each processing branch.
			if ( isset( $_GET['wgrsvp_skip_wizard'] ) ) {
				if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wgrsvp_skip_setup_wizard' ) ) {
					return;
				}
				update_option( self::OPTION_DONE, '1', false );
				delete_transient( self::TRANSIENT_REDIRECT );
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-main' ) );
				exit;
			}

			if ( isset( $_POST['wgrsvp_wizard_step1_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_wizard_step1', 'wgrsvp_wizard_step1_nonce' );
				if ( ! isset( $_POST['wgrsvp_wizard_step1'] ) ) {
					return;
				}
				$prev = get_option( $this->plugin->get_general_settings_option_name(), array() );
				if ( ! is_array( $prev ) ) {
					$prev = array();
				}
				/**
				 * MANUAL REVIEW REQUIRED — Keep consistent with General Settings `welcome_title` handling
				 * (see docblock on that option in `wedding-party-rsvp.php`).
				 */
				$welcome = isset( $_POST['welcome_title'] ) ? sanitize_text_field( wp_unslash( $_POST['welcome_title'] ) ) : '';
				$rsvpurl = isset( $_POST['rsvp_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['rsvp_page_url'] ) ) : '';
				$merged  = array_merge(
					$prev,
					array(
						'welcome_title' => $welcome,
						'rsvp_page_url' => $rsvpurl,
					)
				);
				update_option( $this->plugin->get_general_settings_option_name(), $merged );

				wp_safe_redirect(
					admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=2' )
				);
				exit;
			}

			if ( isset( $_POST['wgrsvp_wizard_step2_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_wizard_step2', 'wgrsvp_wizard_step2_nonce' );
				if ( ! isset( $_POST['wgrsvp_wizard_create_page'] ) ) {
					return;
				}
				$page_id = $this->create_or_get_rsvp_page();
				if ( $page_id > 0 ) {
					$permalink = get_permalink( $page_id );
					if ( $permalink ) {
						$prev = get_option( $this->plugin->get_general_settings_option_name(), array() );
						if ( ! is_array( $prev ) ) {
							$prev = array();
						}
						$prev['rsvp_page_url'] = $permalink;
						update_option( $this->plugin->get_general_settings_option_name(), $prev );
					}
					update_option( self::OPTION_WIZARD_PAGE, $page_id, false );
					$this->maybe_insert_test_guest();
				}
				wp_safe_redirect(
					admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=3' )
				);
				exit;
			}

			if ( isset( $_POST['wgrsvp_wizard_finish_nonce'] ) ) {
				check_admin_referer( 'wgrsvp_wizard_finish', 'wgrsvp_wizard_finish_nonce' );
				if ( ! isset( $_POST['wgrsvp_wizard_finish'] ) ) {
					return;
				}
				update_option( self::OPTION_DONE, '1', false );
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-main' ) );
				exit;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		/**
		 * Create published page with shortcode or return existing wizard page ID.
		 *
		 * @return int Post ID or 0 on failure.
		 */
		private function create_or_get_rsvp_page() {
			$existing = absint( get_option( self::OPTION_WIZARD_PAGE, 0 ) );
			if ( $existing > 0 && get_post_status( $existing ) ) {
				return $existing;
			}

			$post_id = wp_insert_post(
				array(
					'post_title'   => __( 'RSVP', 'wedding-party-rsvp' ),
					'post_content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Please RSVP below.', 'wedding-party-rsvp' ) . '</p><!-- /wp:paragraph --><!-- wp:shortcode -->[wedding_rsvp_form]<!-- /wp:shortcode -->',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return 0;
			}

			return (int) $post_id;
		}

		/**
		 * One test guest for trying Party ID on the public form.
		 *
		 * @return void
		 */
		private function maybe_insert_test_guest() {
			global $wpdb;
			$table       = $wpdb->prefix . 'wedding_rsvps';
			$pid         = 'WIZARD-TEST';
			$cache_group = 'wedding_rsvp';
			$cache_key   = 'wgrsvp_wizard_test_party_' . md5( $wpdb->prefix . $pid );

			$cached_exists = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached_exists && 1 === (int) $cached_exists ) {
				return;
			}

			$wp_version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0';
			if ( version_compare( $wp_version, '6.2', '>=' ) ) {
				$sql = $wpdb->prepare(
					'SELECT id FROM %i WHERE party_id = %s LIMIT 1',
					$table,
					$pid
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Query built with prepare() above; table uses %i.
				$exists = $wpdb->get_var( $sql );
			} else {
				$table_safe = '`' . str_replace( '`', '``', $table ) . '`';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP before 6.2: table identifier concatenated; party_id uses %s (single-line prepare so sniff applies to same instruction).
				$sql = $wpdb->prepare( 'SELECT id FROM ' . $table_safe . ' WHERE party_id = %s LIMIT 1', $pid );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Output of prepare(); table from prefix + literal suffix.
				$exists = $wpdb->get_var( $sql );
			}

			if ( $exists ) {
				wp_cache_set( $cache_key, 1, $cache_group, 12 * HOUR_IN_SECONDS );
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->insert(
				$table,
				array(
					'party_id'   => $pid,
					'guest_name' => __( 'Sample guest (wizard)', 'wedding-party-rsvp' ),
				)
			);
			wp_cache_set( $cache_key, 1, $cache_group, 12 * HOUR_IN_SECONDS );
			wp_cache_delete( 'wgrsvp_wizard_guest_count', $cache_group );
			$this->plugin->clear_dashboard_stats_cache();
		}

		/**
		 * Render wizard screens.
		 *
		 * @return void
		 */
		public function render_wizard() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( $step < 1 || $step > 3 ) {
				$step = 1;
			}

			$s           = get_option( $this->plugin->get_general_settings_option_name(), array() );
			$skip_url    = wp_nonce_url(
				add_query_arg(
					array(
						'page'               => self::PAGE_SLUG,
						'wgrsvp_skip_wizard' => '1',
					),
					admin_url( 'admin.php' )
				),
				'wgrsvp_skip_setup_wizard'
			);
			$pro_url     = apply_filters(
				'wgrsvp_pro_marketing_url',
				'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/?utm_source=wp-plugin-free&utm_medium=setup-wizard&utm_campaign=pro'
			);
			$page_id     = absint( get_option( self::OPTION_WIZARD_PAGE, 0 ) );
			$public_link = ( $page_id && get_post_status( $page_id ) ) ? get_permalink( $page_id ) : '';

			?>
			<div class="wrap wgrsvp-setup-wizard">
				<h1 class="screen-reader-text"><?php esc_html_e( 'Wedding RSVP setup', 'wedding-party-rsvp' ); ?></h1>

				<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
					<p class="description" style="margin:0;">
						<?php esc_html_e( 'Quick setup — about a minute. You can leave anytime.', 'wedding-party-rsvp' ); ?>
					</p>
					<a href="<?php echo esc_url( $skip_url ); ?>" class="button"><?php esc_html_e( 'Skip setup', 'wedding-party-rsvp' ); ?></a>
				</div>

				<ul class="wgrsvp-wizard-steps" style="list-style:none; margin:0 0 24px; padding:0; display:flex; gap:8px; flex-wrap:wrap;">
					<?php
					$labels = array(
						1 => __( 'Welcome & setup', 'wedding-party-rsvp' ),
						2 => __( 'Test RSVP page', 'wedding-party-rsvp' ),
						3 => __( 'Success', 'wedding-party-rsvp' ),
					);
					foreach ( $labels as $n => $label ) :
						$class = (int) $step === $n ? 'button button-primary' : 'button';
						$url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . absint( $n ) );
						?>
						<li><a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $n . '. ' . $label ); ?></a></li>
					<?php endforeach; ?>
				</ul>

				<?php if ( 1 === $step ) : ?>
					<div class="card" style="max-width:720px;">
						<h2><?php esc_html_e( 'Welcome', 'wedding-party-rsvp' ); ?></h2>
						<p><?php esc_html_e( 'Set a couple of basics for your public RSVP form. You can change everything later under Wedding RSVP → Settings.', 'wedding-party-rsvp' ); ?></p>
						<form method="post">
							<?php wp_nonce_field( 'wgrsvp_wizard_step1', 'wgrsvp_wizard_step1_nonce' ); ?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="wgrsvp_wizard_welcome"><?php esc_html_e( 'Welcome title', 'wedding-party-rsvp' ); ?></label></th>
									<td>
										<input type="text" name="welcome_title" id="wgrsvp_wizard_welcome" class="regular-text"
											value="<?php echo esc_attr( $s['welcome_title'] ?? '' ); ?>"
											placeholder="<?php esc_attr_e( 'e.g. Welcome to our wedding!', 'wedding-party-rsvp' ); ?>">
										<p class="description"><?php esc_html_e( 'Shown above the RSVP form after guests enter their Party ID.', 'wedding-party-rsvp' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wgrsvp_wizard_rsvp_url"><?php esc_html_e( 'RSVP page URL', 'wedding-party-rsvp' ); ?></label></th>
									<td>
										<input type="url" name="rsvp_page_url" id="wgrsvp_wizard_rsvp_url" class="regular-text code"
											value="<?php echo esc_url( $s['rsvp_page_url'] ?? '' ); ?>"
											placeholder="<?php esc_attr_e( 'Optional — set after you create your RSVP page in step 2', 'wedding-party-rsvp' ); ?>">
										<p class="description"><?php esc_html_e( 'Used for admin “copy RSVP link” actions. Step 2 can fill this automatically.', 'wedding-party-rsvp' ); ?></p>
									</td>
								</tr>
							</table>
							<p class="submit">
								<button type="submit" name="wgrsvp_wizard_step1" class="button button-primary"><?php esc_html_e( 'Continue', 'wedding-party-rsvp' ); ?></button>
							</p>
						</form>
					</div>
				<?php elseif ( 2 === $step ) : ?>
					<div class="card" style="max-width:720px;">
						<h2><?php esc_html_e( 'Create your RSVP page', 'wedding-party-rsvp' ); ?></h2>
						<p><?php esc_html_e( 'We will publish a page that includes the RSVP shortcode and set your RSVP page URL.', 'wedding-party-rsvp' ); ?></p>
						<p><?php esc_html_e( 'We also add one sample guest with Party ID WIZARD-TEST so you can try the form immediately.', 'wedding-party-rsvp' ); ?></p>
						<?php if ( $page_id && $public_link ) : ?>
							<div class="notice notice-success inline"><p>
								<strong><?php esc_html_e( 'RSVP page is ready.', 'wedding-party-rsvp' ); ?></strong>
								<a href="<?php echo esc_url( $public_link ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View page', 'wedding-party-rsvp' ); ?></a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( get_edit_post_link( $page_id, 'raw' ) ); ?>"><?php esc_html_e( 'Edit page', 'wedding-party-rsvp' ); ?></a>
							</p></div>
							<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=3' ) ); ?>"><?php esc_html_e( 'Continue to success', 'wedding-party-rsvp' ); ?></a></p>
						<?php else : ?>
							<form method="post">
								<?php wp_nonce_field( 'wgrsvp_wizard_step2', 'wgrsvp_wizard_step2_nonce' ); ?>
								<p class="submit">
									<button type="submit" name="wgrsvp_wizard_create_page" class="button button-primary"><?php esc_html_e( 'Create RSVP page', 'wedding-party-rsvp' ); ?></button>
								</p>
							</form>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="card" style="max-width:720px;">
						<h2><?php esc_html_e( 'You are ready to collect RSVPs', 'wedding-party-rsvp' ); ?></h2>
						<p><?php esc_html_e( 'Your guest list lives under Wedding RSVP in the admin menu. Add guests, import CSV, or share Party IDs with households.', 'wedding-party-rsvp' ); ?></p>
						<?php if ( $public_link ) : ?>
							<p>
								<strong><?php esc_html_e( 'Public RSVP page:', 'wedding-party-rsvp' ); ?></strong><br>
								<a href="<?php echo esc_url( $public_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $public_link ); ?></a>
							</p>
							<p class="description"><?php esc_html_e( 'Try Party ID WIZARD-TEST on that page to see the sample guest.', 'wedding-party-rsvp' ); ?></p>
						<?php endif; ?>

						<?php if ( function_exists( 'wgrsvp_is_pro_plugin_active' ) && ! wgrsvp_is_pro_plugin_active() ) : ?>
						<div style="background:#f6f7f7; border:1px solid #c3c4c7; padding:16px; margin-top:16px; border-radius:4px;">
							<h3 style="margin-top:0;"><?php esc_html_e( 'Need seating charts, batch email & SMS, or deeper customization?', 'wedding-party-rsvp' ); ?></h3>
							<p style="margin-bottom:12px;"><?php esc_html_e( 'Wedding Party RSVP Pro adds advanced seating and notes, child meals, batch invitations, styling options, and more — while keeping this free plugin as your core guest list.', 'wedding-party-rsvp' ); ?></p>
							<a href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e( 'Explore Pro', 'wedding-party-rsvp' ); ?></a>
						</div>
						<?php endif; ?>

						<form method="post" style="margin-top:20px;">
							<?php wp_nonce_field( 'wgrsvp_wizard_finish', 'wgrsvp_wizard_finish_nonce' ); ?>
							<p class="submit">
								<button type="submit" name="wgrsvp_wizard_finish" class="button button-primary"><?php esc_html_e( 'Go to guest list', 'wedding-party-rsvp' ); ?></button>
							</p>
						</form>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
