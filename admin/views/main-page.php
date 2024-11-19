<?php
/**
 * Template principale dell'interfaccia amministrativa
 * 
 * @package CF7FileManager
 * @since 1.0.0
 */

// Previeni accesso diretto
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ottieni tutti i form CF7
$forms = WPCF7_ContactForm::find();

// Ottieni i parametri di filtro
$current_form = filter_input( INPUT_GET, 'form_id', FILTER_VALIDATE_INT ) ?: 0;
$search_term = filter_input( INPUT_GET, 'search', FILTER_SANITIZE_STRING ) ?: '';
$page = filter_input( INPUT_GET, 'paged', FILTER_VALIDATE_INT ) ?: 1;

// Prepara i filtri
$filters = array(
	'form_id' => $current_form,
	'search' => $search_term
);

// Ottieni i file
$result = $this->uploads_manager->get_files( $page, $filters );
$files = $result['files'];
?>

<div class="wrap cf7fm-wrap">
	<div class="cf7fm-header">
		<h1><?php _e( 'CF7 File Manager', 'cf7-file-manager' ); ?></h1>
	</div>

	<?php settings_errors(); ?>

	<!-- Filtri -->
	<div class="cf7fm-filters-container">
		<form method="get" class="cf7fm-filters">
			<?php wp_nonce_field( 'cf7fm_filter_files', 'cf7fm_filter_nonce' ); ?>
			<input type="hidden" name="page" value="cf7-file-manager">

			<!-- Select Form -->
			<select name="form_id" class="cf7fm-select">
				<option value=""><?php _e( 'Tutti i form', 'cf7-file-manager' ); ?></option>
				<?php foreach ( $forms as $form ) : ?>
					<option value="<?php echo esc_attr( $form->id() ); ?>" <?php selected( $current_form, $form->id() ); ?>>
						<?php echo esc_html( $form->title() ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<!-- Campo Ricerca -->
			<input type="search" name="search" class="cf7fm-search" value="<?php echo esc_attr( $search_term ); ?>"
				placeholder="<?php esc_attr_e( 'Cerca file...', 'cf7-file-manager' ); ?>">

			<!-- Bottoni -->
			<?php submit_button( __( 'Filtra', 'cf7-file-manager' ), 'secondary', 'submit', false ); ?>

			<?php if ( $current_form || $search_term ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cf7-file-manager' ) ); ?>" class="button">
					<?php _e( 'Resetta filtri', 'cf7-file-manager' ); ?>
				</a>
			<?php endif; ?>
		</form>
	</div>

	<!-- Tabella -->
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col" class="check-column">
					<input type="checkbox" id="cb-select-all">
				</th>
				<th scope="col" class="column-filename">
					<?php _e( 'Nome File', 'cf7-file-manager' ); ?>
				</th>
				<th scope="col" class="column-form">
					<?php _e( 'Form', 'cf7-file-manager' ); ?>
				</th>
				<th scope="col" class="column-type">
					<?php _e( 'Tipo', 'cf7-file-manager' ); ?>
				</th>
				<th scope="col" class="column-size">
					<?php _e( 'Dimensione', 'cf7-file-manager' ); ?>
				</th>
				<th scope="col" class="column-date">
					<?php _e( 'Data', 'cf7-file-manager' ); ?>
				</th>
				<th scope="col" class="column-actions">
					<?php _e( 'Azioni', 'cf7-file-manager' ); ?>
				</th>
			</tr>
		</thead>

		<tbody>
			<?php if ( empty( $files ) ) : ?>
				<tr>
					<td colspan="6" class="cf7fm-empty-state">
						<?php
						if ( $current_form || $search_term ) {
							_e( 'Nessun file trovato con i filtri selezionati.', 'cf7-file-manager' );
						} else {
							_e( 'Nessun file trovato.', 'cf7-file-manager' );
						}
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $files as $file ) : ?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" name="file_ids[]" value="<?php echo esc_attr( $file['id'] ); ?>"
								class="file-checkbox">
						</th>
						<td class="column-filename">
							<?php echo esc_html( $file['original_name'] ); ?>
						</td>
						<td class="column-form" data-title="<?php esc_attr_e( 'Form:', 'cf7-file-manager' ); ?>">
							<?php
							$form = wpcf7_contact_form( $file['form_id'] );
							echo $form ? esc_html( $form->title() ) :
								esc_html( sprintf( __( 'Form #%d', 'cf7-file-manager' ),
									$file['form_id'] ) );
							?>
						</td>
						<td class="column-type" data-title="<?php esc_attr_e( 'Tipo:', 'cf7-file-manager' ); ?>">
							<?php echo esc_html( strtoupper( $file['file_type'] ) ); ?>
						</td>
						<td class="column-size" data-title="<?php esc_attr_e( 'Dimensione:', 'cf7-file-manager' ); ?>">
							<?php echo esc_html( $file['size_formatted'] ); ?>
						</td>
						<td class="column-date" data-title="<?php esc_attr_e( 'Data:', 'cf7-file-manager' ); ?>">
							<span title="<?php echo esc_attr(
								wp_date(
									'Y-m-d H:i:s',  // Formato completo per il tooltip
									strtotime( $file['uploaded_at'] . ' UTC' )
								)
							); ?>">
								<?php echo esc_html( $file['uploaded_at'] ); ?>
							</span>
						</td>
						<td class="column-actions">
							<div class="cf7fm-file-actions">
								<a href="<?php echo esc_url( $this->uploads_manager->get_file_url( $file['id'] ) ); ?>"
									target="_blank" class="button button-small"
									title="<?php esc_attr_e( 'Visualizza', 'cf7-file-manager' ); ?>">
									<?php esc_attr_e( 'Visualizza', 'cf7-file-manager' ); ?>
								</a>

								<a href="<?php echo esc_url( $this->uploads_manager->get_file_url( $file['id'], true ) ); ?>"
									class="button button-small" title="<?php esc_attr_e( 'Scarica', 'cf7-file-manager' ); ?>">
									<?php esc_attr_e( 'Scarica', 'cf7-file-manager' ); ?>
								</a>

								<button type="button" class="button button-small delete-file"
									data-file-id="<?php echo esc_attr( $file['id'] ); ?>"
									data-filename="<?php echo esc_attr( $file['file_name'] ); ?>"
									title="<?php esc_attr_e( 'Elimina', 'cf7-file-manager' ); ?>">
									<?php esc_attr_e( 'Elimina', 'cf7-file-manager' ); ?>
								</button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>


	<!-- Aggiungi il bottone per eliminare i file selezionati -->
	<div class="tablenav bottom">
		<div class="alignleft actions bulkactions">
			<button type="button" id="delete-selected" class="button action" disabled>
				<?php _e( 'Elimina selezionati', 'cf7-file-manager' ); ?>
			</button>
		</div>
		<?php if ( $result['pages'] > 1 ) : ?>
			<div class="cf7fm-pagination">
				<?php
				echo paginate_links( array(
					'base' => add_query_arg( 'paged', '%#%' ),
					'format' => '',
					'prev_text' => __( '&laquo;' ),
					'next_text' => __( '&raquo;' ),
					'total' => $result['pages'],
					'current' => $page,
					'add_args' => array(
						'form_id' => $current_form,
						'search' => $search_term
					)
				) );
				?>
			</div>
		<?php endif; ?>
	</div>


</div>