(function ($) {
  'use strict';
  var dirty = false;
  var editor = $('.eva-voucher-editor');
  var selector = $('#eva-voucher-course-selector');
  var selectedCourse = selector.find('select').val();
  var dimensionRequest;
  var layoutReader;
  var layoutRows = editor.find('[data-voucher-field]');
  var numericFields = ['x', 'y', 'width', 'height', 'size'];

  editor.find('.eva-voucher-color').wpColorPicker({ change: function () { dirty = true; } });
  editor.on('input change', ':input', function () { dirty = true; });
  $('.eva-voucher-field-enabled').on('change', function () {
    $(this).closest('tr').find('input[type="number"]').prop('readOnly', !this.checked);
  });

  function validateLayout(fields) {
    if (!fields || typeof fields !== 'object' || Array.isArray(fields) || Object.keys(fields).length !== layoutRows.length) {
      throw new Error('File del layout non valido: devono essere presenti tutti i campi del voucher.');
    }
    layoutRows.each(function () {
      var row = $(this);
      var field = fields[row.attr('data-voucher-field')];
      var fontInput = row.find('[name$="[size]"]')[0];
      if (!field || typeof field.enabled !== 'boolean' || ['L', 'C', 'R'].indexOf(field.align) === -1 ||
          typeof field.color !== 'string' || field.color.length !== 7 || !/^#[a-fA-F0-9]{6}$/.test(field.color) ||
          numericFields.some(function (key) { return !Number.isFinite(field[key]); }) ||
          (field.enabled && (field.x < 0 || field.y < 0 || field.width <= 2 || field.height <= 0 ||
            field.size < Number(fontInput.min) || field.size > Number(fontInput.max)))) {
        throw new Error('Valori del layout non validi per il campo ' + row.find('th').text().trim() + '.');
      }
    });
  }

  $('.eva-voucher-layout-export').on('click', function () {
    var status = editor.find('.eva-voucher-layout-status');
    try {
      var fields = {};
      layoutRows.each(function () {
        var row = $(this);
        var field = {
          enabled: row.find('.eva-voucher-field-enabled').prop('checked'),
          align: row.find('select').val(),
          color: row.find('.eva-voucher-color').val()
        };
        numericFields.forEach(function (key) { field[key] = row.find('[name$="[' + key + ']"]')[0].valueAsNumber; });
        fields[row.attr('data-voucher-field')] = field;
      });
      validateLayout(fields);
      var url = URL.createObjectURL(new Blob([JSON.stringify({ fields: fields }, null, 2)], { type: 'application/json' }));
      var link = $('<a>', { href: url, download: 'eva-voucher-layout-' + editor.find('[name="voucher_product_id"]').val() + '.json' }).appendTo('body');
      link[0].click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      status.text('Layout esportato.');
    } catch (error) {
      status.text(error.message);
    }
  });

  $('.eva-voucher-layout-import').on('click', function () {
    editor.find('.eva-voucher-layout-file').val('').trigger('click');
  });
  $('.eva-voucher-layout-file').on('change', function () {
    var file = this.files[0];
    var status = editor.find('.eva-voucher-layout-status');
    if (!file) { return; }
    if (layoutReader) { layoutReader.abort(); }
    if (file.size > 65536) { status.text('Il file del layout non può superare 64 KB.'); return; }
    layoutReader = new FileReader();
    layoutReader.onload = function (event) {
      try {
        var layout = JSON.parse(event.target.result);
        var fields = layout && layout.fields;
        validateLayout(fields);
        layoutRows.each(function () {
          var row = $(this);
          var field = fields[row.attr('data-voucher-field')];
          numericFields.forEach(function (key) { row.find('[name$="[' + key + ']"]').val(field[key]); });
          row.find('.eva-voucher-field-enabled').prop('checked', field.enabled).trigger('change');
          row.find('select').val(field.align);
          row.find('.eva-voucher-color').wpColorPicker('color', field.color);
        });
        dirty = true;
        editor.find('iframe').attr('src', 'about:blank');
        status.text('Layout importato. Genera l’anteprima e salva la configurazione del corso.');
      } catch (error) {
        status.text('Importazione non riuscita. ' + (error instanceof SyntaxError ? 'Scegli un file JSON esportato da questo editor.' : error.message));
      }
    };
    layoutReader.onerror = function () { status.text('Impossibile leggere il file del layout. Riprova.'); };
    layoutReader.readAsText(file);
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
