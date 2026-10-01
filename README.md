PrestaShop Payop Payment Gateway
=====================

## Brief Description

Add the ability to accept payments in PrestaShop via Payop.com.

## Requirements

- PHP 7.4+
- PrestaShop 8.0+


## Installation
 1. Download latest [release](https://github.com/Payop/prestashop-plugin/releases)
 2. Log in to your PrestaShop dashboard, navigate to the Modules menu and click on Modules Manager submenu
 3. Click "Upload a module" button and choose release archive
 4. Click "Configure" after successful installation. 
 5. Configure and save your settings accordingly.

## Configuration and additional checkout buttons

The module supports PrestaShop 8.0+ and uses the native `PaymentOption` checkout integration. Use the PHP version supported by your PrestaShop installation. Version 2.4.0 was tested locally on PrestaShop 9.2.0 / PHP 8.3 and PrestaShop 8.2.8 / PHP 8.1; other target versions require a compatibility run before release.

1. Enable Payop payments and configure the existing Display Name, Description, Public Key and Secret Key. These settings continue to control the original Payop Hosted Page option.
2. The optional **JWT Token** is used only to load the project payment method catalogue. Invoice creation and IPN payment verification do not use JWT. Existing saved JWT values are retained for catalogue loading.
3. To load the project payment method catalogue, save **JWT Token** and the Public Key, then click **Refresh saved project payment methods**. JWT is not required to create invoices or verify IPN, and an absent/expired JWT cannot block payment confirmation. Refresh uses saved credentials and does not save unsaved edits.
4. Under **Additional checkout payment buttons**, click **Add payment button**. Set Enabled, a customer title and description for each shop language, and select an integration type:
   - **Hosted Page**: shows all available methods and omits `paymentMethod` from the invoice request.
   - **Hosted Page with Payment Method ID**: select a project method from the dropdown. Selection is required for this type and the saved ID is sent as `paymentMethod`.
5. Save. The original Payop button and each enabled additional button appear separately on checkout. There is no configured limit on the number of additional buttons. All buttons share the module keys and configuration. Empty translations fall back to the default shop language; a title in the default language is required.
6. Disable a row to hide it without deleting its configuration, or remove it and save. Disabled/deleted options cannot be submitted for a new invoice.

API errors show a warning and retain existing buttons and the last successfully loaded catalogue for the same project. Previously saved method IDs remain editable even if the catalogue is temporarily unavailable. New method IDs must be selected from the loaded project catalogue. The catalogue is cached for five minutes and can be refreshed explicitly.

For new setups, use the **Signed Callback URL** shown in the module settings. Existing signed and legacy unsigned callback URLs remain supported after upgrade, without reconfiguration. If a signature is supplied, it must be valid. All callbacks require server-side invoice verification. Changing the JWT does not change the callback URL.

## Returning from Payop and IPN handling

PrestaShop creates the order before redirecting to Payop. When returning to checkout or the payment failure page, the customer can select another Payop option on a payment retry page for that same order. Each retry creates a fresh invoice, including when reselecting the same option. The order amount and currency remain those of the existing order; retry does not create a new order or use an edited cart total.

Every issued invoice retains its order/cart, button and method context in `payop_invoice_history`. The current invoice pointer remains in `payop_order_meta`. Removing a button does not remove its invoice history. Invoice creation and IPN processing use the same cart lock to serialize concurrent payment attempts.

Unknown invoices are rejected. IPN requires a stored order/cart/invoice association and a successful HTTPS lookup of the invoice at `/v1/invoices/{invoiceID}` without an Authorization header. The response must match the invoice ID, order ID, amount, currency, status and `transactionIdentifier`. Invoice status is mapped separately from transaction state. Callback data alone can never confirm payment; API errors fail closed for retry. Provided callback signatures are validated, while legacy unsigned callback URLs remain supported through the same full invoice verification. A verified successful payment of an earlier invoice can complete the existing order. Pending, failed or expired events for an earlier invoice do not change the current order attempt. An order that has already been paid cannot be downgraded by a later event, including after it moves to a shipping status. Repeated successful IPN does not create another order/payment or repeat the state transition. A browser return alone never changes payment state.

**Do not pay multiple invoices for the same order.** If the customer pays two attempts, the module preserves their verified history and keeps the order paid; handling a duplicate charge/refund belongs to merchant reconciliation.

## Upgrade from 2.3.x

Replace the module files and run the normal module **Upgrade** action in Module Manager (or `php bin/console prestashop:module upgrade payop`). Do not uninstall/reset the module. The upgrade registers the retry hook and migrates the currently stored invoice into history. Existing keys, API JWT, names, descriptions, enabled setting and callback signature remain unchanged; additional buttons start empty. Invoices overwritten by versions before 2.4.0 cannot be recovered retrospectively.

## Development verification

Run against a disposable installed PrestaShop shop:

```sh
PAYOP_TEST_SHOP=1 php modules/payop/tests/integration.php
find modules/payop -name '*.php' -not -path '*/vendor/*' -exec php -l {} \;
```

The integration suite replaces external API calls with deterministic responses, exercises the real module/controllers and database, and rolls back its synthetic orders and configuration. It covers normal and method-specific invoice payloads, switching options on the same order, settings validation, unavailable JWT/catalogue, unknown invoices, signature/binding rejection, late success/failure/timeout and duplicate IPN. It does not perform live payments.

Before publishing, verify with a real Payop project: load its actual method catalogue, create each invoice, follow the Hosted Page redirects and deliver signed IPN to a public HTTPS test shop. Test checkout with both logged-in and guest customers, browser Back, and each supported PrestaShop/PHP combination. Publication/tagging is a separate step.

## Support

* [Open an issue](https://github.com/Payop/prestashop-plugin/issues) if you are having issues with this plugin.
* [Payop Documentation](https://payop.com/en/documentation/common/)
* [Contact Payop support](https://payop.com/en/contact-us/)
  
**TIP**: When contacting support, it will be helpful if you also provide some additional information, such as:

* PrestaShop Version
* Other plugins you have installed
  * Some plugins do not play nice
* Configuration settings for the plugin (Most merchants take screenshots)
* Log files
  * PrestaShop logs
  * Web server error logs
* Screenshots of error message if applicable.

## Contribute

Would you like to help with this project?  Great!  You don't have to be a developer, either.
If you've found a bug or have an idea for an improvement, please open an
[issue](https://github.com/Payop/prestashop-plugin/issues) and tell us about it.

If you *are* a developer wanting to contribute an enhancement, bugfix or other patch to this project,
please fork this repository and submit a pull request detailing your changes.  We review all PRs!

This open source project is released under the [MIT license](http://opensource.org/licenses/MIT)
which means if you would like to use this project's code in your own project you are free to do so.


## License

Please refer to the 
[LICENSE](https://github.com/Payop/prestashop-plugin/blob/master/LICENSE)
file that come with this project.


## Changelog

= v1.0.1-beta =
* 2020-02-28
* Fix wrong order id on checkout

= v1.0.2-beta =
* 2024-06-07
* changed the domain for the API
* Changed plugin name

= v2.0.0 =
* 2025-02-27
* Improvements and support for PrestaShop 8.0+

= 2.1.0 =
* 2025-07-22
* The order is created only after payment has been made.
* The cart is cleared only after the payment has been made.

= 2.2.0 =
* 2025-11-04
* The order is created immediately after the client switches to the payment system; this is the most correct solution that does not cause side effects.

= 2.2.1 =
* 2025-11-14
* Improved callback handling for PayOp.
* Order status and payment records are now fully synchronized.
* Payment information (amount, method, transaction ID) is automatically added to the “Payments” section of the order.
* Duplicate payment records are prevented when the payment system sends repeated callbacks.

= 2.3.0 =
* 2026-04-01
* Added a dedicated success page for customer return flow to avoid broken order confirmation redirects and 404 pages.
* Fixed success page rendering so it is displayed inside the active PrestaShop theme layout.
* Added API JWT Token configuration for mandatory server-side transaction verification through Payop API v2.
* Hardened callback processing with signed callback URL validation, order-module validation, stored invoice binding, and mandatory txid checks.
* Removed insecure callback fallback logic: order statuses are updated only after successful Payop API verification.
* Updated transaction verification to use Payop v2 fields such as productAmount, productCurrency, orderId, and identifier.
* Improved invoice identifier handling by prioritizing the identifier response header as recommended by Payop documentation.

= 2.3.1 =
* 2026-04-29
* Improved payment record handling in callbacks: existing order payments are updated with the transaction ID when possible, and duplicate payment records are avoided.

= 2.4.0 =
* Added unlimited additional checkout payment options with per-language customer titles and descriptions.
* Added Hosted Page and Hosted Page with Payment Method ID integration types with conditional method selection.
* Added optional JWT for project payment method discovery only, cached catalogue and recoverable API errors.
* Preserved the original Hosted Page and existing credentials/settings during upgrade.
* Added immutable invoice history and same-order payment retries when changing a payment option.
* Serialized invoice creation and IPN handling, rejected unknown invoices and verified invoice/order/amount/currency/status/transaction through the invoice API without JWT.
* Preserved existing callback URLs and ensured missing/expired catalogue JWT cannot block payment confirmation.
* Prevented superseded failures/timeouts and repeated IPN from downgrading or duplicating an already paid order.
