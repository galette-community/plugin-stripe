<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Configuration file for Stripe plugin
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
 * @category  Plugins
 * @package   GaletteStripe
 *
 * @author    Mathieu PELLEGRIN <dev@pingveno.net>
 * @copyright 2021 The Galette Team
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GPL License 3.0 or (at your option) any later version
 * @link      http://galette.tuxfamily.org
 */

$this->register(
    'Galette Stripe',        //Name
    'Stripe integration',    //Short description
    'Mathieu PELLEGRIN',     //Author
    '0.0.2' ,                //Version
    '0.9.4.2',               //Galette compatible version
    'stripe',                //routing name and translation domain
    '2021-02-07',            //Release date
    [   //Permissions needed
        'stripe_preferences'        => 'staff',
        'store_stripe_preferences'  => 'staff',
        'stripe_history'            => 'staff',
        'filter_stripe_history'     => 'staff'
    ]
);
