<?php

namespace Tests\Feature;

use App\Models\CarrybeeInvoiceLine;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturnPenalty;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\PayoutFlag;
use App\Models\Product;
use App\Models\SettlementInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCarryBeeCredintial;
use App\Service\ApiTokenService;
use App\Service\CarrybeeReconcileService;
use App\Service\OrderAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rebuilds the real cases found in the vendor 42 (Bongobox) audit, 2026-11-22
 * (docs/audit-vendor42-carrybee-2026-11-22.md), and checks that syncing with CarryBee fixes them.
 */
class CarrybeeReconcileTest extends TestCase
{
    use RefreshDatabase;

    private array $admin;
    private Vendor $vendor;
    private User $dropshipper;
    private string $account;
    private array $o = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([6 => 'Delivered', 8 => 'Returned', 10 => 'Carrybee Paid'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'delivery', 'is_active' => true]);
        }
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->admin = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
        $this->dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        $vendorUser = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create(['user_id' => $vendorUser->id, 'shop_name' => 'Bongobox', 'shop_type' => 'Supplier', 'is_active' => true, 'has_own_delivery_account' => true]);
        VendorCarryBeeCredintial::create(['vendor_id' => $this->vendor->id, 'client_id' => 'vid', 'client_secret' => 'vs', 'client_context' => 'vc', 'is_active' => true]);
        $this->account = "vendor-{$this->vendor->id}";

        // [order, total, ship, remaining_cod, unit, admin, status, quote delivery fee, quote total fee, consignment]
        $this->o[65] = $this->order('ORD-65', 844, 130, 0, 680, 714, 10, 105, 113.44, 'F65');       // remaining_cod wrongly 0
        $this->o[134] = $this->order('ORD-134', 1500, 130, 1500, 1050, 1101, 10, 105, 120, 'F134');  // quote fee 105, CarryBee 150
        $this->o[90] = $this->order('ORD-90', 1340, 70, 1340, 1050, 1101, 10, 85, 98.4, 'F90');      // dropshipper overpaid
        $this->o[126] = $this->order('ORD-126', 1000, 130, 0, 600, 650, 8, 105, 105, 'F126');        // returned

        $this->settle($this->o[134], 260, OrderSettlement::STATUS_PENDING);   // correct 254
        $this->settle($this->o[90], 403.29, OrderSettlement::STATUS_SETTLED); // correct 155.60 → overpaid 247.69
        $this->settle($this->o[65], 0, OrderSettlement::STATUS_PENDING);
        foreach ($this->o as $order) {
            OrderSettlement::create(['order_id' => $order->id, 'vendor_id' => $this->vendor->id, 'payable_user_id' => $vendorUser->id, 'user_type' => 'vendor',
                'settlement_type' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE, 'gross_entitlement' => 0, 'settleable_amount' => 0, 'net_payable_amount' => 0,
                'status' => OrderSettlement::STATUS_PENDING, 'trx_id' => 'SV-' . $order->id]);
        }

        $line = fn ($c, $m, $col, $del, $cod, $st) => ['consignment_id' => $c, 'merchant_order_id' => $m, 'collected_amount' => $col, 'delivery_fee' => $del, 'cod_fee' => $cod, 'total_fee' => $del + $cod, 'delivery_status' => $st];
        $details = [
            'I1' => [$line('F65', 'ORD-65', 844, 125, 8.44, 'delivered'), $line('F134', 'ORD-134', 1500, 150, 15, 'delivered')],
            'I2' => [$line('F90', 'ORD-90', 1340, 120, 13.4, 'delivered'), $line('F126', 'ORD-126', 0, 105, 0, 'return'), $line('R126', 'ORD-126', 0, 31.5, 0, 'returned_to_merchant')],
        ];
        Http::fake(function (Request $request) use ($details) {
            if (preg_match('#/api/v2/invoices/(I\d)$#', $request->url(), $m)) {
                return Http::response(['error' => false, 'data' => ['invoice_id' => $m[1], 'invoiced_date' => '2026-11-15', 'receivable_status' => 'received', 'consignments' => $details[$m[1]]]]);
            }
            if (str_contains($request->url(), '/api/v2/invoices')) {
                return Http::response(['error' => false, 'data' => ['invoices' => [
                    ['invoice_id' => 'I1', 'invoice_date' => '2026-11-15', 'receivable_status' => 'paid'],
                    ['invoice_id' => 'I2', 'invoice_date' => '2026-11-10', 'receivable_status' => 'paid'],
                ]], 'pagination' => ['nextPage' => '']]);
            }

            return Http::response([], 404);
        });
    }

    private function order(string $no, float $total, float $ship, float $rcod, float $unit, float $admin, int $status, float $quoteFee, float $quoteTotal, string $cons): Order
    {
        $order = Order::create(['order_number' => $no, 'user_id' => $this->dropshipper->id, 'vendor_id' => $this->vendor->id, 'customer_name' => 'C', 'customer_phone' => '017',
            'shipping_address' => 'A', 'total' => $total, 'subtotal' => $total - $ship, 'shipping_fee' => $ship, 'remaining_cod' => $rcod, 'courier_account_owner' => 'vendor',
            'status' => $status, 'payment_status' => 'paid', 'consignment_id' => $cons]);
        $product = Product::create(['name' => "P {$no}", 'slug' => 'p-' . $no, 'vendor_id' => $this->vendor->id, 'shop_id' => $this->vendor->id, 'unit_price' => $unit,
            'admin_price' => $admin, 'price' => $total - $ship, 'category_id' => 1, 'brand_id' => 1, 'status' => 1, 'is_approved' => 1]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'shop_id' => $this->vendor->id, 'unit_price' => $unit, 'admin_price' => $admin,
            'price' => $total - $ship, 'reseller_price' => $total - $ship, 'qty' => 1]);
        DeliveryAssignedInfo::create(['order_id' => $order->id, 'consignment_id' => $cons, 'recipient_name' => 'C', 'recipient_phone' => '017', 'recipient_address' => 'A',
            'collectable_amount' => $total, 'delivery_fee' => $quoteFee, 'total_fee' => $quoteTotal]);

        return $order;
    }

    private function settle(Order $order, float $amount, string $status): void
    {
        OrderSettlement::create(['order_id' => $order->id, 'payable_user_id' => $this->dropshipper->id, 'user_type' => 'dropshipper',
            'settlement_type' => OrderSettlement::TYPE_RESELLER_PROFIT, 'gross_entitlement' => $amount, 'settleable_amount' => $amount, 'net_payable_amount' => $amount,
            'status' => $status, 'trx_id' => 'SR-' . $order->id, 'settled_at' => $status === OrderSettlement::STATUS_SETTLED ? now() : null]);
    }

    private function supplier(Order $o): float
    {
        return (float) OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)->value('settleable_amount');
    }

    private function reseller(Order $o): float
    {
        return (float) OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)->value('settleable_amount');
    }

    public function test_sync_matches_orders_to_carrybee_and_fixes_unpaid_settlements(): void
    {
        $accounting = app(OrderAccountingService::class);
        $this->assertEquals(-793.44, $accounting->calculateOrderStakeBreakdown($this->o[65])['supplier']['supplier_owes_platform'], 'the audit bug, before sync');

        $res = $this->postJson("/api/delivery-companies/carrybee/reconcile/{$this->account}/sync", [], $this->admin)->assertOk();

        $this->assertSame(5, CarrybeeInvoiceLine::count());
        $this->assertSame(0, CarrybeeInvoiceLine::whereNull('order_id')->count(), 'R-line matched by order number');
        $this->assertSame(CarrybeeInvoiceLine::TYPE_RETURN, CarrybeeInvoiceLine::where('consignment_id', 'R126')->value('line_type'));

        // Order 65: cash restored from CarryBee → vendor owes 844 − 133.44 − 680 = 30.56; dropshipper floored at 0.
        $this->assertEquals(844, $this->o[65]->fresh()->remaining_cod);
        $this->assertEquals(30.56, $this->supplier($this->o[65]));
        $this->assertEquals(0, $this->reseller($this->o[65]));
        // Order 134: CarryBee fee 150 (not the 105 quote) → 1500 − 165 − 1050 = 285; unpaid profit corrected 260 → 254.
        $this->assertEquals(285, $this->supplier($this->o[134]));
        $this->assertEquals(254, $this->reseller($this->o[134]));
        $this->assertEquals(150, $this->o[134]->fresh()->actual_courier_fee);
        // Order 90: profit already paid (403.29) is NOT changed; it is flagged overpaid by 247.69.
        $this->assertEquals(156.6, $this->supplier($this->o[90]));
        $this->assertEquals(403.29, $this->reseller($this->o[90]));
        $flag = PayoutFlag::where('order_id', $this->o[90]->id)->first();
        $this->assertSame('overpaid', $flag->flag);
        $this->assertEquals(247.69, $flag->difference);
        // Order 126: returned → penalty 105 + 31.50, payer not decided yet.
        $penalty = OrderReturnPenalty::where('order_id', $this->o[126]->id)->first();
        $this->assertEquals(136.5, $penalty->amount);
        $this->assertNull($penalty->payer);

        $totals = $res->json('data.report.totals');
        $this->assertEquals(3115.66, $totals['carrybee_net']);
        $this->assertEquals(2780, $totals['supplier_price']);
        $this->assertSame(1, $totals['penalties_undecided']);
        $this->assertSame(1, $totals['open_flags']);

        // Breakdown now shows CarryBee's figures.
        $this->getJson("/api/order-settlements/orders/{$this->o[65]->id}/breakdown", $this->admin)
            ->assertJsonPath('data.courier.delivery_fee', 125)
            ->assertJsonPath('data.carrybee.reconciled', true);
    }

    public function test_penalty_payer_decides_who_carries_the_return_charge(): void
    {
        app(CarrybeeReconcileService::class)->syncAccount($this->account);
        $url = "/api/delivery-companies/carrybee/reconcile/orders/{$this->o[126]->id}/penalty";

        $this->postJson($url, ['payer' => 'nobody'], $this->admin)->assertStatus(422);
        $this->postJson($url, ['payer' => 'admin', 'note' => 'our loss'], $this->admin)->assertOk()->assertJsonPath('data.payer', 'admin');
        $this->assertEquals(-136.5, $this->supplier($this->o[126]), 'platform credits the vendor');

        $this->postJson($url, ['payer' => 'vendor'], $this->admin)->assertOk();
        $this->assertEquals(0, $this->supplier($this->o[126]), 'vendor keeps the loss');

        $this->postJson($url, ['payer' => 'dropshipper'], $this->admin)->assertOk();
        $this->assertEquals(-136.5, $this->supplier($this->o[126]), 'vendor credited; dropshipper owes it');
        $this->assertEquals(0, (float) $this->o[126]->fresh()->reseller_profit, 'no negative dropshipper payout');
    }

    public function test_reissue_replaces_the_wrong_vendor_invoice_with_the_carrybee_matched_one(): void
    {
        $accounting = app(OrderAccountingService::class);
        $ids = collect($this->o)->pluck('id')->all();
        $old = $accounting->createVendorSettlementInvoice(['vendor_id' => $this->vendor->id, 'order_ids' => $ids], null);
        $this->assertEquals(-271.84, (float) $old->total_amount_owed, 'wrong invoice, like INV-V42-20261120-836');

        $url = "/api/order-settlements/vendor-invoices/{$old->id}/reissue";
        $this->postJson($url, [], $this->admin)->assertStatus(422); // not synced yet

        app(CarrybeeReconcileService::class)->syncAccount($this->account);
        $this->postJson($url, [], $this->admin)->assertStatus(422)->assertJsonFragment(['message' => 'Decide who pays the CarryBee return charge first: ORD-126']);

        app(CarrybeeReconcileService::class)->setPenaltyPayer($this->o[126], 'admin', null, null);
        $res = $this->postJson($url, [], $this->admin)->assertOk();

        // 30.56 + 285 + 156.60 − 136.50 = 335.66 = CarryBee paid 3115.66 − supplier price 2780.
        $this->assertEquals(335.66, $res->json('data.new.total_amount_owed'));
        $this->assertTrue($res->json('data.carrybee_check.matches'));
        $this->assertSame(SettlementInvoice::STATUS_REJECTED, $old->fresh()->status);
        $this->assertStringContainsString($res->json('data.new.invoice_number'), $old->fresh()->rejection_reason);
    }

    public function test_only_admins(): void
    {
        $token = ApiTokenService::create($this->dropshipper, ['basic'])['plain'];
        $this->getJson("/api/delivery-companies/carrybee/reconcile/{$this->account}", ['Authorization' => "Bearer {$token}"])->assertForbidden();
        $this->getJson('/api/delivery-companies/carrybee/reconcile/vendor-999999', $this->admin)->assertStatus(422);
    }
}
