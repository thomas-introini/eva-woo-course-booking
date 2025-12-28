/**
 * Eva Course Bookings - Booking Management JavaScript
 */

(function ($) {
  'use strict';

  var EvaBooking = {
    init: function () {
      if (!$('.eva-booking-portal').length) {
        return;
      }

      this.initDatepickers();
      this.bindEvents();
    },

    initDatepickers: function () {
      var self = this;

      $('.eva-booking-slot-selection').each(function () {
        var $selection = $(this);
        var availableDates = $selection.data('available-dates') || [];

        if (typeof availableDates === 'string') {
          try {
            availableDates = JSON.parse(availableDates);
          } catch (e) {
            availableDates = [];
          }
        }

        var $dateInput = $selection.find('.eva-datepicker');
        if (!availableDates.length) {
          $dateInput.prop('disabled', true);
          return;
        }

        var leadTimeDays = evaBookingData.leadTimeDays || 0;

        $dateInput.datepicker({
          dateFormat: 'dd/mm/yy',
          minDate: leadTimeDays,
          firstDay: 1,
          dayNamesMin: ['Do', 'Lu', 'Ma', 'Me', 'Gi', 'Ve', 'Sa'],
          monthNames: [
            'Gennaio',
            'Febbraio',
            'Marzo',
            'Aprile',
            'Maggio',
            'Giugno',
            'Luglio',
            'Agosto',
            'Settembre',
            'Ottobre',
            'Novembre',
            'Dicembre',
          ],
          monthNamesShort: [
            'Gen',
            'Feb',
            'Mar',
            'Apr',
            'Mag',
            'Giu',
            'Lug',
            'Ago',
            'Set',
            'Ott',
            'Nov',
            'Dic',
          ],
          beforeShowDay: function (date) {
            var dateStr = self.formatDateForApi(date);
            if (availableDates.indexOf(dateStr) !== -1) {
              return [true, 'eva-available-date', ''];
            }
            return [false, '', ''];
          },
          onSelect: function (dateText) {
            self.onDateSelected($selection, dateText);
          },
        });
      });
    },

    bindEvents: function () {
      var self = this;

      $(document).on(
        'click',
        '.eva-booking-slot-selection .eva-time-slot',
        function () {
          self.onTimeSlotSelected($(this));
        }
      );

      $(document).on('submit', '.eva-booking-item-form', function (e) {
        var $form = $(this);
        if (!self.validateSelection($form)) {
          e.preventDefault();
          return false;
        }
      });
    },

    formatDateForApi: function (date) {
      var year = date.getFullYear();
      var month = ('0' + (date.getMonth() + 1)).slice(-2);
      var day = ('0' + date.getDate()).slice(-2);
      return year + '-' + month + '-' + day;
    },

    onDateSelected: function ($selection, dateText) {
      var self = this;
      var parts = dateText.split('/');
      var apiDate = parts[2] + '-' + parts[1] + '-' + parts[0];
      var productId = $selection.data('product-id');

      self.clearSlotSelection($selection);

      $selection.find('.eva-time-field').show();
      $selection
        .find('.eva-time-slots')
        .html(
          '<div class="eva-loading">' + evaBookingData.i18n.loading + '</div>'
        );

      $.ajax({
        url: evaBookingData.ajaxUrl,
        type: 'POST',
        data: {
          action: 'eva_get_slots_for_date',
          nonce: evaBookingData.nonce,
          product_id: productId,
          date: apiDate,
        },
        success: function (response) {
          if (response.success && response.data.slots.length > 0) {
            self.renderTimeSlots($selection, response.data.slots);
            return;
          }

          var message = evaBookingData.i18n.noSlots;
          if (response.data && response.data.message) {
            message = response.data.message;
          }

          $selection.find('.eva-time-slots').html('<p>' + message + '</p>');
        },
        error: function () {
          $selection
            .find('.eva-time-slots')
            .html(
              '<p class="eva-error">' + evaBookingData.i18n.errorLoading + '</p>'
            );
        },
      });
    },

    renderTimeSlots: function ($selection, slots) {
      var html = '';

      slots.forEach(function (slot) {
        var timeDisplay = slot.time;
        if (slot.end_time) {
          timeDisplay += ' - ' + slot.end_time;
        }

        var isUnavailable = slot.remaining <= 0;
        var unavailableClass = isUnavailable
          ? ' eva-time-slot-unavailable'
          : '';
        var unavailableLabel = isUnavailable
          ? '<span class="eva-time-slot-unavailable-label">Posti esauriti</span>'
          : '';

        html +=
          '<div class="eva-time-slot' +
          unavailableClass +
          '" data-slot-id="' +
          slot.id +
          '" ' +
          'data-slot-start="' +
          slot.start_full +
          '" ' +
          'data-slot-end="' +
          (slot.end_full || '') +
          '" ' +
          'data-remaining="' +
          slot.remaining +
          '">' +
          '<span class="eva-time-slot-time">' +
          timeDisplay +
          '</span>' +
          unavailableLabel +
          '</div>';
      });

      $selection.find('.eva-time-slots').html(html);
    },

    onTimeSlotSelected: function ($slot) {
      if ($slot.hasClass('eva-time-slot-unavailable')) {
        return;
      }

      var $selection = $slot.closest('.eva-booking-slot-selection');
      $selection.find('.eva-time-slot').removeClass('selected');
      $slot.addClass('selected');

      $selection.find('.eva-slot-id').val($slot.data('slot-id'));
      $selection.find('.eva-slot-start').val($slot.data('slot-start'));
      $selection.find('.eva-slot-end').val($slot.data('slot-end'));

      var dateText = $selection.find('.eva-datepicker').val();
      var timeText = $slot.find('.eva-time-slot-time').text();
      $selection
        .find('.eva-summary-text')
        .text('Data: ' + dateText + ' alle ' + timeText);
      $selection.find('.eva-selected-summary').show();
      $selection.find('.eva-validation-message').hide();
      $selection.find('.eva-booking-submit').prop('disabled', false);
    },

    clearSlotSelection: function ($selection) {
      $selection.find('.eva-slot-id').val('');
      $selection.find('.eva-slot-start').val('');
      $selection.find('.eva-slot-end').val('');
      $selection.find('.eva-selected-summary').hide();
      $selection.find('.eva-time-slot').removeClass('selected');
      $selection.find('.eva-booking-submit').prop('disabled', true);
    },

    validateSelection: function ($form) {
      var $selection = $form.closest('.eva-booking-slot-selection');
      var slotId = $selection.find('.eva-slot-id').val();

      if (!slotId) {
        $selection.find('.eva-validation-message').show();
        return false;
      }

      return true;
    },
  };

  $(document).ready(function () {
    EvaBooking.init();
  });
})(jQuery);
