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

namespace GaletteStripe\Controllers;

use Analog\Analog;
use DI\Attribute\Inject;
use Galette\Controllers\AbstractPluginController;
use Galette\Entity\Adherent;
use Galette\Entity\Contribution;
use Galette\Entity\ContributionsTypes;
use Galette\Entity\PaymentType;
use Galette\Filters\HistoryList;
use GaletteStripe\Stripe;
use GaletteStripe\StripeHistory;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Throwable;

/**
 * Galette Stripe plugin controller
 *
 * @author Johan Cwiklinski <johan@x-tnd.be>
 * @author Mathieu PELLEGRIN <dev@pingveno.net>
 * @author manuelh78 <manuelh78dev@ik.me>
 * @author Guillaume AGNIERAY <dev@agnieray.net>
 */

class StripeController extends AbstractPluginController
{
    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Stripe")]
    protected array $module_info;

    /**
     * Main form
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function form(Request $request, Response $response): Response
    {
        $stripe = new Stripe($this->zdb, $this->preferences);

        $current_url = $this->preferences->getURL();

        $params = [
            'stripe'        => $stripe,
            'amounts'       => $stripe->getAmounts($this->login),
            'page_title'    => _T('Online payment', 'stripe'),
            'message'       => null,
            'current_url'   => rtrim($current_url, '/'),
        ];

        if (!$stripe->isLoaded()) {
            $this->flash->addMessageNow(
                'error',
                _T("<strong>Payment could not work</strong>: An error occurred (that has been logged) while loading Stripe settings from the database.<br/>Please report the issue to the staff.", "stripe")
                . '<br/>' . _T("Our apologies for the annoyance.", "stripe")
            );
        }

        if ($stripe->getPubKey() == null || $stripe->getPrivKey() == null) {
            $this->flash->addMessageNow(
                'error',
                _T("Stripe keys have not been defined. Please ask an administrator to add them in the plugin's settings.", "stripe")
            );
        }

        // display page
        $this->view->render(
            $response,
            $this->getTemplate('stripe_form'),
            $params
        );
        return $response;
    }

    /**
     * Checkout form
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function formCheckout(Request $request, Response $response): Response
    {
        $stripe_request = $request->getParsedBody();
        $stripe = new Stripe($this->zdb, $this->preferences);
        $adherent = new Adherent($this->zdb);

        $current_url = $this->preferences->getURL();

        // Check the amount
        $item_id = $stripe_request['item_id'];
        $stripe_amounts = $stripe->getAmounts($this->login);
        $amount = $stripe_request['amount'];
        $amount_check = $stripe->isZeroDecimal($stripe->getCurrency()) ? round((float)$stripe_amounts[$item_id]['amount']) : $stripe_amounts[$item_id]['amount'];

        if ($amount < $amount_check) {
            $this->flash->addMessage(
                'error_detected',
                _T("The amount you've entered is lower than the minimum amount for the selected option. Please choose another option or change the amount.", "stripe")
            );

            return $response
                ->withStatus(301)
                ->withHeader('Location', $this->routeparser->urlFor('stripe_form'));
        } else {
            $metadata = [];

            if ($this->login->isLogged() && !$this->login->isSuperAdmin()) {
                $adherent->load($this->login->id);
                $metadata['member_id'] = $this->login->id;
                $metadata['checkout_name'] = $adherent->name;
                $metadata['checkout_firstname'] = $adherent->surname;
                $metadata['checkout_email'] = $adherent->getEmail();
                $metadata['checkout_address'] = preg_replace('/\r\n|\r|\n/', ', ', $adherent->getAddress());
                $metadata['checkout_city'] = $adherent->getTown();
                $metadata['checkout_zipcode'] = $adherent->getZipcode();
                $metadata['checkout_country'] = $adherent->getCountry();
                $metadata['checkout_company'] = $adherent->company_name;
            }

            $metadata['item_id'] = $item_id;
            $metadata['item_name'] = $stripe_amounts[$item_id]['name'];

            $checkout = $stripe->checkout($metadata, $amount, $stripe->getCurrency());

            if (!$checkout) {
                $this->flash->addMessage(
                    'error_detected',
                    _T('An error occured loading the checkout form.', 'stripe')
                );

                return $response
                    ->withStatus(301)
                    ->withHeader('Location', $this->routeparser->urlFor('stripe_form'));
            } else {
                return $response
                    ->withStatus(301)
                    ->withHeader('Location', $checkout['url']);
            }
        }
    }

    /**
     * Logs page
     *
     * @param Request         $request  PSR Request
     * @param Response        $response PSR Response
     * @param string|null     $option   Either order, reset or page
     * @param string|int|null $value    Option value
     *
     * @return Response
     */
    public function history(Request $request, Response $response, ?string $option = null, string|int|null $value = null): Response
    {
        $stripe_history = new StripeHistory($this->zdb, $this->login, $this->preferences);

        $filters = [];
        if (isset($this->session->filter_stripe_history)) {
            $filters = $this->session->filter_stripe_history;
        } else {
            $filters = new HistoryList();
        }

        if ($option !== null) {
            switch ($option) {
                case 'page':
                    $filters->current_page = (int) $value;
                    break;
                case 'order':
                    $filters->orderby = $value;
                    break;
                case 'reset':
                    $filters = new HistoryList();
                    break;
            }
        }
        $this->session->filter_stripe_history = $filters;

        //assign pagination variables to the template and add pagination links
        $stripe_history->setFilters($filters);
        $logs = $stripe_history->getStripeHistory();
        $logs_count = $stripe_history->getCount();
        $filters->setViewPagination($this->routeparser, $this->view);

        $params = [
            'page_title'        => _T("Stripe History", "stripe"),
            'stripe_history'    => $stripe_history,
            'logs'              => $logs,
            'nb'                => $logs_count,
            'module_id'         => $this->getModuleId()
        ];

        $this->session->filter_stripe_history = $filters;

        // display page
        $this->view->render(
            $response,
            $this->getTemplate('stripe_history'),
            $params
        );
        return $response;
    }

    /**
     * Filter
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function filter(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();

        //reset history
        $filters = $this->session->filter_stripe_history ?? new HistoryList();
        if (isset($post['reset']) && isset($post['nbshow'])) {
        } else {
            //number of rows to show
            $filters->show = $post['nbshow'];
        }

        $this->session->filter_stripe_history = $filters;

        return $response
            ->withStatus(301)
            ->withHeader('Location', $this->routeparser->urlFor('stripe_history'));
    }

    /**
     * Preferences
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function preferences(Request $request, Response $response): Response
    {
        if ($this->session->stripe !== null) {
            $stripe = $this->session->stripe;
            $this->session->stripe = null;
        } else {
            $stripe = new Stripe($this->zdb, $this->preferences);
        }

        $current_country = $stripe->getCountry();
        $amounts = $stripe->getAllAmounts();
        $countries = $stripe->getAllCountries();
        $currencies = $stripe->getAllCurrencies($current_country);

        $params = [
            'page_title'    => _T('Stripe Settings', 'stripe'),
            'stripe'        => $stripe,
            'webhook_url'   => $this->preferences->getURL() . $this->routeparser->urlFor('stripe_webhook'),
            'amounts'       => $amounts,
            'countries'     => $countries,
            'currencies'    => $currencies,
        ];

        // display page
        $this->view->render(
            $response,
            $this->getTemplate('stripe_preferences'),
            $params
        );
        return $response;
    }

    /**
     * Store Preferences
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function storePreferences(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $stripe = new Stripe($this->zdb, $this->preferences);

        if (isset($post['stripe_pubkey']) && $this->login->isAdmin()) {
            $stripe->setPubKey($post['stripe_pubkey']);
        }
        if (isset($post['stripe_privkey']) && $this->login->isAdmin()) {
            $stripe->setPrivKey($post['stripe_privkey']);
        }
        if (isset($post['stripe_webhook_secret']) && $this->login->isAdmin()) {
            $stripe->setWebhookSecret($post['stripe_webhook_secret']);
        }
        if (isset($post['stripe_country']) && $this->login->isAdmin()) {
            $stripe->setCountry($post['stripe_country']);
        }
        if (isset($post['stripe_currency']) && $this->login->isAdmin()) {
            $stripe->setCurrency($post['stripe_currency']);
        }
        if (isset($post['inactives'])) {
            $stripe->setInactives($post['inactives']);
        } else {
            $stripe->unsetInactives();
        }

        if ($stripe->getCurrency() === '') {
            $this->flash->addMessage(
                'error_detected',
                _T('You have to select a currency.', 'stripe')
            );
        } else {
            $stored = $stripe->store();
            if ($stored) {
                $this->flash->addMessage(
                    'success_detected',
                    _T('Stripe settings have been saved.', 'stripe')
                );
            } else {
                $this->session->stripe = $stripe;
                $this->flash->addMessage(
                    'error_detected',
                    _T('An error occured saving stripe settings.', 'stripe')
                );
            }
        }

        return $response
            ->withStatus(301)
            ->withHeader('Location', $this->routeparser->urlFor('stripe_preferences'));
    }

    /**
     * Ajax currencies list refresh
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function refreshCurrencies(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $stripe = new Stripe($this->zdb, $this->preferences);
        $returnedCurrencies = [];
        try {
            $allCurrencies = $stripe->getAllCurrencies($post['country']);
            foreach ($allCurrencies as $key => $value) {
                $returnedCurrencies[] = [
                    'value' => $key,
                    'name' => $value
                ];
            }
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred while retrieving updated currencies list: ' . $e->getMessage(),
                Analog::WARNING
            );
            throw $e;
        }
        return $this->withJson($response, $returnedCurrencies); //@phpstan-ignore-line
    }

    /**
     * Webhook
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function webhook(Request $request, Response $response): Response
    {
        $body = $request->getBody();
        $post = json_decode($body->getContents(), true);
        $stripe = new Stripe($this->zdb, $this->preferences);

        // Check webhook signature
        $stripe_signatures = $request->getHeader('HTTP_STRIPE_SIGNATURE');
        foreach ($stripe_signatures as $signature) {
            $parsedSignature = explode(',', $signature);
            $sig_timestamp = null;
            $sig_hash = null;
            foreach ($parsedSignature as $chunk) {
                $pair = explode('=', $chunk);
                if ($pair[0] == 't') {
                    $sig_timestamp = $pair[1];
                }
                if ($pair[0] == 'v1') {
                    $sig_hash = $pair[1];
                }
            }

            if (abs(time() - $sig_timestamp) > 5) { //@phpstan-ignore-line
                Analog::log(
                    'Stripe signature delayed for too many seconds!',
                    Analog::ERROR
                );
                echo 'Stripe signature delayed for too many seconds!';
                return $response->withStatus(403);
            }

            $signed_body = $sig_timestamp . '.' . $body;
            $body_hash = hash_hmac('sha256', $signed_body, $stripe->getWebhookSecret());

            if ($sig_hash != $body_hash) {
                Analog::log(
                    'Stripe signature mismatch!',
                    Analog::ERROR
                );
                echo 'Stripe signature mismatch!';
                return $response->withStatus(403);
            }
        }

        Analog::log(
            "Stripe webhook request: " . var_export($post, true),
            Analog::DEBUG
        );

        if (
            isset($post['type'])
            && ($post['type'] == 'payment_intent.succeeded' || $post['type'] == 'invoice.payment_succeeded')
            && ($post['data']['object']['metadata']['item_id'] || $post['data']['object']['lines']['data'][0]['metadata']['item_id'])
        ) {
            //We accept subscription invoice (annual or monthly) ; https://stripe.com/docs/billing/subscriptions/overview
            //Todo : rewrite a more cleaner
            if ($post['type'] == 'invoice.payment_succeeded') {
                $post['data']['object']['metadata'] = array_merge($post['data']['object']['metadata'], $post['data']['object']['lines']['data'][0]['metadata']);
                $post['data']['object']['amount_received'] = $post['data']['object']['amount_paid'];
                $post['data']['object']['amount'] = $post['data']['object']['amount_due'];
                $post['data']['object']['description'] = $post['data']['object']['lines']['data'][0]['metadata']['item_name'];

                if ($post['data']['object']['status'] == 'paid') {
                    $post['data']['object']['status'] = 'succeeded';
                }
            }

            $sh = new StripeHistory($this->zdb, $this->login, $this->preferences);
            $sh->add($post);

            // are we working on a real contribution?
            $real_contrib = false;
            if (array_key_exists('member_id', $post['data']['object']['metadata'])) {
                $real_contrib = true;
            }

            if ($sh->isProcessed($post)) {
                Analog::log(
                    'A stripe payment notification has been received, but it is already processed!',
                    Analog::WARNING
                );
                $sh->setState(StripeHistory::STATE_ALREADYDONE);
            } else {
                // we'll now try to add the relevant cotisation
                if ($post['data']['object']['status'] == 'succeeded') {
                    /**
                    * We will use the following parameters:
                    * - $post['data']['object']['amount']: the amount
                    * - $post['data']['object']['metadata']['member_id']: member id
                    * - $post['data']['object']['metadata']['item_id']: contribution type id
                    *
                    * If no member id is provided, we only send to post contribution
                    * script, Galette does not handle anonymous contributions
                    */
                    $amount = $post['data']['object']['amount'];
                    $member_id = array_key_exists('member_id', $post['data']['object']['metadata']) ? $post['data']['object']['metadata']['member_id'] : '';
                    $contrib_args = [
                        'type'          => $post['data']['object']['metadata']['item_id'],
                        'adh'           => $member_id,
                        'payment_type'  => PaymentType::CREDITCARD
                    ];
                    $check_contrib_args = [
                        ContributionsTypes::PK  => $post['data']['object']['metadata']['item_id'],
                        Adherent::PK            => $member_id,
                        'type_paiement_cotis'   => PaymentType::CREDITCARD,
                        'montant_cotis'         => $stripe->isZeroDecimal($stripe->getCurrency()) ? $amount : $amount / 100,
                    ];
                    if ($this->preferences->pref_membership_ext != '') { //@phpstan-ignore-line
                        $contrib_args['ext'] = $this->preferences->pref_membership_ext;
                    }
                    $contrib = new Contribution($this->zdb, $this->login, $contrib_args);

                    // all goes well, we can proceed
                    if ($real_contrib) {
                        // Check contribution to set $contrib->errors to [] and handle contribution overlap
                        $valid = $contrib->setNoCheckLogin()->check($check_contrib_args, [], []);
                        if ($valid !== true) {
                            Analog::log(
                                'An error occurred while storing a new contribution from Stripe payment:' .
                                implode("\n   ", $valid),
                                Analog::ERROR
                            );
                            $sh->setState(StripeHistory::STATE_ERROR);
                            return $response->withStatus(500, 'Internal error');
                        }

                        $store = $contrib->store();
                        if ($contrib->store()) {
                            // contribution has been stored :)
                            Analog::log(
                                'Stripe payment has been successfully registered as a contribution',
                                Analog::DEBUG
                            );
                            $sh->setState(StripeHistory::STATE_PROCESSED);
                        } else {
                            // something went wrong :'(
                            Analog::log(
                                'An error occured while storing a new contribution from Stripe payment',
                                Analog::ERROR
                            );
                            $sh->setState(StripeHistory::STATE_ERROR);
                            return $response->withStatus(500, 'Internal error');
                        }
                        return $response->withStatus(200);
                    }
                } else {
                    Analog::log(
                        'A stripe payment notification has been received, but is not completed!',
                        Analog::WARNING
                    );
                    $sh->setState(StripeHistory::STATE_INCOMPLETE);
                    return $response->withStatus(500, 'Internal error');
                }
            }
            return $response->withStatus(200);
        } else {
            // Ignore all other stripe events.
            Analog::log(
                'Stripe event ignored. Only succeeded payments events are processed.',
                Analog::DEBUG
            );
            return $response->withStatus(200);
        }
    }

    /**
     * Success URL
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function successUrl(Request $request, Response $response): Response
    {
        $params = [
            'page_title'    => _T('Payment success', 'stripe')
        ];

        // display page
        $this->view->render(
            $response,
            $this->getTemplate('stripe_success'),
            $params
        );
        return $response;
    }

    /**
     * Cancel URL
     *
     * @param Request  $request  PSR Request
     * @param Response $response PSR Response
     *
     * @return Response
     */
    public function cancelUrl(Request $request, Response $response): Response
    {
        $this->flash->addMessage(
            'warning_detected',
            _T('Your payment has been aborted!', 'stripe')
        );
        return $response
            ->withStatus(301)
            ->withHeader('Location', $this->routeparser->urlFor('stripe_form'));
    }
}
