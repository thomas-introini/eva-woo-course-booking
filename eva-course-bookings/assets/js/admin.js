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
            this.initCalendar();
        },

        initCalendar: function() {
            var self = this;
            var dialog = document.getElementById('eva-slot-details-modal');
            if (!dialog) {
                return;
            }
            self.initCalendarCreation();

            $(document).on('click', '.eva-calendar-nav', function(e) {
                e.preventDefault();
                self.loadCalendar($(this).data('month'));
            });
            $('#eva-calendar-filters').on('submit', function(e) {
                e.preventDefault();
                self.loadCalendar($(this).find('[name="month"]').val());
            });
            $(document).on('click', '.eva-calendar-retry', function() {
                self.loadCalendar(self.requestedMonth);
            });
            $(document).on('click', '.eva-calendar-slot', function(e) {
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
                    return;
                }
                e.preventDefault();
                self.returnSlotId = $(this).data('slot-id');
                dialog.showModal();
                $('body').addClass('eva-dialog-open');
                self.loadSlotDetails(self.returnSlotId);
            });
            $(document).on('click', '.eva-dialog-close', function() {
                $(this).closest('dialog')[0].close();
            });
            $(document).on('click', '.eva-retry-slot-details', function() {
                self.loadSlotDetails(self.detailsSlotId);
            });
            $(document).on('click', '.eva-move-reservation', function() {
                var $row = $(this).closest('tr');
                self.moveOrigin = $(this);
                self.moveData = {
                    order_id: $row.data('order-id'),
                    item_id: $row.data('item-id'),
                    product_id: $row.data('product-id'),
                    quantity: $row.data('quantity'),
                    old_slot_id: self.detailsSlotId
                };
                var $body = $(dialog).find('.eva-dialog-body');
                $body.find('.eva-slot-bookings-content').hide();
                $body.find('.eva-reservation-move').prop('hidden', false);
                $body.find('.eva-move-summary').text('Ordine #' + self.moveData.order_id + ' · ' + $row.data('customer-name') + ' · ' + self.moveData.quantity + ' partecipanti');
                $body.find('.eva-move-cancel').trigger('focus');
                self.loadMoveSlots();
            });
            $(document).on('click', '.eva-move-cancel', function() {
                if (self.moveRequest) {
                    self.moveRequest.abort();
                }
                if (self.moveData && self.moveData.needs_refresh) {
                    var source = self.moveData.old_slot_id;
                    self.moveData = null;
                    self.loadSlotDetails(source);
                    return;
                }
                $(dialog).find('.eva-reservation-move').prop('hidden', true);
                $(dialog).find('.eva-slot-bookings-content').show();
                self.moveData = null;
                self.moveOrigin.trigger('focus');
            });
            $(document).on('click', '.eva-move-reload', function() {
                self.loadMoveSlots();
            });
            $(document).on('change', '#eva-move-destination', function() {
                var $panel = $(this).closest('.eva-reservation-move');
                $panel.find('.eva-move-confirm').prop('disabled', !$(this).val());
                $panel.find('.eva-move-review').text($(this).val() ? 'Destinazione: ' + $(this).find(':selected').text() + '. Verranno spostati ' + self.moveData.quantity + ' partecipanti.' : '');
            });
            $(document).on('click', '.eva-move-confirm', function() {
                self.moveReservation();
            });
            $(document).on('click', '.eva-move-open-destination', function() {
                self.returnSlotId = $(this).data('slot-id');
                self.loadSlotDetails(self.returnSlotId);
            });
            $(dialog).on('cancel', function(e) {
                if (self.movingReservation) {
                    e.preventDefault();
                }
            });
            $(dialog).on('close', function() {
                self.detailsSlotId = null;
                self.moveData = null;
                if (self.detailsRequest) {
                    self.detailsRequest.abort();
                }
                if (self.moveRequest) {
                    self.moveRequest.abort();
                }
                $('body').removeClass('eva-dialog-open');
                var $target = $('.eva-calendar-slot[data-slot-id="' + self.returnSlotId + '"]');
                ($target.length ? $target : $('#eva-calendar-heading')).trigger('focus');
            });
        },

        initCalendarCreation: function() {
            var self = this;
            var dialog = document.getElementById('eva-slot-create-modal');
            var $form = $('#eva-create-slot-form');
            $(document).on('click', '.eva-calendar-creatable', function(e) {
                if ($(e.target).closest('.eva-calendar-slot').length) {
                    return;
                }
                e.preventDefault();
                self.createOriginDate = $(this).data('create-date');
                self.createNeedsRefresh = false;
                if ($form.length) {
                    $form[0].reset();
                    $form.find('fieldset, button').prop('disabled', false);
                    $form.find('[name="date"]').val(self.createOriginDate);
                    var $course = $form.find('[name="product_id"]');
                    $course.val($('#eva-calendar-product').val());
                    if (!$course.val()) {
                        $course.val($course.find('option').length === 2 ? $course.find('option').eq(1).val() : '');
                    }
                    $('#eva-create-slot-feedback').empty();
                }
                $(dialog).find('.eva-dialog-close').prop('disabled', false);
                dialog.showModal();
                $('body').addClass('eva-dialog-open');
                if ($form.length && $form.find('[name="product_id"]').val()) {
                    $('#eva-create-time').trigger('focus');
                }
            });
            $form.on('submit', function(e) {
                e.preventDefault();
                self.createCalendarSlot(this);
            });
            $(dialog).on('click', '.eva-create-cancel', function() {
                dialog.close();
            }).on('cancel', function(e) {
                if (self.creatingSlot) {
                    e.preventDefault();
                }
            }).on('close', function() {
                $('body').removeClass('eva-dialog-open');
                var $day = $('.eva-calendar-creatable[data-create-date="' + self.createOriginDate + '"] .eva-calendar-create');
                ($day.length ? $day : $('#eva-calendar-heading')).trigger('focus');
                if (self.createNeedsRefresh) {
                    if ($('#eva-calendar-product').val() !== '0') {
                        $('#eva-calendar-product').val($form.find('[name="product_id"]').val());
                    }
                    if ($('#eva-calendar-status').val() === 'closed') {
                        $('#eva-calendar-status').val('');
                    }
                    self.loadCalendar($form.find('[name="date"]').val().slice(0, 7));
                }
            });
        },

        createCalendarSlot: function(form) {
            var self = this;
            if (self.creatingSlot || self.createNeedsRefresh || !form.reportValidity()) {
                return;
            }
            var $form = $(form);
            var $dialog = $form.closest('dialog');
            var $feedback = $('#eva-create-slot-feedback');
            var date = $form.find('[name="date"]').val().split('-');
            var data = {
                action: 'eva_admin_create_slot', nonce: evaAdminData.nonce,
                product_id: $form.find('[name="product_id"]').val(),
                date: date[2] + '/' + date[1] + '/' + date[0],
                time: $form.find('[name="time"]').val(),
                end_time: $form.find('[name="end_time"]').val(),
                capacity: $form.find('[name="capacity"]').val()
            };
            self.creatingSlot = true;
            $form.attr('aria-busy', 'true').find('fieldset, button').prop('disabled', true);
            $dialog.find('.eva-dialog-close').prop('disabled', true);
            $feedback.text('Creazione in corso...');
            $.ajax({url: evaAdminData.ajaxUrl, type: 'POST', timeout: 20000, data: data})
                .done(function(response) {
                    if (!response.success) {
                        $feedback.text(response.data.message);
                        return;
                    }
                    if ($('#eva-calendar-product').val() !== '0') {
                        $('#eva-calendar-product').val(response.data.slot.product_id);
                    }
                    if ($('#eva-calendar-status').val() === 'closed') {
                        $('#eva-calendar-status').val('');
                    }
                    $dialog[0].close();
                    self.loadCalendar(response.data.slot.start_datetime.slice(0, 7), response.data.message);
                }).fail(function() {
                    self.createNeedsRefresh = true;
                    $feedback.text('Impossibile verificare l’esito. Chiudi questa finestra per aggiornare il calendario e controllare prima di riprovare.');
                }).always(function() {
                    self.creatingSlot = false;
                    $form.removeAttr('aria-busy').find('fieldset, [type="submit"]').prop('disabled', self.createNeedsRefresh);
                    $form.find('.eva-create-cancel').prop('disabled', false);
                    $dialog.find('.eva-dialog-close').prop('disabled', false);
                    if (self.createNeedsRefresh) {
                        $form.find('.eva-create-cancel').trigger('focus');
                    }
                });
        },

        loadCalendar: function(month, message) {
            var self = this;
            self.requestedMonth = month;
            if (self.calendarRequest) {
                self.calendarRequest.abort();
            }
            var $message = $('#eva-calendar-message').empty().text('Caricamento calendario...');
            var $view = $('#eva-calendar-view').attr('aria-busy', 'true');
            var data = {
                action: 'eva_admin_get_calendar_slots',
                nonce: evaAdminData.nonce,
                month: month,
                product_id: $('#eva-calendar-product').val(),
                status: $('#eva-calendar-status').val()
            };
            function failed(message) {
                $message.empty().append(document.createTextNode(message + ' '))
                    .append($('<button type="button" class="button eva-calendar-retry">Riprova</button>'));
            }
            self.calendarRequest = $.ajax({url: evaAdminData.ajaxUrl, type: 'POST', data: data})
                .done(function(response) {
                    if (!response.success) {
                        failed(response.data.message);
                        return;
                    }
                    var hadFocus = $.contains($view[0], document.activeElement);
                    $view.html(response.data.html);
                    $('#eva-calendar-filters [name="month"]').val(response.data.month);
                    $message.text((message ? message + ' ' : '') + $('#eva-calendar-heading').text() + ' · Calendario aggiornato.');
                    var url = new URL(window.location.href);
                    url.searchParams.set('tab', 'calendario');
                    url.searchParams.set('month', response.data.month);
                    url.searchParams.set('product_id', data.product_id);
                    url.searchParams.set('status', data.status);
                    window.history.replaceState(null, '', url);
                    if (hadFocus) {
                        $('#eva-calendar-heading').trigger('focus');
                    }
                })
                .fail(function(xhr, status) {
                    if (status !== 'abort') {
                        failed(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : evaAdminData.i18n.errorOccurred);
                    }
                })
                .always(function() {
                    $view.removeAttr('aria-busy');
                });
        },

        loadSlotDetails: function(slotId, message, destinationSlotId) {
            var self = this;
            var dialog = document.getElementById('eva-slot-details-modal');
            if (!dialog || !dialog.open) {
                return;
            }
            if (self.detailsRequest) {
                self.detailsRequest.abort();
            }
            self.detailsSlotId = slotId;
            var $body = $(dialog).find('.eva-dialog-body').attr('aria-busy', 'true').empty();
            $body.append($('<p role="status">Caricamento dettagli...</p>'));
            $(dialog).find('.eva-dialog-close').trigger('focus');
            function failed(error) {
                $body.empty().append($('<p role="alert">').text(error))
                    .append($('<button type="button" class="button eva-retry-slot-details">Riprova</button>'));
            }
            self.detailsRequest = $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {action: 'eva_admin_get_slot_details', nonce: evaAdminData.nonce, slot_id: slotId}
            }).done(function(response) {
                if (!response.success) {
                    failed(response.data.message);
                    return;
                }
                $body.html(response.data.html);
                if (destinationSlotId) {
                    var $notice = $('<div class="notice notice-success" role="status">').append($('<p>').text(message));
                    $notice.find('p').append(' ', $('<button type="button" class="button eva-move-open-destination">Apri slot di destinazione</button>').data('slot-id', destinationSlotId));
                    $body.prepend($notice);
                } else if (message) {
                    self.showNotice('success', message);
                }
            }).fail(function(xhr, status) {
                if (status !== 'abort') {
                    failed(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : evaAdminData.i18n.errorOccurred);
                }
            }).always(function() {
                $body.removeAttr('aria-busy');
            });
        },

        refreshCalendarSlot: function(slotId, message) {
            this.loadCalendar($('#eva-calendar-filters [name="month"]').val());
            if (this.detailsSlotId === slotId) {
                this.loadSlotDetails(slotId, message);
            }
        },

        loadMoveSlots: function() {
            var self = this;
            var context = self.moveData;
            if (!context) {
                return;
            }
            if (self.moveRequest) {
                self.moveRequest.abort();
            }
            var $panel = $('.eva-reservation-move');
            var $select = $panel.find('#eva-move-destination').empty().prop('disabled', true);
            $panel.find('.eva-move-confirm').prop('disabled', true);
            $panel.find('.eva-move-review').empty();
            $panel.find('.eva-move-feedback').text('Caricamento disponibilità...');
            self.moveRequest = $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                data: {action: 'eva_admin_get_product_slots', nonce: evaAdminData.nonce, product_id: context.product_id}
            }).done(function(response) {
                if (self.moveData !== context) {
                    return;
                }
                if (!response.success) {
                    $panel.find('.eva-move-feedback').text(response.data.message);
                    return;
                }
                var available = 0;
                $select.append($('<option value="">Scegli nuovo slot</option>'));
                $.each(response.data.slots, function(index, slot) {
                    if (slot.id === context.old_slot_id) {
                        return;
                    }
                    var enough = slot.remaining >= context.quantity;
                    $select.append($('<option>').val(slot.id).text(slot.label + (enough ? '' : ' · Posti insufficienti')).prop('disabled', !enough));
                    if (enough) {
                        available++;
                    }
                });
                $select.prop('disabled', available === 0);
                $panel.find('.eva-move-feedback').text(available ? 'Seleziona lo slot e verifica la destinazione prima di confermare.' : 'Nessun altro slot futuro aperto ha posti sufficienti per questa prenotazione.');
            }).fail(function(xhr, status) {
                if (status !== 'abort') {
                    $panel.find('.eva-move-feedback').text(evaAdminData.i18n.errorOccurred + ' Usa “Aggiorna disponibilità” per riprovare.');
                }
            });
        },

        moveReservation: function() {
            var self = this;
            var context = self.moveData;
            var $panel = $('.eva-reservation-move');
            var destination = $panel.find('#eva-move-destination').val();
            if (!context || !destination || self.movingReservation) {
                return;
            }
            self.movingReservation = true;
            $panel.find('button, select').prop('disabled', true);
            $('.eva-dialog-close').prop('disabled', true);
            $panel.find('.eva-move-feedback').text('Spostamento in corso...');
            $.ajax({
                url: evaAdminData.ajaxUrl,
                type: 'POST',
                timeout: 20000,
                data: {
                    action: 'eva_admin_change_slot', nonce: evaAdminData.nonce,
                    order_id: context.order_id, item_id: context.item_id,
                    old_slot_id: context.old_slot_id, new_slot_id: destination
                }
            }).done(function(response) {
                if (!response.success) {
                    $panel.find('.eva-move-feedback').text(response.data.message);
                    return;
                }
                self.moveData = null;
                $('.eva-dialog-close').prop('disabled', false);
                self.loadCalendar($('#eva-calendar-filters [name="month"]').val());
                self.loadSlotDetails(context.old_slot_id, response.data.message, response.data.destination_slot_id);
            }).fail(function() {
                context.needs_refresh = true;
                $panel.find('.eva-move-feedback').text('Impossibile verificare l’esito. Torna alle prenotazioni per aggiornare i dettagli prima di riprovare.');
            }).always(function() {
                self.movingReservation = false;
                $panel.find('button, select').prop('disabled', false);
                if (context.needs_refresh) {
                    $panel.find('.eva-move-confirm, #eva-move-destination, .eva-move-reload').prop('disabled', true);
                }
                $('.eva-dialog-close').prop('disabled', false);
            });
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
            $('#eva-edit-slot-capacity').attr('min', Math.max(1, slot.booked));
            $('#eva-edit-slot-date, #eva-edit-slot-time, #eva-edit-slot-end-time').prop('disabled', !!slot.dates_locked);
            $('#eva-edit-slot-modal .eva-dates-locked-notice').remove();
            if (slot.dates_locked) {
                $('#eva-edit-slot-modal h3').after($('<p class="eva-dates-locked-notice">Data e orari bloccati: lo slot ha uno storico prenotazioni.</p>'));
            }

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
            var inCalendar = $('#eva-edit-slot-id').closest('#eva-slot-details-modal').length > 0;
            if ($('#eva-edit-slot-date').attr('type') === 'date') {
                date = date.split('-').reverse().join('/');
            }

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
                        if (inCalendar) {
                            self.refreshCalendarSlot(parseInt(slotId, 10), response.data.message);
                            return;
                        }
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
            var $buttons = $('.eva-toggle-status[data-slot-id="' + slotId + '"]').prop('disabled', true);

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
                        if ($('#eva-calendar-content').length) {
                            self.refreshCalendarSlot(slotId, response.data.message);
                            return;
                        }
                        $('tr[data-slot-id="' + slotId + '"]').replaceWith(response.data.html);
                        self.showNotice('success', response.data.message);
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    self.showNotice('error', evaAdminData.i18n.errorOccurred);
                },
                complete: function() {
                    $buttons.prop('disabled', false);
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
            var notice = $('<div class="notice ' + noticeClass + ' is-dismissible" role="status">').append($('<p>').text(message));

            // Try to find a suitable container for the notice
            var $container = $('#eva-slot-details-modal[open] .eva-dialog-body');
            if ($container.length === 0) {
                $container = $('.eva-slots-container');
            }
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

            if (!confirm('Inviare il promemoria a tutte le ' + participantCount + ' prenotazioni nello storico, inclusi ordini annullati e rimborsati?')) {
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

