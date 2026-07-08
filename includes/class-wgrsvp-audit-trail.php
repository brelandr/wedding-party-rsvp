<?php
/**
 * Guest row audit log: table, append-only logging, admin viewer, privacy hooks.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Audit_Trail' ) ) {

	/**
	 * Stores field-level change history for `wedding_rsvps` rows.
	 */
	class WGRSVP_Audit_Trail {

		public const DB_VERSION        = 1;
		public const OPTION_DB_VERSION = 'wgrsvp_guest_audit_db_version';

		public const PAGE_SLUG = 'wedding-rsvp-audit-log';

		/**
		 * Fully prefixed audit-trail table name.
		 *
		 * @return string
		 */
		public static function table_name() {
			global $wpdb;

			return $wpdb->prefix . 'wgrsvp_guest_audit';
		}

		/**
		 * Column names eligible for diffs (Free + common Pro columns on same table).
		 *
		 * @return array<int, string>
		 */
		public static function get_tracked_field_names() {
			$fields = array(
				'party_id',
				'guest_name',
				'age',
				'is_child',
				'rsvp_status',
				'menu_choice',
				'child_menu_choice',
				'appetizer_choice',
				'hors_doeuvre_choice',
				'dessert_choice',
				'phone',
				'email',
				'address',
				'dietary_restrictions',
				'allergies',
				'song_request',
				'guest_message',
				'admin_notes',
				'gift_received',
				'thankyou_card_sent_on',
				'table_number',
				'wgrsvp_arrived_at',
				'wpr_pro_attended_at',
				'wpr_pro_planner_tags',
				'wpr_pro_ai_note_tags',
				'wpr_pro_accessibility_flags',
				'wpr_pro_admin_notes_revision',
				'plus_one_slots',
				'guest_source',
				'sponsored_by_guest_id',
				'table_id',
			);

			return array_values( array_unique( apply_filters( 'wgrsvp_audit_trail_tracked_fields', $fields ) ) );
		}

		/**
		 * Register install and admin menu hooks.
		 *
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 19 );
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 110 );
		}

		/**
		 * Run on plugin activation.
		 *
		 * @return void
		 */
		public static function activate() {
			self::install_db();
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
		}

		/**
		 * Create or upgrade the audit table when the schema version changes.
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
		 * Run dbDelta for the audit-trail table.
		 *
		 * @return void
		 */
		private static function install_db() {
			global $wpdb;

			$table           = self::table_name();
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurred_at_gmt datetime NOT NULL,
				guest_id bigint(20) unsigned NOT NULL,
				party_id varchar(50) NOT NULL DEFAULT '',
				action varchar(20) NOT NULL,
				actor_type varchar(20) NOT NULL,
				actor_user_id bigint(20) unsigned NULL,
				source varchar(40) NOT NULL,
				changes_json longtext NULL,
				request_id char(8) NULL,
				PRIMARY KEY  (id),
				KEY guest_time (guest_id, occurred_at_gmt),
				KEY party_time (party_id(32), occurred_at_gmt),
				KEY occurred (occurred_at_gmt)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}

		/**
		 * Add the audit-log submenu page.
		 *
		 * @return void
		 */
		public static function register_admin_menu() {
			if ( ! wgrsvp_admin_module_enabled( 'audit_log' ) ) {
				return;
			}
			add_submenu_page(
				'wedding-rsvp-main',
				__( 'Audit log', 'wedding-party-rsvp' ),
				__( 'Audit log', 'wedding-party-rsvp' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_admin_page' )
			);
		}

		/**
		 * Normalize a value for comparison.
		 *
		 * @param mixed $v Value.
		 * @return string
		 */
		private static function normalize_value( $v ) {
			if ( null === $v ) {
				return '';
			}
			if ( is_bool( $v ) ) {
				return $v ? '1' : '0';
			}
			if ( is_numeric( $v ) && ! is_string( $v ) ) {
				return (string) $v;
			}

			return trim( (string) $v );
		}

		/**
		 * Build old/new map for keys present in $new_data only.
		 *
		 * @param object|null          $old_row    Prior DB row.
		 * @param array<string, mixed> $new_data   Columns being written.
		 * @return array<string, array{old:string, new:string}>
		 */
		public static function diff_assoc( $old_row, array $new_data ) {
			$tracked = array_fill_keys( self::get_tracked_field_names(), true );
			$out     = array();
			foreach ( $new_data as $key => $new_val ) {
				$key = (string) $key;
				if ( ! isset( $tracked[ $key ] ) ) {
					continue;
				}
				$old_val = '';
				if ( is_object( $old_row ) && isset( $old_row->{$key} ) ) {
					$old_val = $old_row->{$key};
				}
				$o = self::normalize_value( $old_val );
				$n = self::normalize_value( $new_val );
				if ( $o !== $n ) {
					$out[ $key ] = array(
						'old' => $o,
						'new' => $n,
					);
				}
			}

			return $out;
		}

		/**
		 * Changes map for a new row (insert).
		 *
		 * @param array<string, mixed> $new_data Inserted columns.
		 * @return array<string, array{old:string, new:string}>
		 */
		public static function diff_for_insert( array $new_data ) {
			return self::diff_assoc( null, $new_data );
		}

		/**
		 * Normalize an event source to a known key.
		 *
		 * @param string $source Raw.
		 * @return string
		 */
		private static function sanitize_source( $source ) {
			$key     = sanitize_key( (string) $source );
			$allowed = array(
				'public_form',
				'admin_inline',
				'admin_import_csv',
				'paste_import',
				'dayof_arrival',
				'demo_seed',
				'setup_wizard',
				'pro_edit_guest',
				'pro_rest',
				'pro_rest_notes',
				'pro_frontend',
				'pro_checkin',
				'pro_bulk_guest_list',
				'pro_ai_guest_tags',
				'pro_import_csv',
				'pro_rest_seating',
				'system',
			);
			return in_array( $key, $allowed, true ) ? $key : 'system';
		}

		/**
		 * Normalize an event action to a known key.
		 *
		 * @param string $action Raw.
		 * @return string
		 */
		private static function sanitize_action( $action ) {
			$key = sanitize_key( (string) $action );
			return in_array( $key, array( 'insert', 'update', 'delete' ), true ) ? $key : 'update';
		}

		/**
		 * Normalize an actor type to a known key.
		 *
		 * @param string $actor Raw.
		 * @return string
		 */
		private static function sanitize_actor_type( $actor ) {
			$key = sanitize_key( (string) $actor );
			return in_array( $key, array( 'user', 'guest', 'system' ), true ) ? $key : 'system';
		}

		/**
		 * Append one audit row.
		 *
		 * @param array<string, mixed> $args Keys: guest_id, party_id, action, actor_type, actor_user_id?, source, changes (array), request_id?.
		 * @return int 0 on skip or failure, else insert id.
		 */
		public static function log( array $args ) {
			$guest_id = isset( $args['guest_id'] ) ? absint( $args['guest_id'] ) : 0;
			$party_id = isset( $args['party_id'] ) ? sanitize_text_field( (string) $args['party_id'] ) : '';
			if ( $guest_id < 1 || '' === $party_id ) {
				return 0;
			}

			$action     = self::sanitize_action( isset( $args['action'] ) ? (string) $args['action'] : 'update' );
			$actor_type = self::sanitize_actor_type( isset( $args['actor_type'] ) ? (string) $args['actor_type'] : 'system' );
			$source     = self::sanitize_source( isset( $args['source'] ) ? (string) $args['source'] : 'system' );

			$actor_uid = null;
			if ( array_key_exists( 'actor_user_id', $args ) && null !== $args['actor_user_id'] && '' !== $args['actor_user_id'] ) {
				$actor_uid = absint( $args['actor_user_id'] );
				if ( $actor_uid < 1 ) {
					$actor_uid = null;
				}
			}

			$changes = isset( $args['changes'] ) && is_array( $args['changes'] ) ? $args['changes'] : array();
			$changes = apply_filters(
				'wgrsvp_audit_trail_changes',
				$changes,
				array(
					'guest_id'   => $guest_id,
					'party_id'   => $party_id,
					'action'     => $action,
					'actor_type' => $actor_type,
					'source'     => $source,
				)
			);
			if ( ! is_array( $changes ) ) {
				$changes = array();
			}

			$context = array(
				'guest_id'   => $guest_id,
				'party_id'   => $party_id,
				'action'     => $action,
				'actor_type' => $actor_type,
				'source'     => $source,
			);

			if ( 'update' === $action && empty( $changes ) ) {
				return 0;
			}

			if ( ! apply_filters( 'wgrsvp_audit_trail_should_log', true, $context ) ) {
				return 0;
			}

			$request_id = isset( $args['request_id'] ) ? sanitize_text_field( (string) $args['request_id'] ) : '';
			if ( strlen( $request_id ) > 8 ) {
				$request_id = substr( $request_id, 0, 8 );
			}
			if ( '' === $request_id ) {
				$request_id = null;
			}

			$json = wp_json_encode( $changes );
			if ( false === $json ) {
				$json = '{}';
			}

			global $wpdb;
			$table = self::table_name();
			$now   = current_time( 'mysql', true );

			if ( null === $actor_uid && null === $request_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- %i table name; values via placeholders.
				$ok = $wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (occurred_at_gmt, guest_id, party_id, action, actor_type, actor_user_id, source, changes_json, request_id) VALUES (%s, %d, %s, %s, %s, NULL, %s, %s, NULL)',
						$table,
						$now,
						$guest_id,
						$party_id,
						$action,
						$actor_type,
						$source,
						$json
					)
				);
			} elseif ( null === $actor_uid ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$ok = $wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (occurred_at_gmt, guest_id, party_id, action, actor_type, actor_user_id, source, changes_json, request_id) VALUES (%s, %d, %s, %s, %s, NULL, %s, %s, %s)',
						$table,
						$now,
						$guest_id,
						$party_id,
						$action,
						$actor_type,
						$source,
						$json,
						$request_id
					)
				);
			} elseif ( null === $request_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$ok = $wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (occurred_at_gmt, guest_id, party_id, action, actor_type, actor_user_id, source, changes_json, request_id) VALUES (%s, %d, %s, %s, %s, %d, %s, %s, NULL)',
						$table,
						$now,
						$guest_id,
						$party_id,
						$action,
						$actor_type,
						$actor_uid,
						$source,
						$json
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$ok = $wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (occurred_at_gmt, guest_id, party_id, action, actor_type, actor_user_id, source, changes_json, request_id) VALUES (%s, %d, %s, %s, %s, %d, %s, %s, %s)',
						$table,
						$now,
						$guest_id,
						$party_id,
						$action,
						$actor_type,
						$actor_uid,
						$source,
						$json,
						$request_id
					)
				);
			}

			if ( false === $ok ) {
				return 0;
			}

			$audit_id = (int) $wpdb->insert_id;
			if ( $audit_id > 0 ) {
				/**
				 * After a row is written to the guest audit log.
				 *
				 * @param int   $audit_id New audit row id.
				 * @param array $context  guest_id, party_id, action, actor_type, source.
				 */
				do_action( 'wgrsvp_guest_audit_logged', $audit_id, $context );
			}

			return $audit_id;
		}

		/**
		 * Remove audit rows for guest ids (privacy erase).
		 *
		 * @param array<int, int> $guest_ids Guest row ids.
		 * @return void
		 */
		public static function delete_for_guest_ids( array $guest_ids ) {
			$guest_ids = array_filter( array_map( 'absint', $guest_ids ) );
			if ( empty( $guest_ids ) ) {
				return;
			}
			global $wpdb;
			$table     = self::table_name();
			$guest_ids = array_values( array_unique( $guest_ids ) );
			$place     = implode( ',', array_fill( 0, count( $guest_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- %i + IN placeholders; IDs spread.
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE guest_id IN (' . $place . ')',
					...array_merge( array( $table ), $guest_ids )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		/**
		 * Empty audit log (factory reset).
		 *
		 * @return void
		 */
		public static function truncate_table() {
			global $wpdb;
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Full table reset; identifier via %i (WP 6.2+; plugin requires 6.2).
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		}

		/**
		 * Admin list UI.
		 *
		 * @return void
		 */
		public static function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wedding-party-rsvp' ) );
			}

			wgrsvp_require_admin_module_or_die( 'audit_log' );

			global $wpdb;

			$per_page = 50;
			$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$filter_guest = isset( $_GET['wgrsvp_audit_guest'] ) ? absint( wp_unslash( (string) $_GET['wgrsvp_audit_guest'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter_party = isset( $_GET['wgrsvp_audit_party'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_audit_party'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter_src   = isset( $_GET['wgrsvp_audit_src'] ) ? sanitize_key( wp_unslash( (string) $_GET['wgrsvp_audit_src'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter_act   = isset( $_GET['wgrsvp_audit_act'] ) ? sanitize_key( wp_unslash( (string) $_GET['wgrsvp_audit_act'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$date_from    = isset( $_GET['wgrsvp_audit_from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_audit_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$date_to      = isset( $_GET['wgrsvp_audit_to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wgrsvp_audit_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$table = self::table_name();
			$where = array( '1=1' );
			$prep  = array();

			if ( $filter_guest > 0 ) {
				$where[] = 'guest_id = %d';
				$prep[]  = $filter_guest;
			}
			if ( '' !== $filter_party ) {
				$where[] = 'party_id = %s';
				$prep[]  = $filter_party;
			}
			if ( '' !== $filter_src ) {
				$where[] = 'source = %s';
				$prep[]  = $filter_src;
			}
			if ( '' !== $filter_act && in_array( $filter_act, array( 'insert', 'update', 'delete' ), true ) ) {
				$where[] = 'action = %s';
				$prep[]  = $filter_act;
			}
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
				$where[] = 'occurred_at_gmt >= %s';
				$prep[]  = $date_from . ' 00:00:00';
			}
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
				$where[] = 'occurred_at_gmt <= %s';
				$prep[]  = $date_to . ' 23:59:59';
			}

			$sql_where = implode( ' AND ', $where );
			$offset    = ( $paged - 1 ) * $per_page;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Audit admin list: WHERE built from allowlisted fragments + bound values.
			if ( empty( $prep ) ) {
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE ' . $sql_where,
						$table
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE ' . $sql_where . ' ORDER BY occurred_at_gmt DESC, id DESC LIMIT %d OFFSET %d',
						$table,
						$per_page,
						$offset
					)
				);
			} else {
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE ' . $sql_where,
						...array_merge( array( $table ), $prep )
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE ' . $sql_where . ' ORDER BY occurred_at_gmt DESC, id DESC LIMIT %d OFFSET %d',
						...array_merge( array( $table ), $prep, array( $per_page, $offset ) )
					)
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}

			$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Guest audit log', 'wedding-party-rsvp' ); ?></h1>
				<p class="description"><?php esc_html_e( 'History of inserts, updates, and deletes on guest rows. May include personal data from submitted changes.', 'wedding-party-rsvp' ); ?></p>

				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<label><?php esc_html_e( 'Guest ID', 'wedding-party-rsvp' ); ?><br>
						<input type="number" name="wgrsvp_audit_guest" value="<?php echo esc_attr( $filter_guest > 0 ? (string) $filter_guest : '' ); ?>" class="small-text" min="0"></label>
					<label><?php esc_html_e( 'Party ID', 'wedding-party-rsvp' ); ?><br>
						<input type="text" name="wgrsvp_audit_party" value="<?php echo esc_attr( $filter_party ); ?>" class="regular-text"></label>
					<label><?php esc_html_e( 'Source', 'wedding-party-rsvp' ); ?><br>
						<input type="text" name="wgrsvp_audit_src" value="<?php echo esc_attr( $filter_src ); ?>" class="regular-text" placeholder="e.g. public_form"></label>
					<label><?php esc_html_e( 'Action', 'wedding-party-rsvp' ); ?><br>
						<select name="wgrsvp_audit_act">
							<option value=""><?php esc_html_e( 'Any', 'wedding-party-rsvp' ); ?></option>
							<option value="insert" <?php selected( $filter_act, 'insert' ); ?>><?php esc_html_e( 'insert', 'wedding-party-rsvp' ); ?></option>
							<option value="update" <?php selected( $filter_act, 'update' ); ?>><?php esc_html_e( 'update', 'wedding-party-rsvp' ); ?></option>
							<option value="delete" <?php selected( $filter_act, 'delete' ); ?>><?php esc_html_e( 'delete', 'wedding-party-rsvp' ); ?></option>
						</select></label>
					<label><?php esc_html_e( 'From (UTC date)', 'wedding-party-rsvp' ); ?><br>
						<input type="text" name="wgrsvp_audit_from" value="<?php echo esc_attr( $date_from ); ?>" placeholder="YYYY-MM-DD" class="regular-text"></label>
					<label><?php esc_html_e( 'To (UTC date)', 'wedding-party-rsvp' ); ?><br>
						<input type="text" name="wgrsvp_audit_to" value="<?php echo esc_attr( $date_to ); ?>" placeholder="YYYY-MM-DD" class="regular-text"></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'wedding-party-rsvp' ); ?></button>
					<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'wedding-party-rsvp' ); ?></a>
				</form>

				<p>
				<?php
				printf(
					/* translators: %d: total matching rows */
					esc_html__( 'Total matching entries: %d', 'wedding-party-rsvp' ),
					(int) $total
				);
				?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time (UTC)', 'wedding-party-rsvp' ); ?></th>
							<th><?php esc_html_e( 'Guest', 'wedding-party-rsvp' ); ?></th>
							<th><?php esc_html_e( 'Party', 'wedding-party-rsvp' ); ?></th>
							<th><?php esc_html_e( 'Action', 'wedding-party-rsvp' ); ?></th>
							<th><?php esc_html_e( 'Source', 'wedding-party-rsvp' ); ?></th>
							<th><?php esc_html_e( 'Actor', 'wedding-party-rsvp' ); ?></th>
							<th><?php esc_html_e( 'Changes', 'wedding-party-rsvp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No entries found.', 'wedding-party-rsvp' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $r ) : ?>
								<?php
								$actor_label = __( 'Guest', 'wedding-party-rsvp' );
								if ( 'user' === $r->actor_type && ! empty( $r->actor_user_id ) ) {
									$u           = get_userdata( (int) $r->actor_user_id );
									$actor_label = $u ? $u->display_name : (string) $r->actor_user_id;
								} elseif ( 'system' === $r->actor_type ) {
									$actor_label = __( 'System', 'wedding-party-rsvp' );
								}
								$changes_preview = $r->changes_json;
								if ( is_string( $changes_preview ) && strlen( $changes_preview ) > 200 ) {
									$changes_preview = substr( $changes_preview, 0, 200 ) . '…';
								}
								?>
								<tr>
									<td><?php echo esc_html( (string) $r->occurred_at_gmt ); ?></td>
									<td><?php echo esc_html( (string) $r->guest_id ); ?></td>
									<td><?php echo esc_html( (string) $r->party_id ); ?></td>
									<td><?php echo esc_html( (string) $r->action ); ?></td>
									<td><?php echo esc_html( (string) $r->source ); ?></td>
									<td><?php echo esc_html( (string) $actor_label ); ?></td>
									<td><code style="white-space:pre-wrap;word-break:break-all;"><?php echo esc_html( (string) $changes_preview ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php
				$total_pages = (int) ceil( $total / $per_page );
				if ( $total_pages > 1 ) {
					$link_args = array( 'page' => self::PAGE_SLUG );
					if ( $filter_guest > 0 ) {
						$link_args['wgrsvp_audit_guest'] = $filter_guest;
					}
					if ( '' !== $filter_party ) {
						$link_args['wgrsvp_audit_party'] = $filter_party;
					}
					if ( '' !== $filter_src ) {
						$link_args['wgrsvp_audit_src'] = $filter_src;
					}
					if ( '' !== $filter_act ) {
						$link_args['wgrsvp_audit_act'] = $filter_act;
					}
					if ( '' !== $date_from ) {
						$link_args['wgrsvp_audit_from'] = $date_from;
					}
					if ( '' !== $date_to ) {
						$link_args['wgrsvp_audit_to'] = $date_to;
					}
					echo '<div class="tablenav"><div class="tablenav-pages">';
					for ( $i = 1; $i <= $total_pages; $i++ ) {
						$link_args['paged'] = $i;
						$url                = add_query_arg( $link_args, admin_url( 'admin.php' ) );
						if ( $i === $paged ) {
							echo ' <strong>' . esc_html( (string) $i ) . '</strong> ';
						} else {
							echo ' <a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
						}
					}
					echo '</div></div>';
				}
				?>
			</div>
			<?php
		}
	}
}
