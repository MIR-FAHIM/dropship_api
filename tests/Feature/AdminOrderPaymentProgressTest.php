<?php

namespace Tests\Feature;

use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Payment column on /admin-panel/orders: payment_progress on GET /orders/all/orders.
class AdminOrderPaymentProgressTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;
    private User $dropshipper;
    private Vendor $vendor;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
        $this->dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $vendorUser = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create(['user_id' => $vendorUser->id, 'shop_name' => 'Shop']);
    }

    /** @param array{consignment?:bool, carrybee_paid?:bool, dropshipper?:string, supplier?:string, direction?:string} $o */
    private function order(int $status, array $o = []): Order
    {
        $n = ++$this->seq;
        $paid = $o['carrybee_paid'] ?? false;
        $order = Order::create([
            'user_id' => $this->dropshipper->id, 'vendor_id' => $this->vendor->id, 'order_number' => "PP-{$n}", 'status' => $status,
            'total' => 1130, 'reseller_profit' => 238.70,
            'carrybee_invoice_id' => $paid ? "INV{$n}" : null, 'carrybee_paid_at' => $paid ? now() : null,
            'carrybee_disbursed_to' => $paid ? 'vendor' : null,
        ]);
        if ($o['consignment'] ?? true) {
            DeliveryAssignedInfo::create(['order_id' => $order->id, 'consignment_id' => "FC{$n}", 'merchant_order_id' => $order->order_number,
                'delivery_company_id' => 1, 'recipient_name' => 'C', 'recipient_phone' => '017', 'recipient_address' => 'D']);
        }
        foreach (['dropshipper' => OrderSettlement::TYPE_RESELLER_PROFIT, 'supplier' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE] as $key => $type) {
            if (isset($o[$key])) {
                OrderSettlement::create([
                    'order_id' => $order->id, 'settlement_type' => $type, 'vendor_id' => $key === 'supplier' ? $this->vendor->id : null,
                    'user_type' => $key === 'supplier' ? 'vendor' : 'dropshipper', 'gross_entitlement' => 100, 'settleable_amount' => 100,
                    'net_payable_amount' => $key === 'supplier' ? 700 : 238.70, 'status' => $o[$key],
                    'direction' => $key === 'supplier' ? ($o['direction'] ?? OrderSettlement::DIRECTION_PLATFORM_OWES_PAYEE) : OrderSettlement::DIRECTION_PLATFORM_OWES_PAYEE,
                    'settled_at' => $o[$key] === OrderSettlement::STATUS_SETTLED ? now() : null,
                    'trx_id' => "T-{$n}-{$key}",
                ]);
            }
        }

        return $order;
    }

    private function progress(array $query = []): array
    {
        $rows = $this->getJson('/api/orders/all/orders?' . http_build_query($query + ['cancelled' => 'include']), $this->headers)
            ->assertOk()->json('data.data');

        return collect($rows)->mapWithKeys(fn ($r) => [$r['order_number'] => $r['payment_progress']])->all();
    }

    public function test_each_stage_is_reported(): void
    {
        $S = OrderSettlement::STATUS_SETTLED;
        $P = OrderSettlement::STATUS_PENDING;
        $this->order(7);                                                                          // PP-1 cancelled
        $this->order(4);                                                                          // PP-2 not delivered
        $this->order(6);                                                                          // PP-3 CarryBee pending
        $this->order(10, ['carrybee_paid' => true, 'dropshipper' => $P, 'supplier' => $P]);        // PP-4 both pending
        $this->order(10, ['carrybee_paid' => true, 'dropshipper' => $P, 'supplier' => $S]);        // PP-5 dropshipper pending
        $this->order(10, ['carrybee_paid' => true, 'dropshipper' => $S, 'supplier' => $P]);        // PP-6 supplier pending
        $this->order(9, ['carrybee_paid' => true, 'dropshipper' => $S, 'supplier' => $S]);         // PP-7 completed
        $this->order(12);                                                                         // PP-8 returning
        $this->order(6, ['consignment' => false, 'dropshipper' => $P, 'supplier' => $S]);          // PP-9 no courier, dropshipper pending

        $p = $this->progress();
        $this->assertSame('cancelled', $p['PP-1']['stage']);
        $this->assertSame('not_delivered', $p['PP-2']['stage']);
        $this->assertSame('carrybee_pending', $p['PP-3']['stage']);
        $this->assertSame('both_pending', $p['PP-4']['stage']);
        $this->assertSame('dropshipper_pending', $p['PP-5']['stage']);
        $this->assertSame('supplier_pending', $p['PP-6']['stage']);
        $this->assertSame('completed', $p['PP-7']['stage']);
        $this->assertSame('returning', $p['PP-8']['stage']);
        $this->assertSame('dropshipper_pending', $p['PP-9']['stage']);
        $this->assertFalse($p['PP-9']['carrybee']['applicable']);
    }

    public function test_step_details_for_hover(): void
    {
        $order = $this->order(10, ['carrybee_paid' => true, 'dropshipper' => OrderSettlement::STATUS_SETTLED,
            'supplier' => OrderSettlement::STATUS_PENDING, 'direction' => OrderSettlement::DIRECTION_PAYEE_OWES_PLATFORM]);

        $p = $this->progress()[$order->order_number];
        $this->assertTrue($p['carrybee']['paid']);
        $this->assertSame('INV1', $p['carrybee']['invoice_id']);
        $this->assertSame('vendor', $p['carrybee']['paid_to']);
        $this->assertTrue($p['dropshipper']['paid']);
        $this->assertEquals(238.70, $p['dropshipper']['amount']);
        $this->assertNotNull($p['dropshipper']['paid_at']);
        $this->assertFalse($p['supplier']['paid']);
        $this->assertEquals(700, $p['supplier']['amount']);
        $this->assertSame('we_receive', $p['supplier']['direction'], 'supplier on own CarryBee account: we will get money from them');
    }

    public function test_server_side_stage_filter(): void
    {
        $S = OrderSettlement::STATUS_SETTLED;
        $P = OrderSettlement::STATUS_PENDING;
        $this->order(7);
        $this->order(6);
        $this->order(10, ['carrybee_paid' => true, 'dropshipper' => $P, 'supplier' => $P]);
        $this->order(10, ['carrybee_paid' => true, 'dropshipper' => $S, 'supplier' => $P]);
        $this->order(9, ['carrybee_paid' => true, 'dropshipper' => $S, 'supplier' => $S]);

        $this->assertSame(['PP-2'], array_keys($this->progress(['payment_stage' => 'carrybee_pending'])));
        $this->assertEqualsCanonicalizing(['PP-3'], array_keys($this->progress(['payment_stage' => 'dropshipper_pending'])));
        $this->assertEqualsCanonicalizing(['PP-3', 'PP-4'], array_keys($this->progress(['payment_stage' => 'supplier_pending'])));
        $this->assertSame(['PP-5'], array_keys($this->progress(['payment_stage' => 'completed'])));
        $this->assertSame(['PP-1'], array_keys($this->progress(['payment_stage' => 'cancelled'])));
        $this->assertCount(5, $this->progress(['payment_stage' => 'all']));
    }

    public function test_settlements_are_not_sent_in_the_list(): void
    {
        $this->order(10, ['carrybee_paid' => true, 'dropshipper' => OrderSettlement::STATUS_PENDING]);
        $row = $this->getJson('/api/orders/all/orders', $this->headers)->assertOk()->json('data.data.0');
        $this->assertArrayNotHasKey('settlements', $row);
        $this->assertArrayHasKey('payment_progress', $row);
    }
}
