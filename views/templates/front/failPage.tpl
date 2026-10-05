{extends file='page.tpl'}
{block name='page_content'}
	<h2>{l s='Payment was not completed.' mod='payop'}</h2>
	<p>{l s='If the payment was authorized, the order status will be updated automatically after Payop verification.' mod='payop'}</p>
    {if !empty($payop_retry_options)}
        <h3>{l s='Choose a payment option for this order' mod='payop'}</h3>
        <p>{l s='A new payment attempt will be created for the same order. Do not pay an earlier invoice again.' mod='payop'}</p>
        {foreach $payop_retry_options as $option}
            <form method="post" action="{$option.action|escape:'htmlall':'UTF-8'}">
                <p>{$option.description|escape:'htmlall':'UTF-8'}</p>
                <button type="submit" class="btn btn-primary">{$option.name|escape:'htmlall':'UTF-8'}</button>
            </form>
        {/foreach}
    {/if}
{/block}
