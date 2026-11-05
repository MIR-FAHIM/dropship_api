# Cashflow Report — Flow Audit & Report Plan

Planning doc only — nothing here is built yet. Covers two things: the flow
issues found while auditing the current settlement code, and the plan for the
three stakeholder cashflow reports. Read `docs/payment-flow.md` first; this
doc assumes that one.

> You said "four report plans" but named three (admin / dropshipper /
> vendor). This doc plans those three. If a fourth was intended (e.g. a
> CarryBee/delivery-company reconciliation report, or a consolidated
> company P&L), say so and I'll add a Plan D.

## The core accounting rule for all three reports

**Earning ≠ cash leaving ResellerBrain.** A dropshipper's profit becomes
"theirs" when an order is delivered and settled, but the actual money stays
inside ResellerBrain until they submit a withdrawal and it's transferred. The
same logic applies to vendors and their product-price settlement. Until that
transfer happens, **it is a liability on ResellerBrain's books, not platform
profit** — the opposite of the platform's own commission/margin, which is
realized the moment the order is delivered (nobody needs to "withdraw" the
platform's own earnings; it never left).

So every stakeholder's money passes through three states:

1. **Projected** — order placed/in transit. Profit/entitlement is an
   estimate; no settlement record exists yet.
2. **Settled (liability)** — order delivered, `order_settlements` row
   created/confirmed. ResellerBrain now owes this amount. It sits in the
   stakeholder's balance, unwithdrawn.
3. **Paid out** — stakeholder withdraws (reseller) or an invoice is paid
   (vendor). The liability clears; cash actually leaves ResellerBrain.

Every report below is this same three-state model, just sliced per audience.

## Flow issues found

### 1. Vendor settlement direction is inverted for flow A (already known)

Documented in `payment-flow.md`'s "Known inconsistency" section —
`processOrderDelivery()`'s vendor settlement computes "platform owes vendor a
shortage" when it should compute "vendor owes platform a surplus" for the
vendor-owned-courier case. Repeating it here because **it directly corrupts
any liability total Plan A/C would compute from `order_settlements`** until
fixed.

### 2. A dead but live double-payment path

`POST /settle/reseller-profit/{id}` (`OrderController::settleResellerProfit`)
is a **separate, older mechanism** that credits a `ResellerTransaction` for
`order.reseller_profit` directly, independent of `order_settlements` and
`disburseDropshipperProfitDirectly()`. Its only guard is "no debit
`Transaction` already exists for this order" — it has **no idea the newer
settlement system exists**, so if both paths are ever triggered for the same
order, the reseller is paid twice.

It isn't called from any frontend file today (checked — zero references), so
it's currently unreachable through normal use. But it's a live, callable
route. **Recommend removing it**, or rewriting it to go through
`disburseDropshipperProfitDirectly()` so there's one path, one guard.

### 3. `order_accounting_ledgers` is write-only

`OrderAccountingLedger` is a proper double-entry ledger (account codes:
`cash_platform`, `courier_clearing`, `vendor_payable`, `reseller_payable`,
`platform_margin_product`, `platform_margin_shipping`,
`order_revenue_clearing`) populated on booking and delivery. **Nothing reads
or sums it** — every balance calculation today uses `ResellerTransaction` /
`order_settlements` instead. It's only ever queried for an idempotency check
and for reversal.

This matters for the report plan: do we build the cashflow reports on this
ledger (most correct shape, a real general ledger) or keep using the simpler
`order_settlements` + `ResellerTransaction` pair (already partly wired, but
thinner)? **Needs a decision before Plan A is built**, since Plan A's
"cash-in-hand" number is the kind of thing a real ledger answers and an ad-hoc
sum doesn't.

### 4. The ledger's vendor entry is wrong for multi-vendor orders

Whichever way #3 is decided, this needs fixing first if the ledger is used:
the `vendor_payable` ledger entry in `processOrderDelivery()` credits the
**entire** combined vendor entitlement to `order.vendor_id` alone —
```php
'party_id' => $lockedOrder->vendor_id,
'credit' => $totalVendorEntitlement, // summed across ALL vendors on the order
```
— even though the method's own `$vendorEntitlements` grouping (used correctly
for `order_settlements`) proves an order can have items from multiple
vendors. The settlement rows are correct per-vendor; the ledger entry is not.

### 5. No vendor wallet — vendors are paid a fundamentally different way

Resellers have a running ledger (`reseller_transactions`) +
`withdraw_requests` + `calculateUserBalances()`, so "what's my available
balance right now" is a live query. **Vendors have no equivalent.** They're
paid through admin-created `SettlementInvoice` batches
(`createVendorSettlementInvoice()`) — there's no `vendor_transactions` table,
no running balance, no self-serve withdrawal.

`withdraw_requests.user_id` is generic enough that a vendor *could* create a
row there, but `calculateUserBalances()` only sums `ResellerTransaction`, so
a vendor's balance would always compute as ৳0 and block them. The withdraw
flow is reseller-only in practice despite the generic schema.

**This is the central decision for Plan C** (see below) — build vendors a
real wallet, or report off invoices with no self-serve withdrawal.

### 6. Liability only exists from delivery onward — make sure the UI says so

`orders.reseller_profit` is populated from checkout onward (an estimate), but
no actual liability exists until `processOrderDelivery()` creates the
`order_settlements` row. A report that shows "my profit" without
distinguishing *projected* (order placed, not delivered) from *settled*
(confirmed liability) from *paid* (withdrawn) will overstate what a
dropshipper or vendor can actually expect right now.

### 7. No stakeholder-type column on `withdraw_requests`

Low-priority unless #5 results in a vendor wallet: `withdraw_requests` has no
column distinguishing a reseller's withdrawal from a vendor's. If vendor
withdrawals get built, add a `payable_type` (`'dropshipper' | 'vendor'`)
column rather than relying on `user.user_type` lookups everywhere.

## Report plans

### Plan A — Admin Panel Cashflow Report

**Audience:** admin. **Purpose:** the platform's full cash position —
what's been earned, what's owed to whom, what's actually sitting in
ResellerBrain's own accounts.

| Section | Data source |
|---|---|
| Platform's realized earnings (commission + shipping margin), today/week/month/total | `order_settlements` where `settlement_type` in (`TYPE_COMPANY_PRODUCT_COMMISSION`, `TYPE_COMPANY_LOGISTIC_EARNING`) |
| Total owed to dropshippers (liability, unwithdrawn) | aggregate of `reseller_transactions` (credit − debit), same shape as `calculateUserBalances()` but company-wide, not per-user |
| Total owed to vendors (liability, unsettled) | `order_settlements` where `settlement_type = TYPE_SUPPLIER_PRODUCT_PRICE` and `status != SETTLED`, sum `net_payable_amount` |
| Projected vs settled vs paid, each stakeholder | split by order delivery status + `order_settlements.status` |
| Day-wise earnings/liability growth | same pattern as the orders-dashboard daily/growth report already built |
| Cash-in-hand estimate | needs issue #3 resolved first — this is the one section that genuinely needs the ledger (or an equivalent) to be trustworthy |

### Plan B — Dropshipper Panel Cashflow Report

**Audience:** the reseller themselves. **Purpose:** "what can I withdraw
right now, what's coming, what have I already been paid."

| Section | Data source |
|---|---|
| Available balance (withdrawable now) | `calculateUserBalances($userId)` — already exists |
| Pending/reserved (in an open withdraw request) | same method's `pending_reserved` |
| Projected profit (orders placed, not yet delivered) | sum of `orders.reseller_profit` for the user's orders not yet delivered — labelled clearly as *not yet confirmed* |
| Per-order breakdown | `orders` joined to `order_settlements` (`TYPE_RESELLER_PROFIT`) for this user |
| Day-wise earnings growth | `reseller_transactions` grouped by day, same chart pattern as the admin dashboard |
| Withdrawal history | `withdraw_requests` for this user |

This one needs the least new plumbing — `calculateUserBalances()` and
`reseller_transactions` already exist; it's mostly a reporting/UI job once
issue #2 is cleaned up so the numbers can be trusted.

### Plan C — Vendor Panel Cashflow Report

**Audience:** the vendor themselves. **Depends on deciding issue #5 first.**

- **If we build a vendor wallet** (new `vendor_transactions` table +
  `calculateVendorBalances()` + vendor withdraw requests): mirrors Plan B
  exactly — available balance, pending, projected, per-order breakdown, day-
  wise growth, withdrawal history.
- **If we keep the invoice-based model**: the report is read-only — pending
  settlements grouped by vendor (`order_settlements`,
  `TYPE_SUPPLIER_PRODUCT_PRICE`, not settled), paid invoice history
  (`settlement_invoices`), and the same projected/settled/paid breakdown,
  just driven by invoices instead of a withdraw button.

## What needs to change before building any of this

1. Fix issue #1 (vendor settlement direction) — otherwise Plan A/C inherit a
   wrong number for every vendor-owned-courier order.
2. Decide and resolve issue #3/#4 (ledger vs settlement-table as the source
   of truth for aggregation) before Plan A's cash-in-hand section.
3. Remove or fix issue #2 (`settleResellerProfit`) before trusting any
   dropshipper liability total.
4. Decide issue #5 (vendor wallet vs invoice-only) — this determines whether
   Plan C is a new feature (wallet + withdraw) or a report on existing data.
5. If a vendor wallet is built, add the `payable_type` column from issue #7.

None of this is implemented in this change — this doc is the shared
understanding to build from next.
