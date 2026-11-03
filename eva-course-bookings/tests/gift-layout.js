// Run with node; exercise the editor's real handlers with DOM and file-picker boundaries stubbed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const numbers = ['x', 'y', 'width', 'height', 'size'];
const original = {
  recipient: { enabled: true, x: 53, y: 94, width: 80, height: 9, size: 9, align: 'L', color: '#ffffff' },
  sender: { enabled: true, x: 10, y: 94, width: 107, height: 10, size: 9, align: 'L', color: '#ffffff' },
  note: { enabled: true, x: 10, y: 75, width: 70, height: 20, size: 11, align: 'L', color: '#d95f2d' },
  reference: { enabled: false, x: 20, y: 185, width: 190, height: 8, size: 10, align: 'L', color: '#000000' },
  quantity: { enabled: false, x: 220, y: 185, width: 57, height: 8, size: 10, align: 'R', color: '#000000' }
};
const node = (extra = {}) => ({ attrs: {}, handlers: {}, value: '', text: '', ...extra });
const editor = node();
const selector = node();
const course = node({ value: '1' });
const product = node({ value: '123' });
const pdf = node({ value: '456' });
const status = node();
const frame = node({ attrs: { src: 'old-preview.pdf' } });
const fileInput = node();
const exportButton = node();
const importButton = node();
const rows = Object.entries(original).map(([name, field]) => {
  const row = node({ attrs: { 'data-voucher-field': name }, inputs: {}, label: node({ text: name }) });
  for (const [key, value] of Object.entries(field)) {
    row.inputs[key] = node({ row, value: String(value), checked: key === 'enabled' && value, readOnly: !field.enabled });
    Object.defineProperty(row.inputs[key], 'valueAsNumber', { get() { return this.value === '' ? NaN : Number(this.value); } });
  }
  row.inputs.size.min = '7';
  row.inputs.size.max = '48';
  return row;
});
const find = (item, query) => {
  if (item === editor) {
    if (query === '[data-voucher-field]') { return rows; }
    if (query === '.eva-voucher-color') { return rows.map(row => row.inputs.color); }
    return ({ '.eva-voucher-layout-status': [status], '.eva-voucher-layout-file': [fileInput], iframe: [frame], '[name="voucher_product_id"]': [product] })[query] || [];
  }
  if (item === selector && query === 'select') { return [course]; }
  if (item.inputs) {
    const key = { '.eva-voucher-field-enabled': 'enabled', select: 'align', '.eva-voucher-color': 'color' }[query];
    if (key) { return [item.inputs[key]]; }
    if (query === 'th') { return [item.label]; }
    if (query === 'input[type="number"]') { return numbers.map(key => item.inputs[key]); }
    const suffix = query.match(/^\[name\$="\[([a-z]+)\]"\]$/);
    if (suffix) { return [item.inputs[suffix[1]]]; }
  }
  return [];
};
let download;
let downloads = 0;
let confirmCalls = 0;
let readCalls = 0;
let pendingRead;
const blobs = new Map();
class Collection extends Array {
  find(query) { return new Collection(...this.flatMap(item => find(item, query))); }
  each(callback) { this.forEach((item, i) => callback.call(item, i, item)); return this; }
  on(events, delegated, callback) { return this.each(function () { for (const event of events.split(' ')) { this.handlers[event] = callback || delegated; } }); }
  trigger(event) { return this.each(function () { if (this.handlers[event]) { this.handlers[event].call(this, {}); } }); }
  val(value) { if (value === undefined) { return this[0]?.value; } return this.each(function () { this.value = String(value); }); }
  prop(key, value) { if (value === undefined) { return this[0]?.[key]; } return this.each(function () { this[key] = value; }); }
  attr(key, value) { if (value === undefined) { return this[0]?.attrs[key]; } return this.each(function () { this.attrs[key] = value; }); }
  text(value) { if (value === undefined) { return this[0]?.text || ''; } return this.each(function () { this.text = value; }); }
  closest() { return new Collection(...this.map(item => item.row)); }
  wpColorPicker(option, value) { return this.each(function () { if (option === 'color') { this.value = value; this.swatch = value; } }); }
  appendTo() { return this; }
  remove() { return this; }
}
function $(value, attrs) {
  if (value === '<a>') { return new Collection(node({ click() { download = blobs.get(attrs.href); downloads++; assert.match(attrs.download, /^eva-voucher-layout-\d+\.json$/); } })); }
  if (typeof value !== 'string') { return new Collection(value); }
  return new Collection(...({
    '.eva-voucher-editor': [editor], '#eva-voucher-course-selector': [selector],
    '.eva-voucher-field-enabled': rows.map(row => row.inputs.enabled),
    '.eva-voucher-layout-export': [exportButton], '.eva-voucher-layout-import': [importButton],
    '.eva-voucher-layout-file': [fileInput]
  }[value] || []));
}
class FileReader {
  abort() { this.aborted = true; }
  readAsText(file) {
    readCalls++;
    pendingRead = file.text().then(result => {
      if (!this.aborted) { this.onload({ target: { result } }); }
    }, () => this.onerror());
  }
}
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/gift-admin.js', 'utf8'), {
  jQuery: $, Blob, FileReader,
  URL: { createObjectURL(blob) { const url = 'blob:' + blobs.size; blobs.set(url, blob); return url; }, revokeObjectURL(url) { blobs.delete(url); } },
  setTimeout(callback) { callback(); },
  window: { confirm() { confirmCalls++; return false; } }
});
const snapshot = () => Object.fromEntries(rows.map(row => [row.attrs['data-voucher-field'], Object.fromEntries(Object.entries(row.inputs).map(([key, input]) => [key, key === 'enabled' ? input.checked : numbers.includes(key) ? input.valueAsNumber : input.value]))]));
async function importText(text) {
  fileInput.files = [new Blob([text])];
  fileInput.handlers.change.call(fileInput);
  await pendingRead;
}
async function run() {
  exportButton.handlers.click.call(exportButton);
  assert.equal(downloads, 1);
  const exported = await download.text();
  assert.deepEqual(JSON.parse(exported), { fields: original }, 'Export must retain all settings, including excluded fields outside the page.');
  assert.doesNotMatch(exported, /pdf_id|product_id|nonce|sample|error_email/);

  product.value = '987';
  pdf.value = '654';
  rows[0].inputs.x.value = '1';
  rows[2].inputs.color.value = '#000000';
  await importText(exported);
  assert.deepEqual(snapshot(), original, 'Import must restore every layout value in the destination editor.');
  assert.equal(product.value, '987');
  assert.equal(pdf.value, '654', 'Environment-specific media selection must remain local.');
  assert.equal(frame.attrs.src, 'about:blank', 'Import must clear the old preview.');
  assert.equal(rows[2].inputs.color.swatch, '#d95f2d');
  assert.equal(rows[3].inputs.x.readOnly, true, 'Excluded fields must retain read-only coordinates.');
  let prevented = false;
  selector.handlers.submit({ preventDefault() { prevented = true; } });
  assert.equal(confirmCalls, 1);
  assert.equal(prevented, true, 'Imported changes must trigger the existing unsaved-changes confirmation.');

  for (const text of ['{', 'null', '{}', JSON.stringify({ fields: { recipient: original.recipient } })]) {
    await importText(text);
    assert.deepEqual(snapshot(), original, 'Invalid files must not change existing fields.');
    assert.match(status.text, /non riuscita/);
  }
  for (const patch of [{ size: 'bad' }, { color: '#123456\n' }, { color: ['#123456'] }, { enabled: '0' }, { align: 'invalid' }, { size: null }]) {
    const invalid = JSON.parse(exported);
    invalid.fields.recipient.x = 47;
    Object.assign(invalid.fields.quantity, patch);
    await importText(JSON.stringify(invalid));
    assert.deepEqual(snapshot(), original, 'Validation must finish before applying any changes, including errors in the last field.');
    assert.match(status.text, /non riuscita/);
  }
  for (const patch of [{ x: -1 }, { width: 2 }, { height: 0 }, { size: 6.9 }, { size: 49 }]) {
    const invalid = JSON.parse(exported);
    Object.assign(invalid.fields.recipient, patch);
    await importText(JSON.stringify(invalid));
    assert.deepEqual(snapshot(), original, 'Enabled fields must retain the renderer limits.');
  }
  const reads = readCalls;
  await importText(' '.repeat(65537));
  assert.equal(readCalls, reads, 'Oversized files must be rejected before reading.');
  assert.match(status.text, /64 KB/);
  fileInput.files = [{ size: 1, text: () => Promise.reject(new Error('read failed')) }];
  fileInput.handlers.change.call(fileInput);
  await pendingRead;
  assert.match(status.text, /Impossibile leggere/);
  assert.deepEqual(snapshot(), original);
  rows[0].inputs.x.value = '';
  exportButton.handlers.click.call(exportButton);
  assert.equal(downloads, 1, 'Invalid input must not produce a corrupt export.');
  console.log('Voucher layout export/import, cross-environment round trip, atomic validation, colors, visibility, dirty state and file errors passed.');
}
run().catch(error => { console.error(error); process.exitCode = 1; });
