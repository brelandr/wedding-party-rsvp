<?php
/**
 * Check-in desk PDF (FPDF) for guest list export.
 *
 * Bundled FPDF 1.86 (Olivier Plathey) — see readme.txt Third-party libraries.
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Checkin_PDF' ) ) {

	/**
	 * Renders a landscape table PDF from guest row arrays (assoc keys like DB columns).
	 */
	class WGRSVP_Checkin_PDF {

		/**
		 * Stream PDF download and exit.
		 *
		 * @param array       $rows   List of associative guest rows.
		 * @param string|null $unused Ignored. Kept for call compatibility; text domain is always wedding-party-rsvp.
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

			$pdf = new FPDF( 'L', 'mm', 'Letter' );
			$pdf->SetMargins( 10, 10, 10 );
			$pdf->SetAutoPageBreak( true, 15 );
			$pdf->AddPage();
			$pdf->SetFont( 'Helvetica', 'B', 14 );
			$pdf->Cell( 0, 8, self::pdf_txt( __( 'Guest check-in list', 'wedding-party-rsvp' ) ), 0, 1 );
			$pdf->SetFont( 'Helvetica', '', 9 );
			$pdf->Cell(
				0,
				6,
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

			$w_name  = 38;
			$w_party = 28;
			$w_rsvp  = 22;
			$w_meal  = 35;
			$w_tbl   = 14;
			$w_phone = 28;
			$w_email = 42;
			$w_diet  = 35;
			$w_notes = 38;

			$pdf->SetFont( 'Helvetica', 'B', 8 );
			$pdf->Cell( $w_name, 7, self::pdf_txt( __( 'Name', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_party, 7, self::pdf_txt( __( 'Party ID', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_rsvp, 7, self::pdf_txt( __( 'RSVP', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_meal, 7, self::pdf_txt( __( 'Meal / child meal', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_tbl, 7, self::pdf_txt( __( 'Tbl', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_phone, 7, self::pdf_txt( __( 'Phone', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_email, 7, self::pdf_txt( __( 'Email', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_diet, 7, self::pdf_txt( __( 'Diet / allergies', 'wedding-party-rsvp' ) ), 1, 0 );
			$pdf->Cell( $w_notes, 7, self::pdf_txt( __( 'Notes', 'wedding-party-rsvp' ) ), 1, 1 );

			$pdf->SetFont( 'Helvetica', '', 7 );
			$row_h = 6;
			foreach ( $rows as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				if ( $pdf->GetY() > 185 ) {
					$pdf->AddPage();
					$pdf->SetFont( 'Helvetica', 'B', 8 );
					$pdf->Cell( $w_name, 7, self::pdf_txt( __( 'Name', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_party, 7, self::pdf_txt( __( 'Party ID', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_rsvp, 7, self::pdf_txt( __( 'RSVP', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_meal, 7, self::pdf_txt( __( 'Meal / child meal', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_tbl, 7, self::pdf_txt( __( 'Tbl', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_phone, 7, self::pdf_txt( __( 'Phone', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_email, 7, self::pdf_txt( __( 'Email', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_diet, 7, self::pdf_txt( __( 'Diet / allergies', 'wedding-party-rsvp' ) ), 1, 0 );
					$pdf->Cell( $w_notes, 7, self::pdf_txt( __( 'Notes', 'wedding-party-rsvp' ) ), 1, 1 );
					$pdf->SetFont( 'Helvetica', '', 7 );
				}
				$name = isset( $r['guest_name'] ) ? (string) $r['guest_name'] : '';
				$meal = trim( ( isset( $r['menu_choice'] ) ? (string) $r['menu_choice'] : '' ) . ( ! empty( $r['child_menu_choice'] ) ? ' / ' . (string) $r['child_menu_choice'] : '' ) );
				$diet = trim( ( isset( $r['dietary_restrictions'] ) ? (string) $r['dietary_restrictions'] : '' ) . ( ! empty( $r['allergies'] ) ? ' | ' . (string) $r['allergies'] : '' ) );

				$pdf->Cell( $w_name, $row_h, self::pdf_txt( self::clip( $name, 45 ) ), 1, 0 );
				$pdf->Cell( $w_party, $row_h, self::pdf_txt( self::clip( isset( $r['party_id'] ) ? (string) $r['party_id'] : '', 18 ) ), 1, 0 );
				$pdf->Cell( $w_rsvp, $row_h, self::pdf_txt( self::clip( isset( $r['rsvp_status'] ) ? (string) $r['rsvp_status'] : '', 14 ) ), 1, 0 );
				$pdf->Cell( $w_meal, $row_h, self::pdf_txt( self::clip( $meal, 40 ) ), 1, 0 );
				$pdf->Cell( $w_tbl, $row_h, self::pdf_txt( self::clip( isset( $r['table_number'] ) ? (string) $r['table_number'] : '', 8 ) ), 1, 0 );
				$pdf->Cell( $w_phone, $row_h, self::pdf_txt( self::clip( isset( $r['phone'] ) ? (string) $r['phone'] : '', 18 ) ), 1, 0 );
				$pdf->Cell( $w_email, $row_h, self::pdf_txt( self::clip( isset( $r['email'] ) ? (string) $r['email'] : '', 32 ) ), 1, 0 );
				$pdf->Cell( $w_diet, $row_h, self::pdf_txt( self::clip( $diet, 40 ) ), 1, 0 );
				$pdf->Cell( $w_notes, $row_h, self::pdf_txt( self::clip( isset( $r['admin_notes'] ) ? (string) $r['admin_notes'] : '', 45 ) ), 1, 1 );
			}

			$fname = 'wedding-checkin-' . gmdate( 'Y-m-d' ) . '.pdf';
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
