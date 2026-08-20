<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * The V5id API credential (base URL, device serial/secret, and the token
 * pair issued for them) for one hotel. Each property has its own — an
 * owner may use a separate V5id integration ID per property specifically
 * so that logging into the V5id portal under that property's credential
 * only shows verifications for that property. A single shared credential
 * across every property would defeat that on V5id's own side, not just
 * this module's.
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
    public $api_base_url;
    public $device_serial;
    public $device_secret;
    public $access_token;
    public $refresh_token;
    public $token_expires_at;
    public $refresh_expires_at;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table' => 'v5idfrontdesk_hotel_credential',
        'primary' => 'id',
        'fields' => array(
            'id_hotel' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'api_base_url' => array('type' => self::TYPE_STRING, 'size' => 255),
            'device_serial' => array('type' => self::TYPE_STRING, 'size' => 128),
            'device_secret' => array('type' => self::TYPE_STRING, 'size' => 255),
            'access_token' => array('type' => self::TYPE_STRING),
            'refresh_token' => array('type' => self::TYPE_STRING),
            'token_expires_at' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'refresh_expires_at' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
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
     * new secret clears the cached token pair, since a token issued under
     * the old secret is no longer valid once it changes.
     *
     * @param int $idHotel
     * @param string $apiBaseUrl
     * @param string $deviceSerial
     * @param string $deviceSecret Empty string to leave the saved secret untouched.
     *
     * @return bool
     */
    public static function saveForHotel($idHotel, $apiBaseUrl, $deviceSerial, $deviceSecret)
    {
        $credential = self::getForHotel($idHotel);
        $isNew = !$credential;

        if ($isNew) {
            $credential = new self();
            $credential->id_hotel = (int) $idHotel;
            $credential->date_add = date('Y-m-d H:i:s');
        }

        $credential->api_base_url = rtrim((string) $apiBaseUrl, '/');
        $credential->device_serial = (string) $deviceSerial;

        if ($deviceSecret !== '') {
            $credential->device_secret = $deviceSecret;
            $credential->access_token = null;
            $credential->refresh_token = null;
            $credential->token_expires_at = 0;
            $credential->refresh_expires_at = 0;
        }

        $credential->date_upd = date('Y-m-d H:i:s');

        return $isNew ? (bool) $credential->add() : (bool) $credential->update();
    }

    /**
     * Persists a freshly issued/refreshed token pair onto this row.
     *
     * @param array $tokenResponse Decoded TokenResponse body from the V5id API.
     *
     * @return bool
     */
    public function storeTokenPair(array $tokenResponse)
    {
        $expiresIn = isset($tokenResponse['expires_in']) ? (int) $tokenResponse['expires_in'] : 0;

        $this->access_token = isset($tokenResponse['access_token']) ? $tokenResponse['access_token'] : null;
        $this->token_expires_at = time() + $expiresIn;

        if (!empty($tokenResponse['refresh_token'])) {
            $this->refresh_token = $tokenResponse['refresh_token'];
            $this->refresh_expires_at = time() + (7 * 86400);
        }

        $this->date_upd = date('Y-m-d H:i:s');

        return (bool) $this->update();
    }
}
