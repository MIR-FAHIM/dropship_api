<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\Product;
use App\Models\SettlementInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\DeliveryAssignedInfo;
use App\Service\ApiTokenService;
use App\Service\OrderAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStakeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $adminHeaders;
    private User $dropshipper;
    private User $vendorUser;
    private array $vendorHeaders;
    private Vendor $vendor;
    private Product $product;
    private OrderAccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountingService = app(OrderAccountingService::class);

        // 1. Admin + Token
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin_test_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'user_type' => 'admin',
            'banned' => 0,
        ]);
        $tokenData = ApiTokenService::create($this->admin, ['basic', 'admin'], 30, 'admin-token');
        $this->adminHeaders = ['Authorization' => 'Bearer ' . $tokenData['plain']];

        // 2. Dropshipper
        $this->dropshipper = User::create([
            'name' => 'Dropshipper User',
            'email' => 'dropshipper_test_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'dropshipper',
            'user_type' => 'dropshipper',
            'banned' => 0,
        ]);

        // 3. Vendor with own delivery account
        $this->vendorUser = User::create([
            'name' => 'Vendor User',
            'email' => 'vendor_test_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'vendor',
            'user_type' => 'vendor',
            'banned' => 0,
        ]);
        $vendorTokenData = ApiTokenService::create($this->vendorUser, ['basic', 'vendor'], 30, 'vendor-token');
        $this->vendorHeaders = ['Authorization' => 'Bearer ' . $vendorTokenData['plain']];

        $this->vendor = Vendor::create([
            'user_id' => $this->vendorUser->id,
            'shop_name' => 'Supplier V41 Shop',
            'owner_name' => 'Supplier V41',
            'shop_type' => 'Supplier',
            'is_active' => true,
            'has_own_delivery_account' => true,
        ]);

        // 4. Product: Wholesale 1750, Admin Base 1838, Reseller 2500
        $this->product = Product::create([
            'name' => 'Test Premium Watch',
            'slug' => 'test-premium-watch-' . uniqid(),
            'vendor_id' => $this->vendor->id,
            'shop_id' => $this->vendor->id,
            'unit_price' => 1750.00,
            'admin_price' => 1838.00,
            'price' => 2500.00,
            'category_id' => 1,
            'brand_id' => 1,
            'status' => 1,
            'is_approved' => 1,
        ]);
    }

    public function test_financial_breakdown_matches_target_order_formula()
    {
        // Target order: total 2630 (2500 product + 130 delivery fee)
        $order = Order::create([
            'order_number' => 'ORD-20261023-DNMNTV',
            'user_id' => $this->dropshipper->id,
            'vendor_id' => $this->vendor->id,
            'customer_name' => 'আলমগীর',
            'customer_phone' => '01745243730',
            'shipping_address' => 'বিহারীনগর,ঝাউডাঙ্গা',
            'total' => 2630.00,
            'subtotal' => 2500.00,
            'shipping_fee' => 130.00,
            'remaining_cod' => 2630.00,
            'courier_account_owner' => 'vendor',
            'status' => 'Delivered',
            'payment_status' => 'unpaid',
            'consignment_id' => 'F10246FF7KW',
            'actual_courier_fee' => 105.00,
            'actual_cod_fee' => 26.30,
            'actual_courier_cost' => 131.30,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'shop_id' => $this->vendor->id,
            'unit_price' => 1750.00,
            'admin_price' => 1838.00,
            'price' => 2500.00,
            'reseller_price' => 2500.00,
            'qty' => 1,
        ]);

        DeliveryAssignedInfo::create([
            'order_id' => $order->id,
            'consignment_id' => 'F10246FF7KW',
            'recipient_name' => 'আলমগীর',
            'recipient_phone' => '01745243730',
            'recipient_address' => 'বিহারীনগর,ঝাউডাঙ্গা',
            'collectable_amount' => 2630.00,
            'delivery_fee' => 105.00,
            'total_fee' => 131.30,
        ]);

        $breakdown = $this->accountingService->calculateOrderStakeBreakdown($order);

        // 1. Courier to Supplier Breakdown
        $this->assertEquals(2630.00, $breakdown['courier']['collectable_amount']);
        $this->assertEquals(105.00, $breakdown['courier']['delivery_fee']);
        $this->assertEquals(26.30, $breakdown['courier']['cod_fee']);
        $this->assertEquals(2498.70, $breakdown['courier']['net_received_by_supplier']);

        // 2. Platform Settlement Breakdown
        $this->assertEquals(1750.00, $breakdown['supplier']['vendor_price']);
        $this->assertEquals(748.70, $breakdown['supplier']['supplier_owes_platform']);
        $this->assertEquals(635.70, $breakdown['dropshipper']['profit']);
        $this->assertEquals(113.00, $breakdown['platform']['net_profit']);

        // 3. Perfect Balance
        $this->assertTrue($breakdown['balance']['is_balanced']);
        $this->assertEquals(0.00, $breakdown['balance']['sum_difference']);

        // Test API endpoint returns same breakdown
        $response = $this->withHeaders($this->adminHeaders)->getJson("/api/order-settlements/orders/{$order->id}/breakdown");
        $response->assertStatus(200);
        $response->assertJsonPath('data.supplier.supplier_owes_platform', 748.7);
        $response->assertJsonPath('data.dropshipper.profit', 635.7);
        $this->assertEquals(113.00, (float) $response->json('data.platform.net_profit'));
    }

    public function test_admin_can_adjust_breakdown_before_settlement()
    {
        $order = Order::create([
            'order_number' => 'ORD-TEST-ADJUST',
            'user_id' => $this->dropshipper->id,
            'vendor_id' => $this->vendor->id,
            'total' => 2630.00,
            'shipping_fee' => 130.00,
            'remaining_cod' => 2630.00,
            'courier_account_owner' => 'vendor',
            'status' => 'Delivered',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'shop_id' => $this->vendor->id,
            'unit_price' => 1750.00,
            'admin_price' => 1838.00,
            'reseller_price' => 2500.00,
            'qty' => 1,
        ]);

        // Adjust courier delivery fee to 110.00
        $response = $this->withHeaders($this->adminHeaders)->postJson("/api/order-settlements/orders/{$order->id}/update-breakdown", [
            'courier_delivery_fee' => 110.00,
            'courier_cod_fee' => 26.30,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Net received = 2630 - 110 - 26.30 = 2493.70
        // Supplier owes = 2493.70 - 1750 = 743.70
        // Platform net profit = (1838 - 1750) + (130 - 110) = 88 + 20 = 108.00
        // Dropshipper profit = 635.70
        // 635.70 + 108.00 = 743.70 -> Balanced!
        $this->assertEquals(743.70, $data['supplier']['supplier_owes_platform']);
        $this->assertEquals(108.00, $data['platform']['net_profit']);
        $this->assertEquals(635.70, $data['dropshipper']['profit']);
        $this->assertTrue($data['balance']['is_balanced']);
    }

    public function test_multi_order_vendor_settlement_invoice_creation()
    {
        // Create 2 orders for Vendor V41 (matching Alamgir & Emon)
        $order1 = Order::create([
            'order_number' => 'ORD-20261023-DNMNTV',
            'user_id' => $this->dropshipper->id,
            'vendor_id' => $this->vendor->id,
            'total' => 2630.00,
            'shipping_fee' => 130.00,
            'remaining_cod' => 2630.00,
            'courier_account_owner' => 'vendor',
            'status' => 'Delivered',
            'actual_courier_fee' => 105.00,
            'actual_cod_fee' => 26.30,
        ]);
        OrderItem::create([
            'order_id' => $order1->id,
            'product_id' => $this->product->id,
            'shop_id' => $this->vendor->id,
            'unit_price' => 1750.00,
            'admin_price' => 1838.00,
            'reseller_price' => 2500.00,
            'qty' => 1,
        ]);

        $product2 = Product::create([
            'name' => 'Test Item 2',
            'slug' => 'test-item-2-' . uniqid(),
            'vendor_id' => $this->vendor->id,
            'unit_price' => 400.00,
            'admin_price' => 420.00,
            'price' => 440.00,
            'category_id' => 1,
            'brand_id' => 1,
            'status' => 1,
        ]);
        $order2 = Order::create([
            'order_number' => 'ORD-20261021-QQMGCH',
            'user_id' => $this->dropshipper->id,
            'vendor_id' => $this->vendor->id,
            'total' => 570.00,
            'shipping_fee' => 130.00,
            'remaining_cod' => 570.00,
            'courier_account_owner' => 'vendor',
            'status' => 'Delivered',
            'actual_courier_fee' => 99.00,
            'actual_cod_fee' => 5.70,
        ]);
        OrderItem::create([
            'order_id' => $order2->id,
            'product_id' => $product2->id,
            'shop_id' => $this->vendor->id,
            'unit_price' => 400.00,
            'admin_price' => 420.00,
            'reseller_price' => 440.00,
            'qty' => 1,
        ]);

        $response = $this->withHeaders($this->adminHeaders)->postJson('/api/order-settlements/vendor-invoice', [
            'vendor_id' => $this->vendor->id,
            'order_ids' => [$order1->id, $order2->id],
            'invoice_number' => 'INV-V41-20261028-001',
            'payment_method' => 'bank',
            'trx_id' => 'BRAC-TRX-998877',
            'note' => 'Settlement for Alamgir and Emon orders',
        ]);

        $response->assertStatus(201);
        $invoice = $response->json('data');

        // Aggregate assertions from doc:
        // Total Collectable: 3200
        // Total Delivery Fee: 204
        // Total COD Fee: 32
        // Total Courier Net: 2964
        // Total Supplier Price: 2150
        // Total Amount Owed by Vendor: 814.00
        // Total Dropshipper Payout: 650.00
        // Total Platform Net Profit: 164.00
        $this->assertEquals('INV-V41-20261028-001', $invoice['invoice_number']);
        $this->assertEquals(3200.00, $invoice['total_collectable']);
        $this->assertEquals(204.00, $invoice['total_delivery_fee']);
        $this->assertEquals(32.00, $invoice['total_cod_fee']);
        $this->assertEquals(2964.00, $invoice['total_courier_net']);
        $this->assertEquals(2150.00, $invoice['total_supplier_price']);
        $this->assertEquals(814.00, $invoice['total_amount_owed']);
        $this->assertEquals(650.00, $invoice['total_dropshipper_payout']);
        $this->assertEquals(164.00, $invoice['total_platform_net_profit']);
        $this->assertEquals('BRAC-TRX-998877', $invoice['trx_id']);
    }

    public function test_admin_can_disburse_dropshipper_profit_directly()
    {
        $order = Order::create([
            'order_number' => 'ORD-DIRECT-DISB',
            'user_id' => $this->dropshipper->id,
            'vendor_id' => $this->vendor->id,
            'total' => 2630.00,
            'shipping_fee' => 130.00,
            'remaining_cod' => 2630.00,
            'courier_account_owner' => 'vendor',
            'status' => 'Delivered',
            'actual_courier_fee' => 105.00,
            'actual_cod_fee' => 26.30,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'shop_id' => $this->vendor->id,
            'unit_price' => 1750.00,
            'admin_price' => 1838.00,
            'reseller_price' => 2500.00,
            'qty' => 1,
        ]);

        // Direct disbursement to dropshipper via bKash
        $response = $this->withHeaders($this->adminHeaders)->postJson("/api/order-settlements/orders/{$order->id}/disburse-reseller-profit", [
            'amount' => 635.70,
            'payment_method' => 'bkash',
            'trx_id' => 'BK789456123',
            'note' => 'Direct bKash disbursement by admin',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('order_settlements', [
            'order_id' => $order->id,
            'settlement_type' => OrderSettlement::TYPE_RESELLER_PROFIT,
            'status' => OrderSettlement::STATUS_SETTLED,
            'settled_trx_id' => 'BK789456123',
            'settleable_amount' => 635.70,
        ]);

        // Completed WithdrawRequest created automatically
        $this->assertDatabaseHas('withdraw_requests', [
            'user_id' => $this->dropshipper->id,
            'amount' => 635.70,
            'status' => 'transferred',
            'bank_reference' => 'BK789456123',
            'payment_method' => 'bkash',
        ]);
    }

    public function test_vendor_invoice_payment_and_approval_lifecycle()
    {
        $order = Order::create([
            'order_number' => 'ORD-INV-LIFECYCLE',
            'user_id' => $this->dropshipper->id,
            'vendor_id' => $this->vendor->id,
            'total' => 2630.00,
            'shipping_fee' => 130.00,
            'remaining_cod' => 2630.00,
            'courier_account_owner' => 'vendor',
            'status' => 'Delivered',
            'actual_courier_fee' => 105.00,
            'actual_cod_fee' => 26.30,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'shop_id' => $this->vendor->id,
            'unit_price' => 1750.00,
            'admin_price' => 1838.00,
            'reseller_price' => 2500.00,
            'qty' => 1,
        ]);

        // 1. Admin creates invoice in pending status with admin payment accounts
        $adminAccounts = [
            [
                'type' => 'MFS',
                'provider' => 'bKash (Merchant)',
                'account_number' => '01782084390',
                'account_name' => 'ResellerBrain Admin',
            ]
        ];

        $createRes = $this->withHeaders($this->adminHeaders)->postJson('/api/order-settlements/vendor-invoice', [
            'vendor_id' => $this->vendor->id,
            'order_ids' => [$order->id],
            'status' => 'pending',
            'admin_payment_details' => $adminAccounts,
            'note' => 'Please disburse within 3 business days',
        ]);

        $createRes->assertStatus(201);
        $invoiceId = $createRes->json('data.id');
        $this->assertEquals('pending', $createRes->json('data.status'));
        $this->assertEquals(748.70, (float) $createRes->json('data.total_amount_owed'));

        // 2. Vendor lists invoices
        $listRes = $this->withHeaders($this->vendorHeaders)->getJson('/api/order-settlements/vendor-invoices');
        $listRes->assertStatus(200);
        $this->assertNotEmpty($listRes->json('data.data'));

        // 3. Vendor submits payment
        $payRes = $this->withHeaders($this->vendorHeaders)->postJson("/api/order-settlements/vendor-invoices/{$invoiceId}/pay", [
            'payment_method' => 'bkash',
            'trx_id' => 'BKASH-V41-778899',
            'sender_account' => '01911223344',
            'note' => 'Paid from my personal bKash',
        ]);

        $payRes->assertStatus(200);
        $this->assertEquals('paid', $payRes->json('data.status'));
        $this->assertEquals('BKASH-V41-778899', $payRes->json('data.trx_id'));
        $this->assertEquals('01911223344', $payRes->json('data.sender_account'));

        // 4. Admin approves invoice
        $approveRes = $this->withHeaders($this->adminHeaders)->postJson("/api/order-settlements/vendor-invoices/{$invoiceId}/approve", [
            'trx_id' => 'BKASH-V41-778899',
        ]);
        $approveRes->assertStatus(200);
        $this->assertEquals('approved', $approveRes->json('data.status'));

        // 5. Check order settlement is marked settled
        $this->assertDatabaseHas('order_settlements', [
            'order_id' => $order->id,
            'settlement_type' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE,
            'status' => OrderSettlement::STATUS_SETTLED,
        ]);
    }
}
