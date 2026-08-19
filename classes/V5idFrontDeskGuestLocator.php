<?php
/**
 * V5iD Front Desk module for QloApps.
 *
 * Guest search and scan-to-booking matching for the front desk.
 *
 * Queries QloApps' own booking/customer/room tables directly (read-only) —
 * no duplicated guest data is kept by this module.
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

class V5idFrontDeskGuestLocator
{
    /**
     * Searches current/upcoming bookings by guest name, email, phone, room
     * number or order reference, scoped to one hotel.
     *
     * @param string $term
     * @param int $idHotel
     * @param int $limit
     *
     * @return array
     */
    public static function searchGuests($term, $idHotel, $limit = 25)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return array();
        }

        $escaped = pSQL($term);
        $like = '%'.$escaped.'%';

        return Db::getInstance()->executeS(
            'SELECT
                hbd.id AS id_hotel_booking_detail,
                hbd.id_order,
                hbd.id_customer,
                hbd.id_room,
                hbd.id_status,
                hbd.date_from,
                hbd.date_to,
                hbd.check_in,
                hbd.check_out,
                hbd.adults,
                hbd.children,
                hbd.room_type_name,
                ri.room_num,
                ri.floor,
                c.firstname,
                c.lastname,
                hbd.email,
                hbd.phone,
                o.reference AS order_reference
            FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
            INNER JOIN `'._DB_PREFIX_.'customer` c ON c.id_customer = hbd.id_customer
            LEFT JOIN `'._DB_PREFIX_.'htl_room_information` ri ON ri.id = hbd.id_room
            LEFT JOIN `'._DB_PREFIX_.'orders` o ON o.id_order = hbd.id_order
            WHERE hbd.id_hotel = '.(int) $idHotel.'
                AND hbd.is_refunded = 0
                AND hbd.is_cancelled = 0
                AND hbd.date_to >= "'.date('Y-m-d').'"
                AND (
                    CONCAT(c.firstname, \' \', c.lastname) LIKE "'.$like.'"
                    OR hbd.email LIKE "'.$like.'"
                    OR hbd.phone LIKE "'.$like.'"
                    OR ri.room_num LIKE "'.$like.'"
                    OR o.reference LIKE "'.$like.'"
                )
            ORDER BY hbd.date_from ASC
            LIMIT '.(int) $limit
        );
    }

    /**
     * Best-effort match of a V5iD scan result to a booking arriving/in-house
     * today at the given hotel. This is a name-based match, not a
     * biometric-grade identity match — the UI must let staff confirm or
     * pick among several candidates.
     *
     * @param array $scanResult Normalized result from V5idApiClient::validateScan().
     * @param int $idHotel
     *
     * @return array
     */
    public static function matchScanToBooking(array $scanResult, $idHotel)
    {
        if (empty($scanResult['lastName'])) {
            return array();
        }

        $lastName = pSQL($scanResult['lastName']);
        $firstNameLike = !empty($scanResult['firstName']) ? '%'.pSQL($scanResult['firstName']).'%' : '%';

        $statuses = HotelBookingDetail::STATUS_ALLOTED.', '.HotelBookingDetail::STATUS_CHECKED_IN;

        return Db::getInstance()->executeS(
            'SELECT
                hbd.id AS id_hotel_booking_detail,
                hbd.id_order,
                hbd.id_customer,
                hbd.id_room,
                hbd.id_status,
                hbd.date_from,
                hbd.date_to,
                hbd.room_type_name,
                ri.room_num,
                c.firstname,
                c.lastname,
                o.reference AS order_reference
            FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
            INNER JOIN `'._DB_PREFIX_.'customer` c ON c.id_customer = hbd.id_customer
            LEFT JOIN `'._DB_PREFIX_.'htl_room_information` ri ON ri.id = hbd.id_room
            LEFT JOIN `'._DB_PREFIX_.'orders` o ON o.id_order = hbd.id_order
            WHERE hbd.id_hotel = '.(int) $idHotel.'
                AND hbd.is_refunded = 0
                AND hbd.is_cancelled = 0
                AND hbd.id_status IN ('.$statuses.')
                AND hbd.date_from <= "'.date('Y-m-d').'"
                AND hbd.date_to >= "'.date('Y-m-d').'"
                AND c.lastname LIKE "%'.$lastName.'%"
                AND c.firstname LIKE "'.$firstNameLike.'"
            ORDER BY hbd.date_from ASC
            LIMIT 10'
        );
    }
}
