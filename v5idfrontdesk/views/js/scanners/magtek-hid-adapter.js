/**
 * Scanner adapter: MagTek barcode/PDF417 scanners over WebHID (MagTek's own
 * MMS_HID protocol), rather than Web Bluetooth.
 *
 * Talks to the device through MagTek's own vendored SDK
 * (views/js/vendor/magtek/ — MIT-licensed, see that folder for the original
 * copyright) instead of reimplementing the HID report format by hand. Only
 * the SDK's public openDevice()/sendCommand()/closeDevice() surface is used,
 * and only its OnBarcodeDetected/OnBarcodeRead events are listened for —
 * the same sequence V5id's own reference client at
 * https://kiosk.v5id.net/magtek-scan-v3.html uses for pairing and scanning.
 * That page's own standalone-kiosk validation flow (posting the decoded
 * barcode straight to an API using its own "Integration Key") is NOT reused
 * here — this adapter never talks to any API itself. A decoded scan is just
 * handed to the registry caller's onScan callback, same as every other
 * adapter, and validated through the existing server-side
 * ajaxProcessScanValidate flow using this module's own device credentials.
 *
 * KNOWN LIMITATION — only one MagTek device at a time: the vendored SDK
 * correlates a sent command with its response via a single global
 * `window.mt_device_response` variable (see
 * vendor/magtek/device/API_device_abstract.js and
 * vendor/magtek/API_mmsParse.js), not anything scoped per device instance,
 * and its barcode events go through one shared `window.EventEmitter`. Two
 * MMSHIDDevice instances with commands in flight at the same time could
 * have their responses cross-delivered to the wrong one. That's a
 * limitation in MagTek's own SDK, not something this adapter's contract can
 * paper over without patching vendored code — so connect() below refuses a
 * second simultaneous MagTek connection with a clear error instead of
 * letting that happen silently. It does not affect running one MagTek
 * device alongside a different protocol (e.g. one MagTek + one Inateck) at
 * the same time.
 */
(function (window, document, navigator) {
    'use strict';

    // Dynamic import() from a classic (non-module) script resolves a
    // relative specifier against the *page's* URL, not this script's own —
    // so a plain '../vendor/magtek/...' string here would resolve wrong
    // depending on which page loaded this file. document.currentScript is
    // only valid during this script's own synchronous top-level execution
    // (it's null inside any later callback/promise), so the absolute vendor
    // base has to be captured right here, before anything async happens.
    var VENDOR_BASE = (function () {
        var scriptEl = document.currentScript;
        var scriptUrl = scriptEl ? scriptEl.src : '';
        var withoutQuery = scriptUrl.split('?')[0];
        var scannersDir = withoutQuery.substring(0, withoutQuery.lastIndexOf('/') + 1);
        return scannersDir + '../vendor/magtek/';
    })();

    // Hex commands, verbatim from the reference client — this is MagTek's
    // own MMS command protocol, not something derivable from first principles.
    var CMD_GET_SERIAL = 'AA00810401B5D1018418D10181072B06010401F6098501028704020101018902C100';
    var CMD_PDF417_ONLY = 'AA0081040155D1118413D1118501018704020701058906C10400004000';
    var CMD_TRIGGER_SCAN = 'AA0081040155D1118413D1118501018704020701028906C10483000000';
    var CMD_CANCEL_SCAN = 'AA0081040113100884021008';

    /** How long a triggered scan window stays armed before auto-cancelling and re-arming — matches the reference client. */
    var SCAN_WINDOW_MS = 180000;
    /** Gap between a completed scan and re-arming for the next one. */
    var RETRIGGER_DELAY_MS = 300;

    var MagTekHIDDevice = null;
    var loadPromise = null;

    /** Enforces the single-device limitation documented above. */
    var connectedInstance = null;

    function loadSdk() {
        if (!loadPromise) {
            loadPromise = import(VENDOR_BASE + 'device/API_device_mmsHID.js').then(function (mod) {
                MagTekHIDDevice = mod.default;
            });
        }
        return loadPromise;
    }

    /**
     * Same hex-decode + AAMVA '@'...'ANSI' extraction as the reference
     * client (and as inateck-ble-adapter.js's own processBarcode()) —
     * MagTek's HID reports can carry the payload as literal ASCII or as a
     * hex-encoded string depending on device mode.
     *
     * @param {string} rawData
     * @return {?string} The extracted AAMVA/MRZ text, or null if this event
     *   wasn't actually a completed ID scan (e.g. a partial read, or some
     *   other barcode entirely) — nothing to do with it in that case.
     */
    function extractBarcode(rawData) {
        if (!rawData || rawData.length < 50) {
            return null;
        }

        var barcodeText = rawData;
        if (/^[0-9A-Fa-f]+$/.test(rawData) && rawData.length % 2 === 0) {
            try {
                var decoded = '';
                for (var i = 0; i < rawData.length; i += 2) {
                    decoded += String.fromCharCode(parseInt(rawData.substr(i, 2), 16));
                }
                barcodeText = decoded;
            } catch (err) {
                /* not actually hex — use as-is */
            }
        }

        var ansiIdx = barcodeText.indexOf('ANSI');
        if (ansiIdx < 0) {
            return null;
        }
        var startIdx = ansiIdx;
        for (var j = ansiIdx - 1; j >= Math.max(0, ansiIdx - 20); j--) {
            if (barcodeText[j] === '@') {
                startIdx = j;
                break;
            }
        }

        return barcodeText.substring(startIdx);
    }

    function createInstance() {
        var device = null;
        var rescanTimer = null;
        var scanSeq = 0;
        var userDisconnected = false;

        var onScan = null;
        var onStatusChange = null;
        var onError = null;

        function setStatus(status) {
            if (typeof onStatusChange === 'function') {
                onStatusChange(status);
            }
        }

        function reportError(message) {
            if (typeof onError === 'function') {
                onError(message);
            }
        }

        /** Re-arms the device to stay "ready" — cancels/replaces any window already in flight. */
        function armScan() {
            if (!device || userDisconnected) {
                return;
            }
            var mySeq = ++scanSeq;

            device.sendCommand(CMD_TRIGGER_SCAN).catch(function () {
                if (mySeq !== scanSeq || userDisconnected) {
                    return;
                }
                setStatus('error');
                reportError('Lost contact with the scanner. Disconnect and reconnect it.');
            });

            clearTimeout(rescanTimer);
            rescanTimer = setTimeout(function () {
                if (mySeq !== scanSeq || userDisconnected) {
                    return;
                }
                // The window elapsed with no valid ID scan — cancel and
                // re-arm so the device never sits outside "ready" for good.
                device.sendCommand(CMD_CANCEL_SCAN).catch(function () {});
                armScan();
            }, SCAN_WINDOW_MS);
        }

        function onBarcodeEvent(e) {
            var extracted = e && e.Data ? extractBarcode(e.Data) : null;
            if (extracted === null) {
                // Not a completed ID scan (partial read, some other
                // barcode, ...) — leave the current window running rather
                // than restart it, same as the reference client.
                return;
            }

            if (typeof onScan === 'function') {
                onScan(extracted);
            }

            clearTimeout(rescanTimer);
            rescanTimer = setTimeout(armScan, RETRIGGER_DELAY_MS);
        }

        return {
            /**
             * Opens the browser's WebHID device chooser (must be called
             * from a real click handler) and connects to the selected
             * scanner.
             *
             * @param {{onScan: function(string), onStatusChange: function(string), onError: function(string)}} callbacks
             * @return {Promise<{serial: ?string}>}
             */
            connect: async function (callbacks) {
                callbacks = callbacks || {};
                onScan = callbacks.onScan || null;
                onStatusChange = callbacks.onStatusChange || null;
                onError = callbacks.onError || null;

                if (!('hid' in navigator)) {
                    reportError('This browser does not support WebHID. Use Chrome or Edge on desktop.');
                    throw new Error('WebHID not supported');
                }

                if (connectedInstance && connectedInstance !== this) {
                    reportError('Only one MagTek scanner can be connected at a time (a limitation of MagTek’s own SDK) — disconnect the other one first.');
                    throw new Error('Another MagTek device is already connected');
                }

                try {
                    userDisconnected = false;
                    setStatus('connecting');

                    await loadSdk();
                    device = new MagTekHIDDevice();

                    var hidDevice = await device.openDevice();
                    if (!hidDevice) {
                        setStatus('disconnected');
                        throw new Error('No HID device selected or found.');
                    }

                    connectedInstance = this;

                    // The device needs a beat after opening before it
                    // reliably answers commands — matches the reference client.
                    await new Promise(function (r) { setTimeout(r, 300); });

                    var serial = null;
                    try {
                        var resp = await device.sendCommand(CMD_GET_SERIAL);
                        if (resp && resp.HexString) {
                            serial = resp.HexString.slice(-8, -1);
                        }
                    } catch (e) {
                        /* serial is best-effort — a device without one can still scan */
                    }

                    try {
                        await device.sendCommand(CMD_PDF417_ONLY);
                    } catch (e) {
                        /* non-fatal — device stays usable, just without the PDF417-only filter */
                    }

                    window.EventEmitter.on('OnBarcodeDetected', onBarcodeEvent);
                    window.EventEmitter.on('OnBarcodeRead', onBarcodeEvent);

                    setStatus('connected');
                    armScan();

                    return { serial: serial };
                } catch (e) {
                    var cancelled = e && (e.name === 'NotFoundError' || /no hid device selected/i.test(e.message || ''));
                    setStatus(cancelled ? 'disconnected' : 'error');
                    if (!cancelled) {
                        reportError(e && e.message ? e.message : 'MagTek connection failed.');
                    }
                    throw e;
                }
            },

            disconnect: function () {
                userDisconnected = true;
                clearTimeout(rescanTimer);
                scanSeq++;

                if (window.EventEmitter) {
                    window.EventEmitter.off('OnBarcodeDetected', onBarcodeEvent);
                    window.EventEmitter.off('OnBarcodeRead', onBarcodeEvent);
                }

                if (connectedInstance === this) {
                    connectedInstance = null;
                }

                if (device) {
                    device.closeDevice().catch(function () { /* closing anyway */ });
                }
                device = null;
                setStatus('disconnected');
            },
        };
    }

    window.V5idScannerRegistry.register({
        id: 'magtek-hid',
        label: 'MagTek Barcode Scanner (HID)',
        isSupported: function () {
            return 'hid' in navigator;
        },
        createInstance: createInstance,
    });
})(window, document, navigator);
