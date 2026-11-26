=== Zoho Payments for EDD ===
Contributors: zohopayments
Tags: edd, easy digital downloads, zoho payments, payment gateway, upi
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Cards, UPI, Net Banking, Wallets (India) and Cards & ACH (US) on Easy Digital Downloads 3.x via Zoho Payments.

== Description ==

**Zoho Payments for EDD** integrates Zoho Payments into Easy Digital Downloads 3.x with India (`zoho.in`) and United States (`zoho.com`) dual-edition support, on-demand OAuth 2.0 token caching, embedded modal checkout, admin refunds, and HMAC-SHA256 verified webhooks.

= External Services =

This plugin connects to Zoho Payments services to authenticate your merchant account, create payment sessions, and issue refunds:

* **Zoho Accounts OAuth 2.0 (`https://accounts.zoho.in`, `https://accounts.zoho.com`)** — Used for OAuth authorization and on-demand token refresh.
* **Zoho Payments REST API (`https://payments.zoho.in`, `https://paymentssandbox.zoho.in`, `https://payments.zoho.com`, `https://paymentssandbox.zoho.com`)** — Used to create payment sessions and process refunds.
* **Zoho Payments Widget SDK (`https://static.zohocdn.com/zpay/zpay-js/v1/zpayments.js`)** — Loaded on EDD checkout to display Zoho's payment modal.
* **Legal:** [Zoho Terms of Service](https://www.zoho.com/terms.html) | [Zoho Privacy Policy](https://www.zoho.com/privacy.html)

== Installation ==

1. Upload and activate via **Plugins → Add New → Upload Plugin** (requires Easy Digital Downloads 3.0+).
2. Go to **Downloads → Settings → Payments → Zoho Payments**.
3. Choose your **Edition** (`India` or `United States`), toggle **Sandbox** if testing, save your credentials, and click **Connect Zoho Payments**.
4. Register the **Webhook URL** in Zoho Payments Developer Space and save your **Webhook Signing Key**.

== Changelog ==

= 1.0 =
* Initial production release for Easy Digital Downloads 3.x with India & US dual-edition support, on-demand OAuth caching, refunds, and HMAC-verified webhooks.
