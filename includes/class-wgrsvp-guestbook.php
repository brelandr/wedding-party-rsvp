<?php
/**
 * Public digital guestbook: table, shortcode, moderation, privacy hooks.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moderated guest messages for a public wedding site wall.
 */
class WGRSVP_Guestbook {

	public const DB_VERSION        = 1;
	public const OPTION_DB_VERSION = 'wgrsvp_guestbook_db_version';
	public const STATUS_PENDING    = 'pending';
	public const STATUS_APPROVED   = 'approved';
	public const STATUS_SPAM       = 'spam';

	/**
	 * Prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wgrsvp_guestbook';
	}

	/**
	 * Register shortcode, AJAX, admin, upgrades.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 20 );
		add_shortcode( 'wgrsvp_guestbook', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 110 );
		add_action( 'wp_ajax_wgrsvp_guestbook_submit', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_wgrsvp_guestbook_submit', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'admin_post_wgrsvp_guestbook_moderate', array( __CLASS__, 'handle_moderate' ) );
		add_filter( 'wgrsvp_guest_hub_payload', array( __CLASS__, 'filter_hub_payload' ), 30, 2 );
		if ( class_exists( 'WGRSVP_Guestbook_Captcha', false ) ) {
			WGRSVP_Guestbook_Captcha::register_hooks();
		}
	}

	/**
	 * Create table on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_db();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Upgrade path after plugin updates.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		$ver = (int) get_option( self::OPTION_DB_VERSION, 0 );
		if ( $ver >= self::DB_VERSION ) {
			return;
		}
		self::install_db();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * dbDelta schema.
	 *
	 * @return void
	 */
	private static function install_db() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			author_name varchar(191) NOT NULL DEFAULT '',
			message text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			party_id varchar(64) DEFAULT NULL,
			ip_hash varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status_created (status, created_at),
			KEY party_id (party_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Admin submenu.
	 *
	 * @return void
	 */
	public static function register_admin_menu() {
		if ( ! wgrsvp_admin_module_enabled( 'guestbook' ) ) {
			return;
		}
		add_submenu_page(
			'wedding-rsvp-main',
			__( 'Guestbook', 'wedding-party-rsvp' ),
			__( 'Guestbook', 'wedding-party-rsvp' ),
			'manage_options',
			'wgrsvp-guestbook',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Count guestbook entries awaiting moderation.
	 *
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $exists ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$table,
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * Approved entries for public display.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public static function get_approved( $limit = 50 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 200, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, author_name, message, party_id, created_at FROM %i WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT %d',
				$table,
				self::STATUS_APPROVED,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Shortcode: form + approved wall.
	 *
	 * @param array<string,string>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'limit' => '50',
			),
			is_array( $atts ) ? $atts : array(),
			'wgrsvp_guestbook'
		);
		$limit = absint( $atts['limit'] );
		if ( $limit < 1 ) {
			$limit = 50;
		}

		self::enqueue_assets();

		$entries = self::get_approved( $limit );
		/**
		 * Filter approved guestbook entries before render.
		 *
		 * @param array<int,object> $entries Rows.
		 * @param int               $limit   Limit.
		 */
		$entries = apply_filters( 'wgrsvp_guestbook_approved_entries', $entries, $limit );

		ob_start();
		echo '<div class="wgrsvp-guestbook" data-wgrsvp-guestbook>';
		echo '<div class="wgrsvp-guestbook__wall" aria-live="polite">';
		if ( empty( $entries ) ) {
			echo '<p class="wgrsvp-guestbook__empty">' . esc_html__( 'Be the first to leave a note for the couple.', 'wedding-party-rsvp' ) . '</p>';
		} else {
			foreach ( $entries as $row ) {
				echo self::render_entry_html( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
			}
		}
		echo '</div>';
		echo '<form class="wgrsvp-guestbook__form" method="post" action="">';
		echo '<p class="wgrsvp-guestbook__field"><label for="wgrsvp-gb-name">' . esc_html__( 'Your name', 'wedding-party-rsvp' ) . '</label>';
		echo '<input type="text" id="wgrsvp-gb-name" name="author_name" required maxlength="120" autocomplete="name" /></p>';
		echo '<p class="wgrsvp-guestbook__field"><label for="wgrsvp-gb-message">' . esc_html__( 'Message', 'wedding-party-rsvp' ) . '</label>';
		echo '<textarea id="wgrsvp-gb-message" name="message" required maxlength="2000" rows="4"></textarea></p>';
		echo '<p class="wgrsvp-guestbook__honeypot" aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden;">';
		echo '<label>' . esc_html__( 'Leave blank', 'wedding-party-rsvp' ) . '<input type="text" name="wgrsvp_gb_honey" value="" tabindex="-1" autocomplete="off" /></label></p>';
		echo '<input type="hidden" name="party_id" value="" />';
		if ( class_exists( 'WGRSVP_Guestbook_Captcha', false ) ) {
			echo WGRSVP_Guestbook_Captcha::widget_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from helper.
		}
		echo '<p class="wgrsvp-guestbook__actions"><button type="submit" class="wgrsvp-guestbook__submit">' . esc_html__( 'Sign the guestbook', 'wedding-party-rsvp' ) . '</button></p>';
		echo '<p class="wgrsvp-guestbook__status" role="status" hidden></p>';
		echo '</form>';
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Escaped entry card HTML.
	 *
	 * @param object $row Row.
	 * @return string
	 */
	private static function render_entry_html( $row ) {
		$name = isset( $row->author_name ) ? (string) $row->author_name : '';
		$msg  = isset( $row->message ) ? (string) $row->message : '';
		$when = isset( $row->created_at ) ? (string) $row->created_at : '';
		$html = '<article class="wgrsvp-guestbook__entry">';
		$html .= '<p class="wgrsvp-guestbook__author">' . esc_html( $name ) . '</p>';
		$html .= '<p class="wgrsvp-guestbook__message">' . esc_html( $msg ) . '</p>';
		if ( '' !== $when ) {
			$html .= '<p class="wgrsvp-guestbook__meta"><time datetime="' . esc_attr( $when ) . '">' . esc_html( $when ) . '</time></p>';
		}
		$html .= '</article>';
		return $html;
	}

	/**
	 * Front assets (conditional).
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		$ver  = defined( 'WGRSVP_VERSION' ) ? WGRSVP_VERSION : '8.3.9';
		$base = defined( 'WGRSVP_PLUGIN_FILE' ) ? WGRSVP_PLUGIN_FILE : dirname( __DIR__ ) . '/wedding-party-rsvp.php';
		if ( class_exists( 'WGRSVP_Guestbook_Captcha', false ) ) {
			WGRSVP_Guestbook_Captcha::enqueue_scripts();
		}
		wp_enqueue_style(
			'wgrsvp-guestbook',
			plugins_url( 'assets/css/wgrsvp-guestbook.css', $base ),
			array(),
			$ver
		);
		$deps = array();
		if ( wp_script_is( 'wgrsvp-google-recaptcha', 'enqueued' ) || wp_script_is( 'wgrsvp-google-recaptcha', 'registered' ) ) {
			// Captcha scripts load async; guestbook JS does not hard-depend on them.
		}
		wp_enqueue_script(
			'wgrsvp-guestbook',
			plugins_url( 'assets/js/wgrsvp-guestbook.js', $base ),
			$deps,
			$ver,
			true
		);
		$captcha = class_exists( 'WGRSVP_Guestbook_Captcha', false )
			? WGRSVP_Guestbook_Captcha::front_config()
			: array(
				'primary'          => 'none',
				'recaptchaSiteKey' => '',
				'turnstileSiteKey' => '',
			);
		wp_localize_script(
			'wgrsvp-guestbook',
			'wgrsvpGuestbook',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wgrsvp_guestbook_submit' ),
				'captcha' => $captcha,
				'i18n'    => array(
					'working'       => __( 'Sending…', 'wedding-party-rsvp' ),
					'success'       => __( 'Thank you! Your note will appear after it is approved.', 'wedding-party-rsvp' ),
					'error'         => __( 'Could not save your message. Please try again.', 'wedding-party-rsvp' ),
					'captchaNeeded' => __( 'Please complete the security check.', 'wedding-party-rsvp' ),
				),
			)
		);
	}

	/**
	 * Public AJAX submit.
	 *
	 * @return void
	 */
	public static function ajax_submit() {
		check_ajax_referer( 'wgrsvp_guestbook_submit', 'nonce' );

		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$rl_key = 'wgrsvp_gb_rl_' . md5( $ip );
		$hits   = (int) get_transient( $rl_key );
		$max    = (int) apply_filters( 'wgrsvp_guestbook_rate_limit_max', 8 );
		if ( $hits >= $max ) {
			wp_send_json_error( array( 'message' => __( 'Too many messages. Please wait a minute.', 'wedding-party-rsvp' ) ), 429 );
		}
		set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

		$honey = isset( $_POST['wgrsvp_gb_honey'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_gb_honey'] ) )
			: '';
		if ( '' !== $honey ) {
			wp_send_json_error( array( 'message' => __( 'Rejected.', 'wedding-party-rsvp' ) ), 400 );
		}

		if ( class_exists( 'WGRSVP_Guestbook_Captcha', false ) ) {
			$captcha_ok = WGRSVP_Guestbook_Captcha::verify_request();
			if ( is_wp_error( $captcha_ok ) ) {
				wp_send_json_error( array( 'message' => $captcha_ok->get_error_message() ), 400 );
			}
		}

		$name = isset( $_POST['author_name'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['author_name'] ) )
			: '';
		$msg = isset( $_POST['message'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) )
			: '';
		$party_id = isset( $_POST['party_id'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['party_id'] ) )
			: '';

		if ( '' === $name || '' === $msg ) {
			wp_send_json_error( array( 'message' => __( 'Name and message are required.', 'wedding-party-rsvp' ) ), 400 );
		}
		if ( strlen( $msg ) > 2000 ) {
			wp_send_json_error( array( 'message' => __( 'Message is too long.', 'wedding-party-rsvp' ) ), 400 );
		}

		$status = self::STATUS_PENDING;
		/**
		 * Filter initial guestbook status (Pro may auto-approve trusted Party IDs).
		 *
		 * @param string $status   pending|approved|spam.
		 * @param string $party_id Party ID.
		 * @param string $name     Author name.
		 */
		$status = (string) apply_filters( 'wgrsvp_guestbook_entry_status', $status, $party_id, $name );
		if ( ! in_array( $status, array( self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_SPAM ), true ) ) {
			$status = self::STATUS_PENDING;
		}

		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'author_name' => $name,
				'message'     => $msg,
				'status'      => $status,
				'party_id'    => '' !== $party_id ? $party_id : null,
				'ip_hash'     => '' !== $ip ? hash( 'sha256', $ip . wp_salt( 'nonce' ) ) : '',
				'created_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not save your message.', 'wedding-party-rsvp' ) ), 500 );
		}
		$id = (int) $wpdb->insert_id;
		$data = array(
			'id'          => $id,
			'author_name' => $name,
			'message'     => $msg,
			'status'      => $status,
			'party_id'    => $party_id,
			'created_at'  => $now,
		);
		/**
		 * Fires after a guestbook entry is stored.
		 *
		 * @param int                  $id   Row ID.
		 * @param array<string,mixed>  $data Entry data.
		 */
		do_action( 'wgrsvp_guestbook_entry_submitted', $id, $data );
		if ( class_exists( 'WGRSVP_Admin_Menu_Badges', false ) ) {
			WGRSVP_Admin_Menu_Badges::flush_cache();
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Thank you! Your note will appear after it is approved.', 'wedding-party-rsvp' ),
				'status'   => $status,
				'approved' => self::STATUS_APPROVED === $status,
			)
		);
	}

	/**
	 * Admin moderation POST.
	 *
	 * @return void
	 */
	public static function handle_moderate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wedding-party-rsvp' ) );
		}
		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'wgrsvp_guestbook_moderate' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ) );
		}

		$id     = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$action = isset( $_POST['gb_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gb_action'] ) ) : '';
		if ( $id < 1 || ! in_array( $action, array( 'approve', 'spam', 'delete', 'pending' ), true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wgrsvp-guestbook' ) );
			exit;
		}

		global $wpdb;
		$table = self::table_name();
		if ( 'delete' === $action ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		} else {
			$map = array(
				'approve' => self::STATUS_APPROVED,
				'spam'    => self::STATUS_SPAM,
				'pending' => self::STATUS_PENDING,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'status' => $map[ $action ] ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			/**
			 * Fires after guestbook moderation.
			 *
			 * @param int    $id     Entry ID.
			 * @param string $action approve|spam|pending.
			 */
			do_action( 'wgrsvp_guestbook_entry_moderated', $id, $action );
		}
		if ( class_exists( 'WGRSVP_Admin_Menu_Badges', false ) ) {
			WGRSVP_Admin_Menu_Badges::flush_cache();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wgrsvp-guestbook&updated=1' ) );
		exit;
	}

	/**
	 * Admin list UI.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		wgrsvp_require_admin_module_or_die( 'guestbook' );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, author_name, message, status, party_id, created_at FROM %i ORDER BY created_at DESC, id DESC LIMIT %d',
				$table,
				200
			)
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Guestbook', 'wedding-party-rsvp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Moderate public guestbook messages. Approved notes appear on [wgrsvp_guestbook].', 'wedding-party-rsvp' ) . '</p>';
		if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Updated.', 'wedding-party-rsvp' ) . '</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'wedding-party-rsvp' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'wedding-party-rsvp' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'wedding-party-rsvp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'wedding-party-rsvp' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wedding-party-rsvp' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No guestbook entries yet.', 'wedding-party-rsvp' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$id = (int) $row->id;
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row->created_at ) . '</td>';
			echo '<td>' . esc_html( (string) $row->author_name ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( (string) $row->message, 40 ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row->status ) . '</td>';
			echo '<td>';
			foreach ( array( 'approve' => __( 'Approve', 'wedding-party-rsvp' ), 'pending' => __( 'Pending', 'wedding-party-rsvp' ), 'spam' => __( 'Spam', 'wedding-party-rsvp' ), 'delete' => __( 'Delete', 'wedding-party-rsvp' ) ) as $act => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin-right:4px;">';
				wp_nonce_field( 'wgrsvp_guestbook_moderate' );
				echo '<input type="hidden" name="action" value="wgrsvp_guestbook_moderate" />';
				echo '<input type="hidden" name="entry_id" value="' . esc_attr( (string) $id ) . '" />';
				echo '<input type="hidden" name="gb_action" value="' . esc_attr( $act ) . '" />';
				echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Teaser on guest hub when entries exist.
	 *
	 * @param array<string,mixed> $payload Hub payload.
	 * @param string              $party_id Party ID.
	 * @return array<string,mixed>
	 */
	public static function filter_hub_payload( $payload, $party_id ) {
		unset( $party_id );
		if ( ! is_array( $payload ) ) {
			return $payload;
		}
		$n = count( self::get_approved( 5 ) );
		if ( $n > 0 ) {
			$payload['guestbookCount'] = $n;
			$payload['guestbookUrl']   = home_url( '/' );
		}
		return $payload;
	}
}
