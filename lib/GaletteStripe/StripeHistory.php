<?php

/**
 * Copyright © 2003-2026 The Galette Team
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
use Galette\Core\Galette;
use Galette\Core\Login;
use Galette\Core\History;
use Galette\Core\Preferences;
use Galette\Filters\HistoryList;
use Stripe\StripeClient;

/**
 * This class stores and serve the Stripe History.
 *
 * @author Johan Cwiklinski <johan@x-tnd.be>
 * @author Mathieu PELLEGRIN <dev@pingveno.net>
 * @author manuelh78 <manuelh78dev@ik.me>
 * @author Guillaume AGNIERAY <dev@agnieray.net>
 */
class StripeHistory extends History
{
    public const TABLE = 'history';
    public const PK = 'id_stripe';

    public const STATE_NONE = 0;
    public const STATE_PROCESSED = 1;
    public const STATE_ERROR = 2;
    public const STATE_INCOMPLETE = 3;
    public const STATE_ALREADYDONE = 4;

    private int $id;

    /**
     * Default constructor.
     *
     * @param Db           $zdb         Database
     * @param Login        $login       Login
     * @param Preferences  $preferences Preferences
     * @param ?HistoryList $filters     Filtering
     */
    public function __construct(Db $zdb, Login $login, Preferences $preferences, ?HistoryList $filters = null)
    {
        $this->with_lists = false;
        parent::__construct($zdb, $login, $preferences, $filters);
    }

    /**
     * Add a new entry
     *
     * @param array|string $action   the action to log
     * @param string       $argument the argument
     * @param string       $query    the query (if relevant)
     *
     * @return bool true if entry was successfully added, false otherwise
     */
    public function add(array|string $action, string $argument = '', string $query = ''): bool
    {
        $stripe = new Stripe($this->zdb, $this->preferences);
        $request = $action;
        $payment_method = $this->getStripePaymentMethod($request['data']['object']['payment_method']);
        try {
            $values = [
                'history_date'  => date('Y-m-d H:i:s'),
                'intent_id'     => $request['data']['object']['id'],
                'amount'        => $stripe->isZeroDecimal($stripe->getCurrency()) ? $request['data']['object']['amount'] : $request['data']['object']['amount'] / 100,
                'payer_name'    => $payment_method['billing_details']['name'],
                'comments'      => $request['data']['object']['metadata']['item_name'],
                'request'       => Galette::jsonEncode($request),
                'state'         => self::STATE_NONE
            ];

            $insert = $this->zdb->insert($this->getTableName());
            $insert->values($values);
            $this->zdb->execute($insert);
            $this->id = (int)$this->zdb->driver->getLastGeneratedValue();

            Analog::log(
                'An entry has been added in stripe history',
                Analog::DEBUG
            );
        } catch (\Exception $e) {
            Analog::log(
                "An error occured trying to add log entry. " . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }

        return true;
    }

    /**
     * Get table's name
     *
     * @param bool $prefixed Whether table name should be prefixed
     */
    protected function getTableName(bool $prefixed = false): string
    {
        if ($prefixed === true) {
            return PREFIX_DB . STRIPE_PREFIX . self::TABLE;
        } else {
            return STRIPE_PREFIX . self::TABLE;
        }
    }

    /**
     * Get table's PK
     */
    protected function getPk(): string
    {
        return self::PK;
    }

    /**
     * Gets Stripe history
     */
    public function getStripeHistory(): array
    {
        $orig = $this->getHistory();
        $new = [];
        $dedup = [];
        if (count($orig) > 0) {
            foreach ($orig as $o) {
                try {
                    if (Galette::isSerialized($o['request'])) {
                        $oa = unserialize($o['request']);
                    } else {
                        $oa = Galette::jsonDecode($o['request']);
                    }

                    $o['raw_request'] = print_r($oa, true);
                    $o['request'] = $oa;
                    if (in_array($o['intent_id'], $dedup)) {
                        $o['duplicate'] = true;
                    } else {
                        $dedup[] = $o['intent_id'];
                    }

                    $new[] = $o;
                } catch (\Exception $e) {
                    Analog::log(
                        'Error loading Stripe history entry #' . $o[$this->getPk()]
                        . ' ' . $e->getMessage(),
                        Analog::WARNING
                    );
                }
            }
        }
        return $new;
    }

    /**
     * Gets Stripe Payment Method details
     *
     * @param string $id ID of the payment method to retrieve
     */
    public function getStripePaymentMethod(string $id): array
    {
        $stripe = new Stripe($this->zdb, $this->preferences);
        $stripe_client = new StripeClient($stripe->getPrivKey());

        $paymentMethod = $stripe_client->paymentMethods->retrieve($id, []);
        $content = json_encode($paymentMethod);

        return json_decode($content, true);
    }

    /**
     * Builds the order clause
     *
     * @return array SQL ORDER clause
     */
    protected function buildOrderClause(): array
    {
        $order = [];

        if ($this->filters->orderby == HistoryList::ORDERBY_DATE) {
            $order[] = 'history_date ' . $this->filters->getDirection();
        }

        return $order;
    }

    /**
     * Is payment already processed?
     *
     * @param array $request Verify sign stripe parameter
     */
    public function isProcessed(array $request): bool
    {
        $select = $this->zdb->select($this->getTableName());
        $select->where(
            [
                'intent_id' => $request['data']['object']['id'],
                'state'     => self::STATE_PROCESSED
            ]
        );
        $results = $this->zdb->execute($select);

        return (count($results) > 0);
    }

    /**
     * Set payment state
     *
     * @param int $state State, one of self::STATE_ constants
     */
    public function setState(int $state): bool
    {
        try {
            $update = $this->zdb->update($this->getTableName());
            $update
                ->set(['state' => $state])
                ->where([self::PK => $this->id]);
            $this->zdb->execute($update);
            return true;
        } catch (\Exception $e) {
            Analog::log(
                'An error occurred when updating state field | ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return false;
    }
}
