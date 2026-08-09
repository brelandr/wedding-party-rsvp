<?php
/**
 * Place-card + per-guest catering-by-table CSV exports (Avery / Canva friendly).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stream place-card and table×entrée detail CSVs.
 */
class WGRSVP_Placecard_Export {

	/**
	 * Short entrée symbol for place cards (first letter / digits).
	 *
	 * @param string $meal Meal label.
	 * @return string
	 */
	public static function entree_symbol( $meal ) {
		$meal = trim( (string) $meal );
		if ( '' === $meal ) {
			return '—';
		}
		$clean = preg_replace( '/[^A-Za-z0-9]/', '', $meal );
		if ( ! is_string( $clean ) || '' === $clean ) {
			return mb_substr( $meal, 0, 1 );
		}
		return strtoupper( mb_substr( $clean, 0, 2 ) );
	}

	/**
	 * Resolve display meal for a guest row.
	 *
	 * @param array<string,mixed> $r Row.
	 * @return string
	 */
	public static function meal_for_row( array $r ) {
		$is_child = ! empty( $r['is_child'] );
		$child_m  = isset( $r['child_menu_choice'] ) ? trim( (string) $r['child_menu_choice'] ) : '';
		$adult_m  = isset( $r['menu_choice'] ) ? trim( (string) $r['menu_choice'] ) : '';
		if ( $is_child || '' !== $child_m ) {
			return '' !== $child_m ? $child_m : __( 'Child meal', 'wedding-party-rsvp' );
		}
		return '' !== $adult_m ? $adult_m : '';
	}

	/**
	 * Table label for a row.
	 *
	 * @param array<string,mixed> $r Row.
	 * @return string
	 */
	public static function table_label( array $r ) {
		$tbl = isset( $r['table_number'] ) ? trim( (string) $r['table_number'] ) : '';
		return '' !== $tbl ? $tbl : '—';
	}

	/**
	 * Filter to Accepted guests unless $accepted_only is false.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param bool                           $accepted_only Accepted only.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_rows( array $rows, $accepted_only ) {
		if ( ! $accepted_only ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$st = isset( $r['rsvp_status'] ) ? (string) $r['rsvp_status'] : '';
			if ( 'Accepted' === $st ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Sort rows by table then name.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sort_rows( array $rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				$ta = self::table_label( is_array( $a ) ? $a : array() );
				$tb = self::table_label( is_array( $b ) ? $b : array() );
				if ( '—' === $ta && '—' !== $tb ) {
					return 1;
				}
				if ( '—' !== $ta && '—' === $tb ) {
					return -1;
				}
				$cmp = strnatcasecmp( $ta, $tb );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				$na = isset( $a['guest_name'] ) ? (string) $a['guest_name'] : '';
				$nb = isset( $b['guest_name'] ) ? (string) $b['guest_name'] : '';
				return strcasecmp( $na, $nb );
			}
		);
		return $rows;
	}

	/**
	 * Place-card CSV: Name, Table, Entrée, Symbol (Canva / Avery import).
	 *
	 * @param array<int,array<string,mixed>> $rows          Guest rows.
	 * @param bool                           $accepted_only Accepted only.
	 * @return void
	 */
	public static function stream_placecard_csv( array $rows, $accepted_only = true ) {
		$rows = self::sort_rows( self::filter_rows( $rows, $accepted_only ) );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wedding-place-cards-' . gmdate( 'Y-m-d' ) . '.csv"' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$f = fopen( 'php://output', 'w' );
		fputcsv(
			$f,
			array(
				__( 'Name', 'wedding-party-rsvp' ),
				__( 'Table', 'wedding-party-rsvp' ),
				__( 'Entree', 'wedding-party-rsvp' ),
				__( 'Entree Symbol', 'wedding-party-rsvp' ),
				__( 'Party ID', 'wedding-party-rsvp' ),
				__( 'Dietary', 'wedding-party-rsvp' ),
				__( 'Allergies', 'wedding-party-rsvp' ),
			)
		);
		foreach ( $rows as $r ) {
			$meal = self::meal_for_row( $r );
			fputcsv(
				$f,
				array(
					isset( $r['guest_name'] ) ? (string) $r['guest_name'] : '',
					self::table_label( $r ),
					$meal,
					self::entree_symbol( $meal ),
					isset( $r['party_id'] ) ? (string) $r['party_id'] : '',
					isset( $r['dietary_restrictions'] ) ? (string) $r['dietary_restrictions'] : '',
					isset( $r['allergies'] ) ? (string) $r['allergies'] : '',
				)
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $f );
		exit;
	}

	/**
	 * Per-guest catering sheet grouped by table (Name, Table, Entrée, allergies).
	 *
	 * @param array<int,array<string,mixed>> $rows          Guest rows.
	 * @param bool                           $accepted_only Accepted only.
	 * @return void
	 */
	public static function stream_table_detail_csv( array $rows, $accepted_only = true ) {
		$rows = self::sort_rows( self::filter_rows( $rows, $accepted_only ) );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wedding-catering-by-table-' . gmdate( 'Y-m-d' ) . '.csv"' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$f = fopen( 'php://output', 'w' );
		fputcsv(
			$f,
			array(
				__( 'Table', 'wedding-party-rsvp' ),
				__( 'Name', 'wedding-party-rsvp' ),
				__( 'Entree', 'wedding-party-rsvp' ),
				__( 'Child meal', 'wedding-party-rsvp' ),
				__( 'Dietary', 'wedding-party-rsvp' ),
				__( 'Allergies', 'wedding-party-rsvp' ),
				__( 'RSVP', 'wedding-party-rsvp' ),
			)
		);
		foreach ( $rows as $r ) {
			fputcsv(
				$f,
				array(
					self::table_label( $r ),
					isset( $r['guest_name'] ) ? (string) $r['guest_name'] : '',
					isset( $r['menu_choice'] ) ? (string) $r['menu_choice'] : '',
					isset( $r['child_menu_choice'] ) ? (string) $r['child_menu_choice'] : '',
					isset( $r['dietary_restrictions'] ) ? (string) $r['dietary_restrictions'] : '',
					isset( $r['allergies'] ) ? (string) $r['allergies'] : '',
					isset( $r['rsvp_status'] ) ? (string) $r['rsvp_status'] : '',
				)
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $f );
		exit;
	}

	/**
	 * Printable place-card PDF sheets (2×4 cards per Letter page).
	 *
	 * @param array<int,array<string,mixed>> $rows          Guest rows.
	 * @param bool                           $accepted_only Accepted only.
	 * @return void
	 */
	public static function stream_placecard_pdf( array $rows, $accepted_only = true ) {
		$rows = self::sort_rows( self::filter_rows( $rows, $accepted_only ) );
		$fpdf = __DIR__ . '/lib/fpdf/fpdf.php';
		if ( ! is_readable( $fpdf ) ) {
			wp_die( esc_html__( 'PDF export is unavailable.', 'wedding-party-rsvp' ), esc_html__( 'Export', 'wedding-party-rsvp' ), array( 'response' => 500 ) );
		}
		require_once $fpdf;

		$pdf = new FPDF( 'P', 'mm', 'Letter' );
		$pdf->SetMargins( 10, 10, 10 );
		$pdf->SetAutoPageBreak( false );

		$cols   = 2;
		$rows_n = 4;
		$cw     = 95;
		$ch     = 60;
		$gap_x  = 5;
		$gap_y  = 4;
		$i      = 0;
		$count  = count( $rows );

		while ( $i < $count || 0 === $count ) {
			if ( 0 === $count ) {
				break;
			}
			$pdf->AddPage();
			for ( $r = 0; $r < $rows_n; $r++ ) {
				for ( $c = 0; $c < $cols; $c++ ) {
					if ( $i >= $count ) {
						break 2;
					}
					$guest = $rows[ $i ];
					++$i;
					$x = 10 + ( $c * ( $cw + $gap_x ) );
					$y = 12 + ( $r * ( $ch + $gap_y ) );
					$pdf->Rect( $x, $y, $cw, $ch );
					$name  = isset( $guest['guest_name'] ) ? (string) $guest['guest_name'] : '';
					$table = self::table_label( is_array( $guest ) ? $guest : array() );
					$meal  = self::meal_for_row( is_array( $guest ) ? $guest : array() );
					$sym   = self::entree_symbol( $meal );
					$diet  = isset( $guest['dietary_restrictions'] ) ? trim( (string) $guest['dietary_restrictions'] ) : '';
					$all   = isset( $guest['allergies'] ) ? trim( (string) $guest['allergies'] ) : '';

					$pdf->SetXY( $x + 4, $y + 10 );
					$pdf->SetFont( 'Helvetica', 'B', 16 );
					$pdf->MultiCell( $cw - 8, 7, self::pdf_txt( $name ), 0, 'C' );
					$pdf->SetFont( 'Helvetica', '', 11 );
					$pdf->SetX( $x + 4 );
					$pdf->MultiCell(
						$cw - 8,
						6,
						self::pdf_txt(
							sprintf(
								/* translators: 1: table label, 2: entrée symbol */
								__( 'Table %1$s · %2$s', 'wedding-party-rsvp' ),
								$table,
								$sym
							)
						),
						0,
						'C'
					);
					if ( '' !== $meal ) {
						$pdf->SetX( $x + 4 );
						$pdf->SetFont( 'Helvetica', '', 9 );
						$pdf->MultiCell( $cw - 8, 5, self::pdf_txt( $meal ), 0, 'C' );
					}
					$notes = trim( $diet . ( '' !== $diet && '' !== $all ? ' · ' : '' ) . $all );
					if ( '' !== $notes ) {
						$pdf->SetX( $x + 4 );
						$pdf->SetFont( 'Helvetica', 'I', 8 );
						$pdf->MultiCell( $cw - 8, 4, self::pdf_txt( self::clip( $notes, 80 ) ), 0, 'C' );
					}
				}
			}
		}

		$pdf->Output( 'D', 'wedding-place-cards-' . gmdate( 'Y-m-d' ) . '.pdf' );
		exit;
	}

	/**
	 * Clip string.
	 *
	 * @param string $s   Text.
	 * @param int    $max Max length.
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
	 * FPDF-safe text.
	 *
	 * @param string $s UTF-8.
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
