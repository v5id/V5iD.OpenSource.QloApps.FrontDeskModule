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

class V5idFrontDesk extends Module
{
    const CONTROLLER_CLASS = 'AdminV5idFrontDesk';

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
    );

    /** @var string[] Configuration keys removed on uninstall. */
    private $configKeys = array(
        'V5IDFRONTDESK_API_BASE_URL',
        'V5IDFRONTDESK_DEVICE_SERIAL',
        'V5IDFRONTDESK_DEVICE_SECRET',
        'V5IDFRONTDESK_DEVICE_ACCESS_TOKEN',
        'V5IDFRONTDESK_DEVICE_REFRESH_TOKEN',
        'V5IDFRONTDESK_DEVICE_TOKEN_EXPIRES_AT',
        'V5IDFRONTDESK_DEVICE_REFRESH_EXPIRES_AT',
        'V5IDFRONTDESK_ENABLED_SCANNERS',
        'V5IDFRONTDESK_SCAN_LOG_PII_PURGED',
    );

    public function __construct()
    {
        $this->name = 'v5idfrontdesk';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'V5iD, Inc.';
        $this->need_instance = 0;
        $this->bootstrap = true;

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
        $tables = array('v5idfrontdesk_scan_log', 'v5idfrontdesk_activity_log');
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
        return Configuration::updateValue('V5IDFRONTDESK_API_BASE_URL', 'https://test.api.v5id.dev/api/v1')
            && Configuration::updateValue('V5IDFRONTDESK_ENABLED_SCANNERS', json_encode(array()));
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
        foreach ($this->configKeys as $key) {
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

        $this->context->controller->addJS($this->_path.'views/js/admin-nav-icon.js');
    }

    /**
     * Module configuration page: V5iD device credentials + connection test.
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

        $output = '';

        // HelperForm always injects a hidden `<submit_action>=1` field, so
        // submitV5idFrontDeskSettings is present on every submit regardless
        // of which button was clicked — the more specific button must be
        // checked first.
        if (Tools::isSubmit('submitV5idFrontDeskTestConnection')) {
            // Save before testing: V5idApiClient reads its base URL/serial/
            // secret straight from Configuration, not from this request's
            // POST data, so testing without saving first would silently
            // re-test whatever was already in the database — not whatever
            // was just typed into the form.
            $saveResult = $this->processSettingsSubmit();
            $output .= $saveResult['html'];
            if ($saveResult['success']) {
                $output .= $this->processTestConnection();
            }
        } elseif (Tools::isSubmit('submitV5idFrontDeskSettings')) {
            $output .= $this->processSettingsSubmit()['html'];
        }

        return $output.$this->renderSettingsForm();
    }

    /**
     * @return array{html: string, success: bool}
     */
    private function processSettingsSubmit()
    {
        $baseUrl = trim((string) Tools::getValue('V5IDFRONTDESK_API_BASE_URL'));
        $serial = trim((string) Tools::getValue('V5IDFRONTDESK_DEVICE_SERIAL'));
        $secret = trim((string) Tools::getValue('V5IDFRONTDESK_DEVICE_SECRET'));

        if (!$baseUrl || !Validate::isAbsoluteUrl($baseUrl)) {
            return array('html' => $this->displayError($this->l('Please enter a valid API base URL.')), 'success' => false);
        }

        Configuration::updateValue('V5IDFRONTDESK_API_BASE_URL', rtrim($baseUrl, '/'));
        Configuration::updateValue('V5IDFRONTDESK_DEVICE_SERIAL', $serial);

        // Only overwrite the stored secret when the admin actually typed a new one,
        // so re-saving the form doesn't blank it out.
        if ($secret !== '') {
            Configuration::updateValue('V5IDFRONTDESK_DEVICE_SECRET', $secret);
        }

        // Credentials changed: drop any cached token so the next call re-authenticates.
        Configuration::deleteByName('V5IDFRONTDESK_DEVICE_ACCESS_TOKEN');
        Configuration::deleteByName('V5IDFRONTDESK_DEVICE_REFRESH_TOKEN');
        Configuration::deleteByName('V5IDFRONTDESK_DEVICE_TOKEN_EXPIRES_AT');
        Configuration::deleteByName('V5IDFRONTDESK_DEVICE_REFRESH_EXPIRES_AT');

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
     * @return string
     */
    private function processTestConnection()
    {
        $client = new V5idApiClient();
        $result = $client->getDeviceToken(true);

        if ($result['success']) {
            return $this->displayConfirmation($this->l('Connection successful — a device token was issued.'));
        }

        return $this->displayError(sprintf($this->l('Connection failed: %s'), $result['message']));
    }

    /**
     * @return string
     */
    private function renderSettingsForm()
    {
        $fieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('V5iD device credentials'),
                    'icon' => 'icon-key',
                ),
                'description' => $this->l('Enter the device credentials issued by the V5iD portal for the ID scanner used at this front desk. These are exchanged server-side for a short-lived device token used to validate scans — they are never sent to the browser.'),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API base URL'),
                        'name' => 'V5IDFRONTDESK_API_BASE_URL',
                        'desc' => $this->l('e.g. https://test.api.v5id.dev/api/v1'),
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Device serial number'),
                        'name' => 'V5IDFRONTDESK_DEVICE_SERIAL',
                        'required' => true,
                    ),
                    array(
                        'type' => 'password',
                        'label' => $this->l('Device secret'),
                        'name' => 'V5IDFRONTDESK_DEVICE_SECRET',
                        'desc' => $this->l('Leave blank to keep the currently saved secret.'),
                        'required' => false,
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
                    'title' => $this->l('Scanner protocols'),
                    'icon' => 'icon-barcode',
                ),
                'description' => $this->l('Turn on the scanner protocols in use at this property. Each one is a self-contained adapter, so new scanner brands/protocols can be added here later without changing the front desk screen itself. Scanners that just type plain keystrokes (most USB/Bluetooth-HID barcode scanners) need nothing enabled here — they work automatically.'),
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
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fieldsForm, $scannerFieldsForm));
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
     * @return array
     */
    private function getConfigFieldsValues()
    {
        $fields = array(
            'V5IDFRONTDESK_API_BASE_URL' => Tools::getValue(
                'V5IDFRONTDESK_API_BASE_URL',
                Configuration::get('V5IDFRONTDESK_API_BASE_URL')
            ),
            'V5IDFRONTDESK_DEVICE_SERIAL' => Tools::getValue(
                'V5IDFRONTDESK_DEVICE_SERIAL',
                Configuration::get('V5IDFRONTDESK_DEVICE_SERIAL')
            ),
            'V5IDFRONTDESK_DEVICE_SECRET' => '',
        );

        $enabledScanners = self::getEnabledScannerAdapters();
        foreach (array_keys(self::SCANNER_ADAPTERS) as $adapterId) {
            $fields['ENABLED_SCANNERS_'.$adapterId] = in_array($adapterId, $enabledScanners, true);
        }

        return $fields;
    }
}
