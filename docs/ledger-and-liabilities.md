# Ledger & Liabilities — Table Map, Reconciliation, and Manual-Entry Runbook

Read `docs/payment-flow.md` and `docs/cashflow-report-plan.md` first; this doc
assumes both. Those two explain the *stakeholder* story (who gets what) and
the *report* plan (Plan A/B/C). This doc answers a different, more
operational question — **as the admin, how do I prove the books are right,
and what do I do when they aren't?**

It exists to answer three things you asked for directly:

1. If the ledger is missing a historical entry, how do I add it — what are
   the exact steps, which tables does it touch, and what does each step
   actually change?
2. The company ledger should be 100/100. What does "balanced" mean here,
   precisely, and how do I check it?
3. Which table drives which other table, for money specifically — so a
   number that looks wrong can be traced back to its source instead of
   guessed at.

The last section is the loophole list — gaps found while writing this doc,
not yet fixed, that make the three answers above harder than they should be.

---

## 1. The 100/100 rule

**Per order**, once it's delivered, this identity must hold exactly:

```
vendor's product price   (settlement_type = supplier_product_price)
+ dropshipper's profit   (settlement_type = reseller_profit)
+ platform's product cut (settlement_type = company_product_commission)
+ platform's shipping cut(settlement_type = company_logistic_earning)
+ CarryBee's delivery fee + COD fee  (never stored as a settlement — it's
  cut at source, so it's the "missing" piece that makes the equation close)
= order.total   (the full COD amount the customer paid)
```

This is exactly what `OrderAccountingService::calculateOrderStakeBreakdown()`
already checks per order (look for `is_balanced` / the difference field in
its return value) — if that's false for an order, stop there, don't go
further up the chain; the per-order math itself is broken before any
reconciliation question even applies.

**Company-wide**, the same identity should hold in aggregate across every
delivered order:

```
Total COD collected across all delivered orders
= Total paid out to vendors (ever)
+ Total paid out to dropshippers (ever)
+ Total still owed to vendors (not yet paid)
+ Total still owed to dropshippers (not yet paid)
+ Total realized as ResellerBrain's own earnings
+ Total CarryBee kept as delivery/COD fees
```

Rearranged into the three numbers you actually care about as admin:

- **Money coming IN** (traceable): the sum of `orders.courier_net_payout`
  for every order where CarryBee has *actually* confirmed payment
  (`carrybee_paid_at IS NOT NULL` — never check the `is_carrybee_paid`
  accessor in a query, see §4). This is cash that has genuinely landed in a
  ResellerBrain-controlled account.
- **Money going OUT** (traceable): the sum of
  `reseller_transactions` debit rows of `type = withdraw` with
  `status = completed` (money actually wired to a dropshipper), **plus** the
  sum of `order_settlements.net_payable_amount` where
  `settlement_type = supplier_product_price` and `status = SETTLED` (money
  claimed as paid to a vendor via a settlement invoice).
- **ResellerBrain's own profit in hand**: `getCashflowOverview()`
  (`OrderAccountingService::getCashflowOverview()`, backing Plan A /
  `/admin-panel/cashflow`) already computes this as
  `platform_earnings.realized` — the sum of `company_logistic_earning` +
  `company_product_commission` settlement rows. That number is *realized
  earning*, not yet "cash sitting in a bank account" — see the loophole on
  the vendor-invoice side in §5, because not every realized-earning order
  has a correspondingly provable cash movement behind it.

**What's missing today**: nothing currently computes and displays the
company-wide identity above as a single pass/fail number ("in" minus "out"
minus "still owed" should equal zero, always). `getCashflowOverview()`
computes the pieces but never sums them into one reconciliation check. That
is the single most valuable thing to add next if you want a literal "100/100"
indicator on screen — see §5, loophole L1.

---

## 2. The table map — what writes what, and why it affects what else

Every table below is listed with: what it represents, its money-relevant
columns, what writes to it (the trigger), and what it feeds into downstream.
Read it top-to-bottom — it follows the order's actual lifecycle.

### `orders` — the order itself
- **Money columns**: `subtotal`, `shipping_fee`, `discount`, `total`,
  `reseller_profit`, `advance_amount`, `advance_status`, `courier_account_owner`,
  `actual_courier_fee`, `actual_cod_fee`, `actual_courier_cost`,
  `courier_net_payout`, `courier_payout_status`, `carrybee_invoice_id`,
  `carrybee_paid_at`, `carrybee_disbursed_to`, `payment_status`.
- **Written by**: checkout (creates the row), `OrderController::updateOrder`
  (admin edits total/shipping/discount — now re-derives `reseller_profit`
  from the breakdown, not a free-standing field), `CarrybeeWebhookService`
  (every webhook event syncs `actual_*`, `carrybee_*`, `payment_status`),
  `OrderAccountingService::processOrderDelivery` (sets `courier_payout_status`).
- **Feeds**: `calculateOrderStakeBreakdown()` — the single live-math source
  every panel, report, and settlement creation reads from. Every other
  table below either *produces* a row from this table's state (settlements,
  ledger) or *confirms* a figure this table claims (webhook events,
  delivery_assigned_info).

### `order_items` — per-product cost lines
- **Money columns**: `unit_price` (vendor's cost), `admin_price` (platform
  wholesale price shown to resellers), `reseller_price` (what the
  dropshipper sells it for), `qty`, `line_total`, `line_total_reseller_profit`.
- **Written by**: checkout; `OrderController::updateOrder` recomputes
  `line_total_reseller_profit` proportionally when the order total changes
  (`allocateResellerProfitToItems` — last item absorbs rounding).
- **Feeds**: summed into `orders.subtotal`/`reseller_profit`; grouped by
  `vendor_id` inside `processOrderDelivery` to build one settlement row
  per vendor on multi-vendor orders.

### `order_settlements` — the stakeholder ledger (the real source of truth)
This is the table every liability/earnings number in Plan A ultimately comes
from. One order can have multiple rows: one per `settlement_type`, and one
per vendor if the order has items from more than one vendor.
- **Money columns**: `settlement_type` (`supplier_product_price` /
  `reseller_profit` / `company_logistic_earning` / `company_product_commission`),
  `gross_entitlement`, `deducted_by_courier`, `advance_deduction`,
  `settleable_amount`, `net_payable_amount`, `direction`
  (`platform_owes_payee` / `payee_owes_platform`), `status`
  (`PENDING` / `SETTLED` / `CANCELLED`).
- **Written by**: `processOrderDelivery` (creates all rows at delivery
  confirmation — see §3, stage 3), `saveBreakdownAdjustments` (adjusts
  `supplier_product_price`/`reseller_profit` rows when an admin edits a
  live, undelivered order), `disburseDropshipperProfitDirectly` (flips a
  `reseller_profit` row to `SETTLED`), vendor-invoice actions (flip
  `supplier_product_price` rows to `SETTLED`), `processOrderReversal`
  (flips rows to `CANCELLED` with a reversing amount, never deletes).
- **Feeds**: `getCashflowOverview()`'s entire liabilities/receivables/
  earnings breakdown reads *only* this table. If a number in Plan A looks
  wrong, this is always the first table to check — not `orders`, not the
  ledger.
- **If this table is wrong, everything downstream of it is wrong**, because
  nothing else re-derives these amounts independently.

### `order_accounting_ledgers` — the double-entry ledger
- **Money columns**: `entry_type` (`booking` / `delivery_completed` /
  `courier_payout` / `reversal`), `account_code` (`cash_platform` /
  `courier_clearing` / `vendor_payable` / `reseller_payable` /
  `platform_margin_product` / `platform_margin_shipping` /
  `order_revenue_clearing`), `party_type`, `party_id`, `debit`, `credit`.
- **Written by**: `processOrderBooking` (booking-time entries),
  `processOrderDelivery` (`delivery_completed` + `courier_payout` entries),
  `processOrderReversal` (reversal rows, debit/credit flipped, not deleted).
- **Feeds**: nothing today (see §5, loophole L2) — this is the formal
  double-entry audit trail, built to let you prove debits = credits for any
  order, but no report currently reads or sums it.

### `reseller_transactions` — a dropshipper's personal wallet statement
- **Money columns**: `amount`, `trx_type` (`credit`/`debit`), `type`
  (`order_settlement` / `withdraw` / `order_advance` / `advance_refund` /
  `wallet_topup`), `status`, `trx_id`, `ref_id`.
- **Written by**: `disburseDropshipperProfitDirectly` (credit, on settle),
  `transferWithdrawal` (debit, on actual payout), the advance flow
  (`payAdvanceFromWallet`, `approveOrderAdvance`, `refundOrderAdvance`),
  `approveWalletTopup`.
- **Feeds**: `calculateUserBalances()` — the *per-reseller* "how much can
  this one person withdraw right now" figure shown in the dropshipper panel.
  This is a **separate computation** from `getCashflowOverview()`'s
  company-wide "owed to dropshippers" figure (which reads `order_settlements`
  instead) — see §5, loophole L3, for why these two numbers are not
  provably the same number computed two ways.

### `transactions` — the company's own high-level cashbook
- **Money columns**: `type` (`platform_order_margin` / `wallet_topup` /
  `reseller_withdraw` / legacy `order_status`), `trx_type`, `amount`,
  `status`.
- **Written by**: `processOrderDelivery` (credits `platform_order_margin`),
  `approveWalletTopup`, `transferWithdrawal` (debits `reseller_withdraw`).
- **Feeds**: nothing structurally — it's descriptive, used for an admin to
  eyeball recent activity. **It is not a complete cashbook** — vendor
  settlement-invoice payouts never write a row here at all (see §5,
  loophole L4), so "sum this table" never equals "all money that left
  ResellerBrain."

### `withdraw_requests` — dropshipper payout requests
- **Money columns**: `amount`, `reserved_amount`, `status`
  (`pending`/`transferred`/`rejected`), `bank_reference`, `transferred_at`.
- **Written by**: `reserveWithdrawal`, `transferWithdrawal`, `rejectWithdrawal`,
  and the auto-created row inside `disburseDropshipperProfitDirectly`'s
  non-wallet branch.
- **Feeds**: the "money going OUT" dropshipper side of §1. No `payable_type`
  column exists, so if a vendor wallet/withdrawal flow is ever built (Plan C
  blocker), this table can't tell a vendor payout from a reseller payout —
  it would need that column first.

### `settlement_invoices` — vendor payout/collection batches
- **Money columns**: `total_amount_owed` (commented in the migration as
  *"Vendor owes platform"*), `total_supplier_price`, `total_dropshipper_payout`,
  `total_platform_net_profit`, `status` (`pending`/`settled`/`cancelled`),
  `order_ids` (JSON array), `trx_id`, `paid_by`/`approved_by`, `settled_at`.
- **Written by**: `createVendorSettlementInvoice` (batches a vendor's
  deliverable orders into one invoice), `submitVendorInvoicePayment` /
  `approveVendorInvoice` (marks it settled and flips the linked
  `order_settlements` rows to `SETTLED`).
- **Feeds**: the linked `order_settlements` rows only. **It does not write
  to `order_accounting_ledgers` or `transactions`** — a vendor payout is the
  least traceable money movement in the whole system (see §5, loophole L4).
- **Important nuance**: `total_amount_owed` is only ever populated from
  `supplier_owes_platform` (non-zero only when the vendor owns the CarryBee
  account). When the platform owns the courier account instead — the more
  common case — `total_amount_owed` sits at 0 and the real payable figure is
  `total_supplier_price`. Nothing in the record itself says which column is
  "live" for a given invoice; you have to know the vendor's
  `has_own_delivery_account` flag to read this table correctly (see §5,
  loophole L5 — this is the same ambiguity that was fixed on
  `order_settlements.direction`, but not here).

### `delivery_assigned_info` — CarryBee consignment state per order
- **Money columns**: `consignment_id`, `collectable_amount`, `delivery_fee`,
  `total_fee`, `transfer_status_id`, `carrybee_invoice_id`, `carrybee_paid_at`.
- **Written by**: `CarrybeeWebhookService` on every event; `manualOrderPayment`.
- **Feeds**: `calculateOrderStakeBreakdown()`'s courier-fee resolution (this
  is its first-priority source, before falling back to `orders.actual_*` or
  an estimate).

### `carrybee_webhook_events` — raw webhook audit log
- **Money columns**: `event`, `consignment_id`, `invoice_id`,
  `disbursed_to`, `disbursed_amount`, `processing_status`, `payload`.
- **Written by**: every inbound CarryBee webhook, unconditionally, before
  any processing happens.
- **Feeds**: nothing computational — but this is the single most trustworthy
  table for tracing "what did CarryBee actually tell us, and when," since
  it's a raw append-only log, not a derived figure. **Start here** when
  investigating any dispute about whether a payment was really confirmed.

### `wallet_topups` — dropshipper wallet recharge requests
- **Money columns**: `amount`, `status`, `trx_id`, `approved_by`.
- **Written by**: reseller request; `approveWalletTopup` on approval.
- **Feeds**: on approval, credits both `reseller_transactions` and
  `transactions` in the same action — the one flow in this system where
  those two tables are written together and so can't drift apart from each
  other (by construction, not by reconciliation).

### `vendors.has_own_delivery_account` — not a ledger, but the switch
Not a money table, but every one of the tables above behaves differently
depending on this one boolean. Any investigation into "why doesn't this
order's numbers look like that order's" should check this first.

---

## 3. The money-flow journey, stage by stage

This is the "picture" version of the table map — the order it actually
happens in, and which tables get touched at each step.

| Stage | Trigger | Tables written | What it does |
|---|---|---|---|
| **1. Order placed** | Customer checkout | `orders`, `order_items` | Establishes the claim: who owes what to whom, nothing settled yet. |
| **2. Booking** | Order created / advance taken | `order_accounting_ledgers` (`booking` entries) | Opens the double-entry trail. No settlement exists yet — this is a provisional entry only. |
| **3. Delivery confirmed** | CarryBee `order.paid` webhook (consignment orders) or manual status update (non-consignment) | `order_settlements` (4 rows created), `order_accounting_ledgers` (`delivery_completed` + `courier_payout`), `transactions` (`platform_order_margin`), `orders` (`courier_payout_status`) | The moment a provisional number becomes a real, owed liability. Before this, every figure shown anywhere is a *projection*, not a fact — this is why the vendor-panel fix earlier in this session mattered: nothing before this stage should ever claim money "has been" paid. |
| **4. Dropshipper disbursed** | Admin action (`disburseDropshipperProfitDirectly`) | `reseller_transactions` (credit), `order_settlements` (reseller row → `SETTLED`) | Moves the reseller's profit from "owed" to "available in their wallet." Money has not left ResellerBrain yet — it's just no longer contested. |
| **5. Dropshipper withdraws** | Reseller requests + admin transfers | `withdraw_requests`, `reseller_transactions` (debit) | The only stage where money actually leaves ResellerBrain for a dropshipper. This is the true "money OUT" event for §1. |
| **6. Vendor settled** | Admin batches + approves a `settlement_invoice` | `settlement_invoices`, `order_settlements` (supplier row → `SETTLED`) | The true "money OUT" event for a vendor — but **no ledger or `transactions` row is written here** (loophole L4). The only record that money moved is the invoice row itself and the vendor's own claimed `trx_id`. |
| **7. Vendor owes platform** (only when vendor owns the CarryBee account and collects more than their product price) | `processOrderDelivery` detects `direction = payee_owes_platform` | `order_settlements` row created with that direction | **No further stage exists.** There is currently no action, endpoint, or table that marks this kind of receivable as *collected* — it is created and then nothing ever closes it (loophole L1/L6, below). |

---

## 4. Manual-entry runbook — if the ledger is missing a historical entry

Follow this order. Never hand-write SQL for these tables directly — always
go through the existing service methods, because they're what keep
`direction`, `status`, and the cross-table relationships consistent. A raw
`INSERT` is exactly how the vendor-direction bug and the multi-vendor ledger
bug happened earlier in this project's history.

1. **Identify which table is actually missing the entry**, in this order of
   likelihood:
   - Is the *settlement itself* missing (`order_settlements` has no row, or
     the wrong amount, for this order/stakeholder)? This is the common case
     and the one that matters most, since every report reads from here.
   - Is the settlement correct, but the *real-world payout* was never
     recorded (money was actually wired, but no `reseller_transactions` /
     `withdraw_requests` / `settlement_invoices` row says so)?
   - Is everything else correct, and only the `order_accounting_ledgers`
     audit trail is missing? (Lowest priority — nothing reads it yet, but
     still worth backfilling for completeness.)

2. **If the settlement row is missing or wrong**: re-run
   `OrderAccountingService::calculateOrderStakeBreakdown($order)` for that
   order first and compare it against what's in `order_settlements`. If the
   order was already delivered and settlements were never created (e.g. a
   webhook was missed historically), call `processOrderDelivery($order)`
   directly rather than inserting rows by hand — it already knows how to
   create all four settlement types with the correct `direction` and
   per-vendor split.

3. **If the settlement exists but the real payout wasn't recorded**:
   - Dropshipper side: call `disburseDropshipperProfitDirectly($order)` if
     it hasn't been settled yet, or — if it *was* settled but the wallet
     credit/withdrawal never happened — manually create the
     `reseller_transactions` credit row (`type = order_settlement`) and, if
     money already left the building, a matching debit row (`type =
     withdraw`) plus a `withdraw_requests` row with `status = transferred`
     and the real bank reference. Never silently re-run disbursement on an
     order that's already `SETTLED` — the guard in
     `disburseDropshipperProfitDirectly` will reject it (by design, to stop
     double-payment); that guard is correct, don't bypass it.
   - Vendor side: use `createVendorSettlementInvoice` /
     `approveVendorInvoice` / `submitVendorInvoicePayment` rather than
     inserting into `settlement_invoices` directly — the `order_ids` JSON
     and the linked `order_settlements` rows need to stay in lock-step, and
     the service methods are what guarantee that.

4. **If only the `order_accounting_ledgers` trail is missing**: insert a row
   using the *same* `account_code`/`entry_type` conventions already used by
   `processOrderDelivery` for that stage (see §2), and write a description
   that says plainly it's a backfill — e.g. "Manually backfilled on
   2026-11-05 for a gap found during audit; real settlement occurred on
   [date]." Never backdate it to look like it was created automatically.

5. **To correct a mistake, never edit or delete an existing row.** Add a
   reversing entry (same pattern as `processOrderReversal` — flip debit/
   credit, or flip the settlement to `CANCELLED` with a note), then add the
   new, correct entry. This is the only way the history stays provably
   complete — "no payment should be traceless" means the trail of *what we
   believed and when*, not just the final number.

6. **After any manual entry**, re-check the per-order 100/100 identity in
   §1 for that order specifically before moving on. If it doesn't balance,
   the backfill itself is wrong — stop and recheck before touching any
   other order.

---

## 5. Loopholes found while writing this doc

Carried over from `docs/cashflow-report-plan.md` (already fixed, listed
here only for completeness): the vendor settlement direction bug, the
multi-vendor settlement loop bug, the double-disbursement gap, and the
MySQL-only `CONCAT()` portability bug. All four are ✅ fixed.

**Still open**, in order of how much they threaten the 100/100 guarantee:

- **L1 — No company-wide reconciliation check exists.**
  `getCashflowOverview()` computes "in," "out," and "earnings" as separate
  numbers but never sums them into one pass/fail identity (§1). Today,
  proving the books balance requires manually comparing several numbers on
  the Plan A screen; there's no single indicator that says "yes, every
  taka is accounted for" or flags the order where it isn't.

- **L2 — `order_accounting_ledgers` is write-only.** Every delivery and
  reversal writes to it correctly, but nothing reads it back. In practice
  this means the formal double-entry ledger — the one table built
  specifically to prove debits equal credits — currently proves nothing,
  because nobody's checking it. (Previously flagged as issue #3 in
  `cashflow-report-plan.md`; restated here because it's the most direct
  blocker to "my ledger should be 100/100.")

- **L3 — Two different formulas can answer "what does ResellerBrain owe
  this dropshipper," and they aren't provably the same.**
  `calculateUserBalances()` (per-reseller, used in the dropshipper panel)
  sums `reseller_transactions` credit/debit rows. `getCashflowOverview()`
  (company-wide, Plan A) sums `order_settlements.net_payable_amount` where
  `status = PENDING`/`SETTLED`. These should always agree in the normal
  flow (disbursement always creates a matching pair), but there is no
  constraint or test today that proves they can never drift — e.g. a
  manual settlement correction (§4) that updates `order_settlements` but
  forgets the matching `reseller_transactions` row would silently create a
  gap between "what the dropshipper sees they can withdraw" and "what the
  company's books say it owes."

- **L4 — Vendor payouts are the least traceable money movement in the
  system.** Settling a `settlement_invoices` row flips the linked
  `order_settlements` rows to `SETTLED`, full stop. No
  `order_accounting_ledgers` row, no `transactions` row. Every other
  outbound payment (reseller withdrawal, wallet topup) leaves at least one
  of those two trails; vendor payouts leave neither. If you ever need to
  answer "prove every taka paid to vendors is accounted for," today the
  only evidence is the invoice table itself plus whatever `trx_id` the
  vendor typed in.

- **L5 — `settlement_invoices.total_amount_owed` is directionally
  ambiguous**, exactly the same shape of bug that was found and fixed on
  `order_settlements.direction` earlier this project — except here it was
  never fixed. The column is only meaningful when
  `vendors.has_own_delivery_account = true`; otherwise it's always 0 and
  the real payable number lives in a *different* column
  (`total_supplier_price`) with no field on the row itself saying which one
  applies. Reading this table correctly requires knowing a fact that isn't
  stored on the table.

- **L6 — Vendor receivables have no collection step.** When a vendor owns
  the CarryBee account and collects more than their product price, stage 7
  in §3 creates an `order_settlements` row with
  `direction = payee_owes_platform` — and that's the end of the line. There
  is no action, button, or endpoint anywhere that marks this kind of row as
  "collected from the vendor." Unlike every other liability in this system,
  which has a clear settle/disburse action that closes it out, this one
  can only ever accumulate. Over time, `getCashflowOverview()`'s
  "receivables" figure would only ever grow, never shrink — which, if
  you've ever actually collected from a vendor in real life, means the
  number on screen is already wrong today.

None of these six require touching the order-delivery flow itself — they're
all about the *reporting and closing* side (reconciliation, collection,
audit-trail completeness), which is exactly why they went unnoticed while
the delivery-time math was being fixed earlier. Worth tackling in roughly
this order: **L1 and L6 first** (both are pure gaps — nothing to break by
adding them), then **L5** (a data-model fix, same shape as the
`order_settlements.direction` migration), then **L4** (requires deciding
whether vendor payouts should start writing ledger/transactions rows, which
changes behavior, not just adds a report), and **L2/L3** last (these are
either reporting-only or require a cross-check job, lower urgency than the
ones above).
