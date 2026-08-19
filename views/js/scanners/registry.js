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
 *     label: 'Human readable name', // shown in the "Connect Scanner" picker
 *     isSupported: function () { return <browser API available>; },
 *     connect: function (callbacks) { ... },   // callbacks: onScan, onStatusChange, onError
 *     disconnect: function () { ... },
 *   });
 *
 * Which adapter scripts get loaded at all is controlled server-side
 * (AdminV5idFrontDeskController::renderScannerManagerPage(), gated by the
 * module's settings) — the registry itself doesn't know or care what's
 * possible, only what actually showed up on this page. This file and its
 * adapters only ever load in the Scanner Manager tab (see
 * views/js/scanner-manager-app.js): Bluetooth connections don't survive
 * navigation within a tab, so the Front Desk board tabs never load them —
 * they just listen for scans over views/js/scanner-channel.js.
 */
(function (window) {
    'use strict';

    var adapters = {};

    window.V5idScannerRegistry = {
        register: function (adapter) {
            adapters[adapter.id] = adapter;
        },

        /** @return {object[]} Every registered adapter, regardless of browser support. */
        all: function () {
            return Object.keys(adapters).map(function (id) { return adapters[id]; });
        },

        /** @return {object[]} Registered adapters this browser can actually use. */
        available: function () {
            return this.all().filter(function (a) {
                try {
                    return !!a.isSupported();
                } catch (e) {
                    return false;
                }
            });
        },
    };
})(window);
