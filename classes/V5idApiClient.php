<?php
/**
 * V5iD Front Desk module for QloApps.
 *
 * Thin client for the V5iD device-scan validation API.
 *
 * Scope is intentionally limited to the standalone device-scan flow:
 *   POST /security/device/token
 *   POST /security/device/token/refresh
 *   POST /device/validate
 * The richer /verifications/* flow (document images, face liveness) is out of scope.
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

    /** @var string */
    private $baseUrl;

    /** @var Module */
    private $moduleInstance;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) Configuration::get('V5IDFRONTDESK_API_BASE_URL'), '/');
        $this->moduleInstance = Module::getInstanceByName('v5idfrontdesk');
    }

    /**
     * Ensures a valid device access token is cached and returns it.
     *
     * @param bool $forceReauth When true, ignores any cached token and re-authenticates
     *                          from the stored serial/secret (used by "Test connection").
     *
     * @return array{success: bool, access_token: ?string, message: string}
     */
    public function getDeviceToken($forceReauth = false)
    {
        if (!$forceReauth) {
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
     * Validates a decoded scan (PDF417 barcode or MRZ) against the V5iD API.
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
            return $this->errorResult($format, $this->l('V5iD rejected the device token for this operation. Re-check the device configuration.'));
        }

        if (!in_array($response['http_code'], array(200, 400), true) || $response['body'] === null) {
            $detail = !empty($response['curl_error']) ? $response['curl_error'] : sprintf($this->l('HTTP %d'), (int) $response['http_code']);
            return $this->errorResult($format, $this->l('V5iD scan validation failed unexpectedly.').' ('.$detail.')');
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
        $token = Configuration::get('V5IDFRONTDESK_DEVICE_ACCESS_TOKEN');
        $expiresAt = (int) Configuration::get('V5IDFRONTDESK_DEVICE_TOKEN_EXPIRES_AT');

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
        $refreshToken = Configuration::get('V5IDFRONTDESK_DEVICE_REFRESH_TOKEN');
        $refreshExpiresAt = (int) Configuration::get('V5IDFRONTDESK_DEVICE_REFRESH_EXPIRES_AT');

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

        $this->storeTokenPair($response['body']);

        return array('success' => true, 'access_token' => $response['body']['access_token'], 'message' => '');
    }

    /**
     * @return array{success: bool, access_token: ?string, message: string}
     */
    private function issueDeviceToken()
    {
        $serial = Configuration::get('V5IDFRONTDESK_DEVICE_SERIAL');
        $secret = Configuration::get('V5IDFRONTDESK_DEVICE_SECRET');

        if (!$serial || !$secret) {
            return array('success' => false, 'access_token' => null, 'message' => $this->l('Device serial number and secret are not configured.'));
        }

        $response = $this->request(
            'POST',
            '/security/device/token',
            array('serialNumber' => $serial, 'secret' => $secret)
        );

        if ($response['http_code'] !== 200 || $response['body'] === null) {
            return array('success' => false, 'access_token' => null, 'message' => $this->extractErrorMessage($response));
        }

        $this->storeTokenPair($response['body']);

        return array('success' => true, 'access_token' => $response['body']['access_token'], 'message' => '');
    }

    /**
     * @param array $tokenResponse Decoded TokenResponse body.
     */
    private function storeTokenPair(array $tokenResponse)
    {
        $expiresIn = isset($tokenResponse['expires_in']) ? (int) $tokenResponse['expires_in'] : 0;

        Configuration::updateValue('V5IDFRONTDESK_DEVICE_ACCESS_TOKEN', $tokenResponse['access_token']);
        Configuration::updateValue('V5IDFRONTDESK_DEVICE_TOKEN_EXPIRES_AT', time() + $expiresIn);

        if (!empty($tokenResponse['refresh_token'])) {
            Configuration::updateValue('V5IDFRONTDESK_DEVICE_REFRESH_TOKEN', $tokenResponse['refresh_token']);
            Configuration::updateValue('V5IDFRONTDESK_DEVICE_REFRESH_EXPIRES_AT', time() + (7 * 86400));
        }
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
