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
use Stripe\StripeClient;

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
    public const TABLE = 'preferences';

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
        $this->webhook_secret = null;
        $this->country = 'FR';
        $this->currency = null;
        $this->load();
    }

    /**
     * Load preferences form the database and amounts from core contributions types
     *
     * @return void
     */
    public function load(): void
    {
        try {
            $results = $this->zdb->selectAll(STRIPE_PREFIX . self::TABLE);

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
            $this->loadContributionsTypes();
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
     * Load amounts from core contributions types
     *
     * @return void
     */
    private function loadContributionsTypes(): void
    {
        try {
            $ct = new ContributionsTypes($this->zdb);
            $this->prices = $ct->getCompleteList();
            //amounts should be loaded here
            $this->amounts_loaded = true;
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot load amounts from core contributions types' .
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
            $update = $this->zdb->update(STRIPE_PREFIX . self::TABLE);
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
            $update = $this->zdb->update(STRIPE_PREFIX . self::TABLE);
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
            $update = $this->zdb->update(STRIPE_PREFIX . self::TABLE);
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
            $update = $this->zdb->update(STRIPE_PREFIX . self::TABLE);
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
            $update = $this->zdb->update(STRIPE_PREFIX . self::TABLE);
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
            $update = $this->zdb->update(STRIPE_PREFIX . self::TABLE);
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

            return true;
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
     * Create payment intent
     *
     * @param array<string, mixed> $metadata Array of metadata to transmit with payment
     * @param string               $amount   Amount of payment
     * @param string               $currency Currency used
     *
     * @return string|bool
     */
    public function createPaymentIntent(array $metadata, string $amount, string $currency): string|bool
    {
        try {
            $stripe = new StripeClient($this->getPrivKey());
            $amountIntended = $this->isZeroDecimal($currency) ? round((float)$amount) : (float)$amount * 100;
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => (int)$amountIntended, // Stripe needs integer cents as amount
                'currency' => $this->getCurrency(),
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => $metadata
            ]);

            return $paymentIntent->client_secret;
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot create Stripe payment intent' .
                '` | ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
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
            $amount = $this->isZeroDecimal($this->getCurrency()) ? round((float)$v['amount']) : $v['amount'];
            if (!$this->isInactive($k) && $amount > 0) {
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
     * @param string $country Stripe country
     *
     * @return array<string, string>
     */
    public function getAllCurrencies(string $country): array
    {
        // Currencies names are voluntarily not translatable.
        $allCurrencies = [
            "aed" => "AED - United Arab Emirates Dirham",
            "afn" => "AFN - Afghan Afghani",
            "all" => "ALL - Albanian Lek",
            "amd" => "AMD - Armenian Dram",
            "ang" => "ANG - Netherlands Antillean Guilder",
            "aoa" => "AOA - Angolan Kwanza",
            "ars" => "ARS - Argentine Peso",
            "aud" => "AUD - Australian Dollar",
            "awg" => "AWG - Aruban Florin",
            "azn" => "AZN - Azerbaijani Manat",
            "bam" => "BAM - Bosnia and Herzegovina Convertible Mark",
            "bbd" => "BBD - Barbadian Dollar",
            "bdt" => "BDT - Bangladeshi Taka",
            "bgn" => "BGN - Bulgarian Lev",
            "bhd" => "BHD - Bahraini Dinar",
            "bif" => "BIF - Burundian Franc",
            "bmd" => "BMD - Bermudian Dollar",
            "bnd" => "BND - Brunei Dollar",
            "bob" => "BOB - Bolivian Boliviano",
            "brl" => "BRL - Brazilian Real",
            "bsd" => "BSD - Bahamian Dollar",
            "bwp" => "BWP - Botswana Pula",
            "byn" => "BYN - Belarusian Ruble",
            "bzd" => "BZD - Belize Dollar",
            "cad" => "CAD - Canadian Dollar",
            "cdf" => "CDF - Congolese Franc",
            "chf" => "CHF - Swiss Franc",
            "clp" => "CLP - Chilean Peso",
            "cny" => "CNY - Chinese Yuan",
            "cop" => "COP - Colombian Peso",
            "crc" => "CRC - Costa Rican Colón",
            "cve" => "CVE - Cape Verdean Escudo",
            "czk" => "CZK - Czech Koruna",
            "djf" => "DJF - Djiboutian Franc",
            "dkk" => "DKK - Danish Krone",
            "dop" => "DOP - Dominican Peso",
            "dzd" => "DZD - Algerian Dinar",
            "egp" => "EGP - Egyptian Pound",
            "etb" => "ETB - Ethiopian Birr",
            "eur" => "EUR - Euro",
            "fjd" => "FJD - Fijian Dollar",
            "fkp" => "FKP - Falkland Islands Pound",
            "gbp" => "GBP - British Pound Sterling",
            "gel" => "GEL - Georgian Lari",
            "gip" => "GIP - Gibraltar Pound",
            "gmd" => "GMD - Gambian Dalasi",
            "gnf" => "GNF - Guinean Franc",
            "gtq" => "GTQ - Guatemalan Quetzal",
            "gyd" => "GYD - Guyanese Dollar",
            "hkd" => "HKD - Hong Kong Dollar",
            "hnl" => "HNL - Honduran Lempira",
            "htg" => "HTG - Haitian Gourde",
            "huf" => "HUF - Hungarian Forint",
            "idr" => "IDR - Indonesian Rupiah",
            "ils" => "ILS - Israeli New Shekel",
            "inr" => "INR - Indian Rupee",
            "isk" => "ISK - Icelandic Króna",
            "jmd" => "JMD - Jamaican Dollar",
            "jod" => "JOD - Jordanian Dinar",
            "jpy" => "JPY - Japanese Yen",
            "kes" => "KES - Kenyan Shilling",
            "kgs" => "KGS - Kyrgyzstani Som",
            "khr" => "KHR - Cambodian Riel",
            "kmf" => "KMF - Comorian Franc",
            "krw" => "KRW - South Korean Won",
            "kwd" => "KWD - Kuwaiti Dinar",
            "kyd" => "KYD - Cayman Islands Dollar",
            "kzt" => "KZT - Kazakhstani Tenge",
            "lak" => "LAK - Laotian Kip",
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
            "mur" => "MUR - Mauritian Rupee",
            "mvr" => "MVR - Maldivian Rufiyaa",
            "mwk" => "MWK - Malawian Kwacha",
            "mxn" => "MXN - Mexican Peso",
            "myr" => "MYR - Malaysian Ringgit",
            "mzn" => "MZN - Mozambican Metical",
            "nad" => "NAD - Namibian Dollar",
            "ngn" => "NGN - Nigerian Naira",
            "nio" => "NIO - Nicaraguan Córdoba",
            "nok" => "NOK - Norwegian Krone",
            "npr" => "NPR - Nepalese Rupee",
            "nzd" => "NZD - New Zealand Dollar",
            "omr" => "OMR - Omani Rial",
            "pab" => "PAB - Panamanian Balboa",
            "pen" => "PEN - Peruvian Sol",
            "pgk" => "PGK - Papua New Guinean Kina",
            "php" => "PHP - Philippine Peso",
            "pkr" => "PKR - Pakistani Rupee",
            "pln" => "PLN - Polish Zloty",
            "pyg" => "PYG - Paraguayan Guarani",
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
            "shp" => "SHP - Saint Helena Pound",
            "sle" => "SLE - Sierra Leonean Leone",
            "sos" => "SOS - Somali Shilling",
            "srd" => "SRD - Surinamese Dollar",
            "std" => "STD - São Tomé and Príncipe Dobra",
            "szl" => "SZL - Swazi Lilangeni",
            "thb" => "THB - Thai Baht",
            "tjs" => "TJS - Tajikistani Somoni",
            "tnd" => "TND - Tunisian Dinar",
            "top" => "TOP - Tongan Paʻanga",
            "try" => "TRY - Turkish Lira",
            "ttd" => "TTD - Trinidad and Tobago Dollar",
            "twd" => "TWD - New Taiwan Dollar",
            "tzs" => "TZS - Tanzanian Shilling",
            "uah" => "UAH - Ukrainian Hryvnia",
            "ugx" => "UGX - Ugandan Shilling",
            "usd" => "USD - United States Dollar",
            "uyu" => "UYU - Uruguayan Peso",
            "uzs" => "UZS - Uzbekistani Som",
            "vnd" => "VND - Vietnamese Dong",
            "vuv" => "VUV - Vanuatu Vatu",
            "wst" => "WST - Samoan Tala",
            "xaf" => "XAF - Central African CFA Franc",
            "xcd" => "XCD - East Caribbean Dollar",
            "xcg" => "XCG - Gold Currency Unit",
            "xof" => "XOF - West African CFA Franc",
            "yer" => "YER - Yemeni Rial",
            "zar" => "ZAR - South African Rand",
            "zmw" => "ZMW - Zambian Kwacha",
            "xpf" => "XPF - CFP Franc"
        ];

        try {
            $stripe = new StripeClient($this->getPrivKey());
            $countrySpec = $stripe->countrySpecs->retrieve($country, []);
            $countryCurrencies = $countrySpec->supported_payment_currencies;

            $supportedCurrencies = array_intersect_key($allCurrencies, array_flip($countryCurrencies));
            asort($supportedCurrencies);

            return $supportedCurrencies;
        } catch (\Exception $e) {
            Analog::log(
                '[' . get_class($this) . '] Cannot get supported currencies' .
                '` | ' . $e->getMessage(),
                Analog::ERROR
            );
            return $allCurrencies;
        }
    }

    /**
     * Is currency a zero-decimal?
     * https://docs.stripe.com/currencies#zero-decimal
     *
     * @param string $currency Currency
     *
     * @return boolean
     */
    public function isZeroDecimal(string $currency): bool
    {
        $zeroDecimalCurrencies = [
            "bif",
            "clp",
            "djf",
            "gnf",
            "jpy",
            "kmf",
            "krw",
            "mga",
            "pyg",
            "rwf",
            "vnd",
            "vuv",
            "xaf",
            "xof",
            "xpf"
        ];

        return in_array($currency, $zeroDecimalCurrencies);
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
