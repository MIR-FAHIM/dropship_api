# Grouped Order History API

## Schema Findings

- `orders.id -> order_items.order_id`; one order can contain multiple products, vendors, and variant rows.
- Checkout, landing-page checkout, settlements, and `OrderItem::shop()` treat `order_items.shop_id` as **`vendors.id`**. It is not an ID from the separate `shops` table.
- `vendors.user_id -> users.id` identifies the authenticated vendor. Vendor history uses this ownership lookup and the order item's historical fulfillment ID, never a client-supplied vendor ID or the order header's `vendor_id`.
- `products.vendor_id -> vendors.id` is current product ownership. It is not used to transfer access to old orders when a product changes owner.
- Shop profiles are linked through `shops.user_id = vendors.user_id`. They are returned as `shop_profiles`, independently of fulfillment shop IDs. Multiple profiles do not multiply order items or totals.
- If a vendor has multiple `shops` profiles, the existing order snapshot does not record which profile handled a sale. Vendor grouping therefore uses the actual fulfillment `shop_id` (vendor ID); profile-level attribution cannot be reconstructed without a future checkout/schema change.
- Order-time `unit_price` is the vendor cost, `admin_price` is the base price, and `reseller_price` is the customer selling price. Checkout writes `line_total = qty * reseller_price`.
- Product sales use stored `order_items.line_total`, not current catalog prices or entire order totals. Prices and product names in individual history rows are order-time snapshots.
- Header shipping fees and discounts are not added to product sales. There is no persisted product-level allocation of header discounts, so this API does not invent one. `discount_allocation: "not_available"` makes this explicit.
- This is order history, not recognized revenue: all order statuses are included unless filtered. Use the appropriate completed status ID for a completed-order sales report.

## Authentication And Access

All endpoints require `Authorization: Bearer <API_TOKEN>` and use the existing `ApiTokenAuth` middleware.

The project does not have separate admin/vendor authorization middleware. New route-local `OrderHistoryAccess` checks the authenticated user's `role` or `user_type`:

- Admin: `admin`, `superadmin`.
- Vendor: `vendor`, `seller`, plus an existing vendor profile linked to the authenticated user.
- Missing/invalid token: 401. Wrong panel role or missing vendor profile: 403.
- A supplied `vendor_id` is rejected with 422. It can never choose the authorized vendor.
- A vendor's `shop_id` must equal their fulfillment vendor ID; a foreign or unknown ID is rejected with 422 before reporting queries run.

## Endpoints

| Method | URL | Result |
| --- | --- | --- |
| GET | `/api/admin/orders/history` | Paginated product groups across all vendors. |
| GET | `/api/vendor/orders/history` | Paginated product + fulfillment-shop groups for the authenticated vendor. |
| GET | `/api/admin/orders/history/orders` | Paginated orders for a selected product, with matching items only. |
| GET | `/api/vendor/orders/history/orders` | Same expansion, restricted to the authenticated vendor's matching items. |

Main endpoints return `data.groups`, a Laravel length-aware paginator. Each group has aggregate totals and a `history_url` for expansion.

When `product_id` (or `unlinked_product=1`) is provided to a main endpoint, it also returns `data.orders`, independently paginated using `history_page` and `history_per_page`.

Expansion endpoints require either `product_id` or `unlinked_product=1` and return `data.orders`. They use ordinary `page` / `per_page` pagination. This separate endpoint avoids a query per group or unbounded embedded history.

### Query Parameters

| Parameter | Validation / Meaning |
| --- | --- |
| `product_id` | Positive integer, exists in `products`. Required on expansion unless `unlinked_product=1`. |
| `shop_id` | Positive integer, exists in **`vendors`**; must be the authenticated vendor on vendor endpoints. |
| `date_from` | Optional inclusive order creation date, `YYYY-MM-DD`. |
| `date_to` | Optional inclusive order creation date, `YYYY-MM-DD`, not before `date_from`. Can be used without `date_from`. |
| `status` | Existing positive `order_statuses.id`. Filters header status, not item status. |
| `payment_status` | `unpaid`, `paid`, `failed`, `refunded`, `pending`, or `partially_paid`. |
| `page` | Positive integer, max 1,000,000. Default 1. Group page on main endpoints; order page on expansion endpoints. |
| `per_page` | Integer 1-100. Default 20. Group size on main endpoints; order size on expansion endpoints. |
| `history_page` | Positive integer, max 1,000,000. Default 1. Embedded orders only. |
| `history_per_page` | Integer 1-100. Default 20. Embedded orders only. |
| `unlinked_product` | Optional accepted flag, normally `1`. Mutually exclusive with `product_id`. Reports items whose product reference is null. |

Date filters operate on `orders.created_at` in the database's stored timezone. Groups sort by latest matching order date, then product ID. Orders sort by date descending, then order ID descending.

Deleted product foreign keys are set to null by the existing schema. Their name/price snapshots remain accessible in the unlinked-product bucket. Multiple deleted products necessarily share that bucket; their original product IDs cannot be reconstructed once the foreign keys are cleared.

## Requests

```http
GET /api/admin/orders/history?per_page=20&page=1
Authorization: Bearer <ADMIN_TOKEN>
Accept: application/json
```

```http
GET /api/admin/orders/history?product_id=40&date_from=2026-10-01&date_to=2026-10-25&payment_status=paid&history_per_page=20&history_page=1
Authorization: Bearer <ADMIN_TOKEN>
```

```http
GET /api/vendor/orders/history?product_id=40&shop_id=3
Authorization: Bearer <VENDOR_TOKEN>
```

```http
GET /api/vendor/orders/history/orders?product_id=40&shop_id=3&per_page=10&page=2
Authorization: Bearer <VENDOR_TOKEN>
```

## Responses

Main group response, abbreviated pagination metadata:

```json
{
  "status": "success",
  "message": "Order history fetched successfully",
  "data": {
    "grouped_by": ["product_id", "shop_id"],
    "product_sales_basis": "stored_line_total_before_order_discount_and_shipping",
    "discount_allocation": "not_available",
    "groups": {
      "current_page": 1,
      "per_page": 20,
      "total": 1,
      "last_page": 1,
      "data": [
        {
          "product_id": 40,
          "product_name": "Drill",
          "product": {"id": 40, "name": "Drill", "sku": "DRILL", "slug": "drill"},
          "totals": {
            "order_count": 2,
            "item_count": 3,
            "total_quantity": 5,
            "total_product_sales": 675
          },
          "latest_order_at": "2026-10-25 00:00:00",
          "shops": [
            {
              "id": 3,
              "name": "First Store",
              "vendor": {"id": 3, "user_id": 12, "shop_name": "First Store"},
              "shop_profiles": [{"id": 8, "user_id": 12, "name": "First Profile", "slug": "first-profile", "address": null}]
            }
          ],
          "shop_id": 3,
          "shop": {
            "id": 3,
            "name": "First Store",
            "vendor": {"id": 3, "user_id": 12, "shop_name": "First Store"},
            "shop_profiles": [{"id": 8, "user_id": 12, "name": "First Profile", "slug": "first-profile", "address": null}]
          },
          "history_url": "https://your-api.example/api/vendor/orders/history/orders?product_id=40&shop_id=3"
        }
      ]
    }
  }
}
```

An unfiltered main request omits `orders`. A main request with `product_id` includes the paginator below alongside `groups`. An expansion request returns this paginator without `groups`:

```json
{
  "status": "success",
  "message": "Order history fetched successfully",
  "data": {
    "grouped_by": ["product_id", "shop_id"],
    "product_sales_basis": "stored_line_total_before_order_discount_and_shipping",
    "discount_allocation": "not_available",
    "orders": {
      "current_page": 1,
      "per_page": 20,
      "total": 2,
      "last_page": 1,
      "data": [
        {
          "order_id": 101,
          "order_number": "ORD-20261025-ABC123",
          "order_date": "2026-10-25 00:00:00",
          "status": "9",
          "order_status": {"id": 9, "name": "Completed", "type": "completed"},
          "payment_status": "paid",
          "quantity": 2,
          "subtotal": 200,
          "items": [
            {
              "id": 501,
              "order_id": 101,
              "product_id": 40,
              "shop_id": 3,
              "product_name": "Drill",
              "sku": "DRILL",
              "attribute_id": null,
              "unit_price": 80,
              "admin_price": 85,
              "reseller_price": 100,
              "qty": 2,
              "line_total": 200,
              "status": "delivered",
              "shop": {
                "id": 3,
                "name": "First Store",
                "vendor": {"id": 3, "user_id": 12, "shop_name": "First Store"},
                "shop_profiles": []
              }
            }
          ]
        }
      ]
    }
  }
}
```

The order example illustrates one entry; all matching entries appear through pagination. Paginators also include standard Laravel URLs, links, `from`, and `to`. Status raw values preserve database storage; `order_status` is null for an unresolved/legacy status. Missing product/shop metadata is null or absent from `shops`; stored item snapshots are retained.

Totals are computed across all matching rows, before pagination. `order_count` uses distinct order IDs within the group. Repeated variant rows contribute quantity and value once per item, but a matching order appears once in the expanded history. Admin product groups span all fulfillment vendors. Vendor expansion contains no other vendors' items or whole-order financial totals.

Empty matching results return 200 with empty paginator data and `total: 0`, not 404. Invalid input returns the existing `{ "status": "failed", "message": "Validation failed", "errors": { ... } }` shape with 422.

## Implementation And Deployment

Changed / added files:

- `routes/api.php`: four authenticated history routes.
- `app/Http/Middleware/OrderHistoryAccess.php`: panel authorization and authenticated vendor resolution.
- `app/Http/Controllers/OrderHistoryController.php`: query validation and consistent response envelopes.
- `app/Service/OrderHistoryService.php`: item-scoped grouped aggregates, batched metadata, order expansion.
- `database/migrations/2026_10_25_000002_add_order_history_indexes.php`: additive product/order, vendor/product/order, order-date, and status/date indexes; no new tables or columns.
- `tests/Feature/OrderHistoryTest.php`: access, ownership, grouping, pricing, pagination, filters, deleted-product, and bounded-query tests.
- This documentation.

Run `php artisan migrate` during deployment. This creates only reporting indexes; it does not change order creation, settlement, pricing, or accounting behavior. Large production tables may require a maintenance window for index creation.

## Verification

Run the focused suite:

```sh
php artisan test --filter=OrderHistoryTest
php artisan route:list --path=orders/history
```

The feature suite covers admin access across vendors, unauthorized access, vendor identity spoofing, foreign shop rejection, mixed-vendor items, product ownership changes, multiple shop profiles, stored prices, repeated variants, header discount/shipping exclusion, group/order pagination, inclusive date/status/payment filters, missing products, and query counts that do not grow per group.

Tests use the configured isolated in-memory SQLite database. Live MySQL and production data are not changed or used by these tests.

Results for this implementation:

- Focused history tests: **14 passed, 146 assertions**.
- Full suite: **104 passed, 2 failed, 547 assertions**. The failures are existing `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` and `Tests\Feature\WhatsAppServiceTest::test_unbanning_dropshipper_dispatches_whatsapp_job`: both send unauthenticated requests and expect 200 but receive 401 from the existing token middleware. Their routes, tests, and authentication behavior were not modified by this feature.
- PHP syntax checks passed for the controller, middleware, service, migration, routes, and tests.
- Laravel Pint passed for all five new PHP files; `git diff --check` passed.
- All four endpoints were confirmed with `artisan route:list`.

The new migration has not been applied to the application's configured database. Deploy it after reviewing the additive indexes and the normal pending-migration list.
