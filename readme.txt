=== Zano Payment Gateway ===
Contributors: ZanoList  
Donate link: https://zanolist.com  
Tags: payments, cryptocurrency, woocommerce, zano, blockchain, non-custodial  
Requires at least: 5.0  
Tested up to: 6.4  
Requires PHP: 7.4  
Stable tag: 1.0.0  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

A non-custodial payment processor for WordPress/WooCommerce that allows merchants to accept Zano cryptocurrency.

== Description ==

The Zano Payment Gateway plugin enables WooCommerce stores to accept Zano (ZANO) and Freedom Dollar (fUSD) cryptocurrency payments directly, without requiring a third-party payment processor. This non-custodial solution gives merchants full control over their funds.

= Key Features =

* Non-custodial: You maintain full control of your funds  
* Multi-asset support: Accept both ZANO and fUSD payments  
* Real-time monitoring: Automatic blockchain transaction verification  
* QR code payments: Easy mobile wallet integration  
* Integrated addresses: Unique payment IDs for each transaction  
* Comprehensive management: Admin dashboard for transaction oversight  
* Automated processing: Background monitoring and status updates  
* Security focused: Payment ID verification prevents transaction mix-ups  

= Supported Assets =

* ZANO – Native Zano cryptocurrency  
* fUSD – Freedom Dollar stablecoin  

= How It Works =

1. Customer selects Zano payment at checkout  
2. System generates unique integrated address with payment ID  
3. Customer scans QR code or copies payment details  
4. Customer sends payment from their Zano wallet  
5. Plugin monitors blockchain for payment confirmation  
6. Order status updates automatically when confirmed  

= Requirements =

* WordPress 5.0 or higher  
* WooCommerce 4.0 or higher  
* PHP 7.4 or higher  
* Zano wallet address and private view key  
* Access to Zano blockchain node  

== Installation ==

1. Upload the plugin files to /wp-content/plugins/zano-payment-gateway/  
2. Activate the plugin through the 'Plugins' screen in WordPress  
3. Go to WooCommerce → Settings → Payments  
4. Click on "Zano Payment" to configure settings  
5. Enter your Zano wallet address and private view key  
6. Configure payment settings as needed  
7. Save settings and test with a small transaction  

== Frequently Asked Questions ==

= Is this plugin custodial? =

No, this is a non-custodial solution. Payments go directly to your Zano wallet address. The plugin only monitors the blockchain using your private view key (which is read-only).

= What cryptocurrencies are supported? =

Currently supports ZANO (native Zano) and fUSD (Freedom Dollar) assets on the Zano blockchain.

= Do I need to run a Zano node? =

No, the plugin connects to public Zano nodes by default. However, you can configure it to use your own node for better privacy and reliability.

= How are payments verified? =

The plugin uses your wallet's private view key to scan the blockchain for incoming transactions that match the generated payment IDs.

= What happens if a payment is detected but not confirmed? =

The plugin tracks confirmation status and automatically updates order status once the required number of confirmations is reached (default: 10).

= Can I process payments manually? =

Yes, the admin panel includes tools for manual payment verification and status updates.

= Is there a transaction fee? =

The plugin itself doesn't charge fees. You only pay standard Zano network transaction fees when receiving payments.

== Screenshots ==

1. Checkout page with Zano payment option  
2. Payment details page with QR code  
3. Admin transaction management dashboard  
4. Plugin configuration settings  
5. Order status updates and confirmations  

== Changelog ==

= 1.0.0 =  
* Initial release  
* Non-custodial Zano payment processing  
* ZANO and fUSD asset support  
* Real-time blockchain monitoring  
* QR code payment integration  
* Comprehensive admin dashboard  
* Automated payment verification  
* Bulk order status updates  
* Transaction management tools
== Upgrade Notice ==

= 1.0.0 =  
Initial release of the Zano Payment Gateway plugin.

== Privacy Policy ==

This plugin connects to external Zano blockchain nodes to verify payments. The following data may be transmitted:

* Wallet addresses (public information)  
* Transaction hashes (public blockchain data)  
* Payment IDs (generated locally)  

No personal customer information is transmitted to external services. All payment verification is done using publicly available blockchain data.

== Technical Details ==

= Database Tables =  
The plugin creates a custom table to track payment transactions and their status.

= Cron Jobs =  
- Payment monitoring: Every 5 minutes  
- Status updates: Every 10 minutes  
- Cleanup expired payments: Hourly  

= API Endpoints =  
- Payment status checking  
- Transaction verification  
- Order completion processing  

= Security Features =  
- Payment ID verification  
- Rate limiting on API calls  
- Input validation and sanitization  
- Secure private view key handling  

== Support ==

For support, please visit:  
* Plugin documentation: https://www.zanolist.com/zano-payment-processor
* Bug reports: Plugin support forum  

== Contributing ==

This plugin is open source. Contributions are welcome via GitHub.