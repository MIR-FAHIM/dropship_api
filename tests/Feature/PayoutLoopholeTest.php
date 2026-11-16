<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Models\WithdrawRequest;
use App\Service\ApiTokenService;
use App\Service\OrderAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every way a dropshipper could be paid twice, or paid money that isn't theirs,
 * found in the 2026-11 payout audit. Each test performs the exploit and asserts
 * it no longer works.
 */
class PayoutLoopholeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $rahim;
    private User $karim;
    private Vendor $vendor;
    private Product $product;
    private OrderAccountingService $acc;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1 => 'Pending', 2 => 'Assigned', 3 => 'Ready', 4 => 'Shipped', 5 => 'OFD', 6 => 'Delivered', 7 => 'Cancelled', 8 => 'Returned',
            9 => 'Completed', 10 => 'Carrybee Paid', 11 => 'Lost', 12 => 'Return in Transit', 13 => 'Partially Delivered'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'order', 'is_active' => true]);
        }
        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->rahim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->karim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $vu = User::factory()->create(['user_type' => 'vendor', 'role' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create(['user_id' => $vu->id, 'shop_name' => 'S', 'has_own_delivery_account' => false, 'is_active' => true]);
        $cat = Category::create(['name' => 'K', 'slug' => 'k-' . uniqid(), 'is_active' => true]);
        $this->product = Product::create(['name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'P1', 'unit_price' => 500, 'admin_price' => 600,
            'price' => 800, 'current_stock' => 10, 'category_id' => $cat->id, 'vendor_id' => $this->vendor->id, 'status' => 1]);
        $this->acc = app(OrderAccountingService::class);
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain']];
    }

    private function bal(User $u): float
    {
        return $this->acc->calculateUserBalances($u->id)['net_balance'];
    }

    private function credit(User $u, float $amount): void
    {
        ResellerTransaction::create(['reseller_id' => $u->id, 'amount' => $amount, 'trx_id' => 'SEED-' . uniqid(), 'trx_type' => 'credit',
            'type' => 'manual_seed', 'status' => 'completed']);
    }

    private function sent(User $u): float
    {
        return (float) WithdrawRequest::where('user_id', $u->id)->where('status', 'transferred')->sum('amount');
    }

    /** Delivered order; by default CarryBee has paid and delivery accounting has run. */
    private function order(User $u, int $status = 10, bool $paid = true, bool $consignment = true, bool $process = true): Order
    {
        $n = ++$this->seq;
        $o = Order::create(['user_id' => $u->id, 'order_number' => 'ORD-L-' . $n, 'status' => $status, 'payment_status' => 'paid',
            'customer_name' => 'C', 'customer_phone' => '017', 'shipping_address' => 'D', 'subtotal' => 800, 'reseller_price' => 800,
            'reseller_profit' => 200, 'shipping_fee' => 70, 'discount' => 0, 'total' => 870, 'remaining_cod' => 870, 'vendor_id' => $this->vendor->id,
            'carrybee_invoice_id' => $paid ? 'IX' . $n : null, 'carrybee_paid_at' => $paid ? now() : null]);
        OrderItem::create(['order_id' => $o->id, 'product_id' => $this->product->id, 'shop_id' => $this->vendor->id, 'product_name' => 'P', 'sku' => 'P1',
            'unit_price' => 500, 'admin_price' => 600, 'reseller_price' => 800, 'qty' => 1, 'line_total' => 800, 'line_total_reseller_profit' => 200, 'status' => 1]);
        if ($consignment) {
            DeliveryAssignedInfo::create(['order_id' => $o->id, 'consignment_id' => 'FXL' . $n, 'merchant_order_id' => $o->order_number, 'delivery_company_id' => 1,
                'recipient_name' => 'C', 'recipient_phone' => '88017', 'recipient_address' => 'D', 'collectable_amount' => 870, 'delivery_fee' => 60, 'total_fee' => 68.7]);
        }
        if ($process && $paid) {
            $this->acc->processOrderDelivery($o->fresh(), ['confirmed' => true, 'payout_status' => 'received']);
        }

        return $o->fresh();
    }

    private function payDirect(Order $o, array $extra = [])
    {
        return $this->postJson("/api/order-settlements/orders/{$o->id}/disburse-reseller-profit",
            $extra + ['payment_method' => 'wallet', 'payout_review_confirmed' => true], $this->h($this->admin));
    }

    /* ---------- 1 & 6: money routes are admin-only ---------- */

    public function test_dropshipper_cannot_approve_or_reject_wallet_topups(): void
    {
        $topup = WalletTopup::create(['user_id' => $this->rahim->id, 'amount' => 5000, 'payment_method' => 'bkash', 'trx_id' => 'FAKE-TRX', 'status' => 'pending']);

        $this->postJson("/api/wallet/topup/{$topup->id}/approve", [], $this->h($this->rahim))->assertForbidden();
        $this->postJson("/api/wallet/topup/{$topup->id}/reject", ['reason' => 'x'], $this->h($this->rahim))->assertForbidden();
        $this->getJson('/api/wallet/topup/all', $this->h($this->rahim))->assertForbidden();

        $this->assertSame('pending', $topup->fresh()->status);
        $this->assertEquals(0.0, $this->bal($this->rahim));
    }

    public function test_dropshipper_cannot_touch_settlements_withdraw_statuses_or_breakdowns(): void
    {
        $o = $this->order($this->rahim);
        $s = OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)->firstOrFail();
        $this->credit($this->rahim, 500);
        $w = $this->postJson('/api/withdraws/add', ['amount' => 500], $this->h($this->rahim))->json('data.id');

        // Settle own profit into the wallet / reopen it.
        $this->postJson("/api/order-settlements/settle-now/{$s->id}", [], $this->h($this->rahim))->assertForbidden();
        $this->postJson("/api/order-settlements/settle-nowe/{$s->id}", ['status' => 'pending'], $this->h($this->rahim))->assertForbidden();
        $this->postJson("/api/order-settlements/add-settled-trx-id/{$s->id}", ['settled_trx_id' => 'X'], $this->h($this->rahim))->assertForbidden();
        // Change anyone's withdraw request, or list everyone's.
        $this->postJson("/api/withdraws/status-change/{$w}", ['status' => 'rejected'], $this->h($this->karim))->assertForbidden();
        $this->postJson("/api/withdraws/status-change/{$w}", ['status' => 'transferred'], $this->h($this->rahim))->assertForbidden();
        $this->getJson('/api/withdraws/all', $this->h($this->rahim))->assertForbidden();
        $this->getJson("/api/withdraws/user/{$this->rahim->id}", $this->h($this->karim))->assertForbidden();
        $this->getJson("/api/withdraws/user/{$this->rahim->id}", $this->h($this->rahim))->assertOk();
        // Rewrite an order's money breakdown, or create/approve vendor invoices.
        $this->postJson("/api/order-settlements/orders/{$o->id}/update-breakdown", ['dropshipper_profit' => 9999], $this->h($this->rahim))->assertForbidden();
        $this->postJson('/api/order-settlements/vendor-invoice', ['vendor_id' => $this->vendor->id, 'order_ids' => [$o->id]], $this->h($this->rahim))->assertForbidden();
        $this->postJson('/api/withdraws/admin-payout', ['user_id' => $this->rahim->id, 'amount' => 1, 'payment_method' => 'cash'], $this->h($this->rahim))->assertForbidden();

        $this->assertSame(OrderSettlement::STATUS_PENDING, $s->fresh()->status);
        $this->assertSame('pending', WithdrawRequest::find($w)->status);
        $this->assertEquals(500.0, $this->bal($this->rahim));
    }

    /* ---------- 2 & 10: withdrawals ---------- */

    public function test_approved_withdraw_still_holds_the_balance(): void
    {
        $this->credit($this->rahim, 1000);
        $a = $this->h($this->admin);
        $w1 = $this->postJson('/api/withdraws/add', ['amount' => 1000], $this->h($this->rahim))->assertCreated()->json('data.id');
        $this->postJson("/api/withdraws/status-change/{$w1}", ['status' => 'approved'], $a)->assertOk();

        // Used to be accepted once the first request was "approved": the same ৳1,000 twice.
        $this->postJson('/api/withdraws/add', ['amount' => 1000], $this->h($this->rahim))->assertStatus(422);
        $this->postJson('/api/withdraws/add', ['amount' => 1], $this->h($this->rahim))->assertStatus(422);

        $this->postJson("/api/withdraws/status-change/{$w1}", ['status' => 'transferred', 'bank_reference' => 'B1'], $a)->assertOk();
        $this->assertEquals(1000.0, $this->sent($this->rahim));
        $this->assertEquals(0.0, $this->bal($this->rahim));
    }

    public function test_rejected_or_transferred_withdraw_cannot_be_revived(): void
    {
        $this->credit($this->rahim, 500);
        $a = $this->h($this->admin);
        $w = $this->postJson('/api/withdraws/add', ['amount' => 500], $this->h($this->rahim))->json('data.id');
        $this->postJson("/api/withdraws/status-change/{$w}", ['status' => 'rejected'], $a)->assertOk();

        // Rejected: can't be transferred, approved or reopened.
        foreach (['transferred', 'approved', 'pending'] as $status) {
            $this->postJson("/api/withdraws/status-change/{$w}", ['status' => $status, 'bank_reference' => 'B'], $a)->assertStatus(422);
        }
        $this->assertSame('rejected', WithdrawRequest::find($w)->status);
        $this->assertEquals(500.0, $this->bal($this->rahim));

        // Transferred: final.
        $w2 = $this->postJson('/api/withdraws/add', ['amount' => 500], $this->h($this->rahim))->json('data.id');
        $this->postJson("/api/withdraws/status-change/{$w2}", ['status' => 'transferred', 'bank_reference' => 'B2'], $a)->assertOk();
        foreach (['rejected', 'pending', 'approved'] as $status) {
            $this->postJson("/api/withdraws/status-change/{$w2}", ['status' => $status], $a)->assertStatus(422);
        }
        $this->assertSame('transferred', WithdrawRequest::find($w2)->status);
        $this->assertEquals(500.0, $this->sent($this->rahim));
        $this->assertEquals(0.0, $this->bal($this->rahim));
    }

    public function test_transfer_is_refused_when_the_balance_dropped_after_the_request(): void
    {
        $this->credit($this->rahim, 500);
        $a = $this->h($this->admin);
        $w = $this->postJson('/api/withdraws/add', ['amount' => 500], $this->h($this->rahim))->json('data.id');
        // Balance drops (e.g. a profit clawback) while the request waits.
        ResellerTransaction::create(['reseller_id' => $this->rahim->id, 'amount' => 200, 'trx_id' => 'CB', 'trx_type' => 'debit', 'type' => 'profit_clawback', 'status' => 'completed']);

        $this->postJson("/api/withdraws/status-change/{$w}", ['status' => 'transferred', 'bank_reference' => 'B'], $a)->assertStatus(422);
        $this->assertEquals(0.0, $this->sent($this->rahim));
    }

    /* ---------- 3 & 9: advances ---------- */

    public function test_bkash_advance_is_not_credited_twice_on_delivery(): void
    {
        $o = $this->order($this->rahim, 1, false, false, false);
        $this->acc->submitMfsAdvance($o, 130, 'bkash', 'ADV-1');
        $this->acc->approveOrderAdvance($o, $this->admin->id);
        $this->assertEquals(130.0, $this->bal($this->rahim), 'the deposit is in the wallet after approval');

        $o->update(['status' => 6]);
        $this->acc->processOrderDelivery($o->fresh(), ['confirmed' => true, 'payout_status' => 'received']);

        // Paid ৳130 once -> ৳130 in the wallet, not ৳260.
        $this->assertEquals(130.0, $this->bal($this->rahim));
        $this->assertDatabaseHas('reseller_transactions', ['order_id' => $o->id, 'type' => 'advance_refund', 'status' => 'released']);
    }

    public function test_wallet_advance_is_still_released_once_on_delivery(): void
    {
        $this->credit($this->rahim, 500);
        $o = $this->order($this->rahim, 1, false, false, false);
        $this->acc->payAdvanceFromWallet($o, 130);
        $this->assertEquals(370.0, $this->bal($this->rahim));

        $o->update(['status' => 6]);
        $this->acc->processOrderDelivery($o->fresh(), ['confirmed' => true, 'payout_status' => 'received']);
        $this->acc->refundOrderAdvance($o->fresh(), 'delivered'); // a second call must not credit again

        $this->assertEquals(500.0, $this->bal($this->rahim));
    }

    public function test_one_payment_trx_id_cannot_back_several_deposits(): void
    {
        $o1 = $this->order($this->rahim, 1, false, false, false);
        $o2 = $this->order($this->rahim, 1, false, false, false);
        $h = $this->h($this->rahim);
        $advance = ['payment_method' => 'bkash', 'trx_id' => 'SAME-TRX', 'amount' => 130, 'sender_phone' => '017'];

        $this->postJson("/api/orders/{$o1->id}/pay-advance", $advance, $h)->assertOk();
        $this->postJson("/api/orders/{$o2->id}/pay-advance", $advance, $h)->assertStatus(422);
        $this->postJson('/api/wallet/topup', ['amount' => 130, 'payment_method' => 'bkash', 'trx_id' => 'SAME-TRX'], $h)->assertStatus(422);

        // And the other way round: a top-up's trx can't become an order advance.
        $this->postJson('/api/wallet/topup', ['amount' => 200, 'payment_method' => 'bkash', 'trx_id' => 'TOPUP-TRX'], $h)->assertCreated();
        $this->postJson("/api/orders/{$o2->id}/pay-advance", ['trx_id' => 'TOPUP-TRX'] + $advance, $h)->assertStatus(422);
        // Resubmitting the same order with its own trx is fine.
        $this->postJson("/api/orders/{$o1->id}/pay-advance", $advance, $h)->assertOk();
    }

    /* ---------- 4: top-ups ---------- */

    public function test_topup_approve_reject_approve_credits_once(): void
    {
        $topup = WalletTopup::create(['user_id' => $this->rahim->id, 'amount' => 1000, 'payment_method' => 'bkash', 'trx_id' => 'T2', 'status' => 'pending']);
        $a = $this->h($this->admin);

        $this->postJson("/api/wallet/topup/{$topup->id}/approve", [], $a)->assertOk();
        $this->assertEquals(1000.0, $this->bal($this->rahim));

        // Rejecting an approved top-up takes the money back out.
        $this->postJson("/api/wallet/topup/{$topup->id}/reject", ['reason' => 'mistake'], $a)->assertOk();
        $this->assertEquals(0.0, $this->bal($this->rahim));

        // Approving again restores it once, not twice.
        $this->postJson("/api/wallet/topup/{$topup->id}/approve", [], $a)->assertOk();
        $this->assertEquals(1000.0, $this->bal($this->rahim));
        $this->assertSame(1, ResellerTransaction::where('type', 'wallet_topup')->where('ref_id', $topup->id)->count());
    }

    public function test_approved_topup_cannot_be_rejected_after_the_money_was_withdrawn(): void
    {
        $topup = WalletTopup::create(['user_id' => $this->rahim->id, 'amount' => 1000, 'payment_method' => 'bkash', 'trx_id' => 'T3', 'status' => 'pending']);
        $a = $this->h($this->admin);
        $this->postJson("/api/wallet/topup/{$topup->id}/approve", [], $a)->assertOk();
        $w = $this->postJson('/api/withdraws/add', ['amount' => 1000], $this->h($this->rahim))->json('data.id');
        $this->postJson("/api/withdraws/status-change/{$w}", ['status' => 'transferred', 'bank_reference' => 'B'], $a)->assertOk();

        $this->postJson("/api/wallet/topup/{$topup->id}/reject", ['reason' => 'fake trx'], $a)->assertStatus(422);
        $this->assertSame('approved', $topup->fresh()->status);
    }

    /* ---------- 5: single-order payout ---------- */

    public function test_direct_payout_refuses_unearned_profit(): void
    {
        $outForDelivery = $this->order($this->rahim, 5, false);
        $cancelled = $this->order($this->rahim, 7, false);
        $returning = $this->order($this->rahim, 12, false);
        $onHold = $this->order($this->rahim);
        DeliveryAssignedInfo::where('order_id', $onHold->id)->update(['needs_review' => true, 'review_reason' => 'Collected less']);

        foreach ([$outForDelivery, $cancelled, $returning, $onHold] as $o) {
            $this->payDirect($o)->assertStatus(400);
        }
        $this->assertEquals(0.0, $this->bal($this->rahim));
    }

    public function test_direct_payout_above_calculated_profit_needs_a_reason(): void
    {
        $o = $this->order($this->rahim);

        $this->payDirect($o, ['amount' => 5000])->assertStatus(400);
        $this->assertEquals(0.0, $this->bal($this->rahim));

        $this->payDirect($o, ['amount' => 250, 'amount_reason' => 'Bonus agreed for bulk sale'])->assertOk();
        $this->assertEquals(250.0, $this->bal($this->rahim));
        $this->assertStringContainsString('Bonus agreed for bulk sale',
            OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)->value('admin_note'));
    }

    /* ---------- 7: paid, then returned ---------- */

    public function test_profit_is_taken_back_when_a_paid_order_is_returned(): void
    {
        $o = $this->order($this->rahim);
        $this->payDirect($o)->assertOk();
        $paid = $this->bal($this->rahim);
        $this->assertGreaterThan(0, $paid);

        $this->postJson("/api/orders/update/status/{$o->id}", ['status_id' => 8, 'override_reason' => 'customer returned later'], $this->h($this->admin))->assertOk();
        $this->assertEquals(0.0, $this->bal($this->rahim));
        $this->assertDatabaseHas('reseller_transactions', ['order_id' => $o->id, 'type' => 'profit_clawback', 'trx_type' => 'debit', 'amount' => $paid]);

        // Only once.
        $this->assertSame(0.0, $this->acc->clawbackResellerProfit($o->fresh(), 'again'));
        $this->assertEquals(0.0, $this->bal($this->rahim));
    }

    public function test_clawback_after_bkash_payout_leaves_a_debt_that_blocks_withdrawals(): void
    {
        $o = $this->order($this->rahim);
        $this->payDirect($o, ['payment_method' => 'bkash', 'trx_id' => 'B1'])->assertOk();
        $sent = $this->sent($this->rahim);
        $this->assertEquals(0.0, $this->bal($this->rahim));

        $this->postJson("/api/orders/update/status/{$o->id}", ['status_id' => 8, 'override_reason' => 'returned'], $this->h($this->admin))->assertOk();

        // The money already left by bKash, so the wallet now owes it.
        $this->assertEquals(-$sent, $this->bal($this->rahim));
        $this->credit($this->rahim, 100);
        $this->postJson('/api/withdraws/add', ['amount' => 100], $this->h($this->rahim))->assertStatus(422);
    }

    /* ---------- "blocked by luck" cases now refuse cleanly ---------- */

    public function test_second_payout_after_carrybee_reset_is_refused_with_a_clear_message(): void
    {
        $o = $this->order($this->rahim);
        $a = $this->h($this->admin);
        $this->payDirect($o, ['payment_method' => 'bkash', 'trx_id' => 'B1'])->assertOk();
        $sent = $this->sent($this->rahim);

        $this->postJson("/api/delivery-companies/carrybee/manual-order-payment/{$o->id}", ['action' => 'reset'], $a)->assertOk();
        $this->postJson("/api/delivery-companies/carrybee/manual-order-payment/{$o->id}", ['action' => 'mark_paid', 'invoice_id' => 'IXNEW'], $a)->assertOk();

        $again = $this->payDirect($o, ['payment_method' => 'bkash', 'trx_id' => 'B2'])->assertStatus(400);
        $this->assertStringContainsString('already paid', $again->json('message'));
        $this->assertEquals($sent, $this->sent($this->rahim));

        // Not offered in the payout invoice modal either.
        $this->getJson('/api/dropshipper-payouts/payable-orders?dropshipper_id=' . $this->rahim->id, $a)
            ->assertOk()->assertJsonPath('data.payable_count', 0)->assertJsonCount(0, 'data.rows');
    }

    /* ---------- 8: record a wallet payout made by hand ---------- */

    public function test_admin_records_a_manual_wallet_payout_so_the_balance_drops(): void
    {
        $o = $this->order($this->rahim);
        $this->payDirect($o)->assertOk();
        $balance = $this->bal($this->rahim);
        $a = $this->h($this->admin);
        $body = ['user_id' => $this->rahim->id, 'payment_method' => 'bkash', 'bank_reference' => 'BK-HAND-1', 'note' => 'Paid on request'];

        // Needs a trx id; can't exceed the wallet.
        $this->postJson('/api/withdraws/admin-payout', ['amount' => $balance, 'bank_reference' => null] + $body, $a)->assertStatus(422);
        $this->postJson('/api/withdraws/admin-payout', ['amount' => $balance + 1] + $body, $a)->assertStatus(422);

        $this->postJson('/api/withdraws/admin-payout', ['amount' => $balance] + $body, $a)
            ->assertCreated()->assertJsonPath('balance.net_balance', 0);

        $this->assertEquals(0.0, $this->bal($this->rahim));
        $this->assertEquals($balance, $this->sent($this->rahim));
        $this->assertDatabaseHas('withdraw_requests', ['user_id' => $this->rahim->id, 'type' => 'admin_wallet_payout', 'status' => 'transferred', 'bank_reference' => 'BK-HAND-1']);
        // The dropshipper can't withdraw the same money again.
        $this->postJson('/api/withdraws/add', ['amount' => $balance], $this->h($this->rahim))->assertStatus(422);
    }

    public function test_manual_wallet_payout_respects_money_held_by_open_requests(): void
    {
        $this->credit($this->rahim, 1000);
        $this->postJson('/api/withdraws/add', ['amount' => 700], $this->h($this->rahim))->assertCreated();

        $body = ['user_id' => $this->rahim->id, 'payment_method' => 'cash'];
        $this->postJson('/api/withdraws/admin-payout', ['amount' => 400] + $body, $this->h($this->admin))->assertStatus(422);
        $this->postJson('/api/withdraws/admin-payout', ['amount' => 300] + $body, $this->h($this->admin))->assertCreated();
        $this->assertEquals(700.0, $this->bal($this->rahim));
    }
}
