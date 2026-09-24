<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * The V5id integration one hotel signs its scanners in under. Each
 * property is its own integration in the V5id portal — which is what
 * makes logging into the portal under one property show only that
 * property's verifications — and its OAuth client id is
 * "int-<integration_id>" (see V5idDeviceOAuth).
 *
 * This row holds no secret at all: the integration key is typed by staff
 * into V5id's own sign-in page, never into QloApps, and the resulting
 * device sessions live on each V5idFrontDeskScannerDevice row. The API
 * base URL is likewise a single system-wide setting (see
 * V5IDFRONTDESK_API_BASE_URL), since it never varies by property.
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
    public $integration_id;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table' => 'v5idfrontdesk_hotel_credential',
        'primary' => 'id',
        'fields' => array(
            'id_hotel' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'integration_id' => array('type' => self::TYPE_STRING, 'size' => 36),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * @param int $idHotel
     *
     * @return self|null Null if this hotel has no integration configured yet.
     */
    public static function getForHotel($idHotel)
    {
        $idCredential = (int) Db::getInstance()->getValue(
            'SELECT id
            FROM `'._DB_PREFIX_.'v5idfrontdesk_hotel_credential`
            WHERE id_hotel = '.(int) $idHotel,
            false
        );

        if (!$idCredential) {
            return null;
        }

        $credential = new self($idCredential);

        return Validate::isLoadedObject($credential) ? $credential : null;
    }

    /**
     * Creates or updates the row for one hotel. Changing the integration
     * signs out every scanner at this property first: their sessions were
     * issued to the old integration, and revoking them needs its client id.
     *
     * @param int $idHotel
     * @param string $integrationId Bare lowercase UUID (see V5idDeviceOAuth::normalizeIntegrationId()).
     *
     * @return bool
     */
    public static function saveForHotel($idHotel, $integrationId)
    {
        $credential = self::getForHotel($idHotel);
        $isNew = !$credential;

        if ($isNew) {
            $credential = new self();
            $credential->id_hotel = (int) $idHotel;
            $credential->date_add = date('Y-m-d H:i:s');
        } elseif ($credential->integration_id !== $integrationId) {
            V5idDeviceOAuth::signOutHotel($idHotel);
        }

        $credential->integration_id = $integrationId;
        $credential->date_upd = date('Y-m-d H:i:s');

        return $isNew ? (bool) $credential->add() : (bool) $credential->update();
    }
}
