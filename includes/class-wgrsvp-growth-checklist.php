<?php
/**
 * In-dashboard getting started checklist and “next steps” for pending RSVPs.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Growth_Checklist' ) ) {

	/**
	 * Growth / onboarding panels on the Wedding Dashboard (guest list).
	 */
	class WGRSVP_Growth_Checklist {

		public const OPT_PANEL_DISMISSED = 'wgrsvp_getting_started_panel_dismissed';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wgrsvp_guest_list_after_title', array( __CLASS__, 'render_panels' ), 8, 2 );
		}

		/**
		 * Render checklist + next-steps notices for administrators; light coordinator pending hint.
		 *
		 * @param bool   $can_manage_rsvp Whether current user may change guests/settings.
		 * @param object $plugin          Main plugin instance (wedding-party-rsvp main class).
		 * @return void
		 */
		public static function render_panels( $can_manage_rsvp, $plugin ) {
			if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_aggregated_rsvp_stats' ) ) {
				return;
			}

			$stats         = $plugin->get_aggregated_rsvp_stats();
			$total_pending = isset( $stats['total_pending'] ) ? (int) $stats['total_pending'] : 0;
			$total_guests  = isset( $stats['total_guests'] ) ? (int) $stats['total_guests'] : 0;

			if ( ! $can_manage_rsvp ) {
				if ( $total_pending > 0 ) {
					echo '<div class="notice notice-warning"><p>';
					echo esc_html(
						sprintf(
							/* translators: %d: number of guests with pending RSVP */
							_n(
								'%d guest still has a pending RSVP — filter the list to follow up.',
								'%d guests still have a pending RSVP — filter the list to follow up.',
								$total_pending,
								'wedding-party-rsvp'
							),
							$total_pending
						)
					);
					echo ' <a href="' . esc_url( admin_url( 'admin.php?page=wedding-rsvp-main&filter_status=Pending' ) ) . '">' . esc_html__( 'Show pending only', 'wedding-party-rsvp' ) . '</a>';
					echo '</p></div>';
				}
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$settings = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			$rsvp_url_ok = is_string( $settings['rsvp_page_url'] ?? '' ) && '' !== trim( $settings['rsvp_page_url'] );

			$menus  = get_option( 'wgrsvp_menu_options', array() );
			$has_me = false;
			if ( is_array( $menus ) ) {
				foreach ( $menus as $m ) {
					if ( is_string( $m ) && '' !== trim( $m ) ) {
						$has_me = true;
						break;
					}
				}
			}

			$guests_ok = $total_guests > 0;

			if ( ! get_option( self::OPT_PANEL_DISMISSED, false ) ) {
				$wizard_url = admin_url( 'admin.php?page=wgrsvp-setup-wizard&step=1' );
				$dismiss    = wp_nonce_url(
					add_query_arg( 'wgrsvp_dismiss_notice', 'getting_started_panel' ),
					'wgrsvp_dismiss_growth_notice'
				);
				?>
				<div class="notice notice-info" style="padding:12px 14px;">
					<p style="margin:0 0 10px;"><strong><?php esc_html_e( 'Getting started', 'wedding-party-rsvp' ); ?></strong>
						— <?php esc_html_e( 'Pick the path that fits you, then tick items off (or run the quick setup wizard).', 'wedding-party-rsvp' ); ?></p>
					<div class="wgrsvp-onboarding-personas" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin:0 0 14px;">
						<div style="background:rgba(255,255,255,0.65);border:1px solid #c3c4c7;border-radius:6px;padding:10px 12px;">
							<strong><?php esc_html_e( 'Couple or family (DIY)', 'wedding-party-rsvp' ); ?></strong>
							<ul style="margin:8px 0 0 1.1em;padding:0;list-style:disc;font-size:13px;line-height:1.45;">
								<li><?php esc_html_e( 'Create your RSVP page and add the shortcode — nothing you do here affects the rest of your site until you publish.', 'wedding-party-rsvp' ); ?></li>
								<li><?php esc_html_e( 'Share each household’s invitation code (same as Party ID in CSV templates).', 'wedding-party-rsvp' ); ?></li>
								<li><?php esc_html_e( 'Without Pro, copy the RSVP link from the guest list instead of batch email.', 'wedding-party-rsvp' ); ?></li>
							</ul>
						</div>
						<div style="background:rgba(255,255,255,0.65);border:1px solid #c3c4c7;border-radius:6px;padding:10px 12px;">
							<strong><?php esc_html_e( 'Professional planner', 'wedding-party-rsvp' ); ?></strong>
							<ul style="margin:8px 0 0 1.1em;padding:0;list-style:disc;font-size:13px;line-height:1.45;">
								<li><?php esc_html_e( 'Import CSV or paste-import, then use Guest list health tiles for mixed households and meal gaps.', 'wedding-party-rsvp' ); ?></li>
								<li><?php esc_html_e( 'Give coordinators the Wedding Coordinator role — they see lists without dangerous settings.', 'wedding-party-rsvp' ); ?></li>
								<li><?php esc_html_e( 'Vendor & venue packet (submenu) is your one-page handoff; audit log tracks who changed a guest row.', 'wedding-party-rsvp' ); ?></li>
							</ul>
						</div>
					</div>
					<ol style="margin:0 0 12px 1.2em; list-style:decimal;">
						<li style="margin:4px 0;">
							<?php echo esc_html( $rsvp_url_ok ? '✓ ' : '' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-settings' ) ); ?>"><?php esc_html_e( 'Set your RSVP page URL', 'wedding-party-rsvp' ); ?></a>
							<?php if ( ! $rsvp_url_ok ) : ?>
								<span class="description"><?php esc_html_e( '(used for “copy RSVP link” in the guest list)', 'wedding-party-rsvp' ); ?></span>
							<?php endif; ?>
						</li>
						<li style="margin:4px 0;">
							<?php echo esc_html( $has_me ? '✓ ' : '' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-menu' ) ); ?>"><?php esc_html_e( 'Add at least one adult entrée', 'wedding-party-rsvp' ); ?></a>
							<?php if ( ! $has_me ) : ?>
								<span class="description"><?php esc_html_e( '(Menu Options)', 'wedding-party-rsvp' ); ?></span>
							<?php endif; ?>
						</li>
						<li style="margin:4px 0;">
							<?php echo esc_html( $guests_ok ? '✓ ' : '' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-paste-guests' ) ); ?>"><?php esc_html_e( 'Import or add guests', 'wedding-party-rsvp' ); ?></a>
							<?php if ( ! $guests_ok ) : ?>
								<span class="description"><?php esc_html_e( '(CSV, paste, or “Add Guest” above)', 'wedding-party-rsvp' ); ?></span>
							<?php endif; ?>
						</li>
					</ol>
					<p style="margin:0;">
						<a class="button button-secondary" href="<?php echo esc_url( $wizard_url ); ?>"><?php esc_html_e( 'Open setup wizard', 'wedding-party-rsvp' ); ?></a>
						<a class="button" href="<?php echo esc_url( 'https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/brelandr/wedding-party-rsvp/main/blueprint.json' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Try live demo (Playground)', 'wedding-party-rsvp' ); ?></a>
						<?php if ( function_exists( 'wgrsvp_is_pro_plugin_active' ) && ! wgrsvp_is_pro_plugin_active() && function_exists( 'wgrsvp_get_pro_live_demo_url' ) ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( wgrsvp_get_pro_live_demo_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Try Premium', 'wedding-party-rsvp' ); ?></a>
						<?php endif; ?>
						<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss this checklist', 'wedding-party-rsvp' ); ?></a>
					</p>
				</div>
				<?php
			}

			if ( $total_pending > 0 && ! get_user_meta( get_current_user_id(), 'wgrsvp_next_steps_notice_dismissed', true ) ) {
				$pending_url = admin_url( 'admin.php?page=wedding-rsvp-main&filter_status=Pending' );
				$nudge_url   = admin_url( 'admin.php?page=wedding-rsvp-settings#wgrsvp-logistics-heading' );
				$gifts_url   = admin_url( 'admin.php?page=wedding-rsvp-gifts-report' );
				$dismiss_ns  = wp_nonce_url(
					add_query_arg( 'wgrsvp_dismiss_notice', 'next_steps' ),
					'wgrsvp_dismiss_growth_notice'
				);

				$comm_url = admin_url( 'admin.php?page=wedding-rsvp-comm' );
				?>
				<div class="notice notice-warning" style="padding:12px 14px;">
					<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Next steps: pending RSVPs', 'wedding-party-rsvp' ); ?></strong></p>
					<p style="margin:0 0 8px;">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: pending guest count */
								_n(
									'You have %d guest who has not replied yet.',
									'You have %d guests who have not replied yet.',
									$total_pending,
									'wedding-party-rsvp'
								),
								$total_pending
							)
						);
						?>
					</p>
					<p style="margin:0;">
						<a class="button button-small" href="<?php echo esc_url( $pending_url ); ?>"><?php esc_html_e( 'View pending list', 'wedding-party-rsvp' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( $nudge_url ); ?>"><?php esc_html_e( 'Deadline reminder emails', 'wedding-party-rsvp' ); ?></a>
						<?php if ( function_exists( 'wgrsvp_is_pro_plugin_active' ) && wgrsvp_is_pro_plugin_active() && function_exists( 'wgrsvp_is_pro_license_effectively_valid' ) && wgrsvp_is_pro_license_effectively_valid() ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $comm_url ); ?>"><?php esc_html_e( 'Email & SMS (Pro)', 'wedding-party-rsvp' ); ?></a>
						<?php else : ?>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wedding-rsvp-email' ) ); ?>"><?php esc_html_e( 'Email invites', 'wedding-party-rsvp' ); ?></a>
						<?php endif; ?>
						<?php if ( class_exists( 'WGRSVP_Gifts_Report', false ) ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $gifts_url ); ?>"><?php esc_html_e( 'Gifts & thank-you', 'wedding-party-rsvp' ); ?></a>
						<?php endif; ?>
						<a href="<?php echo esc_url( $dismiss_ns ); ?>"><?php esc_html_e( 'Dismiss', 'wedding-party-rsvp' ); ?></a>
					</p>
				</div>
				<?php
			}
		}
	}
}
