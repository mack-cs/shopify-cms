const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.join(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'resources/views/filament/pages/shop-your-vibe.blade.php'), 'utf8');
const vendor = fs.readFileSync(path.join(root, 'vendor/livewire/livewire/dist/livewire.js'), 'utf8');
const context = { window: { crypto: { randomUUID: () => 'test' } }, crypto: { randomUUID: () => 'test' } };
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('window.syvEditor = function ()'), source.indexOf('</script>', source.indexOf('window.syvEditor = function ()'))), context);
// Exercise the installed Livewire diff and snapshot merge, not a replacement.
vm.runInContext(vendor.slice(vendor.indexOf('  function isObjecty('), vendor.indexOf('  function extractData(')), context);
const mergeStart = vendor.indexOf('    mergeNewSnapshot(snapshotEncoded, effects, updates = {})');
const mergeEnd = vendor.indexOf('    queueUpdate(', mergeStart);
vm.runInContext(`var methods = { ${vendor.slice(mergeStart, mergeEnd).trim()}, };`, context);
context.extractData = data => data;
const diff = context.diff;
const clone = value => JSON.parse(JSON.stringify(value));
const initial = { revision: 2, discardReason: '', confirmingDiscard: true, cardForm: { name: 'Confirmed' } };
const fresh = { discardReason: '', confirmingDiscard: true, revision: 2, cardForm: { name: 'Confirmed' } };
assert.deepEqual(Object.keys(diff(initial, fresh)), [''], 'Reproduce invalid empty property update from root key order');
const component = {
    canonical: clone(initial), ephemeral: clone(initial), reactive: clone(initial),
    mergeNewSnapshot: context.methods.mergeNewSnapshot,
    applyUpdates: (data, updates) => Object.assign(data, updates),
};
let prepare;
let cleaned = false;
const cleanup = context.window.syvEditor().protectLivewirePropertyOrder(component, {
    hook: (event, callback) => { assert.equal(event, 'commit.prepare'); prepare = callback; return () => { cleaned = true; }; },
});
const snapshot = JSON.stringify({ data: fresh });
component.mergeNewSnapshot(snapshot, {});
assert.equal(component.snapshotEncoded, snapshot, 'Signed snapshot is unchanged');
assert.equal(Object.hasOwn(component.reactive, ''), false, 'Snapshot merge does not create an empty reactive property');
component.ephemeral.discardReason = 'No longer needed';
prepare({ component });
assert.deepEqual(clone(diff(component.canonical, component.ephemeral)), { discardReason: 'No longer needed' });
// A server response with fresh values and reordered root keys must update data.
component.ephemeral = { ...component.ephemeral, revision: 3 };
component.reactive = component.ephemeral;
component.mergeNewSnapshot(JSON.stringify({ data: { ...initial, revision: 3, cardForm: { name: 'Fresh Shopify title' } } }), {});
assert.equal(component.reactive.cardForm.name, 'Fresh Shopify title');
assert.equal(component.reactive.discardReason, 'No longer needed', 'Unrelated local input is retained');
prepare({ component });
assert.equal(Object.hasOwn(diff(component.canonical, component.ephemeral), ''), false);
const unrelated = { canonical: { b: 1, a: 2 }, ephemeral: { a: 2, b: 1 } };
const original = unrelated.canonical;
prepare({ component: unrelated });
assert.equal(unrelated.canonical, original, 'Other components are untouched');
cleanup();
assert.equal(cleaned, true);
console.log('SYV Livewire properties: reproduced empty path; snapshot, input, fresh values and component isolation checks passed.');
