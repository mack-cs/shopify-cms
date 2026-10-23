const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// Execute the actual editor factory, rather than a copy of its heartbeat code.
const source = fs.readFileSync(path.join(__dirname, '../../resources/views/filament/pages/shop-your-vibe.blade.php'), 'utf8');
const start = source.indexOf('window.syvEditor = function ()');
const end = source.indexOf('</script>', start);
let clock = 100000;
let heartbeats = 0;
let reads = 0;
const document = { hidden: false };
const window = { crypto: { randomUUID: () => 'browser-session' } };
vm.runInNewContext(source.slice(start, end), { window, crypto: window.crypto, document, Date: { now: () => clock } });
const editor = window.syvEditor();
editor.$el = { dataset: { editingMine: '1', collection: 'collection-1' } };
editor.$wire = {
    editingHeartbeat: async () => { heartbeats++; return { expires_at: 'later' }; },
    editingStatus: async () => { reads++; return {}; },
};
editor.$dispatch = () => {};

(async () => {
    await editor.editingActivity({ isTrusted: false });
    assert.equal(heartbeats, 0, 'Synthetic events cannot renew the lock');
    await editor.editingActivity({ isTrusted: true });
    assert.equal(heartbeats, 1);
    await editor.editingActivity({ isTrusted: true });
    assert.equal(heartbeats, 1, 'Activity heartbeats are throttled');
    clock += 31000;
    document.hidden = true;
    await editor.editingActivity({ isTrusted: true });
    assert.equal(heartbeats, 1, 'Hidden tabs do not renew');
    document.hidden = false;
    for (let i = 0; i < 30; i++) {
        clock += 31000;
        await editor.pollEditingState();
    }
    assert.equal(reads, 30);
    assert.equal(heartbeats, 1, 'Idle status polling never renews an editing lock');
    editor.$el.dataset.editingMine = '0';
    await editor.editingActivity({ isTrusted: true });
    assert.equal(heartbeats, 1, 'Viewers cannot renew another editor’s lock');
    console.log('SYV editing client: activity, throttle, hidden-tab, idle and viewer checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
