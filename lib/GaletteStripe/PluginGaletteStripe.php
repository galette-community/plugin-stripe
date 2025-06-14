<?php

namespace GaletteStripe;

use Galette\Core\Login;
use Galette\Entity\Adherent;
use Galette\Core\GalettePlugin;

class PluginGaletteStripe extends GalettePlugin
{
    /**
     * Extra menus entries
     *
     * @return array<string, string|array<string,mixed>>
     */
    public static function getMenusContents(): array
    {
        /**
         * @var Login $login
         */
        global $login;
        $content = [
            'title' => _T("Stripe", "stripe"),
            'icon' => 'stripe'
        ];
        $content['items'] = [];

        if ($login->isAdmin() || $login->isStaff()) {

            $content['items'] = [
                [
                    'label' => _T("Stripe History", "stripe"),
                    'route' => [
                        'name' => 'stripe_history'
                    ]
                ],
                [
                    'label' => _T("Settings"),
                    'route' => [
                        'name' => 'stripe_preferences'
                    ]
                ]
            ];
        }

        $menus['plugin_stripe'] = $content;
        return $menus;
    }

    /**
     * Extra public menus entries
     *
     * @return array<int, string|array<string,mixed>>
     */
    public static function getPublicMenusItemsList(): array
    {
        return [
            [
                'label' => _T("Payment form", "stripe"),
                'route' => [
                    'name' => 'stripe_form'
                ],
                'icon' => 'stripe'
            ]
        ];
    }

    /**
     * Get dashboards contents
     *
     * @return array<int, string|array<string,mixed>>
     */
    public static function getDashboardsContents(): array
    {
        /** @var Login $login */
        global $login;
        /** @var Preferences $preferences */
        global $preferences;
        $contents = [];

        if ($preferences->showPublicPages($login)) {
            $contents[] = [
                'label' => _T("Payment form", "stripe"),
                'route' => [
                    'name' => 'stripe_form'
                ],
                'icon' => 'stripe'
            ];
        }
        return $contents;
    }

    /**
     * Get current logged-in user dashboards contents
     *
     * @return array<int, string|array<string,mixed>>
     */
    public static function getMyDashboardsContents(): array
    {
        return [];
    }

    /**
     * Get actions contents
     *
     * @param Adherent $member Member instance
     *
     * @return array<int, string|array<string,mixed>>
     */
    public static function getListActionsContents(Adherent $member): array
    {
        return [];
    }

    /**
     * Get detailed actions contents
     *
     * @param Adherent $member Member instance
     *
     * @return array<int, string|array<string,mixed>>
     */
    public static function getDetailedActionsContents(Adherent $member): array
    {
        return static::getListActionsContents($member);
    }

    /**
     * Get batch actions contents
     *
     * @return array<int, string|array<string,mixed>>
     */
    public static function getBatchActionsContents(): array
    {
        return [];
    }
}
