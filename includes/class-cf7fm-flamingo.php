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
		add_action( 'wpcf7_mail_sent', array( $this, 'store_custom_field' ) );
		add_filter( 'flamingo_inbound_fields_table', array( $this, 'modify_fields_table' ), 10, 2 );
		cf7fm_log( 'Hook Flamingo inizializzato' );
	}


	public function store_custom_field( $contact_form ) {
		cf7fm_log( 'Store custom field chiamato' );
		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'flamingo_inbound'
		);

		$messages = Flamingo_Inbound_Message::find( $args );
		foreach ( $messages as $message ) {
			$fields = $message->fields;
			cf7fm_log( 'Fields correnti: ' . print_r( $fields, true ) );
			$fields['poppa'] = 'prova';
			$message->fields = $fields;
			$message->save();
			cf7fm_log( 'Salvato campo poppa per message ID: ' . $message->id() );
		}
	}

	public function modify_fields_table( $content, $message ) {
		cf7fm_log( 'Modify fields table chiamato per message: ' . $message->id() );
		return $content . '<tr><th>poppa</th><td>prova</td></tr>';
	}

	public static function is_flamingo_active() {
		return defined( 'FLAMINGO_VERSION' );
	}
}