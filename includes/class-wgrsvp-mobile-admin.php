<?php
/**
 * Mobile App admin screen: connect details couples need for the companion app.
 *
 * Read-only reference page. It shows the site URL, the `/app/connect` URL and
 * the hub join URL, plus the steps a couple follows to pair the companion app
 * with this wedding site.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mobile App reference screen under the Wedding RSVP menu.
 */
class WGRSVP_Mobile_Admin {

	/**
	 * Parent menu slug registered by the main plugin class.
	 */
	const PARENT_SLUG = 'wedding-rsvp-main';

	/**
	 * This screen's menu slug.
	 */
	const MENU_SLUG = 'wedding-rsvp-mobile-app';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Mobile App', 'wedding-party-rsvp' ),
			__( 'Mobile App', 'wedding-party-rsvp' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'wedding-party-rsvp' ),
				'',
				array( 'response' => 403 )
			);
		}

		$site_url    = untrailingslashit( home_url() );
		$config      = WGRSVP_App_Config::get_config();
		$connect_url = isset( $config['connectUrl'] ) ? (string) $config['connectUrl'] : '';
		$join_url    = isset( $config['joinUrl'] ) ? (string) $config['joinUrl'] : '';
		$config_url  = rest_url( WGRSVP_App_Config::REST_NAMESPACE . '/app-config' );
		$pro_hub     = class_exists( 'WPR_Pro_Event_Network' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mobile App', 'wedding-party-rsvp' ); ?></h1>

			<p>
				<?php esc_html_e( 'The Wedding RSVP companion app reads and updates this site over the REST API. Share the connect link below with the couple or coordinator and they can manage the guest list from their phone.', 'wedding-party-rsvp' ); ?>
			</p>

			<h2><?php esc_html_e( 'Connection details', 'wedding-party-rsvp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="wgrsvp-mobile-site-url"><?php esc_html_e( 'Site URL', 'wedding-party-rsvp' ); ?></label>
						</th>
						<td>
							<input id="wgrsvp-mobile-site-url" type="text" class="large-text code" readonly value="<?php echo esc_attr( $site_url ); ?>" />
							<p class="description"><?php esc_html_e( 'Paste this into the app when it asks which wedding website to open.', 'wedding-party-rsvp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wgrsvp-mobile-connect-url"><?php esc_html_e( 'Connect URL', 'wedding-party-rsvp' ); ?></label>
						</th>
						<td>
							<input id="wgrsvp-mobile-connect-url" type="text" class="large-text code" readonly value="<?php echo esc_attr( $connect_url ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Open this page in a phone browser, sign in with a WordPress account that can manage the guest list, and the site hands the app a one-time connect code.', 'wedding-party-rsvp' ); ?>
								<?php if ( '' !== $connect_url ) : ?>
									<a href="<?php echo esc_url( $connect_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open connect page', 'wedding-party-rsvp' ); ?></a>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wgrsvp-mobile-join-url"><?php esc_html_e( 'Join URL', 'wedding-party-rsvp' ); ?></label>
						</th>
						<td>
							<input id="wgrsvp-mobile-join-url" type="text" class="large-text code" readonly value="<?php echo esc_attr( $join_url ); ?>" />
							<p class="description"><?php esc_html_e( 'Share this link to add the wedding to the app without typing the site URL.', 'wedding-party-rsvp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wgrsvp-mobile-config-url"><?php esc_html_e( 'App config endpoint', 'wedding-party-rsvp' ); ?></label>
						</th>
						<td>
							<input id="wgrsvp-mobile-config-url" type="text" class="large-text code" readonly value="<?php echo esc_attr( $config_url ); ?>" />
							<p class="description"><?php esc_html_e( 'Public read-only endpoint the app uses to confirm this site is reachable. Open it in a browser to verify the REST API is working.', 'wedding-party-rsvp' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'How the couple connects', 'wedding-party-rsvp' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Install the Wedding RSVP companion app on the phone.', 'wedding-party-rsvp' ); ?></li>
				<li><?php esc_html_e( 'Make sure the person connecting has a WordPress account on this site with the administrator role or the wedding coordinator role.', 'wedding-party-rsvp' ); ?></li>
				<li><?php esc_html_e( 'Open the connect URL above on the phone and sign in with that account.', 'wedding-party-rsvp' ); ?></li>
				<li><?php esc_html_e( 'Tap "Open Wedding RSVP app", or copy the connect code shown and paste it into the app. Codes expire after five minutes.', 'wedding-party-rsvp' ); ?></li>
				<li><?php esc_html_e( 'Guests do not need an account. They sign in to the app with the Party ID from their invitation.', 'wedding-party-rsvp' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'App Network (short join codes)', 'wedding-party-rsvp' ); ?></h2>
			<?php
			$pro_owns = class_exists( 'WGRSVP_Event_Network', false ) && WGRSVP_Event_Network::pro_owns_connect();
			if ( $pro_owns ) :
				?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'Wedding Party RSVP Pro is active. Use Wedding RSVP → Mobile App (Pro) → Connect to Wedding RSVP App Network so the hub records this site as Pro.', 'wedding-party-rsvp' ); ?>
					</p>
					<p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wpr-pro-mobile-app' ) ); ?>">
							<?php esc_html_e( 'Open Pro Mobile App', 'wedding-party-rsvp' ); ?>
						</a>
					</p>
				</div>
			<?php else :
				$status     = class_exists( 'WGRSVP_Event_Network', false ) ? WGRSVP_Event_Network::get_local_status() : array();
				$state      = is_array( $status ) && isset( $status['state'] ) ? (string) $status['state'] : 'disconnected';
				$msg        = is_array( $status ) && isset( $status['message'] ) ? (string) $status['message'] : '';
				$party_name = (string) get_option( 'wgrsvp_wedding_party_name', '' );
				$partner_1  = (string) get_option( 'wgrsvp_wedding_partner_1', '' );
				$partner_2  = (string) get_option( 'wgrsvp_wedding_partner_2', '' );
				$city_saved = (string) get_option( 'wgrsvp_wedding_city', '' );
				$state_saved = (string) get_option( 'wgrsvp_wedding_state', '' );
				$zip_saved  = (string) get_option( 'wgrsvp_wedding_zip', '' );
				?>
				<p>
					<?php esc_html_e( 'Connect this free plugin site to weddingrsvp.pro so the companion can find it with a short join code. The hub stores directory metadata only (no guest list) and labels the listing as Free.', 'wedding-party-rsvp' ); ?>
				</p>
				<p><strong><?php echo esc_html( ucfirst( $state ) ); ?></strong>
					<?php if ( $msg ) : ?>
						— <?php echo esc_html( $msg ); ?>
					<?php endif; ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wgrsvp_network_connect" />
					<?php wp_nonce_field( 'wgrsvp_network_connect', 'wgrsvp_network_connect_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wgrsvp_wedding_party_name"><?php esc_html_e( 'Wedding / party name', 'wedding-party-rsvp' ); ?></label></th>
							<td>
								<input type="text" id="wgrsvp_wedding_party_name" name="wgrsvp_wedding_party_name" class="regular-text" value="<?php echo esc_attr( $party_name ); ?>" required placeholder="<?php echo esc_attr__( 'Taylor & Riley', 'wedding-party-rsvp' ); ?>" />
								<p class="description"><?php esc_html_e( 'Shown in the app directory so guests can tell weddings apart (required).', 'wedding-party-rsvp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wgrsvp_wedding_partner_1"><?php esc_html_e( 'Partner 1', 'wedding-party-rsvp' ); ?></label></th>
							<td><input type="text" id="wgrsvp_wedding_partner_1" name="wgrsvp_wedding_partner_1" class="regular-text" value="<?php echo esc_attr( $partner_1 ); ?>" placeholder="<?php echo esc_attr__( 'Optional', 'wedding-party-rsvp' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wgrsvp_wedding_partner_2"><?php esc_html_e( 'Partner 2', 'wedding-party-rsvp' ); ?></label></th>
							<td><input type="text" id="wgrsvp_wedding_partner_2" name="wgrsvp_wedding_partner_2" class="regular-text" value="<?php echo esc_attr( $partner_2 ); ?>" placeholder="<?php echo esc_attr__( 'Optional', 'wedding-party-rsvp' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'City', 'wedding-party-rsvp' ); ?></th>
							<td><input type="text" name="wgrsvp_wedding_city" class="regular-text" value="<?php echo esc_attr( $city_saved ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'State', 'wedding-party-rsvp' ); ?></th>
							<td><input type="text" name="wgrsvp_wedding_state" class="regular-text" value="<?php echo esc_attr( $state_saved ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'ZIP', 'wedding-party-rsvp' ); ?></th>
							<td><input type="text" name="wgrsvp_wedding_zip" class="regular-text" value="<?php echo esc_attr( $zip_saved ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hub token (if required)', 'wedding-party-rsvp' ); ?></th>
							<td><input type="text" name="wgrsvp_hub_token" class="regular-text" autocomplete="off" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Connect to Wedding RSVP App Network', 'wedding-party-rsvp' ), 'primary' ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Requirements', 'wedding-party-rsvp' ); ?></h2>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'The site must be served over HTTPS so WordPress can issue Application Passwords.', 'wedding-party-rsvp' ); ?></li>
				<li><?php esc_html_e( 'The REST API must be reachable, and the host must pass the Authorization header through to WordPress.', 'wedding-party-rsvp' ); ?></li>
			</ul>

			<?php if ( $pro_hub ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'Wedding Party RSVP Pro is active on this site, so a separate Pro Mobile App screen may also appear with push notifications, reminders and hub settings. The app prefers the Pro API when it is licensed; this page stays available as a reference.', 'wedding-party-rsvp' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
