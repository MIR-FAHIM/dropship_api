# Dropshipper Delivery Case Studies, checked against the system

Study date: 2026-11-16. Source checklist: `docs/dropshipper_delivery_invoice_case_studies.docx`.
Read with `docs/payment-flow.md` and `docs/ledger-and-liabilities.md`.

This file takes each case in the invoice-review checklist and records:

- what the checklist expects,
- what the code does today (file and method),
- any gap between the two,
- how to test it by hand.

**No code was changed for this study.** Every gap below is still open. Where a line says
*Verified*, the numbers came from running the code against a test database. Everything
else comes from reading the code.

---

## 0. Business decisions (agreed 2026-11-16)

These are the rules. Where the code differs, the code is wrong.

**D1. The dropshipper pays the 1% COD charge out of their profit. The platform's
delivery margin covers only CarryBee's delivery fee.** (Corrected 2026-11-16; an earlier
draft of this file said the opposite.)
- **Dropshipper profit** = customer total − admin price − delivery charge (130, or 70
  inside Dhaka) − 1% COD charge.
  - Example: 1,130 − 750 − 130 − 11.30 = **238.70**.
  - The checklist's 250 leaves out the COD charge.
- **Platform delivery margin** = delivery charge (130/70) − CarryBee's actual delivery
  fee. It's the platform's second margin, separate from the product margin.
  - Example: 130 − 105 = **25**, matching the checklist.
  - The COD charge is **not** taken off again here: the dropshipper has already paid it.
- **What the dropshipper sees:** the fixed 130/70 and the 1% COD charge. They never see
  CarryBee's actual delivery fee.
- **The customer** pays the order total (1,130). The checkout price doesn't change.
- The shares add up: 700 + 238.70 + 50 + 25 + CarryBee 116.30 (105 + 11.30) = 1,130 ✓.

**D2. The 130 taka advance is the dropshipper's reliability balance with the platform.**
- **Whose money:** the dropshipper deposits it, and the platform **owes it back** to
  them. It's a liability on the platform's books, never platform income, a fee or a
  penalty. The dropshipper can withdraw it later.
- **Not part of the order's money:** ResellerBrain doesn't know the dropshipper's
  customer, only the dropshipper. The customer pays the full order total to CarryBee
  (`remaining_cod` = order total) whether or not a deposit exists. The deposit never
  reduces, adds to or settles the collection, and never comes out of profit. The code
  already keeps it out of `remaining_cod`.
- **When it's asked for:** before an order goes ahead, the dropshipper should have at
  least 130 taka of reliability balance with the platform. Checkout prompts for it, but
  the order can still be placed without it ("pay later" is allowed today).
- **D2a (agreed): 130 per order, until the dropshipper has five completed orders.**
  Only completed orders count; cancelled, returned and lost ones don't. While the
  dropshipper has fewer than 5 completed orders, every new order has its own 130
  deposit. After 5 completed orders, none is asked for. Each deposit belongs to its
  order and is owed back to the dropshipper.
- *Still open (D2b):* does each deposit stay **locked until its order ends** (delivered,
  cancelled, returned or lost) and then go back to the dropshipper's withdrawable
  balance, or can it be **withdrawn at any time**? Today the answer depends on how it
  was paid (see F5 / F5b); both methods must follow the same rule.

---

## 1. The regular delivery example, through the system

Order: customer total 1,130 = products 1,000 + delivery 130. Vendor price 700, admin
price 750. CarryBee's actual fees: delivery 105, plus a COD fee. The system uses 1% of
the collectable amount (11.30) when CarryBee hasn't sent one.

| Figure | Checklist | Breakdown screen (`calculateOrderStakeBreakdown`) | Stored at delivery (`processOrderDelivery` → `order_settlements`) |
|---|---|---|---|
| Vendor entitlement | 700 | 700 | 700 (`supplier_product_price`) |
| Dropshipper profit | 1,130 − 750 − 130 = **250** | **238.70** | **238.70** (`reseller_profit`) |
| Platform product margin | 750 − 700 = 50 | 50 | 50 (`company_product_commission`) |
| Platform delivery margin | 130 − 105 = **25** | **25** | **13.70** (`company_logistic_earning`) |
| Platform net | 75 | 75 | **63.70** |
| Courier keeps | 105 (+ COD fee) | 116.30 | 116.30 |
| **Sum** | | 700 + 238.70 + 75 + 116.30 = **1,130** ✓ | 700 + 238.70 + 63.70 + 116.30 = **1,118.70** ✗ (11.30 short) |

*Verified* with the exact figures above.

**Finding F1. The COD fee is taken twice in stored settlements.**

- Dropshipper profit takes off the COD fee:
  `remaining_cod − admin price − customer shipping − COD fee`
  (`OrderAccountingService::processOrderDelivery`, around line 217).
- The platform shipping margin takes it off again:
  `customer shipping − (delivery fee + COD fee)` (around line 174).
- The breakdown screen takes it off the dropshipper only
  (`platformDeliveryMargin = shipping − delivery fee`, around line 1544). The screen and
  the stored earnings therefore disagree by one COD fee on every delivered order. The
  stored ledger (`order_accounting_ledgers`, `transactions.platform_order_margin`)
  under-counts platform income by the same amount.
- Under D1 the **breakdown screen is right** and **delivery accounting is wrong**: it
  takes the COD fee off the platform's delivery margin even though the dropshipper has
  already paid it.

**Finding F2. Dropshipper profit takes off the COD fee: correct under D1.** All six
places do it: backend checkout, breakdown and delivery accounting; frontend breakdown
helper, checkout page and quick-order box. Nothing to change there.

**The fix for F1: one line.** In `OrderAccountingService::processOrderDelivery`
(around line 174):

- Today: platform shipping margin = `customer shipping − (delivery fee + COD fee)`
- After: platform shipping margin = `customer shipping − delivery fee`

That one value feeds three stored records, and all of them become correct together:

- the `company_logistic_earning` settlement: 13.70 → 25,
- the `platform_margin_shipping` ledger credit, which also makes that order's
  `order_accounting_ledgers` entries balance. Today credits are one COD fee short of
  debits.
- `transactions.platform_order_margin`: 63.70 → 75.

Target numbers for the example, after the fix (screen and stored figures the same):

| Vendor | Dropshipper | Product margin | Delivery margin | CarryBee | Sum |
|---|---|---|---|---|---|
| 700 | 238.70 | 50 | 25 | 116.30 | 1,130 ✓ |

Notes:

- **Old delivered orders** keep the old stored margin, one COD fee too low. Only the
  platform's own income figure is affected; no dropshipper or vendor was paid wrongly.
  Correcting old rows is optional and should be a separate, deliberate step.
- **F2b (small): the COD charge on screen and in the payout can differ slightly.**
  - Checkout and the quick-order box round it to whole taka (11 on a 1,130 order). The
    backend keeps two decimals (11.30).
  - Delivery accounting uses CarryBee's *actual* COD fee once it arrives, so the
    dropshipper's final profit can move by a few paisa or taka from what they saw.
  - To keep their profit exactly as shown, use 1% of the collection amount, rounded the
    same way everywhere, instead of CarryBee's figure.

---

## 2. Status reference

Status ids: 1 Pending, 2 Assigned To Vendor, 3 Vendor Is Ready, 4 Shipped, 5 Out for
Delivery, 6 Delivered, 7 Cancelled, 8 Returned, 9 Completed, 10 Carrybee Paid,
11 Lost / Damaged, 12 Return in Transit, 13 Partially Delivered.

| Status | Money reversed? (`processOrderReversal` + profit clawback) | Breakdown zeroed? (`isCancelledOrReturned`) |
|---|---|---|
| 7 Cancelled | Yes, by manual status change and by CarryBee `order.pickup-cancelled` | Yes |
| 8 Returned | Yes, by manual change and by CarryBee `order.returned-to-merchant` | Yes |
| 11 Lost / Damaged | Yes, by **manual change only** (CarryBee has no lost event) | **No**: still shows the full profit and margin (*Verified*) |
| 12 Return in Transit | **No** (by design: reversed only when the parcel is back with the vendor) | **Yes** ("return" is in the name) |
| 13 Partially Delivered | No. Settlement is held for review | No |

**Finding F3. The breakdown decides cancel/return by matching the status *name*.**
`isCancelledOrReturned` looks for "cancel", "return", বাতিল or ফেরত in the status
name or type. As a result:

- Status 11 Lost / Damaged is treated as a normal order on the breakdown and in vendor
  invoices.
- Status 12 is zeroed on screen while its settlements are still live.
- Renaming a status in `order_statuses` silently changes the money rules.

Elsewhere the code uses fixed ids (`[7, 8, 11]` for manual reversal, `[7, 8, 11, 12]`
for blocking payouts). The two methods should agree.

---

## 3. Case by case

Format: **Checklist** → **System** → **Gap** → **Test**.

### 01 Order placed
- **Checklist:** product and delivery amounts kept separately. Profit of 250 only if
  delivery succeeds.
- **System:** checkout stores `subtotal`, `shipping_fee`, `total`, `remaining_cod` (=
  total) and per-item `unit_price` / `admin_price` / `reseller_price`. `reseller_profit`
  at this point is a projection; it's recalculated with the actual fees at delivery.
- **Gap:** none. The projection takes off the 1% COD charge, which is correct under D1.
  See F2b for rounding.
- **Test:** place an order. In admin order details, the financial stake breakdown shows
  projected figures and no `order_settlements` rows exist yet.

### 02 Reliability balance before five orders
- **Checklist:** fewer than 5 *prior orders* → at least 130 taka deposit, recorded apart
  from order proceeds and profit.
- **System:** `checkAdvanceEligibility` counts orders with status 9 (Completed) or a
  delivered/completed status type. Threshold 5, default 130. It's paid:
  - from the wallet (`payAdvanceFromWallet`: wallet debit, `advance_status = approved`
    at once), or
  - by bKash/Nagad (`submitMfsAdvance` → pending → an admin runs `approveOrderAdvance`).
- **Gaps** (updated for D2):
  - **F4. The count is correct under D2a.** `checkAdvanceEligibility` asks for a
    deposit while the dropshipper has fewer than 5 *completed* orders (status 9, or a
    status whose type is delivered/completed). That's the agreed rule: cancelled orders
    don't count.
    - Minor point: status 6 (Delivered) and 10 (Carrybee Paid) count only if their
      `order_statuses.type` is "delivered"/"completed", so check that data in
      production.
  - **F5. A bKash/Nagad deposit is withdrawable as soon as it's approved.** When an
    admin approves it, an `order_advance` credit with status `completed` goes into the
    wallet, so `calculateUserBalances` counts it as available while its order is still
    open. That's correct only if D2b is "withdraw at any time". If deposits stay locked
    until the order ends, this is wrong.
  - **F5b. A wallet-paid deposit is held per order and can be lost: wrong under D2.**
    `payAdvanceFromWallet` *debits* the wallet for every order, so with three open
    orders 390 taka is held. Delivery credits it back (`refundOrderAdvance`
    'delivered'). Cancel, return or loss never does (F7), so the platform ends up keeping
    money it owes the dropshipper.
    - D2a is per order, so the 130 must come back on **every** outcome: delivered,
      cancelled, returned and lost. Today it only comes back on delivery.
    - Holding it while the order is open is correct only if D2b is "locked until the
      order ends".
- **Test:**
  1. Use a dropshipper with fewer than 5 completed orders and pay a 130 advance by
     bKash.
  2. Approve it.
  3. Check the wallet: available balance has gone *up* by 130 before delivery.
  4. Repeat paying from the wallet: available balance goes *down* by 130.

### 03 Order assigned to supplier
- **Checklist:** assign only after the required balance and order checks.
- **System:** status 2 can be set without an approved advance; nothing in
  `OrderStatusTransitionService::checkManual` or the status update looks at
  `advance_status`.
- **Gap: F6. Not a gap under D2.** The deposit is optional, so assignment must not wait
  for it. The code already behaves this way. The checklist line "after the required
  balance" should read "after any deposit the dropshipper chose to pay".
- **Test:** for an order with `advance_status = pending`, change the status to 2. It's
  accepted.

### 04 Cancellation before consignment
- **Checklist:** proceeds, entitlements and profits all zero. Check the deposit for a
  refund request.
- **System:**
  - Status 7 → `processOrderReversal`. That only acts if delivery accounting exists,
    which it never does before a consignment, so it's effectively a no-op.
  - `clawbackResellerProfit` → nothing to take back.
  - The breakdown zeroes everything.
- **Gap: F7. A wallet-paid deposit is lost on cancel, return or loss. That's a bug
  under D2.** `refundOrderAdvance` is only called on delivery (its "cancelled" wording
  is never used). The outcome depends on how the deposit was paid:
  - **Wallet-paid:** the 130 stays debited. The platform keeps the dropshipper's money,
    with no row recording it. Under D2 it has to go back to the wallet, on cancel (7),
    return (8) and lost (11). The cleaner fix is F5b: never lock it in the first place.
  - **bKash-paid:** it's already withdrawable in the wallet (F5), which is correct.
  - Under D2 no forfeit action is needed, because the platform never keeps the deposit.
- **Test:**
  1. Pay the advance from the wallet, then cancel (7).
  2. In `reseller_transactions`, check for an `order_advance` debit and **no**
     `advance_refund`.
  3. The wallet stays 130 lower.

### 05 CarryBee consignment created
- **Checklist:** record the consignment ID and expected collection. The fee is
  provisional.
- **System:** `delivery_assigned_info` stores `consignment_id`, `collectable_amount`
  and `delivery_fee`, plus `total_fee` / `cod_fee` when CarryBee sends them. The
  webhook updates the fees until the order is settled. The breakdown uses
  `delivery_info.delivery_fee` first, then `orders.actual_courier_fee`, then the
  customer shipping charge.
- **Gap:** nothing on the order marks the fee as an estimate or as confirmed by
  CarryBee. `courier_cost_confirmed` is only set at delivery.
- **Test:** create a consignment, then send `order.updated` with a new `delivery_fee`.
  The breakdown's courier fee follows it.

### 06 Pickup and payout route
- **Checklist:** confirm whether the payout goes to the supplier's account or to
  ResellerBrain's.
- **System:** decided by `vendors.has_own_delivery_account` (and
  `orders.courier_account_owner`). CarryBee's `order.paid` webhook also sends
  `disbursed_to`, stored as `orders.carrybee_disbursed_to` and in
  `carrybee_webhook_events`.
- **Gap: F8.** The money rules use only the vendor flag. They never compare it with
  CarryBee's actual `disbursed_to`. If the two disagree (vendor flag off but CarryBee
  paid the vendor), the settlements assume the wrong destination and nothing warns the
  admin.
- **Test:** for a vendor with `has_own_delivery_account = false`, send `order.paid` with
  `disbursed_to: vendor`. Settlements still assume the platform received the money.

### 07 Successful delivery and full collection
- **Checklist:** reconcile the payout, actual fees, supplier entitlement, platform
  margin and dropshipper profit before invoicing.
- **System:**
  - Delivery (6) captures the fees. Settlement starts only on CarryBee `order.paid`
    (status 10), through `triggerDisbursementForPaidOrder` → `processOrderDelivery`.
  - That creates the four settlement rows, ledger entries and
    `transactions.platform_order_margin`, releases the advance (`refundOrderAdvance`
    'delivered'), then `checkAndMarkOrderCompleted`.
  - A delivered event where the collected amount differs from the collectable amount by
    1 taka or more holds the order for review.
  - Orders without a consignment settle on a manual status change to 6 or 9.
- **Gap:** F1 (the stored delivery margin takes off the COD fee a second time) affects
  every order of this kind.
- **Test:**
  1. Use the regular example with `delivery_fee` 105 and a COD fee.
  2. Send `order.delivered`, then `order.paid`.
  3. Compare `order_settlements` with the section 1 table.

### 08 Customer refuses, parcel returns
- **Checklist:** product, dropshipper and platform amounts are zero. Keep only the
  actual courier charge and **record who bears it**.
- **System:**
  - 12 (returning) changes no money.
  - 8 (`order.returned-to-merchant`) reverses settlements and takes back any profit
    already paid.
  - The breakdown zeroes all amounts but still shows CarryBee's `delivery_fee` (the
  COD fee is zeroed).
- **Gap: F9. The return charge is never booked.**
  - No settlement or ledger row says "CarryBee charged X for the return".
  - There's no field for who bears it (platform, dropshipper or supplier).
  - Where it lands depends on whose CarryBee account it is: the vendor's account or
    ResellerBrain's.
- **Test:**
  1. Send `order.returned` (→ 12), then `order.returned-to-merchant` (→ 8).
  2. All settlements become CANCELLED, and no row records the return charge.

### 09 Parcel lost, adjustment requested
- **Checklist:** no loss adjustment on an invoice until the loss and the responsible
  party are reviewed and approved. Record the claim, the decision and who is charged.
- **System:** status 11 is manual only. It reverses settlements and takes back profit,
  but:
  - The breakdown and vendor invoices still treat the order as normal (F3).
  - There's no table or field for a loss claim, its amount, the decision or the party
    charged.
- **Gap: F10.** There's no loss-claim workflow. A vendor invoice built with a lost order
  in it uses the full projected figures.
- **Test:**
  1. Deliver an order, then set it to 11.
  2. The breakdown still shows a profit of 238.70 (*Verified*).
  3. Add it to a vendor invoice: it counts as a normal order.

### 10 Customer pays less than the collectable amount
- **Checklist:** record the actual collected amount and the shortfall separately. Don't
  invoice the missing amount as received.
- **System:**
  - `order.partial-delivery` (→ 13) and a delivered event with a different
    `collected_amount` store `delivery_assigned_info.collected_amount` and hold the
    order for review (`needs_review`).
  - Payouts are blocked while it's held.
  - On approval (`approveCourierReview`), settlement still runs `processOrderDelivery`,
    which uses `orders.remaining_cod` (the **full** amount), not `collected_amount`.
- **Gap: F11. After approval the shortfall is settled as if the full amount had been
  collected.** It's only correct if the admin first edits the order total or breakdown
  by hand. There's no shortfall field and no "who bears the shortfall" field.
- **Test:**
  1. Send `order.delivered` with `collected_amount` 1,000 on a 1,130 order. It's held.
  2. Approve it, then send `order.paid`.
  3. The reseller and platform rows are still based on 1,130.

### 11 Returned, but the customer paid the delivery charge
- **Checklist:** record what the customer paid and CarryBee's charge separately. Product
  and profits are zero; reconcile the difference to whoever actually received it.
- **System:** CarryBee `order.paid-return` → 12 ("Returning (delivery charge
  collected)"). Later `returned-to-merchant` → 8 reverses everything.
- **Gap: F12.** The delivery charge the customer paid isn't stored as money anywhere,
  even when `collected_amount` is in the payload. Nothing reconciles it against the
  courier charge.
- **Test:** send `order.paid-return` with `collected_amount` 130. No settlement, ledger
  or transaction row holds the 130.

### 12 Returned, and the customer paid nothing
- **Checklist:** CarryBee's charge still has to be paid. Assign the penalty only after
  the responsible party is confirmed and approved.
- **System:** same as 08. No penalty record and no approval step.
- **Gap:** F9. Under D2 the deposit must **not** be used to pay this charge: it's the
  dropshipper's money. Any return penalty charged to the dropshipper has to be its own
  approved wallet debit.
- **Test:** as for 08.

### 13 Actual courier fee differs from the estimate
- **Checklist:** the 105 actual vs 130 charged gives 25 of platform margin, but only
  once collection and payout are confirmed.
- **System:** the actual fee from webhook `delivery_fee` replaces the estimate. The margin
  is stored only when settlement runs on `order.paid`, so it's "confirmed" in that
  sense.
- **Gap:** F1. The stored margin is 13.70, not 25, when there's an 11.30 COD fee.
- **Test:** as for 07.

### 14 Payout lands in the supplier's account
- **Checklist:** confirm what CarryBee paid the supplier. Match the supplier's transfer
  back to ResellerBrain before invoicing the supplier or paying the dropshipper.
- **System:**
  - With `has_own_delivery_account`, the vendor settlement is `payee_owes_platform` with
    `net = courier net − product cost`.
  - The vendor invoice's `total_amount_owed` sums `supplier_owes_platform`.
  - Dropshipper payout needs only CarryBee to have paid (`is_carrybee_paid`) and no open
    review.
- **Gaps:**
  - **F13. A dropshipper can be paid before the supplier transfers the platform's
    share.** Nothing links the dropshipper payout to the vendor invoice being settled.
    The checklist says to match the supplier's transfer first.
  - L6 in `ledger-and-liabilities.md`: a vendor receivable has no "collected" step.
- **Test:**
  1. For a vendor with their own account, settle an order (`order.paid`).
  2. Pay the dropshipper. It's allowed while the vendor invoice is still pending.

### 15 Payout lands in ResellerBrain's account
- **Checklist:** reconcile CarryBee's deposit against the consignment, then calculate
  the supplier and dropshipper amounts. Don't count a supplier transfer that never
  happened.
- **System:**
  - The vendor settlement is `platform_owes_payee` with the product cost (700).
  - `order.paid` stores `carrybee_invoice_id` and `disbursed_amount` on
    `carrybee_webhook_events`.
- **Gaps:**
  - **F14. The CarryBee deposit isn't checked against the expected net.** It never
    compares CarryBee's `disbursed_amount` with `collectable − fees`.
  - **F15. A vendor invoice for a platform-account vendor records 0 as owed.**
    `createVendorSettlementInvoice` writes
    `settleable_amount = supplier_owes_platform` into the supplier settlement. That's 0
    for a platform-account vendor, which overwrites the 700 that delivery accounting set.
    It also overwrites `deducted_by_courier` and `status`. `net_payable_amount`, which
    the cashflow report reads, stays at 700, so the row now contradicts itself. The
    invoice's own `total_amount_owed` is also 0; the real payable is only in
    `total_supplier_price` (also L5 in `ledger-and-liabilities.md`).
- **Test:**
  1. For a platform-account vendor, settle an order, then create a vendor invoice for
     it.
  2. On the `supplier_product_price` row, `settleable_amount` goes from 700 to 0 while
     `net_payable_amount` stays 700.

---

## 4. Invoice clearance checklist, mapped to the system

| Checklist item | Where to check it in the system | Enforced by code? |
|---|---|---|
| Status is delivered / cancelled / returned / lost / unresolved | `orders.status` + timeline (`order_status_histories`) | Dropshipper payout: yes (blocks 7/8/11/12 and unpaid CarryBee). **Vendor invoice: no**. Any order can be added (F16). |
| Consignment ID matches | `delivery_assigned_info.consignment_id`, `carrybee_webhook_events` | Webhook matches by consignment / merchant order id |
| Collectable, received and shortfall reconciled | `collectable_amount`, `collected_amount`, `needs_review` | Held for review, but settlement ignores `collected_amount` (F11) |
| Customer delivery charge separate from CarryBee delivery and COD fees | `orders.shipping_fee` vs `actual_courier_fee` / `actual_cod_fee` | Stored separately; the split is wrong (F1) |
| Payout destination confirmed | `has_own_delivery_account` vs `orders.carrybee_disbursed_to` | Not compared (F8) |
| Supplier transfer verified before invoicing | Vendor invoice `trx_id` / approval | Not linked to dropshipper payout (F13) |
| Loss or return penalty has an approved amount and responsible party | — | No fields (F9, F10) |
| Reliability balance reconciled apart from profit | `orders.advance_*`, `reseller_transactions` type `order_advance` / `advance_refund` | bKash deposits correct under D2; wallet-paid deposits locked and lost on cancel (F5b, F7) |
| Amounts recalculated from confirmed values | `processOrderDelivery` uses CarryBee's fees from `order.paid` | Yes, except F1 / F11 |
| Cancelled or returned: everything zero except the courier charge | Breakdown zeroes amounts | Mostly; lost isn't zeroed (F3), and the courier charge isn't booked (F9) |
| Invoice total doesn't duplicate a transfer | Dropshipper payout: guarded (one settlement credit per order). Vendor invoice: no check that an order is already on another invoice (F16) | Partly |

**F16. Vendor invoices have no order checks.** `createVendorInvoice` validates only that
the order ids exist. It doesn't check:

- the order status (it can include undelivered, cancelled or lost orders),
- that CarryBee has paid,
- that the orders belong to that vendor,
- that the order isn't already on another vendor invoice.

It's admin-only (`admin:accounts`), so it depends on the admin being careful.

---

## 5. Findings, by risk

| # | Finding | Effect | Kind |
|---|---|---|---|
| F1 | Stored delivery margin takes off the COD fee the dropshipper already paid | Platform income stored 1 COD fee too low on every delivered order; screen ≠ ledger; ledger unbalanced | Bug (one line) |
| F2b | COD charge rounded differently on screen (whole taka) and in the payout (CarryBee's actual) | Dropshipper's final profit can differ slightly from what they saw | Small |
| F11 | Short collection settled as the full amount after review | Overpays the dropshipper and vendor, overstates platform income | Bug |
| F15 | Vendor invoice sets a platform-account vendor's `settleable_amount` (and the invoice's amount owed) to 0 | Settlement row contradicts itself; the invoice shows 0 owed | Bug |
| F16 | Vendor invoice accepts any order (status, owner, already invoiced) | Double or premature vendor settlement | Missing guard |
| F5b + F7 | Wallet-paid deposit locked until delivery and never returned on cancel, return or loss | Platform keeps the dropshipper's own money with no record | Bug, rule D2 |
| F9 / F10 | No record of the return charge, loss claim or responsible party | Can't invoice returns or losses as the checklist requires | Missing feature |
| F13 | Dropshipper paid before the supplier transfers (vendor-account orders) | Platform pays out money it hasn't received | Missing guard |
| F3 | Cancel/return decided by status name; Lost not zeroed | Lost orders show and invoice full profit | Bug |
| F8 / F14 | CarryBee's `disbursed_to` / `disbursed_amount` never checked | Wrong destination or short deposit goes unnoticed | Missing check |
| F12 | Delivery charge paid on a refused parcel isn't recorded | Unreconciled money | Missing feature |
| F5 | bKash/Nagad deposit withdrawable while its order is still open; a wallet-paid one isn't | The two payment methods follow different rules | Decision D2b |

No longer gaps under D2: **F4** (counting completed orders is the agreed rule) and **F6**
(assigning without a deposit).

Still to decide:
- **D2b:** whether each order's deposit is locked until that order ends, or can be
  withdrawn at any time.
- **F9/F10:** who approves loss and return penalties.

---

## 6. Running these cases as tests

These cases aren't automated yet. To make them repeatable, add one feature test per
case in `tests/Feature/DeliveryCaseStudiesTest.php`:

- Build the section 1 order (`Order` + one `OrderItem`: unit 700, admin 750, reseller
  1,000; shipping 130; total 1,130).
- Drive it through CarryBee webhooks the way `CarrybeeStatusJourneyTest` does, or
  through `PATCH`/`POST` status changes.
- Assert the `order_settlements`, `reseller_transactions` and breakdown values above.
- Seed `order_statuses` 1–10 by name, so `isCancelledOrReturned` behaves as it does in
  production. The test database only has 11–13 from the 2026-11-11 migration.

Running tests on this machine: `php artisan test` fails while `.env` has
`FIREBASE_CREDENTIALS` with backslashes inside double quotes. Copy `.env` to
`.env.testing` with forward slashes, run `php vendor/bin/phpunit`, then delete
`.env.testing`.
