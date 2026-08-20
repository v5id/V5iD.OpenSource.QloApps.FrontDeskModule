/**
 * Scanner adapter: Marson MT810 barcode/PDF417 scanners over Web Bluetooth,
 * using the same vendor "Birch" custom GATT service V5id's own reference
 * client at https://test.kiosk.v5id.dev/marson-scan-v1.html uses for pairing
 * and scanning. Only the BLE framing and connection-management logic is
 * reused here, same as inateck-ble-adapter.js — this adapter does not talk
 * to the V5iD API directly and never sees the device secret: a decoded scan
 * is just handed to the registry caller's onScan callback, which the app
 * wires into the same server-side ajaxProcessScanValidate flow used by the
 * keyboard-wedge path.
 *
 * This unit's standard 1800/1801/180f/180a services are present but only
 * expose boilerplate (GAP name, battery level, PnP ID) — no hardware serial
 * number string (2a25), confirmed absent via live GATT discovery against the
 * reference client. The real scan-data path is the vendor Birch service
 * (feea/2aa1 notify/2aa2 write), and the serial has to be requested with a
 * vendor command instead — see requestSerialNumber().
 *
 * Framing is simpler than Inateck's: this unit streams raw ASCII in ~20-byte
 * BLE-MTU chunks with no length/checksum header at all, so a full payload
 * (scan or command response) is recognized purely by a pause in traffic —
 * see NOTIFY_DEBOUNCE_MS. There is also no separate "arm/trigger" command
 * like MagTek's SDK needs: once connected and subscribed, the device pushes
 * a scan whenever one happens.
 */
(function (window, navigator) {
    'use strict';

    // ── BLE constants (Marson / MT-810 — confirmed via live GATT discovery
    // against the reference client) ─────────────────────────────────────
    var SVC = '0000feea-0000-1000-8000-00805f9b34fb'; // vendor-custom (Birch)
    var N_CHR = '00002aa1-0000-1000-8000-00805f9b34fb'; // notify — scan data + command responses
    var W_CHR = '00002aa2-0000-1000-8000-00805f9b34fb'; // write — vendor raw commands
    var DEVINFO_SVC = '0000180a-0000-1000-8000-00805f9b34fb';
    var SERIAL_CHR = '00002a25-0000-1000-8000-00805f9b34fb'; // Serial Number String — not present on this unit, tried defensively
    var BATT_SVC = '0000180f-0000-1000-8000-00805f9b34fb';
    var GAP_SVC = '00001800-0000-1000-8000-00805f9b34fb';
    var CMD_GET_SERIAL = '^&C11&^'; // vendor command — writing this to W_CHR makes the serial come back over N_CHR

    var OPTIONAL_SERVICES = [SVC, DEVINFO_SVC, BATT_SVC, GAP_SVC];

    /** This device streams raw ASCII with no length/checksum header, so a full scan (or command response) is framed by a pause in traffic rather than a byte count. */
    var NOTIFY_DEBOUNCE_MS = 200;
    var SERIAL_TIMEOUT_MS = 3000;

    /** Strips vendor command-wrapper tokens like "^&C11&^"/"^&OK&^" that may be echoed around the payload, leaving just the serial value. */
    function parseSerialResponse(raw) {
        var stripped = raw.replace(/\^&[^&]*&\^/g, '').trim();
        return stripped.length > 0 ? stripped : raw.trim();
    }

    function createAdapter() {
        var device = null;
        var server = null;
        var notifyChr = null;
        var writeChr = null;
        var notifyBytes = [];
        var notifyFlushTimer = null;
        // When set, the next flushed notify payload is treated as a command
        // response (e.g. the serial-number request) rather than a barcode scan.
        var pendingSerialResolve = null;

        var userDisconnected = false;
        var reconnecting = false;
        var reconnectTimer = null;

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

        function waitForNotifyFlush(timeoutMs) {
            return new Promise(function (resolve, reject) {
                pendingSerialResolve = resolve;
                setTimeout(function () {
                    if (pendingSerialResolve === resolve) {
                        pendingSerialResolve = null;
                        reject(new Error('Timeout waiting for scanner response'));
                    }
                }, timeoutMs);
            });
        }

        async function writeCommand(chr, str) {
            var bytes = new TextEncoder().encode(str);
            var dv = new DataView(bytes.buffer);
            var props = chr.properties;
            if (props && props.write && !props.writeWithoutResponse) {
                await chr.writeValue(dv);
            } else {
                await chr.writeValueWithoutResponse(dv);
            }
        }

        async function requestSerialNumber() {
            if (!writeChr) {
                return null;
            }
            try {
                notifyBytes = [];
                clearTimeout(notifyFlushTimer);
                var responsePromise = waitForNotifyFlush(SERIAL_TIMEOUT_MS);
                await writeCommand(writeChr, CMD_GET_SERIAL);
                var raw = await responsePromise;
                return parseSerialResponse(raw) || null;
            } catch (e) {
                return null;
            }
        }

        async function getSerialNumber() {
            var fromCommand = await requestSerialNumber();
            if (fromCommand) {
                return fromCommand;
            }

            try {
                var svc = await server.getPrimaryService(DEVINFO_SVC);
                var chr = await svc.getCharacteristic(SERIAL_CHR);
                var val = await chr.readValue();
                var text = new TextDecoder('utf-8', { fatal: false }).decode(val).trim();
                if (text) {
                    return text;
                }
            } catch (e) {
                /* not exposed on this device — expected, fall through */
            }

            // Web Bluetooth's own per-origin device id, as a last resort — a
            // stable-enough fallback so pairing can still succeed even if
            // neither the vendor command nor 2a25 answered.
            return device && device.id ? device.id : null;
        }

        async function connectGatt(maxAttempts) {
            maxAttempts = maxAttempts || 4;
            for (var attempt = 1; attempt <= maxAttempts; attempt++) {
                try {
                    var srv = await device.gatt.connect();
                    await new Promise(function (r) { setTimeout(r, 350); });
                    if (!device.gatt.connected) {
                        throw new Error('Link dropped immediately after connect');
                    }
                    return srv;
                } catch (e) {
                    if (attempt === maxAttempts) {
                        throw e;
                    }
                    await new Promise(function (r) { setTimeout(r, 800 * attempt); });
                }
            }
        }

        async function setupServices() {
            var svc = await server.getPrimaryService(SVC);
            notifyChr = await svc.getCharacteristic(N_CHR);
            writeChr = await svc.getCharacteristic(W_CHR);
            await notifyChr.startNotifications();
            notifyChr.addEventListener('characteristicvaluechanged', onNotify);
        }

        function onNotify(event) {
            var data = new Uint8Array(event.target.value.buffer);
            for (var i = 0; i < data.length; i++) {
                notifyBytes.push(data[i]);
            }
            clearTimeout(notifyFlushTimer);
            notifyFlushTimer = setTimeout(flushNotifyBuffer, NOTIFY_DEBOUNCE_MS);
        }

        function flushNotifyBuffer() {
            if (notifyBytes.length === 0) {
                return;
            }
            var bytes = new Uint8Array(notifyBytes);
            notifyBytes = [];
            var text = new TextDecoder('utf-8', { fatal: false }).decode(bytes);

            if (pendingSerialResolve) {
                var resolve = pendingSerialResolve;
                pendingSerialResolve = null;
                resolve(text);
                return;
            }

            processBarcode(text);
        }

        // Same AAMVA/ANSI marker + '@' backtrack as inateck-ble-adapter.js
        // and magtek-hid-adapter.js's extractBarcode() — this unit doesn't
        // hex-encode its payload, so no hex-decode step is needed first.
        function processBarcode(bcData) {
            if (!bcData) {
                return;
            }
            var barcodeText = bcData.trim();
            if (barcodeText.length < 10) {
                return;
            }

            var ansiIdx = barcodeText.indexOf('ANSI');
            if (ansiIdx < 0 && barcodeText.length < 50) {
                return;
            }
            if (ansiIdx >= 0) {
                var startIdx = ansiIdx;
                for (var j = ansiIdx - 1; j >= Math.max(0, ansiIdx - 20); j--) {
                    if (barcodeText[j] === '@') {
                        startIdx = j;
                        break;
                    }
                }
                barcodeText = barcodeText.substring(startIdx);
            }

            if (typeof onScan === 'function') {
                onScan(barcodeText);
            }
        }

        function stopReconnect() {
            reconnecting = false;
            if (reconnectTimer) {
                clearTimeout(reconnectTimer);
                reconnectTimer = null;
            }
        }

        function scheduleReconnect(delay) {
            if (!reconnecting || userDisconnected) {
                return;
            }
            reconnectTimer = setTimeout(tryReconnect, delay || 3000);
        }

        function startReconnect() {
            if (reconnecting || userDisconnected || !device) {
                return;
            }
            reconnecting = true;
            setStatus('reconnecting');
            scheduleReconnect(1500);
        }

        async function tryReconnect() {
            if (!reconnecting || userDisconnected || !device) {
                return;
            }
            try {
                server = await connectGatt(2);
                await setupServices();
                stopReconnect();
                setStatus('connected');
            } catch (e) {
                scheduleReconnect(3000);
            }
        }

        function handleDisconnect() {
            notifyChr = null;
            writeChr = null;
            server = null;
            pendingSerialResolve = null;
            if (!userDisconnected) {
                startReconnect();
            } else {
                setStatus('disconnected');
            }
        }

        return {
            /**
             * Opens the browser's device chooser (must be called from a real
             * click handler) and connects to the selected scanner.
             *
             * @param {{onScan: function(string), onStatusChange: function(string), onError: function(string)}} callbacks
             * @return {Promise<{serial: ?string}>}
             */
            connect: async function (callbacks) {
                callbacks = callbacks || {};
                onScan = callbacks.onScan || null;
                onStatusChange = callbacks.onStatusChange || null;
                onError = callbacks.onError || null;

                if (!navigator.bluetooth) {
                    reportError('This browser does not support Web Bluetooth. Use Chrome or Edge on desktop.');
                    throw new Error('Web Bluetooth not supported');
                }

                try {
                    userDisconnected = false;
                    setStatus('connecting');

                    if (!device) {
                        device = await navigator.bluetooth.requestDevice({
                            acceptAllDevices: true,
                            optionalServices: OPTIONAL_SERVICES,
                        });
                        device.addEventListener('gattserverdisconnected', handleDisconnect);
                    }

                    server = await connectGatt();
                    await setupServices();

                    var serial = await getSerialNumber();
                    setStatus('connected');

                    return { serial: serial };
                } catch (e) {
                    setStatus(e && e.name === 'NotFoundError' ? 'disconnected' : 'error');
                    if (!(e && e.name === 'NotFoundError')) {
                        reportError(e && e.message ? e.message : 'Marson connection failed.');
                    }
                    throw e;
                }
            },

            disconnect: function () {
                userDisconnected = true;
                stopReconnect();
                notifyChr = null;
                writeChr = null;
                server = null;
                notifyBytes = [];
                clearTimeout(notifyFlushTimer);
                pendingSerialResolve = null;
                if (device && device.gatt && device.gatt.connected) {
                    device.gatt.disconnect();
                }
                device = null;
                setStatus('disconnected');
            },
        };
    }

    window.V5idScannerRegistry.register({
        id: 'marson-ble',
        label: 'Marson Bluetooth Scanner (MT810)',
        isSupported: function () {
            return !!navigator.bluetooth;
        },
        // A fresh call per physical scanner — see registry.js.
        createInstance: createAdapter,
    });
})(window, navigator);
