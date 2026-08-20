<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * Thin client for the V5id device-scan validation API.
 *
 * Scope is intentionally limited to the standalone device-scan flow:
 *   POST /security/device/token
 *   POST /security/device/token/refresh
 *   POST /device/validate
 * The richer /verifications/* flow (document images, face liveness) is out of scope.
 *
 * A client is scoped to one hotel and, for anything that talks to V5id,
 * one physical device's serial number: the V5id API rejects a token
 * request carrying no serial ("Device is not registered"), so a serial is
 * mandatory — see issueDeviceToken(). The device secret comes from that
 * hotel's own V5idFrontDeskHotelCredential row, never a shared/global
 * credential (an owner using a separate V5id integration ID per property
 * expects the V5id portal itself to only show that property's
 * verifications, which a shared credential would defeat). The token
 * itself is cached on whichever V5idFrontDeskScannerDevice row matches
 * (id_hotel, serial), if any — a serial with no such row (e.g. one typed
 * into "Test Connection" for a one-off check) still authenticates fine,
 * it's just never cached. The API base URL is a single system-wide
 * setting (V5IDFRONTDESK_API_BASE_URL) — just an endpoint address, not a
 * per-property credential, so it never varies by hotel.
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

class V5idApiClient
{
    /** Refresh the access token when fewer than this many seconds remain. */
    const TOKEN_EXPIRY_BUFFER = 60;

    /** @var int */
    private $idHotel;

    /** @var string Physical device serial this client authenticates as. May be '' when no scan/test supplied one. */
    private $serial;

    /** @var V5idFrontDeskHotelCredential|null Null when this hotel has no secret configured yet. */
    private $credential;

    /** @var V5idFrontDeskScannerDevice|null The paired device row backing $serial, if one exists — used as the token cache. */
    private $device;

    /** @var string */
    private $baseUrl;

    /** @var Module */
    private $moduleInstance;

    /**
     * @param int $idHotel
     * @param string $serial The physical scanner's serial number — required by the V5id API for
     *                       every token request. Pass '' only when no device identity is available
     *                       yet (getDeviceToken()/validateScan() will fail cleanly rather than call
     *                       the API with a request it's guaranteed to reject).
     */
    public function __construct($idHotel, $serial = '')
    {
        $this->idHotel = (int) $idHotel;
        $this->serial = trim((string) $serial);
        $this->credential = V5idFrontDeskHotelCredential::getForHotel($this->idHotel);
        $this->device = $this->serial !== '' ? V5idFrontDeskScannerDevice::findByHotelSerial($this->idHotel, $this->serial) : null;
        $this->baseUrl = rtrim((string) Configuration::get('V5IDFRONTDESK_API_BASE_URL'), '/');
        $this->moduleInstance = Module::getInstanceByName('v5idfrontdesk');
    }

    /**
     * Ensures a valid device access token is cached and returns it.
     *
     * @param bool $forceReauth When true, ignores any cached token and re-authenticates
     *                          from the stored secret (used by "Test connection").
     *
     * @return array{success: bool, access_token: ?string, message: string}
     */
    public function getDeviceToken($forceReauth = false)
    {
        if (!$this->credential || !$this->credential->device_secret) {
            return array(
                'success' => false,
                'access_token' => null,
                'message' => $this->l('No V5id secret is configured for this property yet. Set one up under Front Desk settings.'),
            );
        }

        if ($this->serial === '') {
            return array(
                'success' => false,
                'access_token' => null,
                'message' => $this->l('This scan didn\'t come from a device with a known serial number. Pair the scanner in Scanner Manager first.'),
            );
        }

        if (!$forceReauth && $this->device) {
            $cached = $this->getCachedAccessToken();
            if ($cached !== null) {
                return array('success' => true, 'access_token' => $cached, 'message' => '');
            }

            $refreshed = $this->refreshDeviceToken();
            if ($refreshed['success']) {
                return $refreshed;
            }
        }

        return $this->issueDeviceToken();
    }

    /**
     * Validates a decoded scan (PDF417 barcode or MRZ) against the V5id API.
     *
     * @param string $rawText The already-decoded scan text, exactly as the scanner emitted it.
     *
     * @return array Normalized result: valid, errors[], message, firstName, middleName,
     *               lastName, age, documentNumber, documentExpirationDate, address[].
     */
    public function validateScan($rawText)
    {
        $rawText = (string) $rawText;
        $format = (isset($rawText[0]) && $rawText[0] === '@') ? 'barcode' : 'mrz';

        $tokenResult = $this->getDeviceToken();
        if (!$tokenResult['success']) {
            return $this->errorResult($format, $tokenResult['message']);
        }

        $response = $this->callValidate($rawText, $format, $tokenResult['access_token']);

        // Access token expired/invalid mid-flight: force a fresh one and retry once.
        if ($response['http_code'] === 401) {
            $retryToken = $this->issueDeviceToken();
            if (!$retryToken['success']) {
                return $this->errorResult($format, $retryToken['message']);
            }
            $response = $this->callValidate($rawText, $format, $retryToken['access_token']);
        }

        // A connection-level failure (DNS/timeout/reset) is worth one quiet
        // retry before giving up — front desk staff shouldn't have to
        // re-scan for a transient network blip.
        if ($response['http_code'] === 0) {
            $response = $this->callValidate($rawText, $format, $tokenResult['access_token']);
        }

        if ($response['http_code'] === 403) {
            return $this->errorResult($format, $this->l('V5id rejected the device token for this operation. Re-check this property\'s device configuration.'));
        }

        if (!in_array($response['http_code'], array(200, 400), true) || $response['body'] === null) {
            $detail = !empty($response['curl_error']) ? $response['curl_error'] : sprintf($this->l('HTTP %d'), (int) $response['http_code']);
            return $this->errorResult($format, $this->l('V5id scan validation failed unexpectedly.').' ('.$detail.')');
        }

        $body = $response['body'];

        return array(
            'format' => $format,
            'valid' => !empty($body['valid']),
            'errors' => isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : array(),
            'message' => isset($body['message']) ? $body['message'] : '',
            'firstName' => isset($body['firstName']) ? $body['firstName'] : null,
            'middleName' => isset($body['middleName']) ? $body['middleName'] : null,
            'lastName' => isset($body['lastName']) ? $body['lastName'] : null,
            'age' => isset($body['age']) ? (int) $body['age'] : null,
            'documentNumber' => isset($body['documentNumber']) ? $body['documentNumber'] : null,
            'documentExpirationDate' => isset($body['documentExpirationDate']) ? $body['documentExpirationDate'] : null,
            'address' => isset($body['address']) && is_array($body['address']) ? $body['address'] : null,
        );
    }

    /**
     * @return string|null
     */
    private function getCachedAccessToken()
    {
        $token = $this->device->access_token;
        $expiresAt = (int) $this->device->token_expires_at;

        if ($token && $expiresAt > (time() + self::TOKEN_EXPIRY_BUFFER)) {
            return $token;
        }

        return null;
    }

    /**
     * @return array{success: bool, access_token: ?string, message: string}
     */
    private function refreshDeviceToken()
    {
        $refreshToken = $this->device->refresh_token;
        $refreshExpiresAt = (int) $this->device->refresh_expires_at;

        if (!$refreshToken || $refreshExpiresAt <= time()) {
            return array('success' => false, 'access_token' => null, 'message' => $this->l('No usable refresh token cached.'));
        }

        $response = $this->request(
            'POST',
            '/security/device/token/refresh',
            null,
            array('Authorization: Bearer '.$refreshToken)
        );

        if ($response['http_code'] !== 200 || $response['body'] === null) {
            return array('success' => false, 'access_token' => null, 'message' => $this->extractErrorMessage($response));
        }

        $this->device->storeTokenPair($response['body']);

        return array('success' => true, 'access_token' => $response['body']['access_token'], 'message' => '');
    }

    /**
     * @return array{success: bool, access_token: ?string, message: string}
     */
    private function issueDeviceToken()
    {
        $secret = $this->credential->device_secret;

        // The V5id API requires both fields — a request with no
        // serialNumber is rejected outright ("Device is not registered"),
        // confirming a token is issued (and scoped) per device, not per
        // property. $this->serial has already been checked non-empty by
        // getDeviceToken() before this is ever called.
        $response = $this->request(
            'POST',
            '/security/device/token',
            array('serialNumber' => $this->serial, 'secret' => $secret)
        );

        if ($response['http_code'] !== 200 || $response['body'] === null) {
            return array('success' => false, 'access_token' => null, 'message' => $this->extractErrorMessage($response));
        }

        // Only a serial with a matching paired-device row has anywhere to
        // cache the token — see this class's docblock. A "Test Connection"
        // serial that isn't paired to anything still authenticates fine,
        // it's just re-issued every time rather than cached.
        if ($this->device) {
            $this->device->storeTokenPair($response['body']);
        }

        return array('success' => true, 'access_token' => $response['body']['access_token'], 'message' => '');
    }

    /**
     * @param string $rawText
     * @param string $format 'barcode'|'mrz'
     * @param string $accessToken
     *
     * @return array{http_code: int, body: ?array}
     */
    private function callValidate($rawText, $format, $accessToken)
    {
        // Always send the literal first character of the payload, per the
        // API contract — rather than relying on "no header falls back to
        // MRZ", which left the header off entirely on the MRZ path.
        $headers = array(
            'Authorization: Bearer '.$accessToken,
            'X-Body-Code-1: '.substr($rawText, 0, 1),
        );

        return $this->request('POST', '/device/validate', array('barcode' => $rawText), $headers);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array|null $jsonBody
     * @param string[] $extraHeaders
     *
     * @return array{http_code: int, body: ?array}
     */
    private function request($method, $path, $jsonBody = null, array $extraHeaders = array())
    {
        $url = $this->baseUrl.$path;
        $headers = array_merge(array('Content-Type: application/json', 'Accept: application/json'), $extraHeaders);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        }

        $raw = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno) {
            return array('http_code' => 0, 'body' => null, 'curl_error' => $curlError);
        }

        $body = null;
        if ($raw !== '' && $raw !== false) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $body = $decoded;
            }
        }

        return array('http_code' => $httpCode, 'body' => $body, 'curl_error' => null);
    }

    /**
     * @param array $response
     *
     * @return string
     */
    private function extractErrorMessage(array $response)
    {
        if (!empty($response['curl_error'])) {
            return $response['curl_error'];
        }

        $body = $response['body'];
        if (is_array($body)) {
            foreach (array('Message', 'message') as $key) {
                if (!empty($body[$key])) {
                    return $body[$key];
                }
            }
        }

        return sprintf($this->l('Unexpected response (HTTP %d).'), (int) $response['http_code']);
    }

    /**
     * @param string $format
     * @param string $message
     *
     * @return array
     */
    private function errorResult($format, $message)
    {
        return array(
            'format' => $format,
            'valid' => false,
            'errors' => array(),
            'message' => $message,
            'firstName' => null,
            'middleName' => null,
            'lastName' => null,
            'age' => null,
            'documentNumber' => null,
            'documentExpirationDate' => null,
            'address' => null,
        );
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function l($string)
    {
        return $this->moduleInstance->l($string, 'V5idApiClient');
    }
}
