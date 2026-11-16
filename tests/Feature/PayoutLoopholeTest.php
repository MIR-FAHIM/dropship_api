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
 * PROBE, not a spec: each test tries a suspected way to over-pay a dropshipper and
 * prints what the system does today. Used for the payout loophole audit.
 */
class PayoutLoopholeProbeTest extends TestCase
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

    private function say(string $line): void
    {
        fwrite(STDERR, "\nPROBE | " . $line);
    }

    private function bal(User $u): float
    {
        return $this->acc->calculateUserBalances($u->id)['net_balance'];
    }

    private function credit(User $u, float $amount): void
    {
        ResellerTransaction::create(['reseller_id' => $u->id, 'amount' => $amount, 'trx_id' => 'SEED-' . uniqid(), 'trx_type' => 'credit',
            'type' => 'wallet_topup', 'status' => 'completed']);
    }

    private function order(User $u, int $status = 10, bool $paid = true, bool $consignment = true): Order
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

        return $o->fresh();
    }

    public function test_01_dropshipper_approves_own_wallet_topup(): void
    {
        $topup = WalletTopup::create(['user_id' => $this->rahim->id, 'amount' => 5000, 'payment_method' => 'bkash', 'trx_id' => 'FAKE-TRX', 'status' => 'pending']);
        $res = $this->postJson("/api/wallet/topup/{$topup->id}/approve", [], $this->h($this->rahim));
        $this->say("01 dropshipper approves OWN topup -> HTTP {$res->status()}, wallet balance now " . $this->bal($this->rahim));
        $this->assertTrue(true);
    }

    public function test_02_topup_approve_reject_approve(): void
    {
        $topup = WalletTopup::create(['user_id' => $this->rahim->id, 'amount' => 1000, 'payment_method' => 'bkash', 'trx_id' => 'T2', 'status' => 'pending']);
        $a = $this->h($this->admin);
        $this->postJson("/api/wallet/topup/{$topup->id}/approve", [], $a);
        $r = $this->postJson("/api/wallet/topup/{$topup->id}/reject", ['reason' => 'mistake'], $a);
        $afterReject = $this->bal($this->rahim);
        $this->postJson("/api/wallet/topup/{$topup->id}/approve", [], $a);
        $this->say("02 topup 1000: approve -> reject (HTTP {$r->status()}, balance {$afterReject}) -> approve again: balance " . $this->bal($this->rahim));
        $this->assertTrue(true);
    }

    public function test_03_mfs_advance_then_delivery(): void
    {
        $o = $this->order($this->rahim, 1, false, false);
        $this->acc->submitMfsAdvance($o, 130, 'bkash', 'ADV-1');
        $this->acc->approveOrderAdvance($o, $this->admin->id);
        $afterApprove = $this->bal($this->rahim);
        $avail = $this->acc->calculateUserBalances($this->rahim->id)['available_balance'];
        $o->update(['status' => 6]);
        $this->acc->processOrderDelivery($o->fresh(), ['confirmed' => true, 'payout_status' => 'received']);
        $this->say("03 paid 130 advance by bKash: after approval balance {$afterApprove} (withdrawable {$avail}); after delivery balance " . $this->bal($this->rahim)
            . ' | advance rows: ' . ResellerTransaction::where('order_id', $o->id)->whereIn('type', ['order_advance', 'advance_refund'])->get()->map(fn ($t) => "{$t->type}:{$t->trx_type}:{$t->amount}:{$t->status}")->implode(', '));
        $this->assertTrue(true);
    }

    public function test_04_same_advance_trx_on_two_orders(): void
    {
        $o1 = $this->order($this->rahim, 1, false, false);
        $o2 = $this->order($this->rahim, 1, false, false);
        $h = $this->h($this->rahim);
        $r1 = $this->postJson("/api/orders/{$o1->id}/pay-advance", ['payment_method' => 'bkash', 'trx_id' => 'SAME-TRX', 'amount' => 130, 'sender_phone' => '017'], $h);
        $r2 = $this->postJson("/api/orders/{$o2->id}/pay-advance", ['payment_method' => 'bkash', 'trx_id' => 'SAME-TRX', 'amount' => 130, 'sender_phone' => '017'], $h);
        $t = $this->postJson('/api/wallet/topup', ['amount' => 130, 'payment_method' => 'bkash', 'trx_id' => 'SAME-TRX'], $h);
        $this->say("04 same bKash trx id used for 2 order advances + 1 topup -> HTTP {$r1->status()}, {$r2->status()}, topup {$t->status()}");
        $this->assertTrue(true);
    }

    public function test_05_second_withdraw_while_first_is_approved(): void
    {
        $this->credit($this->rahim, 1000);
        $h = $this->h($this->rahim);
        $a = $this->h($this->admin);
        $w1 = $this->postJson('/api/withdraws/add', ['amount' => 1000], $h)->json('data.id');
        $blocked = $this->postJson('/api/withdraws/add', ['amount' => 1000], $h)->status();
        $this->postJson("/api/withdraws/status-change/{$w1}", ['status' => 'approved'], $a);
        $second = $this->postJson('/api/withdraws/add', ['amount' => 1000], $h);
        $w2 = $second->json('data.id');
        $this->postJson("/api/withdraws/status-change/{$w1}", ['status' => 'transferred', 'bank_reference' => 'B1'], $a);
        if ($w2) {
            $this->postJson("/api/withdraws/status-change/{$w2}", ['status' => 'transferred', 'bank_reference' => 'B2'], $a);
        }
        $this->say("05 balance 1000: 2nd request while 1st pending -> HTTP {$blocked}; after admin APPROVES 1st, 2nd request -> HTTP {$second->status()}; both transferred -> balance " . $this->bal($this->rahim)
            . ', total sent ' . WithdrawRequest::where('user_id', $this->rahim->id)->where('status', 'transferred')->sum('amount'));
        $this->assertTrue(true);
    }

    public function test_06_transfer_rejected_withdraw_and_dropshipper_changes_status(): void
    {
        $this->credit($this->rahim, 500);
        $w = $this->postJson('/api/withdraws/add', ['amount' => 500], $this->h($this->rahim))->json('data.id');
        $byDropshipper = $this->postJson("/api/withdraws/status-change/{$w}", ['status' => 'rejected'], $this->h($this->karim));
        $this->postJson('/api/withdraws/add', ['amount' => 500], $this->h($this->rahim));
        $t = $this->postJson("/api/withdraws/status-change/{$w}", ['status' => 'transferred', 'bank_reference' => 'B'], $this->h($this->admin));
        $this->say("06 ANOTHER dropshipper rejects rahim's withdraw -> HTTP {$byDropshipper->status()}; admin then transfers that REJECTED request -> HTTP {$t->status()}, status "
            . WithdrawRequest::find($w)->status . ', balance ' . $this->bal($this->rahim) . ', open pending: ' . WithdrawRequest::where('user_id', $this->rahim->id)->where('status', 'pending')->sum('amount'));
        $this->assertTrue(true);
    }

    public function test_07_dropshipper_settles_own_profit(): void
    {
        $o = $this->order($this->rahim);
        $this->acc->processOrderDelivery($o, ['confirmed' => true, 'payout_status' => 'received']);
        $s = OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)->first();
        $res = $this->postJson("/api/order-settlements/settle-now/{$s->id}", [], $this->h($this->rahim));
        $this->say("07 dropshipper calls settle-now on own profit settlement -> HTTP {$res->status()}, settlement {$s->fresh()->status}, wallet " . $this->bal($this->rahim));
        $this->assertTrue(true);
    }

    public function test_08_direct_payout_without_carrybee_paid_and_over_amount(): void
    {
        $notPaid = $this->order($this->rahim, 5, false);          // still out for delivery
        $cancelled = $this->order($this->rahim, 7, false);        // cancelled
        $a = $this->h($this->admin);
        $body = ['amount' => 5000, 'payment_method' => 'wallet', 'payout_review_confirmed' => true];
        $r1 = $this->postJson("/api/order-settlements/orders/{$notPaid->id}/disburse-reseller-profit", $body, $a);
        $r2 = $this->postJson("/api/order-settlements/orders/{$cancelled->id}/disburse-reseller-profit", $body, $a);
        $this->say("08 direct payout of 5000 (real profit ~190): order still OUT FOR DELIVERY -> HTTP {$r1->status()}; CANCELLED order -> HTTP {$r2->status()}; wallet " . $this->bal($this->rahim));
        $this->assertTrue(true);
    }

    public function test_09_paid_then_returned(): void
    {
        $o = $this->order($this->rahim);
        $this->acc->processOrderDelivery($o, ['confirmed' => true, 'payout_status' => 'received']);
        $this->postJson("/api/order-settlements/orders/{$o->id}/disburse-reseller-profit", ['payment_method' => 'wallet', 'payout_review_confirmed' => true], $this->h($this->admin));
        $paid = $this->bal($this->rahim);
        $this->postJson("/api/orders/update/status/{$o->id}", ['status_id' => 8, 'override_reason' => 'customer returned later'], $this->h($this->admin));
        $this->say("09 profit paid to wallet ({$paid}), then order marked RETURNED -> wallet " . $this->bal($this->rahim)
            . ', settlement ' . OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)->value('status'));
        $this->assertTrue(true);
    }

    public function test_10_reset_carrybee_paid_then_pay_again(): void
    {
        $o = $this->order($this->rahim);
        $a = $this->h($this->admin);
        $this->acc->processOrderDelivery($o, ['confirmed' => true, 'payout_status' => 'received']);
        $this->postJson("/api/order-settlements/orders/{$o->id}/disburse-reseller-profit", ['payment_method' => 'bkash', 'trx_id' => 'B1', 'payout_review_confirmed' => true], $a);
        $sent1 = WithdrawRequest::where('user_id', $this->rahim->id)->sum('amount');
        $reset = $this->postJson("/api/delivery-companies/carrybee/manual-order-payment/{$o->id}", ['action' => 'reset'], $a);
        $mark = $this->postJson("/api/delivery-companies/carrybee/manual-order-payment/{$o->id}", ['action' => 'mark_paid', 'invoice_id' => 'IXNEW'], $a);
        $s = OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)->first();
        $again = $this->postJson("/api/order-settlements/orders/{$o->id}/disburse-reseller-profit", ['payment_method' => 'bkash', 'trx_id' => 'B2', 'payout_review_confirmed' => true], $a);
        $payable = $this->getJson('/api/dropshipper-payouts/payable-orders?dropshipper_id=' . $this->rahim->id, $a)->json('data.payable_count');
        $this->say("10 paid {$sent1} by bKash; admin RESETS CarryBee paid (HTTP {$reset->status()}) then marks paid again (HTTP {$mark->status()}): settlement now {$s?->status}; "
            . "2nd direct payout -> HTTP {$again->status()} ({$again->json('message')}); shows as payable in invoice modal: {$payable}; total sent " . WithdrawRequest::where('user_id', $this->rahim->id)->sum('amount'));
        $this->assertTrue(true);
    }

    public function test_11_wallet_profit_then_manual_payment_outside_system(): void
    {
        $o = $this->order($this->rahim);
        $this->acc->processOrderDelivery($o, ['confirmed' => true, 'payout_status' => 'received']);
        $a = $this->h($this->admin);
        $this->postJson("/api/order-settlements/orders/{$o->id}/disburse-reseller-profit", ['payment_method' => 'wallet', 'payout_review_confirmed' => true], $a);
        $balance = $this->bal($this->rahim);
        // Admin pays the same money by hand on bKash and wants to record it. Can admin create a withdraw for the dropshipper?
        $res = $this->postJson('/api/withdraws/add', ['amount' => $balance, 'user_id' => $this->rahim->id, 'note' => 'paid by hand'], $a);
        $owner = WithdrawRequest::find($res->json('data.id'))?->user_id;
        $this->say("11 profit {$balance} sits in wallet; admin tries to record a manual payment for the dropshipper -> HTTP {$res->status()} "
            . ($owner ? "(request created for user #{$owner}; dropshipper is #{$this->rahim->id}, admin is #{$this->admin->id})" : "({$res->json('message')})")
            . '; dropshipper wallet still ' . $this->bal($this->rahim));
        $this->assertTrue(true);
    }

    public function test_12_settlement_reopened_by_dropshipper(): void
    {
        $o = $this->order($this->rahim);
        $this->acc->processOrderDelivery($o, ['confirmed' => true, 'payout_status' => 'received']);
        $a = $this->h($this->admin);
        $this->postJson("/api/order-settlements/orders/{$o->id}/disburse-reseller-profit", ['payment_method' => 'bkash', 'trx_id' => 'B1', 'payout_review_confirmed' => true], $a);
        $s = OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)->first();
        $reopen = $this->postJson("/api/order-settlements/settle-now/{$s->id}", ['status' => 'pending'], $this->h($this->rahim));
        $again = $this->postJson("/api/order-settlements/orders/{$o->id}/disburse-reseller-profit", ['payment_method' => 'bkash', 'trx_id' => 'B2', 'payout_review_confirmed' => true], $a);
        $this->say("12 paid by bKash; DROPSHIPPER sets own settlement back to pending -> HTTP {$reopen->status()} (now {$s->fresh()->status}); admin pays again -> HTTP {$again->status()} ({$again->json('message')}); total sent "
            . WithdrawRequest::where('user_id', $this->rahim->id)->sum('amount'));
        $this->assertTrue(true);
    }
}
