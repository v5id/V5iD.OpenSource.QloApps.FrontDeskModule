/**
 * Scanner Manager tab — the one tab meant to be kept open for the length of
 * a shift. It holds the actual explicit-pairing connections (Bluetooth GATT,
 * etc. — see views/js/scanners/registry.js and one adapter file per
 * protocol) and forwards every scan/status/error over
 * views/js/scanner-channel.js so Front Desk board tabs (frontdesk-app.js)
 * receive scans no matter what page they're on, since those connections
 * don't survive navigation within the tab that opened them.
 *
 * Deliberately framework-free (no Vue): this is a small, self-contained
 * control panel, and keeping it free of the board's app shell means a
 * problem loading/rendering the board can never take this tab down with it.
 */
(function (window, document) {
    'use strict';

    var channel = window.V5idScannerChannel;
    var registry = window.V5idScannerRegistry;

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

    /** @param {object} adapter One entry from registry.available(). */
    function buildRow(adapter, root) {
        var status = 'disconnected';

        var row = el('div', 'v5sm-row');
        var info = el('div', 'v5sm-row-info');
        info.appendChild(el('strong', null, adapter.label));
        var badge = el('span', 'v5sm-badge is-disconnected', STATUS_LABEL.disconnected);
        info.appendChild(badge);
        row.appendChild(info);

        var btn = el('button', 'v5sm-btn', 'Connect');
        row.appendChild(btn);

        var log = el('div', 'v5sm-log');
        row.appendChild(log);

        function logLine(text) {
            var line = el('div', 'v5sm-log-line', text);
            log.insertBefore(line, log.firstChild);
            while (log.childNodes.length > MAX_LOG_LINES) {
                log.removeChild(log.lastChild);
            }
        }

        function setStatus(next) {
            status = next;
            badge.textContent = STATUS_LABEL[status] || status;
            badge.className = 'v5sm-badge is-' + status;
            btn.textContent = (status === 'connected' || status === 'reconnecting') ? 'Disconnect' : 'Connect';
            btn.disabled = status === 'connecting';
            channel.send('status', { adapterId: adapter.id, status: status });
        }

        btn.addEventListener('click', function () {
            if (status === 'connected' || status === 'reconnecting') {
                adapter.disconnect();
                setStatus('disconnected');
                logLine('Disconnected.');
                return;
            }

            adapter.connect({
                onScan: function (raw) {
                    channel.send('scan', { adapterId: adapter.id, data: raw });
                    // Confirms a scan came through without echoing any of its
                    // decoded content (name, DOB, document number, ...) to
                    // the screen — this tab is for pairing/monitoring the
                    // device, not for reading what's on anyone's ID.
                    logLine('Scan received (' + raw.length + ' chars) at ' + new Date().toLocaleTimeString());
                },
                onStatusChange: setStatus,
                onError: function (message) {
                    channel.send('error', { adapterId: adapter.id, message: message });
                    logLine('Error: ' + message);
                },
            }).catch(function () {
                // Status/error already reported through the callbacks above
                // (e.g. the user cancelled the Bluetooth device chooser).
            });
        });

        root.appendChild(row);
        return {
            disconnect: function () { adapter.disconnect(); },
            // Lets boot()'s 'query-status' handler answer with what this row
            // actually shows right now, not just what it was at the moment
            // it last changed — a board tab that (re)loads after a status
            // change already happened would otherwise never learn it, since
            // BroadcastChannel only delivers messages to listeners that were
            // already attached when postMessage() ran.
            currentStatus: function () { return { adapterId: adapter.id, status: status }; },
        };
    }

    function boot() {
        var root = document.getElementById('v5idfrontdesk-scanner-manager');
        if (!root) {
            return;
        }

        if (!channel || !channel.isSupported()) {
            root.appendChild(el('div', 'v5sm-warning', 'This browser does not support BroadcastChannel, so scans from this tab cannot reach your Front Desk tabs. Use a recent Chrome, Firefox, Edge or Safari.'));
            return;
        }

        var adapters = registry ? registry.available() : [];
        if (!adapters.length) {
            root.appendChild(el('div', 'v5sm-warning', 'No scanner protocol is enabled for this property, or this browser doesn’t support the ones that are. Enable one under Front Desk settings.'));
            return;
        }

        var rows = adapters.map(function (adapter) {
            return buildRow(adapter, root);
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
