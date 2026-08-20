/**
 * Scanner adapter: Tera HW0009 barcode/PDF417 scanners over Web Bluetooth,
 * using the same vendor GATT service V5id's own reference client at
 * https://beta.kiosk.v5id.dev/tera-scan-v1.html uses for pairing and
 * scanning. Only the BLE framing and connection-management logic is reused
 * here, same as inateck-ble-adapter.js and marson-ble-adapter.js — this
 * adapter does not talk to the V5iD API directly and never sees the device
 * secret: a decoded scan is just handed to the registry caller's onScan
 * callback, which the app wires into the same server-side
 * ajaxProcessScanValidate flow used by the keyboard-wedge path.
 *
 * This unit advertises the exact same vendor characteristic UUIDs as the
 * Marson MT810 (2aa1 notify / 2aa2 write under service feea) — evidently the
 * same underlying BLE module — but everything built on top of them differs:
 * pairing is done by BLE advertised name rather than accepting any device,
 * commands are raw binary bytes rather than ASCII strings, and the serial
 * number is fetched via a retry loop rather than a single request (the
 * device only answers a *second* trigger once it's ready — see
 * requestSerialNumber()). It also needs an explicit "Immediate Mode" command
 * after connecting so scans stream over BLE right away instead of buffering
 * on the device for later retrieval.
 *
 * NOT reused from the reference client: its buzzer feedback (playing a
 * success/age-fail/error tone on the device itself once a scan comes back
 * from the API). That would need this module's Scanner Manager to learn a
 * scan's validation outcome after the fact and route it back to the
 * specific device instance that produced it — a callback this adapter
 * contract doesn't have today (see registry.js) — so it's left for a later
 * change rather than half-wired here.
 */
(function (window, navigator) {
    'use strict';

    // ── BLE constants (Tera HW0009 — from the reference client) ─────────
    var BLE_NAME = 'BarCode Scanner BLE';
    var SVC = '0000feea-0000-1000-8000-00805f9b34fb'; // vendor-custom, same UUID family as the Marson MT810
    var N_CHR = '00002aa1-0000-1000-8000-00805f9b34fb'; // notify — scan data + command responses
    var W_CHR = '00002aa2-0000-1000-8000-00805f9b34fb'; // write — vendor raw commands
    var OPTIONAL_SERVICES = [
        '00001800-0000-1000-8000-00805f9b34fb',
        '00001801-0000-1000-8000-00805f9b34fb',
        '00001804-0000-1000-8000-00805f9b34fb',
        '0000180a-0000-1000-8000-00805f9b34fb',
        '0000180f-0000-1000-8000-00805f9b34fb',
        SVC,
    ];

    // Vendor command bytes, verbatim from the reference client.
    var CMD_GET_SERIAL = [0xba, 0x05, 0xba, 0x08, 0x03];
    var CMD_IMMEDIATE_MODE = [0xba, 0x05, 0x10]; // disables on-device storage so scans stream immediately

    /** Multi-line AAMVA/PDF417 payloads arrive as several notify events — a full payload is framed by this much silence, matching the reference client. */
    var NOTIFY_DEBOUNCE_MS = 350;
    /** Per-attempt timeout for one CMD_GET_SERIAL request/response round trip. */
    var SERIAL_ATTEMPT_TIMEOUT_MS = 6000;
    /** Fixed retry cadence once connected — matches the reference client's own indefinite "every 2s until it's back" reconnect loop, rather than escalating backoff. */
    var RECONNECT_INTERVAL_MS = 2000;

    function createAdapter() {
        var device = null;
        var server = null;
        var notifyChr = null;
        var writeChr = null;
        var rxBuffer = '';
        var flushTimer = null;
        // When set, the next non-empty notify payload is treated as a command
        // response (the serial-number request) rather than a barcode scan.
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

        /** Fire-and-forget, same as the reference client's sendBin() — a dropped command isn't worth failing the whole connection over. */
        async function sendCommand(bytes) {
            if (!writeChr) {
                return;
            }
            try {
                await writeChr.writeValue(new Uint8Array(bytes));
            } catch (e) {
                /* link likely dropped — handleDisconnect()/reconnect will pick it up */
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

        function isLinked() {
            return !!(device && device.gatt && device.gatt.connected);
        }

        /**
         * The device treats the first CMD_GET_SERIAL as a wake-up trigger and
         * only answers a later one once it's ready — matching the reference
         * client, this keeps re-sending it until a response arrives or the
         * link drops, rather than a single request/response.
         */
        async function requestSerialNumber() {
            while (writeChr && isLinked() && !userDisconnected) {
                try {
                    var responsePromise = waitForNotifyFlush(SERIAL_ATTEMPT_TIMEOUT_MS);
                    await sendCommand(CMD_GET_SERIAL);
                    var raw = await responsePromise;
                    if (raw) {
                        return raw;
                    }
                } catch (e) {
                    /* no answer this round — loop and retry while still linked */
                }
            }
            return null;
        }

        async function getSerialNumber() {
            var fromCommand = await requestSerialNumber();
            if (fromCommand) {
                return fromCommand;
            }

            // Not part of the reference client (which relies solely on the
            // vendor command), but consistent with this module's other
            // adapters: a stable-enough fallback so pairing can still
            // succeed if the vendor command never answers.
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
            var text = new TextDecoder('utf-8', { fatal: false }).decode(event.target.value);

            if (pendingSerialResolve) {
                var raw = text.replace(/[\r\n\x00]/g, '').trim();
                if (raw.length > 0) {
                    var resolve = pendingSerialResolve;
                    pendingSerialResolve = null;
                    resolve(raw);
                }
                return;
            }

            rxBuffer += text;
            clearTimeout(flushTimer);
            flushTimer = setTimeout(flushBuffer, NOTIFY_DEBOUNCE_MS);
        }

        function flushBuffer() {
            var payload = rxBuffer.trim();
            rxBuffer = '';
            if (payload.length > 0) {
                processBarcode(payload);
            }
        }

        // Same AAMVA/ANSI marker + '@' backtrack as the other adapters in
        // this module — a defensive filter this device's own reference
        // client doesn't need (it gates on a separate "validation screen"
        // state this module has no equivalent of), but harmless and
        // consistent here since we start listening for real scans as soon
        // as we're connected rather than after a second explicit step.
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
            reconnectTimer = setTimeout(tryReconnect, delay || RECONNECT_INTERVAL_MS);
        }

        function startReconnect() {
            if (reconnecting || userDisconnected || !device) {
                return;
            }
            reconnecting = true;
            setStatus('reconnecting');
            scheduleReconnect(RECONNECT_INTERVAL_MS);
        }

        async function tryReconnect() {
            if (!reconnecting || userDisconnected || !device) {
                return;
            }
            try {
                server = await connectGatt(2);
                await setupServices();
                // Re-arm immediate mode, matching the reference client's own
                // reconnect path (its initScanner() runs again there too).
                await sendCommand(CMD_IMMEDIATE_MODE);
                stopReconnect();
                setStatus('connected');
            } catch (e) {
                scheduleReconnect(RECONNECT_INTERVAL_MS);
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
             * Opens the browser's device chooser, filtered to this device's
             * advertised BLE name (must be called from a real click
             * handler), and connects to it.
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
                            filters: [{ name: BLE_NAME }],
                            optionalServices: OPTIONAL_SERVICES,
                        });
                        device.addEventListener('gattserverdisconnected', handleDisconnect);
                    }

                    server = await connectGatt();
                    await setupServices();

                    // Serial before Immediate Mode, same order as the
                    // reference client — so the mode-switch command doesn't
                    // block the serial-request loop above it.
                    var serial = await getSerialNumber();
                    await sendCommand(CMD_IMMEDIATE_MODE);

                    setStatus('connected');

                    return { serial: serial };
                } catch (e) {
                    setStatus(e && e.name === 'NotFoundError' ? 'disconnected' : 'error');
                    if (!(e && e.name === 'NotFoundError')) {
                        reportError(e && e.message ? e.message : 'Tera connection failed.');
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
                rxBuffer = '';
                clearTimeout(flushTimer);
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
        id: 'tera-ble',
        label: 'Tera Bluetooth Scanner (HW0009)',
        isSupported: function () {
            return !!navigator.bluetooth;
        },
        // A fresh call per physical scanner — see registry.js.
        createInstance: createAdapter,
    });
})(window, navigator);
