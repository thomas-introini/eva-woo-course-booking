/**
 * Eva Course Bookings - Admin JavaScript
 */

(function($) {
    'use strict';

    var EvaAdmin = {
        init: function() {
            this.initDatepickers();
            this.bindEvents();
            this.toggleSlotsVisibility();
        },

        initDatepickers: function() {
            $('.eva-datepicker').datepicker({
                dateFormat: 'dd/mm/yy',
                minDate: 0,
                firstDay: 1, // Monday
                dayNamesMin: ['Do', 'Lu', 'Ma', 'Me', 'Gi', 'Ve', 'Sa'],
                monthNames: ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
                    'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'],
                monthNamesShort: ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu',
                    'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic']
            });
        },

        bindEvents: function() {
            var self = this;

            // Toggle slots visibility when checkbox changes
            $('#_eva_course_enabled').on('change', this.toggleSlotsVisibility);

            // Add slot
            $(document).on('click', '#eva-add-slot-btn', function(e) {
                e.preventDefault();
                self.createSlot();
            });

            // Edit slot
            $(document).on('click', '.eva-edit-slot', function(e) {
                e.preventDefault();
                var slot = $(this).data('slot');
                self.openEditModal(slot);
            });

            // Save slot changes
            $(document).on('click', '#eva-save-slot-btn', function(e) {
                e.preventDefault();
                self.updateSlot();
            });

            // Toggle slot status
            $(document).on('click', '.eva-toggle-status', function(e) {
                e.preventDefault();
                var slotId = $(this).data('slot-id');
                var currentStatus = $(this).data('current-status');
                self.toggleSlotStatus(slotId, currentStatus);
            });

            // Delete slot
            $(document).on('click', '.eva-delete-slot', function(e) {
                e.preventDefault();
                var slotId = $(this).data('slot-id');
                self.deleteSlot(slotId);
            });

            // Close modal
            $(document).on('click', '.eva-modal-close, .eva-modal-cancel', function() {
                $('#eva-edit-slot-modal').hide();
            });

            // Close modal on outside click
            $(document).on('click', '.eva-modal', function(e) {
                if ($(e.target).hasClass('eva-modal')) {
                    $(this).hide();
                }
            });

            // Bulk enable
            $('#eva-bulk-enable-form').on('submit', function(e) {
                e.preventDefault();
                self.bulkEnable();
            });

            // Select all / Deselect all
            $('#eva-select-all, #eva-check-all').on('click', function() {
                $('input[name="product_ids[]"]').prop('checked', true);
            });

            $('#eva-deselect-all').on('click', function() {
                $('input[name="product_ids[]"]').prop('checked', false);
            });

            // Send reminder to single participant
            $(document).on('click', '.eva-send-reminder-single', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $row = $btn.closest('tr');
                var $table = $('#eva-slot-bookings-table');

                var data = {
                    slot_id: $table.data('slot-id'),
                    customer_email: $row.data('customer-email'),
                    customer_name: $row.data('customer-name'),
                    quantity: $row.data('quantity'),
                    course_name: $table.data('course-name'),
                    course_date: $table.data('course-date'),
                    course_time: $table.data('course-time'),
                    course_end_time: $table.data('course-end-time')
                };

                self.sendReminderSingle($btn, data);
            });

            // Send reminder to all participants
            $(document).on('click', '#eva-send-reminder-all', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var slotId = $btn.data('slot-id');
                self.sendReminderAll($btn, slotId);
            });
        },

        toggleSlotsVisibility: function() {
            var isEnabled = $('#_eva_course_enabled').is(':checked');
            if (isEnabled) {
                $('#eva-slots-container').show();
                $('#eva-slots-disabled-notice').hide();
            } else {
                $('#eva-slots-container').hide();
                $('#eva-slots-disabled-notice').show();
            }
        },

        createSlot: function() {
            var self = this;
            var productId = $('#eva-slots-container').data('product-id');
            var date = $('#eva-slot-date').val();
            var time = $('#eva-slot-time').val();
            var endTime = $('#eva-slot-end-time').val();
            var capacity = $('#eva-slot-capacity').val();

            if (!date || !time || !capacity) {
                alert(evaAdminData.i18n.requiredFields);
                return;
            }

            if (parseInt(capacity) <= 0) {
                alert(evaAdminData.i18n.invalidCapacity);
                return;
            }

            $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eva_admin_create_slot',
                    nonce: evaAdminData.nonce,
                    product_id: productId,
                    date: date,
                    time: time,
                    end_time: endTime,
                    capacity: capacity
                },
                beforeSend: function() {
                    $('#eva-add-slot-btn').prop('disabled', true).text('Creazione...');
                },
                success: function(response) {
                    if (response.success) {
                        // Remove "no slots" row if present
                        $('#eva-slots-table .eva-no-slots').remove();

                        // Add new row
                        $('#eva-slots-table tbody').append(response.data.html);

                        // Clear form
                        $('#eva-slot-date').val('');
                        $('#eva-slot-time').val('');
                        $('#eva-slot-end-time').val('');
                        $('#eva-slot-capacity').val('10');

                        self.showNotice('success', response.data.message);
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    self.showNotice('error', evaAdminData.i18n.errorOccurred);
                },
                complete: function() {
                    $('#eva-add-slot-btn').prop('disabled', false).text('Aggiungi slot');
                }
            });
        },

        openEditModal: function(slot) {
            // Parse datetime
            var startParts = slot.start_datetime.split(' ');
            var dateParts = startParts[0].split('-');
            var timeParts = startParts[1].split(':');

            var formattedDate = dateParts[2] + '/' + dateParts[1] + '/' + dateParts[0];
            var formattedTime = timeParts[0] + ':' + timeParts[1];

            var formattedEndTime = '';
            if (slot.end_datetime) {
                var endParts = slot.end_datetime.split(' ')[1].split(':');
                formattedEndTime = endParts[0] + ':' + endParts[1];
            }

            $('#eva-edit-slot-id').val(slot.id);
            $('#eva-edit-slot-date').val(formattedDate);
            $('#eva-edit-slot-time').val(formattedTime);
            $('#eva-edit-slot-end-time').val(formattedEndTime);
            $('#eva-edit-slot-capacity').val(slot.capacity);

            // Reinitialize datepicker for modal
            $('#eva-edit-slot-date').datepicker({
                dateFormat: 'dd/mm/yy',
                minDate: 0,
                firstDay: 1,
                dayNamesMin: ['Do', 'Lu', 'Ma', 'Me', 'Gi', 'Ve', 'Sa'],
                monthNames: ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
                    'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'],
                monthNamesShort: ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu',
                    'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic']
            });

            $('#eva-edit-slot-modal').show();
        },

        updateSlot: function() {
            var self = this;
            var slotId = $('#eva-edit-slot-id').val();
            var date = $('#eva-edit-slot-date').val();
            var time = $('#eva-edit-slot-time').val();
            var endTime = $('#eva-edit-slot-end-time').val();
            var capacity = $('#eva-edit-slot-capacity').val();

            if (!date || !time || !capacity) {
                alert(evaAdminData.i18n.requiredFields);
                return;
            }

            $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eva_admin_update_slot',
                    nonce: evaAdminData.nonce,
                    slot_id: slotId,
                    date: date,
                    time: time,
                    end_time: endTime,
                    capacity: capacity
                },
                beforeSend: function() {
                    $('#eva-save-slot-btn').prop('disabled', true).text('Salvataggio...');
                },
                success: function(response) {
                    if (response.success) {
                        // Replace row
                        $('tr[data-slot-id="' + slotId + '"]').replaceWith(response.data.html);
                        $('#eva-edit-slot-modal').hide();
                        self.showNotice('success', response.data.message);
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    self.showNotice('error', evaAdminData.i18n.errorOccurred);
                },
                complete: function() {
                    $('#eva-save-slot-btn').prop('disabled', false).text('Salva modifiche');
                }
            });
        },

        toggleSlotStatus: function(slotId, currentStatus) {
            var self = this;

            $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eva_admin_toggle_slot_status',
                    nonce: evaAdminData.nonce,
                    slot_id: slotId,
                    current_status: currentStatus
                },
                success: function(response) {
                    if (response.success) {
                        $('tr[data-slot-id="' + slotId + '"]').replaceWith(response.data.html);
                        self.showNotice('success', response.data.message);
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    self.showNotice('error', evaAdminData.i18n.errorOccurred);
                }
            });
        },

        deleteSlot: function(slotId) {
            var self = this;

            if (!confirm(evaAdminData.i18n.confirmDelete)) {
                return;
            }

            $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eva_admin_delete_slot',
                    nonce: evaAdminData.nonce,
                    slot_id: slotId
                },
                success: function(response) {
                    if (response.success) {
                        $('tr[data-slot-id="' + slotId + '"]').fadeOut(function() {
                            $(this).remove();

                            // Check if table is empty
                            if ($('#eva-slots-table tbody tr').length === 0) {
                                $('#eva-slots-table tbody').append(
                                    '<tr class="eva-no-slots"><td colspan="7">Nessuno slot creato. Aggiungi il primo slot sopra.</td></tr>'
                                );
                            }
                        });
                        self.showNotice('success', response.data.message);
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    self.showNotice('error', evaAdminData.i18n.errorOccurred);
                }
            });
        },

        bulkEnable: function() {
            var self = this;
            var productIds = [];

            $('input[name="product_ids[]"]:checked').each(function() {
                productIds.push($(this).val());
            });

            if (productIds.length === 0) {
                alert('Seleziona almeno un prodotto.');
                return;
            }

            $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eva_admin_bulk_enable',
                    nonce: $('#eva_bulk_nonce').val(),
                    product_ids: productIds
                },
                beforeSend: function() {
                    $('#eva-bulk-enable-form button[type="submit"]').prop('disabled', true).text('Aggiornamento...');
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    self.showNotice('error', evaAdminData.i18n.errorOccurred);
                },
                complete: function() {
                    $('#eva-bulk-enable-form button[type="submit"]').prop('disabled', false).text('Abilita selezionati');
                }
            });
        },

        showNotice: function(type, message) {
            var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

            // Try to find a suitable container for the notice
            var $container = $('.eva-slots-container');
            if ($container.length === 0) {
                $container = $('.eva-slot-bookings-content');
            }
            if ($container.length === 0) {
                $container = $('.wrap');
            }

            $container.prepend(notice);

            setTimeout(function() {
                notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        sendReminderSingle: function($btn, data) {
            var self = this;
            var originalText = $btn.html();

            if (!confirm('Inviare il promemoria a ' + data.customer_email + '?')) {
                return;
            }

            $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eva_admin_send_reminder',
                    nonce: evaAdminData.nonce,
                    slot_id: data.slot_id,
                    customer_email: data.customer_email,
                    customer_name: data.customer_name,
                    quantity: data.quantity,
                    course_name: data.course_name,
                    course_date: data.course_date,
                    course_time: data.course_time,
                    course_end_time: data.course_end_time
                },
                beforeSend: function() {
                    $btn.prop('disabled', true).html('Invio...');
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        $btn.html('✓ Inviato');
                        setTimeout(function() {
                            $btn.html(originalText).prop('disabled', false);
                        }, 3000);
                    } else {
                        self.showNotice('error', response.data.message);
                        $btn.html(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    self.showNotice('error', evaAdminData.i18n.errorOccurred);
                    $btn.html(originalText).prop('disabled', false);
                }
            });
        },

        sendReminderAll: function($btn, slotId) {
            var self = this;
            var originalText = $btn.html();
            var participantCount = $('#eva-slot-bookings-table tbody tr').length;

            if (!confirm('Inviare il promemoria a tutti i ' + participantCount + ' partecipanti?')) {
                return;
            }

            $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eva_admin_send_reminder_all',
                    nonce: evaAdminData.nonce,
                    slot_id: slotId
                },
                beforeSend: function() {
                    $btn.prop('disabled', true).html('Invio in corso...');
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        $btn.html('✓ Inviati ' + response.data.sent);
                        setTimeout(function() {
                            $btn.html(originalText).prop('disabled', false);
                        }, 5000);
                    } else {
                        self.showNotice('error', response.data.message);
                        $btn.html(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    self.showNotice('error', evaAdminData.i18n.errorOccurred);
                    $btn.html(originalText).prop('disabled', false);
                }
            });
        }
    };

    $(document).ready(function() {
        EvaAdmin.init();
    });

})(jQuery);

