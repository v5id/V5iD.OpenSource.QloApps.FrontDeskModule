<?php
/**
 * V5iD Front Desk module for QloApps.
 *
 * Compares a V5iD scan result against the guest's stored profile (Customer
 * + the invoice address on the booking's order) so front desk staff can be
 * prompted to fix missing/inaccurate data instead of silently trusting
 * whatever's on file.
 *
 * Only fields the scan gives us as *exact* values are eligible to be
 * written back automatically (see apply()). V5idApiClient::validateScan()
 * returns a computed age, never a raw date of birth, so a birthday mismatch
 * is surfaced for manual review only — there is no precise value to offer
 * writing to Customer::$birthday.
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

class V5idFrontDeskProfileCheck
{
    /** Age difference (years) tolerated before flagging a mismatch — covers a birthday just passed/about to pass. */
    const AGE_TOLERANCE = 1;

    /** Field keys apply() knows how to write. Kept in sync with the keys run() can mark 'missing'/'mismatch' for. */
    const APPLICABLE_FIELDS = array('documentNumber', 'address1', 'city', 'postcode', 'country', 'state');

    /**
     * ISO 3166-1 alpha-3 -> alpha-2. V5iD returns alpha-3 (e.g. "USA");
     * QloApps' own Country table only stores alpha-2. This intentionally
     * covers common cases rather than claiming to be exhaustive — a code
     * missing from it is reported as 'unverifiable' rather than guessed at,
     * so an incomplete map can never produce a false "wrong country" prompt.
     *
     * @var array<string, string>
     */
    private static $alpha3ToAlpha2 = array(
        'USA' => 'US', 'CAN' => 'CA', 'MEX' => 'MX', 'GBR' => 'GB', 'IRL' => 'IE',
        'FRA' => 'FR', 'DEU' => 'DE', 'ESP' => 'ES', 'PRT' => 'PT', 'ITA' => 'IT',
        'NLD' => 'NL', 'BEL' => 'BE', 'LUX' => 'LU', 'CHE' => 'CH', 'AUT' => 'AT',
        'DNK' => 'DK', 'SWE' => 'SE', 'NOR' => 'NO', 'FIN' => 'FI', 'ISL' => 'IS',
        'POL' => 'PL', 'CZE' => 'CZ', 'SVK' => 'SK', 'HUN' => 'HU', 'ROU' => 'RO',
        'BGR' => 'BG', 'GRC' => 'GR', 'TUR' => 'TR', 'RUS' => 'RU', 'UKR' => 'UA',
        'HRV' => 'HR', 'SVN' => 'SI', 'SRB' => 'RS', 'EST' => 'EE', 'LVA' => 'LV',
        'LTU' => 'LT', 'AUS' => 'AU', 'NZL' => 'NZ', 'JPN' => 'JP', 'KOR' => 'KR',
        'CHN' => 'CN', 'HKG' => 'HK', 'TWN' => 'TW', 'SGP' => 'SG', 'MYS' => 'MY',
        'THA' => 'TH', 'VNM' => 'VN', 'PHL' => 'PH', 'IDN' => 'ID', 'IND' => 'IN',
        'PAK' => 'PK', 'BGD' => 'BD', 'ARE' => 'AE', 'SAU' => 'SA', 'ISR' => 'IL',
        'EGY' => 'EG', 'ZAF' => 'ZA', 'NGA' => 'NG', 'KEN' => 'KE', 'BRA' => 'BR',
        'ARG' => 'AR', 'CHL' => 'CL', 'COL' => 'CO', 'PER' => 'PE', 'VEN' => 'VE',
        'CUB' => 'CU', 'JAM' => 'JM', 'DOM' => 'DO', 'CRI' => 'CR', 'PAN' => 'PA',
        'GTM' => 'GT',
    );

    /**
     * @param Customer $customer
     * @param Address|null $address The invoice address for the booking being checked, if any.
     * @param array{age: ?int, documentNumber: ?string, address: array} $scan
     *
     * @return array{fields: array<string, array>, hasIssues: bool, canAutoApply: string[]}
     */
    public static function run(Customer $customer, $address, array $scan)
    {
        $scanAddress = isset($scan['address']) && is_array($scan['address']) ? $scan['address'] : array();
        $hasAddress = ($address instanceof Address) && $address->id;

        $fields = array();
        $fields['age'] = self::checkAge($customer, $scan);

        if (!$hasAddress) {
            $fields['documentNumber'] = self::missingOrUnverifiable(isset($scan['documentNumber']) ? $scan['documentNumber'] : null, 'ID document number');
            $fields['address1'] = self::missingOrUnverifiable(self::val($scanAddress, 'streetAddress'), 'Street address');
            $fields['city'] = self::missingOrUnverifiable(self::val($scanAddress, 'city'), 'City');
            $fields['postcode'] = self::missingOrUnverifiable(self::val($scanAddress, 'postalCode'), 'Postal code');

            // Country needs its own check rather than missingOrUnverifiable():
            // it can only go in canAutoApply — and so be written when the
            // record below gets created — if the scanned code actually
            // resolves to one of QloApps' countries. id_country is a
            // required field on Address, so leaving it unresolved here
            // would otherwise make apply() try to save a new address
            // without it.
            $scannedCountryCode = self::val($scanAddress, 'countryCode');
            $resolvedIdCountry = $scannedCountryCode ? self::resolveCountryId($scannedCountryCode) : 0;

            if ($resolvedIdCountry) {
                $fields['country'] = array('status' => 'missing', 'current' => null, 'scanned' => $scannedCountryCode, 'label' => 'Country');
            } else {
                $fields['country'] = array(
                    'status' => 'unverifiable',
                    'current' => null,
                    'scanned' => $scannedCountryCode,
                    'label' => 'Country',
                    'note' => $scannedCountryCode ? 'Unrecognized country code — please verify manually.' : 'No address on file for this booking.',
                );
            }

            // Same reasoning as country: without this, a brand-new address
            // would only ever get its state filled in on a *second*
            // "Update guest record" click, since compareState() below can
            // only run once an address (and so a known id_country) exists —
            // the very first check would silently omit state from
            // canAutoApply entirely, not even flag it.
            if ($resolvedIdCountry && Country::containsStates($resolvedIdCountry)) {
                $scannedRegion = self::val($scanAddress, 'regionCode');
                $fields['state'] = $scannedRegion
                    ? array('status' => 'missing', 'current' => null, 'scanned' => strtoupper($scannedRegion), 'label' => 'State/region')
                    : array('status' => 'unverifiable', 'current' => null, 'scanned' => null, 'label' => 'State/region');
            }
        } else {
            $fields['documentNumber'] = self::compareDocumentNumber($address->dni, $scan['documentNumber']);
            $fields['address1'] = self::compareText($address->address1, self::val($scanAddress, 'streetAddress'), 'Street address');
            $fields['city'] = self::compareText($address->city, self::val($scanAddress, 'city'), 'City');
            $fields['postcode'] = self::comparePostcode($address->postcode, self::val($scanAddress, 'postalCode'));
            $fields['country'] = self::compareCountry((int) $address->id_country, self::val($scanAddress, 'countryCode'));

            // Check state applicability against whichever country the scan
            // resolves to, not just whatever's already on file. apply()
            // sets country before state within a single call, so once
            // 'country' is in canAutoApply the record will have the new
            // country by the time state is written — but compareState()
            // must judge "does this country even use states" the same way,
            // or a stored country that doesn't use states (while the scan's
            // does) would silently drop 'state' from this pass entirely,
            // deferring it to a second "Update guest record" click.
            $scannedIdCountry = self::resolveCountryId(self::val($scanAddress, 'countryCode'));
            $effectiveIdCountry = $scannedIdCountry ?: (int) $address->id_country;

            $stateField = self::compareState($effectiveIdCountry, (int) $address->id_state, self::val($scanAddress, 'regionCode'));
            if ($stateField !== null) {
                $fields['state'] = $stateField;
            }
        }

        $hasIssues = false;
        $canAutoApply = array();
        foreach ($fields as $key => $field) {
            if (in_array($field['status'], array('missing', 'mismatch'), true)) {
                $hasIssues = true;
                if (in_array($key, self::APPLICABLE_FIELDS, true)) {
                    $canAutoApply[] = $key;
                }
            }
        }

        return array('fields' => $fields, 'hasIssues' => $hasIssues, 'canAutoApply' => $canAutoApply);
    }

    /**
     * Writes the scanned values for the given field keys onto $address and
     * saves it — creating a fresh Address for the customer first if $address
     * came in null (some bookings here have no address on file at all).
     * Never touches Customer::$birthday — see class docblock.
     *
     * @param Customer $customer
     * @param Address|null $address Passed by reference: set to the newly
     *   created row when null on entry. QloApps may also itself swap in a
     *   new row (soft-deleting the old one) if $address was already
     *   attached to an order — see Address::update()/updateUsedAddress().
     *   Callers must re-read $address->id afterwards rather than assume the
     *   id passed in survived.
     * @param array{age: ?int, documentNumber: ?string, address: array} $scan
     * @param string[] $fieldKeys Subset of self::APPLICABLE_FIELDS.
     *
     * @return bool
     */
    public static function apply(Customer $customer, &$address, array $scan, array $fieldKeys)
    {
        $scanAddress = isset($scan['address']) && is_array($scan['address']) ? $scan['address'] : array();
        $fieldKeys = array_intersect($fieldKeys, self::APPLICABLE_FIELDS);
        $isNew = !($address instanceof Address) || !$address->id;

        if ($isNew) {
            $address = new Address();
            $address->id_customer = (int) $customer->id;
            $address->alias = 'ID scan';
            $address->firstname = $customer->firstname ?: 'Guest';
            $address->lastname = $customer->lastname ?: 'Guest';
        }

        if (in_array('documentNumber', $fieldKeys, true) && !empty($scan['documentNumber'])) {
            $address->dni = $scan['documentNumber'];
        }
        if (in_array('address1', $fieldKeys, true) && self::val($scanAddress, 'streetAddress')) {
            $address->address1 = self::val($scanAddress, 'streetAddress');
        }
        if (in_array('city', $fieldKeys, true) && self::val($scanAddress, 'city')) {
            $address->city = self::val($scanAddress, 'city');
        }
        if (in_array('postcode', $fieldKeys, true) && self::val($scanAddress, 'postalCode')) {
            $address->postcode = self::val($scanAddress, 'postalCode');
        }
        if (in_array('country', $fieldKeys, true)) {
            $idCountry = self::resolveCountryId(self::val($scanAddress, 'countryCode'));
            if ($idCountry) {
                $address->id_country = $idCountry;
                // The old state id almost certainly doesn't belong to the
                // new country — clear it rather than leave a stale/invalid
                // pairing; a 'state' apply in the same batch (see below)
                // will set the right one if the scan provided one.
                $address->id_state = 0;
            }
        }
        if (in_array('state', $fieldKeys, true)) {
            $idState = self::resolveStateId((int) $address->id_country, self::val($scanAddress, 'regionCode'));
            if ($idState) {
                $address->id_state = $idState;
            }
        }

        try {
            // ObjectModel::add()/update() validate required/format rules via
            // validateFields(), which throws PrestaShopException rather than
            // returning false on failure (e.g. a required field left empty,
            // or a scanned value that doesn't fit the field's format/size).
            // Left uncaught, that exception reaches PrestaShop's own
            // top-level handler, which renders an HTML debug page instead of
            // this endpoint's JSON — exactly the kind of failure this
            // feature exists to surface cleanly to front desk staff instead.
            return $isNew ? (bool) $address->add() : (bool) $address->save();
        } catch (PrestaShopException $e) {
            return false;
        }
    }

    /**
     * @param Customer $customer
     * @param array $scan
     *
     * @return array
     */
    private static function checkAge(Customer $customer, array $scan)
    {
        $label = 'Date of birth';
        $scannedAge = isset($scan['age']) ? (int) $scan['age'] : null;

        if ($scannedAge === null) {
            return array('status' => 'unverifiable', 'current' => self::birthdayDisplay($customer), 'scanned' => null, 'label' => $label);
        }

        if (empty($customer->birthday) || $customer->birthday === '0000-00-00') {
            return array('status' => 'missing', 'current' => null, 'scanned' => $scannedAge.' yrs (scanned)', 'label' => $label);
        }

        try {
            $dob = new DateTime($customer->birthday);
            $computedAge = $dob->diff(new DateTime())->y;
        } catch (Exception $e) {
            return array('status' => 'unverifiable', 'current' => self::birthdayDisplay($customer), 'scanned' => $scannedAge.' yrs (scanned)', 'label' => $label);
        }

        $status = (abs($computedAge - $scannedAge) <= self::AGE_TOLERANCE) ? 'ok' : 'mismatch';

        return array(
            'status' => $status,
            'current' => self::birthdayDisplay($customer).' ('.$computedAge.' yrs)',
            'scanned' => $scannedAge.' yrs (scanned)',
            'label' => $label,
        );
    }

    /**
     * @param Customer $customer
     *
     * @return string
     */
    private static function birthdayDisplay(Customer $customer)
    {
        if (empty($customer->birthday) || $customer->birthday === '0000-00-00') {
            return '';
        }

        return $customer->birthday;
    }

    /**
     * @param string|null $current
     * @param string|null $scanned
     * @param string $label
     *
     * @return array
     */
    private static function compareText($current, $scanned, $label)
    {
        $scanned = self::normalize($scanned);
        $current = self::normalize($current);

        if ($scanned === '') {
            return array('status' => 'unverifiable', 'current' => ($current !== '') ? $current : null, 'scanned' => null, 'label' => $label);
        }
        if ($current === '') {
            return array('status' => 'missing', 'current' => null, 'scanned' => $scanned, 'label' => $label);
        }

        $status = (mb_strtoupper($current) === mb_strtoupper($scanned)) ? 'ok' : 'mismatch';

        return array('status' => $status, 'current' => $current, 'scanned' => $scanned, 'label' => $label);
    }

    /**
     * @param string|null $current
     * @param string|null $scanned
     *
     * @return array
     */
    private static function compareDocumentNumber($current, $scanned)
    {
        $label = 'ID document number';
        $scannedNorm = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $scanned));
        $currentNorm = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $current));

        if ($scannedNorm === '') {
            return array('status' => 'unverifiable', 'current' => $current ?: null, 'scanned' => null, 'label' => $label);
        }
        if ($currentNorm === '') {
            return array('status' => 'missing', 'current' => null, 'scanned' => $scanned, 'label' => $label);
        }

        return array('status' => ($currentNorm === $scannedNorm) ? 'ok' : 'mismatch', 'current' => $current, 'scanned' => $scanned, 'label' => $label);
    }

    /**
     * @param string|null $current
     * @param string|null $scanned
     *
     * @return array
     */
    private static function comparePostcode($current, $scanned)
    {
        $label = 'Postal code';
        $scannedNorm = strtoupper(str_replace(' ', '', (string) $scanned));
        $currentNorm = strtoupper(str_replace(' ', '', (string) $current));

        if ($scannedNorm === '') {
            return array('status' => 'unverifiable', 'current' => $current ?: null, 'scanned' => null, 'label' => $label);
        }
        if ($currentNorm === '') {
            return array('status' => 'missing', 'current' => null, 'scanned' => $scanned, 'label' => $label);
        }

        // A "ZIP+4" on file (e.g. "78701-1234") shouldn't flag as a
        // mismatch against a plain 5-digit scanned code, or vice versa.
        $match = ($currentNorm === $scannedNorm)
            || (strpos($currentNorm, $scannedNorm) === 0)
            || (strpos($scannedNorm, $currentNorm) === 0);

        return array('status' => $match ? 'ok' : 'mismatch', 'current' => $current, 'scanned' => $scanned, 'label' => $label);
    }

    /**
     * @param int $idCountry
     * @param string|null $scannedAlpha3
     *
     * @return array
     */
    private static function compareCountry($idCountry, $scannedAlpha3)
    {
        $label = 'Country';
        $currentName = $idCountry ? self::countryName($idCountry) : null;

        if (!$scannedAlpha3) {
            return array('status' => 'unverifiable', 'current' => $currentName, 'scanned' => null, 'label' => $label);
        }

        $alpha2 = self::resolveAlpha2($scannedAlpha3);
        if (!$alpha2) {
            return array(
                'status' => 'unverifiable',
                'current' => $currentName,
                'scanned' => $scannedAlpha3,
                'label' => $label,
                'note' => 'Unrecognized country code — please verify manually.',
            );
        }

        if (!$idCountry) {
            return array('status' => 'missing', 'current' => null, 'scanned' => $scannedAlpha3, 'label' => $label);
        }

        $currentIso = self::countryIso($idCountry);

        return array('status' => ($currentIso === $alpha2) ? 'ok' : 'mismatch', 'current' => $currentName, 'scanned' => $scannedAlpha3, 'label' => $label);
    }

    /**
     * @param int $idCountry
     * @param int $idState
     * @param string|null $scannedRegion
     *
     * @return array|null Null when the country doesn't use states — nothing to check.
     */
    private static function compareState($idCountry, $idState, $scannedRegion)
    {
        if (!$idCountry || !Country::containsStates($idCountry)) {
            return null;
        }

        $label = 'State/region';
        $currentIso = $idState ? self::stateIso($idState) : null;

        if (!$scannedRegion) {
            return array('status' => 'unverifiable', 'current' => $currentIso, 'scanned' => null, 'label' => $label);
        }
        if (!$idState) {
            return array('status' => 'missing', 'current' => null, 'scanned' => strtoupper($scannedRegion), 'label' => $label);
        }

        $status = ($currentIso === strtoupper($scannedRegion)) ? 'ok' : 'mismatch';

        return array('status' => $status, 'current' => $currentIso, 'scanned' => strtoupper($scannedRegion), 'label' => $label);
    }

    /**
     * Used when there's no address on file at all — every address-shaped
     * field is either 'missing' (the scan has a value to offer) or
     * 'unverifiable' (the scan doesn't, so there's nothing to compare or apply).
     *
     * @param string|null $scanned
     * @param string $label
     *
     * @return array
     */
    private static function missingOrUnverifiable($scanned, $label)
    {
        if (empty($scanned)) {
            return array('status' => 'unverifiable', 'current' => null, 'scanned' => null, 'label' => $label);
        }

        return array('status' => 'missing', 'current' => null, 'scanned' => $scanned, 'label' => $label);
    }

    /**
     * @param string|null $alpha3
     *
     * @return string|null
     */
    private static function resolveAlpha2($alpha3)
    {
        $alpha3 = strtoupper((string) $alpha3);

        return isset(self::$alpha3ToAlpha2[$alpha3]) ? self::$alpha3ToAlpha2[$alpha3] : null;
    }

    /**
     * @param string|null $alpha3
     *
     * @return int
     */
    private static function resolveCountryId($alpha3)
    {
        $alpha2 = self::resolveAlpha2($alpha3);

        return $alpha2 ? (int) Country::getByIso($alpha2) : 0;
    }

    /**
     * @param int $idCountry
     * @param string|null $scannedRegion
     *
     * @return int
     */
    private static function resolveStateId($idCountry, $scannedRegion)
    {
        if (!$idCountry || !$scannedRegion) {
            return 0;
        }

        return (int) State::getIdByIso(strtoupper($scannedRegion), $idCountry);
    }

    /**
     * @param int $idCountry
     *
     * @return string
     */
    private static function countryIso($idCountry)
    {
        $country = new Country((int) $idCountry);

        return Validate::isLoadedObject($country) ? strtoupper($country->iso_code) : '';
    }

    /**
     * @param int $idCountry
     *
     * @return string|null
     */
    private static function countryName($idCountry)
    {
        $country = new Country((int) $idCountry, (int) Context::getContext()->language->id);

        return Validate::isLoadedObject($country) ? $country->name : null;
    }

    /**
     * @param int $idState
     *
     * @return string|null
     */
    private static function stateIso($idState)
    {
        $state = new State((int) $idState);

        return Validate::isLoadedObject($state) ? strtoupper($state->iso_code) : null;
    }

    /**
     * @param array $arr
     * @param string $key
     *
     * @return string|null
     */
    private static function val(array $arr, $key)
    {
        return (isset($arr[$key]) && $arr[$key] !== '') ? (string) $arr[$key] : null;
    }

    /**
     * @param string|null $value
     *
     * @return string
     */
    private static function normalize($value)
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $value));
    }
}
