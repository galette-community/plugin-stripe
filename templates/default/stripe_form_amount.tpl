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
<form action="" method="post" id="stripe">
    <!-- Stripe variables -->

    <fieldset id="stripe_form_amount">
        <legend class="ui-state-active ui-corner-top">
    {if $amounts|@count eq 0}
            {_T string="Enter payment reason" domain="stripe"}
    {elseif $amounts|@count eq 1}
            {_T string="Payment reason" domain="stripe"}
    {elseif $amounts|@count gt 1}
            {_T string="Select an payment reason" domain="stripe"}
    {/if}
        </legend>

    {if $stripe->areAmountsLoaded()}
        <div id="amounts">
        {if $amounts|@count gt 0}
            <input type="hidden" name="item_name" id="item_name" value="{if $login->isLogged()}{_T string="annual fee"}{else}{_T string="donation in money"}{/if}"/>
            {foreach from=$amounts key=k item=amount name=amounts}
            {if $smarty.foreach.amounts.index != 0}<br/>{/if}
            <input type="radio" name="item_number" id="in{$k}" value="{$k}"{if $smarty.foreach.amounts.index == 0} checked="checked"{/if}/>
            <label for="in{$k}"><span id="in{$k}_name">{$amount['name']}</span>
                {if $amount['amount'] gt 0}
                (<span id="in{$k}_amount">{$amount['amount']|string_format:"%.2f"}</span> €){* TODO: parametize currency *}
                {/if}
            </label>
            {/foreach}
        {else}
            <label for="item_name">{_T string="Payment reason:" domain="stripe"}</label>
            <input type="text" name="item_name" id="item_name" value="{if $login->isLogged()}{_T string="annual fee"}{else}{_T string="donation in money"}{/if}"/>
        {/if}
        </div>
    {else}
        <p>{_T string="No predefined amounts have been configured yet." domain="stripe"}</p>
    {/if}

        <p>
    {if $stripe->areAmountsLoaded() and $amounts|@count gt 0}
            <noscript>
                <br/><span class="required">{_T string="WARNING: If you enter an amount below, make sure that it is not lower than the amount of the option you've selected." domain="stripe"}</span>
                {if $message neq null}
                    <span class="error">{$message}</span>
                {/if}
            </noscript>
    {/if}
        </p>
        <p>
            <label for="amount">{_T string="Amount"}</label>
            <input type="text" name="amount" id="amount" value="{if $amounts|@count > 0}{$amounts[1]['amount']}{else}20{/if}"/>
        </p>
    </fieldset>

    <div class="button-container">
        <input type="submit" name="submit" value="{_T string="Validate"}"/>
    </div>
</form>
        </section>
{/if}
{/block}

{block name="javascripts"}
{if $stripe->isLoaded() and $stripe->getPubKey() neq null and $stripe->getPrivKey() neq null and $stripe->areAmountsLoaded()}
<script type="text/javascript">
    $(function() {
        $('input[name="item_number"]').change(function(){
            var _amount = parseFloat($('#' + this.id + '_amount').text());
            var _name = $('#' + this.id + '_name').text();
            $('#item_name').val(_name);
            if ( _amount != '' && !isNaN(_amount) ) {
                $('#amount').val(_amount);
            }
        });
    {if $amounts|@count gt 0}
        $('#stripe').submit(function(){
            var _checked = $('input:checked');
            if (_checked.length == 0 ) {
                alert("{_T string="You have to select an option"}");
                return false;
            } else {
                var _current_amount = parseFloat($('#amount').val());
                var _amount = parseFloat($('#' + _checked[0].id + '_amount').text());
                if ( isNaN(_current_amount) ) {
                    alert("{_T string="Please enter an amount." domain="stripe" escape="js"}");
                    return false;
                } else if ( !isNaN(_amount) && _current_amount < _amount ) {
                    alert("{_T string="The amount you've entered is lower than the minimum amount for the selected option.\\nPlease choose another option or change the amount." domain="stripe" escape="js"}");
                    return false;
                }
            }
            return true;
        });
    {/if}
    });
</script>
{/if}
{/block}
