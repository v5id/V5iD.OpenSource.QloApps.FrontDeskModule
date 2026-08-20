/**
 * Registry for scanner protocol adapters that need an explicit pairing step
 * (Bluetooth GATT, Serial, HID API, etc.) — as opposed to the always-on
 * keyboard-wedge listener (scanner-listener.js), which needs no adapter
 * because it works for any scanner emitting plain keystrokes.
 *
 * Each adapter self-registers when its script loads:
 *
 *   window.V5idScannerRegistry.register({
 *     id: 'some-protocol',          // stable id, matches the settings key
 *     label: 'Human readable name', // shown per-row in the Scanner Manager
 *     isSupported: function () { return <browser API available>; },
 *     createInstance: function () {
 *       // Returns a FRESH { connect, disconnect } object every call, with
 *       // its own private state (device handle, GATT/HID session, ...) —
 *       // never a shared singleton. A property can have several physical
 *       // scanners of the same protocol (or a mix of protocols) connected
 *       // at once; the Scanner Manager calls createInstance() once per
 *       // known device, not once per protocol.
 *       return {
 *         connect: function (callbacks) { ... },   // callbacks: onScan, onStatusChange, onError. Resolves { serial }.
 *         disconnect: function () { ... },
 *       };
 *     },
 *   });
 *
 * Which adapter scripts get loaded at all is controlled server-side
 * (AdminV5idFrontDeskController::renderScannerManagerPage(), gated by the
 * module's settings) — the registry itself doesn't know or care what's
 * possible, only what actually showed up on this page. This file and its
 * adapters only ever load in the Scanner Manager tab (see
 * views/js/scanner-manager-app.js): Bluetooth/HID connections don't survive
 * navigation within a tab, so the Front Desk board tabs never load them —
 * they just listen for scans over views/js/scanner-channel.js.
 */
(function (window) {
    'use strict';

    var protocols = {};

    window.V5idScannerRegistry = {
        register: function (protocol) {
            protocols[protocol.id] = protocol;
        },

        /** @return {object[]} Every registered protocol, regardless of browser support. */
        all: function () {
            return Object.keys(protocols).map(function (id) { return protocols[id]; });
        },

        /** @return {object[]} Registered protocols this browser can actually use. */
        available: function () {
            return this.all().filter(function (p) {
                try {
                    return !!p.isSupported();
                } catch (e) {
                    return false;
                }
            });
        },

        /**
         * @param {string} id
         * @return {object|null} The protocol descriptor for one id, or null if unknown/unregistered.
         */
        get: function (id) {
            return protocols[id] || null;
        },
    };
})(window);
