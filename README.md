# SFS Order Tracking (WordPress plugin)

Receives B2Sign shipments and live UPS/FedEx status from the **SFS Tracking Sync** Chrome extension, attaches them
to the right order, and shows customers live tracking on the tracking page, the order page and My Orders.
The supplier is never shown to customers.

## How it fits together

```
Chrome extension (owner's Chrome) ── signed POST /sfs-tracking/v1/sync ──► this plugin
   reads B2Sign + UPS/FedEx pages                                          matches orders, saves shipments,
                                                                           marks Shipped (one email), schedules checks
Customer's open tracking page ◄── POST /sfs-tracking/v1/track every 60 s (signed 2-hour token, no cache)
```

## Matching (owner decision 2026-10-29)

1. **Exact:** type the B2Sign order number (e.g. `B2612345`) in the order screen's **Shipments** box. Several are
   fine: separate them with commas. The item links on the next sync, or press **Link now**.
2. Items already linked, items staff confirmed in the review queue, and our order number in the B2Sign job name
   are also exact.
3. Anything else goes to **Orders → Tracking sync → Review queue** with suggested orders. Staff confirm one or
   click "Not ours". Fuzzy matches are never applied unless "Auto-apply confident fuzzy matches" is turned on.

## What changes on an order

- A **Shipments** list with one entry per package (meta `_sfs_shipments`). The first package also fills the theme's
  `_tracking_carrier` / `_tracking_number`, so the existing emails, quick edit and status box keep working.
- When **every** item has shipped and the order is pending, in production or on hold, the order becomes
  **Shipped** through the normal status change. The theme sends its one shipped email, listing every package.
  This never happens for cancelled, refunded or failed orders, and never twice for the same order.
- Only some items shipped: the status stays as it is, and customers see "Partially shipped (1 of 2 items)".
- An item B2Sign refunded, or a shipment for a cancelled order, is flagged for staff and never changes the order.
- Carrier status: label created, in transit, out for delivery, delivered, delivery issue, returning, cancelled.
  Customers see "Updated N min ago" from the real last check, never a made-up time.
- Staff get an email when a package has a delivery issue. Customer delivered/issue emails exist but are **off**.

## Settings (Orders → Tracking sync)

The settings are stored in WordPress options, so **a local → live push overwrites them** with your local settings.
After a push, check the mode on live. To pin dry-run on live regardless of pushes, add this to live
`wp-config.php`:

```php
define('SFS_TRACKING_DRY_RUN', false); // or true
```

The extension key lives only in `wp-config.php` (`SFS_TRACKING_KEY_ID`, `SFS_TRACKING_SECRET`), never in options.

## Go-live runbook (owner)

1. Upload this folder to live `wp-content/plugins/sfs-order-tracking/` (or upload `sfs-order-tracking-1.0.0.zip`
   under Plugins → Add New → Upload) and **activate** it. Upload these four changed theme files to
   `wp-content/themes/wholesale/`:
   - `inc/admin-orders.php`
   - `page-track-order.php`
   - `single-order.php`
   - `my_orders.php`
2. In wp-admin go to **Orders → Tracking sync** and click **Generate key**. Copy the two `define(...)` lines it shows
   (they are shown only once) into live `wp-config.php` using cPanel File Manager, above "That's all, stop editing!".
3. In Chrome: open `chrome://extensions`, turn on Developer mode, click **Load unpacked** and choose the
   `sfs-tracking-extension` folder. Open its Options, choose **Live**, paste the key ID and secret, and click
   **Test connection**. Then run **Test carrier check** with one current UPS or FedEx number.
4. Keep **Dry run ON** and click **Sync now** in the extension. On **Orders → Tracking sync**, read the log
   ("Would mark Shipped…" lines) and confirm or ignore anything in the review queue.
5. Turn **Dry run OFF** and sync again. Check one real order end to end: tracking saved, one shipped email, and the
   customer tracking page shows live status.
6. Keep Chrome open on that computer during work hours. A background tab may briefly open and close a UPS or FedEx
   page: that is the tracking check.
7. From now on, type the B2Sign order number on our order (Shipments box) whenever you place the supplier order.

## Safety and privacy

- Requests from the extension are signed: HMAC-SHA256 in X-SFS-* headers. Requests more than 5 minutes off,
  reused requests and wrong signatures are refused, and an address is locked out for 15 minutes after 10 failures.
- `/track` returns only a whitelist of fields (tests assert the exact keys): no B2Sign numbers, job names,
  factory, ship-from or prices. Only destination-side scan locations are shown. Every response is no-store, and
  the tracking, order and My Orders pages are never page-cached.
- The inbox and log tables are registered as live-owned (`wudt_live_owned_tables`), so a push never wipes them.
  Order meta travels with live orders.

## Tests

```bash
php tests/run.php unit
```

```bash
php tests/run.php integration
```

Integration tests need the plugin active on the local site. They create orders titled "SFS TRACKING TEST ORDER",
capture mail instead of sending it, and delete everything they created. The final line reports what was left
(it must be 0). `tests/vectors/hmac.json` is shared with the extension.

## Uninstall

Deleting the plugin removes its settings, cron job and tables. Tracking stored on orders is kept unless
"Also delete tracking data stored on orders" is ticked.
