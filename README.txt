=== Bleumi Pay Crypto Payments for WooCommerce ===
Contributors: bleumiinc
Plugin URL: https://pay.bleumi.com/
Tags: payment-gateway, erc20-token, digital-payments, wordpress-plugin, payment-processing, woocommerce-plugin, accept-crypto-payments, bleumipay-plugin
Requires at least: 4.9
Requires PHP: 5.6
Tested up to: 5.4
Stable tag: 1.0.9
License: MIT
License URI: https://github.com/bleumipay/bleumipay-woocommerce/blob/master/LICENSE
Donate link: https://pay.bleumi.com/

Accept quick and secure digital currency payments (like Tether USD, USD Coin, Stasis EURO, CryptoFranc) in your WooCommerce Store. 

== Description ==

[Bleumi Pay](https://pay.bleumi.com) is an all-in-one global digital currency payment platform. Our platform enables businesses to Accept Digital Currency Payments and Send Digital Currency Payouts including Microtransactions.

With this extension, customers can make stablecoin payments (cryptocurrencies designed to mimic the value of fiat money like the dollar or the euro) in your WooCommerce Store.

= Demo Video =

[Bleumi Pay - Hosted Checkout - Demo](https://www.youtube.com/watch?v=_TP0ialIDas)

= Benefits of Stablecoin Payments =

*   Quick - Get paid within seconds.
*   Secure - By blockchain technology.
*   Global - Payments are borderless.
*   No Chargeback - Payments are irreversible.
*   No Volatility Risk - Benefits of digital currency with the stability of fiat money (like USD, AUD, CAD, EUR, GBP, CHF, HKD, SGD, KRW). e.g. 1 USD Coin can always be redeemed for US$1.00

= Supported Stablecoins =

One Integration for major Blockchain Networks — Algorand, Ethereum, xDAI 
 
Tap into 7Bn+ fiat money circulating in stablecoins — Bleumi Pay supports any stablecoin on supported blockchain networks. 

= Recommended USD Stablecoins =

ERC-20

*   Tether USD (USDT)
*   USD Coin (USDC)
*   Paxos (PAX)
*   Binance USD (BUSD)
*   TrueUSD (TUSD)
*   Multi-collateral DAI (DAI)
*   USDK (USDK)
*   Gemini Dollar (GUSD)
*   sUSD (SUSD)
*   USDQ (USDQ)
*   StableUSD (USDS)

ERC-20

*   Dollar on Chain (DOC)
*   RIF Dollar on Chain (RDOC)

= Recommended other fiat money Stablecoins =

ERC-20

*   Stasis EURO (EURS)
*   CryptoFranc (XCHF)
*   1SG (1SG)

For more details please refer the following links

[Blockchains](https://pay.bleumi.com/docs/#supported-networks)

[Digital Currencies](https://pay.bleumi.com/docs/#tokens)

== Installation ==

= From your WordPress dashboard =

1. Visit 'Plugins > Add New'
2. Search for 'bleumipay'
3. Activate Bleumi Pay Crypto Payment for WooCommerce from your Plugins page.

= From WordPress.org =

1. Download Bleumi Pay Crypto Payment.
2. Upload to your '/wp-content/plugins/' directory, using your favorite method (ftp, sftp, scp, etc...)
3. Activate Bleumi Pay Crypto Payment from your Plugins page.

= Configuring Bleumi Pay Account =

* You will need to set up an account on https://pay.bleumi.com/app/

1. Create API Key
	* Log in to Bleumi Pay Dashboard and navigate to Sandbox (or) Production > Integration Settings and click New API Key to create an API Key. 
	**Note: Production network access requires a verified account. To enable production access, contact support@bleumi.com. 

2. Setup Gas Accounts - Gas Accounts are used to fund Network Fee for your payment operations (Settle/Refund) on the Network. 
	* Log in to Bleumi Pay Dashboard and navigate to Sandbox (or) Production > Gas Accounts. 
	* Select the Network for which the Gas Account needs to be setup. 
	* Click Generate Gas Account. 
	* Fund Gas to the Gas Account and click on Validate & Activate to activate the Gas Account. 

3. Setup Hosted Checkout
	* Log in to [Bleumi Pay Dashboard](https://pay.bleumi.com/app/) and navigate to Sandbox (or) Production > Hosted Checkout. 
	* Under Branding, customize the Brand Name and Brand Logo to be displayed in the Bleumi Pay Hosted Checkout of your WooCommerce Store. 
	* Under Tokens, click Add Token to configure the tokens that you wish to receive as payments from your WooCommerce Store users. You can either: 
		* Select a predefined token and provide a Token Settlement Address (Your Wallet Address to receive payments). 
		* Add a custom token 

= Once Activated =

1. Go to WooCommerce > Settings > Payments
2. Configure the plugin for your store

= Enable / Disable =

Turn the Bleumi Pay payment method on / off for visitors at checkout.

= Title =

Title of the payment method on the checkout page

= Description =

Description of the payment method on the checkout page

= API Key =

Your Bleumi Pay API key. Available within the https://pay.bleumi.com/app/productionIntegration/

= Debug log =

Whether or not to store debug logs.

If this is checked, these are saved within your `wp-content/uploads/wc-logs/` folder in a .log file prefixed with `bleumipay-`

== Frequently Asked Questions ==

= Can clients pay without registering on my website? =

There is no account needed for your clients to pay with crypto coins. They just need to scan the payment QR code or copy the wallet address and enter the right amount to pay.

= Is there any Middleman in control of my payments? =

	* No, our platform is unique because it allows completely decentralized peer to peer transactions. You can use any personal wallet of your choice and keep your tokens on your own devices.
	* Allowing your customer to pay you directly, avoiding any middleman in control of your tokens as typically found with other Payment Gateways.

= How fast do I get paid? =

Since we don't hold your funds or act as a proxy in any capacity, you get paid the moment your customer pays you.

= Does Bleumi Pay have a test environment? =

Yes, you can create an account on Bleumi Pay’s sandbox environment to process payments on testnet. 

= Can I accept multiple tokens for a single order Id? =

Yes, you cannot accept multiple tokens on a single Id. But buyer has to pay the total amount using a single token. 

= What fees do you charge? =

We charge a flat transaction fee irrespective of the number of transactions a merchant processes in a month. This fee will be constant regardless of the amounts involved in the transactions. For custom pricing please visit https://pay.bleumi.com/pricing/

= Is the fee a merchant charged dependent upon the amount in any way? =

No. We charge the same fixed fee irrespective of the amount.

= What is your minimum amount per transaction? =

We have no minimum amount.

= Can you help me to integrate crypto payments to my website? =

Our support team is always here to help. support@bleumi.com.

== Screenshots ==

1. Checkout page with 'Pay with Crypto-currencies Bleumi Pay' enabled.
2. Token selection page
3. Payment address page
4. Blockchain confirmations page
5. Successful payment page
6. Admin Order summary   
7. Payment methods

== Changelog ==

= 1.0 =
* Initial Release

== Upgrade Notice ==

= 1.0 =
Initial Release
