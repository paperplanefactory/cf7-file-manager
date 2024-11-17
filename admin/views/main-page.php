<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ottieni tutti i form CF7
$forms = WPCF7_ContactForm::find();

// Ottieni i parametri di filtro
$current_form = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : 0;
$search_term = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
$page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

// Prepara i filtri
$filters = array(
	'form_id' => $current_form,
	'search' => $search_term
);

// Ottieni i file
$result = $this->uploads_manager->get_files( $page, $filters );
$files = $result['files'];
?>

<div class="wrap">
	<h1><?php _e( 'CF7 File Manager', 'cf7-file-manager' ); ?></h1>

	<!-- Form di ricerca e filtri -->
	<div class="cf7fm-filters-container">
		<form method="get" class="cf7fm-filters">
			<input type="hidden" name="page" value="cf7-file-manager">

			<select name="form_id" class="cf7fm-select">
				<option value=""><?php _e( 'Tutti i form', 'cf7-file-manager' ); ?></option>
				<?php foreach ( $forms as $form ) : ?>
					<option value="<?php echo esc_attr( $form->id() ); ?>" <?php selected( $current_form, $form->id() ); ?>>
						<?php echo esc_html( $form->title() ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="search" name="search" value="<?php echo esc_attr( $search_term ); ?>"
				placeholder="<?php esc_attr_e( 'Cerca file...', 'cf7-file-manager' ); ?>" class="cf7fm-search">

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filtra', 'cf7-file-manager' ); ?>">

			<?php if ( $current_form || $search_term ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cf7-file-manager' ) ); ?>" class="button">
					<?php _e( 'Resetta filtri', 'cf7-file-manager' ); ?>
				</a>
			<?php endif; ?>
		</form>
	</div>

	<div class="clear"></div>

	<!-- Resto del codice della tabella... -->

	<!-- Tabella dei file -->
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php _e( 'Nome File', 'cf7-file-manager' ); ?></th>
				<th><?php _e( 'Form', 'cf7-file-manager' ); ?></th>
				<th><?php _e( 'Tipo', 'cf7-file-manager' ); ?></th>
				<th><?php _e( 'Dimensione', 'cf7-file-manager' ); ?></th>
				<th><?php _e( 'Data', 'cf7-file-manager' ); ?></th>
				<th><?php _e( 'Azioni', 'cf7-file-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $files ) ) : ?>
				<tr>
					<td colspan="6">
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
						<td><?php echo esc_html( $file['original_name'] ); ?></td>
						<td><?php
						$form = wpcf7_contact_form( $file['form_id'] );
						echo $form ? esc_html( $form->title() ) : esc_html( sprintf( __( 'Form #%d', 'cf7-file-manager' ), $file['form_id'] ) );
						?></td>
						<td><?php echo esc_html( strtoupper( $file['file_type'] ) ); ?></td>
						<td><?php echo esc_html( $file['size_formatted'] ); ?></td>
						<td><?php echo esc_html( $file['upload_date_formatted'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( $this->uploads_manager->get_file_url( $file['id'] ) ); ?>"
								target="_blank" class="button button-small">
								<?php _e( 'Visualizza', 'cf7-file-manager' ); ?>
							</a>
							<a href="<?php echo esc_url( $this->uploads_manager->get_file_url( $file['id'], true ) ); ?>"
								class="button button-small">
								<?php _e( 'Scarica', 'cf7-file-manager' ); ?>
							</a>
							<button class="button button-small delete-file"
								data-file-id="<?php echo esc_attr( $file['id'] ); ?>"
								data-filename="<?php echo esc_attr( $file['file_name'] ); ?>">
								<?php _e( 'Elimina', 'cf7-file-manager' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $result['pages'] > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
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
		</div>
	<?php endif; ?>
</div>