<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * V5id device sign-in: OAuth 2.0 Authorization Code + PKCE (S256).
 *
 * A device session is started by staff from Scanner Manager, one paired
 * scanner at a time:
 *   1. startAuthorization() creates a single-use transaction (state + PKCE
 *      verifier, both generated and kept server-side) and returns the
 *      V5id /oauth/authorize URL for the browser to open in a popup.
 *   2. Staff type the property's integration key into V5id's own form —
 *      the key never passes through QloApps at all.
 *   3. V5id redirects the popup to this module's front controller
 *      (controllers/front/oauthcallback.php), which hands the code and
 *      state to completeAuthorization(). The code is exchanged here,
 *      server-side, with the verifier the browser never saw.
 *
 * The resulting token pair is cached on the scanner's own
 * V5idFrontDeskScannerDevice row and never sent to the browser.
 * getAccessToken() renews it with the refresh token. V5id rotates refresh
 * tokens on every use and the presented one is dead the moment the reply
 * arrives, so two requests refreshing the same device at once would end
 * its session: every refresh runs under a per-device MySQL named lock,
 * re-reading the row once the lock is held.
 *
 * Each property is its own V5id integration: the OAuth client id is
 * "int-<integration uuid>" from V5idFrontDeskHotelCredential, and this
 * module's callback URL (redirectUri()) must be registered as a redirect
 * URL for that integration in the V5id portal.
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

class V5idDeviceOAuth
{
    /** The only scope V5id's device authorization endpoint accepts. */
    const SCOPE = 'device.access offline_access';

    /** How long staff have to finish signing in on V5id's page. */
    const TRANSACTION_TTL = 600;

    /** Renew the access token when fewer than this many seconds remain. */
    const TOKEN_EXPIRY_BUFFER = 60;

    /** V5id's own session ceiling; a larger expires_in is a malformed reply, not a long-lived token. */
    const MAX_EXPIRES_IN = 604800;

    /** Seconds to wait for another request that is already refreshing the same device. */
    const LOCK_TIMEOUT = 15;

    /** Front controller registered in V5idFrontDesk::$controllers. */
    const CALLBACK_CONTROLLER = 'oauthcallback';

    /** OAuth errors that mean the refresh token itself is gone, not that the server is struggling. */
    const SESSION_ENDING_ERRORS = array('invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_scope');

    const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    /**
     * The authorization server is the API gateway's own origin — the
     * /oauth/* endpoints sit at its root, not under the /api/v1 base path
     * the module's other calls use.
     *
     * @return string e.g. "https://api.v5id.net", or '' when the base URL isn't a usable absolute URL.
     */
    public static function authorizationOrigin()
    {
        $parts = parse_url((string) Configuration::get('V5IDFRONTDESK_API_BASE_URL'));
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $origin = Tools::strtolower($parts['scheme']).'://'.Tools::strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $origin .= ':'.(int) $parts['port'];
        }

        return $origin;
    }

    /**
     * The URL V5id sends the popup back to. Built with the shop's default
     * language rather than the current employee's: V5id compares it byte
     * for byte against the one registered in the portal, so it must not
     * change with whoever happens to be signing in.
     *
     * @return string
     */
    public static function redirectUri()
    {
        return Context::getContext()->link->getModuleLink(
            'v5idfrontdesk',
            self::CALLBACK_CONTROLLER,
            array(),
            true,
            (int) Configuration::get('PS_LANG_DEFAULT')
        );
    }

    /**
     * Accepts an integration id as copied from the V5id portal — a bare
     * UUID, or the full "int-<uuid>" client id — and returns the bare,
     * lowercase UUID V5id's client id pattern requires.
     *
     * @param string $value
     *
     * @return string|null Null when it isn't a UUID at all.
     */
    public static function normalizeIntegrationId($value)
    {
        $value = Tools::strtolower(trim((string) $value));
        if (strpos($value, 'int-') === 0) {
            $value = Tools::substr($value, 4);
        }

        return preg_match(self::UUID_PATTERN, $value) ? $value : null;
    }

    /**
     * @param int $idHotel
     *
     * @return string|null "int-<uuid>", or null when this property has no integration configured.
     */
    public static function clientIdForHotel($idHotel)
    {
        $credential = V5idFrontDeskHotelCredential::getForHotel($idHotel);
        if (!$credential || !$credential->integration_id) {
            return null;
        }

        return 'int-'.$credential->integration_id;
    }

    // -----------------------------------------------------------------
    // Sign-in
    // -----------------------------------------------------------------

    /**
     * @param V5idFrontDeskScannerDevice $device Already access-checked by the caller.
     * @param int $idEmployee
     *
     * @return array{success: bool, authorize_url: ?string, message: string}
     */
    public static function startAuthorization(V5idFrontDeskScannerDevice $device, $idEmployee)
    {
        $origin = self::authorizationOrigin();
        if ($origin === '') {
            return self::startFailed(self::l('The V5id API base URL is not set. Set it under Front Desk settings.'));
        }

        $clientId = self::clientIdForHotel($device->id_hotel);
        if ($clientId === null) {
            return self::startFailed(self::l('No V5id integration is configured for this property yet. Set one up under Front Desk settings.'));
        }

        $redirectUri = self::redirectUri();
        $state = self::randomBase64Url(32);
        $verifier = self::randomBase64Url(64);

        V5idFrontDeskOAuthTransaction::purgeExpired();
        if (!V5idFrontDeskOAuthTransaction::create($state, $verifier, $device, $idEmployee, $clientId, $redirectUri, time() + self::TRANSACTION_TTL)) {
            return self::startFailed(self::l('Could not start the V5id sign-in. Please try again.'));
        }

        $query = http_build_query(array(
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'serial_number' => $device->serial,
        ), '', '&', PHP_QUERY_RFC3986);

        return array('success' => true, 'authorize_url' => $origin.'/oauth/authorize?'.$query, 'message' => '');
    }

    /**
     * Finishes a sign-in from the parameters V5id redirected the popup
     * back with.
     *
     * @param array $params The callback's query parameters (state, code, error, error_description, iss).
     *
     * @return array{success: bool, message: string}
     */
    public static function completeAuthorization(array $params)
    {
        $state = isset($params['state']) ? (string) $params['state'] : '';
        $transaction = $state !== '' ? V5idFrontDeskOAuthTransaction::consume($state) : null;

        // Unknown, expired or already used: either way nothing here may be
        // trusted, including any error V5id reported — it could belong to
        // someone else's sign-in.
        if (!$transaction) {
            return self::completeFailed(self::l('This sign-in link has expired or was already used. Start the sign-in again from Scanner Manager.'));
        }

        // RFC 9207: V5id names itself on every reply, so a code minted by
        // any other server can't be replayed into this exchange.
        if (isset($params['iss']) && rtrim((string) $params['iss'], '/') !== self::authorizationOrigin()) {
            return self::completeFailed(self::l('The sign-in reply did not come from the configured V5id server.'));
        }

        if (!empty($params['error'])) {
            return self::completeFailed(self::describeAuthorizationError((string) $params['error']));
        }

        $code = isset($params['code']) ? (string) $params['code'] : '';
        if ($code === '') {
            return self::completeFailed(self::l('V5id did not return an authorization. Please try again.'));
        }

        $device = new V5idFrontDeskScannerDevice((int) $transaction['id_device']);
        if (!Validate::isLoadedObject($device)
            || (int) $device->id_hotel !== (int) $transaction['id_hotel']
            || $device->serial !== $transaction['serial']
        ) {
            return self::completeFailed(self::l('This scanner was removed or re-paired while signing in. Start the sign-in again from Scanner Manager.'));
        }

        $response = self::tokenRequest(array(
            'grant_type' => 'authorization_code',
            'client_id' => $transaction['client_id'],
            'code' => $code,
            'redirect_uri' => $transaction['redirect_uri'],
            'code_verifier' => $transaction['code_verifier'],
        ));

        if (!$response['success']) {
            return self::completeFailed($response['message']);
        }

        // Signing in again replaces a session that may still be alive; end
        // the old one rather than leave it redeemable for another week.
        $previous = V5idFrontDeskScannerDevice::getTokenState($device->id);
        if ($previous && $previous['refresh_token']) {
            self::revoke($previous['refresh_token'], $transaction['client_id']);
        }

        V5idFrontDeskScannerDevice::storeSession($device->id, $response['body'], true);

        return array('success' => true, 'message' => sprintf(self::l('"%s" is signed in to V5id.'), $device->label));
    }

    /**
     * Ends a device's V5id session: revoked at V5id (best effort) and
     * forgotten here.
     *
     * @param V5idFrontDeskScannerDevice $device
     *
     * @return void
     */
    public static function signOut(V5idFrontDeskScannerDevice $device)
    {
        $state = V5idFrontDeskScannerDevice::getTokenState($device->id);
        $clientId = self::clientIdForHotel($device->id_hotel);

        V5idFrontDeskScannerDevice::clearSession($device->id);

        if ($state && $state['refresh_token'] && $clientId !== null) {
            self::revoke($state['refresh_token'], $clientId);
        }
    }

    /**
     * Ends every device session at one property — used when its
     * integration changes, since those sessions belong to the old one.
     *
     * @param int $idHotel
     *
     * @return void
     */
    public static function signOutHotel($idHotel)
    {
        foreach (V5idFrontDeskScannerDevice::getForHotel($idHotel) ?: array() as $row) {
            $device = new V5idFrontDeskScannerDevice((int) $row['id']);
            if (Validate::isLoadedObject($device)) {
                self::signOut($device);
            }
        }
    }

    // -----------------------------------------------------------------
    // Access tokens
    // -----------------------------------------------------------------

    /**
     * Returns a usable access token for the device, renewing it first if
     * needed.
     *
     * @param V5idFrontDeskScannerDevice $device
     * @param string|null $rejectedToken An access token V5id just answered 401 to — renew even if it
     *                                   looks unexpired, unless another request already replaced it.
     *
     * @return array{success: bool, access_token: ?string, message: string, sign_in_required: bool}
     */
    public static function getAccessToken(V5idFrontDeskScannerDevice $device, $rejectedToken = null)
    {
        $state = V5idFrontDeskScannerDevice::getTokenState($device->id);
        if ($state && self::isUsable($state, $rejectedToken)) {
            return self::tokenOk($state['access_token']);
        }

        if (!$state || !$state['refresh_token']) {
            return self::signInRequired($device);
        }

        $lock = self::lockName($device->id);
        if (!(int) Db::getInstance()->getValue('SELECT GET_LOCK("'.pSQL($lock).'", '.(int) self::LOCK_TIMEOUT.')', false)) {
            return self::tokenFailed(self::l('V5id sign-in is busy for this scanner. Scan again in a moment.'));
        }

        try {
            // Whoever held the lock before us may have renewed it already;
            // presenting the refresh token they just spent would end the session.
            $state = V5idFrontDeskScannerDevice::getTokenState($device->id);
            if ($state && self::isUsable($state, $rejectedToken)) {
                return self::tokenOk($state['access_token']);
            }
            if (!$state || !$state['refresh_token']) {
                return self::signInRequired($device);
            }

            $clientId = self::clientIdForHotel($device->id_hotel);
            if ($clientId === null) {
                return self::tokenFailed(self::l('No V5id integration is configured for this property yet. Set one up under Front Desk settings.'));
            }

            $response = self::tokenRequest(array(
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'refresh_token' => $state['refresh_token'],
            ));

            if ($response['success'] && $response['body']['refresh_token'] === $state['refresh_token']) {
                $response = array('success' => false, 'session_ended' => true, 'message' => self::l('V5id returned an unexpected sign-in reply.'));
            }

            if (!$response['success']) {
                if ($response['session_ended']) {
                    V5idFrontDeskScannerDevice::clearSession($device->id);

                    return self::signInRequired($device);
                }

                return self::tokenFailed($response['message']);
            }

            V5idFrontDeskScannerDevice::storeSession($device->id, $response['body'], false);

            return self::tokenOk($response['body']['access_token']);
        } finally {
            Db::getInstance()->getValue('SELECT RELEASE_LOCK("'.pSQL($lock).'")', false);
        }
    }

    // -----------------------------------------------------------------
    // Diagnostics (module settings → "Test connection")
    // -----------------------------------------------------------------

    /**
     * Checks that the V5id authorization server answers, and — when this
     * property has an integration — that V5id accepts both its client id
     * and this module's redirect URL. That second check is what catches the
     * most likely setup mistake: a redirect URL not yet added to the
     * integration in the V5id portal.
     *
     * @param int $idHotel
     *
     * @return array{success: bool, message: string}
     */
    public static function checkConfiguration($idHotel)
    {
        $origin = self::authorizationOrigin();
        if ($origin === '') {
            return array('success' => false, 'message' => self::l('The V5id API base URL is not a valid absolute URL.'));
        }

        $discovery = self::request('GET', $origin.'/.well-known/oauth-authorization-server');
        if ($discovery['http_code'] !== 200 || !is_array($discovery['body'])) {
            return array('success' => false, 'message' => sprintf(self::l('The V5id sign-in server at %s did not answer (%s).'), $origin, self::describeTransport($discovery)));
        }
        if (!isset($discovery['body']['issuer']) || rtrim($discovery['body']['issuer'], '/') !== $origin) {
            return array('success' => false, 'message' => sprintf(self::l('%s answered, but not as a V5id sign-in server.'), $origin));
        }

        $clientId = self::clientIdForHotel($idHotel);
        if ($clientId === null) {
            return array('success' => false, 'message' => self::l('The V5id sign-in server answers, but no integration ID is saved for this property yet.'));
        }

        // A throwaway authorization request: V5id vets client_id and
        // redirect_uri before anything else and answers 400 when either is
        // wrong. Anything else means both were accepted — the made-up serial
        // and challenge are never used, since no one completes this request.
        $probe = self::request('GET', $origin.'/oauth/authorize?'.http_build_query(array(
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::redirectUri(),
            'scope' => self::SCOPE,
            'state' => self::randomBase64Url(16),
            'code_challenge' => self::base64Url(hash('sha256', self::randomBase64Url(32), true)),
            'code_challenge_method' => 'S256',
            'serial_number' => 'qloapps-connection-test',
        ), '', '&', PHP_QUERY_RFC3986), null, true);

        if ($probe['http_code'] === 400) {
            $description = self::extractErrorDescription($probe['raw']);
            if (stripos($description, 'redirect_uri') !== false) {
                return array('success' => false, 'message' => self::l('V5id does not accept this module\'s callback URL for this integration. Add the Redirect URL shown below to the integration in the V5id portal.'));
            }
            if (stripos($description, 'client_id') !== false) {
                return array('success' => false, 'message' => self::l('V5id does not recognize this integration ID. Copy it again from the V5id portal.'));
            }

            return array('success' => false, 'message' => sprintf(self::l('V5id rejected the sign-in request: %s'), $description !== '' ? $description : 'HTTP 400'));
        }

        if (!in_array($probe['http_code'], array(200, 302, 303), true)) {
            return array('success' => false, 'message' => sprintf(self::l('V5id sign-in check failed (%s).'), self::describeTransport($probe)));
        }

        return array('success' => true, 'message' => self::l('V5id accepts this integration and its redirect URL. Sign each scanner in from Scanner Manager.'));
    }

    // -----------------------------------------------------------------
    // Protocol helpers
    // -----------------------------------------------------------------

    /**
     * @param array $form
     *
     * @return array{success: bool, body?: array, message: string, session_ended: bool}
     */
    private static function tokenRequest(array $form)
    {
        $response = self::request('POST', self::authorizationOrigin().'/oauth/token', $form);
        $body = $response['body'];

        if ($response['http_code'] === 200 && self::isValidTokenResponse($body)) {
            return array('success' => true, 'body' => $body, 'message' => '', 'session_ended' => false);
        }

        if ($response['http_code'] === 200) {
            return array('success' => false, 'message' => self::l('V5id returned an unexpected sign-in reply.'), 'session_ended' => false);
        }

        $error = is_array($body) && isset($body['error']) ? (string) $body['error'] : '';
        // RFC 6749 §5.2: invalid_client may come back as 401 rather than 400
        // (V5id does exactly that for an unknown client id).
        $sessionEnded = in_array($response['http_code'], array(400, 401), true) && in_array($error, self::SESSION_ENDING_ERRORS, true);

        if ($response['http_code'] === 0 || $response['http_code'] >= 500 || $response['http_code'] === 429) {
            $message = sprintf(self::l('The V5id sign-in server is not answering (%s). Try again in a moment.'), self::describeTransport($response));
        } elseif ($error === 'invalid_grant') {
            $message = self::l('The V5id sign-in has expired or was already used.');
        } elseif ($error === 'invalid_client') {
            $message = self::l('V5id does not recognize this property\'s integration ID. Check it under Front Desk settings.');
        } else {
            $message = sprintf(self::l('V5id refused the sign-in (%s).'), $error !== '' ? $error : 'HTTP '.$response['http_code']);
        }

        return array('success' => false, 'message' => $message, 'session_ended' => $sessionEnded);
    }

    /**
     * @param string $refreshToken
     * @param string $clientId
     *
     * @return void
     */
    private static function revoke($refreshToken, $clientId)
    {
        // Best effort: forgetting the token locally already ends the
        // session for this module, and V5id bounds whatever is left by the
        // token's own expiry.
        self::request('POST', self::authorizationOrigin().'/oauth/revoke', array(
            'token' => $refreshToken,
            'token_type_hint' => 'refresh_token',
            'client_id' => $clientId,
        ));
    }

    /**
     * @param mixed $body
     *
     * @return bool
     */
    private static function isValidTokenResponse($body)
    {
        return is_array($body)
            && self::isNonEmptyString($body, 'access_token')
            && self::isNonEmptyString($body, 'refresh_token')
            && self::isNonEmptyString($body, 'token_type')
            && Tools::strtolower(trim($body['token_type'])) === 'bearer'
            && isset($body['expires_in']) && is_int($body['expires_in'])
            && $body['expires_in'] > 0 && $body['expires_in'] <= self::MAX_EXPIRES_IN;
    }

    /**
     * @param array $body
     * @param string $key
     *
     * @return bool
     */
    private static function isNonEmptyString(array $body, $key)
    {
        return isset($body[$key]) && is_string($body[$key]) && trim($body[$key]) !== '';
    }

    /**
     * @param array $state Row from V5idFrontDeskScannerDevice::getTokenState().
     * @param string|null $rejectedToken
     *
     * @return bool
     */
    private static function isUsable(array $state, $rejectedToken)
    {
        if (!$state['access_token'] || (int) $state['token_expires_at'] <= time() + self::TOKEN_EXPIRY_BUFFER) {
            return false;
        }

        return $rejectedToken === null || !hash_equals($rejectedToken, $state['access_token']);
    }

    /**
     * MySQL named locks are server-wide, so the name carries this install's
     * own database and table prefix — two shops on one MySQL server must
     * never wait on each other's device ids.
     *
     * @param int $idDevice
     *
     * @return string
     */
    private static function lockName($idDevice)
    {
        return 'v5idfd_'.Tools::substr(md5(_DB_NAME_.'|'._DB_PREFIX_), 0, 16).'_'.(int) $idDevice;
    }

    /**
     * @param string $error RFC 6749 error code from the callback.
     *
     * @return string
     */
    private static function describeAuthorizationError($error)
    {
        switch ($error) {
            case 'access_denied':
                return self::l('V5id refused the sign-in. Check the integration key, and that this scanner\'s serial is registered to this property\'s integration in the V5id portal.');
            case 'temporarily_unavailable':
            case 'server_error':
                return self::l('The V5id sign-in server could not finish the sign-in. Please try again.');
            default:
                return sprintf(self::l('The V5id sign-in failed (%s).'), $error);
        }
    }

    /**
     * Pulls error_description out of V5id's 400 page, which may be JSON or
     * a small HTML/plain-text rendering of the same fields.
     *
     * @param string $raw
     *
     * @return string
     */
    private static function extractErrorDescription($raw)
    {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) && isset($decoded['error_description'])) {
            return (string) $decoded['error_description'];
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $raw)));
        if (preg_match('/error_description:?\s*(.+?)(?:\s+error_uri|$)/i', $text, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * @param array $response
     *
     * @return string
     */
    private static function describeTransport(array $response)
    {
        return !empty($response['curl_error']) ? $response['curl_error'] : 'HTTP '.(int) $response['http_code'];
    }

    /**
     * @param string $method
     * @param string $url
     * @param array|null $form Sent as application/x-www-form-urlencoded, as every /oauth/* endpoint expects.
     * @param bool $keepRaw Whether to return the raw body as well (for non-JSON error pages).
     *
     * @return array{http_code: int, body: ?array, raw: ?string, curl_error: ?string}
     */
    private static function request($method, $url, $form = null, $keepRaw = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Never follow redirects: /oauth/authorize answers with one aimed at
        // the hotel's own callback, and following it would be wrong.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        // Generous on purpose, as in V5id's own device pages: the sign-in
        // server can take a while to wake up, and a refresh abandoned after
        // V5id already rotated the token would end the device's session.
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&'));
        }

        $raw = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno) {
            return array('http_code' => 0, 'body' => null, 'raw' => null, 'curl_error' => $curlError);
        }

        $body = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $body = $decoded;
            }
        }

        return array('http_code' => $httpCode, 'body' => $body, 'raw' => $keepRaw ? (string) $raw : null, 'curl_error' => null);
    }

    /**
     * @param int $bytes
     *
     * @return string
     */
    private static function randomBase64Url($bytes)
    {
        return self::base64Url(random_bytes($bytes));
    }

    /**
     * @param string $binary
     *
     * @return string
     */
    private static function base64Url($binary)
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    // -----------------------------------------------------------------
    // Result shapes
    // -----------------------------------------------------------------

    private static function startFailed($message)
    {
        return array('success' => false, 'authorize_url' => null, 'message' => $message);
    }

    private static function completeFailed($message)
    {
        return array('success' => false, 'message' => $message);
    }

    private static function tokenOk($accessToken)
    {
        return array('success' => true, 'access_token' => $accessToken, 'message' => '', 'sign_in_required' => false);
    }

    private static function tokenFailed($message)
    {
        return array('success' => false, 'access_token' => null, 'message' => $message, 'sign_in_required' => false);
    }

    private static function signInRequired(V5idFrontDeskScannerDevice $device)
    {
        return array(
            'success' => false,
            'access_token' => null,
            'message' => sprintf(self::l('"%s" is not signed in to V5id. Open Scanner Manager and click "Sign in" on it.'), $device->label),
            'sign_in_required' => true,
        );
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private static function l($string)
    {
        return Module::getInstanceByName('v5idfrontdesk')->l($string, 'V5idDeviceOAuth');
    }
}
