{extends file='page.tpl'}
{block name='page_content'}
	<h2>{l s='Callback here' mod='payop'}</h2>
	{if isset($log)}
		<h2>{$log}</h2>
	{else}
		<p>{l s='No log data available' mod='payop'}</p>
	{/if}
{/block}
