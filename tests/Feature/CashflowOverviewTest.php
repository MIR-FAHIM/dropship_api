<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ResellerTransaction;
use App\Models\SettlementInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use App\Service\OrderAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan A - Admin Panel Cashflow Report. Builds three orders covering each
 * stage of the projected -> settled -> paid lifecycle for both dropshipper
 * and vendor, and checks the overview adds them up correctly.
 */
class CashflowOverviewTest extends TestCase
{
    use RefreshDatabase;

    private OrderAccountingService $accountingService;
    private string $adminToken;
    private User $reseller;
    private Vendor $vendor;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountingService = app(OrderAccountingService::class);

        foreach ([1 => 'Pending', 6 => 'Delivered', 9 => 'Completed', 10 => 'Carrybee Paid'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'other', 'is_active' => true]);
        }

        $admin = User::factory()->create(['email' => 'cashflow_admin@test.com', 'user_type' => 'admin', 'role' => 'admin']);
        $this->adminToken = ApiTokenService::create($admin, ['basic', 'admin'], 60, 'tok')['plain'];

        $this->reseller = User::factory()->create(['email' => 'cashflow_reseller@test.com', 'user_type' => 'dropshipper']);
        $vendorUser = User::factory()->create(['email' => 'cashflow_vendor@test.com', 'user_type' => 'vendor']);
        $this->vendor = Vendor::create(['user_id' => $vendorUser->id, 'shop_name' => 'Cashflow Shop', 'is_active' => true, 'has_own_delivery_account' => false]);

        $this->product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-CF',
            'unit_price' => 500.00, 'admin_price' => 600.00, 'price' => 800.00,
            'current_stock' => 50, 'category_id' => 1, 'vendor_id' => $this->vendor->id, 'status' => 1,
        ]);
    }

    private function makeOrder(int $status, bool $withSettlement = false): Order
    {
        $order = Order::create([
            'user_id' => $this->reseller->id, 'order_number' => 'ORD-CF-' . uniqid(), 'status' => $status,
            'payment_status' => 'unpaid', 'customer_name' => 'John', 'customer_phone' => '017',
            'shipping_address' => 'addr', 'zone' => 'Dhaka',
            'subtotal' => 800, 'reseller_price' => 800, 'reseller_profit' => 200,
            'shipping_fee' => 100, 'discount' => 0, 'total' => 900, 'remaining_cod' => 900,
            'vendor_id' => $this->vendor->id, 'courier_account_owner' => 'platform',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'shop_id' => $this->vendor->id,
            'product_name' => $this->product->name, 'sku' => $this->product->sku,
            'unit_price' => 500, 'admin_price' => 600, 'reseller_price' => 800,
            'qty' => 1, 'line_total' => 800, 'line_total_reseller_profit' => 200, 'status' => 1,
        ]);

        if ($withSettlement) {
            $this->accountingService->processOrderDelivery($order, [
                'actual_courier_fee' => 50.00,
                'actual_cod_fee' => 9.00,
                'confirmed' => true,
            ]);
            $order->refresh();
            $order->forceFill(['carrybee_paid_at' => now()])->save();
        }

        return $order->refresh();
    }

    public function test_cashflow_overview_tracks_projected_settled_and_paid_for_both_stakeholders(): void
    {
        // Order 1: placed, not delivered -> PROJECTED only.
        $order1 = $this->makeOrder(1, withSettlement: false);

        // Order 2: delivered -> SETTLED (liability), nothing paid out yet.
        $order2 = $this->makeOrder(6, withSettlement: true);

        // Order 3: delivered AND fully paid out to both reseller and vendor.
        $order3 = $this->makeOrder(6, withSettlement: true);

        // Disburse reseller profit for order 3 to wallet, then actually withdraw it.
        $this->accountingService->disburseDropshipperProfitDirectly($order3, ['payment_method' => 'wallet'], null);
        $balance = $this->accountingService->calculateUserBalances($this->reseller->id);
        $withdraw = $this->accountingService->reserveWithdrawal($this->reseller->id, $balance['available_balance']);
        $this->accountingService->transferWithdrawal($withdraw, 'BANK-REF-CF-1');

        // Pay the vendor settlement for order 3 via a directly-paid invoice.
        $this->accountingService->createVendorSettlementInvoice([
            'vendor_id' => $this->vendor->id,
            'order_ids' => [$order3->id],
            'status' => SettlementInvoice::STATUS_PAID,
        ]);

        $overview = $this->accountingService->getCashflowOverview(30);

        // --- Dropshipper flow ---
        $this->assertEquals(200.00, $overview['dropshipper_flow']['projected']); // order1 only
        // order2 (191 pending) + order3 (191, now settled+withdrawn) = 382 confirmed, 191 paid out.
        $resellerProfit = round(900 - 600 - 100 - 9, 2); // cash-flow formula, matches both orders
        $this->assertEquals($resellerProfit, 191.00);
        $this->assertEquals(191.00, $overview['dropshipper_flow']['paid_out_total']);
        $this->assertEquals(191.00, $overview['dropshipper_flow']['settled_unwithdrawn']); // order2 still owed

        // --- Vendor flow ---
        $this->assertEquals(500.00, $overview['vendor_flow']['projected']); // order1 only
        $this->assertEquals(500.00, $overview['vendor_flow']['settled_unpaid']); // order2 still owed
        $this->assertEquals(500.00, $overview['vendor_flow']['paid_out_total']); // order3 paid
        $this->assertEquals(0.00, $overview['vendor_flow']['receivable_from_vendors']);

        // --- Liabilities ---
        $this->assertEquals(191.00, $overview['liabilities']['owed_to_dropshippers']);
        $this->assertEquals(500.00, $overview['liabilities']['owed_to_vendors']);
        $this->assertEquals(691.00, $overview['liabilities']['total']);

        // --- Platform earnings: (600-500)+(100-59)=41+100=141 per settled order, x2 orders ---
        $this->assertEquals(282.00, $overview['platform_earnings']['total']);
        $this->assertEquals(200.00, $overview['platform_earnings']['product_commission_total']);
        $this->assertEquals(82.00, $overview['platform_earnings']['shipping_margin_total']);

        // --- Cash in hand: platform inflow (courier_net_payout x2 paid orders) minus payouts ---
        $order2->refresh();
        $order3->refresh();
        $expectedInflow = round((float) $order2->courier_net_payout + (float) $order3->courier_net_payout, 2);
        $this->assertEquals(
            round($expectedInflow - 500.00 - 191.00, 2),
            $overview['cash_in_hand']['estimate']
        );

        // --- Structure sanity ---
        $this->assertCount(30, $overview['daily_breakdown']);
        $this->assertArrayHasKey('week', $overview['growth']);
        $this->assertArrayHasKey('month', $overview['growth']);
    }

    public function test_cashflow_dashboard_endpoint_returns_overview(): void
    {
        $this->makeOrder(1, withSettlement: false);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson('/api/reports/cashflow-dashboard?days=7');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('platform_earnings', $data);
        $this->assertArrayHasKey('liabilities', $data);
        $this->assertArrayHasKey('receivables', $data);
        $this->assertArrayHasKey('dropshipper_flow', $data);
        $this->assertArrayHasKey('vendor_flow', $data);
        $this->assertArrayHasKey('cash_in_hand', $data);
        $this->assertCount(7, $data['daily_breakdown']);
    }
}
