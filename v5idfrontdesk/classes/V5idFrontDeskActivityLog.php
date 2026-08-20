<?php
/**
 * V5iD Front Desk module for QloApps.
 *
 * Audit trail of check-in / check-out / room-swap actions performed from
 * the V5iD Front Desk screen, for staff accountability.
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

class V5idFrontDeskActivityLog extends ObjectModel
{
    const ACTION_CHECKIN = 'checkin';
    const ACTION_CHECKOUT = 'checkout';
    const ACTION_SWAP = 'swap';
    const ACTION_PROFILE_UPDATE = 'profile_update';

    public $id_employee;
    public $id_hotel_booking_detail;
    public $id_order;
    public $action_type;
    public $from_id_room;
    public $to_id_room;
    public $note;
    public $date_add;

    public static $definition = array(
        'table' => 'v5idfrontdesk_activity_log',
        'primary' => 'id',
        'fields' => array(
            'id_employee' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_hotel_booking_detail' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'action_type' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 16),
            'from_id_room' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'to_id_room' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'note' => array('type' => self::TYPE_STRING, 'size' => 255),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * @param array $data
     *
     * @return bool
     */
    public static function record(array $data)
    {
        $log = new self();
        $log->id_employee = (int) $data['id_employee'];
        $log->id_hotel_booking_detail = (int) $data['id_hotel_booking_detail'];
        $log->id_order = (int) $data['id_order'];
        $log->action_type = $data['action_type'];
        $log->from_id_room = !empty($data['from_id_room']) ? (int) $data['from_id_room'] : null;
        $log->to_id_room = !empty($data['to_id_room']) ? (int) $data['to_id_room'] : null;
        $log->note = isset($data['note']) ? $data['note'] : null;
        $log->date_add = date('Y-m-d H:i:s');

        return (bool) $log->add();
    }

    /**
     * @param int $idHotel
     * @param int $limit
     *
     * @return array
     */
    public static function getRecentForHotel($idHotel, $limit = 20)
    {
        return Db::getInstance()->executeS(
            'SELECT al.*, CONCAT(e.firstname, \' \', e.lastname) AS employee_name
            FROM `'._DB_PREFIX_.'v5idfrontdesk_activity_log` al
            LEFT JOIN `'._DB_PREFIX_.'employee` e ON e.id_employee = al.id_employee
            LEFT JOIN `'._DB_PREFIX_.'htl_booking_detail` hbd ON hbd.id = al.id_hotel_booking_detail
            WHERE hbd.id_hotel = '.(int) $idHotel.'
            ORDER BY al.date_add DESC
            LIMIT '.(int) $limit
        );
    }
}
