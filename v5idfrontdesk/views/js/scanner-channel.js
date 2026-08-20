/**
 * Cross-tab transport for scan data, shared by the Scanner Manager tab
 * (views/js/scanner-manager-app.js — the only tab that ever holds a live
 * Bluetooth/HID connection) and every Front Desk board tab
 * (views/js/frontdesk-app.js), which just listens.
 *
 * Every channel is scoped to one hotel — call create(idHotel), never a
 * shared singleton. Scanners are hard-scoped per hotel (see
 * V5idFrontDeskScannerDevice): a board tab for Property A must never
 * receive a scan, status, or "is a manager open" signal that actually came
 * from Property B's Scanner Manager tab, even when both happen to be open
 * in the same browser at once (a real scenario for anyone administering
 * more than one property). A single shared channel name/heartbeat key
 * would leak exactly that — handleScan() has no way to know a received
 * scan came from a different property's device, and would validate it
 * against whatever hotel the board tab currently has selected.
 *
 * Two mechanisms per channel, both same-origin/same-browser only:
 *
 * - BroadcastChannel carries the actual events (scan/status/error), tagged
 *   with the id of the adapter that produced them (see scanners/registry.js)
 *   so a board tab can tell which physical scanner a message came from. It
 *   also carries a 'query-status' request with no payload, which a board tab
 *   sends right after it (re)mounts so the manager can reply with a 'status'
 *   message per device — otherwise a tab that starts listening only after a
 *   status change already happened would never learn about it.
 * - A localStorage timestamp, refreshed every HEARTBEAT_INTERVAL_MS by the
 *   Scanner Manager tab, lets board tabs answer "is a manager tab even open
 *   right now, for this hotel?" without needing a live BroadcastChannel
 *   round trip — useful for showing an "Open Scanner Manager" prompt before
 *   any adapter has reported status.
 */
(function (window) {
    'use strict';

    var CHANNEL_NAME_PREFIX = 'v5idfrontdesk_scanner_v1_';
    var HEARTBEAT_KEY_PREFIX = 'v5idfrontdesk_scanner_manager_heartbeat_';
    var HEARTBEAT_INTERVAL_MS = 2000;
    var HEARTBEAT_STALE_MS = 5000;

    /** @param {number|string} idHotel */
    function createChannel(idHotel) {
        var channelName = CHANNEL_NAME_PREFIX + idHotel;
        var heartbeatKey = HEARTBEAT_KEY_PREFIX + idHotel;
        var channel = ('BroadcastChannel' in window) ? new BroadcastChannel(channelName) : null;
        var handlers = {};
        var heartbeatTimer = null;

        if (channel) {
            channel.onmessage = function (event) {
                var msg = event.data || {};
                var list = handlers[msg.type];
                if (!list) {
                    return;
                }
                list.forEach(function (fn) {
                    fn(msg.payload, msg);
                });
            };
        }

        return {
            /** @return {boolean} False in browsers without BroadcastChannel (no IE). */
            isSupported: function () {
                return !!channel;
            },

            /**
             * @param {string} type 'scan' | 'status' | 'error'
             * @param {object} payload
             */
            send: function (type, payload) {
                if (!channel) {
                    return;
                }
                channel.postMessage({ type: type, payload: payload, ts: Date.now() });
            },

            /**
             * @param {string} type
             * @param {function(object):void} fn
             */
            on: function (type, fn) {
                (handlers[type] = handlers[type] || []).push(fn);
            },

            close: function () {
                if (channel) {
                    channel.close();
                }
                this.stopHeartbeat();
            },

            /** Scanner Manager tab only: marks it alive for isManagerAlive(). */
            startHeartbeat: function () {
                var beat = function () {
                    try {
                        window.localStorage.setItem(heartbeatKey, String(Date.now()));
                    } catch (e) {
                        /* storage unavailable (private browsing, quota) — isManagerAlive() will just read as stale */
                    }
                };
                beat();
                heartbeatTimer = window.setInterval(beat, HEARTBEAT_INTERVAL_MS);
            },

            stopHeartbeat: function () {
                if (heartbeatTimer) {
                    window.clearInterval(heartbeatTimer);
                    heartbeatTimer = null;
                }
                try {
                    window.localStorage.removeItem(heartbeatKey);
                } catch (e) {
                    /* ignore */
                }
            },

            /** Board tabs: is a Scanner Manager tab for this same hotel currently open and alive? */
            isManagerAlive: function () {
                try {
                    var last = parseInt(window.localStorage.getItem(heartbeatKey) || '0', 10);
                    return (Date.now() - last) < HEARTBEAT_STALE_MS;
                } catch (e) {
                    return false;
                }
            },
        };
    }

    window.V5idScannerChannel = {
        /** @param {number|string} idHotel */
        create: createChannel,
    };
})(window);
