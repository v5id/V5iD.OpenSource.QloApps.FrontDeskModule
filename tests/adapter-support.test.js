const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..', 'v5idfrontdesk', 'views', 'js', 'scanners');
const host = {};
new Function('window', fs.readFileSync(path.join(root, 'adapter-support.js'), 'utf8'))(host);
const support = host.V5idScannerSupport;

function bleOriginal(bcData) {
    if (!bcData) return null;
    let barcodeText = bcData.trim();
    if (barcodeText.length < 10) return null;
    const ansiIdx = barcodeText.indexOf('ANSI');
    if (ansiIdx < 0 && barcodeText.length < 50) return null;
    if (ansiIdx >= 0) {
        let startIdx = ansiIdx;
        for (let j = ansiIdx - 1; j >= Math.max(0, ansiIdx - 20); j--) {
            if (barcodeText[j] === '@') { startIdx = j; break; }
        }
        barcodeText = barcodeText.substring(startIdx);
    }
    return barcodeText;
}

function bleNew(bcData) {
    if (!bcData) return null;
    const barcodeText = bcData.trim();
    if (barcodeText.length < 10) return null;
    return support.extractIdPayload(barcodeText);
}

const AAMVA = '@\n\x1e\rANSI 636014040002DL00410288ZC03290015DLDCAPUBLIC-SAMPLE-PAYLOAD-0001';
const MRZ = 'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<L898902C36UTO7408122F1204159ZE184226B<<<<<10';

const cases = [
    AAMVA,
    AAMVA.replace('@\n\x1e\r', ''),
    'GARBAGE-PREFIX@\n\x1e\rANSI 636014040002DL0041 more payload text here to pass fifty',
    'X'.repeat(30) + 'ANSI 6360140400',
    MRZ,
    'short',
    'exactly-ten',
    'A'.repeat(49),
    'A'.repeat(50),
    '',
    '   ' + AAMVA + '  ',
];

for (const raw of cases) {
    assert.strictEqual(bleNew(raw), bleOriginal(raw), 'BLE mismatch for ' + JSON.stringify(raw.slice(0, 40)));
}

assert.strictEqual(support.extractIdPayload('@' + 'x'.repeat(25) + 'ANSI rest'), 'ANSI rest');
assert.strictEqual(support.extractIdPayload('@' + 'x'.repeat(5) + 'ANSI rest'), '@' + 'x'.repeat(5) + 'ANSI rest');

assert.strictEqual(support.extractIdPayload(MRZ), MRZ);
assert.strictEqual(support.extractIdPayload('A'.repeat(49)), null);

assert.strictEqual(support.looksLikeIdScan(AAMVA), true);
assert.strictEqual(support.looksLikeIdScan(MRZ), true);
assert.strictEqual(support.looksLikeIdScan('SN12345678'), false);
assert.strictEqual(support.looksLikeIdScan('^&C11&^ABC123'), false);

console.log('adapter-support: ' + (cases.length + 8) + ' checks passed');
