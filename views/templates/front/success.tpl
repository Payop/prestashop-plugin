{extends file='page.tpl'}

{block name='page_content'}
	<section class="card">
		<div class="card-block">
			<h1>{l s='Payment completed successfully.' mod='payop'}</h1>
			<p>{l s='Your order has been created and recorded by Payop.' mod='payop'}</p>
			<p>{l s='Order reference:' mod='payop'} <strong>{$order_reference|escape:'htmlall':'UTF-8'}</strong></p>

			{if $is_customer_logged}
				<p><a class="btn btn-primary" href="{$order_history_url|escape:'htmlall':'UTF-8'}">{l s='View my orders' mod='payop'}</a></p>
			{else}
				<p><a class="btn btn-primary" href="{$home_url|escape:'htmlall':'UTF-8'}">{l s='Back to home page' mod='payop'}</a></p>
			{/if}
		</div>
	</section>
{/block}
