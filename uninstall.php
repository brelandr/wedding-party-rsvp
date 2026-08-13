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

$wgrsvp_drip_state_table = $wpdb->prefix . 'wgrsvp_drip_state';
$wgrsvp_drip_sends_table = $wpdb->prefix . 'wgrsvp_drip_sends';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall DROP; table from trusted $wpdb->prefix + fixed slug.
$wpdb->query( "DROP TABLE IF EXISTS `{$wgrsvp_drip_state_table}`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall DROP; table from trusted $wpdb->prefix + fixed slug.
$wpdb->query( "DROP TABLE IF EXISTS `{$wgrsvp_drip_sends_table}`" );

delete_option( 'wgrsvp_drip_journey' );
delete_option( 'wgrsvp_drip_db_version' );

$wgrsvp_guestbook_table = $wpdb->prefix . 'wgrsvp_guestbook';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall DROP; table from trusted $wpdb->prefix + fixed slug.
$wpdb->query( "DROP TABLE IF EXISTS `{$wgrsvp_guestbook_table}`" );
delete_option( 'wgrsvp_guestbook_db_version' );
delete_option( 'wgrsvp_guestbook_captcha' );
delete_option( 'wgrsvp_travel_settings' );

wp_clear_scheduled_hook( 'wgrsvp_drip_tick' );
