(function ($) {
  'use strict';
  var dirty = false;
  var editor = $('.eva-voucher-editor');
  var selector = $('#eva-voucher-course-selector');
  var selectedCourse = selector.find('select').val();
  var dimensionRequest;

  editor.on('input change', ':input', function () { dirty = true; });
  $('.eva-voucher-field-enabled').on('change', function () {
    $(this).closest('tr').find('input[type="number"]').prop('readOnly', !this.checked);
  });
  function refreshPdf() {
    dirty = true;
    editor.find('iframe').attr('src', 'about:blank');
    if (dimensionRequest) { dimensionRequest.abort(); }
    if (!Number($('#eva-gift-pdf-id').val())) {
      editor.find('.eva-voucher-dimensions').text('Seleziona un PDF per rilevare le dimensioni.');
      return;
    }
    editor.find('.eva-voucher-dimensions').text('Lettura dimensioni…');
    dimensionRequest = $.post(editor.attr('data-ajax-url'), {
      action: 'eva_voucher_dimensions',
      eva_voucher_nonce: editor.find('[name="eva_voucher_nonce"]').val(),
      voucher_product_id: editor.find('[name="voucher_product_id"]').val(),
      _eva_gift_pdf_id: $('#eva-gift-pdf-id').val()
    }).done(function (response) {
      editor.find('.eva-voucher-dimensions').text(response.data.width.toFixed(1) + ' × ' + response.data.height.toFixed(1) + ' mm');
    }).fail(function (response, status) {
      if (status !== 'abort') {
        editor.find('.eva-voucher-dimensions').text(response.responseJSON ? response.responseJSON.data : 'Impossibile leggere il PDF. Riprova.');
      }
    });
  }
  $('#eva-gift-pdf-select').on('click', function () {
    var picker = wp.media({ title: 'Modello buono regalo', library: { type: 'application/pdf' }, multiple: false });
    picker.on('select', function () {
      var file = picker.state().get('selection').first().toJSON();
      $('#eva-gift-pdf-id').val(file.id);
      $('#eva-gift-pdf-name').text(file.title);
      refreshPdf();
    });
    picker.open();
  });
  $('#eva-gift-pdf-clear').on('click', function () {
    $('#eva-gift-pdf-id').val('0');
    $('#eva-gift-pdf-name').text('Nessun modello');
    refreshPdf();
  });
  selector.on('submit', function (event) {
    if (dirty && !window.confirm('Le modifiche al corso non sono state salvate. Cambiare corso?')) {
      event.preventDefault();
      selector.find('select').val(selectedCourse);
    }
  }).find('select').on('change', function () { selector.trigger('submit'); });

  $('.eva-voucher-preview').on('click', function () {
    var inputs = editor.find(':input[name]');
    for (var i = 0; i < inputs.length; i++) {
      if (!inputs[i].reportValidity()) { return; }
    }
    // The product editor already has a form; send only voucher fields in a separate POST.
    var form = $('<form>', { method: 'post', action: editor.attr('data-post-url'), target: editor.find('iframe').attr('name') }).hide().appendTo('body');
    $.each(inputs.serializeArray().concat([{ name: 'action', value: 'eva_voucher_preview' }]), function (_, field) {
      $('<input>', { type: 'hidden', name: field.name, value: field.value }).appendTo(form);
    });
    form[0].submit();
    form.remove();
  });
  $('.eva-gift-send').on('submit', function (event) {
    if (!window.confirm($(this).attr('data-confirm'))) {
      event.preventDefault();
    }
  });
})(jQuery);
