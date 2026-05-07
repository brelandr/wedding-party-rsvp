<?php
/**
 * Caterer-oriented summary export (aggregated by table + meal counts).
 *
 * @package Wedding_Party_RSVP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WGRSVP_Vendor_Catering_Export' ) ) {

	/**
	 * Build prose + CSV/PDF streams for kitchen staff.
	 */
	class WGRSVP_Vendor_Catering_Export {

		/**
		 * Aggregate accepted guests by table for display/export.
		 *
		 * @param array<int,array<string,mixed>> $rows Guest rows (assoc, DB columns).
		 * @param bool                           $accepted_only Only include Accepted RSVPs.
		 * @return array<string,array{meals:array<string,int>,diet:array<int,string>,allergy:array<int,string>,count:int}>
		 */
		public static function aggregate_by_table( array $rows, $accepted_only = true ) {
			$tables = array();
			foreach ( $rows as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$st = isset( $r['rsvp_status'] ) ? (string) $r['rsvp_status'] : '';
				if ( $accepted_only && 'Accepted' !== $st ) {
					continue;
				}
				$tbl = isset( $r['table_number'] ) ? trim( (string) $r['table_number'] ) : '';
				if ( '' === $tbl ) {
					$tbl = '—';
				}
				if ( ! isset( $tables[ $tbl ] ) ) {
					$tables[ $tbl ] = array(
						'meals'   => array(),
						'diet'    => array(),
						'allergy' => array(),
						'count'   => 0,
					);
				}
				++$tables[ $tbl ]['count'];
				$meal_key = self::meal_label_for_row( $r );
				if ( ! isset( $tables[ $tbl ]['meals'][ $meal_key ] ) ) {
					$tables[ $tbl ]['meals'][ $meal_key ] = 0;
				}
				++$tables[ $tbl ]['meals'][ $meal_key ];
				$d = isset( $r['dietary_restrictions'] ) ? trim( (string) $r['dietary_restrictions'] ) : '';
				if ( '' !== $d ) {
					$tables[ $tbl ]['diet'][ $d ] = $d;
				}
				$a = isset( $r['allergies'] ) ? trim( (string) $r['allergies'] ) : '';
				if ( '' !== $a ) {
					$tables[ $tbl ]['allergy'][ $a ] = $a;
				}
			}
			return $tables;
		}

		/**
		 * @param array<string,mixed> $r Row.
		 * @return string
		 */
		private static function meal_label_for_row( array $r ) {
			$is_child = ! empty( $r['is_child'] );
			$child_m  = isset( $r['child_menu_choice'] ) ? trim( (string) $r['child_menu_choice'] ) : '';
			$adult_m  = isset( $r['menu_choice'] ) ? trim( (string) $r['menu_choice'] ) : '';
			if ( $is_child || '' !== $child_m ) {
				return '' !== $child_m
					/* translators: %s: child entrée label */
					? sprintf( __( 'Child: %s', 'wedding-party-rsvp' ), $child_m )
					: __( 'Child meal (not set)', 'wedding-party-rsvp' );
			}
			return '' !== $adult_m ? $adult_m : __( 'Adult entrée (not set)', 'wedding-party-rsvp' );
		}

		/**
		 * One-line summary per table.
		 *
		 * @param string $table_label Table name.
		 * @param array  $bucket      aggregate bucket.
		 * @return string
		 */
		public static function prose_for_table( $table_label, array $bucket ) {
			$parts = array();
			foreach ( $bucket['meals'] as $label => $n ) {
				$parts[] = (int) $n . ' ' . $label;
			}
			$meal_str = implode( ', ', $parts );
			$diet     = implode( ', ', array_values( $bucket['diet'] ) );
			$allg     = implode( ', ', array_values( $bucket['allergy'] ) );
			$extras   = array();
			if ( '' !== $diet ) {
				$extras[] = $diet;
			}
			if ( '' !== $allg ) {
				/* translators: %s: allergy text */
				$extras[] = sprintf( __( 'Allergies: %s', 'wedding-party-rsvp' ), $allg );
			}
			$suffix = ! empty( $extras ) ? ' (' . implode( '; ', $extras ) . ')' : '';

			return sprintf(
				/* translators: 1: table label, 2: meal counts, 3: dietary/allergy suffix */
				__( 'Table %1$s: %2$s%3$s.', 'wedding-party-rsvp' ),
				$table_label,
				$meal_str,
				$suffix
			);
		}

		/**
		 * Stream CSV download.
		 *
		 * @param array<int,array<string,mixed>> $rows          Raw rows (same as list export).
		 * @param bool                           $accepted_only Limit aggregation to Accepted.
		 * @param string|null                    $unused        Ignored. Kept for call compatibility; text domain is always wedding-party-rsvp.
		 * @return void
		 */
		public static function stream_csv( array $rows, $accepted_only, $unused = null ) {
			unset( $unused );
			$agg = self::aggregate_by_table( $rows, $accepted_only );
			self::sort_table_keys( $agg );
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="wedding-caterer-summary-' . gmdate( 'Y-m-d' ) . '.csv"' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$f = fopen( 'php://output', 'w' );
			fputcsv(
				$f,
				array(
					__( 'Table', 'wedding-party-rsvp' ),
					__( 'Guest count', 'wedding-party-rsvp' ),
					__( 'Summary', 'wedding-party-rsvp' ),
					__( 'Meal breakdown', 'wedding-party-rsvp' ),
					__( 'Dietary notes', 'wedding-party-rsvp' ),
					__( 'Allergy notes', 'wedding-party-rsvp' ),
				)
			);
			foreach ( $agg as $tbl => $bucket ) {
				$breakdown = array();
				foreach ( $bucket['meals'] as $lab => $n ) {
					$breakdown[] = (int) $n . '× ' . $lab;
				}
				fputcsv(
					$f,
					array(
						$tbl,
						$bucket['count'],
						self::prose_for_table( $tbl, $bucket ),
						implode( '; ', $breakdown ),
						implode( ' | ', array_values( $bucket['diet'] ) ),
						implode( ' | ', array_values( $bucket['allergy'] ) ),
					)
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $f );
			exit;
		}

		/**
		 * @param array<string,array> $agg Aggregates (by reference sort keys).
		 * @return void
		 */
		public static function sort_table_keys( array &$agg ) {
			$keys = array_keys( $agg );
			usort(
				$keys,
				static function ( $a, $b ) {
					if ( '—' === $a ) {
						return 1;
					}
					if ( '—' === $b ) {
						return -1;
					}
					return strnatcasecmp( (string) $a, (string) $b );
				}
			);
			$sorted = array();
			foreach ( $keys as $k ) {
				$sorted[ $k ] = $agg[ $k ];
			}
			$agg = $sorted;
		}

		/**
		 * Stream PDF (FPDF).
		 *
		 * @param array<int,array<string,mixed>> $rows          Rows.
		 * @param bool                           $accepted_only Accepted only.
		 * @param string|null                    $unused        Ignored. Kept for call compatibility; text domain is always wedding-party-rsvp.
		 * @return void
		 */
		public static function stream_pdf( array $rows, $accepted_only, $unused = null ) {
			unset( $unused );
			$fpdf = __DIR__ . '/lib/fpdf/fpdf.php';
			if ( ! is_readable( $fpdf ) ) {
				wp_die( esc_html__( 'PDF export is unavailable.', 'wedding-party-rsvp' ), esc_html__( 'Export', 'wedding-party-rsvp' ), array( 'response' => 500 ) );
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Local library path; is_readable checked.
			require_once $fpdf;

			$agg = self::aggregate_by_table( $rows, $accepted_only );
			self::sort_table_keys( $agg );

			$pdf = new FPDF( 'P', 'mm', 'Letter' );
			$pdf->SetMargins( 12, 12, 12 );
			$pdf->SetAutoPageBreak( true, 15 );
			$pdf->AddPage();
			$pdf->SetFont( 'Helvetica', 'B', 14 );
			$pdf->Cell( 0, 8, self::pdf_txt( __( 'Caterer summary (by table)', 'wedding-party-rsvp' ) ), 0, 1 );
			$pdf->SetFont( 'Helvetica', '', 9 );
			$pdf->Cell(
				0,
				6,
				self::pdf_txt(
					sprintf(
						/* translators: %s: datetime */
						__( 'Generated %s — meal counts include only guests shown below.', 'wedding-party-rsvp' ),
						wp_date( 'Y-m-d H:i' )
					)
				),
				0,
				1
			);
			$pdf->Ln( 2 );
			if ( empty( $agg ) ) {
				$pdf->SetFont( 'Helvetica', 'I', 10 );
				$pdf->MultiCell( 0, 6, self::pdf_txt( __( 'No guests matched the current filters (try Accepted only or broaden filters).', 'wedding-party-rsvp' ) ) );
			} else {
				foreach ( $agg as $tbl => $bucket ) {
					$pdf->SetFont( 'Helvetica', 'B', 11 );
					$pdf->MultiCell( 0, 6, self::pdf_txt( self::prose_for_table( $tbl, $bucket ) ) );
					$pdf->SetFont( 'Helvetica', '', 9 );
					$lines = array();
					foreach ( $bucket['meals'] as $lab => $n ) {
						$lines[] = ' • ' . (int) $n . ' ' . $lab;
					}
					$pdf->MultiCell( 0, 5, self::pdf_txt( implode( "\n", $lines ) ) );
					$pdf->Ln( 2 );
				}
			}
			$fname = 'wedding-caterer-summary-' . gmdate( 'Y-m-d' ) . '.pdf';
			$pdf->Output( 'D', $fname );
			exit;
		}

		/**
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
