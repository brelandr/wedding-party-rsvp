<?php
/**
 * PDF export for gifts & thank-you report (bundled FPDF).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Gifts_PDF' ) ) {

	/**
	 * Portrait PDF for gift / thank-you tracking.
	 */
	class WGRSVP_Gifts_PDF {

		/**
		 * Stream download and exit.
		 *
		 * @param array<int,array<string,mixed>> $rows   Guest rows (assoc).
		 * @param string|null                    $unused Ignored. Kept for call compatibility; text domain is always wedding-party-rsvp.
		 * @return void
		 */
		public static function stream_rows( array $rows, $unused = null ) {
			unset( $unused );
			$fpdf = __DIR__ . '/lib/fpdf/fpdf.php';
			if ( ! is_readable( $fpdf ) ) {
				wp_die( esc_html__( 'PDF export is unavailable.', 'wedding-party-rsvp' ), esc_html__( 'Export', 'wedding-party-rsvp' ), array( 'response' => 500 ) );
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Local library path; is_readable checked.
			require_once $fpdf;

			$pdf = new FPDF( 'P', 'mm', 'Letter' );
			$pdf->SetMargins( 12, 12, 12 );
			$pdf->SetAutoPageBreak( true, 14 );
			$pdf->AddPage();
			$pdf->SetFont( 'Helvetica', 'B', 13 );
			$pdf->Cell( 0, 8, self::pdf_txt( __( 'Gifts & thank-you cards', 'wedding-party-rsvp' ) ), 0, 1 );
			$pdf->SetFont( 'Helvetica', '', 9 );
			$pdf->Cell(
				0,
				5,
				self::pdf_txt(
					sprintf(
						/* translators: %s: generated date/time. */
						__( 'Generated %s', 'wedding-party-rsvp' ),
						wp_date( 'Y-m-d H:i' )
					)
				),
				0,
				1
			);
			$pdf->Ln( 2 );

			$w_party = 24;
			$w_name  = 32;
			$w_gift  = 52;
			$w_ty    = 22;
			$w_addr  = 58;

			$pdf->SetFont( 'Helvetica', 'B', 8 );
			$pdf->Cell( $w_party, 6, self::pdf_txt( __( 'Party', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_name, 6, self::pdf_txt( __( 'Name', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_gift, 6, self::pdf_txt( __( 'Gift', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_ty, 6, self::pdf_txt( __( 'TY sent', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_addr, 6, self::pdf_txt( __( 'Address / contact', 'wedding-party-rsvp' ) ), 1, 1 );

			$pdf->SetFont( 'Helvetica', '', 7 );
			$row_h = 5;
			foreach ( $rows as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				if ( $pdf->GetY() > 250 ) {
					$pdf->AddPage();
					$pdf->SetFont( 'Helvetica', 'B', 8 );
					$pdf->Cell( $w_party, 6, self::pdf_txt( __( 'Party', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_name, 6, self::pdf_txt( __( 'Name', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_gift, 6, self::pdf_txt( __( 'Gift', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_ty, 6, self::pdf_txt( __( 'TY sent', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_addr, 6, self::pdf_txt( __( 'Address / contact', 'wedding-party-rsvp' ) ), 1, 1 );
					$pdf->SetFont( 'Helvetica', '', 7 );
				}

				$party = isset( $r['party_id'] ) ? (string) $r['party_id'] : '';
				$name  = isset( $r['guest_name'] ) ? (string) $r['guest_name'] : '';
				$gift  = isset( $r['gift_received'] ) ? wp_strip_all_tags( (string) $r['gift_received'] ) : '';
				$ty    = '';
				if ( isset( $r['thankyou_card_sent_on'] ) && is_string( $r['thankyou_card_sent_on'] ) && '' !== trim( $r['thankyou_card_sent_on'] ) ) {
					if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $r['thankyou_card_sent_on'], $m ) ) {
						$ty = $m[1];
					}
				}
				$addr = array();
				if ( ! empty( $r['email'] ) ) {
					$addr[] = (string) $r['email'];
				}
				if ( ! empty( $r['phone'] ) ) {
					$addr[] = (string) $r['phone'];
				}
				if ( ! empty( $r['address'] ) ) {
					$addr[] = (string) $r['address'];
				}
				$addr_s = self::clip( implode( ' · ', $addr ), 120 );

				$pdf->Cell( $w_party, $row_h, self::pdf_txt( self::clip( $party, 14 ) ), 1, 0 );
				$pdf->Cell( $w_name, $row_h, self::pdf_txt( self::clip( $name, 22 ) ), 1, 0 );
				$pdf->Cell( $w_gift, $row_h, self::pdf_txt( self::clip( $gift, 38 ) ), 1, 0 );
				$pdf->Cell( $w_ty, $row_h, self::pdf_txt( self::clip( $ty, 12 ) ), 1, 0 );
				$pdf->Cell( $w_addr, $row_h, self::pdf_txt( $addr_s ), 1, 1 );
			}

			$fname = 'wedding-rsvp-gifts-thankyou-' . gmdate( 'Y-m-d' ) . '.pdf';
			$pdf->Output( 'D', $fname );
			exit;
		}

		/**
		 * Clip string length for PDF cells.
		 *
		 * @param string $s   Text.
		 * @param int    $max Max chars.
		 * @return string
		 */
		private static function clip( $s, $max ) {
			$s = (string) $s;
			if ( strlen( $s ) <= $max ) {
				return $s;
			}

			return substr( $s, 0, $max - 1 ) . '…';
		}

		/**
		 * Best-effort encoding for FPDF core fonts (ISO-8859-1).
		 *
		 * @param string $s UTF-8 string.
		 * @return string
		 */
		private static function pdf_txt( $s ) {
			$s = wp_strip_all_tags( (string) $s );
			if ( function_exists( 'iconv' ) ) {
				$out = @iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT', $s );
				if ( false !== $out && '' !== $out ) {
					return $out;
				}
			}

			return $s;
		}
	}
}
