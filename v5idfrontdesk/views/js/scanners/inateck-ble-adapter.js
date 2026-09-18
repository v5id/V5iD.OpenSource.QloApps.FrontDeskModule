/**
 * Scanner adapter: Inateck BLE barcode scanners (e.g. Nano 260D / N6015),
 * paired directly via `navigator.bluetooth` rather than the OS keyboard-HID
 * layer.
 *
 * This talks to the scanner over its native BLE protocol and receives raw
 * decoded bytes as GATT notifications — there is no OS keyboard emulation
 * involved, so none of the Alt+Numpad control-character composition issues
 * that affect the HID keyboard-wedge path (see scanner-listener.js) apply
 * here. The trade-off is Web Bluetooth's own constraints: it needs a secure
 * context (HTTPS, or localhost for dev), only Chromium-based browsers
 * support it, and pairing must be started from a real user click —
 * `requestDevice()` cannot be triggered ambiently.
 *
 * Protocol/GATT layout adapted from V5id's own reference client at
 * https://dev.kiosk.v5id.dev/inateck-scan-v1.html — only the BLE framing
 * and connection-management logic is reused here. This adapter does not
 * talk to the V5iD API directly and never sees the device secret: a
 * decoded scan is just handed to the registry caller's `onScan` callback,
 * which the app wires into the same server-side ajaxProcessScanValidate
 * flow used by the keyboard-wedge path.
 *
 * This is one adapter among potentially many (see registry.js) — the goal
 * is that adding support for a different scanner brand/protocol later
 * means writing a new self-contained file like this one, registering under
 * its own id, without touching this file or the app shell.
 */
(function (window, navigator) {
    'use strict';

    var support = window.V5idScannerSupport;

    // ── BLE constants (Inateck protocol) ────────────────────────────────
    var SVC = '0000ff00-0000-1000-8000-00805f9b34fb';
    var N_CHR = '0000ff01-0000-1000-8000-00805f9b34fb'; // notify — scan data + read responses
    var W_CHR = '0000ff04-0000-1000-8000-00805f9b34fb'; // write without response — commands
    var A_CHR = '0000ff05-0000-1000-8000-00805f9b34fb'; // auth write
    var FFE_SVC = '0000ffe0-0000-1000-8000-00805f9b34fb'; // generic BLE-UART (GATT-mode scan data)
    var FFE1_CHR = '0000ffe1-0000-1000-8000-00805f9b34fb';
    var MAC_ADDR = [0x7f, 0x7b];

    var OPTIONAL_SERVICES = [
        SVC,
        '0000180f-0000-1000-8000-00805f9b34fb', // battery
        FFE_SVC,
        '0000ae00-0000-1000-8000-00805f9b34fb',
        '000018f0-0000-1000-8000-00805f9b34fb',
        '0000180a-0000-1000-8000-00805f9b34fb',
        N_CHR,
        '0000ff02-0000-1000-8000-00805f9b34fb',
        '0000ff03-0000-1000-8000-00805f9b34fb',
        W_CHR,
        A_CHR,
        FFE1_CHR,
    ];

    /** BLE MTU payload for one write. */
    var WRITE_CHUNK_BYTES = 20;

    function checkSum(arr) {
        return arr.reduce(function (s, b) { return s + b; }, 0) % 256;
    }

    function createAdapter() {
        var device = null;
        var server = null;
        var writeChr = null;
        var authChr = null;
        var notifyChr = null;
        var readAddrData = [];
        var notifyBuffer = new Uint8Array(0);

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

        async function writeChunked(chr, bytes) {
            var props = chr.properties;
            var useWithResponse = props && props.write && !props.writeWithoutResponse;
            for (var i = 0; i < bytes.length; i += WRITE_CHUNK_BYTES) {
                var p = bytes.slice(i, i + WRITE_CHUNK_BYTES);
                var dv = new DataView(new ArrayBuffer(p.length));
                p.forEach(function (b, idx) { dv.setUint8(idx, b); });
                if (useWithResponse) {
                    await chr.writeValue(dv);
                } else {
                    await chr.writeValueWithoutResponse(dv);
                }
            }
        }

        function waitForData(getter, timeoutMs) {
            return new Promise(function (resolve, reject) {
                var start = Date.now();
                (function poll() {
                    var d = getter();
                    if (d.length > 0) {
                        return resolve(d);
                    }
                    if (Date.now() - start > timeoutMs) {
                        return reject(new Error('Timeout'));
                    }
                    setTimeout(poll, 50);
                })();
            });
        }

        async function getAddrInfo(addr, continuousLength) {
            readAddrData = [];
            var msg = [0xf2];
            msg.push(addr.length + (continuousLength > 0 ? 1 : 0));
            addr.forEach(function (b) { msg.push(b); });
            if (continuousLength > 0) {
                msg.push(continuousLength);
            }
            msg.push(checkSum(msg));
            await writeChunked(writeChr, msg);
            return waitForData(function () { return readAddrData; }, 3000);
        }

        function parseNotifyData(incoming) {
            var combined = new Uint8Array(notifyBuffer.length + incoming.length);
            combined.set(notifyBuffer);
            combined.set(incoming, notifyBuffer.length);
            notifyBuffer = combined;

            if (notifyBuffer.length > 0) {
                var h = notifyBuffer[0];
                if (h === 0xf1 || h === 0xf3) {
                    notifyBuffer = new Uint8Array(0);
                    return null;
                }
                if (h === 0xf2) {
                    readAddrData = Array.from(notifyBuffer);
                    notifyBuffer = new Uint8Array(0);
                    return null;
                }
            }

            if (notifyBuffer.length < 2) {
                return null;
            }
            var h0 = notifyBuffer[0];
            if (h0 < 0xc1 || h0 > 0xc9) {
                notifyBuffer = new Uint8Array(0);
                return null;
            }

            var pktLen = (h0 - 0xc1) * 256 + notifyBuffer[1];
            var total = pktLen + 3;
            if (notifyBuffer.length < total) {
                return null;
            }

            var frame = Array.from(notifyBuffer.slice(0, total));
            var lastByte = frame[total - 1];
            if (lastByte !== checkSum(frame.slice(0, total - 1))) {
                notifyBuffer = notifyBuffer.slice(total);
                return null;
            }
            var barcodeBytes = frame.slice(2, pktLen + 2);
            var barcode = new TextDecoder('utf-8', { fatal: false }).decode(new Uint8Array(barcodeBytes));
            notifyBuffer = notifyBuffer.slice(total);
            return barcode;
        }

        async function doAuth() {
            // The authentication frame carries three empty credential fields,
            // so the payload is the two separators and nothing else.
            var payload = [0x00, 0x00];
            var strLength = payload.length;
            var msg = [0xf1];
            if (strLength < 255) {
                msg.push(0, strLength);
            } else {
                var hex = strLength.toString(16).padStart(4, '0');
                msg.push(parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(-2), 16));
            }
            msg = msg.concat(payload);
            msg.push(checkSum(msg));
            await writeChunked(authChr, msg);
            await new Promise(function (r) { setTimeout(r, 500); });
        }

        async function getStableDeviceId() {
            if (!writeChr) {
                return null;
            }
            try {
                var data = await getAddrInfo(MAC_ADDR, 6);
                var raw6 = data.slice(4, 10);
                var stable5 = raw6.slice(0, 5);
                return Array.from(stable5).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
            } catch (e) {
                return null;
            }
        }

        async function setupServices() {
            var svc = await server.getPrimaryService(SVC);
            notifyChr = await svc.getCharacteristic(N_CHR);
            writeChr = await svc.getCharacteristic(W_CHR);
            authChr = await svc.getCharacteristic(A_CHR);

            await notifyChr.startNotifications();
            notifyChr.addEventListener('characteristicvaluechanged', onNotify);

            await doAuth();

            // Some pairing modes also expose a generic BLE-UART channel —
            // not present when the device is in a different BLE mode, which is fine.
            try {
                var ffeSvc = await server.getPrimaryService(FFE_SVC);
                var ffe1Chr = await ffeSvc.getCharacteristic(FFE1_CHR);
                await ffe1Chr.startNotifications();
                ffe1Chr.addEventListener('characteristicvaluechanged', onFfe1Notify);
            } catch (e) {
                /* ffe0/ffe1 not exposed in this BLE mode — fine */
            }
        }

        function onNotify(event) {
            var data = new Uint8Array(event.target.value.buffer);
            var barcode = parseNotifyData(data);
            if (barcode !== null) {
                processBarcode(barcode);
            }
        }

        function onFfe1Notify(event) {
            var text = new TextDecoder('utf-8', { fatal: false }).decode(event.target.value).trim();
            if (text.length > 0) {
                processBarcode(text);
            }
        }

        function processBarcode(bcData) {
            if (!bcData || bcData.length < 10) {
                return;
            }

            var barcodeText = bcData;

            // Hex-encoded payloads decode to the real text first.
            if (/^[0-9A-Fa-f]+$/.test(bcData) && bcData.length % 2 === 0) {
                var decoded = '';
                for (var i = 0; i < bcData.length; i += 2) {
                    decoded += String.fromCharCode(parseInt(bcData.substr(i, 2), 16));
                }
                barcodeText = decoded;
            }

            var payload = support.extractIdPayload(barcodeText);
            if (payload === null) {
                return;
            }

            if (typeof onScan === 'function') {
                onScan(payload);
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
                server = await support.connectGatt(device, 2);
                await setupServices();
                stopReconnect();
                setStatus('connected');
            } catch (e) {
                // Deliberately unbounded: these units sleep when idle and
                // must come back on their own when they wake, so a ceiling
                // would strand a scanner that was simply left alone.
                scheduleReconnect(3000);
            }
        }

        function handleDisconnect() {
            writeChr = null;
            authChr = null;
            notifyChr = null;
            server = null;
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

                    server = await support.connectGatt(device);
                    await setupServices();

                    var serial = await getStableDeviceId();
                    setStatus('connected');

                    return { serial: serial };
                } catch (e) {
                    setStatus(e && e.name === 'NotFoundError' ? 'disconnected' : 'error');
                    if (!(e && e.name === 'NotFoundError')) {
                        reportError(e && e.message ? e.message : 'Bluetooth connection failed.');
                    }
                    throw e;
                }
            },

            disconnect: function () {
                userDisconnected = true;
                stopReconnect();
                writeChr = null;
                authChr = null;
                notifyChr = null;
                server = null;
                if (device && device.gatt && device.gatt.connected) {
                    device.gatt.disconnect();
                }
                device = null;
                setStatus('disconnected');
            },
        };
    }

    window.V5idScannerRegistry.register({
        id: 'inateck-ble',
        label: 'Inateck Bluetooth Scanner (BLE)',
        isSupported: function () {
            return !!navigator.bluetooth;
        },
        // A fresh call per physical scanner — see registry.js. createAdapter()
        // already builds all its state (device/server/GATT handles) inside
        // its own closure, so it was a factory in everything but name.
        createInstance: createAdapter,
    });
})(window, navigator);
