# Audit: Vendor 42 (Bongobox, user 134) vs CarryBee final invoices

Date: 2026-11-22 · Source: live data (apidropship.resellerbrain.com), read-only.
Vendor 42 has its **own CarryBee account** (store 30070), so CarryBee pays the vendor directly and the vendor owes
the platform `CarryBee net − supplier price` per order.

Formulas used (CarryBee figures per parcel):

- **Vendor received** = collected − delivery fee − COD fee
- **Vendor owes platform** = vendor received − supplier price
- **Dropshipper profit** = collected − admin price − customer delivery charge − COD fee
- **Platform profit** = (admin price − supplier price) + (customer delivery charge − CarryBee delivery fee)
- Check: vendor owes = dropshipper profit + platform profit (holds on every order below)

## 1. Headline

| | Amount |
|---|---|
| CarryBee paid vendor 42 (9 invoices, all `paid`) | **৳14,158.46** |
| Supplier price of the 13 delivered orders | ৳11,540.00 |
| **Vendor really owes platform (CarryBee-matched)** | **৳2,618.46** |
| Vendor invoice INV-V42-20261120-836 — system calculated | ৳2,372.96 |
| Same invoice — after manual admin adjustment (+৳373.04) | ৳2,746.00 (pending) |

The invoice is wrong in both directions: −৳824 on order 65, +৳355 overstated fees on 11 orders, and ৳223.50 of
return charges that CarryBee took from the vendor but our system shows as ৳0.

## 2. Order by order

| Order | CarryBee invoice | Collected | CB delivery | CB COD | Vendor owes — recorded | Vendor owes — correct | Diff | Dropshipper — recorded | Dropshipper — correct | DS status |
|---|---|---|---|---|---|---|---|---|---|---|
| 65 | I171026QB3G7S | 844 | 125 | 8.44 | **−793.44** | 30.56 | **−824.00** | 0 | −8.44 | pending |
| 70 (returned) | I241026ZS0UFY | 50 | 105 | 0.50 | 0 | −55.50 | +55.50 | — | 0 | — |
| ↳ return line R1017HXLPPC | I241026ZS0UFY | 0 | 31.50 fee | | 0 | −31.50 | +31.50 | | | |
| 78 | I241026ZS0UFY | 920 | 105 | 9.20 | 185.80 | 185.80 | 0 | **139.00** | 129.80 | settled (+9.20 over) |
| 80 | I291026HR1B7Q | 1231 | 120 | 12.31 | 83.69 | 48.69 | +35.00 | 0 | −12.31 | pending |
| 90 | I281026ZAC0RD | 1340 | 120 | 13.40 | 191.60 | 156.60 | +35.00 | **403.29** | 155.60 | settled (**+247.69 over**) |
| 117 | I141126JK45EC | 1400 | 140 | 14.00 | 231.00 | 196.00 | +35.00 | 155.00 | 155.00 | settled |
| 119 | I1011262JBXGP | 1130 | 125 | 11.30 | 393.70 | 373.70 | +20.00 | 337.70 | 337.70 | settled |
| 120 | I0711260RV7N7 | 1500 | 150 | 15.00 | 330.00 | 285.00 | +45.00 | 255.30 | 254.00 | pending (+1.30) |
| 124 | I1011262JBXGP | 1430 | 140 | 14.30 | 260.70 | 225.70 | +35.00 | 184.70 | 184.70 | settled |
| 125 | I111126QPPB77 | 999 | 125 | 9.99 | 314.01 | 294.01 | +20.00 | 260.01 | 260.01 | settled |
| 126 (returned) | I1011262JBXGP | 0 | 105 | 0 | 0 | −105.00 | +105.00 | — | 0 | — |
| ↳ return line R1108GNHG4X | I141126JK45EC | 0 | 31.50 fee | | 0 | −31.50 | +31.50 | | | |
| 133 | I1511262RHAE5 | 830 | 125 | 8.30 | 206.70 | 186.70 | +20.00 | 155.70 | 155.70 | settled |
| 134 | I1511262RHAE5 | 1500 | 150 | 15.00 | 330.00 | 285.00 | +45.00 | 254.00 | 254.00 | pending |
| 136 | I141126JK45EC | 680 | 100 | 6.80 | 78.20 | 63.20 | +15.00 | 7.20 | 7.20 | settled |
| 138 | I141126JK45EC | 2400 | 135 | 24.00 | 561.00 | 511.00 | +50.00 | 431.00 | 431.00 | pending |
| 127 | — not in any CarryBee invoice — | | | | 582.80 | ? | | 531.80 | ? | settled |
| **Total (CarryBee orders)** | | | | | **2,372.96** | **2,618.46** | **−245.50** | | | |

Every CarryBee parcel of vendor 42 maps to one of our orders; no unknown parcels.

## 3. Root causes (code)

1. **Order 65: `orders.remaining_cod = 0`** while total = ৳844 and there is no advance. The breakdown takes
   `remaining_cod` as the collectable amount, so it thinks CarryBee collected ৳0, and vendor-owes becomes
   0 − 113.44 − 680 = −793.44. The order history doesn't show who set this.
2. **The breakdown uses the booking quote for the delivery fee, not CarryBee's final figure.**
   `OrderAccountingService::calculateOrderStakeBreakdown()` checks `delivery_assigned_info.delivery_fee` first.
   That is the quote saved when the parcel was booked (৳85/৳105). It only falls back to `orders.actual_courier_fee`,
   which the webhook updates, when there's no quote. The real CarryBee charge was ৳100–150, so vendor-owes is
   overstated by ৳15–50 on 11 orders (৳355 total).
3. **Delivery accounting runs once and then locks.** `processOrderDelivery()` returns early if a
   `delivery_completed` ledger row exists. So when the CarryBee "paid" webhook later brings the invoice, its fees
   never reach the settlements. `orders.actual_courier_fee` is still wrong on 7 orders (136, 133, 117, 120, 119, 90, 80/78).
4. **Return charges are ignored.** Returned orders are set to 0, but CarryBee charged the vendor's account the
   return delivery fee plus a ৳31.50 "returned to merchant" fee (orders 70 and 126 together: ৳223.50). The dropshipper's
   ৳130 return deposit on order 126 is still `pending` (never collected).
5. **Dropshipper profit is floored at 0.** On orders 65 and 80 the dropshipper sold at admin price + delivery,
   so the 1% COD fee makes the real profit negative (−৳8.44, −৳12.31). Clamping it to 0 means the platform
   silently absorbs it.
6. **Dropshipper overpaid on order 90 (৳247.69) and 78 (৳9.20).** Both are already settled. Order 90 had customer
   delivery ৳70 and a rejected ৳130 advance; the paid ৳403.29 doesn't match any formula.
7. **Order 127** is marked Delivered with no consignment and is in no CarryBee invoice. Its vendor settlement
   (৳582.80) assumes a courier payout that CarryBee never made — check how it was shipped.
8. The vendor invoice is built from these per-order estimates, and the manual +৳373.04 adjustment hides the gap
   instead of fixing it.

## 4. Sync design: CarryBee invoice = source of truth per order

**A. Store CarryBee's invoice lines.** New table `carrybee_invoice_lines`: account key, invoice_id, invoiced_date,
consignment_id, merchant_order_id, order_id, collected, delivery_fee, cod_fee, total_fee, delivery_status, synced_at.
Match by consignment_id. Return lines (`R…`) match by merchant_order_id to the original order.

**B. Reconcile each order** (`CarrybeeReconcileService::reconcileOrder`):
- copy CarryBee's collected / delivery fee / COD fee onto the order (`remaining_cod` for delivered parcels,
  `actual_courier_fee`, `actual_cod_fee`, `courier_cost_confirmed = true`, new `carrybee_reconciled_at`);
- recompute the breakdown; in the breakdown the order of priority becomes:
  CarryBee invoice line → confirmed actual fees → booking quote;
- **unsettled** settlement rows (supplier, dropshipper profit, platform margins) are rewritten to the new figures;
- **settled / paid** rows are never changed. Instead a `settlement_discrepancies` row is written
  (order, party, recorded, correct, diff, reason). "Skip what we already did wrong" means it is only shown, not
  recovered, until you choose to net it off a future payout.

**C. Returns** (own-account vendors): add the return delivery fee and the return-to-merchant fee as a credit to the
vendor on the supplier settlement. Recover it from the dropshipper's ৳130 deposit when one was collected.

**D. Dropshipper advances** (paid before CarryBee paid): the advance payout stays as it is; once the invoice line
arrives, any difference becomes a discrepancy row against that dropshipper (shown on the next payout screen).

**E. Vendor invoices:** only orders with `carrybee_reconciled_at` can go on a vendor invoice. The invoice shows
"CarryBee paid vendor ৳X − supplier price ৳Y = owed ৳Z" next to the per-order total and refuses to save if they differ.

**F. Triggers:** the CarryBee "paid" webhook reconciles that invoice's orders; a daily `carrybee:reconcile` command
picks up anything missed; and a "Sync with CarryBee" button on Admin → Delivery → CarryBee Invoices does it on demand.

## 4b. Built (2026-11-22, uncommitted)

Decisions taken: the return charge gets a **penalty flag with a payer** (vendor / admin / dropshipper); **dropshipper
payouts never go below 0**; **payouts already made are flagged** (overpaid / underpaid), never changed; a **corrected
vendor invoice** replaces the wrong one.

- Tables: `carrybee_invoice_lines`, `order_return_penalties`, `payout_flags`, plus `orders.carrybee_reconciled_at`
  (migration `2026_11_22_000007`).
- `CarrybeeReconcileService`: syncs an account, reconciles orders, sets the penalty payer, resolves flags and
  reissues a vendor invoice. `calculateOrderStakeBreakdown()` now reads CarryBee's line first and applies the penalty rule.
- API: `GET/POST /delivery-companies/carrybee/reconcile/{platform|vendor-ID}[/sync]`,
  `POST …/reconcile/orders/{id}/penalty`, `POST …/reconcile/flags/{id}/resolve`, `POST /order-settlements/vendor-invoices/{id}/reissue`.
- Runs automatically after each CarryBee "paid" webhook and daily (`carrybee:reconcile`, 04:30).
- Admin page: CarryBee Invoices → an account → **Match payouts with CarryBee**.

Vendor 42 after deploy: sync `vendor-42`, then pick the payer for orders 70 (৳87) and 126 (৳136.50), then
**Replace with corrected invoice** on INV-V42-20261120-836. That gives ৳2,618.46 if admin or dropshipper pays both,
or ৳2,841.96 if the vendor does. Flags expected: order 90 (+৳247.69) and order 78 (+৳9.20).

## 5. Decisions needed

1. Return charges on an own-account vendor's CarryBee account: credit them to the vendor (platform/dropshipper pays),
   or leave them as the vendor's cost? This changes vendor 42's figure: ৳2,618.46 (credited) vs ৳2,841.96 (vendor's cost).
2. Negative dropshipper profit (orders 65, 80): absorb as platform cost, or carry as a deduction on the next payout?
3. Overpaid dropshippers (order 90 ৳247.69, order 78 ৳9.20): only record them, or net them off the next payout?
4. Invoice INV-V42-20261120-836 (pending, ৳2,746): reject it and re-issue at the CarryBee-matched figure once the sync is in.
