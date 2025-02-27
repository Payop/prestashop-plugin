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

You can issue  **Public key** and **Secret key** after registering as merchant on Payop.com.

Use the following parameters to configure your Payop project:
* **Callback/IPN URL**: https://{replace-with-your-domain}/en/?fc=module&module=payop&controller=callback

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
