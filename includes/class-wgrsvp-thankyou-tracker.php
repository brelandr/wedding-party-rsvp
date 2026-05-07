<?php
/**
 * Post-event thank-you checklist: DB table, admin UI, shortcode, block.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_ThankYou_Tracker' ) ) {

	/**
	 * Planner checklist stored in a custom table (no guest PII by default).
	 *
	 * Admin POST actions verify nonces before reading task fields; GET deletes use per-row nonces.
	 */
	class WGRSVP_ThankYou_Tracker {

		public const DB_VERSION        = 1;
		public const OPTION_DB_VERSION = 'wgrsvp_thankyou_tracker_db_version';

		/**
		 * Return the checklist DB table name including prefix.
		 *
		 * @return string
		 */
		public static function table_name() {
			global $wpdb;

			return $wpdb->prefix . 'wgrsvp_thankyou_tasks';
		}

		/**
		 * Whether this WordPress version supports %i table identifiers in $wpdb->prepare().
		 *
		 * @since 7.3.35
		 *
		 * @return bool True when running WordPress 6.2 or newer.
		 */
		private static function db_supports_identifier_placeholder() {
			$wp_version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0';
			return version_compare( $wp_version, '6.2', '>=' );
		}

		/**
		 * Quote a table name with backticks for SQL on WordPress versions older than 6.2.
		 *
		 * @since 7.3.35
		 *
		 * @param string $table Table name, including $wpdb->prefix where applicable.
		 * @return string Quoted identifier safe for concatenation into a static SQL fragment.
		 */
		private static function legacy_escape_table_name( $table ) {
			return '`' . str_replace( '`', '``', $table ) . '`';
		}

		/**
		 * Load all checklist rows ordered for display (admin screen or front-end shortcode).
		 *
		 * @since 7.3.35
		 *
		 * @param wpdb   $wpdb  WordPress database object.
		 * @param string $table Full checklist table name (typically from table_name()).
		 * @return array<int, stdClass> Row objects with id, title, is_done, sort_order.
		 */
		private static function fetch_all_tasks( $wpdb, $table ) {
			if ( self::db_supports_identifier_placeholder() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- %i table; static ORDER BY.
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, is_done, sort_order FROM %i ORDER BY sort_order ASC, id ASC', $table ), OBJECT );
			} else {
				$t = self::legacy_escape_table_name( $table );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WP < 6.2: table from plugin prefix + literal suffix.
				$rows = $wpdb->get_results( 'SELECT id, title, is_done, sort_order FROM ' . $t . ' ORDER BY sort_order ASC, id ASC', OBJECT );
			}

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Hook registration (shortcode, upgrades).
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 20 );
			add_shortcode( 'wgrsvp_thankyou_tracker', array( __CLASS__, 'shortcode' ) );
			// After Premium rebuilds the Wedding RSVP menu (admin_menu priority 100), re-attach free-only screens.
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 110 );
		}

		/**
		 * Create/update table on activation or upgrade.
		 *
		 * @return void
		 */
		public static function activate() {
			self::install_db();
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
			self::maybe_seed_defaults();
		}

		/**
		 * Ensure schema exists after plugin updates.
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
			self::maybe_seed_defaults();
		}

		/**
		 * Run dbDelta for the thank-you tasks table.
		 *
		 * @return void
		 */
		private static function install_db() {
			global $wpdb;

			$table           = self::table_name();
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL DEFAULT '',
				is_done tinyint(1) NOT NULL DEFAULT 0,
				sort_order int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY sort_order (sort_order)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}

		/**
		 * Insert starter rows when the table is empty.
		 *
		 * @return void
		 */
		private static function maybe_seed_defaults() {
			global $wpdb;

			$table = self::table_name();
			if ( self::db_supports_identifier_placeholder() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			} else {
				$t = self::legacy_escape_table_name( $table );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $t );
			}
			if ( $n > 0 ) {
				return;
			}

			$defaults = array(
				__( 'Send thank-you notes (or emails) to guests', 'wedding-party-rsvp' ),
				__( 'Thank vendors, venue, and officiant', 'wedding-party-rsvp' ),
				__( 'Finalize final payments or reviews where you committed to them', 'wedding-party-rsvp' ),
				__( 'Return rentals and collect deposits if applicable', 'wedding-party-rsvp' ),
			);

			$o = 0;
			foreach ( $defaults as $title ) {
				++$o;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$table,
					array(
						'title'      => $title,
						'is_done'    => 0,
						'sort_order' => $o,
					),
					array( '%s', '%d', '%d' )
				);
			}
		}

		/**
		 * Register the WeddingRSVP admin submenu for this feature.
		 *
		 * @return void
		 */
		public static function register_admin_menu() {
			if ( ! wgrsvp_admin_module_enabled( 'thankyou_tracker' ) ) {
				return;
			}
			add_submenu_page(
				'wedding-rsvp-main',
				__( 'Thank-you checklist', 'wedding-party-rsvp' ),
				__( 'Thank-you checklist', 'wedding-party-rsvp' ),
				'manage_options',
				'wedding-rsvp-thankyou-tracker',
				array( __CLASS__, 'render_admin_page' )
			);
		}

		/**
		 * Render the checklist admin screen; processes GET deletes and POST saves after nonce checks.
		 *
		 * @return void
		 */
		public static function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ) );
			}

			wgrsvp_require_admin_module_or_die( 'thankyou_tracker' );

			global $wpdb;

			$table = self::table_name();
			$msg   = '';

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET delete: explicit nonce failure handling below.
			if ( isset( $_GET['wgrsvp_thankyou_remove'] ) ) {
				if ( ! isset( $_GET['_wpnonce'] ) ) {
					wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
				}
				$rid = absint( wp_unslash( (string) $_GET['wgrsvp_thankyou_remove'] ) );
				if ( $rid < 1 || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'wgrsvp_thankyou_remove_' . (string) $rid ) ) {
					wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
				}
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->delete( $table, array( 'id' => $rid ), array( '%d' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=wedding-rsvp-thankyou-tracker' ) );
				exit;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( isset( $_POST['wgrsvp_save_thankyou_checklist_nonce'] ) && isset( $_POST['wgrsvp_thankyou_action'] ) ) {
				if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_save_thankyou_checklist_nonce'] ) ), 'wgrsvp_save_thankyou_checklist' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
				}
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ), esc_html__( 'Error', 'wedding-party-rsvp' ), 403 );
				}
				$action = sanitize_key( wp_unslash( (string) $_POST['wgrsvp_thankyou_action'] ) );

				if ( 'add' === $action && isset( $_POST['wgrsvp_thankyou_new_title'] ) ) {
					$new_title = sanitize_text_field( wp_unslash( (string) $_POST['wgrsvp_thankyou_new_title'] ) );
					if ( '' !== $new_title ) {
						if ( self::db_supports_identifier_placeholder() ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
							$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort_order) FROM %i', $table ) );
						} else {
							$t = self::legacy_escape_table_name( $table );
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
							$max = (int) $wpdb->get_var( 'SELECT MAX(sort_order) FROM ' . $t );
						}
						if ( $max < 1 ) {
							$max = 0;
						}
						$next = $max + 1;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->insert(
							$table,
							array(
								'title'      => $new_title,
								'is_done'    => 0,
								'sort_order' => $next,
							),
							array( '%s', '%d', '%d' )
						);
						/* translators: %s: task title. */
						$msg = sprintf( __( 'Added: %s', 'wedding-party-rsvp' ), $new_title );
					}
				} elseif ( 'save' === $action && isset( $_POST['task'] ) && is_array( $_POST['task'] ) ) {
					// Nonce verified above; nested task fields normalized with map_deep; loop applies absint and boolean for DB.
					$raw_tasks = map_deep( wp_unslash( $_POST['task'] ), 'sanitize_text_field' );
					if ( ! is_array( $raw_tasks ) ) {
						$raw_tasks = array();
					}
					foreach ( $raw_tasks as $id_raw => $row ) {
						$id = absint( $id_raw );
						if ( $id < 1 || ! is_array( $row ) ) {
							continue;
						}
						$title   = isset( $row['title'] ) ? sanitize_text_field( wp_unslash( (string) $row['title'] ) ) : '';
						$sort    = isset( $row['sort_order'] ) ? absint( $row['sort_order'] ) : 0;
						$is_on   = ! empty( $row['is_done'] ) && '1' === (string) $row['is_done'];
						$is_done = $is_on ? 1 : 0;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$table,
							array(
								'title'      => $title,
								'sort_order' => $sort,
								'is_done'    => $is_done,
							),
							array( 'id' => $id ),
							array( '%s', '%d', '%d' ),
							array( '%d' )
						);
					}
					$msg = __( 'Checklist saved.', 'wedding-party-rsvp' );
					do_action( 'wgrsvp_thankyou_tracker_saved' );
				}

				if ( '' !== $msg ) {
					add_settings_error( 'wgrsvp_thankyou_tracker', 'wgrsvp_thankyou_tracker_msg', $msg, 'success' );
				}
			}

			$rows = self::fetch_all_tasks( $wpdb, $table );

			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Thank-you checklist', 'wedding-party-rsvp' ) . '</h1>';
			echo '<p class="description">' . esc_html__( 'Track post-event follow-ups (thank-you notes, vendors, rentals). This list is stored in your site database and is separate from guest RSVP rows.', 'wedding-party-rsvp' ) . '</p>';
			settings_errors( 'wgrsvp_thankyou_tracker' );

			echo '<h2>' . esc_html__( 'Add a task', 'wedding-party-rsvp' ) . '</h2>';
			echo '<form method="post" style="margin-bottom:2em;">';
			wp_nonce_field( 'wgrsvp_save_thankyou_checklist', 'wgrsvp_save_thankyou_checklist_nonce' );
			echo '<input type="hidden" name="wgrsvp_thankyou_action" value="add" />';
			echo '<input type="text" name="wgrsvp_thankyou_new_title" class="regular-text" placeholder="' . esc_attr__( 'e.g. Mail photo thank-yous', 'wedding-party-rsvp' ) . '" /> ';
			submit_button( __( 'Add task', 'wedding-party-rsvp' ), 'secondary', 'submit', false );
			echo '</form>';

			echo '<form method="post">';
			wp_nonce_field( 'wgrsvp_save_thankyou_checklist', 'wgrsvp_save_thankyou_checklist_nonce' );
			echo '<input type="hidden" name="wgrsvp_thankyou_action" value="save" />';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Done', 'wedding-party-rsvp' ) . '</th>';
			echo '<th>' . esc_html__( 'Task', 'wedding-party-rsvp' ) . '</th>';
			echo '<th>' . esc_html__( 'Order', 'wedding-party-rsvp' ) . '</th>';
			echo '<th>' . esc_html__( 'Remove', 'wedding-party-rsvp' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $r ) {
				$rid   = (int) $r->id;
				$done  = ( 1 === (int) $r->is_done );
				$title = (string) $r->title;
				$sort  = (int) $r->sort_order;
				echo '<tr>';
				echo '<td><label><input type="checkbox" name="task[' . absint( $rid ) . '][is_done]" value="1" ' . checked( $done, true, false ) . ' /></label></td>';
				echo '<td><input type="text" class="large-text" name="task[' . absint( $rid ) . '][title]" value="' . esc_attr( $title ) . '" /></td>';
				echo '<td><input type="number" name="task[' . absint( $rid ) . '][sort_order]" value="' . esc_attr( (string) $sort ) . '" style="width:5em;" /></td>';
				$del_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'                   => 'wedding-rsvp-thankyou-tracker',
							'wgrsvp_thankyou_remove' => $rid,
						),
						admin_url( 'admin.php' )
					),
					'wgrsvp_thankyou_remove_' . (string) $rid
				);
				echo '<td><a href="' . esc_url( $del_url ) . '" class="button-link wgrsvp-admin-confirm" data-wgrsvp-confirm="confirmRemoveThankyouTask">' . esc_html__( 'Remove', 'wedding-party-rsvp' ) . '</a></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
			submit_button( __( 'Save changes', 'wedding-party-rsvp' ) );
			echo '</form>';
			echo '</div>';
		}

		/**
		 * Shortcode: progress list for logged-in admins (or filtered).
		 *
		 * @param array<string,string> $atts Shortcode attributes.
		 * @return string
		 */
		public static function shortcode( $atts ) {
			$atts = shortcode_atts(
				array(
					// When true, any site visitor can see task titles (use only on private pages).
					'public' => '0',
				),
				$atts,
				'wgrsvp_thankyou_tracker'
			);

			$public = ( '1' === (string) $atts['public'] || 'true' === strtolower( (string) $atts['public'] ) );

			$can = $public;
			if ( ! $can && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				$can = true;
			}

			/**
			 * Whether the current viewer may see the thank-you checklist on the front end.
			 *
			 * @param bool   $can    Default capability-based result.
			 * @param bool   $public Shortcode public attribute.
			 */
			$can = (bool) apply_filters( 'wgrsvp_thankyou_tracker_can_view_frontend', $can, $public );

			if ( ! wgrsvp_admin_module_enabled( 'thankyou_tracker' ) ) {
				return '';
			}

			if ( ! $can ) {
				return '';
			}

			global $wpdb;

			$table = self::table_name();
			$rows  = self::fetch_all_tasks( $wpdb, $table );

			if ( array() === $rows ) {
				return '<p class="wgrsvp-thankyou-checklist wgrsvp-thankyou-empty">' . esc_html__( 'No checklist tasks yet. Add them under Wedding RSVP → Thank-you checklist.', 'wedding-party-rsvp' ) . '</p>';
			}

			$total = count( $rows );
			$done  = 0;
			foreach ( $rows as $r ) {
				if ( 1 === (int) $r->is_done ) {
					++$done;
				}
			}

			ob_start();
			$progress_text = sprintf(
				/* translators: 1: completed count, 2: total count. */
				__( '%1$d of %2$d complete', 'wedding-party-rsvp' ),
				$done,
				$total
			);
			echo '<div class="wgrsvp-thankyou-checklist">';
			echo '<p class="wgrsvp-thankyou-progress" aria-live="polite"><strong>' . esc_html( $progress_text ) . '</strong></p>';
			echo '<ul class="wgrsvp-thankyou-list" style="list-style:none;padding-left:0;">';
			foreach ( $rows as $r ) {
				$is_done = 1 === (int) $r->is_done;
				$title   = (string) $r->title;
				$mark    = $is_done ? '✓ ' : '';
				$cls     = $is_done ? ' wgrsvp-thankyou-done' : '';
				echo '<li class="' . esc_attr( 'wgrsvp-thankyou-item' . $cls ) . '" style="margin:6px 0;">' . esc_html( $mark ) . esc_html( $title ) . '</li>';
			}
			echo '</ul></div>';

			return (string) ob_get_clean();
		}

		/**
		 * Register dynamic block (same output as shortcode).
		 *
		 * @return void
		 */
		public static function register_block() {
			if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
				return;
			}
			$block_dir = dirname( __DIR__ ) . '/blocks/thankyou-checklist';
			if ( ! is_readable( $block_dir . '/block.json' ) ) {
				return;
			}
			register_block_type_from_metadata( $block_dir );
		}
	}

}
