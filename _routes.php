<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Stripe routes
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
 * @category Plugins
 * @package  GaletteStripe
 *
 * @author    Mathieu PELLEGRIN <dev@pingveno.net>; manuelh78 <manuelh78dev@ik.me>
 * @copyright 2021 The Galette Team
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GPL License 3.0 or (at your option) any later version
 * @link      http://galette.tuxfamily.org
 */
use GaletteStripe\Controllers\StripeController;

//Constants and classes from plugin
require_once $module['root'] . '/_config.inc.php';

$app->get(
    '/preferences',
    [StripeController::class, 'preferences']
)->setName('stripe_preferences')->add($authenticate);

$app->post(
    '/preferences',
    [StripeController::class, 'storePreferences']
)->setName('store_stripe_preferences')->add($authenticate);

$app->get(
    '/form',
    [StripeController::class, 'form']
)->setName('stripe_form');

$app->post(
    '/form',
    [StripeController::class, 'formCheckout']
)->setName('stripe_formCheckout');

$app->get(
    '/logs[/{option:order|reset|page}/{value}]',
    [StripeController::class, 'history']
)->setName('stripe_history')->add($authenticate);

//history filtering
$app->post(
    '/history/filter',
    [StripeController::class, 'filter']
)->setName('filter_stripe_history')->add($authenticate);

$app->post(
    '/webhook',
    [StripeController::class, 'webhook']
)->setName('stripe_webhook');