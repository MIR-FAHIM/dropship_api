# Payment / Settlement Flow

How money moves on a delivered order, between the four stakeholders, and where
each number lives in the code. This is a reference doc, not a feature yet — no
report or UI reads from this file; it exists so the future cashflow report can
be built on a shared, written-down understanding instead of each person's
memory of the code.

## The four stakeholders

| # | Stakeholder | Gets |
|---|---|---|
| 1 | **ResellerBrain** (platform) | Its commission on the product (`admin_price − vendor_price`, 5% by default) + its shipping margin (`shipping_fee − actual_courier_fee`) |
| 2 | **Vendor** (supplier) | His product cost (`unit_price`, aka "vendor price") |
| 3 | **Reseller / Dropshipper** | His profit (`reseller_price − admin_price`, net of shipping/COD fee — see note below) |
| 4 | **Delivery company** (CarryBee) | His delivery fee + COD handling fee, cut automatically at source before anyone else is paid |

## Worked example — order total ৳1,500

The customer pays **৳1,500 COD**, collected in full by CarryBee. That ৳1,500
already contains the delivery charge and CarryBee's 1% COD fee — nothing is
added on top.

| Item | Amount |
|---|---|
| Product selling price (subtotal, `reseller_price`) | ৳1,350 |
| Customer shipping charge (`shipping_fee`) | ৳150 |
| **Customer COD total (`total` / `remaining_cod`)** | **৳1,500** |
| CarryBee's actual delivery fee | ৳120 |
| CarryBee's COD fee (1% of 1,500) | ৳15 |
| **CarryBee's total cut** | **৳135** |
| **Net CarryBee disburses (to vendor or platform's account)** | **৳1,365** |
| Vendor's product cost (`unit_price`) | ৳1,000 |
| Platform's price to reseller (`admin_price`, = vendor price × 1.05) | ৳1,050 |
| → Platform's product commission (`admin_price − unit_price`) | ৳50 |
| → Platform's shipping margin (`shipping_fee − courier fee`) | ৳15 |
| → **Reseller's profit** | **৳300** |

Final split, either way the order is delivered: **Vendor ৳1,000 · ResellerBrain
৳65 · Reseller ৳300 · CarryBee ৳135 = ৳1,500.**

## Estimate vs actual delivery charge — a second, easy-to-miss profit source

**No `consignment_id` means no real logistics has happened yet.** Until
CarryBee actually creates a consignment for the order, nothing has shipped —
every delivery/COD figure in the system up to that point is a *planning
estimate*, not a real cost:

- `orders.shipping_fee` is the delivery charge **charged to the customer** at
  order time (an estimate the admin/system set when pricing the order).
- The 1% COD fee used in `calculateOrderStakeBreakdown()` before a consignment
  exists is also just an estimate (`collectable_amount × 1%`), not a real
  CarryBee charge.

Once the consignment is created and CarryBee's webhook reports what it
*actually* charged (`orders.actual_courier_fee`, `orders.actual_cod_fee`), the
real cost is usually **lower** than what was estimated and charged to the
customer. That gap is extra ResellerBrain profit — on top of the product
commission and the shipping margin already counted above.

**Example:** the system estimates/collects ৳135 worth of delivery + COD fee
from the order total (this is the amount deducted from the dropshipper's
profit calculation). CarryBee's consignment report later shows the *real*
delivery charge was only ৳105. **ResellerBrain keeps the extra ৳30** — it is
never paid back to the dropshipper, the vendor, or CarryBee.

Why the dropshipper doesn't get that ৳30 back: `reseller_profit` is deducted
by the full `shipping_fee` that was **charged to the customer** — not by the
actual CarryBee delivery fee — in both `calculateOrderStakeBreakdown()` and
`processOrderDelivery()`. Only the **COD-fee** portion of the deduction gets
reconciled to the real, confirmed amount once it's known
(`$actualCodFee` in `processOrderDelivery()`); the **delivery-fee** portion of
the margin (`shipping_fee − actual_courier_fee`) is, by design, always
ResellerBrain's — it's the same `platform_shipping_margin` row from the table
above, just worth calling out on its own since it's easy to assume delivery
charges are a zero-margin pass-through cost. They aren't.

Practical implication: before a consignment exists, the live breakdown shown
to admins necessarily *assumes* delivery cost ≈ charged `shipping_fee` (no
margin yet, since no real figure exists) — the true margin only appears once
the consignment is created and CarryBee confirms its actual charge.

## The two delivery flows

Which one applies is `vendors.has_own_delivery_account` (copied onto the order
as `orders.courier_account_owner`, `'vendor'` or `'platform'`).

### A. Vendor has their own CarryBee account (`has_own_delivery_account = true`)

CarryBee's account used for this order belongs to the **vendor**, so the money
lands with the vendor first.

1. CarryBee collects ৳1,500 COD from the customer.
2. CarryBee deducts its ৳135 cut, deposits the **net ৳1,365 into the vendor's
   own CarryBee-linked account**.
3. Vendor withdraws it, keeps his ৳1,000 product cost, and remits the
   **remaining ৳365 to ResellerBrain**.
4. ResellerBrain keeps its ৳65 (commission + shipping margin) and disburses
   the **remaining ৳300 to the reseller/dropshipper** as profit.

Money direction: **vendor owes platform** the surplus above his product cost.

### B. ResellerBrain controls the CarryBee account (`has_own_delivery_account = false`)

The CarryBee account used for this order belongs to **ResellerBrain**, so the
money lands with the platform directly.

1. CarryBee collects ৳1,500 COD from the customer.
2. CarryBee deducts its ৳135 cut, deposits the **net ৳1,365 directly into
   ResellerBrain's CarryBee account**.
3. ResellerBrain clears every liability out of that ৳1,365 itself: pays the
   vendor his ৳1,000, pays the reseller his ৳300 profit, and keeps the
   remaining ৳65 as its own earning.

Money direction: **platform owes vendor** his product cost (nothing to
collect from the vendor — the cash already sits with the platform).

## Where this lives in code today

| Concept | Field / method |
|---|---|
| Customer COD total | `orders.total`, `orders.remaining_cod` |
| Customer shipping charge | `orders.shipping_fee` |
| Vendor's product cost ("vendor price") | `order_items.unit_price` |
| Platform's price to reseller | `order_items.admin_price` (default `unit_price × 1.05` — see `pricing.utils.js::getAdminBasePrice` on the frontend) |
| Reseller's selling price | `order_items.reseller_price` |
| Reseller's profit (order-level, authoritative) | `orders.reseller_profit`, cash-flow formula: `collectable − admin_base_price − shipping_fee − COD_fee`, see `OrderAccountingService::calculateOrderStakeBreakdown()` |
| Who owns the CarryBee account | `vendors.has_own_delivery_account` → `orders.courier_account_owner` |
| Whether real logistics has started | `Order::hasConsignment()` / `orders.consignment_id` — nothing before this is a real cost |
| Actual CarryBee fees (only exist after consignment + delivery webhook) | `orders.actual_courier_fee`, `orders.actual_cod_fee` |
| Net amount CarryBee disburses | computed as `courierNetPayout` in `OrderAccountingService::processOrderDelivery()` |
| Live/preview breakdown shown to admin | `OrderAccountingService::calculateOrderStakeBreakdown()` (backs `GET /order-settlements/orders/{id}/breakdown`) |
| Real settlement rows created at delivery | `OrderAccountingService::processOrderDelivery()`, writing to `order_settlements` |
| Vendor's settlement | `order_settlements.settlement_type = TYPE_SUPPLIER_PRODUCT_PRICE` |
| Reseller's settlement | `order_settlements.settlement_type = TYPE_RESELLER_PROFIT` |
| Platform's shipping margin | `order_settlements.settlement_type = TYPE_COMPANY_LOGISTIC_EARNING` |
| Platform's product commission | `order_settlements.settlement_type = TYPE_COMPANY_PRODUCT_COMMISSION` |

## ⚠️ Known inconsistency — resolve before building the cashflow report

`calculateOrderStakeBreakdown()` (the live breakdown admins see) and
`processOrderDelivery()` (the code that actually creates the vendor's
settlement row on delivery) model flow **A** in opposite directions:

- `calculateOrderStakeBreakdown()` correctly computes `supplier_owes_platform
  = courier_net_to_supplier − vendor_price` — i.e. the vendor received more
  than his cost and owes the platform the rest. **This matches the flow
  described above.**
- `processOrderDelivery()`'s vendor settlement instead computes a
  `net_payable_amount` framed as "the shortage the **platform still owes the
  vendor**" (`max(0, vendor_gross_entitlement − courier_net_payout)`) — the
  opposite direction of money flow for this case.

So today, the *real* `order_settlements` row created for a vendor-owned-courier
delivery does not reflect who-owes-whom the way the live preview (and this
doc) describes. This needs to be reconciled before the cashflow report is
built on top of `order_settlements` data, otherwise the report will inherit
the wrong direction for flow A. Not fixed here — this doc only records it as a
known gap to resolve first.
