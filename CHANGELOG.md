# Changelog

## 1.0.0 — 2026-10-29

- First release: signed /ping and /sync, B2Sign order-number matching, review queue, shipments meta,
  auto Shipped with one shipped email listing every package, carrier status and scheduling, staff alerts,
  public /track with live updates, Delivered progress step, admin page, Shipments box and list column.
- Review queue bulk actions (confirm suggested, mark not ours, reopen, delete) and the B2Sign number in Quick Edit.
- Typing a B2Sign number is enough: the sync answer lists numbers with no stored record (want_b2) and the
  extension sends them in the same run.
- Auto In production is on: a Pending review order moves to In production from the B2Sign status, before
  any tracking number exists (built-in status list when the setting is empty).
- A carrier reading that shows the package moving or delivered marks a Pending review / In production order
  Shipped (once), including tracking numbers staff typed; waits while linked B2Sign items are unshipped.
- Staff box on the front-end order page (theme action wholesale_order_staff_tools): B2Sign order number and
  tracking number, saved through POST /admin/order-tracking and linked at once.
- One box holding several B2Sign items counts for every product in it (b2.item_ids), so orders whose
  products share a package are marked Shipped; removing a shipment unlinks each item it carried.
- Every sync brings stored open orders up to date (Shipped / In production), not only orders in that run.
- An order whose linked items all shipped but has more lines is flagged once for staff instead of waiting silently.
- Order status pill shows the delivery stage on Shipped orders (In transit, Out for delivery, Delivered,
  Delivery issue, Returned) and "Partly shipped" on open orders (theme filter wholesale_order_status_badge_item).
- Real order statuses for every stage (theme registers them): open orders follow the B2Sign stage forward
  (In production, Finishing for Trimming, Ready for pickup), never while B2Sign waits on us (artwork,
  approval, hold); shipped orders follow their packages (In transit, Out for delivery, Delivered, Delivery
  issue, Returned to sender). A status staff set by hand is kept until the packages show a new stage.
  Stage statuses are only written when the theme supports them.
- Orders → Tracking sync is minimal: summary tiles, the review queue only when needed, a "Connect the extension"
  card to generate, copy and save the key on the page (option wudt_sfs_tracking_key, kept per site by the
  migration tool; wp-config.php constants still win), and settings, carrier pages and activity folded away.
- My Orders: a shipped order's row shows only the estimate next to its status pill (no duplicate pill); the
  shipments section has the #shipments anchor used by "Track package".
