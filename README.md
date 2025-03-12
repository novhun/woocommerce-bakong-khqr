# WooCommerce Bakong KHQR Payment Gateway

A WooCommerce payment gateway plugin that integrates Bakong KHQR payment system for accepting payments in Cambodia.

## Features
- Generate KHQR codes for payments
- Display QR codes on the thank-you page
- Automatic transaction status checking
- Modern Bootstrap 5 admin interface
- Compatible with WooCommerce High-Performance Order Storage (HPOS)

## Requirements
- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- Bakong API token from [Bakong Portal](https://api-bakong.nbc.gov.kh/register)
- Composer for dependency management

## Installation
1. Clone or download this repository:
   ```bash
   git clone https://github.com/novhun/woocommerce-bakong-khqr.git

2. Navigate to the plugin directory:
 ```bash
cd woocommerce-bakong-khqr
Install dependencies:
 ```bash
composer install

3. Upload the woocommerce-bakong-khqr folder to /wp-content/plugins/ on your WordPress site.
Activate the plugin in WordPress admin.
Configure settings in WooCommerce > Settings > Payments > Bakong KHQR.
Configuration
Bakong Account ID: Your Bakong account (e.g., yourname@bank)
Merchant Name: Your registered merchant name
Merchant City: Your city (e.g., Phnom Penh)
API Token: Obtain from the Bakong Portal
Usage
Customers select "Bakong KHQR Payment" at checkout.
After placing an order, they scan the QR code using the Bakong app.
The plugin automatically checks transaction status and updates the order.
Development
Uses the fidele007/bakong-khqr-php library for KHQR functionality.
Built with Bootstrap 5 for a modern admin interface.

License
[license, e.g., MIT, GPL, etc.]

Support
For issues or feature requests, please open an issue on this repository.