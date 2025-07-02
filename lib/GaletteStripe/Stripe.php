<?php

/**
 * Copyright © 2003-2025 The Galette Team
 *
 * This file is part of Galette Stripe plugin (https://galette-community.github.io/plugin-stripe).
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
 */

declare(strict_types=1);

namespace GaletteStripe;

use Analog\Analog;
use Galette\Core\Db;
use Galette\Core\Login;
use Galette\Entity\ContributionsTypes;

/**
 * Preferences for stripe
 *
 * @author Johan Cwiklinski <johan@x-tnd.be>
 * @author Mathieu PELLEGRIN <dev@pingveno.net>
 * @author manuelh78 <manuelh78dev@ik.me>
 * @author Guillaume AGNIERAY <dev@agnieray.net>
 */

class Stripe
{
    public const TABLE = 'types_cotisation_prices';
    public const PK = ContributionsTypes::PK;
    public const PREFS_TABLE = 'preferences';

    public const PAYMENT_PENDING = 'Pending';
    public const PAYMENT_COMPLETE = 'Complete';

    private Db $zdb;

    /** @var array<int, array<string,mixed>> */
    private array $prices;
    private ?string $pubkey;
    private ?string $privkey;
    private ?string $webhook_secret;
    private ?string $country;
    private ?string $currency;
    /** @var array<int, string> */
    private array $inactives;

    private bool $loaded;
    private bool $amounts_loaded = false;

    /**
     * Default constructor
     *
     * @param Db $zdb Database instance
     */
    public function __construct(Db $zdb)
    {
        $this->zdb = $zdb;
        $this->loaded = false;
        $this->prices = [];
        $this->inactives = [];
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
    public function load(): void
    {
        try {
            $results = $this->zdb->selectAll(STRIPE_PREFIX . self::PREFS_TABLE);

            /** @var \ArrayObject<string, mixed> $row */
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
            $this->loadAmounts();
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot load stripe settings |' .
                $e->getMessage(),
                Analog::ERROR
            );
            //consider plugin is not loaded when missing the main settings
            $this->loaded = false;
        }
    }

    /**
     * Load amounts from database
     *
     * @return void
     */
    private function loadAmounts(): void
    {
        $ct = new ContributionsTypes($this->zdb);
        $this->prices = $ct->getCompleteList();

        try {
            $results = $this->zdb->selectAll(STRIPE_PREFIX . self::TABLE);
            $results = $results->toArray();

            //check if all types currently exists in stripe table
            if (count($results) != count($this->prices)) {
                Analog::log(
                    '[' . get_class($this) . '] There are missing types in ' .
                    'stripe table, Galette will try to create them.',
                    Analog::INFO
                );
            }

            $queries = [];
            foreach ($this->prices as $k => $v) {
                $_found = false;
                if (count($results) > 0) {
                    //for each entry in types, we want to get the associated amount
                    foreach ($results as $stripe) {
                        $stripe = (object)$stripe;
                        if ($stripe->id_type_cotis == $k) {
                            $_found = true;
                            $this->prices[$k]['amount'] = (float)$stripe->amount;
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
                    $queries[] = [
                        'id'   => $k,
                        'amount' => null
                    ];
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
     * @return bool
     */
    public function store(): bool
    {
        try {
            //store stripe pubkey
            $values = [
                'nom_pref' => 'stripe_pubkey',
                'val_pref' => $this->pubkey
            ];
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    [
                        'nom_pref' => 'stripe_pubkey'
                    ]
                );

            $edit = $this->zdb->execute($update);

            //store stripe privkey
            $values = [
                'nom_pref' => 'stripe_privkey',
                'val_pref' => $this->privkey
            ];
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    [
                        'nom_pref' => 'stripe_privkey'
                    ]
                );

            $edit = $this->zdb->execute($update);

            //store stripe webhook secret
            $values = [
                'nom_pref' => 'stripe_webhook_secret',
                'val_pref' => $this->webhook_secret
            ];
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    [
                        'nom_pref' => 'stripe_webhook_secret'
                    ]
                );

            $edit = $this->zdb->execute($update);

            //store stripe country
            $values = [
                'nom_pref' => 'stripe_country',
                'val_pref' => $this->country
            ];
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    [
                        'nom_pref' => 'stripe_country'
                    ]
                );

            $edit = $this->zdb->execute($update);

            //store stripe currency
            $values = [
                'nom_pref' => 'stripe_currency',
                'val_pref' => $this->currency
            ];
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    [
                        'nom_pref' => 'stripe_currency'
                    ]
                );

            $edit = $this->zdb->execute($update);

            //store inactives
            $values = [
                'nom_pref' => 'stripe_inactives',
                'val_pref' => implode(',', $this->inactives)
            ];
            $update = $this->zdb->update(STRIPE_PREFIX . self::PREFS_TABLE);
            $update->set($values)
                ->where(
                    [
                        'nom_pref' => 'stripe_inactives'
                    ]
                );

            $edit = $this->zdb->execute($update);

            Analog::log(
                '[' . get_class($this) .
                '] Stripe settings were sucessfully stored',
                Analog::INFO
            );

            return $this->storeAmounts();
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot store stripe settings' .
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
    public function storeAmounts(): bool
    {
        try {
            $update = $this->zdb->update(STRIPE_PREFIX . self::TABLE);
            $update->set(
                [
                    'amount'    => ':amount'
                ]
            )->where->equalTo(self::PK, ':id');

            $stmt = $this->zdb->sql->prepareStatementForSqlObject($update);

            foreach ($this->prices as $k => $v) {
                $stmt->execute(
                    [
                        'amount'    => (float)$v['amount'],
                        'where1'    => $k
                    ]
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
     * @param array<int, array<string, mixed>> $queries Array of items to insert
     *
     * @return void
     */
    private function newEntries(array $queries): void
    {
        try {
            $insert = $this->zdb->insert(STRIPE_PREFIX . self::TABLE);
            $insert->values(
                [
                    self::PK    => ':' . self::PK,
                    'amount'    => ':amount'
                ]
            );
            $stmt = $this->zdb->sql->prepareStatementForSqlObject($insert);

            foreach ($queries as $q) {
                $stmt->execute(
                    [
                        self::PK    => $q['id'],
                        'amount'    => $q['amount']
                    ]
                );
            }
        } catch (\Exception $e) {
            Analog::log(
                'Unable to store missing types in stripe table.' .
                //@phpstan-ignore-next-line
                $stmt->getMessage() . '(' . $stmt->getDebugInfo() . ')',
                Analog::WARNING
            );
        }
    }

    /**
     * Create payment intent
     *
     * @param array<string, mixed> $metadata Array of metadata to transmit with payment
     * @param string               $amount   Amount of payment
     * @param array<int, string>   $methods  Array of payment methods
     *
     * @return string|boolean
     */
    public function createPaymentIntent(array $metadata, string $amount, array $methods = ['card']): string|bool
    {
        $data = [
            'amount' => (float)$amount * 100, // Stripe needs integer cents as amount
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
    public function getPubKey(): ?string
    {
        return $this->pubkey;
    }

    /**
     * Get Stripe private key
     *
     * @return string
     */
    public function getPrivKey(): ?string
    {
        return $this->privkey;
    }

    /**
     * Get Stripe webhook secret
     *
     * @return string
     */
    public function getWebhookSecret(): ?string
    {
        return $this->webhook_secret;
    }

    /**
     * Get Stripe country
     *
     * @return string
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * Get Stripe currency
     *
     * @return string
     */
    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    /**
     * Get loaded and active amounts
     *
     * @param Login $login Login instance
     *
     * @return array<int, array<string,mixed>>
     */
    public function getAmounts(Login $login): array
    {
        $prices = [];
        foreach ($this->prices as $k => $v) {
            if (!$this->isInactive($k)) {
                if ($login->isLogged() || $v['extra'] == ContributionsTypes::DONATION_TYPE) {
                    $prices[$k] = $v;
                }
            }
        }
        return $prices;
    }

    /**
     * Get loaded amounts
     *
     * @return array<int, array<string,mixed>>
     */
    public function getAllAmounts(): array
    {
        return $this->prices;
    }

    /**
     * Get all countries
     *
     * @return array<string, mixed>
     */
    public function getAllCountries(): array
    {
        // Countries names are voluntarily not translatable.
        $countries = [
            "AE" => "United Arab Emirates",
            "AT" => "Austria",
            "AU" => "Australia",
            "BE" => "Belgium",
            "BG" => "Bulgaria",
            "BR" => "Brazil",
            "CA" => "Canada",
            "CH" => "Switzerland",
            "CY" => "Cyprus",
            "CZ" => "Czech Republic",
            "DE" => "Germany",
            "DK" => "Denmark",
            "EE" => "Estonia",
            "ES" => "Spain",
            "FI" => "Finland",
            "FR" => "France",
            "GB" => "United Kingdom",
            "GI" => "Gibraltar",
            "GR" => "Greece",
            "HK" => "Hong Kong SAR China",
            "HR" => "Croatia",
            "HU" => "Hungary",
            "IE" => "Ireland",
            "IN" => "India",
            "IT" => "Italy",
            "JP" => "Japan",
            "LI" => "Liechtenstein",
            "LT" => "Lithuania",
            "LU" => "Luxembourg",
            "LV" => "Latvia",
            "MT" => "Malta",
            "MX" => "Mexico",
            "MY" => "Malaysia",
            "NL" => "Netherlands",
            "NO" => "Norway",
            "NZ" => "New Zealand",
            "PL" => "Poland",
            "PT" => "Portugal",
            "RO" => "Romania",
            "SE" => "Sweden",
            "SG" => "Singapore",
            "SI" => "Slovenia",
            "SK" => "Slovakia",
            "TH" => "Thailand",
            "US" => "United States"
        ];

        asort($countries);
        return $countries;
    }

    /**
     * Get all currencies
     *
     * @return array<string, mixed>
     */
    public function getAllCurrencies(): array
    {
        // Currencies names are voluntarily not translatable.
        $currencies = [
            "usd" => "USD - United States Dollar",
            "aed" => "AED - United Arab Emirates Dirham",
            "all" => "ALL - Albanian Lek",
            "amd" => "AMD - Armenian Dram",
            "ang" => "ANG - Netherlands Antillean Guilder",
            "aud" => "AUD - Australian Dollar",
            "awg" => "AWG - Aruban Florin",
            "azn" => "AZN - Azerbaijani Manat",
            "bam" => "BAM - Bosnia and Herzegovina Convertible Mark",
            "bbd" => "BBD - Barbadian Dollar",
            "bdt" => "BDT - Bangladeshi Taka",
            "bgn" => "BGN - Bulgarian Lev",
            "bif" => "BIF - Burundian Franc",
            "bmd" => "BMD - Bermudian Dollar",
            "bnd" => "BND - Brunei Dollar",
            "bsd" => "BSD - Bahamian Dollar",
            "bwp" => "BWP - Botswana Pula",
            "byn" => "BYN - Belarusian Ruble",
            "bzd" => "BZD - Belize Dollar",
            "cad" => "CAD - Canadian Dollar",
            "cdf" => "CDF - Congolese Franc",
            "chf" => "CHF - Swiss Franc",
            "cny" => "CNY - Chinese Yuan",
            "czk" => "CZK - Czech Koruna",
            "dkk" => "DKK - Danish Krone",
            "dop" => "DOP - Dominican Peso",
            "dzd" => "DZD - Algerian Dinar",
            "egp" => "EGP - Egyptian Pound",
            "etb" => "ETB - Ethiopian Birr",
            "eur" => "EUR - Euro",
            "fjd" => "FJD - Fijian Dollar",
            "gbp" => "GBP - British Pound Sterling",
            "gel" => "GEL - Georgian Lari",
            "gip" => "GIP - Gibraltar Pound",
            "gmd" => "GMD - Gambian Dalasi",
            "gyd" => "GYD - Guyanese Dollar",
            "hkd" => "HKD - Hong Kong Dollar",
            "htg" => "HTG - Haitian Gourde",
            "huf" => "HUF - Hungarian Forint",
            "idr" => "IDR - Indonesian Rupiah",
            "ils" => "ILS - Israeli New Shekel",
            "inr" => "INR - Indian Rupee",
            "isk" => "ISK - Icelandic Króna",
            "jmd" => "JMD - Jamaican Dollar",
            "jpy" => "JPY - Japanese Yen",
            "kes" => "KES - Kenyan Shilling",
            "kgs" => "KGS - Kyrgyzstani Som",
            "khr" => "KHR - Cambodian Riel",
            "kmf" => "KMF - Comorian Franc",
            "krw" => "KRW - South Korean Won",
            "kyd" => "KYD - Cayman Islands Dollar",
            "kzt" => "KZT - Kazakhstani Tenge",
            "lbp" => "LBP - Lebanese Pound",
            "lkr" => "LKR - Sri Lankan Rupee",
            "lrd" => "LRD - Liberian Dollar",
            "lsl" => "LSL - Lesotho Loti",
            "mad" => "MAD - Moroccan Dirham",
            "mdl" => "MDL - Moldovan Leu",
            "mga" => "MGA - Malagasy Ariary",
            "mkd" => "MKD - Macedonian Denar",
            "mmk" => "MMK - Myanmar Kyat",
            "mnt" => "MNT - Mongolian Tögrög",
            "mop" => "MOP - Macanese Pataca",
            "mvr" => "MVR - Maldivian Rufiyaa",
            "mwk" => "MWK - Malawian Kwacha",
            "mxn" => "MXN - Mexican Peso",
            "myr" => "MYR - Malaysian Ringgit",
            "mzn" => "MZN - Mozambican Metical",
            "nad" => "NAD - Namibian Dollar",
            "ngn" => "NGN - Nigerian Naira",
            "nok" => "NOK - Norwegian Krone",
            "npr" => "NPR - Nepalese Rupee",
            "nzd" => "NZD - New Zealand Dollar",
            "pgk" => "PGK - Papua New Guinean Kina",
            "php" => "PHP - Philippine Peso",
            "pkr" => "PKR - Pakistani Rupee",
            "pln" => "PLN - Polish Zloty",
            "qar" => "QAR - Qatari Rial",
            "ron" => "RON - Romanian Leu",
            "rsd" => "RSD - Serbian Dinar",
            "rub" => "RUB - Russian Ruble",
            "rwf" => "RWF - Rwandan Franc",
            "sar" => "SAR - Saudi Riyal",
            "sbd" => "SBD - Solomon Islands Dollar",
            "scr" => "SCR - Seychellois Rupee",
            "sek" => "SEK - Swedish Krona",
            "sgd" => "SGD - Singapore Dollar",
            "sle" => "SLE - Sierra Leonean Leone",
            "sos" => "SOS - Somali Shilling",
            "szl" => "SZL - Swazi Lilangeni",
            "thb" => "THB - Thai Baht",
            "tjs" => "TJS - Tajikistani Somoni",
            "top" => "TOP - Tongan Paʻanga",
            "try" => "TRY - Turkish Lira",
            "ttd" => "TTD - Trinidad and Tobago Dollar",
            "twd" => "TWD - New Taiwan Dollar",
            "tzs" => "TZS - Tanzanian Shilling",
            "uah" => "UAH - Ukrainian Hryvnia",
            "ugx" => "UGX - Ugandan Shilling",
            "uzs" => "UZS - Uzbekistani Som",
            "vnd" => "VND - Vietnamese Dong",
            "vuv" => "VUV - Vanuatu Vatu",
            "wst" => "WST - Samoan Tala",
            "xaf" => "XAF - Central African CFA Franc",
            "xcd" => "XCD - East Caribbean Dollar",
            "xcg" => "XCG - Gold Currency Unit",
            "yer" => "YER - Yemeni Rial",
            "zar" => "ZAR - South African Rand",
            "zmw" => "ZMW - Zambian Kwacha",
            "afn" => "AFN - Afghan Afghani",
            "aoa" => "AOA - Angolan Kwanza",
            "ars" => "ARS - Argentine Peso",
            "bob" => "BOB - Bolivian Boliviano",
            "brl" => "BRL - Brazilian Real",
            "clp" => "CLP - Chilean Peso",
            "cop" => "COP - Colombian Peso",
            "crc" => "CRC - Costa Rican Colón",
            "cve" => "CVE - Cape Verdean Escudo",
            "djf" => "DJF - Djiboutian Franc",
            "fkp" => "FKP - Falkland Islands Pound",
            "gnf" => "GNF - Guinean Franc",
            "gtq" => "GTQ - Guatemalan Quetzal",
            "hnl" => "HNL - Honduran Lempira",
            "lak" => "LAK - Laotian Kip",
            "mur" => "MUR - Mauritian Rupee",
            "nio" => "NIO - Nicaraguan Córdoba",
            "pab" => "PAB - Panamanian Balboa",
            "pen" => "PEN - Peruvian Sol",
            "pyg" => "PYG - Paraguayan Guarani",
            "shp" => "SHP - Saint Helena Pound",
            "srd" => "SRD - Surinamese Dollar",
            "std" => "STD - São Tomé and Príncipe Dobra",
            "uyu" => "UYU - Uruguayan Peso",
            "xof" => "XOF - West African CFA Franc",
            "xpf" => "XPF - CFP Franc"
        ];

        asort($currencies);
        return $currencies;
    }

    /**
     * Is the plugin loaded?
     *
     * @return boolean
     */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Are amounts loaded?
     *
     * @return boolean
     */
    public function areAmountsLoaded(): bool
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
    public function setPubKey(string $pubkey): void
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
    public function setPrivKey(string $privkey): void
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
    public function setWebhookSecret(string $secret): void
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
    public function setCountry(string $country): void
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
    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    /**
     * Set new prices
     *
     * @param array<int, string> $ids     array of identifier
     * @param array<int, string> $amounts array of amounts
     *
     * @return void
     */
    public function setPrices(array $ids, array $amounts): void
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
    public function isInactive(int $id): bool
    {
        return in_array($id, $this->inactives);
    }

    /**
     * Set inactives types
     *
     * @param array<int, string> $inactives array of inactives types
     *
     * @return void
     */
    public function setInactives(array $inactives): void
    {
        $this->inactives = $inactives;
    }

    /**
     * Unset inactives types
     *
     * @return void
     */
    public function unsetInactives(): void
    {
        $this->inactives = [];
    }
}
