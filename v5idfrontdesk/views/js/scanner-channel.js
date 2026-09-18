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
 * more than one property). A single shared channel name
 * would leak exactly that — handleScan() has no way to know a received
 * scan came from a different property's device, and would validate it
 * against whatever hotel the board tab currently has selected.
 *
 * One mechanism per channel, same-origin/same-browser only: BroadcastChannel
 * carries the events (scan/status/error), tagged with the id of the adapter
 * that produced them (see scanners/registry.js) so a board tab can tell which
 * physical scanner a message came from. It also carries a 'ping' with no
 * payload, which board tabs send on a short interval; the Scanner Manager
 * answers with 'pong' plus one 'status' per device.
 *
 * That round trip, rather than a timestamp the Scanner Manager refreshes on a
 * timer, is what tells a board tab whether a manager is open at all. Chrome
 * throttles a hidden page's timers to roughly one wake-up per minute once it
 * has been in the background for five minutes — exactly the deployment this
 * module asks for — but it does not throttle message delivery, so a liveness
 * signal built on a timer in that tab would read as dead for most of every
 * minute while scans kept arriving normally.
 */
(function (window) {
    'use strict';

    var CHANNEL_NAME_PREFIX = 'v5idfrontdesk_scanner_v1_';

    /** @param {number|string} idHotel */
    function createChannel(idHotel) {
        var channelName = CHANNEL_NAME_PREFIX + idHotel;
        var channel = ('BroadcastChannel' in window) ? new BroadcastChannel(channelName) : null;
        var handlers = {};

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
             * @param {string} type 'scan' | 'status' | 'error' | 'ping' | 'pong'
             * @param {object} [payload]
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
            },
        };
    }

    window.V5idScannerChannel = {
        /** @param {number|string} idHotel */
        create: createChannel,
    };
})(window);
