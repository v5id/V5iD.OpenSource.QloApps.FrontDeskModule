<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * The V5id API secret for one hotel. Each property has its own — an owner
 * may use a separate V5id integration ID per property specifically so
 * that logging into the V5id portal under that property's credential
 * only shows verifications for that property. A single shared secret
 * across every property would defeat that on V5id's own side, not just
 * this module's.
 *
 * This row holds only the secret, not a device serial or any cached
 * token — the V5id API issues (and scopes) a token per physical device
 * serial, not per property, so that cache lives on the matching
 * V5idFrontDeskScannerDevice row instead — see V5idApiClient's docblock.
 * The API base URL is likewise a single system-wide setting (see
 * V5IDFRONTDESK_API_BASE_URL), not part of this per-hotel row, since it
 * never varies by property.
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

class V5idFrontDeskHotelCredential extends ObjectModel
{
    public $id_hotel;
    public $device_secret;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table' => 'v5idfrontdesk_hotel_credential',
        'primary' => 'id',
        'fields' => array(
            'id_hotel' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'device_secret' => array('type' => self::TYPE_STRING, 'size' => 255),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * @param int $idHotel
     *
     * @return self|null Null if this hotel has no credential configured yet.
     */
    public static function getForHotel($idHotel)
    {
        $idCredential = (int) Db::getInstance()->getValue(
            'SELECT id
            FROM `'._DB_PREFIX_.'v5idfrontdesk_hotel_credential`
            WHERE id_hotel = '.(int) $idHotel
        );

        if (!$idCredential) {
            return null;
        }

        $credential = new self($idCredential);

        return Validate::isLoadedObject($credential) ? $credential : null;
    }

    /**
     * Creates or updates the row for one hotel. Passing an empty secret
     * keeps whatever's already saved (matches the settings form's "leave
     * blank to keep the currently saved secret" field) — only a genuinely
     * new secret clears every one of this hotel's paired devices' cached
     * tokens (see V5idFrontDeskScannerDevice), since a token issued under
     * the old secret is no longer valid once it changes.
     *
     * @param int $idHotel
     * @param string $deviceSecret Empty string to leave the saved secret untouched.
     *
     * @return bool
     */
    public static function saveForHotel($idHotel, $deviceSecret)
    {
        $credential = self::getForHotel($idHotel);
        $isNew = !$credential;

        if ($isNew) {
            $credential = new self();
            $credential->id_hotel = (int) $idHotel;
            $credential->date_add = date('Y-m-d H:i:s');
        }

        if ($deviceSecret !== '') {
            $credential->device_secret = $deviceSecret;
        }

        $credential->date_upd = date('Y-m-d H:i:s');

        $saved = $isNew ? (bool) $credential->add() : (bool) $credential->update();

        if ($saved && $deviceSecret !== '') {
            Db::getInstance()->execute(
                'UPDATE `'._DB_PREFIX_.'v5idfrontdesk_scanner_device`
                SET access_token = NULL, refresh_token = NULL, token_expires_at = 0, refresh_expires_at = 0
                WHERE id_hotel = '.(int) $idHotel
            );
        }

        return $saved;
    }
}
