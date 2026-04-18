<?php
/**
 * Review request notice with AJAX dismissal.
 *
 * Shows "Enjoying [Plugin Name]?" after 7 days, with Yes / No (Support) / Dismiss.
 * Only on this plugin's admin pages. Dismissal via AJAX with nonce.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Review_Request' ) ) {

	/**
	 * Optional admin notice (“Enjoying …?”) with AJAX dismissal and translated JSON errors.
	 */
	class WGRSVP_Review_Request {

		const OPT_INSTALLED_AT = 'wgrsvp_review_installed_at';
		const OPT_DISMISSED    = 'wgrsvp_review_dismissed';
		const AJAX_ACTION      = 'wgrsvp_admin_review_request';
		const NONCE_ACTION     = 'wgrsvp_admin_review_request_ajax';
		const DAYS_BEFORE_ASK  = 7;

		/**
		 * Translated plugin name for the notice text.
		 *
		 * @var string
		 */
		private $plugin_name;

		/**
		 * WordPress.org plugin slug (reviews URL).
		 *
		 * @var string
		 */
		private $plugin_slug;

		/**
		 * Support or marketing URL for the "No" action.
		 *
		 * @var string
		 */
		private $support_url;

		/**
		 * WordPress.org reviews URL for the "Yes" action.
		 *
		 * @var string
		 */
		private $review_url;

		/**
		 * Registers hooks for the notice, script, and dismiss AJAX handler.
		 *
		 * @return void
		 */
		public function __construct() {
			$this->plugin_name = __( 'Wedding Party RSVP', 'wedding-party-rsvp' );
			$this->plugin_slug = 'wedding-party-rsvp';
			$this->support_url = apply_filters(
				'wgrsvp_review_request_support_url',
				'https://landtechwebdesigns.com/wedding-party-rsvp-wordpress-plugin/'
			);
			$this->review_url  = 'https://wordpress.org/support/plugin/' . $this->plugin_slug . '/reviews/#new-post';

			add_action( 'admin_init', array( $this, 'maybe_set_install_date' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_script' ) );
			add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_handle_dismiss' ) );
		}

		/**
		 * Stores first-seen timestamp for the delayed review prompt (once per site).
		 *
		 * @return void
		 */
		public function maybe_set_install_date() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( get_option( self::OPT_INSTALLED_AT, 0 ) ) {
				return;
			}
			update_option( self::OPT_INSTALLED_AT, time() );
		}

		/**
		 * Whether we're on one of this plugin's admin pages.
		 *
		 * @return bool
		 */
		private function is_plugin_admin_page() {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || ! isset( $screen->id ) ) {
				return false;
			}
			// Main: toplevel_page_wedding-rsvp-main; submenus: *wedding-rsvp* (e.g. wedding-rsvp-main_page_wedding-rsvp-menu).
			return ( 'toplevel_page_wedding-rsvp-main' === $screen->id || false !== strpos( $screen->id, 'wedding-rsvp' ) );
		}

		/**
		 * Whether the notice should be shown.
		 *
		 * @return bool
		 */
		private function should_show_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}
			if ( ! $this->is_plugin_admin_page() ) {
				return false;
			}
			if ( get_option( self::OPT_DISMISSED, 0 ) ) {
				return false;
			}
			$installed_at = (int) get_option( self::OPT_INSTALLED_AT, 0 );
			if ( ! $installed_at ) {
				return false;
			}
			$days_elapsed = ( time() - $installed_at ) / DAY_IN_SECONDS;
			return $days_elapsed >= self::DAYS_BEFORE_ASK;
		}

		/**
		 * Renders the review notice HTML when `should_show_notice()` is true.
		 *
		 * @return void
		 */
		public function maybe_show_notice() {
			if ( ! $this->should_show_notice() ) {
				return;
			}
			$nonce = wp_create_nonce( self::NONCE_ACTION );
			?>
			<div class="notice notice-info wgrsvp-review-request-notice" id="wgrsvp-review-request-notice" style="position:relative;">
				<p>
					<?php
					printf(
						/* translators: %s: plugin name */
						esc_html__( 'Enjoying %s?', 'wedding-party-rsvp' ),
						esc_html( $this->plugin_name )
					);
					?>
				</p>
				<p>
					<button type="button" class="button button-primary wgrsvp-review-btn" data-action="yes" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-review-url="<?php echo esc_url( $this->review_url ); ?>">
						<?php esc_html_e( 'Yes', 'wedding-party-rsvp' ); ?>
					</button>
					<button type="button" class="button wgrsvp-review-btn" data-action="no" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-support-url="<?php echo esc_url( $this->support_url ); ?>">
						<?php esc_html_e( 'No / Support', 'wedding-party-rsvp' ); ?>
					</button>
					<button type="button" class="button button-link wgrsvp-review-btn" data-action="dismiss" data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Dismiss', 'wedding-party-rsvp' ); ?>
					</button>
				</p>
			</div>
			<?php
		}

		/**
		 * Enqueue review-request script on this plugin's admin pages when notice is shown.
		 *
		 * @param string $hook_suffix Current admin screen hook suffix.
		 * @return void
		 */
		public function maybe_enqueue_script( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- admin_enqueue_scripts passes hook; screen uses get_current_screen().
			if ( ! $this->should_show_notice() ) {
				return;
			}
			$plugin_file = dirname( __DIR__ ) . '/wedding-party-rsvp.php';
			$handle      = 'wgrsvp-review-request';
			$src         = plugins_url( 'assets/js/wgrsvp-review-request.js', $plugin_file );
			wp_register_script( $handle, $src, array(), '7.3.9', true );
			wp_enqueue_script( $handle );
			wp_localize_script(
				$handle,
				'wgrsvpReviewRequest',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::AJAX_ACTION,
				)
			);
		}

		/**
		 * AJAX handler: `check_ajax_referer` first, then capability, then sanitize `choice`.
		 *
		 * Sends JSON success with `choice` or an error message string.
		 *
		 * @return void
		 */
		public function ajax_handle_dismiss() {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'wedding-party-rsvp' ),
					)
				);
			}

			$choice  = isset( $_POST['choice'] ) ? sanitize_text_field( wp_unslash( $_POST['choice'] ) ) : '';
			$allowed = array( 'yes', 'no', 'dismiss' );
			if ( ! in_array( $choice, $allowed, true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid choice.', 'wedding-party-rsvp' ),
					)
				);
			}

			update_option( self::OPT_DISMISSED, 1 );
			wp_send_json_success( array( 'choice' => $choice ) );
		}
	}
}
