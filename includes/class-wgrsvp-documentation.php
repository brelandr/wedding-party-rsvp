<?php
/**
 * In-admin Help & Documentation for the free plugin.
 *
 * Covers free gift registries and documents Pro registry features with clear
 * “Available in Pro” notes so couples can learn the full gift workflow even
 * before upgrading. When Pro’s Documentation page is present, this page links
 * to it for the full licensed manuals.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Help & Documentation admin screen.
 */
class WGRSVP_Documentation {

	const PAGE_SLUG = 'wedding-rsvp-help';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// After Pro’s menu merge (priority 100) so Help survives remove_menu_page + rebuild.
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 110 );
	}

	/**
	 * Marketing URL for Pro (filterable).
	 *
	 * @return string
	 */
	public static function pro_url() {
		return (string) apply_filters(
			'wgrsvp_pro_marketing_url',
			'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/?utm_source=wp-plugin-free&utm_medium=admin&utm_campaign=help-docs'
		);
	}

	/**
	 * Whether Pro’s full Documentation screen is available on this site.
	 *
	 * @return bool
	 */
	public static function pro_docs_available() {
		return class_exists( 'WPR_Pro_Documentation', false );
	}

	/**
	 * Register Help under Wedding RSVP.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'wedding-rsvp-main',
			__( 'Help & Documentation', 'wedding-party-rsvp' ),
			__( 'Help', 'wedding-party-rsvp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the Help page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wedding-party-rsvp' ) );
		}

		$pro_url  = self::pro_url();
		$pro_docs = self::pro_docs_available()
			? admin_url( 'admin.php?page=wedding-rsvp-documentation' )
			: '';

		echo '<div class="wrap wgrsvp-help">';
		echo '<h1>' . esc_html__( 'Help & Documentation', 'wedding-party-rsvp' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Guides for gift registries and related tools. Plain-language explanations — jump with the contents list.', 'wedding-party-rsvp' ) . '</p>';

		if ( '' !== $pro_docs ) {
			echo '<div class="notice notice-info inline" style="margin:12px 0;max-width:920px;"><p>';
			echo esc_html__( 'Wedding Party RSVP Pro is active. For the full Pro manuals (seating, drip, Stripe, companion app, and more), open:', 'wedding-party-rsvp' );
			echo ' <a href="' . esc_url( $pro_docs ) . '"><strong>' . esc_html__( 'Wedding RSVP → Documentation', 'wedding-party-rsvp' ) . '</strong></a>';
			echo '</p></div>';
		}

		echo '<nav class="wgrsvp-help-toc" aria-label="' . esc_attr__( 'Help table of contents', 'wedding-party-rsvp' ) . '" style="max-width:920px;margin:16px 0;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Contents', 'wedding-party-rsvp' ) . '</h2>';
		echo '<ul style="margin:0;columns:2;column-gap:24px;">';
		echo '<li><a href="#wgrsvp-help-setup-guide">' . esc_html__( 'Wedding setup guide', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-order-prints">' . esc_html__( 'Order prints (partners)', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-gift-registries">' . esc_html__( 'Gift registry links (free)', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-amazon">' . esc_html__( 'Amazon Associates (free)', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-skimlinks">' . esc_html__( 'Skimlinks (free)', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-pro-wizard">' . esc_html__( 'Registry setup wizard (Pro)', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-pro-wishlist">' . esc_html__( 'Wish list / item registry (Pro)', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-pro-cash">' . esc_html__( 'Stripe cash fund (Pro)', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-pro-csv">' . esc_html__( 'Gift CSV import (Pro)', 'wedding-party-rsvp' ) . '</a></li>';
		echo '<li><a href="#wgrsvp-help-pro-more">' . esc_html__( 'More Pro gift tools', 'wedding-party-rsvp' ) . '</a></li>';
		echo '</ul></nav>';

		self::render_setup_guide_help();
		self::render_order_prints_help();
		self::render_free_gift_registries();
		self::render_amazon();
		self::render_skimlinks();
		self::render_pro_banner( $pro_url );
		self::render_pro_wizard( $pro_url, $pro_docs );
		self::render_pro_wishlist( $pro_url, $pro_docs );
		self::render_pro_cash( $pro_url, $pro_docs );
		self::render_pro_csv( $pro_url, $pro_docs );
		self::render_pro_more( $pro_url, $pro_docs );

		echo '</div>';
	}

	/**
	 * Echo the “Available in Pro” badge (escaped at print time).
	 *
	 * @return void
	 */
	private static function echo_pro_badge() {
		echo '<span class="wgrsvp-help-pro-badge" style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:10px;background:#fcf0e3;color:#996800;font-size:12px;font-weight:600;vertical-align:middle;">';
		echo esc_html__( 'Available in Pro', 'wedding-party-rsvp' );
		echo '</span>';
	}

	/**
	 * Wedding setup guide pointer.
	 *
	 * @return void
	 */
	private static function render_setup_guide_help() {
		echo '<h2 id="wgrsvp-help-setup-guide">' . esc_html__( 'Wedding setup guide', 'wedding-party-rsvp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Use Wedding RSVP → Setup guide for an ordered checklist that walks a coordinator through first-time setup (RSVP page, menus, guests, testing, and optional reminders).', 'wedding-party-rsvp' ) . '</p>';
		echo '<p>' . esc_html__( 'When Wedding Party RSVP Pro is active, extra Pro steps (license, form wording, communications, registry hub, seating, mobile app, and more) appear in the same guide automatically.', 'wedding-party-rsvp' ) . '</p>';
		if ( class_exists( 'WGRSVP_Setup_Guide', false ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( WGRSVP_Setup_Guide::url() ) . '">' . esc_html__( 'Open setup guide', 'wedding-party-rsvp' ) . '</a></p>';
		}
	}

	/**
	 * Order prints partners help.
	 *
	 * @return void
	 */
	private static function render_order_prints_help() {
		echo '<h2 id="wgrsvp-help-order-prints">' . esc_html__( 'Order prints (partners)', 'wedding-party-rsvp' ) . '</h2>';
		echo '<p>' . esc_html__( 'On the guest list, after you export place cards (CSV/PDF), the Order prints panel links to Printful, Canva, and Gelato so you can order place cards, seating posters, and related stationery.', 'wedding-party-rsvp' ) . '</p>';
		echo '<p>' . esc_html__( 'These are optional third-party services. Opening a link happens in your browser; the plugin does not upload your guest list to the printer automatically. Export your file first, then upload or paste into the partner’s tools.', 'wedding-party-rsvp' ) . '</p>';
		echo '<p>' . esc_html__( 'Affiliate disclosure: partner links may include tracking so Land Tech Web Designs (Wedding Party RSVP) can earn a commission at no extra cost to you. Affiliate destination URLs are configured on the weddingrsvp.pro network hub (Mobile App → hub settings) when Pro is active on the hub. Hide the panel under Settings → Frontend Display if you prefer not to see it.', 'wedding-party-rsvp' ) . '</p>';
		if ( class_exists( 'WGRSVP_Print_Partners', false ) ) {
			WGRSVP_Print_Partners::render_panel();
		}
	}

	/**
	 * Free gift registry links section.
	 *
	 * @return void
	 */
	private static function render_free_gift_registries() {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-gift-registries" style="max-width:920px;margin:28px 0;">';
		echo '<h2>' . esc_html__( 'Gift registry links (included in free)', 'wedding-party-rsvp' ) . '</h2>';
		echo '<p>' . esc_html__( 'You can show store registry links on your RSVP page so guests know where to shop. This is built into the free plugin.', 'wedding-party-rsvp' ) . '</p>';
		echo '<h3>' . esc_html__( 'How to set them up', 'wedding-party-rsvp' ) . '</h3>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Go to Wedding RSVP → Settings → Frontend Display (with Pro licensed, the same fields live under Settings → Frontend text).', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'Optional: type a heading such as “Gift registries”.', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'Add up to 15 rows: a short label (for example “Our Amazon Registry”) and the full https link to that registry.', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'Save. Guests see the links after they enter their Party ID on the RSVP page.', 'wedding-party-rsvp' ) . '</li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'Tip: create the registry on the store’s website first, copy its share link, then paste it here. Blank rows are ignored.', 'wedding-party-rsvp' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Amazon Associates section.
	 *
	 * @return void
	 */
	private static function render_amazon() {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-amazon" style="max-width:920px;margin:28px 0;">';
		echo '<h2>' . esc_html__( 'Amazon Associates (included in free)', 'wedding-party-rsvp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Optional and off by default. When you turn it on and enter your Associates tracking ID, Amazon registry links get your tag appended so qualifying purchases can earn a commission. The required disclosure (“As an Amazon Associate I earn from qualifying purchases.”) appears automatically under the links. Your own ID is required on self-hosted sites.', 'wedding-party-rsvp' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Skimlinks section.
	 *
	 * @return void
	 */
	private static function render_skimlinks() {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-skimlinks" style="max-width:920px;margin:28px 0;">';
		echo '<h2>' . esc_html__( 'Skimlinks (included in free)', 'wedding-party-rsvp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Optional and off by default. When enabled with your Skimlinks publisher ID, non-Amazon registry links can be routed through Skimlinks’ redirect so qualifying purchases may earn a commission. No Skimlinks JavaScript is loaded — only the outbound link changes. An affiliate disclosure is shown automatically. Your domain must be registered in your Skimlinks account.', 'wedding-party-rsvp' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Intro banner for Pro gift features.
	 *
	 * @param string $pro_url Pro marketing URL.
	 * @return void
	 */
	private static function render_pro_banner( $pro_url ) {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-pro" style="max-width:920px;margin:28px 0;padding:16px 18px;background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Pro gift & registry tools', 'wedding-party-rsvp' ) . '</h2>';
		echo '<p>' . esc_html__( 'The sections below describe features that ship in Wedding Party RSVP Pro. They are not active in the free plugin alone. With Pro licensed, open Wedding RSVP → Registry hub to use them, and Wedding RSVP → Documentation for the full manuals.', 'wedding-party-rsvp' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $pro_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn about Pro', 'wedding-party-rsvp' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Pro setup wizard docs.
	 *
	 * @param string $pro_url  Pro marketing URL.
	 * @param string $pro_docs Pro docs admin URL or empty.
	 * @return void
	 */
	private static function render_pro_wizard( $pro_url, $pro_docs ) {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-pro-wizard" style="max-width:920px;margin:28px 0;">';
		echo '<h2>' . esc_html__( 'Registry setup wizard', 'wedding-party-rsvp' ) . ' ';
		self::echo_pro_badge();
		echo '</h2>';
		echo '<p>' . esc_html__( 'A three-step guide on Wedding RSVP → Registry hub that helps you add store registries without hunting for settings screens.', 'wedding-party-rsvp' ) . '</p>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Pick stores — Amazon, Target, Walmart, Zola, Crate & Barrel, Macy’s, Williams Sonoma, The Knot, MyRegistry, or another store.', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'For each store, open that store’s registry-creation page, build the registry there, then paste the share link back into the wizard. A gentle warning appears if the link does not match the store you picked.', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'Review and finish — links are added to the same gift registry list the free plugin shows on the RSVP page. Existing links are kept; duplicates are skipped.', 'wedding-party-rsvp' ) . '</li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'The wizard only stores links on your site. It does not sign in to the stores or manage the registries for you.', 'wedding-party-rsvp' ) . '</p>';
		self::render_pro_cta( $pro_url, $pro_docs, 'wpr-doc-registry-hub' );
		echo '</div>';
	}

	/**
	 * Pro wish list docs.
	 *
	 * @param string $pro_url  Pro marketing URL.
	 * @param string $pro_docs Pro docs admin URL or empty.
	 * @return void
	 */
	private static function render_pro_wishlist( $pro_url, $pro_docs ) {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-pro-wishlist" style="max-width:920px;margin:28px 0;">';
		echo '<h2>' . esc_html__( 'Wish list (on-site item registry)', 'wedding-party-rsvp' ) . ' ';
		self::echo_pro_badge();
		echo '</h2>';
		echo '<p>' . esc_html__( 'Show individual gifts — from any store — as cards on your RSVP page. Guests can reserve a gift or mark it purchased so nobody buys the same thing twice, then shop using the store link on the card.', 'wedding-party-rsvp' ) . '</p>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'On Registry hub, paste a product link and click Fetch details to auto-fill the name, photo, and price (you can edit them).', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'Optional: add a short note for guests (for example “We love the navy color”).', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'Guests reserve with their name; the card flips to Reserved or Purchased for everyone. Reservations are first-come, first-served. Guests can undo from the same browser; you can release or mark purchased from the admin table.', 'wedding-party-rsvp' ) . '</li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'If you enabled Amazon Associates or Skimlinks in Settings, wish-list links get the same affiliate treatment and disclosures as your registry links.', 'wedding-party-rsvp' ) . '</p>';
		self::render_pro_cta( $pro_url, $pro_docs, 'wpr-doc-registry-hub' );
		echo '</div>';
	}

	/**
	 * Pro cash fund docs.
	 *
	 * @param string $pro_url  Pro marketing URL.
	 * @param string $pro_docs Pro docs admin URL or empty.
	 * @return void
	 */
	private static function render_pro_cash( $pro_url, $pro_docs ) {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-pro-cash" style="max-width:920px;margin:28px 0;">';
		echo '<h2>' . esc_html__( 'Stripe cash fund', 'wedding-party-rsvp' ) . ' ';
		self::echo_pro_badge();
		echo '</h2>';
		echo '<p>' . esc_html__( 'Suggest cash gift amounts (for example “$25 — Coffee date”) on the RSVP page. With your own Stripe keys configured on Registry hub, guests can Contribute through Stripe-hosted Checkout — card numbers never touch your WordPress site. Money goes to the couple’s Stripe account; Pro keeps a local receipt list (ledger) of contributions.', 'wedding-party-rsvp' ) . '</p>';
		self::render_pro_cta( $pro_url, $pro_docs, 'wpr-doc-cash-fund' );
		echo '</div>';
	}

	/**
	 * Pro CSV import docs.
	 *
	 * @param string $pro_url  Pro marketing URL.
	 * @param string $pro_docs Pro docs admin URL or empty.
	 * @return void
	 */
	private static function render_pro_csv( $pro_url, $pro_docs ) {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-pro-csv" style="max-width:920px;margin:28px 0;">';
		echo '<h2>' . esc_html__( 'Import gifts CSV', 'wedding-party-rsvp' ) . ' ';
		self::echo_pro_badge();
		echo '</h2>';
		echo '<p>' . esc_html__( 'When guests buy gifts elsewhere, import a CSV (guest_id or guest_name, gift, optional date) on Registry hub to update each guest’s gift-received notes. Use Dry run first to preview matches without writing anything.', 'wedding-party-rsvp' ) . '</p>';
		self::render_pro_cta( $pro_url, $pro_docs, 'wpr-doc-registry-hub' );
		echo '</div>';
	}

	/**
	 * Short list of other Pro gift-related tools.
	 *
	 * @param string $pro_url  Pro marketing URL.
	 * @param string $pro_docs Pro docs admin URL or empty.
	 * @return void
	 */
	private static function render_pro_more( $pro_url, $pro_docs ) {
		echo '<div class="wgrsvp-help-section" id="wgrsvp-help-pro-more" style="max-width:920px;margin:28px 0;">';
		echo '<h2>' . esc_html__( 'More Pro tools related to gifts', 'wedding-party-rsvp' ) . ' ';
		self::echo_pro_badge();
		echo '</h2>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Registry hub planning notes — private quiz / curation notes and retailer research notes for the couple or planner.', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'Thank-you drafts and digital send options — help finish thank-yous after gifts arrive.', 'wedding-party-rsvp' ) . '</li>';
		echo '<li>' . esc_html__( 'Companion mobile app — coordinators can review cash contributions and guest tools from their phone when Pro is licensed.', 'wedding-party-rsvp' ) . '</li>';
		echo '</ul>';
		self::render_pro_cta( $pro_url, $pro_docs, '' );
		echo '</div>';
	}

	/**
	 * CTA row: Learn about Pro and/or open Pro docs section.
	 *
	 * @param string $pro_url  Marketing URL.
	 * @param string $pro_docs Pro docs base URL or empty.
	 * @param string $anchor   Optional Pro docs anchor (without #).
	 * @return void
	 */
	private static function render_pro_cta( $pro_url, $pro_docs, $anchor ) {
		echo '<p style="margin-top:12px;">';
		if ( '' !== $pro_docs ) {
			$url = $pro_docs;
			if ( '' !== $anchor ) {
				$url .= '#' . sanitize_html_class( $anchor );
			}
			echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Open Pro documentation for this topic', 'wedding-party-rsvp' ) . '</a> ';
		} else {
			echo '<a class="button button-secondary" href="' . esc_url( $pro_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Pro to use this feature', 'wedding-party-rsvp' ) . '</a>';
		}
		echo '</p>';
	}
}
