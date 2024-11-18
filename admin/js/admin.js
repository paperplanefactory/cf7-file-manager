/**
* CF7 File Manager Admin JavaScript
* Version: 1.0.0
*/
(function ($) {
    'use strict';

    const CF7FileManager = {
        /**
         * Inizializzazione
         */
        init: function () {
            this.bindEvents();
            this.initFilters();
        },

        /**
         * Binding degli eventi
         */
        bindEvents: function () {
            // Delegazione eventi per delete
            $(document).on('click', '.delete-file', this.handleDelete.bind(this));

            // Gestione filtri form
            $('.cf7fm-filters select').on('change', this.handleFilterChange.bind(this));

            // Gestione ricerca
            $('.cf7fm-search').on('input', this.debounce(this.handleSearch.bind(this), 500));
        },

        /**
         * Inizializza i filtri
         */
        initFilters: function () {
            // Salva i valori iniziali dei filtri
            this.initialFilters = {
                form: $('.cf7fm-select').val(),
                search: $('.cf7fm-search').val()
            };
        },

        /**
         * Gestisce l'eliminazione dei file
         * @param {Event} e Evento click
         */
        handleDelete: function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const filename = $button.data('filename');
            const fileId = $button.data('file-id');

            if (!this.confirmDelete(filename)) {
                return;
            }

            this.deleteFile($button, filename, fileId);
        },

        /**
         * Mostra conferma eliminazione
         * @param {string} filename Nome del file
         * @returns {boolean}
         */
        confirmDelete: function (filename) {
            return confirm(cf7fm_vars.confirm_delete);
        },

        /**
         * Elimina il file via AJAX
         * @param {jQuery} $button Bottone delete
         * @param {string} filename Nome del file
         * @param {number} fileId ID del file
         */
        deleteFile: function ($button, filename, fileId) {
            const $row = $button.closest('tr');

            // Disabilita il bottone e mostra loading
            $button.addClass('updating-message').prop('disabled', true);

            $.ajax({
                url: cf7fm_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_cf7_file',
                    filename: filename,
                    file_id: fileId,
                    nonce: cf7fm_vars.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.handleDeleteSuccess($row);
                    } else {
                        this.handleDeleteError($button, response.data);
                    }
                },
                error: () => {
                    this.handleDeleteError($button);
                }
            });
        },

        /**
         * Gestisce successo eliminazione
         * @param {jQuery} $row Riga della tabella
         */
        handleDeleteSuccess: function ($row) {
            // Rimuovi riga con animazione
            $row.fadeOut(400, () => {
                $row.remove();

                // Controlla se ci sono ancora file
                if ($('.wp-list-table tbody tr').length === 0) {
                    this.showEmptyState();
                }

                // Mostra notifica successo
                this.showNotice(cf7fm_vars.delete_success, 'success');
            });
        },

        /**
         * Gestisce errore eliminazione
         * @param {jQuery} $button Bottone delete
         * @param {string} message Messaggio errore
         */
        handleDeleteError: function ($button, message) {
            // Reset bottone
            $button.removeClass('updating-message').prop('disabled', false);

            // Mostra errore
            this.showNotice(message || cf7fm_vars.delete_error, 'error');
        },

        /**
         * Mostra stato tabella vuota
         */
        showEmptyState: function () {
            const $tbody = $('.wp-list-table tbody');
            const message = cf7fm_vars.no_files;

            $tbody.html(`
                <tr>
                    <td colspan="6" class="cf7fm-empty-state">
                        ${message}
                    </td>
                </tr>
            `);
        },

        /**
         * Gestisce cambio filtri
         * @param {Event} e Evento change
         */
        handleFilterChange: function (e) {
            $(e.target).closest('form').submit();
        },

        /**
         * Gestisce input ricerca
         * @param {Event} e Evento input
         */
        handleSearch: function (e) {
            const searchValue = $(e.target).val();

            // Submitti solo se il valore è cambiato significativamente
            if (searchValue.length === 0 || searchValue.length > 2) {
                $(e.target).closest('form').submit();
            }
        },

        /**
         * Mostra notifica
         * @param {string} message Messaggio
         * @param {string} type Tipo notifica (success/error)
         */
        showNotice: function (message, type = 'success') {
            const $notice = $(`
                <div class="cf7fm-notice cf7fm-notice--${type}">
                    ${message}
                </div>
            `).hide();

            // Rimuovi eventuali notifiche esistenti
            $('.cf7fm-notice').remove();

            // Aggiungi e mostra la nuova notifica
            $('.wrap').prepend($notice);
            $notice.fadeIn();

            // Rimuovi dopo 4 secondi
            setTimeout(() => {
                $notice.fadeOut(() => $notice.remove());
            }, 4000);
        },

        /**
         * Utility: Debounce function
         * @param {Function} func Funzione da debounce
         * @param {number} wait Tempo di attesa in ms
         * @returns {Function}
         */
        debounce: function (func, wait) {
            let timeout;

            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };

                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };

    // Inizializza quando il documento è pronto
    $(document).ready(() => {
        CF7FileManager.init();
    });

})(jQuery);

(function ($) {
    'use strict';

    // Gestione checkbox "seleziona tutti"
    $('#cb-select-all').on('change', function () {
        $('.file-checkbox').prop('checked', $(this).prop('checked'));
        updateDeleteButton();
    });

    // Gestione checkbox singoli
    $(document).on('change', '.file-checkbox', function () {
        updateDeleteButton();

        // Aggiorna il checkbox "seleziona tutti"
        var allChecked = $('.file-checkbox:checked').length === $('.file-checkbox').length;
        $('#cb-select-all').prop('checked', allChecked);
    });

    // Abilita/disabilita il bottone elimina
    function updateDeleteButton() {
        var checkedFiles = $('.file-checkbox:checked').length;
        $('#delete-selected').prop('disabled', checkedFiles === 0);
    }

    // Gestione eliminazione multipla
    $('#delete-selected').on('click', function () {
        if (!confirm(cf7fm_vars.confirm_delete_multiple)) {
            return;
        }

        var fileIds = [];
        $('.file-checkbox:checked').each(function () {
            fileIds.push($(this).val());
        });

        var $button = $(this);
        $button.addClass('updating-message').prop('disabled', true);

        $.ajax({
            url: cf7fm_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'delete_multiple_cf7_files',
                file_ids: fileIds,
                nonce: cf7fm_vars.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Rimuovi le righe dalla tabella
                    fileIds.forEach(function (id) {
                        $('input[value="' + id + '"]').closest('tr').fadeOut(400, function () {
                            $(this).remove();

                            // Se non ci sono più file, mostra il messaggio "nessun file"
                            if ($('.wp-list-table tbody tr').length === 0) {
                                $('.wp-list-table tbody').append(
                                    '<tr><td colspan="7">' + cf7fm_vars.no_files + '</td></tr>'
                                );
                            }
                        });
                    });

                    alert(response.data.message);
                } else {
                    alert(response.data.message || cf7fm_vars.delete_error);
                }
            },
            error: function () {
                alert(cf7fm_vars.ajax_error);
            },
            complete: function () {
                $button.removeClass('updating-message').prop('disabled', false);
                $('#cb-select-all').prop('checked', false);
                updateDeleteButton();
            }
        });
    });

})(jQuery);