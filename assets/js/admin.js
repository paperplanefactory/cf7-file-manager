jQuery(document).ready(function ($) {
    $('.delete-file').on('click', function (e) {
        e.preventDefault();

        var button = $(this);
        var filename = button.data('filename');
        var fileId = button.data('file-id'); // Aggiungiamo l'ID del file

        if (!confirm(cf7fm_vars.confirm_delete)) {
            return;
        }

        button.addClass('updating-message').prop('disabled', true);

        $.ajax({
            url: cf7fm_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'delete_cf7_file',
                filename: filename,
                file_id: fileId, // Includiamo l'ID del file
                nonce: cf7fm_vars.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Rimuovi la riga della tabella con effetto fade
                    button.closest('tr').fadeOut(400, function () {
                        $(this).remove();

                        // Se non ci sono più file, aggiungi riga "nessun file"
                        if ($('.wp-list-table tbody tr').length === 0) {
                            $('.wp-list-table tbody').append(
                                '<tr><td colspan="6">' + cf7fm_vars.no_files + '</td></tr>'
                            );
                        }
                    });
                } else {
                    alert(response.data || cf7fm_vars.delete_error);
                    button.removeClass('updating-message').prop('disabled', false);
                }
            },
            error: function () {
                alert(cf7fm_vars.ajax_error);
                button.removeClass('updating-message').prop('disabled', false);
            }
        });
    });
});