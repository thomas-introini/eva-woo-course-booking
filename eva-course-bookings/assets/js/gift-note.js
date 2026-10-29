(function () {
  'use strict';
  function validate(note) {
    var value = note.value.replace(/\r\n?/g, '\n');
    var breaks = (value.match(/\n/g) || []).length;
    note.setCustomValidity(Array.from(value).length > note.maxLength || breaks > Number(note.dataset.maxBreaks)
      ? 'La dedica può contenere al massimo ' + note.maxLength + ' caratteri e ' + note.dataset.maxBreaks + ' a capo manuali.'
      : '');
  }
  document.addEventListener('input', function (event) {
    if (event.target.matches('.eva-gift-note')) { validate(event.target); }
  });
  document.addEventListener('focusout', function (event) {
    var note = event.target;
    if (!note.matches('.eva-gift-note')) { return; }
    note.value = note.value.replace(/\r\n?/g, '\n').split('\n').map(function (line) {
      return line.trim();
    }).filter(function (line) { return line !== ''; }).join('\n');
    validate(note);
  });
})();
