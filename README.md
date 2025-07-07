# Zano Payment Gateway for WooCommerce

A non-custodial WordPress plugin that enables WooCommerce stores to accept **Zano (ZANO)** and **Freedom Dollar (fUSD)** directly, with full privacy and without intermediaries.

![Zano Payment Gateway Screenshot](https://i.postimg.cc/br57Stfv/Screenshot-2568-07-07-at-10-13-25.png)

## 🔐 Features

- **Non-Custodial:** You retain full control over your funds
- **ZANO + fUSD Support:** Accept both Zano and Freedom Dollar stablecoin
- **Real-Time Monitoring:** Automatic detection and confirmation of blockchain payments
- **QR Code Payments:** Seamless mobile wallet integration
- **Integrated Addressing:** Unique Payment IDs generated per transaction
- **Admin Dashboard:** Monitor and manage orders with ease
- **Automated Updates:** Order statuses update upon confirmation
- **Security-Focused:** Payment verification prevents mix-ups or replay attacks

## 💰 How It Works

1. Customer selects "Zano Payment" at checkout
2. Plugin generates a unique integrated address with payment ID
3. Customer sends ZANO or fUSD to that address
4. Plugin monitors the blockchain via your view key
5. Once confirmed, the order status updates automatically

## 🛠 Requirements

- WordPress 5.0 or higher
- WooCommerce 4.0 or higher
- PHP 7.4 or higher
- A Zano wallet address and private view key
- Access to a Zano blockchain node (public or self-hosted)

## 📦 Installation

1. Upload to `/wp-content/plugins/`  
2. Activate the plugin via the WordPress admin  
3. Go to **WooCommerce → Settings → Payments → Zano Payment**  
4. Enter your wallet address and private view key  
5. Save changes and test with a small transaction

## 🌐 Hosting Recommendation

We recommend using [Hostinger](https://www.hostinger.com/) for hosting your WordPress website. It has been tested for compatibility and works reliably with this plugin.

## 💬 FAQ

**Is this plugin custodial?**  
No — you maintain full control. The plugin uses only a read-only view key to monitor payments.

**What cryptocurrencies are supported?**  
ZANO and fUSD (Freedom Dollar), both on the Zano blockchain.

**Do I need to run a Zano node?**  
Not required. Public Zano nodes are supported by default, but you can use your own node for privacy.

**What if a payment is made but not confirmed?**  
The plugin monitors confirmations and updates the order once the required number is reached (default: 10).

**Can I verify payments manually?**  
Yes. The admin dashboard provides tools for manual verification.

## 📸 Screenshots

1. Zano payment option at checkout  
2. QR code and payment instructions  
3. Admin dashboard with transaction monitoring  
4. Plugin settings panel  
5. Automatic order updates

## 🧩 Technical Overview

**Database Tables:**  
- Custom table for tracking payments and statuses

**Cron Jobs:**  
- Monitor payments: every 5 minutes  
- Update statuses: every 10 minutes  
- Expired cleanup: hourly

**API Endpoints:**  
- Check payment status  
- Confirm transactions  
- Update order status

**Security Features:**  
- Rate-limited API calls  
- Input sanitization  
- View key stored securely  
- Payment ID matching

## 🙌 Contributing

Pull requests and feedback welcome!

## 📖 Learn More

- [Zano.org](https://zano.org)  
- [FreedomDollar.com](https://freedomdollar.com)  

## 📜 License

This plugin is open source and licensed under [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
