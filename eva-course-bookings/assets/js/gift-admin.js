(function ($) {
  'use strict';
  $('#eva-gift-pdf-select').on('click', function () {
    var picker = wp.media({ title: 'Modello buono regalo', library: { type: 'application/pdf' }, multiple: false });
    picker.on('select', function () {
      var file = picker.state().get('selection').first().toJSON();
      $('#eva-gift-pdf-id').val(file.id);
      $('#eva-gift-pdf-name').text(file.title);
    });
    picker.open();
  });
  $('#eva-gift-pdf-clear').on('click', function () {
    $('#eva-gift-pdf-id').val('0');
    $('#eva-gift-pdf-name').text('Nessun modello');
  });
  $('.eva-gift-send').on('submit', function (event) {
    if (!window.confirm($(this).attr('data-confirm'))) {
      event.preventDefault();
    }
  });
})(jQuery);
