<?php
/**
 * Uninstall cleanup for Wedding Party RSVP.
 *
 * Drops multi-step drip tables/options. Guest list data is removed via Settings → Erase all data
 * (or Pro Danger Zone); this file runs only when the plugin is deleted from WordPress.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$state = $wpdb->prefix . 'wgrsvp_drip_state';
$sends = $wpdb->prefix . 'wgrsvp_drip_sends';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall DROP; prefix from $wpdb.
$wpdb->query( "DROP TABLE IF EXISTS `{$state}`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$sends}`" );

delete_option( 'wgrsvp_drip_journey' );
delete_option( 'wgrsvp_drip_db_version' );

wp_clear_scheduled_hook( 'wgrsvp_drip_tick' );
