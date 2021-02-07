{extends file="page.tpl"}
{block name="content"}
        <form action="{path_for name="store_stripe_preferences"}" method="post" enctype="multipart/form-data">
{if !$stripe->isLoaded()}
            <div id="errorbox">
                <h1>{_T string="- ERROR -"}</h1>
            </div>
{/if}
        <div class="bigtable">
            <input type="hidden" name="valid" value="1"/>
            <fieldset class="cssform stripeprefs ">
                <legend class="ui-state-active ui-corner-top">{_T string="Stripe preferences" domain="stripe"}</legend>
{if $login->isAdmin()}
                <p>
                    <label for="stripe_pubkey" class="bline">{_T string="Stripe public key:" domain="stripe"}</label>
                    <span class="tip">{_T string="Enter here your Stripe public key." domain="stripe"}</span>
                    <input type="text" name="stripe_pubkey" id="stripe_pubkey" value="{$stripe->getPubKey()}" required/>
                </p>
                <p>
                    <label for="stripe_privkey" class="bline">{_T string="Stripe secret key:" domain="stripe"}</label>
                    <span class="tip">{_T string="Enter here your Stripe secret key." domain="stripe"}</span>
                    <input type="text" name="stripe_privkey" id="stripe_privkey" value="{$stripe->getPrivKey()}" required/>
                </p>
                <p>
                    <label class="bline">{_T string="Stripe Webhook URL:" domain="stripe"}</label>
                    <span>{base_url}/{$webhook_url}</span>
                </p>
                <p>
                    <label class="bline">{_T string="Stripe Webhook events:" domain="stripe"}</label>
                    <span>payment_intent.succeeded</span>
                </p>
                <p>
                    <label for="stripe_webhook_secret" class="bline">{_T string="Stripe webhook secret:" domain="stripe"}</label>
                    <span class="tip">{_T string="Enter here your Stripe webhook secret." domain="stripe"}</span>
                    <input type="text" name="stripe_webhook_secret" id="stripe_webhook_secret" value="{$stripe->getWebhookSecret()}" required/>
                </p>
                <p>
                    <label for="stripe_country" class="bline">{_T string="Country of your Stripe account:" domain="stripe"}</label>
                    <span class="tip">{_T string="Enter here the country of your Stripe account." domain="stripe"}</span>
                    <select name="stripe_country" id="stripe_country">
                        {foreach from=$countries key=country_code item=country_label name=stripe_countries}
                            <option value="{$country_code}" {if $country_code == $stripe->getCountry()}selected{/if}>{$country_label}</option>
                        {/foreach}
                    </select>
                </p>
                <p>
                    <label for="stripe_currency" class="bline">{_T string="Currency for payments:" domain="stripe"}</label>
                    <span class="tip">{_T string="Enter here the currency used to capture payments." domain="stripe"}</span>
                    <select name="stripe_currency" id="currency">
                        {foreach from=$currencies key=currency_code item=currency_label name=stripe_currencies}
                            <option value="{$currency_code}" {if $currency_code == $stripe->getCurrency()}selected{/if}>{$currency_label}</option>
                        {/foreach}
                    </select>
                </p>

                <p></p>
{/if}
{if $stripe->areAmountsLoaded() and $amounts|@count gt 0}
                <table>
                    <thead>
                        <tr>
                            <th class="listing">{_T string="Contribution type"}</th>
                            <th class="listing">{_T string="Amount"}</th>
                            <th class="listing">{_T string="Inactive"}</th>
                        </tr>
                    </thead>
                    <tbody>
    {foreach from=$amounts key=k item=amount}
                        <tr>
                            <td>
                                <input type="hidden" name="amount_id[]" id="amount_id_{$k}" value="{$k}"/>
                                <label for="amount_{$k}">{$amount['name']}</label>
                            </td>
                            <td>
                                <input type="text" name="amounts[]" id="amount_{$k}" value="{$amount['amount']|string_format:"%.2f"}"/>
                            </td>
                            <td>
                                <input type="checkbox" name="inactives[]" id="inactives_{$k}"{if $stripe->isInactive($k)} checked="checked"{/if} value="{$k}"/>
                            </td>
                        </tr>
    {/foreach}
                    </tbody>
                </table>
            </fieldset>
{else}
            <p>{_T string="Error: no predefined amounts found." domain="stripe"}</p>
{/if}

        </div>
        <div class="button-container">
            <input type="submit" value="{_T string="Save"}"/>
        </div>
        <p>{_T string="NB : The mandatory fields are in"} <span class="required">{_T string="red"}</span></p>
        </form>
{/block}
{block name="javascripts"}

{/block}