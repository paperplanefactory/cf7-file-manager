<?php
/**
 * Integrazione con Flamingo
 * 
 * @package CF7FileManager
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7FM_Flamingo {
	private $uploads_manager;

	public function __construct( $uploads_manager ) {
		$this->uploads_manager = $uploads_manager;
		$this->init_hooks();
	}

	private function init_hooks() {

	}

	public static function is_flamingo_active() {
		error_log( 'Checking if Flamingo is active' );
		return defined( 'FLAMINGO_VERSION' );
	}
}