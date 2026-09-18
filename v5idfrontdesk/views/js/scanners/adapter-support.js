/**
 * The parts of a scanner adapter that are the same whichever unit is on the
 * other end, kept in one place so a correction lands once instead of in every
 * adapter file (see registry.js for what an adapter is).
 *
 * Deliberately limited to code that is byte-identical across the adapters and
 * free of per-device state: the GATT connect retry, and reading an ID payload
 * out of decoded scan text. Each adapter keeps its own connection lifecycle,
 * because those genuinely differ - Tera re-arms Immediate Mode on reconnect,
 * Inateck authenticates and falls back to a second notify channel, Marson
 * frames by a pause in traffic - and merging them would hide those
 * differences rather than remove them.
 *
 * Loaded by AdminV5idFrontDeskController::renderScannerManagerPage() ahead of
 * every adapter script.
 */
(function (window) {
    'use strict';

    /**
     * An AAMVA payload without the ANSI marker is still a scan if it is at
     * least this long; anything shorter is a stray read of some other barcode.
     */
    var MIN_UNANCHORED_LENGTH = 50;

    /** How far back from the ANSI marker the '@' compliance indicator may sit. */
    var COMPLIANCE_INDICATOR_LOOKBACK = 20;

    window.V5idScannerSupport = {
        /**
         * Connects the device's GATT server, retrying a link that drops
         * straight after connecting - which this hardware does often enough
         * that a single attempt is not reliable.
         *
         * @param {BluetoothDevice} device
         * @param {number} [maxAttempts]
         * @return {Promise<BluetoothRemoteGATTServer>}
         */
        connectGatt: async function (device, maxAttempts) {
            maxAttempts = maxAttempts || 4;
            for (var attempt = 1; attempt <= maxAttempts; attempt++) {
                try {
                    var server = await device.gatt.connect();
                    await new Promise(function (resolve) { setTimeout(resolve, 350); });
                    if (!device.gatt.connected) {
                        throw new Error('Link dropped immediately after connect');
                    }
                    return server;
                } catch (e) {
                    if (attempt === maxAttempts) {
                        throw e;
                    }
                    await new Promise(function (resolve) { setTimeout(resolve, 800 * attempt); });
                }
            }
        },

        /**
         * Anchors on the AAMVA/ANSI marker and backs up to the '@' compliance
         * indicator that starts the payload. A passport machine-readable zone
         * carries no marker, so a long enough payload passes through unchanged.
         *
         * @param {string} text Already-decoded scan text.
         * @return {?string} The payload, or null if this was not a completed ID scan.
         */
        extractIdPayload: function (text) {
            var ansiIdx = text.indexOf('ANSI');
            if (ansiIdx < 0) {
                return text.length >= MIN_UNANCHORED_LENGTH ? text : null;
            }

            var startIdx = ansiIdx;
            for (var j = ansiIdx - 1; j >= Math.max(0, ansiIdx - COMPLIANCE_INDICATOR_LOOKBACK); j--) {
                if (text[j] === '@') {
                    startIdx = j;
                    break;
                }
            }

            return text.substring(startIdx);
        },

        /**
         * Whether a notification payload is scan data rather than a vendor
         * command response - a scan performed during a serial handshake must
         * not be answered as the response.
         *
         * @param {string} text
         * @return {boolean}
         */
        looksLikeIdScan: function (text) {
            return text.indexOf('ANSI') !== -1 || text.trim().length >= MIN_UNANCHORED_LENGTH;
        },
    };
})(window);
