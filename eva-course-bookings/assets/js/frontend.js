/**
 * Eva Course Bookings - Frontend JavaScript
 */

(function ($) {
  'use strict';

  var EvaFrontend = {
    selectedSlotId: null,
    selectedSlotStart: null,
    selectedSlotEnd: null,
    skipSlotEnabled: false,

    init: function () {
      if (!$('#eva-slot-selection').length) {
        return;
      }

      this.initDatepicker();
      this.bindEvents();
      this.disableAddToCart();
    },

    initDatepicker: function () {
      var self = this;
      var availableDates = evaFrontendData.availableDates || [];

      var leadTimeDays = evaFrontendData.leadTimeDays || 0;

      $('#eva-date-picker').datepicker({
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
          var dateStr = self.formatDateForAPI(date);
          if (availableDates.indexOf(dateStr) !== -1) {
            return [true, 'eva-available-date', ''];
          }
          return [false, '', ''];
        },
        onSelect: function (dateText) {
          self.onDateSelected(dateText);
        },
      });
    },

    bindEvents: function () {
      var self = this;

      // Skip slot checkbox
      $('#eva-skip-slot-checkbox').on('change', function () {
        self.onSkipSlotChanged($(this).is(':checked'));
      });

      // Gift recipient checkbox
      $('#eva-gift-recipient-checkbox').on('change', function () {
        self.onGiftRecipientChanged($(this).is(':checked'));
      });

      // Gift email input
      $('#eva-gift-email').on('input', function () {
        $('#eva-gift-validation-message').hide();
      });

      // Time slot selection
      $(document).on('click', '.eva-time-slot', function () {
        self.onTimeSlotSelected($(this));
      });

      // Quantity change
      $(document).on('change', '.quantity input.qty', function () {
        self.validateQuantity($(this));
      });

      // Form submission
      $('form.cart').on('submit', function (e) {
        if (!self.validateSelection()) {
          e.preventDefault();
          return false;
        }
      });
    },

    onSkipSlotChanged: function (isChecked) {
      this.skipSlotEnabled = isChecked;

      if (isChecked) {
        // Hide date/time selection
        $('#eva-date-field').hide();
        $('#eva-time-container').hide();
        $('#eva-selected-summary').hide();
        $('#eva-validation-message').hide();

        // Show skip slot summary
        $('#eva-skip-slot-summary').show();

        // Show gift option
        $('#eva-gift-option').show();

        // Set hidden field
        $('#eva-skip-slot').val('1');

        // Clear any previous slot selection
        this.clearSlotSelection();

        // Enable add to cart
        this.enableAddToCart();

        // Remove max quantity restriction
        $('.quantity input.qty').removeAttr('max');
      } else {
        // Show date/time selection
        $('#eva-date-field').show();

        // Hide skip slot summary
        $('#eva-skip-slot-summary').hide();

        // Hide gift option and reset fields
        $('#eva-gift-option').hide();
        $('#eva-gift-recipient-checkbox').prop('checked', false);
        $('#eva-gift-email').val('');
        $('#eva-gift-email-field').hide();
        $('#eva-gift-validation-message').hide();

        // Clear hidden field
        $('#eva-skip-slot').val('');

        // Restore time slots container logic.
        // If a date was already selected, reload and show available time slots.
        // Otherwise keep the time container hidden until the user picks a date.
        var existingDate = $('#eva-date-picker').val();
        if (existingDate) {
          this.onDateSelected(existingDate);
        } else {
          $('#eva-time-container').hide();
          $('#eva-time-slots').empty();
          this.disableAddToCart();
        }
      }
    },

    onGiftRecipientChanged: function (isChecked) {
      if (isChecked) {
        $('#eva-gift-email-field').show();
      } else {
        $('#eva-gift-email-field').hide();
        $('#eva-gift-email').val('');
        $('#eva-gift-validation-message').hide();
      }
    },

    formatDateForAPI: function (date) {
      var year = date.getFullYear();
      var month = ('0' + (date.getMonth() + 1)).slice(-2);
      var day = ('0' + date.getDate()).slice(-2);
      return year + '-' + month + '-' + day;
    },

    onDateSelected: function (dateText) {
      var self = this;

      // Parse Italian date format
      var parts = dateText.split('/');
      var apiDate = parts[2] + '-' + parts[1] + '-' + parts[0];

      // Clear previous selection
      this.clearSlotSelection();

      // Show loading
      $('#eva-time-container').show();
      $('#eva-time-slots').html(
        '<div class="eva-loading">' + evaFrontendData.i18n.loading + '</div>'
      );

      // Fetch available slots
      $.ajax({
        url: evaFrontendData.ajaxUrl,
        type: 'POST',
        data: {
          action: 'eva_get_slots_for_date',
          nonce: evaFrontendData.nonce,
          product_id: evaFrontendData.productId,
          date: apiDate,
        },
        success: function (response) {
          if (response.success && response.data.slots.length > 0) {
            self.renderTimeSlots(response.data.slots);
            return;
          }

          var message = evaFrontendData.i18n.noSlots;
          if (response.data && response.data.message) {
            message = response.data.message;
          }

          $('#eva-time-slots').html('<p>' + message + '</p>');
        },
        error: function () {
          $('#eva-time-slots').html(
            '<p class="eva-error">' + evaFrontendData.i18n.errorLoading + '</p>'
          );
        },
      });
    },

    renderTimeSlots: function (slots) {
      var html = '';
      var self = this;

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

      $('#eva-time-slots').html(html);
    },

    onTimeSlotSelected: function ($slot) {
      // Prevent selection of unavailable slots
      if ($slot.hasClass('eva-time-slot-unavailable')) {
        return;
      }

      // Remove previous selection
      $('.eva-time-slot').removeClass('selected');

      // Add selection to clicked slot
      $slot.addClass('selected');

      // Store selection
      this.selectedSlotId = $slot.data('slot-id');
      this.selectedSlotStart = $slot.data('slot-start');
      this.selectedSlotEnd = $slot.data('slot-end');

      // Update hidden inputs
      $('#eva-slot-id').val(this.selectedSlotId);
      $('#eva-slot-start').val(this.selectedSlotStart);
      $('#eva-slot-end').val(this.selectedSlotEnd);

      // Update max quantity
      var maxQty = $slot.data('remaining');
      $('.quantity input.qty').attr('max', maxQty);

      // If current quantity exceeds max, adjust it
      var currentQty = parseInt($('.quantity input.qty').val()) || 1;
      if (currentQty > maxQty) {
        $('.quantity input.qty').val(maxQty);
      }

      // Show summary
      this.showSelectedSummary($slot);

      // Enable add to cart
      this.enableAddToCart();

      // Hide validation message
      $('#eva-validation-message').hide();
    },

    showSelectedSummary: function ($slot) {
      var dateText = $('#eva-date-picker').val();
      var timeText = $slot.find('.eva-time-slot-time').text();

      var summaryText = 'Data: ' + dateText + ' alle ' + timeText;

      $('#eva-summary-text').text(summaryText);
      $('#eva-selected-summary').show();
    },

    clearSlotSelection: function () {
      this.selectedSlotId = null;
      this.selectedSlotStart = null;
      this.selectedSlotEnd = null;

      $('#eva-slot-id').val('');
      $('#eva-slot-start').val('');
      $('#eva-slot-end').val('');

      $('.eva-time-slot').removeClass('selected');
      $('#eva-selected-summary').hide();

      this.disableAddToCart();
    },

    validateQuantity: function ($input) {
      if (!this.selectedSlotId) {
        return;
      }

      var qty = parseInt($input.val()) || 1;
      var maxQty = parseInt($input.attr('max')) || 999;

      if (qty > maxQty) {
        $input.val(maxQty);
        alert(
          'Sono disponibili solo ' + maxQty + " posti per l'orario selezionato."
        );
      }

      if (qty < 1) {
        $input.val(1);
      }
    },

    validateSelection: function () {
      // If skip slot is enabled, always valid
      if (this.skipSlotEnabled) {
        var wantsGift = $('#eva-gift-recipient-checkbox').is(':checked');
        if (wantsGift) {
          var $giftEmail = $('#eva-gift-email');
          var emailValue = $giftEmail.val().trim();
          if (!emailValue || !$giftEmail[0].checkValidity()) {
            $('#eva-gift-validation-message').show();
            $('html, body').animate(
              {
                scrollTop: $('#eva-gift-option').offset().top - 100,
              },
              300
            );
            return false;
          }
        }
        return true;
      }

      if (!this.selectedSlotId) {
        $('#eva-validation-message').show();

        // Scroll to selection
        $('html, body').animate(
          {
            scrollTop: $('#eva-slot-selection').offset().top - 100,
          },
          300
        );

        return false;
      }

      // Check if selected slot is unavailable
      var $selectedSlot = $(
        '.eva-time-slot[data-slot-id="' + this.selectedSlotId + '"]'
      );
      if (
        $selectedSlot.length &&
        $selectedSlot.hasClass('eva-time-slot-unavailable')
      ) {
        $('#eva-validation-message')
          .text('Non puoi selezionare un orario con posti esauriti.')
          .show();

        // Scroll to selection
        $('html, body').animate(
          {
            scrollTop: $('#eva-slot-selection').offset().top - 100,
          },
          300
        );

        return false;
      }

      return true;
    },

    disableAddToCart: function () {
      var $btn = $('.single_add_to_cart_button');
      $btn.addClass('disabled').prop('disabled', true);
    },

    enableAddToCart: function () {
      var $btn = $('.single_add_to_cart_button');
      $btn.removeClass('disabled').prop('disabled', false);
    },
  };

  $(document).ready(function () {
    EvaFrontend.init();
  });
})(jQuery);
