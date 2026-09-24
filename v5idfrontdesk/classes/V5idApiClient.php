<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * Thin client for the V5id device-scan validation API.
 *
 * Scope is intentionally limited to the standalone device-scan flow:
 *   POST /device/validate, with a device access token from V5id's
 *   Authorization Code + PKCE sign-in (see V5idDeviceOAuth).
 * The richer /verifications/* flow (document images, face liveness) is out of scope.
 *
 * A client is scoped to one hotel and one physical device's serial. V5id
 * signs in each serial separately, so the serial must belong to a scanner
 * paired at this hotel in Scanner Manager and signed in there — its
 * V5idFrontDeskScannerDevice row holds the session. A scan from an
 * unpaired or signed-out device fails cleanly here, with a message saying
 * what staff should do, rather than reaching the API. The API base URL is
 * a single system-wide setting (V5IDFRONTDESK_API_BASE_URL).
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
    /** @var int */
    private $idHotel;

    /** @var string Physical device serial this client validates as. May be '' for a keyboard-wedge scan. */
    private $serial;

    /** @var V5idFrontDeskScannerDevice|null The paired device row backing $serial, if one exists — it holds the V5id session. */
    private $device;

    /** @var string */
    private $baseUrl;

    /** @var Module */
    private $moduleInstance;

    /**
     * @param int $idHotel
     * @param string $serial The physical scanner's serial number. Pass '' only when no device
     *                       identity is available (validateScan() then fails cleanly rather than
     *                       calling the API with a request it's guaranteed to reject).
     */
    public function __construct($idHotel, $serial = '')
    {
        $this->idHotel = (int) $idHotel;
        $this->serial = trim((string) $serial);
        $this->device = $this->serial !== '' ? V5idFrontDeskScannerDevice::findByHotelSerial($this->idHotel, $this->serial) : null;
        $this->baseUrl = rtrim((string) Configuration::get('V5IDFRONTDESK_API_BASE_URL'), '/');
        $this->moduleInstance = Module::getInstanceByName('v5idfrontdesk');
    }

    /**
     * Validates a decoded scan (PDF417 barcode or MRZ) against the V5id API.
     *
     * @param string $rawText The already-decoded scan text, exactly as the scanner emitted it.
     *
     * @return array Normalized result: valid, errors[], message, signInRequired, firstName,
     *               middleName, lastName, age, documentNumber, documentExpirationDate, address[].
     */
    public function validateScan($rawText)
    {
        $rawText = (string) $rawText;
        // An AAMVA barcode normally opens with the '@' compliance
        // indicator, but some units strip the leading control bytes and
        // hand over a payload starting at the ANSI marker (see
        // V5idScannerSupport.extractIdPayload) — that is still a barcode,
        // and logging it as an MRZ misreports what was scanned.
        $format = (isset($rawText[0]) && ($rawText[0] === '@' || strncmp($rawText, 'ANSI', 4) === 0)) ? 'barcode' : 'mrz';

        if (!$this->device) {
            return $this->errorResult($format, $this->serial === ''
                ? $this->l('This scan didn\'t come from a device with a known serial number. Pair the scanner in Scanner Manager first.')
                : $this->l('This scanner isn\'t paired at this property. Pair it in Scanner Manager first.'));
        }

        $tokenResult = V5idDeviceOAuth::getAccessToken($this->device);
        if (!$tokenResult['success']) {
            return $this->errorResult($format, $tokenResult['message'], $tokenResult['sign_in_required']);
        }

        $response = $this->callValidate($rawText, $tokenResult['access_token']);

        // A connection-level failure (DNS/timeout/reset) is worth one quiet
        // retry before giving up — front desk staff shouldn't have to
        // re-scan for a transient network blip.
        if ($response['http_code'] === 0) {
            $response = $this->callValidate($rawText, $tokenResult['access_token']);
        }

        // Access token expired or revoked mid-flight: renew it and retry once.
        if ($response['http_code'] === 401) {
            $retryToken = V5idDeviceOAuth::getAccessToken($this->device, $tokenResult['access_token']);
            if (!$retryToken['success']) {
                return $this->errorResult($format, $retryToken['message'], $retryToken['sign_in_required']);
            }
            $response = $this->callValidate($rawText, $retryToken['access_token']);
        }

        if ($response['http_code'] === 401) {
            V5idFrontDeskScannerDevice::clearSession($this->device->id);

            return $this->errorResult($format, sprintf($this->l('V5id no longer accepts the sign-in for "%s". Open Scanner Manager and sign it in again.'), $this->device->label), true);
        }

        if ($response['http_code'] === 403) {
            return $this->errorResult($format, $this->l('V5id rejected the device token for this operation. Re-check this scanner\'s registration in the V5id portal.'));
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
            'signInRequired' => false,
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
     * @param string $rawText
     * @param string $accessToken
     *
     * @return array{http_code: int, body: ?array}
     */
    private function callValidate($rawText, $accessToken)
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
     * @param string $format
     * @param string $message
     * @param bool $signInRequired Tells the board to point staff at Scanner Manager's "Sign in".
     *
     * @return array
     */
    private function errorResult($format, $message, $signInRequired = false)
    {
        return array(
            'format' => $format,
            'valid' => false,
            'errors' => array(),
            'message' => $message,
            'signInRequired' => (bool) $signInRequired,
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
