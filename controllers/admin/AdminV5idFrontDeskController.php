<?php
/**
 * V5iD Front Desk module for QloApps.
 *
 * Front desk workspace: room/date board, guest search, check-in/check-out,
 * room swap and V5iD scan verification.
 *
 * Renders a single Vue SPA mount point (views/templates/admin/v5id_front_desk)
 * and exposes the AJAX endpoints the SPA talks to. Booking/room-status writes
 * reuse QloApps' own HotelBookingDetail model and mirror the validation rules
 * in AdminOrdersController::changeRoomStatus() so state stays consistent with
 * the rest of the back office.
 *
 * Copyright (C) 2026  V5iD, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminV5idFrontDeskController extends ModuleAdminController
{
    /**
     * True when this request is for the standalone "Scanner Manager" tab
     * (?scanner_manager=1) rather than the normal front desk board. That
     * page bypasses the back-office theme entirely (see initContent()) —
     * it's meant to be left open in its own browser tab/window for as long
     * as a shift lasts, so it deliberately carries none of the BO chrome.
     *
     * @var bool
     */
    private $isScannerManager;

    public function __construct()
    {
        $this->table = 'v5idfrontdesk_activity_log';
        $this->className = 'V5idFrontDeskActivityLog';
        $this->lang = false;
        $this->bootstrap = true;

        parent::__construct();

        $this->isScannerManager = (bool) Tools::getValue('scanner_manager');
    }

    public function initContent()
    {
        if ($this->isScannerManager) {
            $this->renderScannerManagerPage();

            return;
        }

        $this->toolbar_title = $this->l('Front Desk');
        $this->display = 'view';

        parent::initContent();
    }

    public function setMedia()
    {
        parent::setMedia();

        // Self-healing for installs that predate the displayBackOfficeHeader
        // hook (added after this module's initial install() already ran on
        // existing environments) — registers it the next time this
        // controller loads, rather than requiring a reinstall (which would
        // drop the scan/activity log tables). This is the board itself, so
        // it's the fastest path to the fix actually taking effect.
        if (!$this->module->isRegisteredInHook('displayBackOfficeHeader')) {
            $this->module->registerHook('displayBackOfficeHeader');
        }

        // Same self-healing idea, for installs that predate the scan log
        // dropping its first_name/last_name columns — see
        // V5idFrontDeskScanLog::purgePersonalData(). Gated on a config flag
        // rather than checked every load: unlike the hook check above,
        // there's no cheap indexed lookup for "does this column exist",
        // so this should run itself out of the way after the one time it's
        // actually needed.
        if (!Configuration::get('V5IDFRONTDESK_SCAN_LOG_PII_PURGED')) {
            V5idFrontDeskScanLog::purgePersonalData();
            Configuration::updateValue('V5IDFRONTDESK_SCAN_LOG_PII_PURGED', 1);
        }

        // The Scanner Manager page renders its own standalone HTML document
        // (see renderScannerManagerPage()) instead of the back-office theme,
        // so none of the assets queued below ever get output for it — the
        // template pulls in exactly what it needs via literal <script> tags.
        if ($this->isScannerManager) {
            return;
        }

        // check_path=false: addCSS()'s path validation does a literal
        // file_exists() against the URL we pass it, including any query
        // string — which fails for our cache-busted "?v=..." URL and
        // silently drops the stylesheet. addJS() has no such issue (it
        // explicitly strips "?version" before validating), which is why
        // only the CSS needs this.
        $this->addCSS($this->assetUrl('views/css/frontdesk.css'), 'all', null, false);
        $this->addJS($this->assetUrl('views/js/vendor/vue.global.prod.js'));
        $this->addJS($this->assetUrl('views/js/scanner-listener.js'));

        // Explicit-pairing scanner protocols (Bluetooth GATT, etc.) never run
        // on the board itself any more — Bluetooth connections don't survive
        // navigation within a tab, so the board only needs to know which
        // adapters exist (for the "Open Scanner Manager" control below) and
        // listens for their scans over BroadcastChannel. The adapter code
        // itself only loads in the Scanner Manager tab — see
        // renderScannerManagerPage().
        $this->addJS($this->assetUrl('views/js/scanner-channel.js'));
        $this->addJS($this->assetUrl('views/js/frontdesk-app.js'));

        Media::addJsDef(array(
            'v5idFrontDeskConfig' => array(
                'ajaxUrl' => $this->context->link->getAdminLink('AdminV5idFrontDesk'),
                'token' => $this->token,
                'idEmployee' => (int) $this->context->employee->id,
                'hotels' => $this->getAccessibleHotels(),
                'statuses' => array(
                    'alloted' => HotelBookingDetail::STATUS_ALLOTED,
                    'checkedIn' => HotelBookingDetail::STATUS_CHECKED_IN,
                    'checkedOut' => HotelBookingDetail::STATUS_CHECKED_OUT,
                ),
                'scannerAdapters' => $this->getEnabledScannerAdapterMeta(),
                'scannerManagerUrl' => $this->context->link->getAdminLink('AdminV5idFrontDesk').'&scanner_manager=1',
            ),
        ));
    }

    public function renderView()
    {
        $this->tpl_view_vars = array(
            'hotels' => $this->getAccessibleHotels(),
        );

        return parent::renderView();
    }

    /**
     * Outputs the standalone Scanner Manager page and terminates the
     * request. This is the one tab meant to hold the actual Bluetooth GATT
     * connection(s) — see views/js/scanner-manager-app.js — so every
     * enabled adapter's own connection-handling file (registry.js + one
     * file per protocol, gated by V5idFrontDesk::SCANNER_ADAPTERS) loads
     * here and nowhere else.
     */
    private function renderScannerManagerPage()
    {
        $enabledAdapters = V5idFrontDesk::getEnabledScannerAdapters();

        $adapterJsUrls = array();
        foreach ($enabledAdapters as $adapterId) {
            $adapterJsUrls[] = $this->assetUrl(V5idFrontDesk::SCANNER_ADAPTERS[$adapterId]['js']);
        }

        $this->context->smarty->assign(array(
            'cssUrl' => $this->assetUrl('views/css/scanner-manager.css'),
            'registryJsUrl' => $this->assetUrl('views/js/scanners/registry.js'),
            'adapterJsUrls' => $adapterJsUrls,
            'channelJsUrl' => $this->assetUrl('views/js/scanner-channel.js'),
            'managerAppJsUrl' => $this->assetUrl('views/js/scanner-manager-app.js'),
        ));

        die($this->context->smarty->fetch(
            $this->module->getLocalPath().'views/templates/admin/v5id_front_desk/scanner_manager.tpl'
        ));
    }

    /**
     * @return array<int, array{id: string, label: string}> Enabled adapters'
     *                                                       public metadata, for the board's "Open Scanner Manager" control.
     */
    private function getEnabledScannerAdapterMeta()
    {
        $meta = array();
        foreach (V5idFrontDesk::getEnabledScannerAdapters() as $adapterId) {
            $meta[] = array(
                'id' => $adapterId,
                'label' => V5idFrontDesk::SCANNER_ADAPTERS[$adapterId]['label'],
            );
        }

        return $meta;
    }

    /**
     * Module asset URL with a filemtime-based cache-busting query string, so
     * browsers pick up JS/CSS changes immediately after a deploy instead of
     * serving a stale cached copy.
     *
     * @param string $relativePath
     *
     * @return string
     */
    private function assetUrl($relativePath)
    {
        $localFile = $this->module->getLocalPath().$relativePath;
        $version = is_file($localFile) ? filemtime($localFile) : $this->module->version;

        return $this->module->getPathUri().$relativePath.'?v='.$version;
    }

    /**
     * @return array
     */
    private function getAccessibleHotels()
    {
        $hotels = (new HotelBranchInformation())->hotelBranchesInfo(false, 1);
        if (!$hotels) {
            return array();
        }

        $hotels = HotelBranchInformation::filterDataByHotelAccess($hotels, (int) $this->context->employee->id_profile, 'id');

        return $hotels ? array_values($hotels) : array();
    }

    /**
     * @return int[]
     */
    private function getAccessibleHotelIds()
    {
        $ids = HotelBranchInformation::getProfileAccessedHotels((int) $this->context->employee->id_profile, 1, 1);

        return is_array($ids) ? array_map('intval', $ids) : array();
    }

    /**
     * @param int $idHotel
     *
     * @return bool
     */
    private function isHotelAccessible($idHotel)
    {
        $ids = $this->getAccessibleHotelIds();

        return empty($ids) || in_array((int) $idHotel, $ids, true);
    }

    /**
     * @param string $message
     */
    private function dieError($message)
    {
        $this->ajaxDie(json_encode(array('success' => false, 'message' => $message)));
    }

    // -----------------------------------------------------------------
    // Board
    // -----------------------------------------------------------------

    public function ajaxProcessGetBoard()
    {
        $idHotel = (int) Tools::getValue('id_hotel');
        if (!$idHotel || !$this->isHotelAccessible($idHotel)) {
            $this->dieError($this->l('You do not have access to this hotel.'));
        }

        $dateFrom = Tools::getValue('date_from') ?: date('Y-m-d');
        $dateTo = Tools::getValue('date_to') ?: date('Y-m-d', strtotime('+6 days', strtotime($dateFrom)));

        if (!Validate::isDate($dateFrom) || !Validate::isDate($dateTo)) {
            $this->dieError($this->l('Invalid date range.'));
        }

        $idLang = (int) $this->context->language->id;

        $rooms = Db::getInstance()->executeS(
            'SELECT ri.id AS id_room, ri.room_num, ri.floor, ri.id_product, ri.id_status,
                pl.name AS room_type_name
            FROM `'._DB_PREFIX_.'htl_room_information` ri
            LEFT JOIN `'._DB_PREFIX_.'product_lang` pl
                ON pl.id_product = ri.id_product AND pl.id_lang = '.$idLang.'
            WHERE ri.id_hotel = '.$idHotel.'
                AND ri.id_status = '.(int) HotelRoomInformation::STATUS_ACTIVE.'
            ORDER BY ri.floor ASC, ri.room_num ASC'
        );

        $bookings = Db::getInstance()->executeS(
            'SELECT
                hbd.id AS id_hotel_booking_detail,
                hbd.id_room,
                hbd.id_order,
                hbd.id_status,
                hbd.date_from,
                hbd.date_to,
                hbd.check_in,
                hbd.check_out,
                hbd.adults,
                hbd.children,
                c.firstname,
                c.lastname,
                o.reference AS order_reference
            FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
            INNER JOIN `'._DB_PREFIX_.'customer` c ON c.id_customer = hbd.id_customer
            LEFT JOIN `'._DB_PREFIX_.'orders` o ON o.id_order = hbd.id_order
            WHERE hbd.id_hotel = '.$idHotel.'
                AND hbd.is_refunded = 0
                AND hbd.is_cancelled = 0
                AND hbd.date_from < "'.pSQL($dateTo).' 23:59:59"
                AND hbd.date_to > "'.pSQL($dateFrom).' 00:00:00"
            ORDER BY hbd.date_from ASC'
        );

        $this->ajaxDie(json_encode(array(
            'success' => true,
            'rooms' => $rooms ?: array(),
            'bookings' => $bookings ?: array(),
        )));
    }

    // -----------------------------------------------------------------
    // Guest search
    // -----------------------------------------------------------------

    public function ajaxProcessSearchGuests()
    {
        $idHotel = (int) Tools::getValue('id_hotel');
        if (!$idHotel || !$this->isHotelAccessible($idHotel)) {
            $this->dieError($this->l('You do not have access to this hotel.'));
        }

        $term = (string) Tools::getValue('term');
        $results = V5idFrontDeskGuestLocator::searchGuests($term, $idHotel);

        $this->ajaxDie(json_encode(array('success' => true, 'results' => $results ?: array())));
    }

    public function ajaxProcessGetActivity()
    {
        $idHotel = (int) Tools::getValue('id_hotel');
        if (!$idHotel || !$this->isHotelAccessible($idHotel)) {
            $this->dieError($this->l('You do not have access to this hotel.'));
        }

        $this->ajaxDie(json_encode(array(
            'success' => true,
            'activity' => V5idFrontDeskActivityLog::getRecentForHotel($idHotel) ?: array(),
            'scans' => V5idFrontDeskScanLog::getRecent($idHotel) ?: array(),
        )));
    }

    // -----------------------------------------------------------------
    // Check-in / check-out
    // -----------------------------------------------------------------

    public function ajaxProcessCheckIn()
    {
        $this->processStatusChange(HotelBookingDetail::STATUS_CHECKED_IN);
    }

    public function ajaxProcessCheckOut()
    {
        $this->processStatusChange(HotelBookingDetail::STATUS_CHECKED_OUT);
    }

    /**
     * Mirrors the validation rules in AdminOrdersController::changeRoomStatus()
     * so a status change made from the front desk behaves identically to one
     * made from the order page.
     *
     * @param int $newStatus
     */
    private function processStatusChange($newStatus)
    {
        $idBookingDetail = (int) Tools::getValue('id_hotel_booking_detail');
        $objBooking = new HotelBookingDetail($idBookingDetail);

        if (!Validate::isLoadedObject($objBooking)) {
            $this->dieError($this->l('Booking not found.'));
        }

        if (!$this->isHotelAccessible($objBooking->id_hotel)) {
            $this->dieError($this->l('You do not have access to this hotel.'));
        }

        $statusDate = Tools::getValue('status_date');
        $statusDate = $statusDate ? date('Y-m-d H:i:s', strtotime($statusDate)) : date('Y-m-d H:i:s');

        $dateFrom = date('Y-m-d H:i:s', strtotime(date('Y-m-d', strtotime($objBooking->date_from))));
        $dateTo = date('Y-m-d H:i:s', strtotime(date('Y-m-d', strtotime($objBooking->date_to)).' 23:59:59'));

        if (!Validate::isDate($statusDate)) {
            $this->dieError($this->l('Invalid date.'));
        }

        if (strtotime($statusDate) < strtotime($dateFrom) || strtotime($statusDate) > strtotime($dateTo)) {
            $this->dieError($this->l('Date should be between the booking\'s check-in and check-out dates.'));
        }

        if ($objBooking->id_status == HotelBookingDetail::STATUS_CHECKED_OUT
            && date('Y-m-d', strtotime($objBooking->check_out)) != date('Y-m-d', strtotime($objBooking->date_to))
            && ($rebooking = $objBooking->chechRoomBooked($objBooking->id_room, $objBooking->check_out, $objBooking->date_to))
        ) {
            if ($rebooking['id_status'] != HotelBookingDetail::STATUS_CHECKED_OUT
                || date('Y-m-d', strtotime($rebooking['check_out'])) > date('Y-m-d', strtotime($objBooking->date_from))
            ) {
                $this->dieError($this->l('This room has a newer booking. Update the room instead of the status.'));
            }
        }

        if ($newStatus == HotelBookingDetail::STATUS_CHECKED_OUT) {
            if ($objBooking->check_in == '0000-00-00 00:00:00' || !$objBooking->check_in) {
                $this->dieError($this->l('The guest must be checked in before they can be checked out.'));
            }
            if (strtotime($objBooking->check_in) > strtotime($statusDate)) {
                $this->dieError(sprintf($this->l('Check-out can\'t be before check-in (%s).'), date('d-m-Y', strtotime($objBooking->check_in))));
            }
        }

        $order = new Order($objBooking->id_order);
        if ($newStatus == HotelBookingDetail::STATUS_CHECKED_OUT) {
            $remainingRooms = count($objBooking->getOrderCurrentDataByOrderId(
                $objBooking->id_order,
                array(HotelBookingDetail::STATUS_ALLOTED, HotelBookingDetail::STATUS_CHECKED_IN),
                0,
                0
            ));

            if ($remainingRooms == 1 && $order->getTotalPaid() < $order->getOrderTotal()) {
                $this->dieError($this->l('You cannot check out the last room on this order while there are pending bills.'));
            }
        }

        $fromRoom = $objBooking->id_room;

        $objBooking->id_status = $newStatus;
        if ($newStatus == HotelBookingDetail::STATUS_CHECKED_IN) {
            $objBooking->check_in = $statusDate;
        } elseif ($newStatus == HotelBookingDetail::STATUS_CHECKED_OUT) {
            $objBooking->check_out = $statusDate;
        }

        if (!$objBooking->save()) {
            $this->dieError($this->l('Something went wrong. Please try again.'));
        }

        Hook::exec('actionRoomBookingStatusUpdateAfter', array(
            'id_hotel_booking_detail' => $objBooking->id,
            'id_order' => $objBooking->id_order,
            'id_room' => $objBooking->id_room,
            'date_from' => $objBooking->date_from,
            'date_to' => $objBooking->date_to,
        ));

        V5idFrontDeskActivityLog::record(array(
            'id_employee' => (int) $this->context->employee->id,
            'id_hotel_booking_detail' => $objBooking->id,
            'id_order' => $objBooking->id_order,
            'action_type' => $newStatus == HotelBookingDetail::STATUS_CHECKED_IN
                ? V5idFrontDeskActivityLog::ACTION_CHECKIN
                : V5idFrontDeskActivityLog::ACTION_CHECKOUT,
            'from_id_room' => $fromRoom,
            'to_id_room' => $fromRoom,
        ));

        $this->ajaxDie(json_encode(array(
            'success' => true,
            'booking' => array(
                'id_hotel_booking_detail' => $objBooking->id,
                'id_status' => $objBooking->id_status,
                'check_in' => $objBooking->check_in,
                'check_out' => $objBooking->check_out,
            ),
        )));
    }

    // -----------------------------------------------------------------
    // Room swap / relocate
    // -----------------------------------------------------------------

    public function ajaxProcessGetSwapCandidates()
    {
        $idBookingDetail = (int) Tools::getValue('id_hotel_booking_detail');
        $objBooking = new HotelBookingDetail($idBookingDetail);

        if (!Validate::isLoadedObject($objBooking)) {
            $this->dieError($this->l('Booking not found.'));
        }

        if (!$this->isHotelAccessible($objBooking->id_hotel)) {
            $this->dieError($this->l('You do not have access to this hotel.'));
        }

        $idLang = (int) $this->context->language->id;

        // Rooms of the same type with no overlapping booking for this date range.
        $vacantRooms = Db::getInstance()->executeS(
            'SELECT ri.id AS id_room, ri.room_num, ri.floor
            FROM `'._DB_PREFIX_.'htl_room_information` ri
            WHERE ri.id_hotel = '.(int) $objBooking->id_hotel.'
                AND ri.id_product = '.(int) $objBooking->id_product.'
                AND ri.id_status = '.(int) HotelRoomInformation::STATUS_ACTIVE.'
                AND ri.id != '.(int) $objBooking->id_room.'
                AND ri.id NOT IN (
                    SELECT hbd.id_room FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                    WHERE hbd.id_hotel = '.(int) $objBooking->id_hotel.'
                        AND hbd.is_refunded = 0
                        AND hbd.is_cancelled = 0
                        AND hbd.date_from < "'.pSQL($objBooking->date_to).'"
                        AND hbd.date_to > "'.pSQL($objBooking->date_from).'"
                )
            ORDER BY ri.room_num ASC'
        );

        // Other bookings of the same type/dates, for a two-way swap.
        $objBookingDetailModel = new HotelBookingDetail();
        $swapCandidates = $objBookingDetailModel->getAvailableRoomsForSwapping(
            $objBooking->date_from,
            $objBooking->date_to,
            $objBooking->id_product,
            $objBooking->id_hotel,
            $objBooking->id_room
        );

        $this->ajaxDie(json_encode(array(
            'success' => true,
            'vacant_rooms' => $vacantRooms ?: array(),
            'swap_candidates' => $swapCandidates ?: array(),
        )));
    }

    public function ajaxProcessSwapRoom()
    {
        $idBookingDetail = (int) Tools::getValue('id_hotel_booking_detail');
        $objBooking = new HotelBookingDetail($idBookingDetail);

        if (!Validate::isLoadedObject($objBooking)) {
            $this->dieError($this->l('Booking not found.'));
        }

        if (!$this->isHotelAccessible($objBooking->id_hotel)) {
            $this->dieError($this->l('You do not have access to this hotel.'));
        }

        $mode = Tools::getValue('mode', 'move');
        $fromRoom = $objBooking->id_room;

        if ($mode === 'swap') {
            $idBookingDetailTo = (int) Tools::getValue('id_hotel_booking_detail_to');
            $objBookingTo = new HotelBookingDetail($idBookingDetailTo);

            if (!Validate::isLoadedObject($objBookingTo) || $objBookingTo->id_hotel != $objBooking->id_hotel) {
                $this->dieError($this->l('The booking to swap with was not found.'));
            }

            $toRoom = $objBookingTo->id_room;

            $objBookingDetailModel = new HotelBookingDetail();
            if (!$objBookingDetailModel->swapBooking($objBooking->id, $objBookingTo->id)) {
                $this->dieError($this->l('Could not swap these rooms. Please try again.'));
            }
        } else {
            $idRoomTo = (int) Tools::getValue('id_room_to');
            $objRoomTo = new HotelRoomInformation($idRoomTo);

            if (!Validate::isLoadedObject($objRoomTo) || $objRoomTo->id_hotel != $objBooking->id_hotel) {
                $this->dieError($this->l('Target room not found.'));
            }

            if ($objRoomTo->id_product != $objBooking->id_product) {
                $this->dieError($this->l('The target room must be the same room type. Use the Book Now reallocation tool to change room type.'));
            }

            $toRoom = $idRoomTo;

            $objBookingDetailModel = new HotelBookingDetail();
            if (!$objBookingDetailModel->reallocateBooking($objBooking->id, $idRoomTo)) {
                $this->dieError($this->l('This room is not available for the selected dates.'));
            }
        }

        V5idFrontDeskActivityLog::record(array(
            'id_employee' => (int) $this->context->employee->id,
            'id_hotel_booking_detail' => $objBooking->id,
            'id_order' => $objBooking->id_order,
            'action_type' => V5idFrontDeskActivityLog::ACTION_SWAP,
            'from_id_room' => $fromRoom,
            'to_id_room' => $toRoom,
            'note' => $mode === 'swap' ? $this->l('Two-way room swap') : $this->l('Moved to a different room'),
        ));

        $this->ajaxDie(json_encode(array('success' => true)));
    }

    // -----------------------------------------------------------------
    // V5iD scan verification
    // -----------------------------------------------------------------

    public function ajaxProcessScanValidate()
    {
        $idHotel = (int) Tools::getValue('id_hotel');
        if (!$idHotel || !$this->isHotelAccessible($idHotel)) {
            $this->dieError($this->l('You do not have access to this hotel.'));
        }

        $raw = (string) Tools::getValue('scan');
        if ($raw === '') {
            $this->dieError($this->l('Empty scan.'));
        }

        $client = new V5idApiClient();
        $result = $client->validateScan($raw);

        $matches = $result['valid'] ? V5idFrontDeskGuestLocator::matchScanToBooking($result, $idHotel) : array();

        V5idFrontDeskScanLog::record(array(
            'id_employee' => (int) $this->context->employee->id,
            'id_hotel' => $idHotel,
            'scan_format' => $result['format'],
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'age' => $result['age'],
            'document_number' => $result['documentNumber'],
            'id_hotel_booking_detail' => !empty($matches[0]['id_hotel_booking_detail']) ? $matches[0]['id_hotel_booking_detail'] : null,
            'id_order' => !empty($matches[0]['id_order']) ? $matches[0]['id_order'] : null,
            'id_customer' => !empty($matches[0]['id_customer']) ? $matches[0]['id_customer'] : null,
        ));

        $this->ajaxDie(json_encode(array(
            'success' => true,
            'result' => $result,
            'matches' => $matches,
        )));
    }

    // -----------------------------------------------------------------
    // Guest profile vs. scan check
    // -----------------------------------------------------------------

    /**
     * Compares the same scan result the board already has (see
     * ajaxProcessScanValidate() above) against the guest's stored profile,
     * once staff has opened the booking a scan matched. Kept as a separate
     * follow-up call, rather than folded into ScanValidate, so this
     * (address-bearing) comparison only ever runs for the one booking
     * actually opened — not for every candidate in an ambiguous match list.
     */
    public function ajaxProcessCheckGuestProfile()
    {
        $objBooking = $this->loadAccessibleBooking();

        $scan = $this->decodeScanPayload();
        if ($scan === null) {
            $this->dieError($this->l('Missing or invalid scan data.'));
        }

        $customer = new Customer((int) $objBooking->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->dieError($this->l('Guest not found.'));
        }

        $address = $this->getBookingAddress($objBooking);
        $check = V5idFrontDeskProfileCheck::run($customer, $address, $scan);

        $this->ajaxDie(json_encode(array(
            'success' => true,
            'idCustomer' => (int) $customer->id,
            'idAddress' => $address ? (int) $address->id : null,
            'check' => $check,
        )));
    }

    /**
     * Writes the scanned values staff confirmed onto the booking's invoice
     * address. The full scan payload is re-sent by the client rather than
     * looked up server-side — like the rest of this module (see
     * V5idFrontDeskScanLog), the decoded ID document data is never
     * persisted beyond the request it was scanned in.
     */
    public function ajaxProcessApplyGuestProfile()
    {
        $objBooking = $this->loadAccessibleBooking();

        $scan = $this->decodeScanPayload();
        if ($scan === null) {
            $this->dieError($this->l('Missing or invalid scan data.'));
        }

        $fieldKeys = array_filter(array_map('trim', explode(',', (string) Tools::getValue('fields'))));
        $fieldKeys = array_values(array_intersect($fieldKeys, V5idFrontDeskProfileCheck::APPLICABLE_FIELDS));
        if (empty($fieldKeys)) {
            $this->dieError($this->l('Nothing to update.'));
        }

        $customer = new Customer((int) $objBooking->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->dieError($this->l('Guest not found.'));
        }

        // $address may be null going in — apply() creates one for the
        // customer in that case (see getBookingAddress()'s docblock for why
        // a booking can have nothing on file at all) — and is passed by
        // reference since QloApps can itself swap in a different row.
        $address = $this->getBookingAddress($objBooking);

        if (!V5idFrontDeskProfileCheck::apply($customer, $address, $scan, $fieldKeys)) {
            $this->dieError($this->l('Could not save the address. Please check the values and try again.'));
        }

        // Keep the order pointed at whatever row now actually holds the
        // guest's data — needed the first time (the order had no invoice
        // address at all) and again any time QloApps swaps in a new row
        // because the previous one was already attached to an order
        // (Address won't edit an address in place once it's been used on
        // one — see Address::updateUsedAddress()).
        $order = new Order((int) $objBooking->id_order);
        if (Validate::isLoadedObject($order) && (int) $order->id_address_invoice !== (int) $address->id) {
            $order->id_address_invoice = (int) $address->id;
            $order->update();
        }

        V5idFrontDeskActivityLog::record(array(
            'id_employee' => (int) $this->context->employee->id,
            'id_hotel_booking_detail' => $objBooking->id,
            'id_order' => $objBooking->id_order,
            'action_type' => V5idFrontDeskActivityLog::ACTION_PROFILE_UPDATE,
            'from_id_room' => $objBooking->id_room,
            'to_id_room' => $objBooking->id_room,
            'note' => sprintf($this->l('Updated from ID scan: %s'), implode(', ', $fieldKeys)),
        ));

        $check = V5idFrontDeskProfileCheck::run($customer, $address, $scan);

        $this->ajaxDie(json_encode(array(
            'success' => true,
            'idCustomer' => (int) $customer->id,
            'idAddress' => (int) $address->id,
            'check' => $check,
        )));
    }

    /**
     * @return HotelBookingDetail Loaded and access-checked, or dies with an AJAX error.
     */
    private function loadAccessibleBooking()
    {
        $idBookingDetail = (int) Tools::getValue('id_hotel_booking_detail');
        $objBooking = new HotelBookingDetail($idBookingDetail);

        if (!Validate::isLoadedObject($objBooking)) {
            $this->dieError($this->l('Booking not found.'));
        }
        if (!$this->isHotelAccessible($objBooking->id_hotel)) {
            $this->dieError($this->l('You do not have access to this hotel.'));
        }

        return $objBooking;
    }

    /**
     * Prefers the booking's order's invoice address (the address actually
     * recorded for this stay, if any), falling back to whatever address the
     * guest's customer record has on file. Many bookings here have no
     * invoice address at all — e.g. ones created outside the full checkout
     * flow — so the fallback is the common case, not an edge case.
     * QloApps effectively treats addresses as one-per-customer already (see
     * Address::add()'s own guard against creating a second one), which is
     * why the fallback doesn't need to pick among several.
     *
     * @param HotelBookingDetail $objBooking
     *
     * @return Address|null
     */
    private function getBookingAddress(HotelBookingDetail $objBooking)
    {
        $order = new Order((int) $objBooking->id_order);
        if (Validate::isLoadedObject($order) && $order->id_address_invoice) {
            $address = new Address((int) $order->id_address_invoice);
            if (Validate::isLoadedObject($address) && !$address->deleted) {
                return $address;
            }
        }

        $idAddress = Customer::getCustomerIdAddress((int) $objBooking->id_customer, false);
        if ($idAddress) {
            $address = new Address((int) $idAddress);
            if (Validate::isLoadedObject($address)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Decodes the JSON scan payload the front end resends for both of the
     * endpoints above — same shape as V5idApiClient::validateScan()'s
     * 'age'/'documentNumber'/'address' keys.
     *
     * @return array{age: ?int, documentNumber: ?string, address: array}|null
     */
    private function decodeScanPayload()
    {
        $raw = Tools::getValue('scan');
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return array(
            'age' => isset($decoded['age']) ? (int) $decoded['age'] : null,
            'documentNumber' => isset($decoded['documentNumber']) ? (string) $decoded['documentNumber'] : null,
            'address' => isset($decoded['address']) && is_array($decoded['address']) ? $decoded['address'] : array(),
        );
    }
}
