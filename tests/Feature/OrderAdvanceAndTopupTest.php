<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderAccountingLedger;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Service\OrderAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAdvanceAndTopupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reseller;
    private User $vendorUser;
    private Vendor $vendor;
    private Product $product;
    private OrderAccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 1; $i <= 10; $i++) {
            OrderStatus::updateOrCreate(
                ['id' => $i],
                [
                    'name' => match ($i) {
                        1 => 'Pending',
                        2 => 'Processing',
                        9 => 'Delivered',
                        10 => 'Cancelled',
                        default => "Status {$i}",
                    },
                    'type' => match ($i) {
                        1 => 'pending',
                        2 => 'processing',
                        9 => 'delivered',
                        10 => 'cancelled',
                        default => 'other',
                    },
                    'is_active' => true,
                ]
            );
        }

        $this->admin = User::factory()->create([
            'email' => 'admin_adv@test.com',
            'user_type' => 'admin',
        ]);

        $this->reseller = User::factory()->create([
            'email' => 'reseller_adv@test.com',
            'user_type' => 'reseller',
        ]);

        $this->vendorUser = User::factory()->create([
            'email' => 'vendor_adv@test.com',
            'user_type' => 'vendor',
        ]);

        $this->vendor = Vendor::create([
            'user_id' => $this->vendorUser->id,
            'shop_name' => 'Advance Test Vendor',
            'is_active' => true,
            'has_own_delivery_account' => false,
        ]);

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general-' . uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Test Widget',
            'slug' => 'test-widget-' . uniqid(),
            'sku' => 'WIDGET-01',
            'unit_price' => 1000.00,
            'admin_price' => 1000.00,
            'price' => 1200.00,
            'current_stock' => 100,
            'category_id' => $category->id,
            'vendor_id' => $this->vendor->id,
            'status' => 1,
        ]);

        $this->accountingService = app(OrderAccountingService::class);
    }

    public function test_dropshipper_with_less_than_5_orders_requires_advance(): void
    {
        // 0 completed orders
        $eligibility = $this->accountingService->checkAdvanceEligibility($this->reseller->id);
        $this->assertTrue($eligibility['requires_advance']);
        $this->assertEquals(0, $eligibility['completed_orders_count']);
        $this->assertEquals(130.00, $eligibility['default_advance_amount']);

        // Create 4 delivered orders
        for ($i = 0; $i < 4; $i++) {
            Order::create([
                'user_id' => $this->reseller->id,
                'order_number' => 'ORD-DELIV-' . $i,
                'status' => 9,
                'total' => 1130.00,
                'subtotal' => 1000.00,
            ]);
        }

        $eligibility = $this->accountingService->checkAdvanceEligibility($this->reseller->id);
        $this->assertTrue($eligibility['requires_advance']);
        $this->assertEquals(4, $eligibility['completed_orders_count']);
    }

    public function test_dropshipper_with_5_or_more_orders_skips_advance(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Order::create([
                'user_id' => $this->reseller->id,
                'order_number' => 'ORD-DELIV-5-' . $i,
                'status' => 9,
                'total' => 1130.00,
                'subtotal' => 1000.00,
            ]);
        }

        $eligibility = $this->accountingService->checkAdvanceEligibility($this->reseller->id);
        $this->assertFalse($eligibility['requires_advance']);
        $this->assertEquals(5, $eligibility['completed_orders_count']);
    }

    public function test_advance_payment_from_wallet_deducts_balance_instantly(): void
    {
        // Pre-fund reseller wallet with ৳500
        ResellerTransaction::create([
            'reseller_id' => $this->reseller->id,
            'amount' => 500.00,
            'trx_type' => 'credit',
            'type' => 'wallet_topup',
            'status' => 'completed',
        ]);

        $initialBalance = $this->accountingService->calculateUserBalances($this->reseller->id);
        $this->assertEquals(500.00, $initialBalance['available_balance']);

        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-WLT-01',
            'status' => 1,
            'total' => 1130.00,
            'shipping_fee' => 130.00,
            'subtotal' => 1000.00,
        ]);

        $this->accountingService->payAdvanceFromWallet($order, 130.00);

        $order->refresh();
        $this->assertEquals(130.00, $order->advance_amount);
        $this->assertEquals('approved', $order->advance_status);
        $this->assertEquals('wallet', $order->advance_payment_method);
        $this->assertEquals(1000.00, $order->remaining_cod);

        // Check Reseller Transaction debit
        $this->assertDatabaseHas('reseller_transactions', [
            'reseller_id' => $this->reseller->id,
            'order_id' => $order->id,
            'amount' => 130.00,
            'trx_type' => 'debit',
            'type' => 'order_advance',
            'status' => 'completed',
        ]);

        // Available balance is now 500 - 130 = 370
        $newBalance = $this->accountingService->calculateUserBalances($this->reseller->id);
        $this->assertEquals(370.00, $newBalance['available_balance']);

        // Check Ledger
        $this->assertDatabaseHas('order_accounting_ledgers', [
            'order_id' => $order->id,
            'account_code' => 'cash_platform',
            'debit' => 130.00,
        ]);
    }

    public function test_advance_payment_via_mfs_submits_pending_and_admin_can_approve(): void
    {
        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-MFS-01',
            'status' => 1,
            'total' => 1130.00,
            'shipping_fee' => 130.00,
            'subtotal' => 1000.00,
        ]);

        $this->accountingService->submitMfsAdvance($order, 130.00, 'bkash', 'BKASH-TRX-9988', '01711223344');

        $order->refresh();
        $this->assertEquals(130.00, $order->advance_amount);
        $this->assertEquals('pending', $order->advance_status);
        $this->assertEquals('bkash', $order->advance_payment_method);
        $this->assertEquals('BKASH-TRX-9988', $order->advance_trx_id);
        $this->assertEquals('01711223344', $order->advance_sender_phone);
        $this->assertEquals(1000.00, $order->remaining_cod);

        // Check Reseller Transaction is pending
        $this->assertDatabaseHas('reseller_transactions', [
            'order_id' => $order->id,
            'status' => 'pending',
            'type' => 'order_advance',
        ]);

        // Admin quick-approves
        $this->accountingService->approveOrderAdvance($order, $this->admin->id, 'Verified via bKash statement');

        $order->refresh();
        $this->assertEquals('approved', $order->advance_status);
        $this->assertNotNull($order->advance_verified_at);
        $this->assertEquals($this->admin->id, $order->advance_verified_by);

        // Reseller Transaction is completed
        $this->assertDatabaseHas('reseller_transactions', [
            'order_id' => $order->id,
            'status' => 'completed',
            'type' => 'order_advance',
        ]);

        // Platform ledger debited
        $this->assertDatabaseHas('order_accounting_ledgers', [
            'order_id' => $order->id,
            'account_code' => 'cash_platform',
            'debit' => 130.00,
        ]);
    }

    public function test_admin_can_reject_advance_payment(): void
    {
        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-REJ-01',
            'status' => 1,
            'total' => 1130.00,
            'shipping_fee' => 130.00,
            'subtotal' => 1000.00,
        ]);

        $this->accountingService->submitMfsAdvance($order, 130.00, 'nagad', 'FAKE-TRX-0000');
        $this->accountingService->rejectOrderAdvance($order, $this->admin->id, 'Invalid transaction ID');

        $order->refresh();
        $this->assertEquals('rejected', $order->advance_status);
        $this->assertEquals('Invalid transaction ID', $order->advance_admin_note);
        $this->assertEquals(1130.00, $order->remaining_cod);

        $this->assertDatabaseHas('reseller_transactions', [
            'order_id' => $order->id,
            'status' => 'rejected',
        ]);
    }

    public function test_wallet_topup_submission_and_admin_approval_credits_balance(): void
    {
        $initialBalance = $this->accountingService->calculateUserBalances($this->reseller->id);
        $this->assertEquals(0.00, $initialBalance['available_balance']);

        // Reseller submits topup of ৳1,000
        $topup = WalletTopup::create([
            'user_id' => $this->reseller->id,
            'amount' => 1000.00,
            'payment_method' => 'bkash',
            'sender_phone' => '01899001122',
            'trx_id' => 'TOPUP-TRX-5544',
            'status' => 'pending',
        ]);

        // Balance is still 0
        $balancePending = $this->accountingService->calculateUserBalances($this->reseller->id);
        $this->assertEquals(0.00, $balancePending['available_balance']);

        // Admin approves
        $this->accountingService->approveWalletTopup($topup, $this->admin->id, 'Approved');

        $topup->refresh();
        $this->assertEquals('approved', $topup->status);
        $this->assertEquals($this->admin->id, $topup->approved_by);

        // Balance is now ৳1,000!
        $balanceApproved = $this->accountingService->calculateUserBalances($this->reseller->id);
        $this->assertEquals(1000.00, $balanceApproved['available_balance']);

        $this->assertDatabaseHas('reseller_transactions', [
            'reseller_id' => $this->reseller->id,
            'amount' => 1000.00,
            'trx_type' => 'credit',
            'type' => 'wallet_topup',
            'status' => 'completed',
        ]);
    }

    public function test_pre_dispatch_cancellation_refunds_advance_to_wallet(): void
    {
        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-REFUND-01',
            'status' => 1,
            'total' => 1130.00,
            'shipping_fee' => 130.00,
            'subtotal' => 1000.00,
            'advance_amount' => 130.00,
            'advance_status' => 'approved',
            'advance_payment_method' => 'bkash',
        ]);

        $this->accountingService->refundOrderAdvance($order);

        $this->assertDatabaseHas('reseller_transactions', [
            'reseller_id' => $this->reseller->id,
            'order_id' => $order->id,
            'amount' => 130.00,
            'trx_type' => 'credit',
            'type' => 'advance_refund',
            'status' => 'completed',
        ]);

        $balances = $this->accountingService->calculateUserBalances($this->reseller->id);
        $this->assertEquals(130.00, $balances['available_balance']);
    }

    public function test_admin_can_fetch_all_wallet_topups_api(): void
    {
        WalletTopup::create([
            'user_id' => $this->reseller->id,
            'amount' => 500.00,
            'payment_method' => 'bkash',
            'sender_phone' => '01700000000',
            'trx_id' => 'TOPUP-API-001',
            'status' => 'pending',
        ]);

        $tokenData = \App\Service\ApiTokenService::create($this->admin, ['basic', 'admin'], 60, 'admin-token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenData['plain'])
            ->getJson('/api/wallet/topup/all');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'pending_count' => 1,
            ],
        ]);
        $this->assertNotEmpty($response->json('data.items.data'));
    }
}
