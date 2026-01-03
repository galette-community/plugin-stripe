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

namespace GaletteStripe\tests\units;

use Galette\Tests\GaletteTestCase;

/**
 * Stripe tests
 *
 * @author Johan Cwiklinski <johan@x-tnd.be>
 * @author Guillaume AGNIERAY <dev@agnieray.net>
 */
class Stripe extends GaletteTestCase
{
    protected int $seed = 20250617153548;

    /**
     * Cleanup after each test method
     */
    public function tearDown(): void
    {
        $delete = $this->zdb->delete(STRIPE_PREFIX . \GaletteStripe\Stripe::TABLE);
        $this->zdb->execute($delete);
        parent::tearDown();
    }

    /**
     * Test empty
     */
    public function testEmpty(): void
    {
        $stripe = new \GaletteStripe\Stripe($this->zdb, $this->preferences);
        $this->assertSame('', $stripe->getPubKey());
        $this->assertSame('', $stripe->getPrivKey());
        $this->assertSame('', $stripe->getWebhookSecret());
        $this->assertSame('FR', $stripe->getCountry());
        $this->assertSame('eur', $stripe->getCurrency());

        $amounts = $stripe->getAmounts($this->login);
        $this->assertCount(0, $amounts);
        $this->assertCount(7, $stripe->getAllAmounts());
        $this->assertTrue($stripe->areAmountsLoaded());
        $this->assertTrue($stripe->isLoaded());
    }
}
