<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Stripe Galette plugin
 *
 * This page can be loaded directly, or via ajax.
 * Via ajax, we do not have a full html page, but only
 * that will be displayed using javascript on another page
 *
 * Copyright © 2011-2014 The Galette Team
 *
 * This file is part of Galette (http://galette.tuxfamily.org).
 *
 * Galette is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Galette is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Galette. If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  Classes
 * @package   GaletteStripe
 * @author    Mathieu PELLEGRIN <dev@pingveno.net>
 * @copyright 2011-2014 The Galette Team
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GPL License 3.0 or (at your option) any later version
 * @link      http://galette.tuxfamily.org
 */

namespace GaletteStripe;

use Analog\Analog;
use Galette\Core\Db;
use Galette\Core\Login;
use Galette\Entity\ContributionsTypes;

/**
 * Preferences for stripe
 *
 * @category  Classes
 * @name      Stripe
 * @package   GaletteStripe
 * @author    Mathieu PELLEGRIN <dev@pingveno.net>
 * @copyright 2011-2014 The Galette Team
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GPL License 3.0 or (at your option) any later version
 * @link      http://galette.tuxfamily.org
 */

class Stripe
{
    public const TABLE = 'types_cotisation_prices';
    public const PK = ContributionsTypes::PK;
    public const PREFS_TABLE = 'preferences';

    public const PAYMENT_PENDING = 'Pending';
    public const PAYMENT_COMPLETE = 'Complete';

    private $zdb;

    private $prices = array();
    private $pubkey = null;
    private $privkey = null;
    private $webhook_secret = null;
    private $country = null;
    private $currency = null;
    private $inactives = array();

    private $loaded = false;
    private $amounts_loaded = false;

    /**
     * Default constructor
     *
     * @param Db $zdb Database instance
     */
    public function __construct(Db $zdb)
    {
        $this->zdb = $zdb;
        $this->loaded = false;
        $this->prices = array();
        $this->inactives = array();
        $this->pubkey = null;
        $this->privkey = null;
        $this->country = null;
        $this->currency = null;
        $this->load();
    }

    /**
     * Load preferences form the database and amounts
     *
     * @return void
     */
    public function load()
    {
        try {
            $results = $this->zdb->selectAll(STRIPE_PREFIX . self::PREFS_TABLE);

            foreach ($results as $row) {
                switch ($row->nom_pref) {
                    case 'stripe_pubkey':
                        $this->pubkey = $row->val_pref;
                        break;
                    case 'stripe_privkey':
                        $this->privkey = $row->val_pref;
                        break;
                    case 'stripe_webhook_secret':
                        $this->webhook_secret = $row->val_pref;
                        break;
                    case 'stripe_country':
                        $this->country = $row->val_pref;
                        break;
                    case 'stripe_currency':
                        $this->currency = $row->val_pref;
                        break;
                    case 'stripe_inactives':
                        $this->inactives = explode(',', $row->val_pref);
                        break;
                    default:
                        //we've got a preference not intended
                        Analog::log(
                            '[' . get_class($this) . '] unknown preference `' .
                            $row->nom_pref . '` in the database.',
                            Analog::WARNING
                        );
                }
            }
            $this->loaded = true;
            return $this->loadAmounts();
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot load stripe preferences |' .
                $e->getMessage(),
                Analog::ERROR
            );
            //consider plugin is not loaded when missing the main preferences
            //(that includes stripe id)
            $this->loaded = false;
        }
    }

    /**
     * Load amounts from database
     *
     * @return void
     */
    private function loadAmounts()
    {
        $ct = new ContributionsTypes($this->zdb);
        $this->prices = $ct->getCompleteList();

        try {
            $results = $this->zdb->selectAll(STRIPE_PREFIX . self::TABLE);

            //check if all types currently exists in stripe table
            if (count($results) != count($this->prices)) {
                Analog::log(
                    '[' . get_class($this) . '] There are missing types in ' .
                    'stripe table, Galette will try to create them.',
                    Analog::INFO
                );
            }

            $queries = array();
            foreach ($this->prices as $k => $v) {
                $_found = false;
                if (count($results) > 0) {
                    //for each entry in types, we want to get the associated amount
                    foreach ($results as $stripe) {
                        if ($stripe->id_type_cotis == $k) {
                            $_found = true;
                            $this->prices[$k]['amount'] = (double)$stripe->amount;
                            break;
                        }
                    }
                }
                if ($_found === false) {
                    Analog::log(
                        'The type `' . $v['name'] . '` (' . $k . ') does not exist' .
                        ', Galette will attempt to create it.',
                        Analog::INFO
                    );
                    $this->prices[$k]['amount'] = null;
                    $queries[] = array(
                          'id'   => $k,
                        'amount' => null
                    );
                }
            }
            if (count($queries) > 0) {
                $this->newEntries($queries);
            }
            //amounts should be loaded here
            $this->amounts_loaded = true;
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot load stripe amounts' .
                '` | ' . $e->getMessage(),
                Analog::ERROR
            );
            //amounts are not loaded at this point
            $this->amounts_loaded = false;
        }
    }

    /**
     * Store values in the database
     *
     * @return void
     */
    public function store()
    {
        try {
            //store stripe pubkey
            $values = array(
                'nom_pref' => 'stripe_pubkey',
                'val_pref' => $this->pubkey
            );
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    array(
                        'nom_pref' => 'stripe_pubkey'
                    )
                );

            $edit = $this->zdb->execute($update);

            //store stripe privkey
            $values = array(
                'nom_pref' => 'stripe_privkey',
                'val_pref' => $this->privkey
            );
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    array(
                        'nom_pref' => 'stripe_privkey'
                    )
                );

            $edit = $this->zdb->execute($update);

            //store stripe webhook secret
            $values = array(
                'nom_pref' => 'stripe_webhook_secret',
                'val_pref' => $this->webhook_secret
            );
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    array(
                        'nom_pref' => 'stripe_webhook_secret'
                    )
                );

            $edit = $this->zdb->execute($update);

            //store stripe country
            $values = array(
                'nom_pref' => 'stripe_country',
                'val_pref' => $this->country
            );
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    array(
                        'nom_pref' => 'stripe_country'
                    )
                );

            $edit = $this->zdb->execute($update);

            //store stripe currency
            $values = array(
                'nom_pref' => 'stripe_currency',
                'val_pref' => $this->currency
            );
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    array(
                        'nom_pref' => 'stripe_currency'
                    )
                );

            $edit = $this->zdb->execute($update);

            //store inactives
            $values = array(
                'nom_pref' => 'stripe_inactives',
                'val_pref' => implode(',', $this->inactives)
            );
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    array(
                        'nom_pref' => 'stripe_inactives'
                    )
                );

            $edit = $this->zdb->execute($update);

            Analog::log(
                '[' . get_class($this) .
                '] Stripe preferences were sucessfully stored',
                Analog::INFO
            );

            return $this->storeAmounts();
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot store stripe preferences' .
                '` | ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Store amounts in the database
     *
     * @return boolean
     */
    public function storeAmounts()
    {
        try {
            $update = $this->zdb->update(STRIPE_PREFIX . self::TABLE);
            $update->set(
                array(
                    'amount'    => ':amount'
                )
            )->where->equalTo(self::PK, ':id');

            $stmt = $this->zdb->sql->prepareStatementForSqlObject($update);

            foreach ($this->prices as $k => $v) {
                $stmt->execute(
                    array(
                        'amount'    => (float)$v['amount'],
                        'where1'        => $k
                    )
                );
            }

            Analog::log(
                '[' . get_class($this) . '] Stripe amounts were sucessfully stored',
                Analog::INFO
            );
            return true;
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot store stripe amounts' .
                '` | ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
    * Add missing types in stripe table
    *
    * @param Array $queries Array of items to insert
    *
    * @return true on success, false on failure
    */
    private function newEntries($queries)
    {
        try {
            $insert = $this->zdb->insert(STRIPE_PREFIX . self::TABLE);
            $insert->values(
                array(
                    self::PK    => ':' . self::PK,
                    'amount'    => ':amount'
                )
            );
            $stmt = $this->zdb->sql->prepareStatementForSqlObject($insert);

            foreach ($queries as $q) {
                $stmt->execute(
                    array(
                        self::PK    => $q['id'],
                        'amount'    => $q['amount']
                    )
                );
            }

            return true;
        } catch (\Exception $e) {
            Analog::log(
                'Unable to store missing types in stripe table.' .
                $stmt->getMessage() . '(' . $stmt->getDebugInfo() . ')',
                Analog::WARNING
            );
            return false;
        }
    }

    /**
     * Create payment intent
     *
     * @return string
     */
    function createPaymentIntent($metadata, $amount, $methods = ['card']) {
        $data = [
            'amount' => $amount * 100, // Stripe needs integer cents as amount
            'currency' => $this->getCurrency(),
            'payment_method_types' => $methods,
            'metadata' => $metadata,
        ];
        $payload = http_build_query($data);

        // Just using cURL instead of full Stripe PHP library
        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->getPrivKey() . ':');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);
        curl_close($ch);

        $object = json_decode($response, true);

        if ($object === null) {
            return false;
        } else {
            if (isset($object['error'])) {
                return false;
            } else {
                return $object['client_secret'];
            }
        }
    }

    /**
     * Get Stripe public key
     *
     * @return string
     */
    public function getPubKey()
    {
        return $this->pubkey;
    }

    /**
     * Get Stripe private key
     *
     * @return string
     */
    public function getPrivKey()
    {
        return $this->privkey;
    }

    /**
     * Get Stripe webhook secret
     *
     * @return string
     */
    public function getWebhookSecret()
    {
        return $this->webhook_secret;
    }

    /**
     * Get Stripe country
     *
     * @return string
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * Get Stripe currency
     *
     * @return string
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * Get loaded and active amounts
     *
     * @param Login $login Login instance
     *
     * @return array
     */
    public function getAmounts(Login $login)
    {
        $prices = array();
        foreach ($this->prices as $k => $v) {
            if (!$this->isInactive($k)) {
                if ($login->isLogged() || $v['extra'] == 0) {
                    $prices[$k] = $v;
                }
            }
        }
        return $prices;
    }

    /**
     * Get loaded amounts
     *
     * @return array
     */
    public function getAllAmounts()
    {
        return $this->prices;
    }

    /**
     * Get all countries
     *
     * @return array
     */
    public function getAllCountries()
    {
        $countries = [
            "DE" => _T("Germany"),
            "AU" => _T("Australia"),
            "AT" => _T("Austria"),
            "BE" => _T("Belgium"),
            "BG" => _T("Bulgaria"),
            "CA" => _T("Canada"),
            "CY" => _T("Cyprus"),
            "DK" => _T("Danemark"),
            "ES" => _T("Spain"),
            "EE" => _T("Estonia"),
            "US" => _T("USA"),
            "FI" => _T("Finland"),
            "FR" => _T("France"),
            "GR" => _T("Greece"),
            "HK" => _T("Hong Kong"),
            "HU" => _T("Hungary"),
            "IE" => _T("Ireland"),
            "IT" => _T("Italy"),
            "JP" => _T("Japan"),
            "LV" => _T("Latvia"),
            "LT" => _T("Lithuania"),
            "LU" => _T("Luxenbourg"),
            "MY" => _T("Malaysia"),
            "MT" => _T("Malta"),
            "MX" => _T("Mexico"),
            "NO" => _T("Norway"),
            "NZ" => _T("New Zealand"),
            "NL" => _T("Netherlands"),
            "PL" => _T("Poland"),
            "PT" => _T("Portugal"),
            "CZ" => _T("Czech Republic"),
            "RO" => _T("Romania"),
            "GB" => _T("United Kingdom"),
            "SG" => _T("Singapore"),
            "SK" => _T("Slovakia"),
            "SI" => _T("Slovenia"),
            "SE" => _T("Sweden"),
            "CH" => _T("Swiss")
        ];

        asort($countries);
        return $countries;
    }

    /**
     * Get all currencies
     *
     * @return array
     */
    public function getAllCurrencies()
    {
        $currencies = [
            "eur" => _T("Euros"),
            "usd" => _T("US dollars"),
            "chf" => _T("Swiss franc"),
        ];

        asort($currencies);
        return $currencies;
    }

    /**
     * Is the plugin loaded?
     *
     * @return boolean
     */
    public function isLoaded()
    {
        return $this->loaded;
    }

    /**
     * Are amounts loaded?
     *
     * @return boolean
     */
    public function areAmountsLoaded()
    {
        return $this->amounts_loaded;
    }

    /**
     * Set stripe public key
     *
     * @param string $pubkey public key
     *
     * @return void
     */
    public function setPubKey($pubkey)
    {
        $this->pubkey = $pubkey;
    }

    /**
     * Set stripe private key
     *
     * @param string $privkey private key
     *
     * @return void
     */
    public function setPrivKey($privkey)
    {
        $this->privkey = $privkey;
    }

    /**
     * Set stripe webhook secret
     *
     * @param string $secret webhook secret
     *
     * @return void
     */
    public function setWebhookSecret($secret)
    {
        $this->webhook_secret = $secret;
    }

    /**
     * Set stripe country
     *
     * @param string $country country
     *
     * @return void
     */
    public function setCountry($country)
    {
        $this->country = $country;
    }

    /**
     * Set stripe currency
     *
     * @param string $currency currency
     *
     * @return void
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Set new prices
     *
     * @param array $ids     array of identifier
     * @param array $amounts array of amounts
     *
     * @return void
     */
    public function setPrices($ids, $amounts)
    {
        $this->prices = [];
        foreach ($ids as $k => $id) {
            $this->prices[$id]['amount'] = $amounts[$k];
        }
    }

    /**
     * Check if the specified contribution is active
     *
     * @param int $id type identifier
     *
     * @return boolean
     */
    public function isInactive($id)
    {
        return in_array($id, $this->inactives);
    }

    /**
     * Set inactives types
     *
     * @param array $inactives array of inactives types
     *
     * @return void
     */
    public function setInactives($inactives)
    {
        $this->inactives = $inactives;
    }

    /**
     * Unset inactives types
     *
     * @return void
     */
    public function unsetInactives()
    {
        $this->inactives = array();
    }
}
