<?php

/**
 * Copyright © 2003-2025 The Galette Team
 *
 * This file is part of Galette (https://galette.eu).
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

/**
 * Galette Stripe plugin controller
 *
 * @author Johan Cwiklinski <johan@x-tnd.be>
 * @author Mathieu PELLEGRIN <dev@pingveno.net>
 * @author manuelh78 <manuelh78dev@ik.me>
 */

class StripeController extends AbstractPluginController
{
    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Stripe")]
    protected array $module_info;

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
            $stripe = new Stripe($this->zdb);
        }

        $amounts = $stripe->getAllAmounts();
        $countries = $stripe->getAllCountries();
        $currencies = $stripe->getAllCurrencies();

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
        $stripe = new Stripe($this->zdb);

        if (isset($post['amounts'])) {
            if (isset($post['stripe_pubkey']) && $this->login->isAdmin()) {
                $stripe->setPubKey($post['stripe_pubkey']);
            }
            if (isset($post['stripe_privkey']) && $this->login->isAdmin()) {
                $stripe->setPrivKey($post['stripe_privkey']);
            }
            if (isset($post['stripe_webhook_secret']) && $this->login->isAdmin()) {
                $stripe->setWebhookSecret($post['stripe_webhook_secret']);
            }
            if (isset($post['amount_id'])) {
                $stripe->setPrices($post['amount_id'], $post['amounts']);
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

            $stored = $stripe->store();
            if ($stored) {
                $this->flash->addMessage(
                    'success_detected',
                    _T('Stripe preferences has been saved.', 'stripe')
                );
            } else {
                $this->session->stripe = $stripe;
                $this->flash->addMessage(
                    'error_detected',
                    _T('An error occured saving stripe preferences :(', 'stripe')
                );
            }
        }

        return $response
            ->withStatus(301)
            ->withHeader('Location', $this->routeparser->urlFor('stripe_preferences'));
    }

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
        $stripe = new Stripe($this->zdb);

        $current_url = $this->preferences->getURL();

        $params = [
            'stripe'        => $stripe,
            'amounts'       => $stripe->getAmounts($this->login),
            'page_title'    => _T('Stripe payment', 'stripe'),
            'message'       => null,
            'current_url'   => rtrim($current_url, '/'),
        ];

        if (!$stripe->isLoaded()) {
            $this->flash->addMessageNow(
                'error',
                _T("<strong>Payment could not work</strong>: An error occurred (that has been logged) while loading Stripe preferences from the database.<br/>Please report the issue to the staff.", "stripe") .
                '<br/>' . _T("Our apologies for the annoyance :(", "stripe")
            );
        }

        if ($stripe->getPubKey() == null || $stripe->getPrivKey() == null) {
            $this->flash->addMessageNow(
                'error',
                _T("Stripe keys have not been defined. Please ask an administrator to add them in the plugin's preferences.", "stripe")
            );
        }

        if (!$stripe->areAmountsLoaded()) {
            $this->flash->addMessageNow(
                'warning',
                _T("Predefined amounts cannot be loaded, that is not a critical error.", "stripe")
            );
        }

        // display page
        $this->view->render(
            $response,
            $this->getTemplate('stripe_form_amount'),
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
        $stripe = new Stripe($this->zdb);
        $adherent = new Adherent($this->zdb);

        $current_url = $this->preferences->getURL();

        // Check the amount
        $item_number = $stripe_request['item_number'];
        $amount = $stripe_request['amount'];
        $stripe_amounts = $stripe->getAmounts($this->login);

        if ($amount < $stripe_amounts[$item_number]['amount']) {
            $params = [
                'stripe'        => $stripe,
                'amounts'        => $stripe->getAmounts($this->login),
                'page_title'    => _T('Stripe payment', 'stripe'),
                'message'       => _T('The amount you\'ve entered is lower than the minimum amount for the selected option.\\nPlease choose another option or change the amount.', 'stripe'),
                'current_url'   => rtrim($current_url, '/')
            ];

            // display page
            $this->view->render(
                $response,
                $this->getTemplate('stripe_form_amount'),
                $params
            );
            return $response;
        } else {
            $metadata = [];

            if ($this->login->isLogged() && !$this->login->isSuperAdmin()) {
                $adherent->load($this->login->id);
                $metadata['adherent_id'] = $this->login->id;
                $metadata['billing_name'] = Adherent::getSName($this->zdb, $this->login->id);
                $metadata['billing_email'] = $adherent->getEmail();
                $metadata['billing_company'] = $adherent->company_name;
                $metadata['billing_address'] = $adherent->getAddress();
                $metadata['billing_zip'] = $adherent->getZipcode();
                $metadata['billing_town'] = $adherent->getTown();
                $metadata['billing_country'] = $adherent->getCountry();
            }

            if (!$this->login->isLogged()) {
                if ($stripe_request['honeypot'] !== '') {
                    return $response->withStatus(403);
                }

                $metadata['billing_name'] = $stripe_request['billing_firstname'] . ' ' . $stripe_request['billing_lastname'];
                $metadata['billing_email'] = $stripe_request['billing_email'];
                $metadata['billing_company'] = $stripe_request['billing_company'];
                $metadata['billing_address'] = $stripe_request['billing_address'];
                $metadata['billing_zip'] = $stripe_request['billing_zip'];
                $metadata['billing_town'] = $stripe_request['billing_town'];
                $metadata['billing_country'] = $stripe_request['billing_country'];
            }

            $metadata['contrib_id'] = $item_number;

            $client_secret = $stripe->createPaymentIntent($metadata, $amount);

            $params = [
                'stripe'        => $stripe,
                'amount'        => $amount * 100,
                'item_name'     => $stripe_amounts[$item_number]['name'],
                'client_secret' => $client_secret,
                'page_title'    => _T('Stripe payment', 'stripe'),
                'current_url'   => rtrim($current_url, '/'),
                'metadata'      => $metadata,
            ];

            // display page
            $this->view->render(
                $response,
                $this->getTemplate('stripe_form_checkout'),
                $params
            );
            return $response;
        }
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
        $post = $request->getParsedBody();
        $body = $request->getBody();
        $stripe = new Stripe($this->zdb);

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

        Analog::log("Stripe webhook request: " . var_export($post, true), Analog::DEBUG);

        // Process payload
        if (
            isset($post['type'])
            && ($post['type'] == 'payment_intent.succeeded' || $post['type'] == 'invoice.payment_succeeded')
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

            $ph = new StripeHistory($this->zdb, $this->login, $this->preferences);
            $ph->add($post);

            // are we working on a real contribution?
            $real_contrib = false;
            if (
                isset($post['data']['object']['metadata']['adherent_id'])
                && is_numeric($post['data']['object']['metadata']['adherent_id'])
                && $post['data']['object']['status'] == 'succeeded'
                && $post['data']['object']['amount_received'] == $post['data']['object']['amount']
            ) {
                $real_contrib = true;
            }

            if ($ph->isProcessed($post)) {
                Analog::log(
                    'A stripe payment notification has been received, but it is already processed!',
                    Analog::WARNING
                );
                $ph->setState(StripeHistory::STATE_ALREADYDONE);
            }

            // we'll now try to add the relevant cotisation
            if ($post['data']['object']['status'] == 'succeeded') {
                /**
                * We will use the following parameters:
                * - $post['data']['object']['amount']: the amount
                * - $post['data']['object']['metadata']['adherent_id']: member id
                * - $post['data']['object']['metadata']['contrib_id']: contribution type id
                *
                * If no member id is provided, we only send to post contribution
                * script, Galette does not handle anonymous contributions
                */
                $contrib_args = [
                    'type'          => $post['data']['object']['metadata']['contrib_id'],
                    'adh'           => $post['data']['object']['metadata']['adherent_id'],
                    'payment_type'  => PaymentType::CREDITCARD
                ];
                $check_contrib_args = [
                    ContributionsTypes::PK  => $post['data']['object']['metadata']['contrib_id'],
                    Adherent::PK            => $post['data']['object']['metadata']['adherent_id'],
                    'type_paiement_cotis'   => PaymentType::CREDITCARD,
                    'montant_cotis'         => $post['data']['object']['amount'] / 100, // Stripe handles cents
                ];
                if ($this->preferences->pref_membership_ext != '') { //@phpstan-ignore-line
                    $contrib_args['ext'] = $this->preferences->pref_membership_ext;
                }
                $contrib = new Contribution($this->zdb, $this->login, $contrib_args);

                // all goes well, we can proceed
                if ($real_contrib) {
                    // Check contribution to set $contrib->errors to [] and handle contribution overlap
                    $valid = $contrib->check($check_contrib_args, [], []);
                    if ($valid !== true) {
                        Analog::log(
                            'An error occurred while storing a new contribution from Stripe payment:' .
                            implode("\n   ", $valid),
                            Analog::ERROR
                        );
                        $ph->setState(StripeHistory::STATE_ERROR);
                        return $response->withStatus(500, 'Internal error');
                    }

                    $store = $contrib->store();
                    if ($store === true) {
                        // contribution has been stored :)
                        Analog::log(
                            'Stripe payment has been successfully registered as a contribution',
                            Analog::INFO
                        );
                        $ph->setState(StripeHistory::STATE_PROCESSED);
                    } else {
                        // something went wrong :'(
                        Analog::log(
                            'An error occured while storing a new contribution from Stripe payment',
                            Analog::ERROR
                        );
                        $ph->setState(StripeHistory::STATE_ERROR);
                        return $response->withStatus(500, 'Internal error');
                    }
                    return $response->withStatus(200);
                }
            } else {
                Analog::log(
                    'A stripe payment notification has been received, but is not completed!',
                    Analog::WARNING
                );
                $ph->setState(StripeHistory::STATE_INCOMPLETE);
                return $response->withStatus(500, 'Internal error');
            }
            return $response->withStatus(200);
        } else {
            Analog::log(
                'Stripe notify URL call without required arguments!',
                Analog::ERROR
            );
            return $response->withStatus(500, 'Missing required arguments');
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
    public function history(Request $request, Response $response, string $option = null, string|int $value = null): Response
    {
        $stripe_history = new StripeHistory($this->zdb, $this->login, $this->preferences);

        $filters = [];
        if (isset($this->session->filter_stripe_history)) {
            $filters = $this->session->filter_stripe_history;
        } else {
            $filters = new HistoryList();
        }

        /*$option = null;
        if (isset($args['option'])) {
            $option = $args['option'];
        }
        $value = null;
        if (isset($args['value'])) {
            $value = $args['value'];
        }*/

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
        $filters->setViewPagination($this->routeparser, $this->view);

        $params = [
            'page_title'        => _T("Stripe History"),
            'stripe_history'    => $stripe_history,
            'logs'              => $logs,
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
    public function filters(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();

        //reset history
        $filters = [];
        if (isset($post['reset'])) {
        } else {
            //number of rows to show
            if (isset($post['nbshow'])) {
                $filters['show'] = $post['nbshow'];
            }
        }

        $this->session->filter_stripe_history = $filters;

        return $response
            ->withStatus(301)
            ->withHeader('Location', $this->routeparser->urlFor('stripe_history'));
    }
}
