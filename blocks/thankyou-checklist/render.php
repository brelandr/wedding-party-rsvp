<?php
/**
 * Dynamic block: thank-you checklist (shortcode equivalent).
 *
 * @package Wedding_Party_RSVP
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $attributes is injected by WordPress for dynamic block render templates.
$attributes = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();
$public     = ! empty( $attributes['public'] ) ? '1' : '0';
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped by the renderer.
echo do_shortcode( '[wgrsvp_thankyou_tracker public="' . esc_attr( $public ) . '"]' );
