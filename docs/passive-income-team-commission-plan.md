# Passive Income — 4-level team commission: backend plan

The rules come from the page at `/passive-income` and `/app/passive-income` (`PassiveIncomePage.jsx`).

## What the page promises

| Rule on the page | What the backend must do |
|---|---|
| No fee to join (৳০) | Joining a team never charges anything. |
| A seller code after **10 fully delivered orders** | Count a dropshipper's completed orders. At 10, they become a verified seller and get a seller code. |
| New sellers register **with your seller code** | Sign-up accepts a seller code and links the new dropshipper to the code owner (their "upline"). |
| Commission from the team's sales, **4 levels** deep | When a team member's order completes, pay level 1 (direct upline), level 2, level 3 and level 4. |
| Rate depends on the product's **admin price** range | Look up a fixed taka amount by admin price range and level (table below). |
| Only for eligible orders; amounts are not guaranteed | Commission only on delivered and paid orders. Take it back on a return or cancel. |

### Rate table (seed data, editable by admin)

| Admin price | Level 1 | Level 2 | Level 3 | Level 4 |
|---|---|---|---|---|
| ৳2,501 – ৳10,000 | 10 | 6 | 4 | 4 |
| ৳1,501 – ৳2,500 | 9 | 5 | 4 | 3 |
| ৳1,001 – ৳1,500 | 8 | 4 | 3 | 2 |
| ৳801 – ৳1,000 | 7 | 4 | 3 | 2 |
| ৳501 – ৳800 | 6 | 3 | 2 | 2 |
| ৳301 – ৳500 | 4 | 2 | 2 | 2 |
| ৳201 – ৳300 | 3 | 2 | 2 | 1 |
| ৳101 – ৳200 | 2 | 2 | 1 | 1 |
| ৳0 – ৳100 | 1 | 1 | 1 | 1 |

The page gives no rate above ৳10,000, so it is decision D3.

## What already exists

- `users.referred_by` (int) and `users.referral_code` exist but nothing uses them. They can be reused.
- Dropshipper sign-up: `UserController::dropshipperRegister` (`POST /dropshipper-register`).
- `order_items.admin_price` is stored per unit at order time, with `qty`.
- **Hook points:**
  - `OrderAccountingService::processOrderDelivery` runs once, when the order is delivered and CarryBee has paid.
  - `processOrderReversal` runs on cancel, return or lost.
  - Marketer commission already plugs in at both points (`MarketerCommissionService::earnForOrder` / `reverseForOrder`). Team commission follows the same pattern: earn, reverse, clawback, payout.
- Payouts go through `settlement_invoices`. A dropshipper payout invoice already exists (`add_dropshipper_payout_to_settlement_invoices`).
- The admin money board reads `order_settlements`, so team commission must be written there to count against admin income.

## Tasks

### T1. Data (migrations + models)
1. `users` gets these columns:
   - `seller_code` (string 16, unique, nullable)
   - `seller_verified_at` (timestamp)
   - `referred_at` (timestamp)

   Reuse `referred_by` as "upline user id".
2. `team_commission_rates`: `id, min_price, max_price, level (1–4), amount, is_active, version, timestamps`. Seed it from the table above as version 1.
3. `team_commissions`, one row per order item, per level:
   - **Who and which order:** `order_id, order_item_id, earner_user_id (upline), source_user_id (seller who sold), level`
   - **Amounts:** `admin_price, qty, rate_amount, amount, rate_version`
   - **State:** `status (earned | reversed | clawback | invoiced | paid)`, `settlement_invoice_id`, `reversal_of_id`, `note`, `timestamps`
   - Unique key `(order_item_id, level, status='earned')` so it can't be earned twice. In practice, a unique `(order_item_id, level, reversal_of_id)`.
4. `OrderSettlement::TYPE_TEAM_COMMISSION`: one row per order with the total, `user_type = 'dropshipper'`. This lets the money board and final income subtract it.

### T2. Seller code (verification after 10 delivered orders)
- `TeamCommissionService::checkVerification(User $u)` is called from `processOrderDelivery`.
  - It counts the user's orders that are delivered and paid (same definition as the "completed orders" count used for the 130 deposit rule).
  - When the count reaches 10, it sets `seller_verified_at` and creates the `seller_code`, e.g. `RS-` plus 6 letters/digits, with no confusable characters.
  - It notifies the user in Bangla: "অভিনন্দন! আপনি ভেরিফাইড সেলার…".
- An admin backfill command `team:verify-sellers` gives codes to existing dropshippers who already have 10 or more.
- Admin can also give or take back a code by hand. Every such change is logged.

### T3. Joining a team (link to upline)
- `dropshipperRegister` accepts an optional `seller_code`:
  - It looks up a verified, active (not banned) owner and sets `referred_by` and `referred_at`.
  - A bad code returns a clear 422.
  - Sign-up still works without a code.
- `GET /team/code-check/{code}` returns the upline's name only, so the form can show "আপনি X-এর টিমে যোগ দিচ্ছেন".
- **Safety:**
  - No self-referral: same user, or same phone or NID as the upline.
  - No cycles: walk up to 4 levels and reject if the new user is already in the chain.
  - The upline can't be changed by the user later. Only admin can change it (decision D4), and every change goes into an audit log.

### T4. Earning (on delivery + paid)
`TeamCommissionService::earnForOrder(Order)` is called in `processOrderDelivery` right after marketer commission:
1. The seller is the order's dropshipper. Walk `referred_by` up to 4 levels.
2. Skip an upline who is banned, deleted or not a verified seller (decision D2). Skipping a level does **not** move the next upline up to fill it.
3. For each delivered order item:
   - `rate = rates(admin_price, level)`
   - `amount = rate × qty`
   - Create a `team_commissions` row with `rate_version`.
4. Write one `OrderSettlement` of `TYPE_TEAM_COMMISSION` with the total.
5. Idempotent: running it twice creates nothing new.
6. Partial delivery: only items whose status is delivered earn.

### T5. Reversal (cancel / return / lost after delivery)
In `processOrderReversal`, same rule as marketer commission:
- An `earned` line that is not on a payout yet becomes `reversed`.
- A line already invoiced or paid gets a negative `clawback` line, which comes off the earner's next payout.
- **No negative dropshipper payouts** (existing rule): if a clawback is bigger than the next payout, the rest carries forward and is flagged in `payout_flags`.

### T6. Payout
- Team commission is added to the dropshipper's normal payout invoice as its own section, "টিম কমিশন". The alternative is a separate invoice type `team_commission` (decision D5).
- Invoice creation picks all `earned` lines plus unsettled `clawback` lines and marks them `invoiced`. Paying the invoice marks them `paid`; cancelling it puts them back to `earned`.
- Wording on screens and in the API: "Admin will give to dropshipper". Never "owe".

### T7. Dropshipper APIs (`api_user` only, never a request `user_id`)
- `GET /team/summary`:
  - verified status, orders done out of 10, seller code, share link
  - team size per level (1–4)
  - commission this month, total, waiting for payout, paid
- `GET /team/members?level=1..4`: name (masked phone), joined date, delivered orders this month, commission they brought. Deeper levels show only limited info (privacy).
- `GET /team/commissions?status=&month=`: lines with order id, level, product, amount, status.
- `GET /team/rates`: the active table, so the UI table comes from the backend instead of being hard-coded.

### T8. Admin APIs
- `GET/PUT /admin/team/rates`: edit the table. Saving creates a new `version`; old lines keep their version.
- `GET /admin/team/tree/{userId}`: upline chain plus downline per level.
- `PUT /admin/team/users/{id}/upline`: change it with a reason. Includes cycle and self checks, plus an audit log.
- `GET /admin/team/commissions`: filters for user, order, status and month, plus totals.
- `POST /admin/team/sellers/{id}/verify|unverify`.
- Money board:
  - Add a "Team commission — Admin will give to dropshippers" line.
  - Admin final income = existing − team commission earned (net of reversals).

### T9. Tests (`Http::fake` not needed; pure DB)
- Each of the following gets a test:
  - Code issued exactly at the 10th delivered and paid order, not at 9.
  - Sign-up with a code links the upline; a bad code returns 422; self and cycle are refused.
  - A 4-level chain earns the right taka per range and level, multiplied by qty.
  - A skipped upline (banned or not verified) gets nothing, and the level is not shifted.
  - Running delivery twice gives no duplicates.
  - Return before payout → reversed; return after payout → clawback, and no negative payout.
  - A rate change doesn't alter old lines.
  - The money board subtracts team commission.
  - Users can't see another user's team or commission.

### T10. Rollout
1. Run the migrations and seed rates.
2. Run `team:verify-sellers --dry-run`, review it, then run it for real.
3. Turn earning on behind `config('team.enabled')`, so it can be switched off without a deploy.
4. Frontend:
   - the sign-up seller-code field
   - a "আমার টিম" page in the panel
   - the passive-income page reads `/team/rates`

## Decisions (answered 2026-11-26)

D1 per unit · D2 only verified sellers receive · D3 top row above ৳10,000 · D4 upline set only at sign-up or by admin ·
D5 inside the normal dropshipper payout invoice · D6 both marketer and team commission apply · D7 yes, cap at the order's platform margin.

**Phase 1 (now):** the referral system only: seller code at 10 delivered orders, sign-up with a code, 4-level team view,
admin tools (verify, change upline, audit log). **Phase 2 (later):** rates table, earning, reversal, payout, money board (T4–T6, T8 rates/commissions).

## Decisions needed before building (original questions)

- **D1. Per unit or per item?** The plan uses commission per **unit** (rate × qty). The page doesn't say whether 3 pieces of the same product earn 3× or 1×.
- **D2. Must the upline be a verified seller to receive?** The plan says yes. Alternative: anyone with a code earns, and only getting a code needs 10 orders.
- **D3. Products above ৳10,000.** Use the top row, or no commission?
- **D4. Late linking.** Can a dropshipper who signed up without a code add one later, for example within 7 days and before their first order, or only via admin?
- **D5. Payout channel.** Inside the normal dropshipper payout invoice, or a separate team-commission invoice?
- **D6. Marketer clients.** If the order is from a marketer's client, do both marketer commission and team commission apply?
- **D7. Who pays.** Commission comes out of the platform margin. Does it need a cap, e.g. never more than the order's platform product margin, so admin can't lose money on a cheap item? The highest total is ৳24 per unit at ৳2,501+.
