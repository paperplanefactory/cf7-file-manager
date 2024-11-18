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
	private $temp_files_data = array();

	public function __construct( $uploads_manager ) {
		$this->uploads_manager = $uploads_manager;
		$this->init_hooks();
	}

	private function init_hooks() {
		cf7fm_log( 'Setting up Flamingo hooks' );
		add_action( 'wpcf7_mail_sent', array( $this, 'store_files_data' ) );
		add_action( 'flamingo_post_add_inbound', array( $this, 'add_files_to_flamingo' ), 10, 1 );
		add_filter( 'flamingo_inbound_fields_table_column', array( $this, 'format_file_field' ), 10, 3 );
		add_action( 'admin_head', array( $this, 'add_custom_styles' ) );
	}

	public function store_files_data( $contact_form ) {
		cf7fm_log( '=== STORING FILES DATA ===' );

		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			cf7fm_log( 'No submission found' );
			return;
		}

		$uploaded_files = $submission->uploaded_files();
		if ( empty( $uploaded_files ) ) {
			cf7fm_log( 'No files to process' );
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		$files = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name} 
             WHERE form_id = %d 
             AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
             ORDER BY id DESC",
			$contact_form->id()
		) );

		if ( $files ) {
			foreach ( $files as $file ) {
				if ( ! isset( $this->temp_files_data[ $file->field_name ] ) ) {
					$this->temp_files_data[ $file->field_name ] = array();
				}

				$file_url = $this->uploads_manager->get_file_url( $file->id );
				$this->temp_files_data[ $file->field_name ][] = array(
					'id' => $file->id,
					'name' => $file->original_name,
					'url' => $file_url,
					'size' => size_format( $file->file_size )
				);
			}
		}
	}

	public function add_files_to_flamingo( $inbound_message ) {
		cf7fm_log( '=== ADDING FILES TO FLAMINGO ===' );
		cf7fm_log( 'Files data:' );
		cf7fm_log( $this->temp_files_data );

		if ( ! empty( $this->temp_files_data ) ) {
			update_post_meta( $inbound_message->id(), '_cf7fm_files', $this->temp_files_data );
			cf7fm_log( 'Files meta saved for message: ' . $inbound_message->id() );
			$this->temp_files_data = array();
		}
	}

	public function format_file_field( $value, $key, $message ) {
		$files = get_post_meta( $message->id(), '_cf7fm_files', true );

		if ( ! empty( $files ) && isset( $files[ $key ] ) ) {
			$output = '';
			foreach ( $files[ $key ] as $file ) {
				$output .= sprintf(
					'<a href="%s" target="_blank">%s</a> (%s)<br>',
					esc_url( $file['url'] ),
					esc_html( $file['name'] ),
					esc_html( $file['size'] )
				);
			}
			return wp_kses_post( $output );
		}

		return esc_html( $value );
	}

	public function add_custom_styles() {
		?>
		<style>
			.flamingo-inbound-fields td a {
				color: #2271b1;
				text-decoration: none;
			}

			.flamingo-inbound-fields td a:hover {
				color: #135e96;
				text-decoration: underline;
			}
		</style>
		<?php
	}

	public static function is_flamingo_active() {
		return defined( 'FLAMINGO_VERSION' );
	}
}
