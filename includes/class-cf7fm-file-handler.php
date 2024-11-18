<?php
/**
 * Classe per la gestione dei file
 * 
 * @package CF7FileManager
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7FM_File_Handler {
	/**
	 * @var string Directory base per gli upload
	 */
	private $base_upload_dir;

	/**
	 * @var array Tipi MIME supportati
	 */
	private $allowed_mime_types;

	/**
	 * Costruttore
	 */
	public function __construct() {
		$upload_dir = wp_upload_dir();
		$this->base_upload_dir = $upload_dir['basedir'] . '/cf7-uploads/';
		$this->allowed_mime_types = $this->get_allowed_mime_types();

		// Crea la cartella base se non esiste
		$this->create_secure_upload_directory();
	}

	/**
	 * Crea la cartella sicura per gli upload
	 */
	private function create_secure_upload_directory() {
		if ( ! file_exists( $this->base_upload_dir ) ) {
			if ( ! wp_mkdir_p( $this->base_upload_dir ) ) {
				cf7fm_log( 'ERRORE: Impossibile creare la directory per gli upload' );
				return false;
			}

			// Proteggi la cartella
			$this->secure_upload_directory( $this->base_upload_dir );
		}
		return true;
	}

	/**
	 * Protegge la directory degli upload
	 * 
	 * @param string $directory Percorso directory
	 */
	private function secure_upload_directory( $directory ) {
		// Crea .htaccess
		$htaccess_content = "Options -Indexes\nDeny from all";
		file_put_contents( $directory . '.htaccess', $htaccess_content );

		// Crea index.php
		file_put_contents( $directory . 'index.php', '<?php // Silence is golden' );

		// Imposta permessi corretti
		chmod( $directory, 0755 );
	}

	/**
	 * Gestisce l'upload di un file
	 * 
	 * @param array $file Array file da $_FILES
	 * @param int $form_id ID del form CF7
	 * @param string $field_name Nome del campo form
	 * @return array|WP_Error
	 */
	public function handle_upload( $file, $form_id, $field_name ) {
		try {
			// Validazione base
			if ( ! isset( $file['tmp_name'] ) || empty( $file['tmp_name'] ) ) {
				throw new Exception( __( 'File non valido', 'cf7-file-manager' ) );
			}

			// Verifica errori upload
			if ( $file['error'] !== UPLOAD_ERR_OK ) {
				throw new Exception( $this->get_upload_error_message( $file['error'] ) );
			}

			// Verifica tipo MIME
			$mime_type = $this->get_mime_type( $file['tmp_name'] );
			if ( ! in_array( $mime_type, $this->allowed_mime_types ) ) {
				throw new Exception( __( 'Tipo file non supportato', 'cf7-file-manager' ) );
			}

			// Crea struttura cartelle
			$upload_dir = $this->create_upload_structure( $form_id );
			if ( ! $upload_dir ) {
				throw new Exception( __( 'Errore creazione directory', 'cf7-file-manager' ) );
			}

			// Genera nome file sicuro
			$filename = $this->generate_unique_filename( $file['name'], $upload_dir );
			$upload_path = $upload_dir . $filename;

			// Sposta il file
			if ( ! move_uploaded_file( $file['tmp_name'], $upload_path ) ) {
				throw new Exception( __( 'Errore durante il caricamento del file', 'cf7-file-manager' ) );
			}

			// Imposta permessi corretti
			chmod( $upload_path, 0644 );

			// Prepara dati file
			return array(
				'name' => $filename,
				'original_name' => $file['name'],
				'path' => str_replace( $this->base_upload_dir, '', $upload_path ),
				'mime_type' => $mime_type,
				'size' => filesize( $upload_path ),
				'form_id' => $form_id,
				'field_name' => $field_name
			);

		} catch (Exception $e) {
			cf7fm_log( 'Errore upload: ' . $e->getMessage() );
			return new WP_Error( 'upload_error', $e->getMessage() );
		}
	}

	/**
	 * Crea la struttura delle cartelle per gli upload
	 * 
	 * @param int $form_id ID del form
	 * @return string|false
	 */
	private function create_upload_structure( $form_id ) {
		try {
			$form = wpcf7_contact_form( $form_id );
			if ( ! $form ) {
				throw new Exception( 'Form non trovato' );
			}

			$form_slug = sanitize_title( $form->title() );
			$year = date( 'Y' );
			$month = date( 'm' );

			$path = $this->base_upload_dir . "{$form_slug}/{$year}/{$month}/";

			if ( ! wp_mkdir_p( $path ) ) {
				throw new Exception( 'Impossibile creare la struttura directory' );
			}

			return $path;

		} catch (Exception $e) {
			cf7fm_log( 'Errore creazione struttura: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Genera un nome file unico
	 * 
	 * @param string $original_name Nome originale
	 * @param string $upload_dir Directory upload
	 * @return string
	 */
	private function generate_unique_filename( $original_name, $upload_dir ) {
		$info = pathinfo( $original_name );
		$ext = strtolower( $info['extension'] );
		$name = sanitize_file_name( $info['filename'] );

		$filename = $name . '.' . $ext;
		$counter = 1;

		while ( file_exists( $upload_dir . $filename ) ) {
			$filename = $name . '-' . $counter . '.' . $ext;
			$counter++;
		}

		return $filename;
	}

	/**
	 * Elimina un file
	 * 
	 * @param string $file_path Percorso relativo del file
	 * @return bool
	 */
	public function delete_file( $file_path ) {
		$full_path = $this->base_upload_dir . $file_path;

		if ( ! file_exists( $full_path ) ) {
			cf7fm_log( 'File non trovato: ' . $full_path );
			return false;
		}

		if ( ! unlink( $full_path ) ) {
			cf7fm_log( 'Impossibile eliminare il file: ' . $full_path );
			return false;
		}

		return true;
	}

	/**
	 * Ottiene il tipo MIME di un file
	 * 
	 * @param string $file_path Percorso del file
	 * @return string
	 */
	private function get_mime_type( $file_path ) {
		if ( function_exists( 'mime_content_type' ) ) {
			return mime_content_type( $file_path );
		}

		if ( function_exists( 'finfo_file' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type = finfo_file( $finfo, $file_path );
			finfo_close( $finfo );
			return $mime_type;
		}

		// Fallback su estensione
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$mime_types = $this->get_allowed_mime_types();
		return isset( $mime_types[ $ext ] ) ? $mime_types[ $ext ] : 'application/octet-stream';
	}

	/**
	 * Restituisce i tipi MIME supportati
	 * 
	 * @return array
	 */
	private function get_allowed_mime_types() {
		return array(
			// Immagini
			'jpg|jpeg|jpe' => 'image/jpeg',
			'gif' => 'image/gif',
			'png' => 'image/png',
			'webp' => 'image/webp',

			// Documenti
			'pdf' => 'application/pdf',
			'doc' => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls' => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'txt' => 'text/plain',
			'csv' => 'text/csv',

			// Archivi
			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
			'7z' => 'application/x-7z-compressed'
		);
	}

	/**
	 * Ottiene il messaggio di errore per gli errori di upload
	 * 
	 * @param int $error_code Codice errore
	 * @return string
	 */
	private function get_upload_error_message( $error_code ) {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
				return __( 'Il file supera la dimensione massima consentita dal server', 'cf7-file-manager' );
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'Il file supera la dimensione massima consentita dal form', 'cf7-file-manager' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'Il file è stato caricato solo parzialmente', 'cf7-file-manager' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Nessun file caricato', 'cf7-file-manager' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'Directory temporanea mancante', 'cf7-file-manager' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Impossibile scrivere il file su disco', 'cf7-file-manager' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'Upload bloccato da estensione PHP', 'cf7-file-manager' );
			default:
				return __( 'Errore sconosciuto durante il caricamento', 'cf7-file-manager' );
		}
	}
}