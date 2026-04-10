# ezPayments for WooCommerce

Accept payments in your WooCommerce store via ezPayments. Customers are redirected to a secure, hosted payment page to complete their purchase using credit/debit cards, bank transfers (ACH), and other supported methods.

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- An [ezPayments](https://ezpayments.co) merchant account
- HTTPS enabled on your WordPress site

## Installation

### Option 1: Upload via WordPress Admin (Recommended)

1. Download the latest release zip from the [Releases page](https://github.com/ezPayments-LLC/ezpayments-wordpress/releases)
2. In your WordPress admin, go to **Plugins > Add New**
3. Click **Upload Plugin** at the top of the page
4. Choose the downloaded `ezpayments-woocommerce.zip` file
5. Click **Install Now**
6. Click **Activate Plugin**

### Option 2: Manual Upload via FTP/File Manager

1. Download the latest release zip from the [Releases page](https://github.com/ezPayments-LLC/ezpayments-wordpress/releases)
2. Extract the zip file
3. Upload the `ezpayments-woocommerce` folder to `/wp-content/plugins/` on your server
4. In your WordPress admin, go to **Plugins**
5. Find **ezPayments for WooCommerce** and click **Activate**

## Configuration

1. Go to **WooCommerce > Settings > Payments**
2. Find **ezPayments** and click **Manage**
3. Enable the payment method
4. Enter your API keys:
   - **Test Secret Key** — starts with `sk_test_` (for testing)
   - **Live Secret Key** — starts with `sk_live_` (for real payments)
5. Select your mode (Test or Live)
6. Click **Save changes**

Webhooks are registered automatically when you save your settings.

### Getting Your API Keys

1. Log in to your [ezPayments dashboard](https://app.ezpayments.co)
2. Go to **Settings > API Keys**
3. Click **Generate New Key**
4. Copy the **Secret Key** (shown only once) into the plugin settings

> **Important:** Use the **Secret Key** (`sk_test_...` / `sk_live_...`), not the Publishable Key (`pk_...`).

## How It Works

1. Customer adds items to cart and proceeds to checkout
2. Customer selects **ezPayments** as the payment method
3. Customer is redirected to the ezPayments hosted payment page
4. Customer completes payment (card, ACH, etc.)
5. ezPayments sends a webhook to your store confirming payment
6. WooCommerce order is automatically updated to **Processing**
7. Customer is redirected back to your order confirmation page

## Test Mode

When test mode is enabled:
- The plugin uses your test API key (`sk_test_...`)
- Payment links are created in test mode
- No real charges are made
- A visible **TEST MODE** banner appears on the payment page
- Use Stripe test card `4242 4242 4242 4242` with any future expiry and any CVC

## Features

- **Test & Live Modes** with separate API keys and one-click switching
- **Automatic Webhook Registration** on settings save
- **Hosted Payment Page** (PCI-compliant, no card data touches your server)
- **Real-time Order Updates** via signed webhooks
- **Post-payment Redirect** back to your WooCommerce confirmation page
- **HPOS Compatible** (High-Performance Order Storage)
- **Idempotent Processing** prevents duplicate order updates
- **Rate-limited Webhook Endpoint** for DoS protection
- **HMAC-SHA256 Signature Verification** with replay attack prevention

## Frequently Asked Questions

**Do I need to configure webhooks manually?**
No. Webhooks are registered automatically when you save your settings with a valid API key.

**What happens if a customer doesn't complete payment?**
The order stays in "On Hold" status. WooCommerce's built-in unpaid order cleanup will cancel it after the hold stock duration (configurable in WooCommerce > Settings > Products > Inventory).

**Is this plugin PCI compliant?**
Yes. Card details are entered on ezPayments' hosted payment page. Your WordPress server never handles sensitive payment data.

**What happens when I deactivate the plugin?**
Webhook endpoints are automatically cleaned up. Existing orders and their payment data are preserved.

## Support

- [Report an issue](https://github.com/ezPayments-LLC/ezpayments-wordpress/issues)
- [ezPayments Documentation](https://ezpayments.co/docs)
- Email: support@ezpayments.co

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.
