/**
 * V5iD Front Desk — Vue 3 SPA mounted into the admin page by
 * AdminV5idFrontDeskController. Talks to that controller's ajaxProcess*
 * endpoints only; all booking/room-status writes happen server-side.
 */
(function () {
    'use strict';

    var cfg = window.v5idFrontDeskConfig || {};
    // The scanner channel is scoped per hotel (see scanner-channel.js) and
    // gets torn down/recreated on hotel switch — see setupScannerChannel().
    // Only one App instance is ever mounted per page, so a closure-level
    // variable here is equivalent to an instance property.
    var scannerChannel = null;

    function api(action, params) {
        var body = new URLSearchParams(Object.assign({
            ajax: 1,
            action: action,
            token: cfg.token,
        }, params || {}));

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
        }).then(function (res) {
            return res.json();
        });
    }

    function formatDate(d) {
        var yyyy = d.getFullYear();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return yyyy + '-' + mm + '-' + dd;
    }

    function addDays(dateStr, n) {
        var d = new Date(dateStr + 'T00:00:00');
        d.setDate(d.getDate() + n);
        return formatDate(d);
    }

    function guestName(row) {
        return [row.firstname, row.lastname].filter(Boolean).join(' ') || '—';
    }

    var STATUS_LABEL = {};
    STATUS_LABEL[cfg.statuses ? cfg.statuses.alloted : 1] = 'Reserved';
    STATUS_LABEL[cfg.statuses ? cfg.statuses.checkedIn : 2] = 'In house';
    STATUS_LABEL[cfg.statuses ? cfg.statuses.checkedOut : 3] = 'Checked out';

    var STATUS_CLASS = {};
    STATUS_CLASS[cfg.statuses ? cfg.statuses.alloted : 1] = 'is-alloted';
    STATUS_CLASS[cfg.statuses ? cfg.statuses.checkedIn : 2] = 'is-checkedin';
    STATUS_CLASS[cfg.statuses ? cfg.statuses.checkedOut : 3] = 'is-checkedout';

    var App = {
        data: function () {
            var today = formatDate(new Date());
            return {
                hotels: cfg.hotels || [],
                idHotel: (cfg.hotels && cfg.hotels[0]) ? cfg.hotels[0].id : null,
                dateFrom: today,
                numDays: 7,
                rooms: [],
                bookings: [],
                loadingBoard: false,
                errorMessage: '',

                searchTerm: '',
                searchResults: [],
                searching: false,
                searchTimer: null,

                selected: null, // currently open side-panel booking
                swap: null, // { vacant_rooms, swap_candidates, mode }
                swapLoading: false,
                actionLoading: false,

                scanBanner: null, // { result, matches }

                // Comparison of the ID scan that led to the currently open
                // booking against the guest's stored profile — null unless
                // this.selected was opened via a scan (see openBooking()).
                profileCheck: null, // { idCustomer, idAddress, check: { fields, hasIssues, canAutoApply } }
                profileCheckScan: null, // the scan payload behind profileCheck, resent as-is to ApplyGuestProfile (never persisted server-side)
                profileCheckLoading: false,
                profileApplyLoading: false,

                // Explicit-pairing scanner protocols (Bluetooth GATT, etc.)
                // enabled server-side for this property (see
                // AdminV5idFrontDeskController::getEnabledScannerAdapterMeta()).
                // Their connections live only in the Scanner Manager tab —
                // Bluetooth doesn't survive navigation within a tab, so the
                // board never talks to them directly, only over
                // views/js/scanner-channel.js. The always-on keyboard-wedge
                // listener needs none of this — it just works, regardless of
                // what's enabled here.
                scannerAdapters: cfg.scannerAdapters || [],
                scannerStatuses: {}, // deviceId -> disconnected|connecting|connected|reconnecting|error — one entry per physical scanner, see scannerOverallStatus
                managerAlive: false,
                managerPollTimer: null,

                activity: [],
                scans: [],
                showActivity: false,
            };
        },
        computed: {
            scannerOverallStatus: function () {
                if (!this.managerAlive) {
                    return 'disconnected';
                }
                // scannerStatuses is keyed by deviceId (one entry per
                // physical scanner, not per protocol) — a property can have
                // several, so this asks "is any of them connected" rather
                // than looking up a single status per enabled protocol,
                // which would have one device's status silently clobber
                // another's of the same protocol.
                var statuses = Object.keys(this.scannerStatuses).map(function (deviceId) {
                    return this.scannerStatuses[deviceId];
                }.bind(this));
                if (statuses.indexOf('connected') !== -1) {
                    return 'connected';
                }
                if (statuses.indexOf('reconnecting') !== -1) {
                    return 'reconnecting';
                }
                if (statuses.indexOf('connecting') !== -1) {
                    return 'connecting';
                }
                if (statuses.indexOf('error') !== -1) {
                    return 'error';
                }
                return 'disconnected';
            },
            scannerLabel: function () {
                if (!this.managerAlive) {
                    return 'Open Scanner Manager';
                }
                switch (this.scannerOverallStatus) {
                    case 'connecting': return 'Connecting…';
                    case 'connected': return 'Scanner connected';
                    case 'reconnecting': return 'Reconnecting…';
                    case 'error': return 'Connection failed';
                    default: return 'Scanner Manager open';
                }
            },
            days: function () {
                var out = [];
                for (var i = 0; i < this.numDays; i++) {
                    out.push(addDays(this.dateFrom, i));
                }
                return out;
            },
            dateTo: function () {
                return addDays(this.dateFrom, this.numDays - 1);
            },
            roomsByFloor: function () {
                var groups = {};
                this.rooms.forEach(function (r) {
                    var key = r.floor || '—';
                    if (!groups[key]) {
                        groups[key] = [];
                    }
                    groups[key].push(r);
                });
                return groups;
            },
        },
        mounted: function () {
            this.loadBoard();
            this.loadActivity();

            if (window.V5idScannerListener) {
                window.V5idScannerListener.start(this.handleScan.bind(this));
            }

            this.setupScannerChannel();
        },
        beforeUnmount: function () {
            if (window.V5idScannerListener) {
                window.V5idScannerListener.stop();
            }
            if (this.managerPollTimer) {
                window.clearInterval(this.managerPollTimer);
            }
            if (scannerChannel) {
                scannerChannel.close();
                scannerChannel = null;
            }
        },
        methods: {
            /**
             * (Re)binds the scanner channel to the currently selected
             * hotel — called on mount and again on every onHotelChange().
             * Tears down the previous hotel's channel/heartbeat-poll first:
             * each hotel gets its own BroadcastChannel (see
             * scanner-channel.js), so switching properties without this
             * would leave the board listening to the old hotel's channel,
             * or (worse) never switching at all and mixing signals from
             * two properties' Scanner Manager tabs together.
             */
            setupScannerChannel: function () {
                if (this.managerPollTimer) {
                    window.clearInterval(this.managerPollTimer);
                    this.managerPollTimer = null;
                }
                if (scannerChannel) {
                    scannerChannel.close();
                    scannerChannel = null;
                }
                this.scannerStatuses = {};
                this.managerAlive = false;

                if (!window.V5idScannerChannel || !this.idHotel || !this.scannerAdapters.length) {
                    return;
                }

                scannerChannel = window.V5idScannerChannel.create(this.idHotel);
                if (!scannerChannel.isSupported()) {
                    return;
                }

                // Any message at all proves the manager tab is alive *right
                // now* — set this directly rather than waiting for the next
                // managerPollTimer tick (up to 2s away, and background tabs
                // can throttle setInterval well past that in some browsers).
                // A reconnecting device is actively sending 'status'
                // messages, so this makes the badge track it immediately.
                scannerChannel.on('scan', function (payload) {
                    this.managerAlive = true;
                    this.handleScan(payload.data, payload.serial);
                }.bind(this));
                scannerChannel.on('status', function (payload) {
                    this.managerAlive = true;
                    this.scannerStatuses[payload.deviceId] = payload.status;
                }.bind(this));
                scannerChannel.on('error', function (payload) {
                    this.managerAlive = true;
                    var adapter = this.scannerAdapters.find(function (a) { return a.id === payload.adapterId; });
                    this.errorMessage = (adapter ? adapter.label : payload.adapterId) + ': ' + payload.message;
                }.bind(this));

                this.managerAlive = scannerChannel.isManagerAlive();
                if (this.managerAlive) {
                    // Picks up whatever the manager is already doing — e.g.
                    // it connected before this tab loaded, or before we
                    // navigated back to this page — instead of showing
                    // "disconnected" until its next status change happens
                    // to broadcast one.
                    scannerChannel.send('query-status');
                }
                this.managerPollTimer = window.setInterval(function () {
                    if (!scannerChannel) {
                        return;
                    }
                    var alive = scannerChannel.isManagerAlive();
                    if (alive && !this.managerAlive) {
                        // The manager tab just appeared (or its heartbeat
                        // just caught back up) — ask it for a fresh snapshot.
                        scannerChannel.send('query-status');
                    }
                    this.managerAlive = alive;
                }.bind(this), 2000);
            },

            /**
             * Click on the scanner control: opens the Scanner Manager as a
             * normal browser tab (no size/feature string — passing one
             * makes most browsers render a stripped-down popup window
             * instead), or focuses it if it's already open. A fixed window
             * name makes window.open() reuse that existing tab rather than
             * opening a new one each time.
             */
            openScannerManager: function () {
                if (!this.idHotel) {
                    return;
                }
                // A separate window name per hotel: switching properties on
                // the board and clicking this again should open/focus that
                // property's own Scanner Manager tab, not get stuck
                // re-focusing whatever hotel's manager happened to be
                // opened first — scanners are hard-scoped per hotel (see
                // V5idFrontDeskScannerDevice), so the tabs should be too.
                var url = cfg.scannerManagerUrl + '&id_hotel=' + encodeURIComponent(this.idHotel);
                var win = window.open(url, 'v5idfrontdeskScannerManager-' + this.idHotel);
                if (win) {
                    win.focus();
                }
            },
            statusLabel: function (idStatus) {
                return STATUS_LABEL[idStatus] || '—';
            },
            statusClass: function (idStatus) {
                return STATUS_CLASS[idStatus] || '';
            },
            guestName: guestName,

            loadBoard: function () {
                if (!this.idHotel) {
                    return;
                }
                this.loadingBoard = true;
                this.errorMessage = '';
                api('GetBoard', { id_hotel: this.idHotel, date_from: this.dateFrom, date_to: this.dateTo })
                    .then(function (res) {
                        this.loadingBoard = false;
                        if (!res.success) {
                            this.errorMessage = res.message || 'Could not load the board.';
                            return;
                        }
                        this.rooms = res.rooms;
                        this.bookings = res.bookings;
                    }.bind(this))
                    .catch(function () {
                        this.loadingBoard = false;
                        this.errorMessage = 'Network error while loading the board.';
                    }.bind(this));
            },

            loadActivity: function () {
                if (!this.idHotel) {
                    return;
                }
                api('GetActivity', { id_hotel: this.idHotel }).then(function (res) {
                    if (res.success) {
                        this.activity = res.activity;
                        this.scans = res.scans;
                    }
                }.bind(this));
            },

            shiftDates: function (days) {
                this.dateFrom = addDays(this.dateFrom, days);
                this.loadBoard();
            },
            goToday: function () {
                this.dateFrom = formatDate(new Date());
                this.loadBoard();
            },
            onHotelChange: function () {
                this.selected = null;
                this.loadBoard();
                this.loadActivity();
                this.setupScannerChannel();
            },

            bookingsForRoomDay: function (idRoom, day) {
                return this.bookings.filter(function (b) {
                    return b.id_room == idRoom && b.date_from <= day + ' 23:59:59' && b.date_to > day + ' 00:00:00';
                });
            },

            onSearchInput: function () {
                clearTimeout(this.searchTimer);
                if (!this.searchTerm.trim()) {
                    this.searchResults = [];
                    return;
                }
                this.searchTimer = setTimeout(this.runSearch.bind(this), 250);
            },
            runSearch: function () {
                if (!this.idHotel) {
                    return;
                }
                this.searching = true;
                api('SearchGuests', { id_hotel: this.idHotel, term: this.searchTerm }).then(function (res) {
                    this.searching = false;
                    if (res.success) {
                        this.searchResults = res.results;
                    }
                }.bind(this));
            },

            /**
             * @param {object} booking
             * @param {object} [scanResult] Pass this only when the booking was
             *   opened as a direct result of an ID scan (see handleScan() and
             *   selectScanMatch()) — it triggers the guest-profile check.
             *   Manual opens (calendar cell, search result) omit it.
             */
            openBooking: function (booking, scanResult) {
                this.selected = booking;
                this.swap = null;
                this.scanBanner = null;
                this.profileCheck = null;
                this.profileCheckScan = null;
                if (scanResult && scanResult.valid) {
                    this.checkGuestProfile(booking, scanResult);
                }
            },
            selectSearchResult: function (r) {
                this.openBooking(r);
                this.searchResults = [];
                this.searchTerm = '';
            },
            selectScanMatch: function (m) {
                var scanResult = this.scanBanner && this.scanBanner.result;
                this.openBooking(m, scanResult);
            },
            closePanel: function () {
                this.selected = null;
                this.swap = null;
                this.profileCheck = null;
                this.profileCheckScan = null;
            },

            checkGuestProfile: function (booking, scanResult) {
                this.profileCheckLoading = true;
                this.profileCheckScan = {
                    age: scanResult.age,
                    documentNumber: scanResult.documentNumber,
                    address: scanResult.address || {},
                };
                api('CheckGuestProfile', {
                    id_hotel_booking_detail: booking.id_hotel_booking_detail,
                    scan: JSON.stringify(this.profileCheckScan),
                }).then(function (res) {
                    this.profileCheckLoading = false;
                    if (res.success) {
                        this.profileCheck = res;
                    }
                }.bind(this));
            },
            applyGuestProfile: function () {
                if (!this.profileCheck || !this.profileCheck.check.canAutoApply.length || !this.selected || !this.profileCheckScan) {
                    return;
                }
                this.profileApplyLoading = true;
                api('ApplyGuestProfile', {
                    id_hotel_booking_detail: this.selected.id_hotel_booking_detail,
                    fields: this.profileCheck.check.canAutoApply.join(','),
                    scan: JSON.stringify(this.profileCheckScan),
                }).then(function (res) {
                    this.profileApplyLoading = false;
                    if (!res.success) {
                        this.errorMessage = res.message || 'Could not update the guest record.';
                        return;
                    }
                    this.profileCheck = res;
                }.bind(this));
            },
            profileStatusLabel: function (status) {
                switch (status) {
                    case 'ok': return 'Matches';
                    case 'missing': return 'Missing';
                    case 'mismatch': return 'Mismatch';
                    default: return 'Unverified';
                }
            },

            doCheckIn: function () {
                this.runStatusChange('CheckIn');
            },
            doCheckOut: function () {
                this.runStatusChange('CheckOut');
            },
            runStatusChange: function (action) {
                if (!this.selected) {
                    return;
                }
                this.actionLoading = true;
                api(action, { id_hotel_booking_detail: this.selected.id_hotel_booking_detail }).then(function (res) {
                    this.actionLoading = false;
                    if (!res.success) {
                        this.errorMessage = res.message || 'Action failed.';
                        return;
                    }
                    this.selected.id_status = res.booking.id_status;
                    this.selected.check_in = res.booking.check_in;
                    this.selected.check_out = res.booking.check_out;
                    this.loadBoard();
                    this.loadActivity();
                }.bind(this));
            },

            openSwap: function () {
                if (!this.selected) {
                    return;
                }
                this.swapLoading = true;
                api('GetSwapCandidates', { id_hotel_booking_detail: this.selected.id_hotel_booking_detail }).then(function (res) {
                    this.swapLoading = false;
                    if (res.success) {
                        this.swap = res;
                    } else {
                        this.errorMessage = res.message || 'Could not load swap candidates.';
                    }
                }.bind(this));
            },
            moveToRoom: function (idRoom) {
                this.runSwap({ mode: 'move', id_room_to: idRoom });
            },
            swapWithBooking: function (idBookingTo) {
                this.runSwap({ mode: 'swap', id_hotel_booking_detail_to: idBookingTo });
            },
            runSwap: function (params) {
                if (!this.selected) {
                    return;
                }
                this.actionLoading = true;
                var payload = Object.assign({ id_hotel_booking_detail: this.selected.id_hotel_booking_detail }, params);
                api('SwapRoom', payload).then(function (res) {
                    this.actionLoading = false;
                    if (!res.success) {
                        this.errorMessage = res.message || 'Could not move the room.';
                        return;
                    }
                    this.swap = null;
                    this.selected = null;
                    this.loadBoard();
                    this.loadActivity();
                }.bind(this));
            },

            /**
             * @param {string} raw Already-decoded scan text (barcode/MRZ).
             * @param {string} [serial] The paired device's serial (see
             *   scanner-manager-app.js's onScan), forwarded straight to
             *   V5id's scan-validation API, which requires one on every
             *   request. Undefined for a plain keyboard-wedge scan (see
             *   scanner-listener.js) — the server reports a clean "no
             *   known device" error for those rather than calling the API.
             */
            handleScan: function (raw, serial) {
                if (!this.idHotel) {
                    return;
                }
                this.scanBanner = { loading: true };
                api('ScanValidate', { id_hotel: this.idHotel, scan: raw, device_serial: serial || '' }).then(function (res) {
                    if (!res.success) {
                        this.scanBanner = { loading: false, error: res.message || 'Scan failed.' };
                        return;
                    }
                    this.scanBanner = { loading: false, result: res.result, matches: res.matches };
                    if (res.matches && res.matches.length === 1) {
                        this.openBooking(res.matches[0], res.result);
                    }
                    this.loadActivity();
                }.bind(this));
            },
            dismissScanBanner: function () {
                this.scanBanner = null;
            },
        },
        template:
            '<div class="v5idfd-shell">' +
            '  <header class="v5idfd-toolbar">' +
            '    <div class="v5idfd-toolbar-left">' +
            '      <select v-model="idHotel" @change="onHotelChange" class="v5idfd-select">' +
            '        <option v-for="h in hotels" :key="h.id" :value="h.id">{{ h.hotel_name }}</option>' +
            '      </select>' +
            '      <div class="v5idfd-datenav">' +
            '        <button @click="shiftDates(-numDays)" title="Previous">&laquo;</button>' +
            '        <button @click="goToday">Today</button>' +
            '        <button @click="shiftDates(numDays)" title="Next">&raquo;</button>' +
            '        <span class="v5idfd-daterange">{{ dateFrom }} → {{ dateTo }}</span>' +
            '      </div>' +
            '    </div>' +
            '    <div class="v5idfd-toolbar-right">' +
            '      <div class="v5idfd-search">' +
            '        <input type="text" v-model="searchTerm" @input="onSearchInput" placeholder="Search guest, room, order…">' +
            '        <div class="v5idfd-search-results" v-if="searchResults.length">' +
            '          <div class="v5idfd-search-row" v-for="r in searchResults" :key="r.id_hotel_booking_detail" @click="selectSearchResult(r)">' +
            '            <strong>{{ guestName(r) }}</strong>' +
            '            <span>Room {{ r.room_num }} · {{ r.date_from.substr(0,10) }} → {{ r.date_to.substr(0,10) }}</span>' +
            '          </div>' +
            '        </div>' +
            '      </div>' +
            '      <button class="v5idfd-scan-indicator" title="Scanner ready — scan a guest ID at any time">' +
            '        <i class="icon-barcode"></i> Scanner ready' +
            '      </button>' +
            '      <div class="v5idfd-scanner-control" v-if="scannerAdapters.length">' +
            '        <button class="v5idfd-scanner-btn" :class="\'is-\' + scannerOverallStatus" @click="openScannerManager" :title="managerAlive ? \'\' : \'Scanner Manager tab is not open — click to open it\'">' +
            '          <i class="icon-signal"></i> {{ scannerLabel }}' +
            '        </button>' +
            '      </div>' +
            '      <button class="v5idfd-activity-toggle" @click="showActivity = !showActivity">Activity</button>' +
            '    </div>' +
            '  </header>' +

            '  <div class="v5idfd-error" v-if="errorMessage">{{ errorMessage }} <button @click="errorMessage = \'\'">&times;</button></div>' +

            '  <div class="v5idfd-body">' +
            '    <div class="v5idfd-board" :class="{ \'is-loading\': loadingBoard }">' +
            '      <div class="v5idfd-board-header">' +
            '        <div class="v5idfd-room-col">Room</div>' +
            '        <div class="v5idfd-day-col" v-for="d in days" :key="d">{{ d.substr(5) }}</div>' +
            '      </div>' +
            '      <div v-for="(floorRooms, floor) in roomsByFloor" :key="floor" class="v5idfd-floor-group">' +
            '        <div class="v5idfd-floor-label">Floor {{ floor }}</div>' +
            '        <div class="v5idfd-room-row" v-for="room in floorRooms" :key="room.id_room">' +
            '          <div class="v5idfd-room-col">' +
            '            <strong>{{ room.room_num }}</strong>' +
            '            <small>{{ room.room_type_name }}</small>' +
            '          </div>' +
            '          <div class="v5idfd-day-col" v-for="d in days" :key="d">' +
            '            <div v-for="b in bookingsForRoomDay(room.id_room, d)" :key="b.id_hotel_booking_detail"' +
            '                 class="v5idfd-cell" :class="statusClass(b.id_status)" @click="openBooking(b)">' +
            '              {{ guestName(b) }}' +
            '            </div>' +
            '          </div>' +
            '        </div>' +
            '      </div>' +
            '      <div class="v5idfd-empty" v-if="!loadingBoard && !rooms.length">No rooms found for this hotel.</div>' +
            '    </div>' +

            '    <aside class="v5idfd-activity" v-if="showActivity">' +
            '      <h3>Recent activity</h3>' +
            '      <div class="v5idfd-activity-row" v-for="a in activity" :key="\'a\' + a.id">' +
            '        <strong>{{ a.action_type }}</strong> · room {{ a.from_id_room }}<span v-if="a.to_id_room != a.from_id_room"> → {{ a.to_id_room }}</span>' +
            '        <small>{{ a.employee_name }} · {{ a.date_add }}</small>' +
            '      </div>' +
            '      <h3>Recent scans</h3>' +
            '      <div class="v5idfd-activity-row" v-for="s in scans" :key="\'s\' + s.id">' +
            '        <strong :class="s.valid == 1 ? \'ok\' : \'bad\'">{{ s.valid == 1 ? \'Valid\' : \'Invalid\' }}</strong> scan' +
            '        <small>{{ s.employee_name }} · {{ s.date_add }}</small>' +
            '      </div>' +
            '    </aside>' +

            '    <aside class="v5idfd-panel" v-if="selected">' +
            '      <button class="v5idfd-panel-close" @click="closePanel">&times;</button>' +
            '      <h2>{{ guestName(selected) }}</h2>' +
            '      <div class="v5idfd-panel-meta">' +
            '        <span class="v5idfd-badge" :class="statusClass(selected.id_status)">{{ statusLabel(selected.id_status) }}</span>' +
            '        <span>Room {{ selected.room_num }}</span>' +
            '        <span>Order {{ selected.order_reference }}</span>' +
            '      </div>' +
            '      <dl class="v5idfd-panel-facts">' +
            '        <dt>Dates</dt><dd>{{ selected.date_from && selected.date_from.substr(0,10) }} → {{ selected.date_to && selected.date_to.substr(0,10) }}</dd>' +
            '        <dt>Guests</dt><dd>{{ selected.adults }} adult(s)<span v-if="selected.children"> · {{ selected.children }} child(ren)</span></dd>' +
            '        <dt v-if="selected.email">Email</dt><dd v-if="selected.email">{{ selected.email }}</dd>' +
            '        <dt v-if="selected.phone">Phone</dt><dd v-if="selected.phone">{{ selected.phone }}</dd>' +
            '      </dl>' +

            '      <div class="v5idfd-panel-actions">' +
            '        <button :disabled="actionLoading" @click="doCheckIn" v-if="selected.id_status != 2">Check in</button>' +
            '        <button :disabled="actionLoading" @click="doCheckOut" v-if="selected.id_status == 2">Check out</button>' +
            '        <button :disabled="actionLoading" @click="openSwap">Swap / relocate room</button>' +
            '      </div>' +

            '      <div class="v5idfd-profile-check" v-if="profileCheckLoading || profileCheck">' +
            '        <h3>ID scan vs. guest profile</h3>' +
            '        <div v-if="profileCheckLoading" class="v5idfd-muted">Checking guest profile…</div>' +
            '        <template v-else-if="profileCheck">' +
            '          <div class="v5idfd-profile-row" v-for="(field, key) in profileCheck.check.fields" :key="key" :class="\'is-\' + field.status">' +
            '            <div class="v5idfd-profile-row-head">' +
            '              <span>{{ field.label }}</span>' +
            '              <span class="v5idfd-profile-badge">{{ profileStatusLabel(field.status) }}</span>' +
            '            </div>' +
            '            <div class="v5idfd-profile-values" v-if="field.status !== \'ok\'">' +
            '              <span>On file: {{ field.current || \'—\' }}</span>' +
            '              <span>Scanned: {{ field.scanned || \'—\' }}</span>' +
            '            </div>' +
            '          </div>' +
            '          <p class="v5idfd-muted" v-if="!profileCheck.check.hasIssues">Guest profile matches the scanned ID.</p>' +
            '          <p class="v5idfd-muted" v-if="profileCheck.check.fields.age && profileCheck.check.fields.age.status === \'mismatch\'">The scan only gives an age, not an exact date of birth — please verify manually.</p>' +
            '          <button class="v5idfd-profile-apply" v-if="profileCheck.check.canAutoApply.length" :disabled="profileApplyLoading" @click="applyGuestProfile">' +
            '            {{ profileApplyLoading ? \'Updating…\' : \'Update guest record from scan\' }}' +
            '          </button>' +
            '        </template>' +
            '      </div>' +

            '      <div class="v5idfd-swap" v-if="swap">' +
            '        <h3>Move to an empty room</h3>' +
            '        <button class="v5idfd-room-chip" v-for="r in swap.vacant_rooms" :key="\'v\'+r.id_room" @click="moveToRoom(r.id_room)">{{ r.room_num }}</button>' +
            '        <p v-if="!swap.vacant_rooms.length" class="v5idfd-muted">No vacant room of this type for these dates.</p>' +
            '        <h3>Swap with another guest</h3>' +
            '        <button class="v5idfd-room-chip" v-for="c in swap.swap_candidates" :key="\'s\'+c.id_hotel_booking" @click="swapWithBooking(c.id_hotel_booking)">{{ c.room_num }}</button>' +
            '        <p v-if="!swap.swap_candidates.length" class="v5idfd-muted">No same-type booking with matching dates to swap with.</p>' +
            '      </div>' +
            '    </aside>' +
            '  </div>' +

            '  <div class="v5idfd-scan-banner" v-if="scanBanner">' +
            '    <div v-if="scanBanner.loading">Validating scan…</div>' +
            '    <div v-else-if="scanBanner.error">{{ scanBanner.error }}</div>' +
            '    <div v-else>' +
            '      <div class="v5idfd-scan-result" :class="scanBanner.result.valid ? \'ok\' : \'bad\'">' +
            '        <strong>{{ scanBanner.result.valid ? \'ID verified\' : \'ID did not validate\' }}</strong>' +
            '        <span v-if="scanBanner.result.firstName">{{ scanBanner.result.firstName }} {{ scanBanner.result.lastName }}</span>' +
            '        <span v-if="scanBanner.result.age">· {{ scanBanner.result.age }} yrs</span>' +
            '        <span v-if="!scanBanner.result.valid">{{ scanBanner.result.errors.join(\', \') }}</span>' +
            '      </div>' +
            '      <div class="v5idfd-scan-matches" v-if="scanBanner.matches && scanBanner.matches.length > 1">' +
            '        <p>Multiple bookings match this name — pick one:</p>' +
            '        <button class="v5idfd-room-chip" v-for="m in scanBanner.matches" :key="m.id_hotel_booking_detail" @click="selectScanMatch(m)">' +
            '          Room {{ m.room_num }} — {{ guestName(m) }}' +
            '        </button>' +
            '      </div>' +
            '      <p v-else-if="scanBanner.matches && !scanBanner.matches.length" class="v5idfd-muted">No matching arrival found — search manually if needed.</p>' +
            '    </div>' +
            '    <button class="v5idfd-panel-close" @click="dismissScanBanner">&times;</button>' +
            '  </div>' +
            '</div>',
    };

    document.addEventListener('DOMContentLoaded', function () {
        var mountEl = document.getElementById('v5idfrontdesk-app');
        if (mountEl && window.Vue) {
            window.Vue.createApp(App).mount(mountEl);
        }
    });
})();
