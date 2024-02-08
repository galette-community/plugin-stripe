<?php

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
 * Stripe controller
 */

class StripeController extends AbstractPluginController
{
    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Stripe")]
    protected array $module_info;

    public function preferences($request, $response)
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
        $current_url = $this->preferences->getURL();

        $params = [
            'page_title'    => _T('Stripe Settings', 'stripe'),
            'stripe'        => $stripe,
            'webhook_url'   => 'plugins/' . $current_url . $this->module_info['module']['route'] . '/webhook',
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



    public function storePreferences($request, $response)
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
            if (isset($post['amount_id']) && isset($post['amounts'])) {
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



    public function form($request, $response)
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

        // display page
        $this->view->render(
            $response,
            $this->getTemplate('stripe_form_amount'),
            $params
        );
        return $response;
    }



    public function formCheckout($request, $response)
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
                $metadata['adherent_name'] = Adherent::getSName($this->zdb, $this->login->id);
                $metadata['adherent_company'] = $adherent->_company_name;
                $metadata['adherent_address_1'] = $adherent->getAddress();
                //$metadata['adherent_address_2'] = $adherent->getAddressContinuation();
                $metadata['adherent_zip'] = $adherent->getZipcode();
                $metadata['adherent_town'] = $adherent->getTown();
                $metadata['adherent_country'] = $adherent->getCountry();
                $metadata['adherent_email'] = $adherent->getEmail();
                $metadata['adherent_id'] = $this->login->id;
                $metadata['contrib_id'] = $item_number;
            }

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



    public function webhook($request, $response)
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

            if (abs(time() - $sig_timestamp) > 5) {
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

        if (isset($post['type']) 
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
            if (isset($post['data']['object']['metadata']['adherent_id'])
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
                echo 'A stripe payment notification has been received, but it is already processed!';
                return $response->withStatus(200);
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
                if ($this->preferences->pref_membership_ext != '') {
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
                        echo 'An error occurred while storing a new contribution from Stripe payment';
                        return $response->withStatus(500);
                    }

                    $store = $contrib->store();
                    if ($store === true) {
                        // contribution has been stored :)
                        Analog::log(
                            'Stripe payment has been successfully registered as a contribution',
                            Analog::INFO
                        );
                        echo 'Stripe payment has been successfully registered as a contribution';
                        $ph->setState(StripeHistory::STATE_PROCESSED);
                        return $response->withStatus(200);
                    } else {
                        // something went wrong :'(
                        Analog::log(
                            'An error occured while storing a new contribution from Stripe payment',
                            Analog::ERROR
                        );
                        echo 'An error occured while storing a new contribution from Stripe payment';
                        $ph->setState(StripeHistory::STATE_ERROR);
                        return $response->withStatus(500);
                    }
                }
            } else {
                Analog::log(
                    'A stripe payment notification has been received, but the Adherent ID is not found!',
                    Analog::WARNING
                );
                echo 'A stripe payment notification has been received, but the Adherent ID is not found!';
                $ph->setState(StripeHistory::STATE_ERROR);
                return $response->withStatus(403);
            }
        } else {
            Analog::log(
                'Stripe notify URL call without required arguments!',
                Analog::ERROR
            );
            echo 'Stripe notify URL call without required arguments!';
            return $response->withStatus(403);
        }
    }



    //'/logs[/{option:|order|reset|page}/{value}]',
    public function history($request, $response, string $option = null, string|int $value = null)
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


    //history filtering
    public function filters($request, $response)
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
            ->withHeader('Location', $this->router->pathFor('stripe_history'));
    }
}
