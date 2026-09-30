<?php
/**
 * wp-env seed: public RSVP / guestbook pages, guest rows, and auto-approve notes.
 *
 * Loaded only in the local e2e environment via .wp-env.json mappings.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approve guestbook notes immediately so e2e can assert the public wall.
 *
 * @param string $status Incoming status.
 * @return string
 */
function wgrsvp_e2e_auto_approve_guestbook( $status ) {
	unset( $status );
	return 'approved';
}
add_filter( 'wgrsvp_guestbook_entry_status', 'wgrsvp_e2e_auto_approve_guestbook' );

/**
 * Create pages, settings, and fixture guests once the plugin tables exist.
 *
 * @return void
 */
function wgrsvp_e2e_seed_site() {
	if ( get_option( 'wgrsvp_e2e_seeded' ) ) {
		return;
	}
	if ( ! class_exists( 'WGRSVP_Wedding_RSVP', false ) ) {
		return;
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'wedding_rsvps';
	$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $table !== $exists ) {
		return;
	}

	update_option( 'wgrsvp_setup_wizard_done', '1' );
	delete_transient( 'wedding-party-rsvp_activation_redirect_wizard' );

	update_option( 'permalink_structure', '/%postname%/' );
	flush_rewrite_rules();

	$rsvp_id = wp_insert_post(
		array(
			'post_title'   => 'RSVP',
			'post_name'    => 'rsvp',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- wp:shortcode -->[wedding_rsvp_form]<!-- /wp:shortcode -->',
		),
		true
	);
	if ( is_wp_error( $rsvp_id ) ) {
		return;
	}

	$gb_id = wp_insert_post(
		array(
			'post_title'   => 'Guestbook',
			'post_name'    => 'guestbook',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- wp:shortcode -->[wgrsvp_guestbook]<!-- /wp:shortcode -->',
		),
		true
	);
	if ( is_wp_error( $gb_id ) ) {
		return;
	}

	$settings = get_option( 'wgrsvp_general_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	$settings['rsvp_page_url'] = (string) get_permalink( (int) $rsvp_id );
	update_option( 'wgrsvp_general_settings', $settings );

	update_option( 'wgrsvp_menu_options', array( 'Chicken', 'Fish', 'Vegetarian' ) );

	$wpdb->insert(
		$table,
		array(
			'party_id'    => 'E2E-PARTY',
			'guest_name'  => 'E2E Playwright Guest',
			'rsvp_status' => 'Pending',
			'email'       => 'e2e-guest@example.com',
		)
	);

	$i = 1;
	while ( $i <= 30 ) {
		$wpdb->insert(
			$table,
			array(
				'party_id'    => sprintf( 'E2E-%02d', $i ),
				'guest_name'  => sprintf( 'E2E List Guest %02d', $i ),
				'rsvp_status' => ( 0 === $i % 3 ) ? 'Accepted' : 'Pending',
				'email'       => sprintf( 'e2e-list-%02d@example.com', $i ),
			)
		);
		++$i;
	}

	$wpdb->insert(
		$table,
		array(
			'party_id'    => 'AAA-01',
			'guest_name'  => 'AAA E2E First',
			'rsvp_status' => 'Pending',
		)
	);
	$wpdb->insert(
		$table,
		array(
			'party_id'    => 'ZZZ-99',
			'guest_name'  => 'ZZZ E2E Last',
			'rsvp_status' => 'Pending',
		)
	);

	update_option( 'wgrsvp_e2e_seeded', '1', false );
}
add_action( 'init', 'wgrsvp_e2e_seed_site', 30 );
