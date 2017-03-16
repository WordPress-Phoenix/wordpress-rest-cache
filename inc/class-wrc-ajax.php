<?php

/**
 * Class WRC_Admin_Utility
 *
 * Utility functions used to help and simplify building admin html parts
 *
 */
class WRC_Ajax {

	/**
	 * WRC ajax action router
	 */
	public static function run() {
		switch ( $_REQUEST['route'] ) {
			case 'rest_tags':
				static::print_for_suggest( WRC_DB::get_rest_tags( $_REQUEST['q'] ) );
				break;
		}

		exit;
	}

	/**
	 * @param $data
	 */
	public static function print_for_suggest( $data ) {
		foreach ( $data as $result ) {
			echo $result . "\n";
		}
	}
}
