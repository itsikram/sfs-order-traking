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
