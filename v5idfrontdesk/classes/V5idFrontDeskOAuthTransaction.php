<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * One in-flight V5id device sign-in (see V5idDeviceOAuth): the PKCE
 * verifier and everything the callback needs to finish the exchange,
 * keyed by the SHA-256 of its state. Kept server-side so neither the
 * verifier nor the device the sign-in is for ever depends on what the
 * browser sends back — the callback only has to present the state.
 *
 * Rows are single-use (consume() deletes the row it returns, and only the
 * request whose DELETE actually removed it gets it back) and short-lived
 * (V5idDeviceOAuth::TRANSACTION_TTL).
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

class V5idFrontDeskOAuthTransaction
{
    const TABLE = 'v5idfrontdesk_oauth_transaction';

    /**
     * @param string $state
     * @param string $codeVerifier
     * @param V5idFrontDeskScannerDevice $device
     * @param int $idEmployee
     * @param string $clientId
     * @param string $redirectUri
     * @param int $expiresAt
     *
     * @return bool
     */
    public static function create($state, $codeVerifier, V5idFrontDeskScannerDevice $device, $idEmployee, $clientId, $redirectUri, $expiresAt)
    {
        return (bool) Db::getInstance()->insert(self::TABLE, array(
            'state_hash' => hash('sha256', $state),
            'code_verifier' => pSQL($codeVerifier),
            'id_device' => (int) $device->id,
            'id_hotel' => (int) $device->id_hotel,
            'serial' => pSQL($device->serial),
            'id_employee' => (int) $idEmployee,
            'client_id' => pSQL($clientId),
            'redirect_uri' => pSQL($redirectUri),
            'expires_at' => (int) $expiresAt,
            'date_add' => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Looks up and deletes the transaction for a state in one go.
     *
     * @param string $state
     *
     * @return array|null The row, or null when the state is unknown, expired or was already consumed.
     */
    public static function consume($state)
    {
        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT *
            FROM `'._DB_PREFIX_.self::TABLE.'`
            WHERE state_hash = "'.pSQL(hash('sha256', (string) $state)).'"',
            false
        );

        if (!$row) {
            return null;
        }

        // Two callbacks carrying the same state (a double-loaded page, a
        // replay) both find the row; only the one whose DELETE removes it
        // may go on to redeem the code.
        $db->execute('DELETE FROM `'._DB_PREFIX_.self::TABLE.'` WHERE id = '.(int) $row['id']);
        if ((int) $db->Affected_Rows() !== 1) {
            return null;
        }

        return (int) $row['expires_at'] > time() ? $row : null;
    }

    /**
     * @return void
     */
    public static function purgeExpired()
    {
        Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.self::TABLE.'` WHERE expires_at < '.(int) time());
    }

    /**
     * @param int $idDevice
     *
     * @return void
     */
    public static function deleteForDevice($idDevice)
    {
        Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.self::TABLE.'` WHERE id_device = '.(int) $idDevice);
    }
}
