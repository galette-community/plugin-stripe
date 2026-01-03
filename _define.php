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

$this->register(
    'Galette Stripe',                                   //Name
    'Stripe integration',                               //Short description
    'Mathieu PELLEGRIN, manuelh78, Guillaume AGNIERAY', //Author
    '1.0.0-alpha1',                                     //Version
    '1.2.1',                                            //Galette compatible version
    'stripe',                                           //routing name and translation domain
    '2025-12-08',                                       //Release date
    [   //Permissions needed
        'stripe_preferences'        => 'staff',
        'store_stripe_preferences'  => 'staff',
        'stripe_history'            => 'staff',
        'filter_stripe_history'     => 'staff',
        'refresh_currencies'        => 'admin'
    ]
);

$this->setCsrfExclusions(
    [
        '/stripe_(webhook|success|cancel)/',
    ]
);
