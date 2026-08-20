<?php
/**
 * V5iD Front Desk module for QloApps.
 *
 * Audit record of a single V5iD ID scan performed from the front desk.
 *
 * The full decoded scan text (raw AAMVA/MRZ payload) is intentionally never
 * persisted here — only a minimal, privacy-conscious summary of the result
 * plus whichever booking/customer it was matched to. The guest's name isn't
 * stored either: id_customer/id_hotel_booking_detail already give a way to
 * look it up for anyone who legitimately needs to, without every row of
 * this table casually holding it — see purgePersonalData(). Rows also don't
 * accumulate forever: record() prunes anything past RETENTION_DAYS on every
 * write, so the table doesn't grow without bound.
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

class V5idFrontDeskScanLog extends ObjectModel
{
    /** Rows older than this are deleted on every write — see record(). */
    const RETENTION_DAYS = 90;

    public $id_employee;
    public $id_hotel;
    public $scan_format;
    public $valid;
    public $error_codes;
    public $age;
    public $document_number_last4;
    public $id_customer;
    public $id_order;
    public $id_hotel_booking_detail;
    public $date_add;

    public static $definition = array(
        'table' => 'v5idfrontdesk_scan_log',
        'primary' => 'id',
        'fields' => array(
            'id_employee' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_hotel' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'scan_format' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 8),
            'valid' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'error_codes' => array('type' => self::TYPE_STRING),
            'age' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'document_number_last4' => array('type' => self::TYPE_STRING, 'size' => 4),
            'id_customer' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'id_hotel_booking_detail' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * Records a scan attempt. Only the last 4 characters of the document
     * number are stored; the rest of the decoded scan, and the guest's
     * name, are discarded here — id_customer/id_hotel_booking_detail are
     * enough to look the guest up for anyone who actually needs to.
     *
     * @param array $data
     *
     * @return bool
     */
    public static function record(array $data)
    {
        $log = new self();
        $log->id_employee = (int) $data['id_employee'];
        $log->id_hotel = (int) $data['id_hotel'];
        $log->scan_format = $data['scan_format'];
        $log->valid = !empty($data['valid']);
        $log->error_codes = !empty($data['errors']) ? json_encode($data['errors']) : null;
        $log->age = isset($data['age']) ? (int) $data['age'] : null;
        $log->document_number_last4 = !empty($data['document_number'])
            ? Tools::substr($data['document_number'], -4)
            : null;
        $log->id_customer = !empty($data['id_customer']) ? (int) $data['id_customer'] : null;
        $log->id_order = !empty($data['id_order']) ? (int) $data['id_order'] : null;
        $log->id_hotel_booking_detail = !empty($data['id_hotel_booking_detail']) ? (int) $data['id_hotel_booking_detail'] : null;
        $log->date_add = date('Y-m-d H:i:s');

        $result = (bool) $log->add();

        // Best-effort housekeeping, piggybacked on the write that's already
        // happening rather than needing a separate cron/task-scheduler
        // dependency — scan volume at a front desk is low enough that one
        // extra DELETE per scan is negligible.
        self::pruneOldRows();

        return $result;
    }

    /**
     * Deletes scan log rows older than RETENTION_DAYS, across all hotels.
     */
    private static function pruneOldRows()
    {
        Db::getInstance()->execute(
            'DELETE FROM `'._DB_PREFIX_.'v5idfrontdesk_scan_log`
            WHERE date_add < DATE_SUB(NOW(), INTERVAL '.(int) self::RETENTION_DAYS.' DAY)'
        );
    }

    /**
     * One-time cleanup for installs from before this table stopped storing
     * guest names: drops first_name/last_name if they're still there,
     * clearing any names already recorded along with the columns
     * themselves. Safe to call repeatedly — it's a no-op once the columns
     * are gone. See AdminV5idFrontDeskController::setMedia() for the
     * self-healing call site.
     *
     * @return void
     */
    public static function purgePersonalData()
    {
        $hasNameColumns = Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `'._DB_PREFIX_.'v5idfrontdesk_scan_log` LIKE \'first_name\''
        );

        if ($hasNameColumns) {
            Db::getInstance()->execute(
                'ALTER TABLE `'._DB_PREFIX_.'v5idfrontdesk_scan_log`
                DROP COLUMN `first_name`,
                DROP COLUMN `last_name`'
            );
        }
    }

    /**
     * @param int $idHotel
     * @param int $limit
     *
     * @return array
     */
    public static function getRecent($idHotel, $limit = 20)
    {
        return Db::getInstance()->executeS(
            'SELECT sl.*, CONCAT(e.firstname, \' \', e.lastname) AS employee_name
            FROM `'._DB_PREFIX_.'v5idfrontdesk_scan_log` sl
            LEFT JOIN `'._DB_PREFIX_.'employee` e ON e.id_employee = sl.id_employee
            WHERE sl.id_hotel = '.(int) $idHotel.'
            ORDER BY sl.date_add DESC
            LIMIT '.(int) $limit
        );
    }
}
