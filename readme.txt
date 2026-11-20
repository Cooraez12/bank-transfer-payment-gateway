=== RT Bank Transfer Checkout ===
Contributors: rtapp
Tags: woocommerce, payment gateway, ach, bank transfer, checkout
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 6.5.4
WC tested up to: 11.3
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept ACH bank transfer payments in WooCommerce through RT's hosted payment page.

== Description ==

RT Bank Transfer Checkout adds a "Pay by bank transfer (ACH)" option to your WooCommerce checkout. The customer is taken to RT's secure payment page (app.rt.app), connects their US bank account and authorises the debit. The order is placed on hold while the transfer is in flight (1 to 5 business days) and marked paid only when RT confirms the funds have settled. If the bank later returns the payment, the order is moved to "Payment returned" and you are notified by e-mail.

Supports the classic and block checkouts, WooCommerce High-Performance Order Storage, several RT business accounts with priority routing, and a sandbox mode for testing.

You need an RT business account to use this plugin. Copy your API keys from your RT dashboard under Developers > API keys.

== External Services ==

This plugin connects to RT's payment service, operated by RT.app at https://app.rt.app, to create payment sessions, check payment status and check that your RT business account is active. Nothing is sent until you have saved your API keys in the plugin settings.

What is sent, and when:

* `https://app.rt.app/payment-api/request-payment` - when a customer places an order with this gateway. Sends the order number, order total and currency, the customer's name, e-mail address, phone number and billing address, the customer's IP address, the WooCommerce customer ID (0 for guests), the return URL of your shop, your account's public API key and the plugin version. The request is signed with your secret API key; the secret itself is not sent.
* `https://app.rt.app/payment-api/dailylimit` - when the checkout page is shown and when an order is placed, to check that the order total is within your account's daily limit. Sends the amount and your public API key, signed with the secret key.
* `https://app.rt.app/payment-api/payment-requests/public/{payment id}/status` - when the customer returns from the payment page, while the checkout page is waiting for the payment, when you open an order in the admin, and every two hours for orders still awaiting payment. Sends the payment ID and your public API key, signed with the secret key.
* `https://app.rt.app/payment-api/cancel-order-link` - when an order with an unstarted payment is cancelled in WooCommerce. Sends the order number and payment ID, signed with the secret key.
* `https://app.rt.app/payment-api/sync-account-status` - every two hours and when you press "Check with RT" in the settings, to check that your API keys are valid. Sends each account's public key with a signature made with its secret key.
* `https://app.rt.app/payment-api/plugin/check/plugin` - only if you turn on "Share plugin status with RT" in the settings (off by default): when the plugin is deactivated, when its settings are saved, and after each account sync. Nothing is sent when the plugin is activated. Sends your account titles and public keys, your shop's domain name, and the plugin, WordPress and WooCommerce versions.

RT sends payment status updates back to your shop at `/wp-json/rt-bank-transfer/v1/data`, signed with your secret API key. Updates without a valid signature are rejected.

Your secret API keys are stored in your WordPress database and are only ever used to sign requests; they are never transmitted to RT or shown in the browser. Customer bank account details are entered on RT's payment page and never pass through your shop.

* Terms of Service: https://rt.app/terms/
* Privacy Policy: https://rt.app/privacy/
* All RT legal terms: https://rt.app/legal/

== Installation ==

1. In WordPress go to Plugins > Add New, search for "RT Bank Transfer Checkout" and press Install Now. (Or upload the plugin ZIP under Plugins > Add New > Upload Plugin.)
2. Activate the plugin. WooCommerce must be active.
3. Go to WooCommerce > Settings > Payments > RT Bank Transfer, turn on the payment method and add an account with your RT keys (sandbox keys are enough to test).
4. Press "Check with RT" to confirm RT accepts the keys.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Sign in to your RT dashboard and open Developers > API keys. Sandbox (test) keys and live keys are both there.

= Why is my order "On hold"? =

The customer authorised the bank transfer and the debit is in flight. ACH transfers take 1 to 5 business days to settle. The order moves to Processing (or Completed, as configured) when RT confirms the funds arrived.

= What does "Payment returned" mean? =

The customer's bank returned the settled payment (for example, insufficient funds or an unauthorised-debit claim). The funds are no longer in your account. The order note shows the bank's return code; review the order before fulfilling it.

= Can I test without taking real payments? =

Yes. Turn on sandbox mode and enter the sandbox keys from your RT dashboard. No real money moves. On a live site (`WP_ENVIRONMENT_TYPE` set to `production`), paid test orders are put on hold with a note instead of being marked paid, so they are never shipped by mistake.

== Screenshots ==

1. The "Pay by bank transfer (ACH)" option at checkout.
2. The settings screen with a payment account added.

== Changelog ==

= 1.0.5 =
* All functions, classes, settings, order data, order statuses and scripts now use the plugin's own "rtbtch" prefix. Existing settings, orders and payment links are moved over automatically on update.
* Activation now only creates the plugin's table and settings. It no longer contacts RT.
* The payment-link table is created reliably on all MySQL versions.
* Test-mode and setup notices appear only on the plugin's settings screen and WooCommerce order screens.
* Admin styles are limited to the plugin's own settings screen.

= 1.0.4 =
* New name: RT Bank Transfer Checkout. Prepared for the WordPress.org plugin directory.
* Licence is now GPLv2 or later.

= 1.0.3 =
* Orders stay on hold while the bank transfer settles, and returned payments are flagged.

= 1.0.2 =
* Security improvements: signed requests and webhooks, stricter payment checks, and no secret keys sent over the network.
* Works with the block checkout and High-Performance Order Storage.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.5 =
Renames the plugin's stored data to its own prefix. Settings and orders are moved automatically. Test stores that point at another RT environment should use the rtbtch_api_base filter.

== Support ==

For help, visit https://rt.app/help/ or e-mail support@rt.app.
