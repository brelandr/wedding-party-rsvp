<?php
/**
 * Gift registry links for the public RSVP form (stored in wgrsvp_general_settings).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize, read, and render gift registry rows (label + URL).
 *
 * @since 8.0.3
 */
class WGRSVP_Gift_Registries {

	/**
	 * Maximum registry rows saved and displayed.
	 *
	 * @var int
	 */
	public const MAX_ITEMS = 15;

	/**
	 * POST field: label column (array of strings).
	 */
	public const POST_LABEL_KEY = 'wgrsvp_gift_registry_label';

	/**
	 * POST field: URL column (array of strings).
	 */
	public const POST_URL_KEY = 'wgrsvp_gift_registry_url';

	/**
	 * Parse parallel POST arrays into a clean list of registries.
	 *
	 * @param array<string,mixed> $post Typically wp_unslash( $_POST ) from admin save handlers.
	 * @return array<int, array{label: string, url: string}>
	 */
	public static function sanitize_from_request( array $post ) {
		$labels = isset( $post[ self::POST_LABEL_KEY ] ) ? $post[ self::POST_LABEL_KEY ] : array();
		$urls   = isset( $post[ self::POST_URL_KEY ] ) ? $post[ self::POST_URL_KEY ] : array();
		if ( ! is_array( $labels ) ) {
			$labels = array();
		} else {
			$labels = map_deep( wp_unslash( $labels ), 'sanitize_text_field' );
		}
		if ( ! is_array( $urls ) ) {
			$urls = array();
		} else {
			$urls = map_deep( wp_unslash( $urls ), 'sanitize_text_field' );
		}
		$count = min( max( count( $labels ), count( $urls ) ), self::MAX_ITEMS );
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$label = isset( $labels[ $i ] ) ? (string) $labels[ $i ] : '';
			$url   = isset( $urls[ $i ] ) ? esc_url_raw( trim( (string) $urls[ $i ] ), array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				continue;
			}
			if ( '' === $label ) {
				$label = __( 'Gift registry', 'wedding-party-rsvp' );
			}
			$out[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}

		return $out;
	}

	/**
	 * Build display items from stored settings (re-sanitized).
	 *
	 * @param array<string, mixed> $settings wgrsvp_general_settings row.
	 * @return array<int, array{label: string, url: string}>
	 */
	public static function get_items( array $settings ) {
		$raw = isset( $settings['gift_registries'] ) ? $settings['gift_registries'] : array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ), array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			if ( '' === $label ) {
				$label = __( 'Gift registry', 'wedding-party-rsvp' );
			}
			$out[] = array(
				'label' => $label,
				'url'   => $url,
			);
			if ( count( $out ) >= self::MAX_ITEMS ) {
				break;
			}
		}

		/**
		 * Filter gift registry items before rendering.
		 *
		 * @since 8.0.3
		 *
		 * @param array<int, array{label: string, url: string}> $out      Items.
		 * @param array<string, mixed>                         $settings General settings option.
		 */
		return apply_filters( 'wgrsvp_gift_registry_items', $out, $settings );
	}

	/**
	 * HTML block for the RSVP form (empty string if no items).
	 *
	 * @param array<string, mixed> $settings wgrsvp_general_settings.
	 * @return string
	 */
	public static function render( array $settings ) {
		$items = self::get_items( $settings );
		if ( empty( $items ) ) {
			return '';
		}
		$heading = isset( $settings['gift_registry_heading'] ) ? sanitize_text_field( (string) $settings['gift_registry_heading'] ) : '';
		if ( '' === $heading ) {
			$heading = __( 'Gift registries', 'wedding-party-rsvp' );
		}
		$html  = '<div class="wgrsvp-gift-registries">';
		$html .= '<h3 class="wgrsvp-gift-registries__heading">' . esc_html( $heading ) . '</h3>';
		$html .= '<ul class="wgrsvp-gift-registries__list">';
		foreach ( $items as $item ) {
			$html .= '<li><a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['label'] ) . '</a></li>';
		}
		$html .= '</ul></div>';

		return $html;
	}
}
