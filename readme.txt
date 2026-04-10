=== ezPayments for WooCommerce ===
Contributors: ezpayments
Donate link: https://ezpayments.co
Tags: woocommerce, payment gateway, ezpayments, payments, checkout
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments in your WooCommerce store via ezPayments with test and live mode support and automatic webhook registration.

== Description ==

**ezPayments for WooCommerce** adds ezPayments as a payment method to your WooCommerce store. During checkout, customers are redirected to a secure, hosted payment page powered by ezPayments where they can complete their purchase using credit/debit cards, bank transfers (ACH), and other supported payment methods.

= Features =

* **Test & Live Modes** - Separate API keys for testing and production. Switch between modes with a single toggle.
* **Automatic Webhooks** - Webhook endpoints are registered automatically when you save your settings. No manual configuration needed.
* **Hosted Payment Page** - Customers pay on a secure, PCI-compliant page hosted by ezPayments.
* **Real-time Order Updates** - Orders are automatically marked as paid when payment completes via webhook notifications.
* **Post-payment Redirect** - Customers are automatically redirected back to your order confirmation page after payment.
* **HPOS Compatible** - Fully compatible with WooCommerce High-Performance Order Storage.
* **Order Metadata** - Payment link ID, mode, and payment method details are stored on the order for easy reference.

= How It Works =

1. Customer adds items to cart and proceeds to checkout.
2. Customer selects ezPayments as the payment method.
3. Customer is redirected to the ezPayments hosted payment page.
4. Customer completes payment (card, ACH, etc.).
5. ezPayments sends a webhook to your store confirming payment.
6. WooCommerce order is automatically updated to "Processing".
7. Customer is redirected back to your order confirmation page.

= Requirements =

* WordPress 5.8 or later
* WooCommerce 6.0 or later
* PHP 7.4 or later
* An ezPayments merchant account ([sign up at ezpayments.co](https://ezpayments.co))
* API keys from your ezPayments dashboard

== Installation ==

= From WordPress Admin Dashboard =

1. Go to **Plugins > Add New**.
2. Click **Upload Plugin** and select the `ezpayments-woocommerce.zip` file.
3. Click **Install Now**, then **Activate**.

= Manual Installation =

1. Download the plugin zip file.
2. Extract and upload the `ezpayments-woocommerce` folder to `/wp-content/plugins/`.
3. Go to **Plugins** in your WordPress admin and activate **ezPayments for WooCommerce**.

= Configuration =

1. Go to **WooCommerce > Settings > Payments**.
2. Click **Manage** next to **ezPayments**.
3. Enable the payment method.
4. Enter your **Test API Key** (starts with `sk_test_`) and/or **Live API Key** (starts with `sk_live_`).
5. Choose your mode (Test or Live).
6. Click **Save changes** - webhooks are registered automatically.

= Getting Your API Keys =

1. Log in to your [ezPayments dashboard](https://app.ezpayments.co).
2. Navigate to **Settings > API Keys**.
3. Generate a new API key pair.
4. Copy the **Secret Key** (shown only once) into the plugin settings.

== Frequently Asked Questions ==

= Where do I get my API keys? =

Log in to your ezPayments dashboard at [app.ezpayments.co](https://app.ezpayments.co), go to Settings > API Keys, and generate a key pair. Use the secret key (starts with `sk_test_` or `sk_live_`) in the plugin settings.

= How does test mode work? =

When test mode is enabled, the plugin uses your test API key. Payment links created in test mode use test credentials and do not process real charges. This lets you verify the entire checkout flow before going live.

= Do I need to configure webhooks manually? =

No. When you save your plugin settings with a valid API key, the plugin automatically registers a webhook endpoint with ezPayments. You can see the webhook status on the settings page.

= What happens if a customer doesn't complete payment? =

The WooCommerce order stays in "On Hold" status. WooCommerce's built-in unpaid order cleanup (configurable under WooCommerce > Settings > Products > Inventory > Hold stock) will automatically cancel the order after the configured duration.

= What payment methods are supported? =

ezPayments supports credit/debit cards, US bank accounts (ACH), and other methods depending on your account configuration. The available methods are determined by your ezPayments merchant account settings.

= Is this plugin PCI compliant? =

Yes. Card details are entered on ezPayments' hosted payment page, not on your WordPress site. Your server never handles sensitive payment data.

= Can I customize the payment page? =

The payment page is hosted by ezPayments and follows your merchant branding settings configured in the ezPayments dashboard.

= What happens when I deactivate the plugin? =

The plugin automatically cleans up webhook endpoints registered with ezPayments when deactivated. Your existing orders and their payment data are preserved.

== External Services ==

This plugin connects to the following external services:

= ezPayments API =

This plugin sends order data (amount, customer name, email, order reference) to the [ezPayments](https://ezpayments.co) API to create payment links and register webhook endpoints. Customer payment is processed on ezPayments' hosted payment page.

* Service URL: `https://app.ezpayments.co/api/v3/`
* [Terms of Service](https://ezpayments.co/terms)
* [Privacy Policy](https://ezpayments.co/privacy)

= Exchange Rate API =

When your WooCommerce store uses a currency other than USD, this plugin fetches live exchange rates from [Exchange Rate API](https://open.er-api.com) to convert order totals to USD. Only the currency code is sent; no customer data is transmitted.

* Service URL: `https://open.er-api.com/v6/latest/`
* [Terms of Service](https://www.exchangerate-api.com/terms)

= GitHub Releases API =

This plugin checks [GitHub](https://github.com/ezPayments-LLC/ezpayments-wordpress/releases) for new versions to enable auto-updates. Only the current plugin version is compared; no site or user data is sent.

* Service URL: `https://api.github.com/repos/ezPayments-LLC/ezpayments-wordpress/releases/latest`

== Screenshots ==

1. Plugin settings page with test/live mode toggle and API key fields.
2. Checkout page showing ezPayments as a payment option.
3. ezPayments hosted payment page where customers complete payment.
4. Order details showing ezPayments payment metadata.

== Changelog ==

= 1.0.0 =
* Initial release.
* WooCommerce payment gateway integration via ezPayments V3 API.
* Test and live mode support with separate API keys.
* Automatic webhook endpoint registration and cleanup.
* HMAC-SHA256 webhook signature verification.
* Payment link creation with order metadata and customer details.
* Post-payment redirect to WooCommerce order confirmation page.
* "Return to store" link on payment page for order cancellation.
* WooCommerce HPOS (High-Performance Order Storage) compatibility.
* Idempotent webhook processing to prevent duplicate order updates.

== Upgrade Notice ==

= 1.0.0 =
Initial release of ezPayments for WooCommerce.
