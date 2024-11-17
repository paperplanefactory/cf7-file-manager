<?php
/**
 * Plugin Name: CF7 File Manager
 * Plugin URI: 
 * Description: Salva e gestisce i file caricati tramite Contact Form 7
 * Version: 1.0.0
 * Author: PaperPlane
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-file-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Definizioni costanti del plugin
define( 'CF7FM_VERSION', '1.0.0' );
define( 'CF7FM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF7FM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CF7FM_PLUGIN_FILE', __FILE__ );

// Funzione di logging se non esiste
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

class CF7_File_Manager {
	private static $instance = null;
	private $uploads_manager;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Inizializza il gestore uploads
		require_once( CF7FM_PLUGIN_DIR . 'includes/class-cf7fm-uploads-manager.php' );
		$this->uploads_manager = new CF7FM_Uploads_Manager();

		// Setup hooks
		$this->setup_hooks();
	}

	private function setup_hooks() {
		// Attivazione/Disattivazione
		register_activation_hook( CF7FM_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( CF7FM_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Inizializzazione
		add_action( 'init', array( $this, 'init' ) );

		// Admin menu
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// File handling
		add_action( 'parse_request', array( $this, 'handle_file_request' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );

		// Hook per il rewrite
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );

		// Hook per l'attivazione/disattivazione
		register_activation_hook( CF7FM_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( CF7FM_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Hook per quando vengono salvati i permalink
		add_action( 'permalink_structure_changed', array( $this, 'flush_rewrite_rules' ) );
	}

	public function init() {
		$this->add_rewrite_rules();
		cf7fm_log( 'Plugin inizializzato' );
	}

	public function add_rewrite_rules() {
		add_rewrite_rule(
			'cf7fm-file/([0-9]+)/?$',
			'index.php?cf7fm_file=$matches[1]',
			'top'
		);

		// Aggiunge la query var
		add_filter( 'query_vars', function ($vars) {
			$vars[] = 'cf7fm_file';
			return $vars;
		} );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'cf7fm_file';
		return $vars;
	}

	public function handle_file_request( $wp ) {
		if ( isset( $wp->query_vars['cf7fm_file'] ) ) {
			$file_id = intval( $wp->query_vars['cf7fm_file'] );
			cf7fm_log( 'Richiesta file: ' . $file_id );

			if ( ! current_user_can( 'edit_others_posts' ) ) {
				wp_die( 'Accesso non autorizzato' );
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'cf7fm_submissions';

			$file = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d",
				$file_id
			) );

			if ( ! $file ) {
				wp_die( 'File non trovato' );
			}

			$upload_dir = wp_upload_dir();
			$file_path = $upload_dir['basedir'] . '/cf7-uploads/' . $file->file_path;

			cf7fm_log( 'Percorso file: ' . $file_path );

			if ( ! file_exists( $file_path ) ) {
				wp_die( 'File non trovato nel filesystem' );
			}

			$mime_type = mime_content_type( $file_path );
			$is_download = isset( $_GET['download'] );

			cf7fm_log( 'Tipo MIME: ' . $mime_type );
			cf7fm_log( 'Download: ' . ( $is_download ? 'Sì' : 'No' ) );

			// Headers
			nocache_headers();
			header( 'Content-Type: ' . $mime_type );
			header( 'Content-Length: ' . filesize( $file_path ) );

			if ( $is_download ) {
				header( 'Content-Disposition: attachment; filename="' . $file->original_name . '"' );
			} else {
				header( 'Content-Disposition: inline; filename="' . $file->original_name . '"' );
			}

			readfile( $file_path );
			exit;
		}
	}

	public function activate() {
		// Verifica che Contact Form 7 sia attivo
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			deactivate_plugins( plugin_basename( CF7FM_PLUGIN_FILE ) );
			wp_die( 'Questo plugin richiede Contact Form 7 per funzionare.' );
		}



		// Crea/aggiorna tabella database
		CF7FM_Uploads_Manager::create_database_table();

		// Aggiunge le regole di rewrite
		$this->add_rewrite_rules();

		// Salva versione
		update_option( 'cf7fm_version', CF7FM_VERSION );

		// Fa il flush delle regole solo se necessario
		$this->maybe_flush_rules();
	}

	public function deactivate() {
		// Rimuove la versione delle regole
		delete_option( 'cf7fm_rewrite_version' );

		// Fa il flush delle regole
		flush_rewrite_rules();
	}

	public function add_admin_menu() {
		add_menu_page(
			__( 'CF7 File Manager', 'cf7-file-manager' ),
			__( 'CF7 Files', 'cf7-file-manager' ),
			'edit_others_posts',
			'cf7-file-manager',
			array( $this, 'render_admin_page' ),
			'dashicons-upload',
			30
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_cf7-file-manager' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cf7fm-admin-style',
			CF7FM_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			CF7FM_VERSION
		);

		wp_enqueue_script(
			'cf7fm-admin-script',
			CF7FM_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			CF7FM_VERSION,
			true
		);

		wp_localize_script( 'cf7fm-admin-script', 'cf7fm_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'cf7fm_nonce' ),
			'confirm_delete' => __( 'Sei sicuro di voler eliminare questo file?', 'cf7-file-manager' ),
			'delete_success' => __( 'File eliminato con successo', 'cf7-file-manager' ),
			'delete_error' => __( 'Errore durante l\'eliminazione del file', 'cf7-file-manager' )
		) );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Non hai i permessi sufficienti per accedere a questa pagina.', 'cf7-file-manager' ) );
		}

		require_once CF7FM_PLUGIN_DIR . 'admin/views/main-page.php';
	}

	/**
	 * Fa il flush delle regole solo se necessario
	 */
	private function maybe_flush_rules() {
		// Ottiene la versione salvata delle regole
		$saved_version = get_option( 'cf7fm_rewrite_version', '' );
		$current_version = '1.0'; // Incrementa questa versione quando modifichi le regole

		// Se la versione è diversa, fa il flush
		if ( $saved_version !== $current_version ) {
			flush_rewrite_rules();
			update_option( 'cf7fm_rewrite_version', $current_version );
			cf7fm_log( 'Rewrite rules aggiornate alla versione ' . $current_version );
		}
	}

	/**
	 * Gestisce il flush delle regole quando cambiano i permalink
	 */
	public function flush_rewrite_rules() {
		$this->add_rewrite_rules();
		flush_rewrite_rules();
		cf7fm_log( 'Rewrite rules aggiornate dopo cambio permalink' );
	}
}

// Inizializza il plugin
function cf7_file_manager() {
	return CF7_File_Manager::get_instance();
}

cf7_file_manager();