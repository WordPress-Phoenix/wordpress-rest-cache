<?php
/**
 * Class WRC_Admin_Utility
 *
 * Utility functions used to help and simplify building admin html parts
 *
 */

class WRC_Admin_Utility {
	/**
	 * Standardizes printing of error or update messages after form submissions
	 *
	 * @param $type
	 * @param $content
	 *
	 * @return string
	 */
	static function get_action_message( $type, $content ) {
		$msg = '<div class="' . $type . '">';
		$msg .= '<p>' . $content . '</p>';
		$msg .= '</div>';

		return apply_filters( 'wrc_admin_build_page_action_message', $msg, $type, $content );
	}

	/**
	 * Fancy html table printer
	 *
	 * @param $csv_content
	 *
	 * @return string
	 */
	public static function csv_to_table( $csv_content ) {
		if ( empty( $csv_content ) || ! stristr( $csv_content, PHP_EOL ) ) {
			return $csv_content;
		}
		$table = '<table style="width: 100%; text-align: left;">';
		// convert csv into array
		$rows = str_getcsv( $csv_content, "\n" );

		// pull first row off and build table header
		$table .= '<thead><tr>';
		$header_row = array_shift( $rows );
		$cells      = str_getcsv( $header_row );
		foreach ( $cells as &$cell ) {
			$table .= "<th>$cell</th>";
		}
		$table .= '</thead>';

		//build table body data
		$table .= '<tbody>';
		foreach ( $rows as &$row ) {
			$table .= '<tr>';
			$cells = str_getcsv( $row );
			foreach ( $cells as &$cell ) {
				if ( substr( $cell, 0, 2 ) == '{{' ) {
					$cell = static::do_cell_shortcode( $cell );
				}
				$table .= "<td>$cell</td>";
			}
			$table .= '</tr>';
		}
		$table .= '</tbody></table>';

		return $table;
	}

	/**
	 * Shortcodes for CSV strings based on {{label__value1__value2}} string pattern
	 *
	 * @param $shortcode
	 *
	 * @return string
	 */
	public static function do_cell_shortcode( $shortcode ) {
		$shortcode = trim( $shortcode, '{}' );
		$data      = explode( '__', $shortcode );
		$action    = array_shift( $data );
		switch ( $action ) {
			case 'action_delete':
				if ( empty( $data[0] ) ) {
					return '';
				}
				$out = '<form method="post" action="" id="delete_' . $data[0] . '">';
				$out .= '<button type="submit" name="wrc-entry-delete" value="' . $data[0] . '">Delete</button>';
				$out .= '</form>';

				return $out;
		}

		return 'cell_shortcode not found';
	}

}
