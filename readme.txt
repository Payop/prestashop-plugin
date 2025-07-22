=== Payop Official ===
Tags: credit cards, payment methods, payop, payment gateway
Version: 2.1.0
Stable tag: 2.1.0
Tested up to: 8.0+
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add the ability to accept payments in PrestaShop via Payop.com.

== Description ==

Payop: Online payment processing service ➦ Accept payments online by 150+ methods from 170+ countries.
Payments gateway for Growing Your Business in New Locations and fast online payments.

What this module does for you:

* Free and quick setup
* Access 150+ local payment solutions with 1 easy integration.
* Highest security standards and anti-fraud technology

== Installation ==

 1. Download latest [release](https://github.com/Payop/prestashop-plugin/releases)
 2. Log in to your PrestaShop dashboard, navigate to the Modules menu and click on Modules Manager submenu
 3. Click "Upload a module" button and choose release archive
 4. Click "Configure" after successful installation. 
 5. Configure and save your settings accordingly.

You can issue  **Public key** and **Secret key** after registering as merchant on Payop.com.

Use the following parameters to configure your Payop project:
* **Callback/IPN URL**: https://{replace-with-your-domain}/en/?fc=module&module=payop&controller=callback

== Support ==

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


== Changelog ==

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
