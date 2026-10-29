// Run with node; exercise the shared storefront/admin textarea handlers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const handlers = {};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/gift-note.js', 'utf8'), {
  document: { addEventListener: (name, handler) => { handlers[name] = handler; } }
});
const note = {
  value: '', maxLength: 250, dataset: { maxBreaks: '2' }, message: '',
  matches: selector => selector === '.eva-gift-note',
  setCustomValidity(message) { this.message = message; }
};
function input(value) {
  note.value = value;
  handlers.input({ target: note });
}
input('è'.repeat(250));
assert.equal(note.message, '');
input('è'.repeat(251));
assert.match(note.message, /250/);
input('Uno\nDue\nTre');
assert.equal(note.message, '');
input('Uno\nDue\nTre\nQuattro');
assert.match(note.message, /2 a capo/);
input('Auguri!\n');
assert.equal(note.value, 'Auguri!\n', 'Typing Enter must leave the newline available for the next line.');
input(' \r\n Auguri! \r\n \t\r\n Ti vogliamo bene. \r\n ');
handlers.focusout({ target: note });
assert.equal(note.value, 'Auguri!\nTi vogliamo bene.');
assert.equal(note.message, '');
input('a'.repeat(251));
handlers.focusout({ target: note });
assert.equal(note.value.length, 251, 'Messages must never be silently truncated.');
assert.notEqual(note.message, '');
input('');
assert.equal(note.message, '', 'Clearing a note must clear its validation error.');
console.log('Gift-note length, manual breaks, whitespace normalization and typing checks passed.');
