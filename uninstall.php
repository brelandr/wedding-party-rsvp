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
delete_option( 'wgrsvp_app_connect_rewrite_flushed' );
delete_option( 'wgrsvp_app_primary_color' );
delete_option( 'wgrsvp_app_secondary_color' );
delete_option( 'wgrsvp_app_logo_url' );
delete_option( 'wgrsvp_app_min_version' );
delete_option( 'wgrsvp_app_min_ios_build' );
delete_option( 'wgrsvp_app_min_android_version_code' );
delete_option( 'wgrsvp_network_status' );
delete_option( 'wgrsvp_wedding_party_name' );
delete_option( 'wgrsvp_wedding_partner_1' );
delete_option( 'wgrsvp_wedding_partner_2' );
delete_option( 'wgrsvp_wedding_city' );
delete_option( 'wgrsvp_wedding_state' );
delete_option( 'wgrsvp_wedding_zip' );

wp_clear_scheduled_hook( 'wgrsvp_drip_tick' );
