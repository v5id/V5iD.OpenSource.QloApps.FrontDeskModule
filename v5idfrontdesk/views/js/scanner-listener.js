/**
 * Global capture listener for HID barcode/MRZ scanners (Inateck, Tera and
 * similar USB or Bluetooth devices in "keyboard wedge" mode). Both
 * transports present themselves to Chrome as a standard keyboard once
 * paired, so no Web HID/Web Bluetooth permission flow is needed.
 *
 * Why this routes through a hidden textarea rather than reading raw keydown
 * events: an AAMVA PDF417 payload needs literal control bytes right after
 * the '@' compliance indicator (LF, then Record Separator 0x1E, then CR).
 * There is no keyboard key for a byte like 0x1E, so scanners emit it via
 * the Windows "Alt+Numpad" trick — hold Alt, type the decimal code (030),
 * release Alt — which the OS composes into a single character. That
 * composition only happens inside a real focused editable element; a raw
 * `keydown` listener only ever sees the individual digit keys ('0','3','0')
 * and has no way to know they were meant to collapse into one byte. Routing
 * input into a genuine hidden `<textarea>` lets the browser/OS do that
 * composition for us, and we just read the resulting value.
 *
 * The hidden textarea stays focused whenever no other real text field
 * (search box, form input, etc.) has focus, so scanning works without staff
 * needing to click anywhere first — but it also means nothing else on the
 * page should be typed into it, which is why it's 1x1, off-screen and
 * `tabIndex="-1"` (unreachable by Tab, unclickable, never a deliberate
 * target for a person).
 */
(function (window, document) {
    'use strict';

    /** Treat the buffered value as complete once it stops changing for this long. */
    var QUIET_PERIOD_MS = 80;
    /** Discard anything shorter — filters out stray keystrokes/noise. */
    var MIN_SCAN_LENGTH = 15;
    /** Discard anything that took longer than this to arrive — a real scan
     *  lands in well under a second; slower input is a person, not a scanner. */
    var MAX_BURST_DURATION_MS = 1500;

    function createListener() {
        var proxyEl = null;
        var onScan = null;
        var quietTimer = null;
        var burstStartedAt = 0;

        function isRealTextField(el) {
            if (!el || el === proxyEl) {
                return false;
            }
            var tag = el.tagName;
            return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
        }

        function ensureProxy() {
            if (proxyEl) {
                return proxyEl;
            }

            proxyEl = document.createElement('textarea');
            proxyEl.setAttribute('aria-hidden', 'true');
            proxyEl.tabIndex = -1;
            proxyEl.autocomplete = 'off';
            proxyEl.spellcheck = false;
            proxyEl.style.position = 'fixed';
            proxyEl.style.top = '0';
            proxyEl.style.left = '0';
            proxyEl.style.width = '1px';
            proxyEl.style.height = '1px';
            proxyEl.style.opacity = '0';
            proxyEl.style.border = 'none';
            proxyEl.style.padding = '0';
            proxyEl.style.pointerEvents = 'none';
            document.body.appendChild(proxyEl);

            proxyEl.addEventListener('input', onProxyInput);
            proxyEl.addEventListener('keydown', onProxyKeydown);

            return proxyEl;
        }

        function onProxyKeydown(evt) {
            // A real physical Enter key would insert a newline and could be
            // misread as part of the payload — swallow it. Alt+Numpad
            // composed characters don't fire as an "Enter" keydown, so this
            // doesn't affect them.
            if (evt.key === 'Enter') {
                evt.preventDefault();
            }
        }

        function onProxyInput() {
            if (proxyEl.value.length && !burstStartedAt) {
                burstStartedAt = Date.now();
            }
            if (quietTimer) {
                window.clearTimeout(quietTimer);
            }
            quietTimer = window.setTimeout(commit, QUIET_PERIOD_MS);
        }

        function commit() {
            quietTimer = null;
            var value = proxyEl.value;
            var duration = burstStartedAt ? (Date.now() - burstStartedAt) : 0;
            proxyEl.value = '';
            burstStartedAt = 0;

            if (value.length >= MIN_SCAN_LENGTH && duration <= MAX_BURST_DURATION_MS && typeof onScan === 'function') {
                onScan(value);
            }
        }

        function refocusProxy() {
            if (!proxyEl || isRealTextField(document.activeElement)) {
                return;
            }
            proxyEl.focus({ preventScroll: true });
        }

        function onFocusOut() {
            // Give the new element a tick to actually receive focus before checking.
            window.setTimeout(refocusProxy, 0);
        }

        return {
            /**
             * @param {function(string):void} callback Called with the raw
             *   decoded scan text once a burst completes.
             */
            start: function (callback) {
                onScan = callback;
                ensureProxy();
                document.addEventListener('focusout', onFocusOut, true);
                window.addEventListener('focus', refocusProxy);
                refocusProxy();
            },
            stop: function () {
                document.removeEventListener('focusout', onFocusOut, true);
                window.removeEventListener('focus', refocusProxy);
                onScan = null;
            },
        };
    }

    window.V5idScannerListener = createListener();
})(window, document);
