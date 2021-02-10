        <h1 class="nojs">{if $login->isAdmin() or $login->isStaff()}{_T string="Stripe" domain="stripe"}{else}{_T string="Payment" domain="stripe"}{/if}</h1>
        <ul>
            <li{if $cur_route eq "stripe_form_amount" or $cur_route eq "stripe_form_checkout"} class="selected"{/if}><a href="{path_for name="stripe_form_amount"}">{_T string="Payment form" domain="stripe"}</a></li>
{if $login->isAdmin() or $login->isStaff()}
            <li{if $cur_route eq "stripe_history"} class="selected"{/if}><a href="{path_for name="stripe_history"}">{_T string="Stripe History" domain="stripe"}</a></li>
            <li{if $cur_route eq "stripe_preferences"} class="selected"{/if}><a href="{path_for name="stripe_preferences"}">{_T string="Preferences"}</a></li>
{/if}
        </ul>
