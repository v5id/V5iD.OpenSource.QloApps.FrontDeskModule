<?php
/**
 * V5iD Front Desk module for QloApps.
 *
 * Room board, guest search, check-in/out, room swap and V5id-powered ID
 * scan verification.
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

require_once dirname(__FILE__).'/classes/V5idApiClient.php';
require_once dirname(__FILE__).'/classes/V5idFrontDeskScanLog.php';
require_once dirname(__FILE__).'/classes/V5idFrontDeskActivityLog.php';
require_once dirname(__FILE__).'/classes/V5idFrontDeskGuestLocator.php';
require_once dirname(__FILE__).'/classes/V5idFrontDeskProfileCheck.php';
require_once dirname(__FILE__).'/classes/V5idFrontDeskScannerDevice.php';
require_once dirname(__FILE__).'/classes/V5idFrontDeskHotelCredential.php';
require_once dirname(__FILE__).'/classes/V5idFrontDeskOAuthTransaction.php';
require_once dirname(__FILE__).'/classes/V5idDeviceOAuth.php';

class V5idFrontDesk extends Module
{
    const CONTROLLER_CLASS = 'AdminV5idFrontDesk';

    /**
     * Bump whenever createTables() gains a new table (or an existing one
     * needs a follow-up migration), so ensureTablesUpToDate() knows an
     * existing install needs another pass.
     * 1 = scan_log/activity_log/scanner_device, 2 = + hotel_credential
     * (V5id API credentials moved from one global set to one per hotel),
     * 3 = hotel_credential drops api_base_url/device_serial (API base URL
     * became a single system-wide setting — see
     * dropObsoleteCredentialColumns()), 4 = scanner_device gains its own
     * token cache columns (access_token etc.) — the V5id API actually
     * requires a device serial on every token request and rejects one
     * with none ("Device is not registered"), so a token is issued and
     * cached per physical device/serial, not per property (see
     * V5idApiClient and addScannerDeviceTokenColumns()), 5 =
     * hotel_credential drops its now-redundant access_token/refresh_token/
     * token_expires_at/refresh_expires_at columns, superseded by the
     * per-device cache added in 4 (see dropObsoleteCredentialColumns()),
     * 6 = V5id device sign-in moved to OAuth Authorization Code + PKCE:
     * hotel_credential swaps device_secret for integration_id,
     * scanner_device swaps refresh_expires_at for signed_in_at, and
     * oauth_transaction is added (see migrateToOAuth()).
     */
    const SCHEMA_VERSION = 6;

    /**
     * Known scanner protocol adapters. Each one needing an explicit pairing
     * step (as opposed to the always-on keyboard-wedge listener, which
     * needs no adapter at all) is registered here — this is the one place
     * to touch, both here and in the matching frontend adapter file under
     * views/js/scanners/, to add support for another scanner brand/protocol.
     *
     * @var array<string, array{label: string, js: string}>
     */
    const SCANNER_ADAPTERS = array(
        'inateck-ble' => array(
            'label' => 'Inateck Bluetooth Scanner (BLE)',
            'js' => 'views/js/scanners/inateck-ble-adapter.js',
        ),
        'magtek-hid' => array(
            'label' => 'MagTek Barcode Scanner (HID)',
            'js' => 'views/js/scanners/magtek-hid-adapter.js',
        ),
        'marson-ble' => array(
            'label' => 'Marson Bluetooth Scanner (MT810)',
            'js' => 'views/js/scanners/marson-ble-adapter.js',
        ),
        'tera-ble' => array(
            'label' => 'Tera Bluetooth Scanner (HW0009)',
            'js' => 'views/js/scanners/tera-ble-adapter.js',
        ),
    );

    /**
     * Global V5id credentials from before they became per-hotel, then
     * per-integration. Nothing reads them any more; migrateToOAuth()
     * deletes them so no stored key outlives the move to OAuth.
     *
     * @var string[]
     */
    const LEGACY_CONFIG_KEYS = array(
        'V5IDFRONTDESK_DEVICE_SERIAL',
        'V5IDFRONTDESK_DEVICE_SECRET',
        'V5IDFRONTDESK_DEVICE_ACCESS_TOKEN',
        'V5IDFRONTDESK_DEVICE_REFRESH_TOKEN',
        'V5IDFRONTDESK_DEVICE_TOKEN_EXPIRES_AT',
        'V5IDFRONTDESK_DEVICE_REFRESH_EXPIRES_AT',
        'V5IDFRONTDESK_CREDENTIALS_MIGRATED',
    );

    /** @var string[] Configuration keys removed on uninstall (plus LEGACY_CONFIG_KEYS). */
    private $configKeys = array(
        'V5IDFRONTDESK_API_BASE_URL',
        'V5IDFRONTDESK_ENABLED_SCANNERS',
        'V5IDFRONTDESK_SCAN_LOG_PII_PURGED',
        'V5IDFRONTDESK_TABLES_READY', // legacy — superseded by V5IDFRONTDESK_SCHEMA_VERSION, kept here only to clean up any leftover value
        'V5IDFRONTDESK_SCHEMA_VERSION',
    );

    public function __construct()
    {
        $this->name = 'v5idfrontdesk';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'V5iD, Inc.';
        $this->need_instance = 0;
        $this->bootstrap = true;
        // V5id device sign-in callback — see controllers/front/oauthcallback.php.
        $this->controllers = array(V5idDeviceOAuth::CALLBACK_CONTROLLER);

        parent::__construct();

        $this->displayName = $this->l('V5iD Front Desk');
        $this->description = $this->l('Room board, guest search, check-in/check-out, room swap and V5iD ID scan verification.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall V5iD Front Desk? Scan and activity logs recorded by this module will be deleted.');
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (!parent::install()
            || !$this->createTables()
            || !$this->installTab()
            || !$this->installDefaultConfig()
            || !$this->registerHook('displayBackOfficeHeader')
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        // Revoke every scanner's V5id session while the tables still say
        // which ones exist: dropping them only forgets the tokens here.
        // Brought up to date first, since the queries behind this expect
        // the current columns.
        $this->ensureTablesUpToDate();
        foreach ($this->getAllHotels() as $hotel) {
            V5idDeviceOAuth::signOutHotel((int) $hotel['id']);
        }

        if (!$this->uninstallTab()
            || !$this->deleteConfigVars()
            || !$this->dropTables()
            || !parent::uninstall()
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    private function createTables()
    {
        $queries = array(
            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'v5idfrontdesk_scan_log` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_employee` INT UNSIGNED NOT NULL,
                `id_hotel` INT UNSIGNED NOT NULL,
                `scan_format` VARCHAR(8) NOT NULL,
                `valid` TINYINT(1) NOT NULL DEFAULT 0,
                `error_codes` TEXT NULL,
                `age` SMALLINT UNSIGNED NULL,
                `document_number_last4` VARCHAR(4) NULL,
                `id_customer` INT UNSIGNED NULL,
                `id_order` INT UNSIGNED NULL,
                `id_hotel_booking_detail` INT UNSIGNED NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_hotel_date` (`id_hotel`, `date_add`),
                KEY `idx_booking` (`id_hotel_booking_detail`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;',

            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'v5idfrontdesk_activity_log` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_employee` INT UNSIGNED NOT NULL,
                `id_hotel_booking_detail` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `action_type` VARCHAR(16) NOT NULL,
                `from_id_room` INT UNSIGNED NULL,
                `to_id_room` INT UNSIGNED NULL,
                `note` VARCHAR(255) NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`id_hotel_booking_detail`),
                KEY `idx_order` (`id_order`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;',

            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'v5idfrontdesk_scanner_device` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_hotel` INT UNSIGNED NOT NULL,
                `adapter_id` VARCHAR(32) NOT NULL,
                `serial` VARCHAR(64) NOT NULL,
                `label` VARCHAR(64) NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `access_token` TEXT NULL,
                `refresh_token` TEXT NULL,
                `token_expires_at` INT UNSIGNED NULL,
                `signed_in_at` INT UNSIGNED NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_hotel_adapter_serial` (`id_hotel`, `adapter_id`, `serial`),
                KEY `idx_hotel` (`id_hotel`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;',

            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'v5idfrontdesk_hotel_credential` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_hotel` INT UNSIGNED NOT NULL,
                `integration_id` VARCHAR(36) NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_hotel` (`id_hotel`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;',

            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'v5idfrontdesk_oauth_transaction` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `state_hash` CHAR(64) NOT NULL,
                `code_verifier` VARCHAR(128) NOT NULL,
                `id_device` INT UNSIGNED NOT NULL,
                `id_hotel` INT UNSIGNED NOT NULL,
                `serial` VARCHAR(64) NOT NULL,
                `id_employee` INT UNSIGNED NOT NULL,
                `client_id` VARCHAR(64) NOT NULL,
                `redirect_uri` VARCHAR(512) NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_state_hash` (`state_hash`),
                KEY `idx_expires_at` (`expires_at`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;',
        );

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    private function dropTables()
    {
        $tables = array('v5idfrontdesk_scan_log', 'v5idfrontdesk_activity_log', 'v5idfrontdesk_scanner_device', 'v5idfrontdesk_hotel_credential', 'v5idfrontdesk_oauth_transaction');
        foreach ($tables as $table) {
            if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.bqSQL($table).'`')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    private function installDefaultConfig()
    {
        return Configuration::updateValue('V5IDFRONTDESK_API_BASE_URL', 'https://api.v5id.net/api/v1')
            && Configuration::updateValue('V5IDFRONTDESK_ENABLED_SCANNERS', json_encode(array()))
            && Configuration::updateValue('V5IDFRONTDESK_SCHEMA_VERSION', self::SCHEMA_VERSION);
    }

    /**
     * Self-heals table schema for installs that predate a newer table, or
     * a later column migration on an existing table (see
     * dropObsoleteCredentialColumns()) — createTables() is idempotent
     * (CREATE TABLE IF NOT EXISTS for each), so safe to call again on an
     * existing install. Gated by a version counter rather than a one-shot
     * boolean flag: a plain "tables are ready" flag would never run again
     * once set, even after a later change added yet another new table
     * (exactly what happened between v5idfrontdesk_scanner_device and
     * v5idfrontdesk_hotel_credential) or altered an existing one — bump
     * SCHEMA_VERSION whenever that happens, and every install below that
     * number picks up the difference on its next page load. See
     * AdminV5idFrontDeskController::setMedia() for the call site.
     *
     * @return void
     */
    public function ensureTablesUpToDate()
    {
        $installedVersion = (int) Configuration::get('V5IDFRONTDESK_SCHEMA_VERSION');
        if ($installedVersion >= self::SCHEMA_VERSION) {
            return;
        }

        if ($this->createTables()
            && $this->addScannerDeviceTokenColumns()
            && $this->migrateToOAuth($installedVersion)
            && $this->dropObsoleteCredentialColumns()
        ) {
            Configuration::updateValue('V5IDFRONTDESK_SCHEMA_VERSION', self::SCHEMA_VERSION);
        }
    }

    /**
     * Drops columns from `v5idfrontdesk_hotel_credential` that later
     * became obsolete: `api_base_url`/`device_serial` (the API base URL
     * became a single system-wide setting, and the device serial moved to
     * being supplied per scan rather than stored per property), and
     * `access_token`/`refresh_token`/`token_expires_at`/
     * `refresh_expires_at` (the V5id token cache moved onto the matching
     * V5idFrontDeskScannerDevice row once it turned out a token is issued
     * per device, not per property), and `device_secret` (the integration
     * key is now typed into V5id's own sign-in page, never stored here) —
     * see V5idFrontDeskHotelCredential's docblock. A no-op wherever
     * createTables() just created the table fresh (those columns were
     * never in it to begin with) or this has already run.
     *
     * @return bool
     */
    private function dropObsoleteCredentialColumns()
    {
        $columns = array(
            'api_base_url', 'device_serial',
            'access_token', 'refresh_token', 'token_expires_at', 'refresh_expires_at',
            'device_secret',
        );

        foreach ($columns as $column) {
            // A plain SELECT against information_schema rather than SHOW
            // COLUMNS ... LIKE: Db::getValue() always appends " LIMIT 1" to
            // whatever SQL it's given, and MySQL's SHOW COLUMNS syntax
            // doesn't accept a LIMIT clause at all — that combination is a
            // syntax error, not just redundant.
            $exists = Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = "'._DB_PREFIX_.'v5idfrontdesk_hotel_credential"
                    AND COLUMN_NAME = "'.pSQL($column).'"'
            );
            if ($exists && !Db::getInstance()->execute(
                'ALTER TABLE `'._DB_PREFIX_.'v5idfrontdesk_hotel_credential` DROP COLUMN `'.bqSQL($column).'`'
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adds the V5id device token cache columns (access_token etc.) to
     * `v5idfrontdesk_scanner_device` on installs that predate a token
     * being cached per physical device — see V5idApiClient's docblock for
     * why (the V5id API requires a device serial on every token request).
     * A no-op wherever createTables() just created the table fresh (those
     * columns are already in it) or this has already run.
     *
     * @return bool
     */
    private function addScannerDeviceTokenColumns()
    {
        $columns = array(
            'access_token' => 'TEXT NULL',
            'refresh_token' => 'TEXT NULL',
            'token_expires_at' => 'INT UNSIGNED NULL',
            'signed_in_at' => 'INT UNSIGNED NULL',
        );

        foreach ($columns as $column => $definition) {
            $exists = Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = "'._DB_PREFIX_.'v5idfrontdesk_scanner_device"
                    AND COLUMN_NAME = "'.pSQL($column).'"'
            );
            if (!$exists && !Db::getInstance()->execute(
                'ALTER TABLE `'._DB_PREFIX_.'v5idfrontdesk_scanner_device` ADD COLUMN `'.bqSQL($column).'` '.$definition
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Moves an existing install onto V5id's OAuth device sign-in: every
     * property gets an (empty) integration_id to fill in, the old key-based
     * token pairs are dropped — they were never OAuth sessions and cannot
     * be renewed through /oauth/token — and every stored key goes with them
     * (the integration key is only ever typed into V5id's own page now).
     * Each scanner then needs signing in once from Scanner Manager.
     * A no-op on a fresh install, where createTables() already made the
     * new columns and there is nothing old to clear.
     *
     * @param int $installedVersion SCHEMA_VERSION this install was on before this pass.
     *
     * @return bool
     */
    private function migrateToOAuth($installedVersion)
    {
        if (!$this->columnExists('v5idfrontdesk_hotel_credential', 'integration_id')
            && !Db::getInstance()->execute(
                'ALTER TABLE `'._DB_PREFIX_.'v5idfrontdesk_hotel_credential` ADD COLUMN `integration_id` VARCHAR(36) NULL'
            )
        ) {
            return false;
        }

        if ($this->columnExists('v5idfrontdesk_scanner_device', 'refresh_expires_at')
            && !Db::getInstance()->execute(
                'ALTER TABLE `'._DB_PREFIX_.'v5idfrontdesk_scanner_device` DROP COLUMN `refresh_expires_at`'
            )
        ) {
            return false;
        }

        if ($installedVersion < 6
            && !Db::getInstance()->execute(
                'UPDATE `'._DB_PREFIX_.'v5idfrontdesk_scanner_device`
                SET access_token = NULL, refresh_token = NULL, token_expires_at = 0, signed_in_at = NULL'
            )
        ) {
            return false;
        }

        foreach (self::LEGACY_CONFIG_KEYS as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * A plain SELECT against information_schema rather than SHOW COLUMNS
     * ... LIKE — see dropObsoleteCredentialColumns() for why.
     *
     * @param string $table Without the prefix.
     * @param string $column
     *
     * @return bool
     */
    private function columnExists($table, $column)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = "'.pSQL(_DB_PREFIX_.$table).'"
                AND COLUMN_NAME = "'.pSQL($column).'"',
            false
        );
    }

    /**
     * @return string[] Ids of the scanner adapters currently enabled, from
     *                   V5idFrontDesk::SCANNER_ADAPTERS.
     */
    public static function getEnabledScannerAdapters()
    {
        $enabled = json_decode((string) Configuration::get('V5IDFRONTDESK_ENABLED_SCANNERS'), true);
        if (!is_array($enabled)) {
            return array();
        }

        return array_values(array_intersect($enabled, array_keys(self::SCANNER_ADAPTERS)));
    }

    /**
     * @return bool
     */
    private function deleteConfigVars()
    {
        foreach (array_merge($this->configKeys, self::LEGACY_CONFIG_KEYS) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * Registers the "Front Desk" top-level admin tab.
     *
     * @return bool
     */
    private function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = self::CONTROLLER_CLASS;
        $tab->name = array();

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Front Desk';
        }

        $tab->id_parent = 0;
        $tab->module = $this->name;
        // Not read by this theme's left nav (see hookDisplayBackOfficeHeader()
        // for what actually puts an icon there) — kept in case anything else
        // (tab search, a future theme) does look at it.
        $tab->icon = 'contact_phone';

        return (bool) $tab->add();
    }

    /**
     * @return bool
     */
    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName(self::CONTROLLER_CLASS);
        if ($idTab) {
            $tab = new Tab($idTab);
            return (bool) $tab->delete();
        }

        return true;
    }

    /**
     * QloApps' 1.6-style admin theme only shows a nav icon for a top-level
     * tab when a `.icon-{ControllerClassName}` CSS rule with actual glyph
     * content exists (see admin/themes/default/sass/partials/_icons.sass) —
     * core controllers get one there, but a module's own tab has nothing to
     * extend, and Tab::$icon (set in installTab()) isn't read by this
     * template at all. Rather than touching that core theme file, this hook
     * loads a small script that adds an already-defined Font Awesome class
     * onto our tab's <i> element so the existing rule supplies the glyph.
     *
     * @return void
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (!isset($this->context->controller) || !($this->context->controller instanceof AdminController)) {
            return;
        }

        $this->context->controller->addJS($this->assetUri('views/js/admin-nav-icon.js'));
    }

    /**
     * Module asset URI with a filemtime-based cache-busting query string, so
     * browsers pick up JS/CSS changes immediately after a deploy instead of
     * serving a stale cached copy. Lives here rather than only on
     * AdminV5idFrontDeskController so assets queued from a hook get it too.
     *
     * @param string $relativePath
     *
     * @return string
     */
    public function assetUri($relativePath)
    {
        $localFile = $this->getLocalPath().$relativePath;
        $version = is_file($localFile) ? filemtime($localFile) : $this->version;

        return $this->_path.$relativePath.'?v='.$version;
    }

    /**
     * Module configuration page: V5iD integration per property + connection test.
     *
     * @return string
     */
    public function getContent()
    {
        // Self-healing for installs that predate the displayBackOfficeHeader
        // hook (added after this module's initial install() already ran on
        // existing environments) — registers it the next time an admin
        // happens to open this settings page, rather than requiring a
        // reinstall (which would drop the scan/activity log tables).
        if (!$this->isRegisteredInHook('displayBackOfficeHeader')) {
            $this->registerHook('displayBackOfficeHeader');
        }

        // This page can be the very first one an admin opens after an
        // upgrade — AdminV5idFrontDeskController::setMedia() never runs in
        // that case, so the same self-heals need to happen here too.
        $this->ensureTablesUpToDate();

        $idHotel = $this->getSelectedHotelId();

        $output = '';

        // HelperForm always injects a hidden `<submit_action>=1` field, so
        // submitV5idFrontDeskSettings is present on every submit regardless
        // of which button was clicked — the more specific button must be
        // checked first.
        if (Tools::isSubmit('submitV5idFrontDeskTestConnection')) {
            // Save before testing: the check reads the system-wide base URL
            // and this hotel's stored integration, not this request's POST
            // data, so testing without saving first would silently re-test
            // whatever was already saved — not whatever was just typed.
            $saveResult = $this->processSettingsSubmit($idHotel);
            $output .= $saveResult['html'];
            if ($saveResult['success']) {
                $output .= $this->processTestConnection($idHotel);
            }
        } elseif (Tools::isSubmit('submitV5idFrontDeskSettings')) {
            $output .= $this->processSettingsSubmit($idHotel)['html'];
        }

        return $output.$this->renderSettingsForm($idHotel);
    }

    /**
     * @return int Id of the hotel currently selected for credential
     *             editing — from ?id_hotel=, falling back to the first
     *             hotel in the system. 0 if there are no hotels at all.
     */
    private function getSelectedHotelId()
    {
        $requested = (int) Tools::getValue('id_hotel');
        $hotels = $this->getAllHotels();

        foreach ($hotels as $hotel) {
            if ((int) $hotel['id'] === $requested) {
                return $requested;
            }
        }

        return $hotels ? (int) $hotels[0]['id'] : 0;
    }

    /**
     * Every hotel in the system, not just the ones the current employee
     * happens to be assigned to — this is a superadmin-level configuration
     * page, unlike the front desk board's own employee-scoped hotel list.
     *
     * @return array<int, array{id: string, hotel_name: string}>
     */
    private function getAllHotels()
    {
        return (new HotelBranchInformation())->hotelsNameAndId() ?: array();
    }

    /**
     * @param int $idHotel
     *
     * @return array{html: string, success: bool}
     */
    private function processSettingsSubmit($idHotel)
    {
        // The API base URL is a single system-wide setting — saved once
        // here regardless of which property's form triggered the submit,
        // never scoped to $idHotel.
        $baseUrl = trim((string) Tools::getValue('V5IDFRONTDESK_API_BASE_URL'));
        if (!$baseUrl || !Validate::isAbsoluteUrl($baseUrl)) {
            return array('html' => $this->displayError($this->l('Please enter a valid API base URL.')), 'success' => false);
        }
        Configuration::updateValue('V5IDFRONTDESK_API_BASE_URL', rtrim($baseUrl, '/'));

        if (!$idHotel) {
            return array('html' => $this->displayError($this->l('Select a property first.')), 'success' => false);
        }

        $rawIntegrationId = trim((string) Tools::getValue('V5IDFRONTDESK_INTEGRATION_ID'));
        $integrationId = $rawIntegrationId === '' ? '' : V5idDeviceOAuth::normalizeIntegrationId($rawIntegrationId);
        if ($integrationId === null) {
            return array('html' => $this->displayError($this->l('The integration ID must be the UUID shown for this property\'s integration in the V5id portal.')), 'success' => false);
        }

        // Changing it signs out this property's scanners — see
        // V5idFrontDeskHotelCredential::saveForHotel().
        if (!V5idFrontDeskHotelCredential::saveForHotel($idHotel, $integrationId)) {
            return array('html' => $this->displayError($this->l('Could not save this property\'s integration. Please try again.')), 'success' => false);
        }

        $enabledScanners = array();
        foreach (array_keys(self::SCANNER_ADAPTERS) as $adapterId) {
            if (Tools::getValue('ENABLED_SCANNERS_'.$adapterId)) {
                $enabledScanners[] = $adapterId;
            }
        }
        Configuration::updateValue('V5IDFRONTDESK_ENABLED_SCANNERS', json_encode($enabledScanners));

        return array('html' => $this->displayConfirmation($this->l('Settings updated.')), 'success' => true);
    }

    /**
     * @param int $idHotel
     *
     * @return string
     */
    private function processTestConnection($idHotel)
    {
        $result = V5idDeviceOAuth::checkConfiguration($idHotel);

        return $result['success']
            ? $this->displayConfirmation($result['message'])
            : $this->displayError(sprintf($this->l('Connection check failed: %s'), $result['message']));
    }

    /**
     * @param int $idHotel
     *
     * @return string
     */
    private function renderSettingsForm($idHotel)
    {
        $apiFieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('V5id API — system-wide'),
                    'icon' => 'icon-globe',
                ),
                'description' => $this->l('A single setting for the whole installation, not specific to one property: just the endpoint address, so it never varies by hotel. Scanner sign-in uses the same server\'s origin (its /oauth/ endpoints).'),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API base URL'),
                        'name' => 'V5IDFRONTDESK_API_BASE_URL',
                        'desc' => $this->l('e.g. https://api.v5id.net/api/v1'),
                        'required' => true,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'name' => 'submitV5idFrontDeskSettings',
                ),
            ),
        );

        $redirectUri = V5idDeviceOAuth::redirectUri();
        $redirectDesc = $this->l('Add this exact URL as a redirect URL for this property\'s integration in the V5id portal. V5id only returns a scanner\'s sign-in to a URL registered there.');
        if (strpos($redirectUri, 'https://') !== 0) {
            $redirectDesc .= ' '.$this->l('Enable SSL for this shop first: V5id sign-in requires an HTTPS redirect URL.');
        }

        $fieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('V5id integration for this property'),
                    'icon' => 'icon-key',
                ),
                'description' => $this->l('Each property is its own integration in the V5id portal, so logging into the portal under one property shows only that property\'s verifications. Scanners sign in to that integration one at a time from Scanner Manager (open it from the Front Desk screen): staff click "Sign in" on a paired scanner and type the integration key into V5id\'s own sign-in page. The key is never entered into or stored by QloApps, and each scanner\'s session stays on this server.'),
                'input' => array(
                    array(
                        'type' => 'hidden',
                        'name' => 'id_hotel',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Integration ID'),
                        'name' => 'V5IDFRONTDESK_INTEGRATION_ID',
                        'desc' => $this->l('The integration\'s UUID from the V5id portal, e.g. 3f2b8c1e-5d4a-4e7b-9c1f-2a6d8e0b7c45. Changing it signs out every scanner at this property.'),
                        'required' => false,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Redirect URL'),
                        'name' => 'V5IDFRONTDESK_REDIRECT_URI',
                        'readonly' => true,
                        'desc' => $redirectDesc,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'name' => 'submitV5idFrontDeskSettings',
                ),
                'buttons' => array(
                    array(
                        'title' => $this->l('Test connection'),
                        'name' => 'submitV5idFrontDeskTestConnection',
                        'type' => 'submit',
                        'icon' => 'process-icon-refresh',
                        'class' => 'pull-right',
                    ),
                ),
            ),
        );

        $scannerFieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Scanner protocols — all properties'),
                    'icon' => 'icon-barcode',
                ),
                'description' => $this->l('A system-wide allow-list, not specific to one property: turn on the scanner protocols available anywhere in this installation. Each one is a self-contained adapter, so a new scanner brand can be added later without changing the front desk screen itself. Enabling a protocol here doesn\'t connect anything by itself — each property still pairs its own specific scanner(s) separately, in that property\'s own Scanner Manager (open it from the Front Desk screen). Pairing is also where V5id scan verification gets the device serial number it signs in with — a scanner that just types plain keystrokes (most USB/Bluetooth-HID barcode scanners) has no pairing step and no serial the browser can read, so its scans currently can\'t be verified against V5id.'),
                'input' => array(
                    array(
                        'type' => 'checkbox',
                        'name' => 'ENABLED_SCANNERS',
                        'values' => array(
                            'query' => $this->getScannerAdapterCheckboxValues(),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'name' => 'submitV5idFrontDeskSettings',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitV5idFrontDeskSettings';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name.'&id_hotel='.(int) $idHotel;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues($idHotel),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $this->renderHotelPicker($idHotel).$helper->generateForm(array($apiFieldsForm, $fieldsForm, $scannerFieldsForm));
    }

    /**
     * A small property switcher above the credentials form — a plain GET
     * form (not a HelperForm field) since all it does is reload this page
     * scoped to a different id_hotel. Hidden fields carry the rest of the
     * URL rather than relying on the <form action="">'s own query string:
     * browsers replace a GET form's action query string with the form's
     * own fields on submit, they don't merge the two.
     *
     * @param int $idHotel
     *
     * @return string
     */
    private function renderHotelPicker($idHotel)
    {
        $hotels = $this->getAllHotels();
        if (!$hotels) {
            return $this->displayError($this->l('No properties found. Add one under Hotel Reservation System first.'));
        }

        // Each <option> carries its own complete, already-tokened URL
        // (built the exact same way PrestaShop's own "Configure" links
        // are, via Link::getAdminLink()) rather than assembling a <form>
        // with hidden fields and a hand-computed token — one less place
        // for the request PrestaShop actually receives to end up subtly
        // different from a normal navigation.
        $html = '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-building"></i> '.$this->l('Property').'</div>';
        $html .= '<div style="padding: 15px;">';
        $html .= '<label style="margin-right: 10px; font-weight: 600;">'.$this->l('Editing the V5id integration for:').'</label> ';
        $html .= '<select onchange="if (this.value) { window.location.href = this.value; }" style="min-width: 260px;">';
        foreach ($hotels as $hotel) {
            $link = $this->context->link->getAdminLink('AdminModules')
                .'&configure='.$this->name
                .'&tab_module='.$this->tab
                .'&module_name='.$this->name
                .'&id_hotel='.(int) $hotel['id'];
            $selected = ((int) $hotel['id'] === (int) $idHotel) ? ' selected="selected"' : '';
            $html .= '<option value="'.htmlspecialchars($link, ENT_QUOTES).'"'.$selected.'>'.htmlspecialchars($hotel['hotel_name'], ENT_QUOTES).'</option>';
        }
        $html .= '</select>';
        $html .= '</div></div>';

        return $html;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function getScannerAdapterCheckboxValues()
    {
        $rows = array();
        foreach (self::SCANNER_ADAPTERS as $id => $adapter) {
            $rows[] = array('id' => $id, 'name' => $adapter['label']);
        }

        return $rows;
    }

    /**
     * @param int $idHotel
     *
     * @return array
     */
    private function getConfigFieldsValues($idHotel)
    {
        $fields = array(
            'id_hotel' => (int) $idHotel,
            'V5IDFRONTDESK_API_BASE_URL' => Tools::getValue(
                'V5IDFRONTDESK_API_BASE_URL',
                Configuration::get('V5IDFRONTDESK_API_BASE_URL')
            ),
            'V5IDFRONTDESK_INTEGRATION_ID' => Tools::getValue('V5IDFRONTDESK_INTEGRATION_ID', $this->getSavedIntegrationId($idHotel)),
            'V5IDFRONTDESK_REDIRECT_URI' => V5idDeviceOAuth::redirectUri(),
        );

        $enabledScanners = self::getEnabledScannerAdapters();
        foreach (array_keys(self::SCANNER_ADAPTERS) as $adapterId) {
            $fields['ENABLED_SCANNERS_'.$adapterId] = in_array($adapterId, $enabledScanners, true);
        }

        return $fields;
    }

    /**
     * @param int $idHotel
     *
     * @return string
     */
    private function getSavedIntegrationId($idHotel)
    {
        $credential = $idHotel ? V5idFrontDeskHotelCredential::getForHotel($idHotel) : null;

        return $credential && $credential->integration_id ? $credential->integration_id : '';
    }
}
