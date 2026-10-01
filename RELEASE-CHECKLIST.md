# Payop 2.4.0 release verification

## Completed locally

- PrestaShop 9.2.0 / PHP 8.3 module upgrade, preserving existing settings.
- PrestaShop 8.2.8 / PHP 8.1 installation and the same integration suite.
- Native checkout PaymentOption generation for general Hosted Page and multiple specific methods.
- Back-office add, edit, disable, remove, save, and conditional required method selector.
- Integration checks for invoice request field omission/inclusion and same-order switching.
- Stored invoice/cart/order context, unknown invoice and invalid signature rejection.
- Verified old-invoice success, repeated success, late failure and timeout, and paid-order protection.
- JWT/API failure retention for the catalogue; invoice creation and verified IPN work with missing/invalid JWT.
- Legacy unsigned callback URL compatibility, invalid supplied signature rejection, and fail-closed invoice API verification.

- Real project JWT catalogue retrieval: 31 available payment methods returned.

## Required before publication

- Real Hosted Page redirects for each configured payment method.
- Real signed IPN using a public HTTPS test shop, including duplicate and delayed events.
- Guest and registered-customer checkout/Back/retry in each supported theme.
- Compatibility run on any additional PrestaShop/PHP versions targeted by the release.
- Merchant reconciliation for an accidental payment of two different invoices.

Create/push the release tag only after these checks. The existing tag workflow publishes GitHub releases automatically.
