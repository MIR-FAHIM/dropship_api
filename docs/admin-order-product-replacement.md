# Admin order product replacement

Use **Admin > Orders > Order Details > Order Items > Replace Product**. The picker searches by product name, SKU, or exact product ID and shows the fulfillment vendor, stock, and admin price. The editor keeps the current quantity and selling price as defaults; review the selling price before confirming.

## Database impact

| Record | Replacement behavior |
| --- | --- |
| `order_items` | Updates the existing row, keeping `id` and `order_id`. Takes `product_id`, `product_name`, `sku`, vendor `shop_id`, `unit_price` and `admin_price` from the replacement product. Clears the old `attribute_id` unless a valid replacement variant is selected. Stores the selling price and quantity, recalculates `line_total` and `line_total_reseller_profit`, and resets item status to pending. |
| `orders` | Updates `vendor_id` to match `order_items.shop_id`. Recalculates `subtotal`, `reseller_price`, `total`, `remaining_cod`, and `reseller_profit`. Updates courier ownership and clears unconfirmed courier fee/payout estimates. A ready order (3) returns to Assigned to Vendor (2). |
| `order_accounting_ledgers` | Updates existing expected COD and booking-value projections. Does not change actual money movements, wallet entries, or advance receipts. |
| `carry_bee_order_create_forms` | Removes the stale, unsubmitted delivery draft so the new product/vendor can be assigned with a fresh form. |
| `order_status_histories` | Records the admin, old and new product IDs/SKUs, and resulting order status. |
| `order_item_change_logs` | Appends one row per changed item for each successful admin edit. Stores the authenticated admin ID, action, timestamp, and full before/after item snapshots, including the final allocated profit. |

The order number, customer, shipping fee, discount, recorded payment and advance are preserved by the replacement dialog. The recorded advance is not deducted from customer COD. Product substitution does not change the original landing-page submission or the catalog products themselves.

`order_items.shop_id` points to `vendors.id`, not `shops.id`. `unit_price` is the vendor price; `admin_price` is the reseller base price. A missing product `admin_price` follows the existing checkout fallback of rounding up the vendor price plus 5%.

Run `php artisan migrate` to create the audit table. No columns are added to the existing order tables. Audit records are written in the same transaction as the edit, are not exposed in the shared order-details response, and survive deletion of the referenced records. Unchanged items and rejected edits do not create records.

Standard checkout does not reserve stock, so the editor checks available product/selected variant stock without incrementing or decrementing either product. Partner API orders reserve stock and cannot use this replacement flow.

## API

Both endpoints require an authenticated, active admin or superadmin:

- `GET /api/orders/replacement-products?search=SKU-123&page=1`: paginated eligible catalog, with vendors and variants.
- `PUT /api/orders/update/{id}`: change the existing item's `product_id` in place.

```json
{
  "items": [
    {
      "id": 51,
      "expected_product_id": 100,
      "product_id": 200,
      "qty": 1,
      "attribute_id": null,
      "reseller_price": 950
    }
  ]
}
```

Send the original product ID in `expected_product_id` (or null for an unlinked item) to detect a stale editor. The server derives the replacement SKU, vendor and base prices; client values cannot override them. The existing update endpoint supports edits to existing item IDs only. Legacy multi-item orders can still edit their original items, but cannot switch products.

`GET /api/orders/details/{id}` returns `item_replacement_block_reason`: null for eligible orders, otherwise a reason. Product swaps require an order awaiting fulfillment, with no delivery assignment/consignment, recorded collection, confirmed courier cost, or settlement/invoice. Stale edits and stale courier submissions return 409; ineligible replacements return 422.
