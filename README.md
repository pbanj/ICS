# Inventory Control System

made with gemini, I had it make this to auto mark certain things on my site as out of stock when open orders go over a certain amount. Handy when taking in devices to work on so you don't get too many at once. Handles variations and products you don't track stock on


## Key Features

* **Dynamic Throttling:** Automatically marks targeted products as "Out of stock" and blocks add-to-cart actions the moment your active order limit is reached.
* **Smart Cart Validation:** Ejects restricted items from active carts with a custom error message if the threshold is crossed while a customer is actively shopping.
* **Advanced Resume Conditions:** Configure the store to automatically resume sales when orders drop below the threshold, wait until exactly zero open orders remain, or stay closed until manually overridden via the admin UI.
* **Discord Integration:** Sends color-coded, real-time webhook alerts to your Discord server the exact moment the store pauses or resumes.
* **Universal Cache Purging:** Automatically flushes major caching layers (LiteSpeed, WP Rocket, W3TC, SG Optimizer, etc.) on state changes so frontend visitors immediately see the correct stock status.
* **Admin UI Dashboard:** Manage thresholds, target product IDs, monitored order statuses (e.g., Processing, On-Hold), and custom frontend messages directly from `WooCommerce > Inventory Control System`.

## Installation & Usage

1. Copy the contents of the `.php` file. Or import the json in Fluent Snippets.
2. Paste it into your WordPress snippet manager (such as Fluent Snippets, WPCode, or Code Snippets).
3. **Important:** Set the snippet to run **Everywhere**. It must be active on the frontend to hook into the cart validation and product display logic.
4. Navigate to **WooCommerce > Inventory Control System** in your WordPress dashboard to configure your threshold, target products, and the Discord webhook.






<img width="1725" height="1216" alt="image" src="https://github.com/user-attachments/assets/7af6c747-bc4f-48f8-b4f7-085c982da1a4" />
