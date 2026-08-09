<?php
/**
 * Dynamic block: Guest Hub (shortcode equivalent).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hub markup is escaped in the shortcode renderer (not safe to wrap the whole tree in wp_kses_post).
echo do_shortcode( '[wgrsvp_guest_hub]' );
