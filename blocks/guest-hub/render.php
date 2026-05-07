<?php
/**
 * Dynamic block: Guest Hub (shortcode equivalent).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped in the renderer.
echo do_shortcode( '[wgrsvp_guest_hub]' );
