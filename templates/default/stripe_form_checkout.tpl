{extends file="page.tpl"}
{block name="content"}
{if !$stripe->isLoaded()}
<div id="errorbox">
    <h1>{_T string="- ERROR -"}</h1>
    <p>{_T string="<strong>Payment coult not work</strong>: An error occured (that has been logged) while loading Stripe preferences from database.<br/>Please report the issue to the staff." domain="stripe"}</p>
    <p>{_T string="Our apologies for the annoyance :("}</p>
</div>
{elseif $stripe->getPubKey() eq null or $stripe->getPrivKey() eq null}
    <div id="errorbox">
        <h1>{_T string="- ERROR -"}</h1>
        <p>{_T string="Stripe keys has not been defined. Please ask an administrator to add it from plugin preferences." domain="stripe"}</p>
    </div>
{else}
    {if !$stripe->areAmountsLoaded()}
<div id="warningbox">
    <h1>{_T string="- WARNING -"}</h1>
    <p>{_T string="Predefined amounts cannot be loaded, that is not a critical error." domain="stripe"}</p>
</div>
    {/if}
    <section>
        <form id="payment-form">
                <fieldset>
                    <legend class="ui-state-active ui-corner-top">{_T string="Stripe payment" domain="stripe"}</legend>
                    <div id="messages"></div>
                    <div class="stripe-flex">
                        <div class="stripe-column">
                            <div class="stripe-row">
                                <strong>{$metadata['adherent_name']}</strong><br>
                                {if $metadata['adherent_company']}{$metadata['adherent_company']}<br>{/if}
                                {$metadata['adherent_address_1']}<br>
                                {if $metadata['adherent_country']}{$metadata['adherent_address_2']}<br>{/if}
                                {$metadata['adherent_zip']} {$metadata['adherent_town']}<br>
                                {if $metadata['adherent_country']}{$metadata['adherent_country']}{/if}
                            </div>
                            <div class="stripe-row">
                                <strong>{$item_name}</strong><br>
                                <strong>{$amount/100.} {$stripe->getCurrency()}</strong><br>
                            </div>
                        </div>
                        <div class="stripe-column">
                            <div class="stripe-row">
                                <label for="stripe-card-number" data-tid="elements.form.card_number_label">{_T string="Card number" domain="stripe"}</label>
                                <div id="stripe-card-number" class="input empty"></div>
                            </div>
                            <div class="stripe-row">
                                <label for="stripe-card-expiry" data-tid="elements.form.card_expiry_label">{_T string="Expiration" domain="stripe"}</label>
                                <div id="stripe-card-expiry" class="input empty"></div>
                            </div>
                            <div class="stripe-row">
                                <label for="stripe-card-cvc" data-tid="elements.form.card_cvc_label">{_T string="CVC" domain="stripe"}</label>
                                <div id="stripe-card-cvc" class="input empty"></div>
                            </div>
                            <div class="stripe-row">
                                <button type="submit" data-tid="elements.form.pay_button">{_T string="Pay" domain="stripe"}</button>
                                <div class="stripe-loader" style="display:none;">Loading...</div>
                            </div>
                        </div>
                    </div>
                </fieldset>
        </form>
    </section>
{/if}
{/block}

{block name="javascripts"}
{if $stripe->isLoaded() and $stripe->getPubKey() neq null and $stripe->getPrivKey() neq null and $stripe->areAmountsLoaded()}
<script src="https://js.stripe.com/v3/"></script>
<script type="text/javascript">
    $(function() {
        var stripe = Stripe('{$stripe->getPubKey()}', {});

        var elementStyles = {
            base: {
                color: '#32325D',
                fontWeight: 500,
                fontFamily: 'Source Code Pro, Consolas, Menlo, monospace',
                fontSize: '16px',
                fontSmoothing: 'antialiased',

                '::placeholder': {
                    color: '#CFD7DF',
                },
                ':-webkit-autofill': {
                    color: '#e39f48',
                },
            },
            invalid: {
                color: '#E25950',

                '::placeholder': {
                    color: '#FFCCA5',
                },
            },
        };

        var elementClasses = {
            focus: 'focused',
            empty: 'empty',
            invalid: 'invalid',
        };

        // Create button from Stripe Elements
        var elements = stripe.elements();

        var cardNumber = elements.create('cardNumber', {
            style: elementStyles,
            classes: elementClasses,
        });
        cardNumber.mount('#stripe-card-number');

        var cardExpiry = elements.create('cardExpiry', {
            style: elementStyles,
            classes: elementClasses,
        });
        cardExpiry.mount('#stripe-card-expiry');

        var cardCvc = elements.create('cardCvc', {
            style: elementStyles,
            classes: elementClasses,
        });
        cardCvc.mount('#stripe-card-cvc');

        var form = document.getElementById('payment-form');

        // Create payment request
        var paymentRequest = stripe.paymentRequest({
            country: '{$stripe->getCountry()}',
            currency: '{$stripe->getCurrency()}',
            total: {
                label: '{$item_name}',
                amount: {$amount},
            },
            requestPayerName: true,
            requestPayerEmail: true,
        });

        form.addEventListener('submit', function(ev) {
            ev.preventDefault();
            jQuery(this).find('input').prop('disabled', true);
            jQuery(this).find('button[type="submit"]').hide();
            jQuery(this).find('.stripe-loader').show();
            stripe.confirmCardPayment('{$client_secret}', {
                payment_method: {
                    card: cardNumber,
                    billing_details: {
                        name: '{$metadata["adherent_name"]}',
                        email: '{$metadata["adherent_email"]}'
                    }
                }
            }).then(function(result) {
                if (result.error !== undefined && result.error !== null && result.error !== '') {
                    jQuery('#messages').html('<div id="errorbox"></div>');
                    jQuery('#messages #errorbox').text(result.error.message);
                    jQuery(form).find('input').prop('disabled', false);
                    jQuery(form).find('button[type="submit"]').show();
                    jQuery(form).find('.stripe-loader').hide();
                } else {
                    // The payment has been processed!
                    if (result.paymentIntent.status === 'succeeded') {
                        jQuery('#messages').html('<div id="successbox"></div>');
                        jQuery('#messages #successbox').text('{_T string="Payment successful" domain="stripe"}');
                        jQuery(form).find('input').hide();
                        jQuery(form).find('.stripe-loader').hide();
                    }
                }
            });
        });

    });
</script>
{/if}
{/block}
