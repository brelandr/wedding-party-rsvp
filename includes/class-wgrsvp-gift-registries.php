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
	 * Default Amazon Associates tracking ID (plugin author's store ID).
	 *
	 * Applied ONLY on sites hosted under HOSTED_NETWORK_DOMAIN (properties the
	 * plugin author owns and operates, per the Amazon Associates Operating
	 * Agreement). On self-hosted installs, tagging stays inactive until the
	 * site owner enters their own tracking ID.
	 *
	 * @since 8.1.0
	 */
	public const DEFAULT_AMAZON_TAG = 'weddingrsvp-20';

	/**
	 * Hosted-network root domain where the default tag may be used.
	 *
	 * @since 8.1.0
	 */
	public const HOSTED_NETWORK_DOMAIN = 'weddingrsvp.pro';

	/**
	 * Default Skimlinks publisher/site ID (plugin author's account).
	 *
	 * Applied ONLY on sites hosted under HOSTED_NETWORK_DOMAIN (properties the
	 * plugin author owns, operates, and has registered with Skimlinks). On
	 * self-hosted installs, Skimlinks wrapping stays inactive until the site
	 * owner enters their own publisher ID (Skimlinks requires each domain to
	 * be registered to the earning account).
	 *
	 * @since 8.3.0
	 */
	public const DEFAULT_SKIMLINKS_ID = '307395X1795678';

	/**
	 * Skimlinks manual-link redirect endpoint (no remote JavaScript involved).
	 *
	 * @since 8.3.0
	 */
	public const SKIMLINKS_REDIRECT_BASE = 'https://go.skimresources.com/';

	/**
	 * Whether this install runs on the plugin author's hosted network
	 * (weddingrsvp.pro or any subdomain, e.g. johnandmary.weddingrsvp.pro).
	 *
	 * @since 8.1.0
	 * @return bool
	 */
	public static function is_hosted_network_site() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );
		return self::HOSTED_NETWORK_DOMAIN === $host
			|| '.' . self::HOSTED_NETWORK_DOMAIN === substr( $host, -( strlen( self::HOSTED_NETWORK_DOMAIN ) + 1 ) );
	}

	/**
	 * Sanitize an Amazon Associates tracking ID (e.g. "mysite-20").
	 *
	 * @since 8.1.0
	 * @param string $raw Raw tag input.
	 * @return string Sanitized tag (letters, digits, hyphens) or ''.
	 */
	public static function sanitize_amazon_tag( $raw ) {
		$tag = strtolower( trim( (string) $raw ) );
		$tag = preg_replace( '/[^a-z0-9\-]/', '', $tag );
		return is_string( $tag ) ? substr( $tag, 0, 60 ) : '';
	}

	/**
	 * Active Amazon Associates tag from settings, or '' when the feature is off.
	 *
	 * Opt-in by design: both the enable flag and a non-empty tag are required
	 * (WordPress.org guideline 10 — affiliate links must be explicit opt-in).
	 *
	 * @since 8.1.0
	 * @param array<string, mixed> $settings Settings array (free or Pro).
	 * @return string
	 */
	public static function get_active_amazon_tag( array $settings ) {
		if ( empty( $settings['amazon_affiliate_enabled'] ) ) {
			return '';
		}
		$tag = isset( $settings['amazon_affiliate_tag'] ) ? self::sanitize_amazon_tag( (string) $settings['amazon_affiliate_tag'] ) : '';
		if ( '' === $tag && self::is_hosted_network_site() ) {
			// Author-owned hosted sites only; self-hosted installs need their own ID.
			$tag = self::DEFAULT_AMAZON_TAG;
		}

		/**
		 * Filter the active Amazon Associates tag before it is appended to links.
		 *
		 * @since 8.1.0
		 * @param string               $tag      Sanitized tag or ''.
		 * @param array<string, mixed> $settings Settings array.
		 */
		return (string) apply_filters( 'wgrsvp_amazon_affiliate_tag', $tag, $settings );
	}

	/**
	 * Whether a URL points at an Amazon retail site.
	 *
	 * @since 8.1.0
	 * @param string $url Registry URL.
	 * @return bool
	 */
	public static function is_amazon_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );
		// Strip common subdomains (www., smile., m.).
		$host = preg_replace( '/^(www|smile|m)\./', '', $host );
		// amazon.com, amazon.co.uk, amazon.com.au, amazon.de, etc.
		return (bool) preg_match( '/^amazon\.[a-z]{2,3}(\.[a-z]{2})?$/', (string) $host );
	}

	/**
	 * Append the Associates tag to an Amazon URL (existing tag params win).
	 *
	 * @since 8.1.0
	 * @param string $url Registry URL.
	 * @param string $tag Sanitized Associates tag.
	 * @return string URL with tag query arg when applicable.
	 */
	public static function maybe_add_amazon_tag( $url, $tag ) {
		if ( '' === $tag || ! self::is_amazon_url( $url ) ) {
			return $url;
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			$args = array();
			wp_parse_str( $query, $args );
			if ( ! empty( $args['tag'] ) ) {
				return $url; // A tag is already present; do not overwrite.
			}
		}
		return add_query_arg( 'tag', rawurlencode( $tag ), $url );
	}

	/**
	 * Sanitize a Skimlinks publisher/site ID (e.g. "307395X1795678").
	 *
	 * @since 8.3.0
	 * @param string $raw Raw ID input.
	 * @return string Sanitized ID (digits and X) or ''.
	 */
	public static function sanitize_skimlinks_id( $raw ) {
		$id = strtoupper( trim( (string) $raw ) );
		$id = preg_replace( '/[^0-9X]/', '', $id );
		if ( ! is_string( $id ) || ! preg_match( '/^[0-9]+X[0-9]+$/', $id ) ) {
			return '';
		}
		return substr( $id, 0, 40 );
	}

	/**
	 * Active Skimlinks publisher ID from settings, or '' when the feature is off.
	 *
	 * Opt-in by design: both the enable flag and a non-empty publisher ID are
	 * required (WordPress.org guideline 10 — affiliate links must be explicit
	 * opt-in). The author's ID is used only on the hosted network.
	 *
	 * @since 8.3.0
	 * @param array<string, mixed> $settings Settings array (free or Pro).
	 * @return string
	 */
	public static function get_active_skimlinks_id( array $settings ) {
		if ( empty( $settings['skimlinks_enabled'] ) ) {
			return '';
		}
		$id = isset( $settings['skimlinks_publisher_id'] ) ? self::sanitize_skimlinks_id( (string) $settings['skimlinks_publisher_id'] ) : '';
		if ( '' === $id && self::is_hosted_network_site() ) {
			// Author-owned hosted sites only; self-hosted installs need their own ID.
			$id = self::DEFAULT_SKIMLINKS_ID;
		}

		/**
		 * Filter the active Skimlinks publisher ID before links are wrapped.
		 *
		 * @since 8.3.0
		 * @param string               $id       Sanitized publisher ID or ''.
		 * @param array<string, mixed> $settings Settings array.
		 */
		return (string) apply_filters( 'wgrsvp_skimlinks_publisher_id', $id, $settings );
	}

	/**
	 * Whether a URL is already a Skimlinks redirect.
	 *
	 * @since 8.3.0
	 * @param string $url Registry URL.
	 * @return bool
	 */
	public static function is_skimlinks_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );
		return 'go.skimresources.com' === $host || 'go.redirectingat.com' === $host;
	}

	/**
	 * Wrap a non-Amazon registry URL in the Skimlinks manual redirect.
	 *
	 * Uses the documented go.skimresources.com link format — no remote
	 * JavaScript is loaded. Amazon URLs are left to the Amazon Associates
	 * tagging path (Amazon is not part of the Skimlinks merchant network),
	 * and already-wrapped URLs are returned unchanged.
	 *
	 * @since 8.3.0
	 * @param string $url Registry URL.
	 * @param string $id  Sanitized Skimlinks publisher ID.
	 * @return string Wrapped URL, or the original URL when not applicable.
	 */
	public static function maybe_wrap_skimlinks( $url, $id ) {
		if ( '' === $id || '' === $url ) {
			return $url;
		}
		if ( self::is_amazon_url( $url ) || self::is_skimlinks_url( $url ) ) {
			return $url;
		}
		return add_query_arg(
			array(
				'id'  => rawurlencode( $id ),
				'xs'  => '1',
				'url' => rawurlencode( $url ),
			),
			self::SKIMLINKS_REDIRECT_BASE
		);
	}

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
		$html  = '';
		if ( ! empty( $items ) ) {
			$heading = isset( $settings['gift_registry_heading'] ) ? sanitize_text_field( (string) $settings['gift_registry_heading'] ) : '';
			if ( '' === $heading ) {
				$heading = __( 'Gift registries', 'wedding-party-rsvp' );
			}
			$amazon_tag = self::get_active_amazon_tag( $settings );
			$skim_id    = self::get_active_skimlinks_id( $settings );
			$has_amazon = false;
			$has_skim   = false;

			$html  = '<div class="wgrsvp-gift-registries">';
			$html .= '<h3 class="wgrsvp-gift-registries__heading">' . esc_html( $heading ) . '</h3>';
			$html .= '<ul class="wgrsvp-gift-registries__list">';
			foreach ( $items as $item ) {
				$url = $item['url'];
				if ( '' !== $amazon_tag && self::is_amazon_url( $url ) ) {
					$has_amazon = true;
					$url        = self::maybe_add_amazon_tag( $url, $amazon_tag );
					// rel="sponsored" per Amazon Associates / search-engine affiliate link guidance.
					$html .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer sponsored">' . esc_html( $item['label'] ) . '</a></li>';
				} elseif ( '' !== $skim_id && ! self::is_amazon_url( $url ) ) {
					$wrapped = self::maybe_wrap_skimlinks( $url, $skim_id );
					if ( $wrapped !== $url ) {
						$has_skim = true;
						// rel="sponsored" per affiliate-link / search-engine guidance.
						$html .= '<li><a href="' . esc_url( $wrapped ) . '" target="_blank" rel="noopener noreferrer sponsored">' . esc_html( $item['label'] ) . '</a></li>';
					} else {
						$html .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['label'] ) . '</a></li>';
					}
				} else {
					$html .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['label'] ) . '</a></li>';
				}
			}
			$html .= '</ul>';
			if ( $has_amazon ) {
				/**
				 * Filter the Amazon Associates disclosure text shown under registry links.
				 *
				 * Required by the Amazon Associates Operating Agreement; do not remove
				 * the disclosure via this filter while the tag is active.
				 *
				 * @since 8.1.0
				 * @param string $text Disclosure sentence.
				 */
				$disclosure = (string) apply_filters(
					'wgrsvp_amazon_disclosure_text',
					__( 'As an Amazon Associate I earn from qualifying purchases.', 'wedding-party-rsvp' )
				);
				if ( '' !== $disclosure ) {
					$html .= '<p class="wgrsvp-gift-registries__disclosure" style="font-size:0.85em;opacity:0.8;margin-top:6px;">' . esc_html( $disclosure ) . '</p>';
				}
			}
			if ( $has_skim ) {
				/**
				 * Filter the affiliate disclosure text shown when Skimlinks wrapping is active.
				 *
				 * Affiliate disclosure is required by the Skimlinks publisher terms and
				 * the FTC; do not remove the disclosure via this filter while wrapping
				 * is active.
				 *
				 * @since 8.3.0
				 * @param string $text Disclosure sentence.
				 */
				$skim_disclosure = (string) apply_filters(
					'wgrsvp_skimlinks_disclosure_text',
					__( 'Some links on this page are affiliate links; we may earn a commission on qualifying purchases at no extra cost to you.', 'wedding-party-rsvp' )
				);
				if ( '' !== $skim_disclosure ) {
					$html .= '<p class="wgrsvp-gift-registries__disclosure wgrsvp-gift-registries__disclosure--skimlinks" style="font-size:0.85em;opacity:0.8;margin-top:6px;">' . esc_html( $skim_disclosure ) . '</p>';
				}
			}
			$html .= '</div>';
		}

		/**
		 * Filter gift-area HTML after registries (Pro may append cash-tier labels / wish list).
		 *
		 * @since 8.0.9
		 * @param string               $html     Registries block (may be empty).
		 * @param array<string, mixed> $settings General settings.
		 */
		return self::kses_gift_area_html( (string) apply_filters( 'wgrsvp_after_gift_registries_html', $html, $settings ) );
	}

	/**
	 * Allowed tags for gift-area HTML (registries + Pro wish list / cash fund).
	 *
	 * Broader than wp_kses_post so Pro can append buttons, inputs, and data-* hooks.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function get_gift_area_allowed_html() {
		$allowed = array(
			'div'    => array(
				'class'              => true,
				'id'                 => true,
				'style'              => true,
				'role'               => true,
				'aria-live'          => true,
				'aria-hidden'        => true,
				'data-wpr-pro-items' => true,
				'data-guest-id'      => true,
			),
			'ul'     => array(
				'class' => true,
			),
			'li'     => array(
				'class'        => true,
				'data-item-id' => true,
				'data-status'  => true,
			),
			'a'      => array(
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			),
			'h3'     => array(
				'class' => true,
			),
			'p'      => array(
				'class'       => true,
				'style'       => true,
				'role'        => true,
				'aria-live'   => true,
				'aria-hidden' => true,
			),
			'span'   => array(
				'class' => true,
			),
			'label'  => array(
				'for'   => true,
				'class' => true,
			),
			'input'  => array(
				'type'         => true,
				'name'         => true,
				'id'           => true,
				'class'        => true,
				'value'        => true,
				'min'          => true,
				'step'         => true,
				'inputmode'    => true,
				'tabindex'     => true,
				'autocomplete' => true,
			),
			'img'    => array(
				'src'     => true,
				'alt'     => true,
				'class'   => true,
				'loading' => true,
				'width'   => true,
				'height'  => true,
			),
			'button' => array(
				'type'              => true,
				'class'             => true,
				'data-amount-cents' => true,
				'data-tier-label'   => true,
				'data-tier-index'   => true,
				'data-custom'       => true,
			),
		);

		/**
		 * Filter allowed tags for gift-area HTML after the registries filter.
		 *
		 * @since 8.2.12
		 * @param array<string, array<string, bool>> $allowed Allowed tags.
		 */
		$allowed = apply_filters( 'wgrsvp_gift_area_kses_allowed_html', $allowed );

		return is_array( $allowed ) ? $allowed : array();
	}

	/**
	 * Late-escape gift-area HTML with the gift-area allow-list.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function kses_gift_area_html( $html ) {
		return wp_kses( (string) $html, self::get_gift_area_allowed_html() );
	}
}
