<?php
/**
 * Server render callback for the RSVP block: expands a registered shortcode.
 *
 * RSVP submission nonces and validation live in the main plugin shortcode / AJAX handlers, not here.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters which shortcode tag the block outputs (must match a tag registered with add_shortcode()).
 *
 * @since 7.3.35
 *
 * @param string $shortcode_tag Default shortcode name.
 */
$wgrsvp_rsvp_block_shortcode_tag = apply_filters( 'wgrsvp_rsvp_form_block_shortcode_tag', 'wedding_rsvp_form' );
if ( ! is_string( $wgrsvp_rsvp_block_shortcode_tag ) ) {
	$wgrsvp_rsvp_block_shortcode_tag = 'wedding_rsvp_form';
} else {
	$wgrsvp_rsvp_block_shortcode_tag = sanitize_key( $wgrsvp_rsvp_block_shortcode_tag );
}
if ( '' === $wgrsvp_rsvp_block_shortcode_tag ) {
	$wgrsvp_rsvp_block_shortcode_tag = 'wedding_rsvp_form';
}

/**
 * Fires immediately before the RSVP block shortcode output is echoed.
 *
 * @since 7.3.35
 */
do_action( 'wgrsvp_before_rsvp_block_render' );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is already escaped by the plugin renderer.
echo do_shortcode( '[' . $wgrsvp_rsvp_block_shortcode_tag . ']' );

/**
 * Fires immediately after the RSVP block shortcode output is echoed.
 *
 * @since 7.3.35
 */
do_action( 'wgrsvp_after_rsvp_block_render' );
