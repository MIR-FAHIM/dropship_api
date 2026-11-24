<?php

namespace Tests\Feature;

use App\Models\CarrybeeInvoiceLine;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturnPenalty;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin dashboard money board: who owes whom per stage, and the admin's final income. */
class AdminMoneyBoardTest extends TestCase
{
    use RefreshDatabase;

    private array $admin;
    private User $drop;
    private Vendor $own;
    private Vendor $shared;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([6 => 'Delivered', 8 => 'Returned', 9 => 'Completed', 10 => 'Carrybee Paid'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'delivery', 'is_active' => true]);
        }
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->admin = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
        $this->drop = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0, 'name' => 'Jahirul']);
        $this->own = Vendor::create(['user_id' => User::factory()->create(['banned' => 0])->id, 'shop_name' => 'Bongobox', 'shop_type' => 'Supplier', 'is_active' => true, 'has_own_delivery_account' => true]);
        $this->shared = Vendor::create(['user_id' => User::factory()->create(['banned' => 0])->id, 'shop_name' => 'Shared', 'shop_type' => 'Supplier', 'is_active' => true, 'has_own_delivery_account' => false]);
    }

    /** @param array|null $cb [collected, delivery fee, cod fee] CarryBee line, null = CarryBee not paid */
    private function order(Vendor $v, int $status, float $total, float $ship, float $unit, float $admin, ?array $cb, array $settled = []): Order
    {
        static $n = 0;
        $n++;
        $o = Order::create(['order_number' => "ORD-MB{$n}", 'user_id' => $this->drop->id, 'vendor_id' => $v->id, 'customer_name' => 'C', 'customer_phone' => '017',
            'shipping_address' => 'A', 'total' => $total, 'subtotal' => $total - $ship, 'shipping_fee' => $ship, 'remaining_cod' => $total,
            'courier_account_owner' => $v->has_own_delivery_account ? 'vendor' : 'platform', 'status' => $status, 'payment_status' => $cb ? 'paid' : 'unpaid',
            'carrybee_invoice_id' => $cb ? "I{$n}" : null, 'carrybee_paid_at' => $cb ? now() : null]);
        $p = Product::create(['name' => "P{$n}", 'slug' => "p-mb{$n}", 'vendor_id' => $v->id, 'shop_id' => $v->id, 'unit_price' => $unit, 'admin_price' => $admin,
            'price' => $total - $ship, 'category_id' => 1, 'brand_id' => 1, 'status' => 1, 'is_approved' => 1]);
        OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'shop_id' => $v->id, 'unit_price' => $unit, 'admin_price' => $admin,
            'price' => $total - $ship, 'reseller_price' => $total - $ship, 'qty' => 1]);
        DeliveryAssignedInfo::create(['order_id' => $o->id, 'consignment_id' => "F{$n}", 'recipient_name' => 'C', 'recipient_phone' => '017', 'recipient_address' => 'A',
            'collectable_amount' => $total, 'delivery_fee' => $cb[1] ?? 125, 'total_fee' => ($cb[1] ?? 125) + ($cb[2] ?? round($total * 0.01, 2))]);
        if ($cb) {
            CarrybeeInvoiceLine::create(['account_key' => 'x', 'invoice_id' => "I{$n}", 'consignment_id' => "F{$n}", 'order_id' => $o->id, 'line_type' => 'delivery',
                'collected' => $cb[0], 'delivery_fee' => $cb[1], 'cod_fee' => $cb[2], 'total_fee' => $cb[1] + $cb[2], 'invoiced_date' => now()->toDateString()]);
        }
        foreach ([OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE, OrderSettlement::TYPE_RESELLER_PROFIT] as $type) {
            $amount = $settled[$type] ?? null;
            OrderSettlement::create(['order_id' => $o->id, 'settlement_type' => $type, 'vendor_id' => $type === OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE ? $v->id : null,
                'user_type' => $type === OrderSettlement::TYPE_RESELLER_PROFIT ? 'dropshipper' : 'vendor', 'gross_entitlement' => $amount ?? 0,
                'settleable_amount' => $amount ?? 0, 'net_payable_amount' => $amount ?? 0, 'trx_id' => "T{$n}{$type}",
                'status' => $amount !== null ? OrderSettlement::STATUS_SETTLED : OrderSettlement::STATUS_PENDING, 'settled_at' => $amount !== null ? now() : null]);
        }

        return $o;
    }

    public function test_board_shows_who_owes_whom_and_final_income(): void
    {
        // Settling: own account, CarryBee paid 1500 − 165 → vendor owes 285, dropshipper due 254, admin income 31.
        $this->order($this->own, 10, 1500, 130, 1050, 1101, [1500, 150, 15]);
        // Completed, platform account, dropshipper OVERPAID 403.29 (correct 155.60): final income 1340 − 133.40 − 1050 − 403.29 = −246.69.
        $this->order($this->shared, 9, 1340, 70, 1050, 1101, [1340, 120, 13.4], [OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE => 1050, OrderSettlement::TYPE_RESELLER_PROFIT => 403.29]);
        // Waiting for CarryBee: own account 844 → expected vendor owes 844 − 133.44 − 680 = 30.56, dropshipper 0 (−8.44 floored).
        $this->order($this->own, 6, 844, 130, 680, 714, null);
        // Settling on the platform account: admin owes the vendor 600 supplier price, dropshipper 1000 − 650 − 130 − 10 = 210.
        $this->order($this->shared, 10, 1000, 130, 600, 650, [1000, 100, 10]);
        // Returned on the vendor's own account, admin carries the 136.50 return charge → admin credits the vendor.
        $ret = $this->order($this->own, 8, 1000, 130, 600, 650, null);
        OrderReturnPenalty::create(['order_id' => $ret->id, 'amount' => 136.5, 'payer' => 'admin']);

        $d = $this->getJson('/api/reports/money-board', $this->admin)->assertOk()->json('data');

        $this->assertSame(1, $d['waiting_carrybee']['orders']);
        $this->assertEquals(844, $d['waiting_carrybee']['collected']);
        $this->assertEquals(30.56, $d['waiting_carrybee']['admin_income']);

        $this->assertSame(2, $d['settling']['orders']);
        $this->assertEquals(31 + 80, $d['settling']['admin_income']); // 31 + (1000 − 110 − 600 − 210)
        $this->assertSame(1, $d['completed']['orders']);
        $this->assertEquals(-246.69, $d['completed']['admin_income']);
        $this->assertEquals(-246.69, $d['completed']['income_this_month']);

        $owes = $d['owes'];
        $this->assertEquals([['Bongobox', 285.0]], array_map(fn ($r) => [$r['name'], $r['amount']], $owes['vendors_owe_admin']));
        $byName = collect($owes['admin_owes_vendors'])->pluck('amount', 'name');
        $this->assertEquals(600, $byName['Shared']);
        $this->assertEquals(136.5, $byName['Bongobox']);
        $this->assertEquals(254 + 210, $owes['admin_owes_dropshippers'][0]['amount']);
        $this->assertEquals(285, $owes['owed_to_admin']);
        $this->assertEquals(600 + 136.5 + 464, $owes['admin_owes']);
        $this->assertEquals(285 - 1200.5, $owes['net']);

        $this->assertEquals(136.5, $d['alerts']['penalties_admin_paid']);
        $this->assertSame(0, $d['alerts']['paid_not_synced']);
    }

    public function test_final_income_uses_the_paid_vendor_invoice_when_the_order_record_is_wrong(): void
    {
        // Like order #83: the vendor paid its invoice (৳748.70 for this order) but the order's
        // settlement row was left at ৳0. Final income must count what the vendor actually paid.
        $o = $this->order($this->own, 9, 2630, 130, 1750, 1838, [2630, 105, 26.3],
            [OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE => 0, OrderSettlement::TYPE_RESELLER_PROFIT => 635.7]);
        \App\Models\SettlementInvoice::create(['invoice_number' => 'INV-V41-TEST', 'vendor_id' => $this->own->id, 'type' => 'vendor_settlement',
            'status' => 'approved', 'payment_method' => 'bank', 'orders_count' => 1, 'order_ids' => [$o->id], 'total_amount_owed' => 748.7,
            'orders_breakdown' => [['order_id' => $o->id, 'supplier' => ['supplier_owes_platform' => 748.7]]]]);

        $d = $this->getJson('/api/reports/money-board?refresh=1', $this->admin)->assertOk()->json('data');

        // 748.70 the vendor gave − 635.70 paid to the dropshipper = 113, not −635.70.
        $this->assertEquals(113, $d['completed']['admin_income']);
    }

    public function test_only_order_admins(): void
    {
        $t = ApiTokenService::create($this->drop, ['basic'])['plain'];
        $this->getJson('/api/reports/money-board', ['Authorization' => "Bearer {$t}"])->assertForbidden();
    }
}
