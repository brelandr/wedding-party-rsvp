<?php
/**
 * Public itinerary + travel shortcodes (Free shells; Pro enriches via filters).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guest-facing schedule and lodging sections for marketing pages.
 */
class WGRSVP_Itinerary_Travel {

	public const OPTION_TRAVEL = 'wgrsvp_travel_settings';

	/**
	 * Register shortcodes and settings save.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_shortcode( 'wgrsvp_itinerary', array( __CLASS__, 'shortcode_itinerary' ) );
		add_shortcode( 'wgrsvp_travel', array( __CLASS__, 'shortcode_travel' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_travel_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_section' ), 120 );
	}

	/**
	 * Travel option defaults.
	 *
	 * @return array<string,string>
	 */
	public static function default_travel() {
		return array(
			'heading'           => '',
			'hotel_name'        => '',
			'hotel_url'         => '',
			'hotel_code'        => '',
			'cutoff'            => '',
			'note'              => '',
			'cta_label'         => '',
			'empty_message'     => '',
			'group_code_label'  => '',
			'book_by_label'     => '',
			'copy_code_label'   => '',
			'copied_label'      => '',
		);
	}

	/**
	 * Front assets when [wgrsvp_travel] is on the page.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_travel_assets() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'wgrsvp_travel' ) ) {
			return;
		}
		$ver  = defined( 'WGRSVP_VERSION' ) ? WGRSVP_VERSION : '8.3.9';
		$base = defined( 'WGRSVP_PLUGIN_FILE' ) ? WGRSVP_PLUGIN_FILE : dirname( __DIR__ ) . '/wedding-party-rsvp.php';
		wp_enqueue_style(
			'wgrsvp-travel',
			plugins_url( 'assets/css/wgrsvp-travel.css', $base ),
			array(),
			$ver
		);
		wp_enqueue_script(
			'wgrsvp-travel',
			plugins_url( 'assets/js/wgrsvp-travel.js', $base ),
			array(),
			$ver,
			true
		);
	}

	/**
	 * Stored Free travel settings (Pro may override via filter).
	 *
	 * @return array<string,string>
	 */
	public static function get_travel_settings() {
		$opt = get_option( self::OPTION_TRAVEL, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		$out = array_merge( self::default_travel(), $opt );
		foreach ( $out as $k => $v ) {
			$out[ $k ] = is_string( $v ) ? $v : '';
		}
		return $out;
	}

	/**
	 * Build itinerary items from general settings + filters.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function get_itinerary_items() {
		$gen = get_option( 'wgrsvp_general_settings', array() );
		if ( ! is_array( $gen ) ) {
			$gen = array();
		}
		$items = array();
		$title = isset( $gen['event_title'] ) ? trim( (string) $gen['event_title'] ) : '';
		if ( '' === $title && isset( $gen['welcome_title'] ) ) {
			$title = trim( (string) $gen['welcome_title'] );
		}
		$start = isset( $gen['event_start'] ) ? trim( (string) $gen['event_start'] ) : '';
		if ( class_exists( 'WGRSVP_ICS', false ) ) {
			$formatted = WGRSVP_ICS::format_event_start_for_display( $gen );
			if ( '' !== $formatted ) {
				$start = $formatted;
			}
		}
		$location = isset( $gen['event_location'] ) ? trim( (string) $gen['event_location'] ) : '';
		if ( '' !== $title || '' !== $start ) {
			$items[] = array(
				'id'       => 'main',
				'title'    => '' !== $title ? $title : __( 'Wedding day', 'wedding-party-rsvp' ),
				'start'    => $start,
				'location' => $location,
				'status'   => '',
			);
		}

		/**
		 * Filter public itinerary items (Pro injects sub-events).
		 *
		 * @param array<int,array<string,string>> $items Items.
		 */
		$items = apply_filters( 'wgrsvp_itinerary_items', $items );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Travel payload for shortcode (Pro may replace).
	 *
	 * @return array<string,string>
	 */
	public static function get_travel_payload() {
		$payload = self::get_travel_settings();
		/**
		 * Filter public travel section payload.
		 *
		 * @param array<string,string> $payload Travel fields.
		 */
		$payload = apply_filters( 'wgrsvp_travel_payload', $payload );
		return is_array( $payload ) ? $payload : self::default_travel();
	}

	/**
	 * [wgrsvp_itinerary]
	 *
	 * @return string
	 */
	public static function shortcode_itinerary() {
		$items = self::get_itinerary_items();
		$heading = __( 'Schedule', 'wedding-party-rsvp' );
		/**
		 * Filter itinerary section heading.
		 *
		 * @param string $heading Heading.
		 */
		$heading = (string) apply_filters( 'wgrsvp_itinerary_heading', $heading );

		ob_start();
		echo '<section class="wgrsvp-itinerary">';
		echo '<h2 class="wgrsvp-itinerary__heading">' . esc_html( $heading ) . '</h2>';
		if ( empty( $items ) ) {
			echo '<p class="wgrsvp-itinerary__empty">' . esc_html__( 'Schedule details coming soon.', 'wedding-party-rsvp' ) . '</p>';
		} else {
			echo '<ol class="wgrsvp-itinerary__list">';
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$title = isset( $item['title'] ) ? (string) $item['title'] : '';
				$start = isset( $item['start'] ) ? (string) $item['start'] : '';
				$loc   = isset( $item['location'] ) ? (string) $item['location'] : '';
				if ( '' === $title && '' === $start ) {
					continue;
				}
				echo '<li class="wgrsvp-itinerary__item">';
				if ( '' !== $title ) {
					echo '<span class="wgrsvp-itinerary__title">' . esc_html( $title ) . '</span>';
				}
				if ( '' !== $start ) {
					echo ' <span class="wgrsvp-itinerary__time">' . esc_html( $start ) . '</span>';
				}
				if ( '' !== $loc ) {
					if ( '' !== $title || '' !== $start ) {
						echo ', ';
					}
					echo '<span class="wgrsvp-itinerary__location">' . esc_html( $loc ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ol>';
		}
		echo '</section>';
		return (string) ob_get_clean();
	}

	/**
	 * [wgrsvp_travel]
	 *
	 * @return string
	 */
	public static function shortcode_travel() {
		$t = self::get_travel_payload();
		$heading = isset( $t['heading'] ) && '' !== trim( (string) $t['heading'] )
			? (string) $t['heading']
			: __( 'Travel & lodging', 'wedding-party-rsvp' );

		$empty_message = isset( $t['empty_message'] ) && '' !== trim( (string) $t['empty_message'] )
			? (string) $t['empty_message']
			: __( 'Hotel and travel details will be posted here.', 'wedding-party-rsvp' );
		$group_code_label = isset( $t['group_code_label'] ) && '' !== trim( (string) $t['group_code_label'] )
			? (string) $t['group_code_label']
			: __( 'Group code:', 'wedding-party-rsvp' );
		$book_by_label = isset( $t['book_by_label'] ) && '' !== trim( (string) $t['book_by_label'] )
			? (string) $t['book_by_label']
			: __( 'Book by:', 'wedding-party-rsvp' );
		$copy_code_label = isset( $t['copy_code_label'] ) && '' !== trim( (string) $t['copy_code_label'] )
			? (string) $t['copy_code_label']
			: __( 'Copy code', 'wedding-party-rsvp' );
		$copied_label = isset( $t['copied_label'] ) && '' !== trim( (string) $t['copied_label'] )
			? (string) $t['copied_label']
			: __( 'Copied', 'wedding-party-rsvp' );

		$has = '' !== trim( (string) ( $t['hotel_name'] ?? '' ) )
			|| '' !== trim( (string) ( $t['note'] ?? '' ) )
			|| '' !== trim( (string) ( $t['hotel_code'] ?? '' ) );

		ob_start();
		echo '<section class="wgrsvp-travel">';
		echo '<h2 class="wgrsvp-travel__heading">' . esc_html( $heading ) . '</h2>';
		if ( ! $has ) {
			echo '<p class="wgrsvp-travel__empty">' . esc_html( $empty_message ) . '</p>';
		} else {
			$url = isset( $t['hotel_url'] ) ? esc_url_raw( (string) $t['hotel_url'] ) : '';
			if ( '' !== trim( (string) $t['hotel_name'] ) ) {
				echo '<p class="wgrsvp-travel__hotel">';
				$name = (string) $t['hotel_name'];
				if ( '' !== $url ) {
					echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $name ) . '</a>';
				} else {
					echo esc_html( $name );
				}
				echo '</p>';
			}
			if ( '' !== trim( (string) ( $t['hotel_code'] ?? '' ) ) ) {
				$code = (string) $t['hotel_code'];
				echo '<div class="wgrsvp-travel__code-row">';
				echo '<p class="wgrsvp-travel__code"><strong>' . esc_html( $group_code_label ) . '</strong> ';
				echo '<span class="wgrsvp-travel__code-value">' . esc_html( $code ) . '</span></p>';
				echo '<button type="button" class="wgrsvp-travel__copy" data-wgrsvp-copy="' . esc_attr( $code ) . '" data-wgrsvp-copied-label="' . esc_attr( $copied_label ) . '">';
				echo esc_html( $copy_code_label );
				echo '</button></div>';
			}
			if ( '' !== trim( (string) ( $t['cutoff'] ?? '' ) ) ) {
				echo '<p class="wgrsvp-travel__cutoff"><strong>' . esc_html( $book_by_label ) . '</strong> ' . esc_html( (string) $t['cutoff'] ) . '</p>';
			}
			if ( '' !== trim( (string) ( $t['note'] ?? '' ) ) ) {
				echo '<div class="wgrsvp-travel__note">' . wp_kses_post( wpautop( (string) $t['note'] ) ) . '</div>';
			}
			if ( '' !== $url ) {
				$cta = isset( $t['cta_label'] ) && '' !== trim( (string) $t['cta_label'] )
					? (string) $t['cta_label']
					: __( 'Book hotel', 'wedding-party-rsvp' );
				echo '<p class="wgrsvp-travel__actions"><a class="wgrsvp-travel__book" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $cta ) . '</a></p>';
			}
		}
		echo '</section>';
		return (string) ob_get_clean();
	}

	/**
	 * register_setting for travel.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'wgrsvp_general_settings_group',
			self::OPTION_TRAVEL,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_travel' ),
				'default'           => self::default_travel(),
			)
		);
	}

	/**
	 * Sanitize travel option.
	 *
	 * @param mixed $input Raw.
	 * @return array<string,string>
	 */
	public static function sanitize_travel( $input ) {
		$in  = is_array( $input ) ? $input : array();
		$out = self::default_travel();
		$out['heading']          = isset( $in['heading'] ) ? sanitize_text_field( (string) $in['heading'] ) : '';
		$out['hotel_name']       = isset( $in['hotel_name'] ) ? sanitize_text_field( (string) $in['hotel_name'] ) : '';
		$out['hotel_url']        = isset( $in['hotel_url'] ) ? esc_url_raw( (string) $in['hotel_url'] ) : '';
		$out['hotel_code']       = isset( $in['hotel_code'] ) ? sanitize_text_field( (string) $in['hotel_code'] ) : '';
		$out['cutoff']           = isset( $in['cutoff'] ) ? sanitize_text_field( (string) $in['cutoff'] ) : '';
		$out['note']             = isset( $in['note'] ) ? sanitize_textarea_field( (string) $in['note'] ) : '';
		$out['cta_label']        = isset( $in['cta_label'] ) ? sanitize_text_field( (string) $in['cta_label'] ) : '';
		$out['empty_message']    = isset( $in['empty_message'] ) ? sanitize_text_field( (string) $in['empty_message'] ) : '';
		$out['group_code_label'] = isset( $in['group_code_label'] ) ? sanitize_text_field( (string) $in['group_code_label'] ) : '';
		$out['book_by_label']    = isset( $in['book_by_label'] ) ? sanitize_text_field( (string) $in['book_by_label'] ) : '';
		$out['copy_code_label']  = isset( $in['copy_code_label'] ) ? sanitize_text_field( (string) $in['copy_code_label'] ) : '';
		$out['copied_label']     = isset( $in['copied_label'] ) ? sanitize_text_field( (string) $in['copied_label'] ) : '';
		return $out;
	}

	/**
	 * Add travel fields under Wedding RSVP settings when possible.
	 *
	 * @return void
	 */
	public static function register_settings_section() {
		// Fields render via admin_post alternate if settings group differs — expose standalone submenu.
		add_submenu_page(
			'wedding-rsvp-main',
			__( 'Travel (public)', 'wedding-party-rsvp' ),
			__( 'Travel (public)', 'wedding-party-rsvp' ),
			'manage_options',
			'wgrsvp-travel-public',
			array( __CLASS__, 'render_travel_settings_page' )
		);
	}

	/**
	 * Simple Free travel settings page.
	 *
	 * @return void
	 */
	public static function render_travel_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if (
			isset( $_POST['wgrsvp_travel_save'] ) &&
			isset( $_POST['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'wgrsvp_save_travel_public' )
		) {
			$raw = isset( $_POST['wgrsvp_travel'] ) && is_array( $_POST['wgrsvp_travel'] )
				? map_deep( wp_unslash( $_POST['wgrsvp_travel'] ), 'sanitize_text_field' )
				: array();
			if ( isset( $_POST['wgrsvp_travel']['note'] ) ) {
				$raw['note'] = sanitize_textarea_field( wp_unslash( (string) $_POST['wgrsvp_travel']['note'] ) );
			}
			if ( isset( $_POST['wgrsvp_travel']['hotel_url'] ) ) {
				$raw['hotel_url'] = esc_url_raw( wp_unslash( (string) $_POST['wgrsvp_travel']['hotel_url'] ) );
			}
			update_option( self::OPTION_TRAVEL, self::sanitize_travel( $raw ), false );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Travel settings saved.', 'wedding-party-rsvp' ) . '</p></div>';
		}
		$t = self::get_travel_settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'Public travel & lodging', 'wedding-party-rsvp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Customize every guest-facing travel string shown by the [wgrsvp_travel] shortcode. When Wedding Party RSVP Pro is active and configured, Pro → Travel & lodging overrides these values.', 'wedding-party-rsvp' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'wgrsvp_save_travel_public' );
		echo '<table class="form-table" role="presentation">';
		$fields = array(
			'heading'           => array(
				'label'       => __( 'Heading', 'wedding-party-rsvp' ),
				'placeholder' => __( 'Travel & lodging', 'wedding-party-rsvp' ),
			),
			'hotel_name'        => array(
				'label'       => __( 'Hotel name', 'wedding-party-rsvp' ),
				'placeholder' => '',
			),
			'hotel_url'         => array(
				'label'       => __( 'Hotel booking URL', 'wedding-party-rsvp' ),
				'placeholder' => '',
			),
			'hotel_code'        => array(
				'label'       => __( 'Group code', 'wedding-party-rsvp' ),
				'placeholder' => '',
			),
			'cutoff'            => array(
				'label'       => __( 'Book-by date (display text)', 'wedding-party-rsvp' ),
				'placeholder' => __( 'May 1, 2027', 'wedding-party-rsvp' ),
			),
			'cta_label'         => array(
				'label'       => __( 'Book button label', 'wedding-party-rsvp' ),
				'placeholder' => __( 'Book hotel', 'wedding-party-rsvp' ),
			),
			'empty_message'     => array(
				'label'       => __( 'Empty-state message', 'wedding-party-rsvp' ),
				'placeholder' => __( 'Hotel and travel details will be posted here.', 'wedding-party-rsvp' ),
			),
			'group_code_label'  => array(
				'label'       => __( 'Group code label', 'wedding-party-rsvp' ),
				'placeholder' => __( 'Group code:', 'wedding-party-rsvp' ),
			),
			'book_by_label'     => array(
				'label'       => __( 'Book-by label', 'wedding-party-rsvp' ),
				'placeholder' => __( 'Book by:', 'wedding-party-rsvp' ),
			),
			'copy_code_label'   => array(
				'label'       => __( 'Copy button label', 'wedding-party-rsvp' ),
				'placeholder' => __( 'Copy code', 'wedding-party-rsvp' ),
			),
			'copied_label'      => array(
				'label'       => __( 'Copied confirmation label', 'wedding-party-rsvp' ),
				'placeholder' => __( 'Copied', 'wedding-party-rsvp' ),
			),
		);
		foreach ( $fields as $key => $meta ) {
			echo '<tr><th scope="row"><label for="wgrsvp-travel-' . esc_attr( $key ) . '">' . esc_html( $meta['label'] ) . '</label></th><td>';
			echo '<input class="regular-text" type="text" id="wgrsvp-travel-' . esc_attr( $key ) . '" name="wgrsvp_travel[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) ( $t[ $key ] ?? '' ) ) . '"';
			if ( '' !== $meta['placeholder'] ) {
				echo ' placeholder="' . esc_attr( $meta['placeholder'] ) . '"';
			}
			echo ' />';
			echo '</td></tr>';
		}
		echo '<tr><th scope="row"><label for="wgrsvp-travel-note">' . esc_html__( 'Notes', 'wedding-party-rsvp' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="5" id="wgrsvp-travel-note" name="wgrsvp_travel[note]">' . esc_textarea( (string) $t['note'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Parking, shuttle, airport tips, or any other travel details.', 'wedding-party-rsvp' ) . '</p>';
		echo '</td></tr></table>';
		echo '<p><button type="submit" class="button button-primary" name="wgrsvp_travel_save" value="1">' . esc_html__( 'Save', 'wedding-party-rsvp' ) . '</button></p>';
		echo '</form></div>';
	}
}
