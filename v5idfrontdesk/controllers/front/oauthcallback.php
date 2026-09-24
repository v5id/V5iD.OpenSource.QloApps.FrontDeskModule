<?php
/**
 * V5id Front Desk module for QloApps.
 *
 * Redirect URL of the V5id device sign-in (see V5idDeviceOAuth). V5id sends
 * the Scanner Manager's sign-in popup here with ?code=&state=&iss= (or
 * ?error=), and this page finishes the sign-in server-side, then tells the
 * opener it is done and closes itself.
 *
 * A front controller rather than an admin one: the URL must be registered
 * in the V5id portal byte for byte, and an admin URL carries both the
 * randomized admin folder and a per-employee token. No employee session is
 * needed here — the state must match a transaction an authenticated
 * employee started for a specific scanner a few minutes ago, it works
 * once, and the PKCE verifier that redeems the code never leaves the
 * server.
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

class V5idFrontDeskOauthcallbackModuleFrontController extends ModuleFrontController
{
    /** Matched by scanner-manager-app.js before it trusts a message from the popup. */
    const MESSAGE_TYPE = 'v5id-device-oauth-result';

    public $ssl = true;
    public $auth = false;
    public $guestAllowed = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        $result = V5idDeviceOAuth::completeAuthorization(array(
            'state' => Tools::getValue('state'),
            'code' => Tools::getValue('code'),
            'error' => Tools::getValue('error'),
            'iss' => Tools::getIsset('iss') ? Tools::getValue('iss') : null,
        ));

        // The URL still carries the (now redeemed) code: keep it out of
        // caches and out of any Referer header.
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Content-Type: text/html; charset=utf-8');

        $title = $result['success'] ? $this->module->l('Signed in to V5id', 'oauthcallback') : $this->module->l('V5id sign-in failed', 'oauthcallback');
        $hint = $this->module->l('You can close this window.', 'oauthcallback');

        // Only whether it worked and a message staff already see on this
        // page: nothing here is worth protecting from whichever window
        // opened it, and the Scanner Manager tab re-reads the scanner's real
        // status from the server either way.
        $payload = json_encode(array(
            'type' => self::MESSAGE_TYPE,
            'ok' => (bool) $result['success'],
            'message' => $result['message'],
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title>'
            .'<style>body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f8fafc;color:#1e293b}'
            .'main{max-width:420px;margin:48px auto;padding:24px;background:#fff;border:1px solid #e2e8f0;border-radius:10px}'
            .'h1{font-size:18px;margin:0 0 10px;color:'.($result['success'] ? '#16a34a' : '#b91c1c').'}'
            .'p{font-size:14px;line-height:1.5;margin:0 0 8px}.muted{color:#64748b;font-size:13px}</style>'
            .'</head><body><main>'
            .'<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>'
            .'<p>'.htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8').'</p>'
            .'<p class="muted">'.htmlspecialchars($hint, ENT_QUOTES, 'UTF-8').'</p>'
            .'</main><script>'
            .'(function(){var data='.$payload.';'
            .'try{history.replaceState(null,"",location.pathname);}catch(e){}'
            .'if(window.opener&&!window.opener.closed){try{window.opener.postMessage(data,"*");}catch(e){}'
            .'if(data.ok){setTimeout(function(){window.close();},1200);}}})();'
            .'</script></body></html>';
        exit;
    }
}
