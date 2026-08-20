/**
 * Scanner Manager tab — the one tab meant to be kept open for the length of
 * a shift. It holds the actual explicit-pairing connections (Bluetooth GATT,
 * WebHID, etc. — see views/js/scanners/registry.js and one adapter file per
 * protocol) and forwards every scan/status/error over
 * views/js/scanner-channel.js so Front Desk board tabs (frontdesk-app.js)
 * receive scans no matter what page they're on, since those connections
 * don't survive navigation within the tab that opened them.
 *
 * Scoped to one hotel (window.v5idScannerManagerConfig.idHotel, set by
 * AdminV5idFrontDeskController::renderScannerManagerPage()) — a property can
 * have several physical scanners, of the same or different protocols,
 * connected at once, and a scanner paired at one property is never listed
 * or usable when this page is opened for a different one. Every AJAX call
 * below re-sends id_hotel and the server re-checks access on each one
 * (isHotelAccessible()) rather than trusting this page to have asked for
 * the right hotel in the first place.
 *
 * Deliberately framework-free (no Vue): this is a small, self-contained
 * control panel, and keeping it free of the board's app shell means a
 * problem loading/rendering the board can never take this tab down with it.
 */
(function (window, document) {
    'use strict';

    var registry = window.V5idScannerRegistry;
    var config = window.v5idScannerManagerConfig || {};
    // Scoped to this page's one hotel — see scanner-channel.js's docblock
    // for why a shared channel would leak scans/status across properties.
    var channel = (window.V5idScannerChannel && config.idHotel)
        ? window.V5idScannerChannel.create(config.idHotel)
        : null;

    var STATUS_LABEL = {
        disconnected: 'Disconnected',
        connecting: 'Connecting…',
        connected: 'Connected',
        reconnecting: 'Reconnecting…',
        error: 'Error',
    };

    var MAX_LOG_LINES = 5;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text != null) {
            node.textContent = text;
        }
        return node;
    }

    function api(action, params) {
        var body = new URLSearchParams(Object.assign({
            ajax: 1,
            action: action,
            token: config.token,
        }, params || {}));

        return fetch(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
        }).then(function (res) { return res.json(); });
    }

    function isProtocolUsable(protocol) {
        if (!protocol) {
            return false;
        }
        try {
            return !!protocol.isSupported();
        } catch (e) {
            return false;
        }
    }

    function makeLogger(container) {
        return function logLine(text) {
            var line = el('div', 'v5sm-log-line', text);
            container.insertBefore(line, container.firstChild);
            while (container.childNodes.length > MAX_LOG_LINES) {
                container.removeChild(container.lastChild);
            }
        };
    }

    /**
     * One row for an already-known, previously-paired device — id_hotel,
     * adapter_id and serial (see V5idFrontDeskScannerDevice) already
     * identify this exact physical unit, so connecting it doesn't need the
     * device chooser to reappear (browser support for reconnect-without-a-
     * prompt permitting — see the protocol's own isSupported()/connect()).
     *
     * @param {object} device Row from GetScannerDevices — {id, adapter_id, serial, label}.
     * @param {Element} root
     */
    function buildDeviceRow(device, root) {
        var protocol = registry.get(device.adapter_id);
        var usable = isProtocolUsable(protocol);
        var status = 'disconnected';
        var instance = null;

        var row = el('div', 'v5sm-row');
        var info = el('div', 'v5sm-row-info');
        var titleWrap = el('div', 'v5sm-row-title');
        titleWrap.appendChild(el('strong', null, device.label));
        titleWrap.appendChild(el('div', 'v5sm-row-sub', (protocol ? protocol.label : device.adapter_id) + ' · ' + device.serial));
        info.appendChild(titleWrap);
        var badge = el('span', 'v5sm-badge is-disconnected', STATUS_LABEL.disconnected);
        info.appendChild(badge);
        row.appendChild(info);

        var actions = el('div', 'v5sm-row-actions');
        var btn = el('button', 'v5sm-btn', 'Connect');
        actions.appendChild(btn);
        var removeBtn = el('button', 'v5sm-btn-ghost', 'Remove');
        actions.appendChild(removeBtn);
        row.appendChild(actions);

        var log = el('div', 'v5sm-log');
        row.appendChild(log);
        var logLine = makeLogger(log);

        function setStatus(next) {
            status = next;
            badge.textContent = STATUS_LABEL[status] || status;
            badge.className = 'v5sm-badge is-' + status;
            btn.textContent = (status === 'connected' || status === 'reconnecting') ? 'Disconnect' : 'Connect';
            btn.disabled = status === 'connecting';
            channel.send('status', { deviceId: device.id, adapterId: device.adapter_id, status: status });
        }

        if (!usable) {
            badge.textContent = 'Unavailable';
            badge.className = 'v5sm-badge is-error';
            btn.disabled = true;
            logLine(protocol
                ? 'This browser doesn’t support this protocol.'
                : 'This protocol isn’t enabled for this property anymore.');
        }

        btn.addEventListener('click', function () {
            if (status === 'connected' || status === 'reconnecting') {
                if (instance) {
                    instance.disconnect();
                }
                setStatus('disconnected');
                logLine('Disconnected.');
                return;
            }

            if (!protocol) {
                return;
            }

            instance = protocol.createInstance();
            instance.connect({
                onScan: function (raw) {
                    channel.send('scan', { deviceId: device.id, adapterId: device.adapter_id, data: raw });
                    // Confirms a scan came through without echoing any of its
                    // decoded content (name, DOB, document number, ...) to
                    // the screen — this tab is for pairing/monitoring
                    // devices, not for reading what's on anyone's ID.
                    logLine('Scan received (' + raw.length + ' chars) at ' + new Date().toLocaleTimeString());
                },
                onStatusChange: setStatus,
                onError: function (message) {
                    channel.send('error', { deviceId: device.id, adapterId: device.adapter_id, message: message });
                    logLine('Error: ' + message);
                },
            }).catch(function () {
                // Status/error already reported through the callbacks above
                // (e.g. the user cancelled the device chooser).
            });
        });

        removeBtn.addEventListener('click', function () {
            if (status === 'connected' || status === 'reconnecting') {
                if (instance) {
                    instance.disconnect();
                }
            }
            if (!window.confirm('Remove "' + device.label + '"? You can pair it again later.')) {
                return;
            }
            api('DeleteScannerDevice', { id_hotel: config.idHotel, id_device: device.id }).then(function (res) {
                if (res.success) {
                    row.remove();
                } else {
                    logLine(res.message || 'Could not remove this scanner.');
                }
            });
        });

        root.appendChild(row);

        return {
            disconnect: function () {
                if (instance) {
                    instance.disconnect();
                }
            },
            // Lets boot()'s 'query-status' handler answer with what this row
            // actually shows right now, not just what it was at the moment
            // it last changed — a board tab that (re)loads after a status
            // change already happened would otherwise never learn it, since
            // BroadcastChannel only delivers messages to listeners that were
            // already attached when postMessage() ran.
            currentStatus: function () { return { deviceId: device.id, adapterId: device.adapter_id, status: status }; },
        };
    }

    /**
     * The "pair a new scanner" panel: pick a protocol, connect it for real
     * (so the adapter can report the physical unit's serial — see each
     * adapter's connect()), then save it against this hotel. Only staff who
     * successfully complete a real pairing ever add a row — there's no way
     * to register a device without actually connecting to it once.
     *
     * @param {Element} root
     * @param {function(object[]):void} onPaired Called with the updated device list after a successful save.
     */
    function buildPairSection(root, onPaired) {
        var section = el('div', 'v5sm-pair');
        section.appendChild(el('h2', 'v5sm-pair-title', 'Pair a new scanner'));

        var available = registry.available();
        if (!available.length) {
            section.appendChild(el('p', 'v5sm-muted', 'No scanner protocol is enabled for this property, or this browser doesn’t support the ones that are. Enable one under Front Desk settings.'));
            root.appendChild(section);
            return;
        }

        var row = el('div', 'v5sm-pair-row');
        var select = document.createElement('select');
        select.className = 'v5sm-select';
        available.forEach(function (protocol) {
            var opt = document.createElement('option');
            opt.value = protocol.id;
            opt.textContent = protocol.label;
            select.appendChild(opt);
        });
        row.appendChild(select);

        var btn = el('button', 'v5sm-btn', 'Connect & pair');
        row.appendChild(btn);
        section.appendChild(row);

        var log = el('div', 'v5sm-log');
        section.appendChild(log);
        var logLine = makeLogger(log);

        btn.addEventListener('click', function () {
            var protocol = registry.get(select.value);
            if (!protocol) {
                return;
            }

            btn.disabled = true;
            select.disabled = true;

            var instance = protocol.createInstance();
            instance.connect({
                onScan: function () { /* ignored during pairing — staff just needs the connection to succeed */ },
                onStatusChange: function () { /* the pairing flow itself is the only feedback needed here */ },
                onError: function (message) {
                    logLine('Error: ' + message);
                },
            }).then(function (result) {
                btn.disabled = false;
                select.disabled = false;

                var serial = result && result.serial;
                if (!serial) {
                    logLine('Connected, but this device didn’t report a serial number — cannot pair it.');
                    instance.disconnect();
                    return;
                }

                var label = window.prompt('Label for this scanner (e.g. "Front Desk", "Back Office")', protocol.label);
                // Disconnect either way: a declined/blank label cancels the
                // pairing, and a saved device gets its own fresh connection
                // from its own row's Connect button rather than reusing
                // this temporary one.
                instance.disconnect();

                if (!label) {
                    return;
                }

                api('SaveScannerDevice', {
                    id_hotel: config.idHotel,
                    adapter_id: protocol.id,
                    serial: serial,
                    label: label,
                }).then(function (res) {
                    if (res.success) {
                        logLine('Paired as "' + label + '" — click Connect on it below to start using it.');
                        onPaired(res.devices);
                    } else {
                        logLine(res.message || 'Could not save this scanner.');
                    }
                });
            }).catch(function () {
                btn.disabled = false;
                select.disabled = false;
                // Status/error already reported through the callbacks above.
            });
        });

        root.appendChild(section);
    }

    function boot() {
        var root = document.getElementById('v5idfrontdesk-scanner-manager');
        if (!root) {
            return;
        }

        if (!config.idHotel) {
            root.appendChild(el('div', 'v5sm-warning', 'No property selected. Open this page from the Front Desk board’s "Open Scanner Manager" button rather than directly.'));
            return;
        }

        if (!channel || !channel.isSupported()) {
            root.appendChild(el('div', 'v5sm-warning', 'This browser does not support BroadcastChannel, so scans from this tab cannot reach your Front Desk tabs. Use a recent Chrome, Firefox, Edge or Safari.'));
            return;
        }

        var listRoot = el('div', 'v5sm-list-inner');
        root.appendChild(listRoot);

        var emptyNotice = el('p', 'v5sm-muted', 'No scanners paired yet for this property — pair one below.');

        var rows = [];
        var renderedIds = {};

        function addDeviceRow(device) {
            if (renderedIds[device.id]) {
                return;
            }
            renderedIds[device.id] = true;
            if (emptyNotice.parentNode) {
                emptyNotice.remove();
            }
            rows.push(buildDeviceRow(device, listRoot));
        }

        api('GetScannerDevices', { id_hotel: config.idHotel }).then(function (res) {
            if (!res.success) {
                root.appendChild(el('div', 'v5sm-warning', res.message || 'Could not load scanners for this property.'));
                return;
            }
            if (!res.devices.length) {
                listRoot.appendChild(emptyNotice);
            }
            res.devices.forEach(addDeviceRow);
        });

        buildPairSection(root, function (devices) {
            // Only the newly-paired device needs a row — everything
            // already shown (including any live connections) is untouched.
            devices.forEach(addDeviceRow);
        });

        // A board tab asks for this right after it (re)mounts, so it can
        // show the real current state immediately instead of guessing
        // "disconnected" until the next status change happens to fire.
        channel.on('query-status', function () {
            rows.forEach(function (row) {
                channel.send('status', row.currentStatus());
            });
        });

        channel.startHeartbeat();

        window.addEventListener('beforeunload', function () {
            rows.forEach(function (row) {
                try {
                    row.disconnect();
                } catch (e) {
                    /* tab is closing anyway */
                }
            });
            channel.close();
        });
    }

    document.addEventListener('DOMContentLoaded', boot);
})(window, document);
