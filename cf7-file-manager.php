<?php
/**
 * Plugin Name: CF7 File Manager
 * Plugin URI: 
 * Description: Salva e gestisce i file caricati tramite Contact Form 7
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: PaperPlane Factory
 * Author URI: https://www.paperplanefactory.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-file-manager
 * Domain Path: /languages
 */

// Evita accesso diretto
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Definizioni costanti
define( 'CF7FM_VERSION', '1.0.0' );
define( 'CF7FM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF7FM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CF7FM_PLUGIN_FILE', __FILE__ );

// Funzione di logging personalizzata
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

/**
 * Classe principale del plugin
 */
class CF7_File_Manager {

	/** @var CF7_File_Manager Singleton instance */
	private static $instance = null;

	/** @var CF7FM_Uploads_Manager */
	private $uploads_manager;

	/**
	 * Ottieni l'istanza singleton
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Costruttore
	 */
	private function __construct() {
		$this->check_dependencies();
		$this->load_textdomain();
		$this->load_dependencies();
		$this->init_components();
		$this->setup_hooks();
	}

	/**
	 * Verifica le dipendenze del plugin
	 */
	private function check_dependencies() {
		add_action( 'admin_init', function () {
			if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
				deactivate_plugins( plugin_basename( CF7FM_PLUGIN_FILE ) );
				add_action( 'admin_notices', function () {
					$message = sprintf(
						__( 'CF7 File Manager richiede Contact Form 7. %sInstalla Contact Form 7%s', 'cf7-file-manager' ),
						'<a href="' . admin_url( 'plugin-install.php?tab=search&s=contact+form+7' ) . '">',
						'</a>'
					);
					printf( '<div class="error"><p>%s</p></div>', $message );
				} );
			}
		} );
	}

	/**
	 * Carica il textdomain per le traduzioni
	 */
	private function load_textdomain() {
		load_plugin_textdomain(
			'cf7-file-manager',
			false,
			dirname( plugin_basename( CF7FM_PLUGIN_FILE ) ) . '/languages/'
		);
	}

	/**
	 * Carica le dipendenze
	 */
	private function load_dependencies() {
		// Carica il gestore uploads
		require_once CF7FM_PLUGIN_DIR . 'includes/class-cf7fm-uploads-manager.php';
		// Carica l'integrazione con Flamingo
		require_once CF7FM_PLUGIN_DIR . 'includes/class-cf7fm-flamingo.php';
	}

	/**
	 * Inizializza i componenti
	 */
	private function init_components() {
		// Prima inizializziamo uploads_manager
		$this->uploads_manager = new CF7FM_Uploads_Manager();

		// Poi inizializziamo Flamingo dopo che tutti i plugin sono caricati
		add_action( 'plugins_loaded', function () {
			cf7fm_log( 'Checking Flamingo integration...' );

			if ( CF7FM_Flamingo::is_flamingo_active() ) {
				cf7fm_log( 'Flamingo is active, initializing integration' );
				$this->flamingo = new CF7FM_Flamingo( $this->uploads_manager );
			} else {
				cf7fm_log( 'Flamingo is not active' );
			}
		}, 20 );
	}

	/**
	 * Setup degli hooks WordPress
	 */
	private function setup_hooks() {
		// Attivazione/Disattivazione
		register_activation_hook( CF7FM_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( CF7FM_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Admin
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Plugin
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );

		// Aggiungi regole rewrite
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );

		// Gestisci richieste file
		add_action( 'parse_request', array( $this, 'handle_file_request' ) );

		// Aggiungi query vars
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
	}

	/**
	 * Callback attivazione plugin
	 */
	public function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Crea/aggiorna tabella database
		CF7FM_Uploads_Manager::create_database_table();

		// Salva versione
		update_option( 'cf7fm_version', CF7FM_VERSION );

		// Marca come necessario il flush delle regole
		update_option( 'cf7fm_flush_needed', 'yes' );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Callback disattivazione plugin
	 */
	public function deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Pulizia
		delete_option( 'cf7fm_version' );
		flush_rewrite_rules();
	}

	/**
	 * Callback init WordPress
	 */
	public function init() {
		// Aggiungi qui eventuali inizializzazioni
	}

	/**
	 * Callback plugins_loaded
	 */
	public function plugins_loaded() {
		// Controlla aggiornamenti
		$this->check_update();
	}

	/**
	 * Controlla e gestisce gli aggiornamenti
	 */
	private function check_update() {
		$current_version = get_option( 'cf7fm_version', '0' );

		if ( version_compare( $current_version, CF7FM_VERSION, '<' ) ) {
			// Esegui aggiornamenti
			CF7FM_Uploads_Manager::maybe_upgrade();

			// Aggiorna versione
			update_option( 'cf7fm_version', CF7FM_VERSION );
		}
	}

	/**
	 * Aggiunge la pagina al menu admin
	 */
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

	/**
	 * Registra e carica gli assets admin
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_cf7-file-manager' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cf7fm-admin-style',
			CF7FM_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			CF7FM_VERSION
		);

		wp_enqueue_script(
			'cf7fm-admin-script',
			CF7FM_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			CF7FM_VERSION,
			true
		);

		wp_localize_script( 'cf7fm-admin-script', 'cf7fm_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'cf7fm_nonce' ),
			'confirm_delete' => __( 'Sei sicuro di voler eliminare questo file?', 'cf7-file-manager' ),
			'confirm_delete_multiple' => __( 'Sei sicuro di voler eliminare i file selezionati?', 'cf7-file-manager' ),
			'delete_success' => __( 'File eliminati con successo', 'cf7-file-manager' ),
			'delete_error' => __( 'Errore durante l\'eliminazione dei file', 'cf7-file-manager' ),
			'ajax_error' => __( 'Errore di comunicazione con il server', 'cf7-file-manager' ),
			'no_files' => __( 'Nessun file trovato.', 'cf7-file-manager' )
		) );
	}

	/**
	 * Renderizza la pagina admin
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Non hai i permessi sufficienti per accedere a questa pagina.', 'cf7-file-manager' ) );
		}

		require_once CF7FM_PLUGIN_DIR . 'admin/views/main-page.php';
	}

	/**
	 * Aggiunge le regole rewrite
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'cf7fm-file/([0-9]+)/?$',
			'index.php?cf7fm_file=$matches[1]',
			'top'
		);

		// Fai il flush solo se necessario
		if ( get_option( 'cf7fm_flush_needed', 'yes' ) === 'yes' ) {
			flush_rewrite_rules();
			update_option( 'cf7fm_flush_needed', 'no' );
		}
	}
	/**
	 * Aggiunge le query vars necessarie
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'cf7fm_file';
		return $vars;
	}

	/**
	 * Gestisce le richieste dei file
	 */
	public function handle_file_request( $wp ) {
		if ( isset( $wp->query_vars['cf7fm_file'] ) ) {
			$file_id = intval( $wp->query_vars['cf7fm_file'] );

			// Verifica permessi
			if ( ! current_user_can( 'edit_others_posts' ) ) {
				wp_die( __( 'Accesso non autorizzato', 'cf7-file-manager' ) );
			}

			// Ottieni file
			$file = $this->uploads_manager->get_file( $file_id );
			if ( ! $file ) {
				wp_die( __( 'File non trovato', 'cf7-file-manager' ) );
			}

			$file_path = $this->uploads_manager->get_file_path( $file );

			if ( ! file_exists( $file_path ) ) {
				wp_die( __( 'File non trovato nel filesystem', 'cf7-file-manager' ) );
			}

			// Impostazione headers
			$is_download = isset( $_GET['download'] );
			$mime_type = mime_content_type( $file_path );

			nocache_headers();
			header( 'Content-Type: ' . $mime_type );
			header( 'Content-Length: ' . filesize( $file_path ) );

			if ( $is_download ) {
				header( 'Content-Disposition: attachment; filename="' . $file->original_name . '"' );
			} else {
				header( 'Content-Disposition: inline; filename="' . $file->original_name . '"' );
			}

			// Output file
			readfile( $file_path );
			exit;
		}
	}

	/**
	 * Inizializza l'integrazione con Flamingo
	 */
	public function init_flamingo() {
		cf7fm_log( 'Init Flamingo - Check class exists: ' . ( class_exists( 'Flamingo_Inbound_Message' ) ? 'YES' : 'NO' ) );

		if ( class_exists( 'Flamingo_Inbound_Message' ) ) {
			require_once CF7FM_PLUGIN_DIR . 'includes/class-cf7fm-flamingo.php';
			$this->flamingo = new CF7FM_Flamingo( $this->uploads_manager );
			cf7fm_log( 'Flamingo integration initialized' );
		}
	}

}

/**
 * Inizializza il plugin
 */
function cf7_file_manager() {
	return CF7_File_Manager::get_instance();
}

// Start the plugin
cf7_file_manager();