<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * A known physical scanner, tied to exactly one hotel. Pairing a device in
 * the Scanner Manager (see views/js/scanner-manager-app.js) records one row
 * here — id_hotel, adapter_id and serial together identify a specific unit,
 * so the same physical scanner reconnecting later is recognized rather than
 * registered again. This is a hard boundary, not just a label: staff at one
 * property must never see or be able to use a scanner registered to another
 * — see getForHotel().
 *
 * This row also holds the unit's V5id device session (access_token,
 * refresh_token, token_expires_at, signed_in_at): V5id signs in each
 * physical serial separately, through the Authorization Code + PKCE flow
 * staff start from Scanner Manager — see V5idDeviceOAuth. The tokens are
 * read and written only by V5idDeviceOAuth, and never leave the server:
 * getForHotel() — the one query whose rows reach the browser — reports the
 * session as a signed_in flag instead.
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

class V5idFrontDeskScannerDevice extends ObjectModel
{
    public $id_hotel;
    public $adapter_id;
    public $serial;
    public $label;
    public $active;
    public $access_token;
    public $refresh_token;
    public $token_expires_at;
    public $signed_in_at;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table' => 'v5idfrontdesk_scanner_device',
        'primary' => 'id',
        'fields' => array(
            'id_hotel' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'adapter_id' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 32),
            'serial' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 64),
            'label' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 64),
            'active' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'access_token' => array('type' => self::TYPE_STRING),
            'refresh_token' => array('type' => self::TYPE_STRING),
            'token_expires_at' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'signed_in_at' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * Every device registered to a hotel, newest first. Callers must always
     * pass the hotel the current employee/session is actually working in
     * (already access-checked via AdminV5idFrontDeskController::
     * isHotelAccessible()) — this is the one query the whole "which
     * scanners can this property see" boundary rests on.
     *
     * Its rows go straight to Scanner Manager, so the columns are listed
     * rather than SELECT *: the V5id tokens stay server-side, and the
     * session is reported as signed_in instead. A session counts as signed
     * in while it holds a refresh token — the access token alone expires
     * within hours and is renewed on the next scan.
     *
     * @param int $idHotel
     *
     * @return array
     */
    public static function getForHotel($idHotel)
    {
        return Db::getInstance()->executeS(
            'SELECT id, id_hotel, adapter_id, serial, label, active, date_add, date_upd,
                (refresh_token IS NOT NULL AND refresh_token != "") AS signed_in,
                signed_in_at
            FROM `'._DB_PREFIX_.'v5idfrontdesk_scanner_device`
            WHERE id_hotel = '.(int) $idHotel.'
            ORDER BY date_add DESC',
            true,
            false
        );
    }

    /**
     * Finds the row for a specific physical unit at a specific hotel, if
     * one's already been paired here. Deliberately scoped by id_hotel, not
     * just adapter_id/serial — the same physical scanner paired at two
     * different properties (e.g. moved between them) gets two independent
     * rows, each governed by that property's own access boundary.
     *
     * @param int $idHotel
     * @param string $adapterId
     * @param string $serial
     *
     * @return V5idFrontDeskScannerDevice|null
     */
    public static function findByHotelAdapterSerial($idHotel, $adapterId, $serial)
    {
        $idDevice = (int) Db::getInstance()->getValue(
            'SELECT id
            FROM `'._DB_PREFIX_.'v5idfrontdesk_scanner_device`
            WHERE id_hotel = '.(int) $idHotel.'
                AND adapter_id = "'.pSQL($adapterId).'"
                AND serial = "'.pSQL($serial).'"'
        );

        if (!$idDevice) {
            return null;
        }

        $device = new self($idDevice);

        return Validate::isLoadedObject($device) ? $device : null;
    }

    /**
     * Finds the row for a specific physical unit at a specific hotel by
     * serial alone, ignoring adapter_id — used for V5id API/token purposes,
     * where the serial is the only identity V5id itself knows about (our
     * adapter_id is a purely local bucket for which local pairing code to
     * use, meaningless to V5id). Scoped by id_hotel for the same reason as
     * findByHotelAdapterSerial(): the same physical unit paired at two
     * properties gets two independent rows/token caches.
     *
     * @param int $idHotel
     * @param string $serial
     *
     * @return V5idFrontDeskScannerDevice|null
     */
    public static function findByHotelSerial($idHotel, $serial)
    {
        $idDevice = (int) Db::getInstance()->getValue(
            'SELECT id
            FROM `'._DB_PREFIX_.'v5idfrontdesk_scanner_device`
            WHERE id_hotel = '.(int) $idHotel.'
                AND serial = "'.pSQL($serial).'"'
        );

        if (!$idDevice) {
            return null;
        }

        $device = new self($idDevice);

        return Validate::isLoadedObject($device) ? $device : null;
    }

    /**
     * The device's current V5id session, read straight from the database
     * — never from ObjectModel's or Db's per-request caches, since
     * V5idDeviceOAuth re-reads it after waiting on another request's
     * refresh and must see what that request just wrote.
     *
     * @param int $idDevice
     *
     * @return array{access_token: ?string, refresh_token: ?string, token_expires_at: int}|null
     */
    public static function getTokenState($idDevice)
    {
        $row = Db::getInstance()->getRow(
            'SELECT access_token, refresh_token, token_expires_at
            FROM `'._DB_PREFIX_.'v5idfrontdesk_scanner_device`
            WHERE id = '.(int) $idDevice,
            false
        );

        return $row ?: null;
    }

    /**
     * Persists a token pair from V5id's /oauth/token onto this device.
     * Written with a plain UPDATE rather than ObjectModel::update(), which
     * would also write back every other field as this request last loaded
     * it — possibly stale by the time a refresh lock was acquired.
     *
     * @param int $idDevice
     * @param array $tokenResponse Validated V5id token response.
     * @param bool $isNewSignIn True for a code exchange; false for a refresh, which keeps the original sign-in time.
     *
     * @return bool
     */
    public static function storeSession($idDevice, array $tokenResponse, $isNewSignIn)
    {
        $now = time();
        $fields = array(
            'access_token' => pSQL($tokenResponse['access_token']),
            'refresh_token' => pSQL($tokenResponse['refresh_token']),
            'token_expires_at' => $now + (int) $tokenResponse['expires_in'],
            'date_upd' => date('Y-m-d H:i:s', $now),
        );
        if ($isNewSignIn) {
            $fields['signed_in_at'] = $now;
        }

        return (bool) Db::getInstance()->update('v5idfrontdesk_scanner_device', $fields, 'id = '.(int) $idDevice);
    }

    /**
     * Forgets this device's V5id session. Does not revoke it at V5id —
     * see V5idDeviceOAuth::signOut() for that.
     *
     * @param int $idDevice
     *
     * @return bool
     */
    public static function clearSession($idDevice)
    {
        return (bool) Db::getInstance()->execute(
            'UPDATE `'._DB_PREFIX_.'v5idfrontdesk_scanner_device`
            SET access_token = NULL, refresh_token = NULL, token_expires_at = 0, signed_in_at = NULL
            WHERE id = '.(int) $idDevice
        );
    }

    /**
     * Registers a newly-paired device, or updates the label/active flag if
     * this exact (hotel, adapter, serial) combination is already known —
     * pairing the same physical unit twice re-labels it rather than
     * creating a duplicate row.
     *
     * @param int $idHotel
     * @param string $adapterId
     * @param string $serial
     * @param string $label
     *
     * @return bool
     */
    public static function upsert($idHotel, $adapterId, $serial, $label)
    {
        $device = self::findByHotelAdapterSerial($idHotel, $adapterId, $serial);
        $isNew = !$device;

        if ($isNew) {
            $device = new self();
            $device->id_hotel = (int) $idHotel;
            $device->adapter_id = $adapterId;
            $device->serial = $serial;
            $device->date_add = date('Y-m-d H:i:s');
        }

        $device->label = $label;
        $device->active = 1;
        $device->date_upd = date('Y-m-d H:i:s');

        return $isNew ? (bool) $device->add() : (bool) $device->update();
    }
}
