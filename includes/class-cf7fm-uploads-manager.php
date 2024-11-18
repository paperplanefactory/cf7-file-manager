<?php
/**
 * Gestione degli upload dei file
 * 
 * @package CF7FileManager
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7FM_Uploads_Manager {
	/**
	 * @var string Directory base per gli upload
	 */
	private $base_upload_dir;

	/**
	 * @var int File per pagina nella lista admin
	 */
	private $per_page = 20;

	/**
	 * Costruttore
	 */
	public function __construct() {
		$upload_dir = wp_upload_dir();
		$this->base_upload_dir = $upload_dir['basedir'] . '/cf7-uploads/';

		// Inizializzazione
		$this->init();
		add_action( 'wp_ajax_delete_multiple_cf7_files', array( $this, 'delete_multiple_files' ) );
	}

	/**
	 * Inizializzazione
	 */
	private function init() {
		// Controlla/crea directory base
		$this->create_secure_upload_directory();

		// Hooks
		add_action( 'wp_ajax_delete_cf7_file', array( $this, 'ajax_delete_file' ) );
		add_action( 'wpcf7_before_send_mail', array( $this, 'handle_file_upload' ) );
		add_action( 'plugins_loaded', array( $this, 'check_db_version' ) );

		cf7fm_log( 'CF7FM Upload Manager inizializzato' );
	}

	/**
	 * Crea e protegge la directory di upload
	 * 
	 * @return bool
	 */
	private function create_secure_upload_directory() {
		if ( ! file_exists( $this->base_upload_dir ) ) {
			if ( ! wp_mkdir_p( $this->base_upload_dir ) ) {
				cf7fm_log( 'ERRORE: Impossibile creare directory upload' );
				return false;
			}

			chmod( $this->base_upload_dir, 0755 );

			// File di protezione
			file_put_contents( $this->base_upload_dir . '.htaccess', "Options -Indexes\nDeny from all" );
			file_put_contents( $this->base_upload_dir . 'index.php', '<?php // Silence is golden' );

			cf7fm_log( 'Directory upload creata e protetta' );
		}

		return true;
	}

	/**
	 * Gestisce l'upload dei file da CF7
	 * 
	 * @param WPCF7_ContactForm $contact_form
	 */
	public function handle_file_upload( $contact_form ) {
		cf7fm_log( 'Inizio gestione upload file' );

		try {
			$submission = WPCF7_Submission::get_instance();
			if ( ! $submission ) {
				throw new Exception( 'Nessuna submission trovata' );
			}

			$uploaded_files = $submission->uploaded_files();
			if ( empty( $uploaded_files ) ) {
				cf7fm_log( 'Nessun file da processare' );
				return;
			}

			$form_folder = $this->get_form_folder( $contact_form->id() );
			if ( ! $form_folder ) {
				throw new Exception( 'Impossibile creare cartella form' );
			}

			$saved_files = array();

			foreach ( $uploaded_files as $field_name => $file_path ) {
				$files = is_array( $file_path ) ? $file_path : array( $file_path );

				foreach ( $files as $file ) {
					if ( empty( $file ) || ! file_exists( $file ) ) {
						continue;
					}

					$saved_file = $this->process_uploaded_file( $file, $form_folder, $field_name );
					if ( $saved_file ) {
						$saved_files[] = array_merge( $saved_file, array(
							'form_id' => $contact_form->id(),
							'field_name' => $field_name
						) );
					}
				}
			}

			if ( ! empty( $saved_files ) ) {
				$this->save_files_to_db( $saved_files );
			}

		} catch (Exception $e) {
			cf7fm_log( 'Errore upload: ' . $e->getMessage() );
		}
	}

	/**
	 * Processa un singolo file caricato
	 * 
	 * @param string $file_path Path del file temporaneo
	 * @param string $form_folder Cartella di destinazione
	 * @param string $field_name Nome del campo form
	 * @return array|false
	 */
	private function process_uploaded_file( $file_path, $form_folder, $field_name ) {
		try {
			$file_info = pathinfo( $file_path );
			$original_name = $file_info['basename'];
			$extension = strtolower( $file_info['extension'] );

			// Genera nome file sicuro
			$new_name = uniqid() . '-' . sanitize_file_name( $original_name );
			$new_path = $form_folder . $new_name;

			if ( ! copy( $file_path, $new_path ) ) {
				throw new Exception( 'Errore copia file' );
			}

			chmod( $new_path, 0644 );

			return array(
				'file_name' => $new_name,
				'original_name' => $original_name,
				'file_path' => str_replace( $this->base_upload_dir, '', $new_path ),
				'file_type' => $extension,
				'file_size' => filesize( $new_path ),
				'uploaded_at' => current_time( 'mysql' )
			);

		} catch (Exception $e) {
			cf7fm_log( 'Errore processing file: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Ottiene la cartella per un form specifico
	 * 
	 * @param int $form_id ID del form
	 * @return string|false
	 */
	private function get_form_folder( $form_id ) {
		$form = wpcf7_contact_form( $form_id );
		if ( ! $form ) {
			return false;
		}

		$form_title = sanitize_title( $form->title() );
		$year = date( 'Y' );
		$month = date( 'm' );

		$path = $this->base_upload_dir . "{$form_title}/{$year}/{$month}/";

		if ( ! wp_mkdir_p( $path ) ) {
			return false;
		}

		return $path;
	}

	public function get_uploaded_files() {
		// Restituisce l'array dei file caricati
		return $this->uploaded_files;
	}

	/**
	 * Salva i file nel database
	 * 
	 * @param array $files Array dei file da salvare
	 */
	private function save_files_to_db( $files ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		foreach ( $files as $file ) {
			cf7fm_log( 'Salvataggio file nel database:' );
			cf7fm_log( print_r( $file, true ) );

			$data = array(
				'form_id' => $file['form_id'],
				'field_name' => $file['field_name'],
				'file_name' => $file['file_name'],
				'original_name' => $file['original_name'],
				'file_path' => $file['file_path'],
				'file_type' => $file['file_type'],
				'file_size' => $file['file_size'],
				'uploaded_at' => current_time( 'mysql', true )  // true per UTC
			);

			$result = $wpdb->insert( $table_name, $data );

			if ( $result === false ) {
				cf7fm_log( 'Errore inserimento database: ' . $wpdb->last_error );
			} else {
				cf7fm_log( 'File salvato nel database con ID: ' . $wpdb->insert_id );
			}
		}
	}

	/**
	 * Gestisce la richiesta AJAX di eliminazione file
	 */
	public function ajax_delete_file() {
		try {
			check_ajax_referer( 'cf7fm_nonce', 'nonce' );

			if ( ! current_user_can( 'edit_others_posts' ) ) {
				throw new Exception( __( 'Permessi insufficienti', 'cf7-file-manager' ) );
			}

			$file_id = filter_input( INPUT_POST, 'file_id', FILTER_VALIDATE_INT );
			if ( ! $file_id ) {
				throw new Exception( __( 'ID file non valido', 'cf7-file-manager' ) );
			}

			$file = $this->get_file( $file_id );
			if ( ! $file ) {
				throw new Exception( __( 'File non trovato', 'cf7-file-manager' ) );
			}

			if ( $this->delete_file( $file_id ) ) {
				wp_send_json_success( __( 'File eliminato con successo', 'cf7-file-manager' ) );
			} else {
				throw new Exception( __( 'Errore eliminazione file', 'cf7-file-manager' ) );
			}

		} catch (Exception $e) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Elimina un file
	 * 
	 * @param int $file_id ID del file
	 * @return bool
	 */
	public function delete_file( $file_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		$file = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$file_id
		) );

		if ( ! $file ) {
			return false;
		}

		$file_path = $this->base_upload_dir . $file->file_path;

		if ( file_exists( $file_path ) && ! unlink( $file_path ) ) {
			return false;
		}

		return $wpdb->delete( $table_name, array( 'id' => $file_id ), array( '%d' ) );
	}

	/**
	 * Ottiene un file specifico
	 * 
	 * @param int $file_id ID del file
	 * @return object|null
	 */
	public function get_file( $file_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$file_id
		) );
	}

	/**
	 * Ottiene la lista dei file con filtri
	 * 
	 * @param int $page Pagina corrente
	 * @param array $filters Filtri
	 * @return array
	 */
	public function get_files( $page = 1, $filters = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		// Query base
		$sql = "SELECT * FROM {$table_name} WHERE 1=1";
		$params = array();

		// Applica filtri
		if ( ! empty( $filters['form_id'] ) ) {
			$sql .= " AND form_id = %d";
			$params[] = $filters['form_id'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$sql .= " AND (file_name LIKE %s OR original_name LIKE %s)";
			$search = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$params[] = $search;
			$params[] = $search;
		}

		// Ordinamento e limit
		$sql .= " ORDER BY uploaded_at DESC";

		// Query totale
		$total_items = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE 1=1" .
			( ! empty( $filters['form_id'] ) ? " AND form_id = %d" : "" ) .
			( ! empty( $filters['search'] ) ? " AND (file_name LIKE %s OR original_name LIKE %s)" : "" ),
			$params
		) );

		// Paginazione
		$offset = ( $page - 1 ) * $this->per_page;
		$sql .= " LIMIT %d OFFSET %d";
		$params[] = $this->per_page;
		$params[] = $offset;

		// Esegui query
		$files = $wpdb->get_results(
			$params ? $wpdb->prepare( $sql, $params ) : $sql,
			ARRAY_A
		);

		// Processa risultati
		if ( $files ) {
			foreach ( $files as &$file ) {
				$file['size_formatted'] = size_format( $file['file_size'] );
				// Converti la data MySQL in timestamp
				$timestamp = strtotime( $file['uploaded_at'] . ' UTC' );  // Aggiungiamo UTC per essere sicuri
				// Debug
				cf7fm_log( 'Original date: ' . $file['uploaded_at'] );
				cf7fm_log( 'Timestamp: ' . $timestamp );


				// Formatta la data usando wp_date() che gestisce il fuso orario di WordPress
				$file['upload_date_formatted'] = wp_date(
					sprintf(
						'%s %s',
						get_option( 'date_format' ),
						get_option( 'time_format' )
					),
					$timestamp
				);

				// Debug
				cf7fm_log( 'Formatted date: ' . $file['upload_date_formatted'] );
			}
		}

		return array(
			'files' => $files ?: array(),
			'total' => $total_items,
			'pages' => ceil( $total_items / $this->per_page ),
			'current_page' => $page
		);
	}

	/**
	 * Crea/aggiorna la tabella del database
	 */
	public static function create_database_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
           id bigint(20) NOT NULL AUTO_INCREMENT,
           form_id bigint(20) NOT NULL,
           field_name varchar(100) NOT NULL,
           file_name varchar(255) NOT NULL,
           original_name varchar(255) NOT NULL,
           file_path text NOT NULL,
           file_type varchar(50) NOT NULL,
           file_size bigint(20) NOT NULL,
           uploaded_at datetime NOT NULL,
           PRIMARY KEY (id),
           KEY form_id (form_id),
           KEY file_type (file_type),
           KEY uploaded_at (uploaded_at)
       ) {$charset_collate};";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	/**
	 * Controlla la versione del database
	 */
	public function check_db_version() {
		if ( get_option( 'cf7fm_db_version' ) !== CF7FM_VERSION ) {
			self::create_database_table();
			update_option( 'cf7fm_db_version', CF7FM_VERSION );
		}
	}

	/**
	 * Ottiene il percorso completo di un file
	 * 
	 * @param object $file Record del file dal database
	 * @return string
	 */
	public function get_file_path( $file ) {
		return $this->base_upload_dir . $file->file_path;
	}

	/**
	 * Genera URL sicuro per un file
	 * 
	 * @param int $file_id ID del file
	 * @param bool $download Flag per download
	 * @return string
	 */
	public function get_file_url( $file_id, $download = false ) {
		$url = home_url( 'cf7fm-file/' . $file_id );
		return $download ? add_query_arg( 'download', '1', $url ) : $url;
	}

	public function delete_multiple_files() {
		check_ajax_referer( 'cf7fm_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array(
				'message' => __( 'Permessi insufficienti', 'cf7-file-manager' )
			) );
		}

		$file_ids = isset( $_POST['file_ids'] ) ? array_map( 'intval', $_POST['file_ids'] ) : array();

		if ( empty( $file_ids ) ) {
			wp_send_json_error( array(
				'message' => __( 'Nessun file selezionato', 'cf7-file-manager' )
			) );
		}

		$deleted = 0;
		$errors = 0;

		foreach ( $file_ids as $file_id ) {
			if ( $this->delete_file( $file_id ) ) {
				$deleted++;
			} else {
				$errors++;
			}
		}

		if ( $errors === 0 ) {
			wp_send_json_success( array(
				'message' => sprintf(
					__( '%d file eliminati con successo', 'cf7-file-manager' ),
					$deleted
				)
			) );
		} else {
			wp_send_json_error( array(
				'message' => sprintf(
					__( 'Eliminati %d file, %d errori', 'cf7-file-manager' ),
					$deleted,
					$errors
				)
			) );
		}
	}
}
