=== Customer Lens – Customer History & Risk Signals for WooCommerce ===
Contributors: cornelraiu-1
Tags: woocommerce, customer history, cash on delivery, refused delivery, fraud prevention
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

See each customer's previous orders, refunds, cancellations and refused deliveries on the WooCommerce order screen, before you ship.

== Description ==

Before a parcel leaves, someone opens the order. Customer Lens puts the customer's history right there, so that person knows in five seconds whether this is a loyal customer, a first-timer, or someone who refused the last two cash-on-delivery parcels.

On every order, a panel shows:

* **Where this order falls**: "First order", "4th order".
* **What went wrong before**: refunds with their amounts, cancelled orders, failed payments and refused deliveries.
* **When they last ordered**, and what they spent in total, net of refunds.
* **Every previous order**, with its status, total and courier, filterable by status.
* **How the orders were matched**: by email, phone or delivery address.
* **Notes for the whole team**, shared across all of the customer's orders.

The orders list gets a Customer Lens column with the same signals ("3rd order", "2 refused COD") and a filter for customers with refused deliveries, with refunds, flagged ones and first-time customers.

= One customer, however they order =

Guests are customers too. Customer Lens matches orders by account, email address, phone number and delivery address, in that order of trust, so a guest who comes back with a new email but the same phone is still one customer. Phone numbers are compared in international format (0722 123 456, +40 722 123 456 and 0040722123456 are one number) and Gmail addresses without their dots and +tags.

When an order's email belongs to one customer and its phone to another, the order goes to the stronger match and the panel says "May be the same person as customer #318". Merging takes one click and can be undone for 30 days. A phone or email used by three or more different names in a year, such as an office line, stops matching anyone. An address alone never links two orders.

= Counts, not verdicts =

Customer Lens never scores a customer and never guesses. Every signal is a number or a date, and its colour only says whether it has reached the threshold you set: "2 refused COD" turns yellow from the first refusal and red from the third, by default. Change the thresholds under Customer Lens > Settings.

The plugin never cancels, holds or changes an order, and never tells a customer anything.

= Built for cash on delivery =

In stores where many parcels are paid at the door, a refused delivery costs the shipping both ways. Mark an order as refused in one click, flag a customer as "watch" with a reason and an expiry date, and every colleague sees it on the next order.

= Flag a customer =

Flag a customer as **Watch** (a warning on every order) or **Trusted** (a known good customer), with an internal reason and an expiry of 30, 90 or 365 days, or none. Flags, notes, refused deliveries and merges are kept with the name of the person who made them.

= Private by design =

* Customer Lens reads your WooCommerce orders and stores what it derives in its own tables. It never writes to your orders.
* Nothing leaves your site. There is no tracking, no account and no external service.
* Events are deleted after 36 months by default (12 to 60, your choice). Notes stay until the customer asks for erasure.
* WordPress's personal data export and erasure tools include Customer Lens data.
* Settings > Privacy has a ready paragraph for your privacy policy, in English and Romanian.

= Fast on large stores =

The order panel reads one row of precomputed counts, and the orders list adds one query per page. On a store with 55,000 orders, the panel renders in about 20 ms for a typical customer and 100 ms for one with 170 orders. Past orders are indexed in the background, newest first, after activation.

= Works with =

* WooCommerce 8.2 or later, with High-Performance Order Storage (HPOS) or legacy order storage.
* The block checkout and the classic checkout.
* Multisite: each site keeps its own customers.
* WP-CLI: `wp customer-lens import`, `status`, `rebuild` and `erase`.

== Installation ==

1. Install and activate WooCommerce.
2. Install Customer Lens from Plugins > Add New, or upload the zip, and activate it.
3. Customer Lens starts indexing your past orders in the background. A notice shows the progress. On large stores you can run `wp customer-lens import` instead.
4. Open any order: the Customer Lens panel is below the order details.

== Frequently Asked Questions ==

= Does Customer Lens cancel or hold orders? =

No. It shows information to your staff and nothing else. Orders, checkout and emails work exactly as before.

= Will my customers know they were flagged? =

No. Flags, notes and refused deliveries are visible in wp-admin only, to users who can manage WooCommerce.

= Does it send any data outside my site? =

No. Customer Lens has no external service and no tracking.

= How are refused deliveries counted? =

Click "Mark refused delivery" on the order. Each order counts once, and you can undo it. Cash-on-delivery orders that were refused before you installed the plugin are usually cancelled, and show in the "cancelled" count.

= What does "net lifetime" mean? =

The total of the customer's orders that were not cancelled or failed, minus their refunds, in your store currency.

= Who can see the panel? =

Users who can manage WooCommerce: administrators and shop managers. Developers can change the capability with the `mwtcl_capability` filter.

= Does it work with HPOS? =

Yes, and with legacy order storage, and with both kept in sync.

= What happens to the data if I delete the plugin? =

It stays, so that flags and notes survive a reinstall. To remove everything, tick "Remove all Customer Lens data when the plugin is deleted" under Customer Lens > Settings > Privacy before deleting it.

= How do I erase a customer's data? =

Use Tools > Erase Personal Data with their email address, or `wp customer-lens erase <email-or-phone>`. Their identities, events and notes are deleted; the orders themselves are WooCommerce's and stay.

== Screenshots ==

1. The Customer Lens panel on the order screen: signals, the flag, a merge suggestion, previous orders, identities and notes.
2. Flag a customer as Watch or Trusted, with a reason and an expiry date.
3. The Customer Lens column on the orders list.
4. The orders list filtered to customers with refused deliveries.
5. Settings: when each count turns into a warning, and how long events are kept.
6. Privacy: a ready paragraph for your privacy policy, and what happens on uninstall.

== Changelog ==

= 1.0.0 =
* First release: customer history and signals on the order screen and the orders list, flags, notes, refused deliveries, merges, background indexing, privacy tools and WP-CLI commands.
