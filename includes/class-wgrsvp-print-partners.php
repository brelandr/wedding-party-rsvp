<?php
/**
 * Order-prints partner links (affiliate / referral) for coordinators.
 *
 * Links open Printful, Canva, or Gelato in a new tab. Affiliate URLs are owned
 * by Land Tech (plugin author) and can be overridden from the weddingrsvp.pro
 * hub (Pro) or via filters. Site owners can hide the panel in Settings.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print partner definitions, URL resolution, and admin UI.
 */
class WGRSVP_Print_Partners {

	/**
	 * Default product landing pages (replaced by hub affiliate URLs when set).
	 */
	public const DEFAULT_PRINTFUL = 'https://www.printful.com/custom/cards';
	public const DEFAULT_CANVA    = 'https://www.canva.com/create/wedding-invitations/';
	public const DEFAULT_GELATO   = 'https://www.gelato.com/';

	/**
	 * Bootstrap.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wgrsvp_guest_list_after_title', array( __CLASS__, 'render_guest_list_panel' ), 12, 2 );
	}

	/**
	 * Whether the Order prints panel should render.
	 *
	 * @param array|null $settings General settings.
	 * @return bool
	 */
	public static function is_enabled( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'wgrsvp_general_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
		}
		// Default on; site owner may hide via Settings.
		if ( isset( $settings['show_print_partners'] ) && ! (int) $settings['show_print_partners'] ) {
			return false;
		}
		/**
		 * Filter whether Order prints partners are shown in admin.
		 *
		 * @param bool  $enabled  Whether enabled.
		 * @param array $settings Settings bag.
		 */
		return (bool) apply_filters( 'wgrsvp_print_partners_enabled', true, $settings );
	}

	/**
	 * Partner catalog with resolved URLs.
	 *
	 * @return array<int, array{id:string,label:string,description:string,url:string,use_for:string}>
	 */
	public static function get_partners() {
		$defaults = array(
			'printful' => self::DEFAULT_PRINTFUL,
			'canva'    => self::DEFAULT_CANVA,
			'gelato'   => self::DEFAULT_GELATO,
		);

		/**
		 * Filter base / affiliate URLs for print partners (hub overrides these).
		 *
		 * @param array<string, string> $urls Map of partner id => URL.
		 */
		$urls = apply_filters( 'wgrsvp_print_partner_urls', $defaults );
		if ( ! is_array( $urls ) ) {
			$urls = $defaults;
		}

		$partners = array(
			array(
				'id'          => 'printful',
				'label'       => __( 'Printful', 'wedding-party-rsvp' ),
				'description' => __( 'Print-on-demand place cards, posters, and signage — upload your CSV or design.', 'wedding-party-rsvp' ),
				'use_for'     => __( 'Place cards, seating chart posters, table signs', 'wedding-party-rsvp' ),
				'url'         => self::sanitize_partner_url( isset( $urls['printful'] ) ? (string) $urls['printful'] : $defaults['printful'] ),
			),
			array(
				'id'          => 'canva',
				'label'       => __( 'Canva', 'wedding-party-rsvp' ),
				'description' => __( 'Design place cards and menus yourself, then print at home or with Canva Print.', 'wedding-party-rsvp' ),
				'use_for'     => __( 'DIY place cards, menus, thank-you cards', 'wedding-party-rsvp' ),
				'url'         => self::sanitize_partner_url( isset( $urls['canva'] ) ? (string) $urls['canva'] : $defaults['canva'] ),
			),
			array(
				'id'          => 'gelato',
				'label'       => __( 'Gelato', 'wedding-party-rsvp' ),
				'description' => __( 'Global print-on-demand for cards and posters with local production hubs.', 'wedding-party-rsvp' ),
				'use_for'     => __( 'Place cards, posters, prints', 'wedding-party-rsvp' ),
				'url'         => self::sanitize_partner_url( isset( $urls['gelato'] ) ? (string) $urls['gelato'] : $defaults['gelato'] ),
			),
		);

		/**
		 * Filter the full partner list (add/remove partners).
		 *
		 * @param array $partners Partner rows.
		 */
		$partners = apply_filters( 'wgrsvp_print_partners', $partners );

		$out = array();
		if ( ! is_array( $partners ) ) {
			return $out;
		}
		foreach ( $partners as $row ) {
			if ( ! is_array( $row ) || empty( $row['label'] ) || empty( $row['url'] ) ) {
				continue;
			}
			$row['url'] = self::sanitize_partner_url( (string) $row['url'] );
			if ( '' === $row['url'] ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function sanitize_partner_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Guest list panel (after exports / checklist).
	 *
	 * @param bool   $can_manage_rsvp Capability flag.
	 * @param object $plugin          Main plugin.
	 * @return void
	 */
	public static function render_guest_list_panel( $can_manage_rsvp, $plugin ) {
		unset( $plugin );
		if ( ! $can_manage_rsvp || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::render_panel();
	}

	/**
	 * Render the Order prints card.
	 *
	 * @return void
	 */
	public static function render_panel() {
		if ( ! self::is_enabled() ) {
			return;
		}
		$partners = self::get_partners();
		if ( empty( $partners ) ) {
			return;
		}
		?>
		<div class="notice notice-info wgrsvp-print-partners" style="padding:12px 14px;">
			<p style="margin:0 0 8px;">
				<strong><?php esc_html_e( 'Order prints', 'wedding-party-rsvp' ); ?></strong>
				—
				<?php esc_html_e( 'Export place cards above, then open a print partner to order stationery. These are optional third-party services.', 'wedding-party-rsvp' ); ?>
			</p>
			<ul style="margin:0 0 10px 1.2em;list-style:disc;">
				<?php foreach ( $partners as $p ) : ?>
					<li style="margin:4px 0;">
						<a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener noreferrer sponsored">
							<?php echo esc_html( (string) $p['label'] ); ?>
						</a>
						<?php if ( ! empty( $p['use_for'] ) ) : ?>
							<span class="description"> — <?php echo esc_html( (string) $p['use_for'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description" style="margin:0;">
				<?php esc_html_e( 'Affiliate disclosure: some partner links may earn a commission for Land Tech Web Designs (Wedding Party RSVP) at no extra cost to you. Hide this panel anytime under Settings → Frontend Display.', 'wedding-party-rsvp' ); ?>
				<?php if ( class_exists( 'WGRSVP_Setup_Guide', false ) ) : ?>
					<a href="<?php echo esc_url( WGRSVP_Setup_Guide::url( 'order_prints' ) ); ?>"><?php esc_html_e( 'Setup guide step', 'wedding-party-rsvp' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Settings fields HTML (Frontend Display).
	 *
	 * @param array $s Settings.
	 * @return void
	 */
	public static function render_settings_fields( array $s ) {
		$show = ! isset( $s['show_print_partners'] ) || (int) $s['show_print_partners'];
		?>
		<hr style="margin:1.25em 0;border:0;border-top:1px solid #ddd;">
		<p><strong><?php esc_html_e( 'Order prints (partners)', 'wedding-party-rsvp' ); ?></strong></p>
		<p class="description"><?php esc_html_e( 'Shows Printful, Canva, and Gelato links on the guest list so coordinators can order place cards and seating charts after exporting CSV/PDF. Partner URLs (including affiliate tracking) are configured by Land Tech on the weddingrsvp.pro hub when available.', 'wedding-party-rsvp' ); ?></p>
		<p>
			<label>
				<input type="checkbox" name="show_print_partners" value="1" <?php checked( $show ); ?>>
				<?php esc_html_e( 'Show “Order prints” partner links in Wedding RSVP admin', 'wedding-party-rsvp' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Affiliate disclosure is shown with the links. Uncheck to hide the panel on this site.', 'wedding-party-rsvp' ); ?></p>
		<?php
	}
}
