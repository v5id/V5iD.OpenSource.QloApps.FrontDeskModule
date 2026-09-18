const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..', 'v5idfrontdesk', 'views', 'js', 'scanners');
const host = {};
new Function('window', fs.readFileSync(path.join(root, 'adapter-support.js'), 'utf8'))(host);
const support = host.V5idScannerSupport;

const MTU = 20;
const chunks = (s) => s.match(new RegExp('.{1,' + MTU + '}', 'gs')) || [];

const AAMVA = '@\n\x1e\rANSI 636014040002DL00410288ZC03290015DLDCAPUBLIC-SAMPLE-PAYLOAD-0001';
const MRZ = 'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<L898902C36UTO7408122F1204159ZE184226B<<<<<10';
const SERIAL = 'SN20240117A';

// Per-chunk classification, as the adapter did before this fix.
function perChunk(payload) {
    let pending = true, serial = null, buffer = '';
    for (const chunk of chunks(payload)) {
        if (pending && !support.looksLikeIdScan(chunk)) {
            const raw = chunk.replace(/[\r\n\x00]/g, '').trim();
            if (raw.length > 0) { pending = false; serial = raw; continue; }
        }
        buffer += chunk;
    }
    return { serial, scan: buffer.trim() || null };
}

// Whole-payload classification, as flushBuffer() does now.
function whole(payload) {
    const text = payload.trim();
    if (!support.looksLikeIdScan(text)) {
        const raw = text.replace(/[\r\n\x00]/g, '').trim();
        if (raw.length > 0) return { serial: raw, scan: null };
    }
    return { serial: null, scan: text };
}

// A scan arriving during the serial handshake must stay a scan, whole.
for (const [name, payload] of [['AAMVA', AAMVA], ['MRZ', MRZ]]) {
    assert.ok(chunks(payload).length > 1, name + ' must span several notifications');

    const before = perChunk(payload);
    assert.notStrictEqual(before.serial, null, name + ': expected the old behaviour to eat a chunk as the serial');
    assert.notStrictEqual(before.scan, payload.trim(), name + ': expected the old behaviour to truncate the scan');

    const after = whole(payload);
    assert.strictEqual(after.serial, null, name + ': no part of a scan may be taken as the serial');
    assert.strictEqual(after.scan, payload.trim(), name + ': the scan must arrive intact');
    assert.strictEqual(support.extractIdPayload(after.scan) === null, false, name + ': the scan must still be recognised');
}

// A genuine short command response is still read as the serial.
const serialResult = whole(SERIAL + '\r\n');
assert.strictEqual(serialResult.serial, SERIAL);
assert.strictEqual(serialResult.scan, null);

console.log('tera-notify-framing: ' + (2 * 6 + 2) + ' checks passed');
