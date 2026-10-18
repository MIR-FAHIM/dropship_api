<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\OrderAccountingLedger;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WithdrawRequest;
use App\Service\CarrybeeWebhookService;
use App\Service\NotificationService;
use App\Service\OrderAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reseller;
    private User $vendorUser;
    private Vendor $vendorWithOwnCourier;
    private Vendor $vendorWithPlatformCourier;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed order statuses 1..10
        for ($i = 1; $i <= 10; $i++) {
            OrderStatus::updateOrCreate(
                ['id' => $i],
                [
                    'name' => match ($i) {
                        1 => 'Pending',
                        2 => 'Processing',
                        3 => 'Pickup Requested',
                        4 => 'In Transit',
                        5 => 'Out for Delivery',
                        6 => 'Delivered',
                        7 => 'Cancelled',
                        8 => 'Returned',
                        9 => 'Paid',
                        10 => 'Completed',
                        default => "Status {$i}",
                    },
                    'type' => match ($i) {
                        1 => 'pending',
                        6, 9, 10 => 'delivered',
                        8 => 'returned',
                        7 => 'cancelled',
                        default => 'in_transit',
                    },
                    'is_active' => true,
                ]
            );
        }

        $this->admin = User::factory()->create(['name' => 'Admin User', 'user_type' => 'admin']);
        $this->reseller = User::factory()->create(['name' => 'Reseller Dropshipper', 'user_type' => 'dropshipper']);
        $vendorUserA = User::factory()->create(['name' => 'Vendor User A', 'user_type' => 'vendor']);
        $vendorUserB = User::factory()->create(['name' => 'Vendor User B', 'user_type' => 'vendor']);

        $this->category = Category::create([
            'name' => 'General',
            'slug' => 'general',
        ]);

        $this->vendorWithOwnCourier = Vendor::create([
            'user_id' => $vendorUserA->id,
            'shop_name' => 'Vendor Own Delivery Shop',
            'has_own_delivery_account' => true,
            'is_active' => true,
        ]);

        $this->vendorWithPlatformCourier = Vendor::create([
            'user_id' => $vendorUserB->id,
            'shop_name' => 'Vendor Platform Delivery Shop',
            'has_own_delivery_account' => false,
            'is_active' => true,
        ]);
    }

    /**
     * Test Mandatory Numerical Scenario with Vendor-Owned Courier Account.
     * Total: 1,130; Vendor entitlement: 1,000; Shipping: 130; Actual courier cost: 110;
     * Advance: 130; Remaining COD: 1,000; Net courier payout: 890.
     */
    public function test_mandatory_numerical_scenario_vendor_owned_courier(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'vendor_id' => $this->vendorWithOwnCourier->id,
            'name' => 'Test Product A',
            'slug' => 'test-product-a',
            'unit_price' => 1000.00,
            'admin_price' => 1000.00,
            'published' => 1,
            'approved' => 1,
        ]);

        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-TEST-VO-1130',
            'status' => 1,
            'payment_status' => 'unpaid',
            'subtotal' => 1000.00,
            'reseller_price' => 1000.00,
            'shipping_fee' => 130.00,
            'discount' => 0.00,
            'total' => 1130.00,
            'vendor_id' => $this->vendorWithOwnCourier->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'shop_id' => $this->vendorWithOwnCourier->id,
            'product_name' => $product->name,
            'unit_price' => 1000.00,
            'admin_price' => 1000.00,
            'reseller_price' => 1000.00,
            'quantity' => 1,
            'qty' => 1,
            'price' => 1000.00,
            'line_total' => 1000.00,
        ]);

        /** @var OrderAccountingService $service */
        $service = app(OrderAccountingService::class);

        // Step 1: Process Booking with Advance of 130 taken by ResellerBrain
        $service->processOrderBooking($order, [
            'amount' => 130.00,
            'recipient' => 'platform',
            'payment_method' => 'bkash',
            'trx_id' => 'BKASH-ADV-130',
        ]);

        $order->refresh();
        $this->assertEquals(130.00, $order->advance_amount);
        $this->assertEquals(1000.00, $order->remaining_cod);
        $this->assertEquals('vendor', $order->courier_account_owner);

        // Step 2: Process Delivery with actual courier cost 110 (delivery fee 90 + cod fee 20)
        $deliveryResult = $service->processOrderDelivery($order, [
            'actual_courier_fee' => 90.00,
            'actual_cod_fee' => 20.00,
            'confirmed' => true,
            'payout_status' => 'received',
        ]);

        $order->refresh();
        $this->assertTrue($deliveryResult['success']);
        $this->assertEquals(110.00, $order->actual_courier_cost);
        $this->assertEquals(890.00, $order->courier_net_payout);

        // Mathematical Reconciliations:
        // Inflows: 130 (advance) + 890 (net courier) + 110 (courier cost) = 1,130
        $totalInflows = $order->advance_amount + $order->courier_net_payout + $order->actual_courier_cost;
        $this->assertEquals(1130.00, $totalInflows);

        // Platform shipping margin: 130 - 110 = 20
        $this->assertEquals(20.00, $deliveryResult['platform_shipping_margin']);

        // Vendor Entitlement: 1000.
        // Vendor receives 890 directly from courier.
        // Platform remittance to vendor must be shortage only: 110 (NOT 1000)!
        $vendorSettlement = OrderSettlement::where('order_id', $order->id)
            ->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)
            ->first();

        $this->assertNotNull($vendorSettlement);
        $this->assertEquals(1000.00, $vendorSettlement->gross_entitlement);
        $this->assertEquals(890.00, $vendorSettlement->deducted_by_courier);
        $this->assertEquals(110.00, $vendorSettlement->settleable_amount);
        $this->assertEquals(110.00, $vendorSettlement->net_payable_amount);

        // Check Platform Logistic Earning settlement
        $platformSettlement = OrderSettlement::where('order_id', $order->id)
            ->where('settlement_type', OrderSettlement::TYPE_COMPANY_LOGISTIC_EARNING)
            ->first();
        $this->assertNotNull($platformSettlement);
        $this->assertEquals(20.00, $platformSettlement->settleable_amount);
        $this->assertEquals(OrderSettlement::STATUS_SETTLED, $platformSettlement->status);

        // Ledger Balance Check: Sum of all debits must equal sum of all credits
        $totalDebits = (float) OrderAccountingLedger::where('order_id', $order->id)->sum('debit');
        $totalCredits = (float) OrderAccountingLedger::where('order_id', $order->id)->sum('credit');
        $this->assertEquals($totalDebits, $totalCredits);
    }

    /**
     * Test Mandatory Numerical Scenario with Platform-Owned Courier Account.
     */
    public function test_mandatory_numerical_scenario_platform_owned_courier(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'vendor_id' => $this->vendorWithPlatformCourier->id,
            'name' => 'Test Product B',
            'slug' => 'test-product-b',
            'unit_price' => 1000.00,
            'admin_price' => 1000.00,
            'published' => 1,
            'approved' => 1,
        ]);

        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-TEST-PO-1130',
            'status' => 1,
            'payment_status' => 'unpaid',
            'subtotal' => 1000.00,
            'reseller_price' => 1000.00,
            'shipping_fee' => 130.00,
            'discount' => 0.00,
            'total' => 1130.00,
            'vendor_id' => $this->vendorWithPlatformCourier->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'shop_id' => $this->vendorWithPlatformCourier->id,
            'product_name' => $product->name,
            'unit_price' => 1000.00,
            'admin_price' => 1000.00,
            'reseller_price' => 1000.00,
            'quantity' => 1,
            'qty' => 1,
            'price' => 1000.00,
            'line_total' => 1000.00,
        ]);

        /** @var OrderAccountingService $service */
        $service = app(OrderAccountingService::class);

        $service->processOrderBooking($order, [
            'amount' => 130.00,
            'recipient' => 'platform',
            'payment_method' => 'nagad',
            'trx_id' => 'NAGAD-ADV-130',
        ]);

        $deliveryResult = $service->processOrderDelivery($order, [
            'actual_courier_fee' => 90.00,
            'actual_cod_fee' => 20.00,
            'confirmed' => true,
        ]);

        $order->refresh();
        $this->assertEquals('platform', $order->courier_account_owner);
        $this->assertEquals(890.00, $order->courier_net_payout);

        // Platform receives 890 from courier + 130 advance = 1020 total cash.
        // Vendor is owed full 1000 from platform.
        $vendorSettlement = OrderSettlement::where('order_id', $order->id)
            ->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)
            ->first();

        $this->assertNotNull($vendorSettlement);
        $this->assertEquals(1000.00, $vendorSettlement->gross_entitlement);
        $this->assertEquals(0.00, $vendorSettlement->deducted_by_courier);
        $this->assertEquals(1000.00, $vendorSettlement->settleable_amount);
        $this->assertEquals(1000.00, $vendorSettlement->net_payable_amount);

        // Platform retains 1020 - 1000 = 20 shipping margin
        $this->assertEquals(20.00, $deliveryResult['platform_shipping_margin']);
    }

    /**
     * Test CarryBee Webhook event status mapping and settlement execution.
     */
    public function test_carrybee_webhook_event_status_mapping_and_delivery_accounting(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'vendor_id' => $this->vendorWithPlatformCourier->id,
            'name' => 'Webhook Product',
            'slug' => 'webhook-product',
            'unit_price' => 500.00,
            'admin_price' => 600.00,
            'published' => 1,
            'approved' => 1,
        ]);

        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-WH-500',
            'status' => 1,
            'payment_status' => 'unpaid',
            'subtotal' => 700.00,
            'reseller_price' => 700.00,
            'shipping_fee' => 100.00,
            'discount' => 0.00,
            'total' => 800.00,
            'vendor_id' => $this->vendorWithPlatformCourier->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'shop_id' => $this->vendorWithPlatformCourier->id,
            'product_name' => $product->name,
            'unit_price' => 500.00,
            'admin_price' => 600.00,
            'reseller_price' => 700.00,
            'quantity' => 1,
            'qty' => 1,
            'price' => 700.00,
            'line_total' => 700.00,
        ]);

        app(OrderAccountingService::class)->processOrderBooking($order);

        /** @var CarrybeeWebhookService $webhookService */
        $webhookService = app(CarrybeeWebhookService::class);

        $payload = [
            'event' => 'order.delivered',
            'order_id' => $order->id,
            'delivery_fee' => 80.00,
            'cod_fee' => 10.00,
        ];

        config(['carrybee.webhook.secret' => 'test-secret']);
        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, 'test-secret');

        $response = $webhookService->process($payload, $rawBody, $signature);
        $this->assertTrue($response['ok']);

        $order->refresh();
        $this->assertEquals(6, $order->status);
        $this->assertEquals('paid', $order->payment_status);

        // Settlements must be created automatically
        $vendorSettlement = OrderSettlement::where('order_id', $order->id)
            ->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)
            ->first();
        $this->assertNotNull($vendorSettlement);
        $this->assertEquals(500.00, $vendorSettlement->gross_entitlement);

        // Reseller profit: 700 selling - 600 admin = 100
        $resellerSettlement = OrderSettlement::where('order_id', $order->id)
            ->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)
            ->first();
        $this->assertNotNull($resellerSettlement);
        $this->assertEquals(100.00, $resellerSettlement->settleable_amount);
    }

    /**
     * Test Two-phase Withdrawal Reservation and Overspending Protection.
     */
    public function test_withdrawal_balance_reservation_and_transfer(): void
    {
        /** @var OrderAccountingService $service */
        $service = app(OrderAccountingService::class);

        // Give reseller 500 completed credit
        ResellerTransaction::create([
            'reseller_id' => $this->reseller->id,
            'amount' => 500.00,
            'trx_id' => 'INIT-CREDIT-500',
            'trx_type' => 'credit',
            'status' => 'completed',
            'source' => 'wallet',
        ]);

        $balances = $service->calculateUserBalances($this->reseller->id);
        $this->assertEquals(500.00, $balances['available_balance']);

        // Overdraft attempt (requesting 600 when balance is 500) must fail
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->reserveWithdrawal($this->reseller->id, 600.00);
    }

    /**
     * Test Valid Withdrawal Reservation, Rejection Release, and Completion.
     */
    public function test_withdrawal_lifecycle_reservation_and_release(): void
    {
        /** @var OrderAccountingService $service */
        $service = app(OrderAccountingService::class);

        ResellerTransaction::create([
            'reseller_id' => $this->reseller->id,
            'amount' => 500.00,
            'trx_id' => 'INIT-CREDIT-500',
            'trx_type' => 'credit',
            'status' => 'completed',
            'source' => 'wallet',
        ]);

        // Request 300
        $withdraw = $service->reserveWithdrawal($this->reseller->id, 300.00);
        $this->assertEquals(300.00, $withdraw->reserved_amount);
        $this->assertEquals('pending', $withdraw->status);

        // Available balance must drop to 200 (500 - 300 reserved)
        $balancesAfterReq = $service->calculateUserBalances($this->reseller->id);
        $this->assertEquals(200.00, $balancesAfterReq['available_balance']);
        $this->assertEquals(300.00, $balancesAfterReq['pending_reserved']);

        // Transfer withdrawal with bank ref
        $service->transferWithdrawal($withdraw, 'BANK-REF-9988');

        $withdraw->refresh();
        $this->assertEquals('transferred', $withdraw->status);
        $this->assertEquals('BANK-REF-9988', $withdraw->bank_reference);

        // Debit transaction must be completed in ResellerTransaction
        $debitTrx = ResellerTransaction::where('ref_id', $withdraw->id)
            ->where('trx_type', 'debit')
            ->where('status', 'completed')
            ->first();
        $this->assertNotNull($debitTrx);
        $this->assertEquals(300.00, $debitTrx->amount);

        // Final balance: total credit 500, total debit 300, net balance 200, pending reserved 0
        $finalBalances = $service->calculateUserBalances($this->reseller->id);
        $this->assertEquals(200.00, $finalBalances['available_balance']);
        $this->assertEquals(0.00, $finalBalances['pending_reserved']);
    }

    /**
     * Test Vendor Dashboard Sales uses unit_price * quantity (vendor entitlement).
     */
    public function test_vendor_dashboard_sales_uses_unit_price(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'vendor_id' => $this->vendorWithOwnCourier->id,
            'name' => 'Pricing Check Product',
            'slug' => 'pricing-check-product',
            'unit_price' => 800.00, // Vendor gets 800
            'admin_price' => 900.00,
            'published' => 1,
            'approved' => 1,
        ]);

        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-VEND-SALES',
            'status' => 9, // Delivered
            'payment_status' => 'paid',
            'subtotal' => 1200.00, // Customer price is 1200
            'reseller_price' => 1200.00,
            'shipping_fee' => 100.00,
            'discount' => 0.00,
            'total' => 1300.00,
            'vendor_id' => $this->vendorWithOwnCourier->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'shop_id' => $this->vendorWithOwnCourier->id,
            'product_name' => $product->name,
            'unit_price' => 800.00, // Vendor entitlement
            'admin_price' => 900.00,
            'reseller_price' => 1200.00,
            'quantity' => 1,
            'qty' => 1,
            'price' => 1200.00,
            'line_total' => 1200.00, // Retail price
        ]);

        $response = $this->getJson("/api/vendors/dashboard/report/{$this->vendorWithOwnCourier->id}");
        $response->assertStatus(200);

        $salesData = $response->json('data.sales');
        // Sales must be 800 (vendor entitlement), NOT 1200 (customer retail line_total)!
        $this->assertEquals(800.00, $salesData['total']);
        $this->assertEquals(800.00, $salesData['completed_sales']);
    }
}
