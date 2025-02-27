{if isset($confirmation)}
	<div class="alert alert-success">{l s='Settings updated' mod='payop'}</div>
{/if}
<fieldset>
	<h2>{l s='Payop configuration' mod='payop'}</h2>
	<div class="panel">
		<form id="data" action="" method="post">
			<div class="form-group clearfix">
				<label class="col-lg-3">{l s='Enable Payop payments' mod='payop'}</label>
				<div class="col-lg-9">
					<img src="../img/admin/enabled.gif" alt="" />
					<input type="radio" id="enablePayments_1" name="enablePayments" value="1" {if isset($enablePayments) && $enablePayments eq '1'}checked{/if}/>
					<label class="t" for="enablePayments_1">{l s='Yes' mod='payop'}</label>
					<img src="../img/admin/disabled.gif" alt="" />
					<input type="radio" id="enablePayments_0" name="enablePayments" value="0" {if !isset($enablePayments) || $enablePayments eq '0'}checked{/if}/>
					<label class="t" for="enablePayments_0">{l s='No' mod='payop'}</label>
				</div>
			</div>

			<div class="form-group clearfix">
				<label class="col-lg-3">{l s='Display Name' mod='payop'}</label>
				<div class="col-lg-9">
					<input type="text" id="displayName" name="displayName" value="{if isset($displayName)}{$displayName}{/if}" placeholder="{l s='Payment method displayed name' mod='payop'}"/>
				</div>
			</div>

			<div class="form-group clearfix">
				<label class="col-lg-3">{l s='Description' mod='payop'}</label>
				<div class="col-lg-9">
					<input type="text" id="description" name="description" value="{if isset($description)}{$description}{/if}" placeholder="{l s='Payment method description' mod='payop'}"/>
				</div>
			</div>

			<div class="form-group clearfix">
				<label class="col-lg-3">{l s='Public Key' mod='payop'}</label>
				<div class="col-lg-9">
					<input type="text" id="publicKey" name="publicKey" value="{if isset($publicKey)}{$publicKey}{/if}" placeholder="{l s='Issued in project settings' mod='payop'}"/>
				</div>
			</div>

			<div class="form-group clearfix">
				<label class="col-lg-3">{l s='Secret Key' mod='payop'}</label>
				<div class="col-lg-9">
					<input type="text" id="secretKey" name="secretKey" value="{if isset($secretKey)}{$secretKey}{/if}" placeholder="{l s='Issued in project settings' mod='payop'}"/>
				</div>
			</div>

			<div class="panel-footer">
				<input class="btn btn-default pull-right" type="submit" name="pc_form" value="{l s='Save' mod='payop'}" />
			</div>
		</form>
	</div>
</fieldset>
