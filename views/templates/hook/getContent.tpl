{if isset($confirmation)}
	<div class="alert alert-success">{l s='Settings updated' mod='payop'}</div>
{/if}
{if !empty($payop_error)}<div class="alert alert-danger">{$payop_error|escape:'htmlall':'UTF-8'}</div>{/if}
{if !empty($payop_notice)}<div class="alert alert-info">{$payop_notice|escape:'htmlall':'UTF-8'}</div>{/if}
<fieldset>
	<h2>{l s='Payop configuration' mod='payop'}</h2>
	<div class="panel">
		<form id="payop-settings-form" id="data" action="" method="post">
            <input type="hidden" name="payop_settings_token" value="{$payop_settings_token|escape:'htmlall':'UTF-8'}" />
            <input type="hidden" id="payop-buttons-json" name="payop_buttons_json" value="{$payop_buttons_json|escape:'htmlall':'UTF-8'}" />
            <textarea id="payop-editor-data" hidden>{$payop_editor_json|escape:'htmlall':'UTF-8'}</textarea>

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
						<input type="text" id="displayName" name="displayName" value="{if isset($displayName)}{$displayName|escape:'htmlall':'UTF-8'}{/if}" placeholder="{l s='Payment method displayed name' mod='payop'}"/>
					</div>
				</div>

				<div class="form-group clearfix">
					<label class="col-lg-3">{l s='Description' mod='payop'}</label>
					<div class="col-lg-9">
						<input type="text" id="description" name="description" value="{if isset($description)}{$description|escape:'htmlall':'UTF-8'}{/if}" placeholder="{l s='Payment method description' mod='payop'}"/>
					</div>
				</div>

				<div class="form-group clearfix">
					<label class="col-lg-3">{l s='Public Key' mod='payop'}</label>
					<div class="col-lg-9">
						<input type="text" id="publicKey" name="publicKey" value="{if isset($publicKey)}{$publicKey|escape:'htmlall':'UTF-8'}{/if}" placeholder="{l s='Issued in project settings' mod='payop'}"/>
					</div>
				</div>

				<div class="form-group clearfix">
					<label class="col-lg-3">{l s='Secret Key' mod='payop'}</label>
					<div class="col-lg-9">
						<input type="password" id="secretKey" name="secretKey" value="{if isset($secretKey)}{$secretKey|escape:'htmlall':'UTF-8'}{/if}" placeholder="{l s='Issued in project settings' mod='payop'}" autocomplete="new-password"/>
					</div>
				</div>

				<div class="form-group clearfix">
					<label class="col-lg-3">{l s='API JWT Token' mod='payop'}</label>
					<div class="col-lg-9">
						<input type="password" id="apiToken" name="apiToken" value="{if isset($apiToken)}{$apiToken|escape:'htmlall':'UTF-8'}{/if}" placeholder="{l s='Used for server-side transaction verification' mod='payop'}" autocomplete="new-password"/>
						<p class="help-block">{l s='Create a Bearer token in the Payop dashboard and paste it here for callback verification.' mod='payop'}</p>
					</div>
				</div>

				<div class="form-group clearfix">
					<label class="col-lg-3">{l s='Signed Callback URL' mod='payop'}</label>
					<div class="col-lg-9">
						<input type="text" readonly="readonly" value="{if isset($callbackUrl)}{$callbackUrl|escape:'htmlall':'UTF-8'}{/if}"/>
						<p class="help-block">{l s='Set exactly this URL as IPN/Callback URL in the Payop project settings.' mod='payop'}</p>
					</div>
				</div>


                <div class="form-group clearfix">
                    <label class="col-lg-3">{l s='Payment Methods JWT Token' mod='payop'}</label>
                    <div class="col-lg-9">
                        <input type="password" name="methodsToken" value="{$methodsToken|escape:'htmlall':'UTF-8'}" autocomplete="new-password" />
                        <p class="help-block">{l s='Used only to load the project payment methods. Optional for ordinary Hosted Page. The API JWT Token above keeps its existing transaction verification role.' mod='payop'}</p>
                        <button class="btn btn-default" name="payop_refresh_methods" value="1" type="submit" formnovalidate>{l s='Refresh saved project payment methods' mod='payop'}</button>
                        <p class="help-block">{l s='Save credentials first, then refresh. Refresh does not save unsaved form changes.' mod='payop'}</p>
                        {if !empty($payop_methods_error)}<div class="alert alert-warning">{$payop_methods_error|escape:'htmlall':'UTF-8'}</div>{/if}
                    </div>
                </div>
                <hr />
                <h3>{l s='Additional checkout payment buttons' mod='payop'}</h3>
                <p>{l s='The existing Payop Hosted Page remains available. All additional buttons share the project keys and module settings.' mod='payop'}</p>
                <div id="payop-buttons-editor"></div>
                <button class="btn btn-default" id="payop-add-button" type="button">{l s='Add payment button' mod='payop'}</button>
                <noscript><p>{l s='Enable JavaScript to edit additional buttons. Existing buttons will be preserved.' mod='payop'}</p></noscript>
			<div class="panel-footer">
				<input class="btn btn-default pull-right" type="submit" name="pc_form" value="{l s='Save' mod='payop'}" />
			</div>
		</form>
	</div>
</fieldset>
