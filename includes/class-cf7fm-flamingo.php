<?php
/**
 * Integrazione con Flamingo per la gestione dei file caricati tramite Contact Form 7
 * 
 * @package CF7FileManager
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
		add_action( 'wpcf7_before_send_mail', array( $this, 'store_files_data' ), 10 );
		add_filter( 'flamingo_add_inbound', array( $this, 'save_files_meta' ), 10 );
		add_action( 'admin_head', array( $this, 'add_styles' ) );
		add_action( 'admin_footer', array( $this, 'add_scripts' ) );
	}

	/**
	 * Salva i file nei meta di Flamingo
	 */
	public function save_files_meta( $args ) {
		if ( ! empty( $this->temp_files_data ) ) {
			$args['meta']['_cf7fm_files'] = $this->temp_files_data;
		}
		return $args;
	}

	/**
	 * Aggiunge gli stili CSS
	 */
	public function add_styles() {
		?>
		<style>
			.cf7fm-file {
				margin: 5px 0;
				display: flex;
				align-items: center;
				gap: 10px;
			}

			.cf7fm-file-link {
				color: #2271b1;
				text-decoration: none;
				font-size: 13px;
			}

			.cf7fm-file-link:hover {
				color: #135e96;
				text-decoration: underline;
			}

			.cf7fm-file-size {
				color: #666;
				font-size: 12px;
			}
		</style>
		<?php
	}

	/**
	 * Aggiunge gli script JavaScript
	 */
	public function add_scripts() {
		?>
		<script>
			jQuery(document).ready(function ($) {
				function formatMetaFiles() {
					$('.field-title').each(function () {
						var $title = $(this);
						if ($title.text() === '_cf7fm_files') {
							// Cambia il testo del titolo
							$title.text('File allegati');

							var $value = $title.next('.field-value');
							var files = [];

							// Trova tutte le liste di file
							$value.find('ul > li > ul > li > ul').each(function () {
								var $items = $(this).find('li p');
								if ($items.length >= 4) {
									files.push({
										url: $items.eq(2).text().trim(),
										name: $items.eq(1).text().trim(),
										size: $items.eq(3).text().trim()
									});
								}
							});

							// Se abbiamo trovato dei file, formatta l'output
							if (files.length > 0) {
								var html = files.map(function (file) {
									return '<div class="cf7fm-file">' +
										'<a href="' + file.url + '" target="_blank" class="cf7fm-file-link">' +
										file.name + '</a>' +
										'<span class="cf7fm-file-size">' + file.size + '</span>' +
										'</div>';
								}).join('');

								$value.html(html);
							}
						}
					});
				}

				// Formatta all'avvio
				formatMetaFiles();

				// Formatta dopo un breve ritardo
				setTimeout(formatMetaFiles, 500);

				// Osserva le modifiche al DOM
				var observer = new MutationObserver(function (mutations) {
					mutations.forEach(function (mutation) {
						if (mutation.addedNodes.length) {
							formatMetaFiles();
						}
					});
				});

				// Avvia l'observer
				var container = document.querySelector('.wrap');
				if (container) {
					observer.observe(container, {
						childList: true,
						subtree: true
					});
				}
			});
		</script>
		<?php
	}

	/**
	 * Memorizza i dati dei file durante l'invio del form
	 */
	public function store_files_data( $contact_form ) {
		$this->temp_files_data = array();

		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$uploaded_files = $submission->uploaded_files();
		if ( empty( $uploaded_files ) ) {
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

	/**
	 * Verifica se Flamingo è attivo
	 */
	public static function is_flamingo_active() {
		return defined( 'FLAMINGO_VERSION' );
	}
}