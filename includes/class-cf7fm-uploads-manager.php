<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cf7fm_log' ) ) {
	function cf7fm_log( $message ) {
		if ( WP_DEBUG === true ) {
			if ( is_array( $message ) || is_object( $message ) ) {
				error_log( '[CF7FM] ' . print_r( $message, true ) );
			} else {
				error_log( '[CF7FM] ' . $message );
			}
		}
	}
}

class CF7FM_Uploads_Manager {
	private $base_upload_dir;
	private $per_page = 20;

	public function __construct() {
		$upload_dir = wp_upload_dir();
		$this->base_upload_dir = $upload_dir['basedir'] . '/cf7-uploads/';

		// Crea la cartella base se non esiste
		$this->create_secure_folder( $this->base_upload_dir );

		// Aggiungi hook per gestione AJAX
		add_action( 'wp_ajax_delete_cf7_file', array( $this, 'ajax_delete_file' ) );

		// Aggiungi hook per il salvataggio dei file
		add_action( 'wpcf7_before_send_mail', array( $this, 'save_uploaded_files' ) );

		// Log per debug
		cf7fm_log( 'CF7FM Manager inizializzato' );

		// Aggiungi l'aggiornamento del database
		add_action( 'plugins_loaded', array( $this, 'check_upgrade' ) );
	}

	public function check_upgrade() {
		// Versione attuale del plugin nel database
		$current_version = get_option( 'cf7fm_version' );

		if ( $current_version !== CF7FM_VERSION ) {
			CF7FM_Uploads_Manager::maybe_upgrade();
			update_option( 'cf7fm_version', CF7FM_VERSION );
		}
	}

	/**
	 * Crea e proteggi una cartella con gestione errori
	 */
	private function create_secure_folder( $folder_path ) {
		cf7fm_log( 'Tentativo di creare cartella: ' . $folder_path );

		if ( ! file_exists( $folder_path ) ) {
			// Crea tutte le cartelle nel percorso ricorsivamente
			if ( ! wp_mkdir_p( $folder_path ) ) {
				cf7fm_log( 'ERRORE: Impossibile creare la cartella: ' . $folder_path );
				return false;
			}

			// Imposta permessi corretti
			@chmod( $folder_path, 0777 );
			cf7fm_log( 'Cartella creata con permessi 777: ' . $folder_path );

			// Crea .htaccess
			$htaccess_content = "Options -Indexes\n";
			$htaccess_path = $folder_path . '.htaccess';
			@file_put_contents( $htaccess_path, $htaccess_content );

			// Crea index.php
			$index_path = $folder_path . 'index.php';
			@file_put_contents( $index_path, '<?php // Silence is golden' );
		}

		return true;
	}


	/**
	 * Ottieni il percorso della cartella per un form specifico
	 */
	private function get_form_folder( $form_id ) {
		cf7fm_log( 'Creazione cartella per form ID: ' . $form_id );

		$form = wpcf7_contact_form( $form_id );
		if ( ! $form ) {
			cf7fm_log( 'Form non trovato per ID: ' . $form_id );
			return false;
		}

		// Crea struttura cartelle
		$form_title = sanitize_title( $form->title() );
		$year = date( 'Y' );
		$month = date( 'm' );

		// Costruisci il percorso completo
		$upload_structure = array(
			$this->base_upload_dir,                    // Cartella base
			$form_title,                               // Cartella del form
			$year,                                     // Anno
			$month                                     // Mese
		);

		$current_path = '';
		foreach ( $upload_structure as $folder ) {
			$current_path .= $folder . '/';
			if ( ! $this->create_secure_folder( $current_path ) ) {
				cf7fm_log( 'ERRORE: Impossibile creare la struttura delle cartelle in: ' . $current_path );
				return false;
			}
		}

		cf7fm_log( 'Struttura cartelle creata con successo: ' . $current_path );
		return $current_path;
	}

	/**
	 * Verifica e imposta i permessi corretti per una cartella
	 */
	private function ensure_writable( $path ) {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		if ( ! is_writable( $path ) ) {
			@chmod( $path, 0777 );
			if ( ! is_writable( $path ) ) {
				cf7fm_log( 'ERRORE: Impossibile impostare i permessi di scrittura per: ' . $path );
				return false;
			}
		}

		return true;
	}

	/**
	 * Salva i file caricati tramite Contact Form 7
	 */
	/**
	 * Salva i file caricati tramite Contact Form 7
	 */
	/**
	 * Salva i file caricati tramite Contact Form 7
	 */
	public function save_uploaded_files( $contact_form ) {
		cf7fm_log( '=== INIZIO SALVATAGGIO FILE ===' );

		try {
			$submission = WPCF7_Submission::get_instance();
			if ( ! $submission ) {
				cf7fm_log( 'Nessuna submission trovata' );
				return;
			}

			$uploaded_files = $submission->uploaded_files();

			// Log sicuro dei file caricati
			if ( ! empty( $uploaded_files ) ) {
				cf7fm_log( 'Files ricevuti:' );
				foreach ( $uploaded_files as $field_name => $file_path ) {
					cf7fm_log( "Campo: {$field_name} => File: " . ( is_string( $file_path ) ? $file_path : 'Array' ) );
				}
			} else {
				cf7fm_log( 'Nessun file da elaborare' );
				return;
			}

			// Crea cartella per il form
			$form_folder = $this->get_form_folder( $contact_form->id() );
			if ( ! $form_folder ) {
				cf7fm_log( 'Impossibile ottenere la cartella del form' );
				return;
			}

			$saved_files = array();

			foreach ( $uploaded_files as $field_name => $file_path ) {
				// Gestisci sia array che stringhe
				$files_to_process = is_array( $file_path ) ? $file_path : array( $file_path );

				foreach ( $files_to_process as $single_file ) {
					cf7fm_log( "Elaborazione file da campo {$field_name}: {$single_file}" );

					if ( empty( $single_file ) ) {
						cf7fm_log( 'File path vuoto, skip' );
						continue;
					}

					if ( ! file_exists( $single_file ) ) {
						cf7fm_log( 'File non trovato: ' . $single_file );
						continue;
					}

					// Genera nome univoco
					$file_info = pathinfo( $single_file );
					$original_name = $file_info['basename'];
					$extension = strtolower( $file_info['extension'] );
					$new_name = uniqid() . '-' . sanitize_file_name( $original_name );
					$new_path = $form_folder . $new_name;

					cf7fm_log( "Tentativo copia da {$single_file} a {$new_path}" );

					if ( copy( $single_file, $new_path ) ) {
						chmod( $new_path, 0644 );

						$upload_dir = wp_upload_dir();
						$relative_path = str_replace( $this->base_upload_dir, '', $new_path );

						$saved_files[] = array(
							'form_id' => $contact_form->id(),
							'field_name' => $field_name,
							'file_name' => $new_name,
							'original_name' => $original_name,
							'file_path' => $relative_path,
							'file_url' => $upload_dir['baseurl'] . '/cf7-uploads/' . $relative_path,
							'file_type' => $extension,
							'file_size' => filesize( $new_path )
						);

						cf7fm_log( "File copiato con successo: {$new_path}" );
					} else {
						cf7fm_log( "Errore nella copia del file: {$single_file}" );
					}
				}
			}

			if ( ! empty( $saved_files ) ) {
				cf7fm_log( 'Preparazione salvataggio di ' . count( $saved_files ) . ' files nel database' );
				$this->save_files_to_db( $saved_files );
			} else {
				cf7fm_log( 'Nessun file da salvare nel database' );
			}

		} catch (Exception $e) {
			cf7fm_log( 'Errore durante il salvataggio: ' . $e->getMessage() );
			cf7fm_log( $e->getTraceAsString() );
		}

		cf7fm_log( '=== FINE SALVATAGGIO FILE ===' );
	}

	/**
	 * Salva i metadati dei file nel database
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
				'file_url' => $file['file_url'],
				'file_type' => $file['file_type'],
				'file_size' => $file['file_size'],
				'uploaded_at' => current_time( 'mysql' )
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
	 * Ottiene la lista dei file con filtri e paginazione
	 */
	public function get_files( $page = 1, $filters = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		cf7fm_log( '=== INIZIO GET_FILES ===' );
		cf7fm_log( 'Query tabella: ' . $table_name );

		// Verifica esistenza tabella
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) == $table_name;
		if ( ! $table_exists ) {
			cf7fm_log( 'Tabella non trovata, la creo' );
			self::create_database_table();
		}

		// Query base
		$sql = "SELECT * FROM $table_name WHERE 1=1";
		$query_args = array();

		// Applica filtri
		if ( ! empty( $filters['form_id'] ) ) {
			$sql .= " AND form_id = %d";
			$query_args[] = $filters['form_id'];
		}

		if ( ! empty( $filters['type'] ) ) {
			$sql .= " AND file_type = %s";
			$query_args[] = $filters['type'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$sql .= " AND (file_name LIKE %s OR original_name LIKE %s)";
			$search_term = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$query_args[] = $search_term;
			$query_args[] = $search_term;
		}

		// Ordinamento
		$sql .= " ORDER BY uploaded_at DESC";

		// Debug query
		$final_sql = empty( $query_args ) ? $sql : $wpdb->prepare( $sql, $query_args );
		cf7fm_log( 'Query SQL: ' . $final_sql );

		// Esegui query
		$files = $wpdb->get_results( $final_sql, ARRAY_A );
		cf7fm_log( 'Files trovati: ' . ( $files ? count( $files ) : 0 ) );

		// Processa risultati
		$processed_files = array();
		if ( $files ) {
			foreach ( $files as $file ) {
				$file_path = $this->base_upload_dir . $file['file_path'];

				if ( file_exists( $file_path ) ) {
					$file['exists'] = true;
					$file['size_formatted'] = size_format( $file['file_size'] );
					$file['upload_date_formatted'] = date_i18n(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						strtotime( $file['uploaded_at'] )
					);

					$form = wpcf7_contact_form( $file['form_id'] );
					$file['form_title'] = $form ? $form->title() : sprintf( __( 'Form #%d', 'cf7-file-manager' ), $file['form_id'] );

					// Genera URL sicure per accesso ai file
					$file['file_url'] = $this->get_file_url( $file['id'] );
					$file['download_url'] = $this->get_file_url( $file['id'], true );

					$processed_files[] = $file;
				}
			}

		}

		cf7fm_log( 'Files processati: ' . count( $processed_files ) );
		cf7fm_log( '=== FINE GET_FILES ===' );

		return array(
			'files' => $processed_files,
			'total' => count( $processed_files ),
			'pages' => ceil( count( $processed_files ) / $this->per_page ),
			'current_page' => $page,
			'items_per_page' => $this->per_page
		);
	}

	/**
	 * Verifica che la tabella del database esista
	 */
	private function ensure_table_exists() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			cf7fm_log( 'Tabella mancante, creazione...' );
			self::create_database_table();
			return false;
		}
		return true;
	}

	/**
	 * Aggiunge le regole di rewrite
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'cf7fm-file/([0-9]+)/?$',
			'index.php?cf7fm_file=$matches[1]',
			'top'
		);

		add_filter( 'query_vars', function ($vars) {
			$vars[] = 'cf7fm_file';
			return $vars;
		} );

		add_action( 'parse_request', array( $this, 'handle_file_request' ) );
	}


	/**
	 * Gestisce l'accesso ai file
	 */
	public function handle_file_request() {
		// Ottieni l'ID del file dall'URL
		global $wp_query;
		if ( ! isset( $wp_query->query_vars['cf7fm_file'] ) ) {
			return;
		}

		$file_id = intval( $wp_query->query_vars['cf7fm_file'] );
		cf7fm_log( 'Richiesta accesso file ID: ' . $file_id );

		// Verifica permessi
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Accesso non autorizzato', 'cf7-file-manager' ) );
		}

		// Recupera info file dal database
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		$file = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$file_id
		) );

		if ( ! $file ) {
			cf7fm_log( 'File non trovato nel database' );
			wp_die( __( 'File non trovato', 'cf7-file-manager' ) );
		}

		// Costruisci il percorso completo del file
		$file_path = $this->base_upload_dir . $file->file_path;
		cf7fm_log( 'Percorso file: ' . $file_path );

		// Verifica esistenza file
		if ( ! file_exists( $file_path ) ) {
			cf7fm_log( 'File non trovato nel filesystem' );
			wp_die( __( 'File non trovato', 'cf7-file-manager' ) );
		}

		// Imposta gli header appropriati
		$mime_type = $this->get_mime_type( $file_path );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . filesize( $file_path ) );

		// Gestisci download vs visualizzazione
		$is_download = isset( $_GET['download'] );
		if ( $is_download ) {
			header( 'Content-Disposition: attachment; filename="' . $file->original_name . '"' );
		} else {
			header( 'Content-Disposition: inline; filename="' . $file->original_name . '"' );
		}

		// Invia il file
		readfile( $file_path );
		exit;
	}

	/**
	 * Gestisce la richiesta AJAX di eliminazione file
	 */
	/**
	 * Gestisce la richiesta AJAX di eliminazione file
	 */
	public function ajax_delete_file() {
		try {
			// Verifica nonce
			check_ajax_referer( 'cf7fm_nonce', 'nonce' );

			// Verifica permessi
			if ( ! current_user_can( 'edit_others_posts' ) ) {
				throw new Exception( __( 'Permessi insufficienti', 'cf7-file-manager' ) );
			}

			// Ottieni i dati del file
			$filename = isset( $_POST['filename'] ) ? sanitize_file_name( $_POST['filename'] ) : '';
			$file_id = isset( $_POST['file_id'] ) ? intval( $_POST['file_id'] ) : 0;

			if ( empty( $filename ) || empty( $file_id ) ) {
				throw new Exception( __( 'Dati file mancanti', 'cf7-file-manager' ) );
			}

			cf7fm_log( "Richiesta eliminazione file ID: $file_id, Nome: $filename" );

			// Ottieni i dati dal database
			global $wpdb;
			$table_name = $wpdb->prefix . 'cf7fm_submissions';

			$file_data = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d AND file_name = %s",
				$file_id,
				$filename
			) );

			if ( ! $file_data ) {
				throw new Exception( __( 'File non trovato nel database', 'cf7-file-manager' ) );
			}

			// Costruisci il percorso completo del file
			$file_path = $this->base_upload_dir . $file_data->file_path;
			cf7fm_log( "Tentativo di eliminazione file: $file_path" );

			// Elimina il file fisico
			if ( file_exists( $file_path ) ) {
				if ( ! unlink( $file_path ) ) {
					throw new Exception( __( 'Impossibile eliminare il file fisico', 'cf7-file-manager' ) );
				}
				cf7fm_log( "File fisico eliminato: $file_path" );
			} else {
				cf7fm_log( "File fisico non trovato: $file_path" );
			}

			// Elimina il record dal database
			$result = $wpdb->delete(
				$table_name,
				array( 'id' => $file_id ),
				array( '%d' )
			);

			if ( $result === false ) {
				throw new Exception( __( 'Errore durante l\'eliminazione dal database', 'cf7-file-manager' ) );
			}

			cf7fm_log( "Record database eliminato per file ID: $file_id" );
			wp_send_json_success( __( 'File eliminato con successo', 'cf7-file-manager' ) );

		} catch (Exception $e) {
			cf7fm_log( "Errore eliminazione file: " . $e->getMessage() );
			wp_send_json_error( $e->getMessage() );
		}
	}


	/**
	 * Ottiene il MIME type del file
	 */
	private function get_mime_type( $file_path ) {
		// Usa la funzione mime_content_type se disponibile
		if ( function_exists( 'mime_content_type' ) ) {
			return mime_content_type( $file_path );
		}

		// Fallback su finfo
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type = finfo_file( $finfo, $file_path );
			finfo_close( $finfo );
			return $mime_type;
		}

		// Fallback su estensione
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Lista estesa di MIME types
		$mime_types = array(
			// Documenti
			'pdf' => 'application/pdf',
			'doc' => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls' => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt' => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'txt' => 'text/plain',
			'rtf' => 'application/rtf',
			'odt' => 'application/vnd.oasis.opendocument.text',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet',

			// Immagini
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png' => 'image/png',
			'gif' => 'image/gif',
			'bmp' => 'image/bmp',
			'webp' => 'image/webp',
			'svg' => 'image/svg+xml',
			'ai' => 'application/postscript',
			'psd' => 'image/vnd.adobe.photoshop',
			'eps' => 'application/postscript',

			// Audio/Video
			'mp3' => 'audio/mpeg',
			'wav' => 'audio/wav',
			'mp4' => 'video/mp4',
			'mov' => 'video/quicktime',
			'avi' => 'video/x-msvideo',
			'wmv' => 'video/x-ms-wmv',

			// Archivi
			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
			'7z' => 'application/x-7z-compressed',

			// Altri
			'xml' => 'application/xml',
			'json' => 'application/json'
		);

		return isset( $mime_types[ $ext ] ) ? $mime_types[ $ext ] : 'application/octet-stream';
	}

	/**
	 * Genera URL sicure per i file
	 */
	public function get_file_url( $file_id, $download = false ) {
		$base_url = home_url( 'cf7fm-file/' . $file_id );
		return $download ? add_query_arg( 'download', '1', $base_url ) : $base_url;
	}

	/**
	 * Restituisce i tipi di file supportati
	 * @return array
	 */
	public function get_allowed_types() {
		// Restituisci una lista completa di tipi di file comuni
		return array(
			'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv',
			'txt', 'rtf', 'zip', 'rar', '7z',
			'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp',
			'mp3', 'wav', 'mp4', 'mov', 'avi', 'wmv',
			'ppt', 'pptx', 'odt', 'ods', 'odp',
			'xml', 'json', 'svg', 'ai', 'psd', 'eps'
		);
	}

	/**
	 * Crea la tabella del database
	 */
	public static function create_database_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_id bigint(20) NOT NULL,
            field_name varchar(100) NOT NULL,
            file_name varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL,
            file_path text NOT NULL,
            file_url text NOT NULL,
            file_type varchar(50) NOT NULL,
            file_size bigint(20) NOT NULL,
            uploaded_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY file_type (file_type),
            KEY uploaded_at (uploaded_at)
        ) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		// Verifica la creazione della tabella
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) == $table_name;
		cf7fm_log( 'Tabella ' . ( $table_exists ? 'creata' : 'NON creata' ) );

		// Verifica la struttura della tabella
		if ( $table_exists ) {
			$columns = $wpdb->get_results( "DESCRIBE $table_name" );
			$has_original_name = false;
			foreach ( $columns as $column ) {
				if ( $column->Field === 'original_name' ) {
					$has_original_name = true;
					break;
				}
			}

			// Se manca la colonna original_name, aggiungila
			if ( ! $has_original_name ) {
				cf7fm_log( 'Aggiunta colonna original_name' );
				$wpdb->query( "ALTER TABLE $table_name ADD COLUMN original_name varchar(255) NOT NULL AFTER file_name" );
			}
		}
	}

	/**
	 * Aggiorna la struttura del database se necessario
	 */
	public static function maybe_upgrade() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		// Verifica se la tabella esiste
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) == $table_name;

		if ( $table_exists ) {
			// Verifica se la colonna original_name esiste
			$column_exists = $wpdb->get_var( "SHOW COLUMNS FROM $table_name LIKE 'original_name'" );

			if ( ! $column_exists ) {
				cf7fm_log( 'Aggiornamento struttura tabella - aggiunta colonna original_name' );

				// Aggiungi la colonna
				$wpdb->query( "ALTER TABLE $table_name ADD COLUMN original_name varchar(255) NOT NULL AFTER file_name" );

				// Aggiorna i record esistenti copiando file_name in original_name
				$wpdb->query( "UPDATE $table_name SET original_name = file_name WHERE original_name = ''" );

				cf7fm_log( 'Aggiornamento completato' );
			}
		} else {
			// Se la tabella non esiste, creala
			self::create_database_table();
		}
	}

	/**
	 * Debug del sistema
	 */
	private function debug_system() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf7fm_submissions';

		// Debug Database
		cf7fm_log( '=== DEBUG DATABASE ===' );

		// Verifica tabella
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;
		cf7fm_log( 'Tabella esiste: ' . ( $table_exists ? 'Sì' : 'No' ) );

		if ( $table_exists ) {
			// Conta records
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
			cf7fm_log( 'Totale records: ' . $count );

			// Ultimi 5 file
			$recent = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY uploaded_at DESC LIMIT 5", ARRAY_A );
			if ( $recent ) {
				cf7fm_log( 'Ultimi 5 file:' );
				foreach ( $recent as $file ) {
					cf7fm_log( "ID: {$file['id']}, Nome: {$file['file_name']}, Data: {$file['uploaded_at']}" );
				}
			}
		}

		// Debug Directory
		cf7fm_log( '=== DEBUG DIRECTORY ===' );
		cf7fm_log( 'Base dir: ' . $this->base_upload_dir );
		cf7fm_log( 'Directory esiste: ' . ( file_exists( $this->base_upload_dir ) ? 'Sì' : 'No' ) );

		if ( file_exists( $this->base_upload_dir ) ) {
			cf7fm_log( 'Permessi: ' . decoct( fileperms( $this->base_upload_dir ) & 0777 ) );
			cf7fm_log( 'Scrivibile: ' . ( is_writable( $this->base_upload_dir ) ? 'Sì' : 'No' ) );

			// Lista file nella directory
			$files = glob( $this->base_upload_dir . '/*/*/*' );
			if ( $files ) {
				cf7fm_log( 'File trovati nella directory:' );
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						cf7fm_log( $file );
					}
				}
			}
		}
	}
}