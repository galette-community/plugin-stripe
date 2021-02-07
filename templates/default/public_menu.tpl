        {if $public_page}
            <a class="button{if $cur_route eq "stripe_form_amount" or $cur_route eq "stripe_form_checkout"} selected{/if}" href="{path_for name="stripe_form_amount"}">
                <i class="fab fa-cc-stripe"></i>
                {_T string="Payment form" domain="stripe"}
            </a>
        {/if}
