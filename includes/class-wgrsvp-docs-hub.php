<?php
/**
 * SelfStorage-style documentation hub for the free plugin Help screen.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card grid + modal markdown viewer (assets enqueued — no inline tags).
 */
class WGRSVP_Docs_Hub {

	const STYLE_HANDLE  = 'wgrsvp-docs-hub';
	const SCRIPT_HANDLE = 'wgrsvp-docs-hub';

	/**
	 * @return string
	 */
	public static function docs_dir() {
		return trailingslashit( WGRSVP_PLUGIN_DIR ) . 'admin-docs/';
	}

	/**
	 * @return string
	 */
	public static function docs_url() {
		return trailingslashit( plugin_dir_url( WGRSVP_PLUGIN_FILE ) ) . 'admin-docs/';
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public static function get_cards() {
		return array(
			array(
				'title'       => __( 'Quick Start Guide', 'wedding-party-rsvp' ),
				'file'        => 'QUICKSTART.md',
				'description' => __( 'Install, place the RSVP form block, and optional guest pages.', 'wedding-party-rsvp' ),
				'icon'        => 'dashicons-clock',
			),
			array(
				'title'       => __( 'Gutenberg Blocks', 'wedding-party-rsvp' ),
				'file'        => 'BLOCKS.md',
				'description' => __( 'All free blocks with links to full per-block guides for designers.', 'wedding-party-rsvp' ),
				'icon'        => 'dashicons-block-default',
			),
			array(
				'title'       => __( 'Shortcodes Reference', 'wedding-party-rsvp' ),
				'file'        => 'SHORTCODES.md',
				'description' => __( 'Classic Editor shortcodes that match each Gutenberg block.', 'wedding-party-rsvp' ),
				'icon'        => 'dashicons-shortcode',
			),
		);
	}

	/**
	 * Enqueue hub assets on the Help page only.
	 *
	 * @param string $hook Admin hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		unset( $hook );
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'wedding-rsvp-help' ) ) {
			return;
		}

		$ver = defined( 'WGRSVP_VERSION' ) ? WGRSVP_VERSION : '1';
		$base = plugin_dir_url( WGRSVP_PLUGIN_FILE );
		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base . 'assets/css/wgrsvp-docs-hub.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$base . 'assets/js/wgrsvp-docs-hub.js',
			array( 'jquery' ),
			$ver,
			true
		);

		$cards        = self::get_cards();
		$doc_contents = array();
		$doc_titles   = array();
		$dir          = self::docs_dir();

		foreach ( $cards as $card ) {
			$doc_titles[ $card['file'] ] = $card['title'];
			$path                        = $dir . $card['file'];
			if ( is_readable( $path ) ) {
				$doc_contents[ $card['file'] ] = self::read_doc_file( $card['file'] );
			}
		}

		$block_files = glob( $dir . 'BLOCKS-*.md' );
		if ( is_array( $block_files ) ) {
			foreach ( $block_files as $block_path ) {
				$base = basename( $block_path );
				if ( ! isset( $doc_contents[ $base ] ) ) {
					$doc_contents[ $base ] = self::read_doc_file( $base );
				}
			}
		}

		$auto_doc = '';
		if ( isset( $_GET['wgrsvp_doc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$auto_doc = sanitize_file_name( wp_unslash( (string) $_GET['wgrsvp_doc'] ) );
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wgrsvpDocsHub',
			array(
				'contents'    => $doc_contents,
				'titles'      => $doc_titles,
				'blockTitles' => array(
					'BLOCKS-RSVP-FORM.md'           => __( 'Wedding RSVP Form block', 'wedding-party-rsvp' ),
					'BLOCKS-GUEST-HUB.md'           => __( 'Guest Hub block', 'wedding-party-rsvp' ),
					'BLOCKS-GUESTBOOK.md'           => __( 'Guestbook block', 'wedding-party-rsvp' ),
					'BLOCKS-ITINERARY.md'           => __( 'Guest itinerary block', 'wedding-party-rsvp' ),
					'BLOCKS-TRAVEL.md'              => __( 'Travel & lodging block', 'wedding-party-rsvp' ),
					'BLOCKS-THANKYOU-CHECKLIST.md'  => __( 'Thank-you checklist block', 'wedding-party-rsvp' ),
				),
				'notFound'    => __( 'Documentation not found.', 'wedding-party-rsvp' ),
				'autoDoc'     => $auto_doc,
			)
		);
	}

	/**
	 * @param string $basename File under admin-docs/.
	 * @return string
	 */
	public static function read_doc_file( $basename ) {
		$basename = basename( (string) $basename );
		if ( '' === $basename || false !== strpos( $basename, '..' ) ) {
			return '';
		}
		$path = self::docs_dir() . $basename;
		if ( ! is_readable( $path ) ) {
			return '';
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}
		if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
			$content = $wp_filesystem->get_contents( $path );
			return is_string( $content ) ? $content : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin file only.
		$content = file_get_contents( $path );
		return is_string( $content ) ? $content : '';
	}

	/**
	 * Render card grid + modal shell (classic help continues after this).
	 *
	 * @return void
	 */
	public static function render_hub() {
		$cards = self::get_cards();
		$dir   = self::docs_dir();
		$ver   = defined( 'WGRSVP_VERSION' ) ? WGRSVP_VERSION : '';

		echo '<div class="wgrsvp-docs-intro">';
		echo '<p class="description">' . esc_html__( 'Click View Documentation on a card to open a full guide. Inside Gutenberg Blocks, follow the links for each block’s instructions.', 'wedding-party-rsvp' ) . '</p>';
		if ( $ver ) {
			echo '<p><strong>' . esc_html__( 'Version:', 'wedding-party-rsvp' ) . '</strong> ' . esc_html( $ver ) . '</p>';
		}
		echo '</div>';

		echo '<div class="wgrsvp-docs-grid">';
		foreach ( $cards as $card ) {
			$path   = $dir . $card['file'];
			$exists = is_readable( $path );
			$size   = $exists ? (int) filesize( $path ) : 0;

			echo '<div class="wgrsvp-doc-card">';
			echo '<div class="doc-header">';
			echo '<span class="dashicons ' . esc_attr( $card['icon'] ) . '" aria-hidden="true"></span>';
			echo '<h2>' . esc_html( $card['title'] ) . '</h2>';
			echo '</div>';
			echo '<p class="doc-description">' . esc_html( $card['description'] ) . '</p>';

			if ( $exists ) {
				echo '<div class="doc-actions">';
				echo '<button type="button" class="button button-primary wgrsvp-view-doc-btn" data-doc="' . esc_attr( $card['file'] ) . '">';
				echo esc_html__( 'View Documentation', 'wedding-party-rsvp' );
				echo '</button>';
				echo '<a class="button" href="' . esc_url( self::docs_url() . $card['file'] ) . '" download target="_blank" rel="noopener noreferrer">';
				echo esc_html__( 'Download', 'wedding-party-rsvp' );
				echo '</a>';
				echo '</div>';
				if ( $size ) {
					echo '<div class="doc-meta"><small>';
					printf(
						/* translators: %s: formatted file size */
						esc_html__( 'File size: %s', 'wedding-party-rsvp' ),
						esc_html( size_format( $size ) )
					);
					echo '</small></div>';
				}
			} else {
				echo '<p class="doc-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
				echo esc_html__( 'Documentation file not found.', 'wedding-party-rsvp' );
				echo '</p>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<div id="wgrsvp-doc-modal" class="wgrsvp-doc-modal" style="display:none;" hidden>';
		echo '<div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="wgrsvp-modal-title">';
		echo '<div class="modal-header">';
		echo '<h2 id="wgrsvp-modal-title"></h2>';
		echo '<button type="button" class="modal-close wgrsvp-modal-close" aria-label="' . esc_attr__( 'Close', 'wedding-party-rsvp' ) . '">&times;</button>';
		echo '</div>';
		echo '<div class="modal-body"><div id="wgrsvp-modal-content"></div></div>';
		echo '</div></div>';
	}
}
