<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Stripe history management
 *
 * Copyright © 2021 The Galette Team
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
 *
 * @author    Mathieu PELLEGRIN <dev@pingveno.net>
 * @copyright 2021 The Galette Team
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GPL License 3.0 or (at your option) any later version
 * @link      http://galette.tuxfamily.org
 */

namespace GaletteStripe;

use Analog\Analog;
use Galette\Core\Db;
use Galette\Core\Login;
use Galette\Core\History;
use Galette\Core\Preferences;
use Galette\Filters\HistoryList;

/**
 * This class stores and serve the Stripe History.
 *
 * @category  Classes
 * @name      StripeHistory
 * @package   GaletteStripe
 * @author    Mathieu PELLEGRIN <dev@pingveno.net>
 * @copyright 2021 The Galette Team
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GPL License 3.0 or (at your option) any later version
 * @link      http://galette.tuxfamily.org
 */
class StripeHistory extends History
{
    public const TABLE = 'history';
    public const PK = 'id_stripe';

    public const STATE_NONE = 0;
    public const STATE_PROCESSED = 1;
    public const STATE_DONE = 2;
    public const STATE_ERROR = 3;
    public const STATE_INCOMPLETE = 4;
    public const STATE_ALREADYDONE = 5;

    private $id;

    /**
     * Default constructor.
     *
     * @param Db          $zdb         Database
     * @param Login       $login       Login
     * @param Preferences $preferences Preferences
     * @param HistoryList $filters     Filtering
     */
    public function __construct(Db $zdb, Login $login, Preferences $preferences, $filters = null)
    {
        $this->with_lists = false;
        parent::__construct($zdb, $login, $preferences, $filters);
    }

    /**
     * Add a new entry
     *
     * @param string $action   the action to log
     * @param string $argument the argument
     * @param string $query    the query (if relevant)
     *
     * @return bool true if entry was successfully added, false otherwise
     */
    public function add($action, $argument = '', $query = '')
    {
        $request = $action;
        try {
            $values = array(
                'history_date'  => date('Y-m-d H:i:s'),
                'intent_id'     => $request['data']['object']['id'],
                'amount'        => $request['data']['object']['amount'] / 100, // Stripe handles cent
                'comment'       => $request['data']['object']['description'],
                'metadata'      => serialize($request['data']['object']['metadata']),
                'state'         => self::STATE_NONE
            );

            $insert = $this->zdb->insert($this->getTableName());
            $insert->values($values);
            $this->zdb->execute($insert);
            //$this->id = $this->zdb->getLastGeneratedValue($this);

            Analog::log(
                'An entry has been added in stripe history',
                Analog::INFO
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
     * @param boolean $prefixed Whether table name should be prefixed
     *
     * @return string
     */
    protected function getTableName($prefixed = false)
    {
        if ($prefixed === true) {
            return PREFIX_DB . STRIPE_PREFIX . self::TABLE;
        } else {
            return STRIPE_PREFIX . self::TABLE;
        }
    }

    /**
     * Get table's PK
     *
     * @return string
     */
    protected function getPk()
    {
        return self::PK;
    }

    /**
     * Gets Stripe history
     *
     * @return array
     */
    public function getStripeHistory()
    {
        $orig = $this->getHistory();
        $new = array();
        $dedup = array();
        if (count($orig) > 0) {
            foreach ($orig as $o) {
                try {
                    $oa = unserialize($o['metadata']);
                } catch (\ErrorException $err) {
                    Analog::log(
                        'Error loading Stripe history entry #' . $o[$this->getPk()] .
                        ' ' . $err->getMessage(),
                        Analog::WARNING
                    );

                    //maybe an unserialization issue, try to fix
                    $data = preg_replace_callback(
                        '!s:(\d+):"(.*?)";!',
                        function ($match) {
                            return ($match[1] == strlen($match[2])) ?
                                $match[0] : 's:' . strlen($match[2]) . ':"' . $match[2] . '";';
                        },
                        $o['metadata']
                    );
                    $oa = unserialize($data);
                } catch (\Exception $e) {
                    Analog::log(
                        'Error loading Stripe history entry #' . $o[$this->getPk()] .
                        ' ' . $e->getMessage(),
                        Analog::WARNING
                    );
                    throw $e;
                }

                $o['raw_request'] = print_r($oa, true);
                $o['metadata'] = $oa;
                if (in_array($o['intent_id'], $dedup)) {
                    $o['duplicate'] = true;
                } else {
                    $dedup[] = $o['intent_id'];
                }

                $new[] = $o;
            }
        }
        return $new;
    }

    /**
     * Builds the order clause
     *
     * @return string SQL ORDER clause
     */
    protected function buildOrderClause()
    {
        $order = array();

        switch ($this->filters->orderby) {
            case HistoryList::ORDERBY_DATE:
                $order[] = 'history_date ' . $this->filters->ordered;
                break;
        }

        return $order;
    }

    /**
     * Is payment already processed?
     *
     * @param string $sign Verify sign stripe parameter
     *
     * @return boolean
     */
    public function isProcessed(string $sign): bool
    {
        $select = $this->zdb->select($this->getTableName());
        $select->where([
            'signature' => $sign,
            'state'     => self::STATE_PROCESSED
        ]);
        $results = $this->zdb->execute($select);

        return (count($results) > 0);
    }

    /**
     * Set payment state
     *
     * @param integer $state State, one of self::STATE_ constants
     *
     * @return boolean
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
