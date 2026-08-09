<?php
/**
 * Wedding setup guide — ordered checklist + walkthrough for coordinators/admins.
 *
 * Free steps ship here. Pro (and other add-ons) may append/reorder via
 * `wgrsvp_setup_guide_steps`.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup guide controller.
 */
class WGRSVP_Setup_Guide {

	public const PAGE_SLUG           = 'wgrsvp-setup-guide';
	public const OPT_MANUAL_DONE     = 'wgrsvp_setup_guide_manual_done';
	public const OPT_GUIDE_DISMISSED = 'wgrsvp_setup_guide_banner_dismissed';

	/**
	 * Main plugin instance.
	 *
	 * @var WGRSVP_Wedding_RSVP|null
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param WGRSVP_Wedding_RSVP $plugin Main plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Priority 110: after Pro merge rebuilds `wedding-rsvp-main` (same pattern as Ops/Help).
		add_action( 'admin_menu', array( $this, 'register_menu' ), 110 );
		add_action( 'admin_init', array( $this, 'handle_requests' ), 6 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wgrsvp_guest_list_after_title', array( $this, 'render_guest_list_banner' ), 6, 2 );
	}

	/**
	 * Admin URL for the guide (optionally focused on a step).
	 *
	 * @param string $step_id Optional step id.
	 * @return string
	 */
	public static function url( $step_id = '' ) {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( is_string( $step_id ) && '' !== $step_id ) {
			$args['step'] = sanitize_key( $step_id );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Visible submenu under Wedding RSVP.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'wedding-rsvp-main',
			__( 'Wedding setup', 'wedding-party-rsvp' ),
			__( 'Setup guide', 'wedding-party-rsvp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Styles for the guide screen only.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page gate.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$css = '
		.wgrsvp-setup-guide { max-width: 960px; }
		.wgrsvp-setup-guide__progress { margin: 0 0 1.25rem; }
		.wgrsvp-setup-guide__bar {
			height: 10px; background: #dcdcde; border-radius: 999px; overflow: hidden; margin: 0.4rem 0 0.25rem;
		}
		.wgrsvp-setup-guide__bar > span {
			display: block; height: 100%; background: #2271b1; border-radius: 999px;
		}
		.wgrsvp-setup-guide__layout {
			display: grid; grid-template-columns: minmax(220px, 280px) 1fr; gap: 1.25rem; align-items: start;
		}
		@media (max-width: 782px) {
			.wgrsvp-setup-guide__layout { grid-template-columns: 1fr; }
		}
		.wgrsvp-setup-guide__nav {
			background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 0.5rem 0; margin: 0;
		}
		.wgrsvp-setup-guide__nav h3 {
			margin: 0; padding: 0.65rem 1rem 0.35rem; font-size: 12px; text-transform: uppercase;
			letter-spacing: 0.04em; color: #646970;
		}
		.wgrsvp-setup-guide__nav ol { margin: 0; padding: 0; list-style: none; }
		.wgrsvp-setup-guide__nav li { margin: 0; }
		.wgrsvp-setup-guide__nav a {
			display: flex; gap: 0.5rem; align-items: flex-start; text-decoration: none;
			padding: 0.55rem 1rem; border-left: 3px solid transparent; color: #1d2327;
		}
		.wgrsvp-setup-guide__nav a:hover { background: #f6f7f7; }
		.wgrsvp-setup-guide__nav a.is-active { border-left-color: #2271b1; background: #f0f6fc; font-weight: 600; }
		.wgrsvp-setup-guide__nav a.is-done { color: #1d2327; }
		.wgrsvp-setup-guide__mark {
			flex: 0 0 1.25rem; width: 1.25rem; height: 1.25rem; border-radius: 50%;
			border: 2px solid #c3c4c7; display: inline-flex; align-items: center; justify-content: center;
			font-size: 11px; line-height: 1; margin-top: 2px; color: #fff;
		}
		.wgrsvp-setup-guide__nav a.is-done .wgrsvp-setup-guide__mark {
			background: #00a32a; border-color: #00a32a;
		}
		.wgrsvp-setup-guide__panel {
			background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 1.25rem 1.5rem;
		}
		.wgrsvp-setup-guide__panel .description { max-width: 42rem; }
		.wgrsvp-setup-guide__actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1.25rem; align-items: center; }
		.wgrsvp-setup-guide__badge {
			display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 6px; border-radius: 3px;
			background: #f0f0f1; color: #50575e; margin-left: 0.35rem; vertical-align: middle;
		}
		.wgrsvp-setup-guide__badge--pro { background: #f0f6fc; color: #135e96; }
		.wgrsvp-setup-guide__badge--optional { background: #f6f7f7; color: #646970; }
		';
		$ver = '8.2.12';
		if ( defined( 'WGRSVP_PLUGIN_FILE' ) && function_exists( 'get_file_data' ) ) {
			$hdr = get_file_data( WGRSVP_PLUGIN_FILE, array( 'Version' => 'Version' ), 'plugin' );
			if ( ! empty( $hdr['Version'] ) ) {
				$ver = (string) $hdr['Version'];
			}
		}
		wp_register_style( 'wgrsvp-setup-guide', false, array(), $ver );
		wp_enqueue_style( 'wgrsvp-setup-guide' );
		wp_add_inline_style( 'wgrsvp-setup-guide', $css );
	}

	/**
	 * Handle mark-complete / unmark / dismiss actions.
	 *
	 * @return void
	 */
	public function handle_requests() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified per branch.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( (string) $_GET['page'] ) ) ) {
			// Allow dismiss from guest list without being on this page.
			$this->maybe_handle_banner_dismiss();
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing
		if ( isset( $_GET['wgrsvp_guide_mark'], $_GET['_wpnonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'wgrsvp_setup_guide_mark' ) ) {
				return;
			}
			$step_id = sanitize_key( wp_unslash( (string) $_GET['wgrsvp_guide_mark'] ) );
			$this->set_manual_done( $step_id, true );
			wp_safe_redirect( self::url( $step_id ) );
			exit;
		}

		if ( isset( $_GET['wgrsvp_guide_unmark'], $_GET['_wpnonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'wgrsvp_setup_guide_unmark' ) ) {
				return;
			}
			$step_id = sanitize_key( wp_unslash( (string) $_GET['wgrsvp_guide_unmark'] ) );
			$this->set_manual_done( $step_id, false );
			wp_safe_redirect( self::url( $step_id ) );
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

		$this->maybe_handle_banner_dismiss();
	}

	/**
	 * Dismiss the guest-list setup banner.
	 *
	 * @return void
	 */
	private function maybe_handle_banner_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wgrsvp_dismiss_notice'] ) || 'setup_guide_banner' !== sanitize_key( wp_unslash( (string) $_GET['wgrsvp_dismiss_notice'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'wgrsvp_dismiss_growth_notice' ) ) {
			return;
		}
		update_option( self::OPT_GUIDE_DISMISSED, 1, false );
		wp_safe_redirect( remove_query_arg( array( 'wgrsvp_dismiss_notice', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Compact progress banner on the guest list.
	 *
	 * @param bool   $can_manage_rsvp Whether user can manage RSVP.
	 * @param object $plugin          Main plugin.
	 * @return void
	 */
	public function render_guest_list_banner( $can_manage_rsvp, $plugin ) {
		unset( $plugin );
		if ( ! $can_manage_rsvp || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::OPT_GUIDE_DISMISSED, false ) ) {
			return;
		}

		$steps   = $this->get_steps();
		$done    = 0;
		$required = 0;
		foreach ( $steps as $step ) {
			if ( ! empty( $step['optional'] ) ) {
				continue;
			}
			++$required;
			if ( $this->is_step_complete( $step ) ) {
				++$done;
			}
		}
		if ( $required > 0 && $done >= $required ) {
			return;
		}

		$pct = $required > 0 ? (int) round( ( $done / $required ) * 100 ) : 0;
		$next = $this->get_first_incomplete_step( $steps );
		$dismiss = wp_nonce_url(
			add_query_arg( 'wgrsvp_dismiss_notice', 'setup_guide_banner' ),
			'wgrsvp_dismiss_growth_notice'
		);
		?>
		<div class="notice notice-info" style="padding:12px 14px;">
			<p style="margin:0 0 8px;">
				<strong><?php esc_html_e( 'Wedding setup guide', 'wedding-party-rsvp' ); ?></strong>
				—
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: completed required steps, 2: total required steps */
						__( '%1$d of %2$d required setup steps complete.', 'wedding-party-rsvp' ),
						$done,
						$required
					)
				);
				?>
			</p>
			<div class="wgrsvp-setup-guide__bar" style="max-width:360px;height:8px;background:#dcdcde;border-radius:999px;overflow:hidden;margin:0 0 10px;">
				<span style="display:block;height:100%;width:<?php echo esc_attr( (string) $pct ); ?>%;background:#2271b1;"></span>
			</div>
			<p style="margin:0;">
				<?php if ( $next ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( self::url( $next['id'] ) ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: next step title */
								__( 'Continue: %s', 'wedding-party-rsvp' ),
								$next['title']
							)
						);
						?>
					</a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Open full checklist', 'wedding-party-rsvp' ); ?></a>
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'wedding-party-rsvp' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the guide page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wedding-party-rsvp' ) );
		}

		$steps = $this->get_steps();
		if ( empty( $steps ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Wedding setup', 'wedding-party-rsvp' ) . '</h1>';
			echo '<p>' . esc_html__( 'No setup steps are available.', 'wedding-party-rsvp' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( (string) $_GET['step'] ) ) : '';
		$current   = null;
		foreach ( $steps as $step ) {
			if ( $requested === $step['id'] ) {
				$current = $step;
				break;
			}
		}
		if ( null === $current ) {
			$current = $this->get_first_incomplete_step( $steps );
			if ( null === $current ) {
				$current = $steps[0];
			}
		}

		$done_req = 0;
		$req      = 0;
		$done_all = 0;
		foreach ( $steps as $step ) {
			$complete = $this->is_step_complete( $step );
			if ( $complete ) {
				++$done_all;
			}
			if ( empty( $step['optional'] ) ) {
				++$req;
				if ( $complete ) {
					++$done_req;
				}
			}
		}
		$pct = $req > 0 ? (int) round( ( $done_req / $req ) * 100 ) : 100;

		$groups = array(
			'core'     => __( 'Core setup', 'wedding-party-rsvp' ),
			'pro'      => __( 'Pro setup', 'wedding-party-rsvp' ),
			'optional' => __( 'Optional', 'wedding-party-rsvp' ),
		);

		?>
		<div class="wrap wgrsvp-setup-guide">
			<h1><?php esc_html_e( 'Wedding setup guide', 'wedding-party-rsvp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Work through these steps in order to get your wedding RSVP ready. Required items update automatically when possible; you can also mark a step done yourself.', 'wedding-party-rsvp' ); ?>
			</p>

			<div class="wgrsvp-setup-guide__progress">
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: completed, 2: total required */
							__( 'Required progress: %1$d / %2$d', 'wedding-party-rsvp' ),
							$done_req,
							$req
						)
					);
					?>
				</strong>
				<span class="description" style="margin-left:8px;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: completed including optional, 2: total steps */
							__( '(%1$d of %2$d steps including optional)', 'wedding-party-rsvp' ),
							$done_all,
							count( $steps )
						)
					);
					?>
				</span>
				<div class="wgrsvp-setup-guide__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>">
					<span style="width:<?php echo esc_attr( (string) $pct ); ?>%;"></span>
				</div>
			</div>

			<div class="wgrsvp-setup-guide__layout">
				<nav class="wgrsvp-setup-guide__nav" aria-label="<?php esc_attr_e( 'Setup steps', 'wedding-party-rsvp' ); ?>">
					<?php
					$by_group = array(
						'core'     => array(),
						'pro'      => array(),
						'optional' => array(),
					);
					foreach ( $steps as $step ) {
						$g = isset( $step['group'] ) ? (string) $step['group'] : 'core';
						if ( ! isset( $by_group[ $g ] ) ) {
							$by_group[ $g ] = array();
						}
						$by_group[ $g ][] = $step;
					}
					$index = 0;
					foreach ( $by_group as $group_key => $group_steps ) {
						if ( empty( $group_steps ) ) {
							continue;
						}
						$heading = isset( $groups[ $group_key ] ) ? $groups[ $group_key ] : $groups['core'];
						echo '<h3>' . esc_html( $heading ) . '</h3><ol>';
						foreach ( $group_steps as $step ) {
							++$index;
							$complete = $this->is_step_complete( $step );
							$classes  = array();
							if ( $step['id'] === $current['id'] ) {
								$classes[] = 'is-active';
							}
							if ( $complete ) {
								$classes[] = 'is-done';
							}
							?>
							<li>
								<a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo esc_url( self::url( $step['id'] ) ); ?>">
									<span class="wgrsvp-setup-guide__mark" aria-hidden="true"><?php echo $complete ? '✓' : esc_html( (string) $index ); ?></span>
									<span>
										<?php echo esc_html( $step['title'] ); ?>
										<?php if ( 'pro' === $group_key ) : ?>
											<span class="wgrsvp-setup-guide__badge wgrsvp-setup-guide__badge--pro"><?php esc_html_e( 'Pro', 'wedding-party-rsvp' ); ?></span>
										<?php elseif ( ! empty( $step['optional'] ) ) : ?>
											<span class="wgrsvp-setup-guide__badge wgrsvp-setup-guide__badge--optional"><?php esc_html_e( 'Optional', 'wedding-party-rsvp' ); ?></span>
										<?php endif; ?>
									</span>
								</a>
							</li>
							<?php
						}
						echo '</ol>';
					}
					?>
				</nav>

				<section class="wgrsvp-setup-guide__panel">
					<?php $this->render_step_panel( $current, $steps ); ?>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the detail panel for one step.
	 *
	 * @param array $current Current step.
	 * @param array $steps   All steps.
	 * @return void
	 */
	private function render_step_panel( array $current, array $steps ) {
		$complete = $this->is_step_complete( $current );
		$manual   = $this->is_manual_done( $current['id'] );
		$auto     = $this->is_auto_complete( $current );

		echo '<h2 style="margin-top:0;">' . esc_html( $current['title'] ) . '</h2>';

		if ( ! empty( $current['summary'] ) ) {
			echo '<p class="description">' . esc_html( $current['summary'] ) . '</p>';
		}

		if ( $complete ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'This step looks complete.', 'wedding-party-rsvp' ) . '</p></div>';
		} elseif ( $auto ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Detected as complete from your current settings.', 'wedding-party-rsvp' ) . '</p></div>';
		}

		if ( ! empty( $current['body'] ) && is_array( $current['body'] ) ) {
			echo '<ul style="margin:1rem 0 0 1.2em;list-style:disc;">';
			foreach ( $current['body'] as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul>';
		}

		/**
		 * Extra markup inside a setup-guide step panel (Pro can inject help).
		 *
		 * @param array $current Step definition.
		 */
		do_action( 'wgrsvp_setup_guide_step_panel', $current );

		echo '<div class="wgrsvp-setup-guide__actions">';

		if ( ! empty( $current['action_url'] ) && ! empty( $current['action_label'] ) ) {
			printf(
				'<a class="button button-primary" href="%1$s">%2$s</a>',
				esc_url( (string) $current['action_url'] ),
				esc_html( (string) $current['action_label'] )
			);
		}

		if ( ! empty( $current['secondary_url'] ) && ! empty( $current['secondary_label'] ) ) {
			printf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( (string) $current['secondary_url'] ),
				esc_html( (string) $current['secondary_label'] )
			);
		}

		if ( $manual || ( $complete && ! $auto ) ) {
			$unmark = wp_nonce_url(
				add_query_arg(
					array(
						'page'                 => self::PAGE_SLUG,
						'step'                 => $current['id'],
						'wgrsvp_guide_unmark'  => $current['id'],
					),
					admin_url( 'admin.php' )
				),
				'wgrsvp_setup_guide_unmark'
			);
			printf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( $unmark ),
				esc_html__( 'Mark as not done', 'wedding-party-rsvp' )
			);
		} elseif ( ! $complete ) {
			$mark = wp_nonce_url(
				add_query_arg(
					array(
						'page'               => self::PAGE_SLUG,
						'step'               => $current['id'],
						'wgrsvp_guide_mark'  => $current['id'],
					),
					admin_url( 'admin.php' )
				),
				'wgrsvp_setup_guide_mark'
			);
			printf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( $mark ),
				esc_html__( 'Mark as done', 'wedding-party-rsvp' )
			);
		}

		$next = $this->get_next_step( $steps, $current['id'] );
		if ( $next ) {
			printf(
				'<a class="button button-secondary" href="%1$s">%2$s</a>',
				esc_url( self::url( $next['id'] ) ),
				esc_html(
					sprintf(
						/* translators: %s: next step title */
						__( 'Next: %s', 'wedding-party-rsvp' ),
						$next['title']
					)
				)
			);
		} else {
			printf(
				'<a class="button button-secondary" href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=wedding-rsvp-main' ) ),
				esc_html__( 'Go to guest list', 'wedding-party-rsvp' )
			);
		}

		echo '</div>';
	}

	/**
	 * Ordered, filtered step definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_steps() {
		$settings = get_option( 'wgrsvp_general_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$steps = array(
			array(
				'id'            => 'quick_start',
				'group'         => 'core',
				'priority'      => 10,
				'optional'      => false,
				'title'         => __( 'Quick start wizard', 'wedding-party-rsvp' ),
				'summary'       => __( 'Create a welcome title, publish an RSVP page with the shortcode, and add a sample guest (Party ID WIZARD-TEST) so you can try the form.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'Takes about a minute.', 'wedding-party-rsvp' ),
					__( 'You can skip it and configure everything from this checklist instead.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Open quick start', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wgrsvp-setup-wizard&step=1' ),
				'auto_complete' => static function () {
					return (bool) get_option( WGRSVP_Setup_Wizard::OPTION_DONE, '' );
				},
			),
			array(
				'id'            => 'event_details',
				'group'         => 'core',
				'priority'      => 20,
				'optional'      => false,
				'title'         => __( 'Event details & deadline', 'wedding-party-rsvp' ),
				'summary'       => __( 'Set the RSVP deadline and (recommended) event title, date/time, and location for calendar links and guest hub info.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'Deadline stops new RSVPs after the date you choose.', 'wedding-party-rsvp' ),
					__( 'Event fields power Add to Calendar and guest hub details.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Open Settings → Logistics', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wedding-rsvp-settings#wgrsvp-logistics-heading' ),
				'auto_complete' => static function () use ( $settings ) {
					$deadline = isset( $settings['deadline_date'] ) ? trim( (string) $settings['deadline_date'] ) : '';
					$event    = isset( $settings['event_title'] ) ? trim( (string) $settings['event_title'] ) : '';
					return '' !== $deadline || '' !== $event;
				},
			),
			array(
				'id'            => 'rsvp_page',
				'group'         => 'core',
				'priority'      => 30,
				'optional'      => false,
				'title'         => __( 'RSVP page URL', 'wedding-party-rsvp' ),
				'summary'       => __( 'Point the plugin at the public page that contains the RSVP form so “copy RSVP link” and reminders use the right URL.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'The page should include the [wedding_rsvp_form] shortcode or RSVP block.', 'wedding-party-rsvp' ),
					__( 'Quick start can create this page for you.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Open Settings', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wedding-rsvp-settings' ),
				'auto_complete' => static function () use ( $settings ) {
					return is_string( $settings['rsvp_page_url'] ?? null ) && '' !== trim( (string) $settings['rsvp_page_url'] );
				},
			),
			array(
				'id'            => 'menus',
				'group'         => 'core',
				'priority'      => 40,
				'optional'      => false,
				'title'         => __( 'Menu / entrée options', 'wedding-party-rsvp' ),
				'summary'       => __( 'Add the adult entrée choices guests will pick on the RSVP form.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'At least one option is required for a useful form.', 'wedding-party-rsvp' ),
					__( 'Pro adds child meals and additional courses.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Open Menu Options', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wedding-rsvp-menu' ),
				'auto_complete' => static function () {
					$menus = get_option( 'wgrsvp_menu_options', array() );
					if ( ! is_array( $menus ) ) {
						return false;
					}
					foreach ( $menus as $m ) {
						if ( is_string( $m ) && '' !== trim( $m ) ) {
							return true;
						}
					}
					return false;
				},
			),
			array(
				'id'            => 'dietary',
				'group'         => 'core',
				'priority'      => 50,
				'optional'      => true,
				'title'         => __( 'Dietary & allergy wording', 'wedding-party-rsvp' ),
				'summary'       => __( 'Customize the dietary and allergies section labels and checkbox lists shown on the RSVP form.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'Defaults cover common dietary preferences and nut allergy.', 'wedding-party-rsvp' ),
					__( 'With Pro licensed, these fields live under Settings → Frontend text.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Edit dietary & allergies', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wedding-rsvp-settings' ),
				'auto_complete' => static function () use ( $settings ) {
					return ( isset( $settings['dietary_option_list'] ) && '' !== trim( (string) $settings['dietary_option_list'] ) )
						|| ( isset( $settings['allergy_option_list'] ) && '' !== trim( (string) $settings['allergy_option_list'] ) )
						|| ( isset( $settings['text_dietary_label'] ) && '' !== trim( (string) $settings['text_dietary_label'] ) );
				},
			),
			array(
				'id'            => 'gift_registries',
				'group'         => 'optional',
				'priority'      => 60,
				'optional'      => true,
				'title'         => __( 'Gift registry links', 'wedding-party-rsvp' ),
				'summary'       => __( 'Optional store links guests see on the RSVP page after they look up their party.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'Add labels + https URLs under Settings → Frontend Display.', 'wedding-party-rsvp' ),
					__( 'Pro adds a registry hub, wish list, and guided store wizard.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Add registry links', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wedding-rsvp-settings' ),
				'auto_complete' => static function () use ( $settings ) {
					if ( empty( $settings['gift_registries'] ) || ! is_array( $settings['gift_registries'] ) ) {
						return false;
					}
					foreach ( $settings['gift_registries'] as $row ) {
						if ( is_array( $row ) && ! empty( $row['url'] ) ) {
							return true;
						}
					}
					return false;
				},
			),
			array(
				'id'            => 'import_guests',
				'group'         => 'core',
				'priority'      => 70,
				'optional'      => false,
				'title'         => __( 'Import or add guests', 'wedding-party-rsvp' ),
				'summary'       => __( 'Load households so each invitation code (Party ID) unlocks the right guests on the public form.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'Paste Guest List is fastest for messy spreadsheets.', 'wedding-party-rsvp' ),
					__( 'CSV import and Add Guest are also available from the guest list.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Paste Guest List', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wedding-rsvp-paste-guests' ),
				'secondary_label' => __( 'Open guest list', 'wedding-party-rsvp' ),
				'secondary_url'   => admin_url( 'admin.php?page=wedding-rsvp-main' ),
				'auto_complete' => array( $this, 'has_real_guests' ),
			),
			array(
				'id'            => 'test_rsvp',
				'group'         => 'core',
				'priority'      => 80,
				'optional'      => false,
				'title'         => __( 'Test the public RSVP form', 'wedding-party-rsvp' ),
				'summary'       => __( 'Open the public page and submit a test reply so you know the form, menus, and dietary fields look right.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'If you used quick start, try Party ID WIZARD-TEST.', 'wedding-party-rsvp' ),
					__( 'Mark this done after you have checked the form on a phone and desktop.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'View RSVP page', 'wedding-party-rsvp' ),
				'action_url'    => $this->get_public_rsvp_url( $settings ),
				'secondary_label' => __( 'Edit RSVP page', 'wedding-party-rsvp' ),
				'secondary_url'   => $this->get_rsvp_page_edit_url(),
				'auto_complete' => null, // Manual only.
			),
			array(
				'id'            => 'order_prints',
				'group'         => 'optional',
				'priority'      => 85,
				'optional'      => true,
				'title'         => __( 'Order prints (partners)', 'wedding-party-rsvp' ),
				'summary'       => __( 'After seating and place-card exports, open Printful, Canva, or Gelato to order stationery. Partner links (including affiliate tracking) are managed from the weddingrsvp.pro hub.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'Export Place cards (CSV/PDF) from the guest list first.', 'wedding-party-rsvp' ),
					__( 'You can hide partner links under Settings → Frontend Display.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Open guest list (Order prints)', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wedding-rsvp-main' ),
				'auto_complete' => null,
			),
			array(
				'id'            => 'reminders',
				'group'         => 'optional',
				'priority'      => 90,
				'optional'      => true,
				'title'         => __( 'Deadline reminders', 'wedding-party-rsvp' ),
				'summary'       => __( 'Optional automatic reminder emails for guests who have not replied before the deadline.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'Uses your site’s email / SMTP.', 'wedding-party-rsvp' ),
					__( 'Pro can also send SMS and richer drip journeys.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Configure reminders', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'admin.php?page=wedding-rsvp-settings#wgrsvp-logistics-heading' ),
				'auto_complete' => static function () use ( $settings ) {
					return ! empty( $settings['deadline_nudges_enabled'] );
				},
			),
			array(
				'id'            => 'coordinator',
				'group'         => 'optional',
				'priority'      => 100,
				'optional'      => true,
				'title'         => __( 'Wedding Coordinator role', 'wedding-party-rsvp' ),
				'summary'       => __( 'Optional WordPress role for planners who should manage guests without full site settings access.', 'wedding-party-rsvp' ),
				'body'          => array(
					__( 'Assign the Wedding Coordinator role to a user under Users → Edit.', 'wedding-party-rsvp' ),
				),
				'action_label'  => __( 'Open Users', 'wedding-party-rsvp' ),
				'action_url'    => admin_url( 'users.php' ),
				'auto_complete' => static function () {
					if ( ! function_exists( 'get_users' ) ) {
						return false;
					}
					$users = get_users(
						array(
							'role'   => 'wedding_coordinator',
							'number' => 1,
							'fields' => 'ID',
						)
					);
					return ! empty( $users );
				},
			),
		);

		/**
		 * Filter setup guide steps (Free + Pro).
		 *
		 * Each step: id, title, summary, body (string[]), action_label, action_url,
		 * optional secondary_*, group (core|pro|optional), priority (int), optional (bool),
		 * auto_complete (callable|null).
		 *
		 * @param array $steps Step definitions.
		 */
		$steps = apply_filters( 'wgrsvp_setup_guide_steps', $steps );

		if ( ! is_array( $steps ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || empty( $step['id'] ) || empty( $step['title'] ) ) {
				continue;
			}
			$step['id']       = sanitize_key( (string) $step['id'] );
			$step['priority'] = isset( $step['priority'] ) ? (int) $step['priority'] : 50;
			$step['group']    = isset( $step['group'] ) ? sanitize_key( (string) $step['group'] ) : 'core';
			if ( ! in_array( $step['group'], array( 'core', 'pro', 'optional' ), true ) ) {
				$step['group'] = 'core';
			}
			$step['optional'] = ! empty( $step['optional'] ) || 'optional' === $step['group'];
			$normalized[]     = $step;
		}

		usort(
			$normalized,
			static function ( $a, $b ) {
				$pa = (int) $a['priority'];
				$pb = (int) $b['priority'];
				if ( $pa === $pb ) {
					return strcmp( (string) $a['id'], (string) $b['id'] );
				}
				return $pa <=> $pb;
			}
		);

		return $normalized;
	}

	/**
	 * Whether any non-wizard guest exists.
	 *
	 * @return bool
	 */
	public function has_real_guests() {
		global $wpdb;
		$table = $wpdb->prefix . 'wedding_rsvps';
		$wp_version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0';
		if ( version_compare( $wp_version, '6.2', '>=' ) ) {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE party_id <> %s",
				$table,
				'WIZARD-TEST'
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$count = (int) $wpdb->get_var( $sql );
		} else {
			$table_safe = '`' . str_replace( '`', '``', $table ) . '`';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $table_safe . ' WHERE party_id <> %s',
					'WIZARD-TEST'
				)
			);
		}
		return $count > 0;
	}

	/**
	 * Public RSVP URL from settings or wizard page.
	 *
	 * @param array $settings Settings bag.
	 * @return string
	 */
	private function get_public_rsvp_url( array $settings ) {
		if ( ! empty( $settings['rsvp_page_url'] ) ) {
			return esc_url_raw( (string) $settings['rsvp_page_url'] );
		}
		$page_id = absint( get_option( WGRSVP_Setup_Wizard::OPTION_WIZARD_PAGE, 0 ) );
		if ( $page_id > 0 ) {
			$link = get_permalink( $page_id );
			if ( $link ) {
				return $link;
			}
		}
		return admin_url( 'admin.php?page=wedding-rsvp-settings' );
	}

	/**
	 * Edit link for wizard-created RSVP page when available.
	 *
	 * @return string
	 */
	private function get_rsvp_page_edit_url() {
		$page_id = absint( get_option( WGRSVP_Setup_Wizard::OPTION_WIZARD_PAGE, 0 ) );
		if ( $page_id > 0 && get_post_status( $page_id ) ) {
			$edit = get_edit_post_link( $page_id, 'raw' );
			if ( $edit ) {
				return $edit;
			}
		}
		return admin_url( 'edit.php?post_type=page' );
	}

	/**
	 * First incomplete step (required first, then optional).
	 *
	 * @param array $steps Steps.
	 * @return array|null
	 */
	private function get_first_incomplete_step( array $steps ) {
		foreach ( $steps as $step ) {
			if ( empty( $step['optional'] ) && ! $this->is_step_complete( $step ) ) {
				return $step;
			}
		}
		foreach ( $steps as $step ) {
			if ( ! $this->is_step_complete( $step ) ) {
				return $step;
			}
		}
		return null;
	}

	/**
	 * Next step after the given id.
	 *
	 * @param array  $steps   Steps.
	 * @param string $step_id Current id.
	 * @return array|null
	 */
	private function get_next_step( array $steps, $step_id ) {
		$found = false;
		foreach ( $steps as $step ) {
			if ( $found ) {
				return $step;
			}
			if ( $step['id'] === $step_id ) {
				$found = true;
			}
		}
		return null;
	}

	/**
	 * Auto or manual completion.
	 *
	 * @param array $step Step.
	 * @return bool
	 */
	private function is_step_complete( array $step ) {
		if ( $this->is_manual_done( $step['id'] ) ) {
			return true;
		}
		return $this->is_auto_complete( $step );
	}

	/**
	 * Run auto_complete callback when present.
	 *
	 * @param array $step Step.
	 * @return bool
	 */
	private function is_auto_complete( array $step ) {
		if ( empty( $step['auto_complete'] ) || ! is_callable( $step['auto_complete'] ) ) {
			return false;
		}
		return (bool) call_user_func( $step['auto_complete'] );
	}

	/**
	 * Manual completion map.
	 *
	 * @return array<string, int>
	 */
	private function get_manual_map() {
		$map = get_option( self::OPT_MANUAL_DONE, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * @param string $step_id Step id.
	 * @return bool
	 */
	private function is_manual_done( $step_id ) {
		$map = $this->get_manual_map();
		return ! empty( $map[ $step_id ] );
	}

	/**
	 * @param string $step_id Step id.
	 * @param bool   $done    Done flag.
	 * @return void
	 */
	private function set_manual_done( $step_id, $done ) {
		$step_id = sanitize_key( $step_id );
		if ( '' === $step_id ) {
			return;
		}
		$map = $this->get_manual_map();
		if ( $done ) {
			$map[ $step_id ] = 1;
		} else {
			unset( $map[ $step_id ] );
		}
		update_option( self::OPT_MANUAL_DONE, $map, false );
	}
}
