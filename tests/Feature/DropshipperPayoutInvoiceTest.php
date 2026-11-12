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
use App\Models\SettlementInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WithdrawRequest;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DropshipperPayoutInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $rahim;
    private User $karim;
    private Vendor $vendor;
    private Product $product;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'Pending', 2 => 'Assigned To Vendor', 3 => 'Vendor Is Ready', 4 => 'Shipped', 5 => 'Out for Delivery', 6 => 'Delivered',
            7 => 'Cancelled', 8 => 'Returned', 9 => 'Completed', 10 => 'Carrybee Paid', 11 => 'Lost / Damaged', 12 => 'Return in Transit', 13 => 'Partially Delivered'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'order', 'is_active' => true]);
        }

        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->rahim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->karim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $vendorUser = User::factory()->create(['user_type' => 'vendor', 'role' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create(['user_id' => $vendorUser->id, 'shop_name' => 'Shop', 'has_own_delivery_account' => false, 'is_active' => true]);
        $category = Category::create(['name' => 'Kitchen', 'slug' => 'kitchen-' . uniqid(), 'is_active' => true]);
        $this->product = Product::create([
            'name' => 'Rice Cooker', 'slug' => 'rc-' . uniqid(), 'sku' => 'RC-1', 'unit_price' => 500, 'admin_price' => 600, 'price' => 800,
            'current_stock' => 10, 'category_id' => $category->id, 'vendor_id' => $this->vendor->id, 'status' => 1,
        ]);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($user, ['basic'])['plain']];
    }

    /** A delivered order with a CarryBee consignment; $carrybeePaid decides if CarryBee has paid. */
    private function order(User $dropshipper, bool $carrybeePaid = true, int $status = 10): Order
    {
        $n = ++$this->seq;
        $order = Order::create([
            'user_id' => $dropshipper->id, 'order_number' => 'ORD-P-' . $n, 'status' => $carrybeePaid ? $status : 6,
            'payment_status' => 'paid', 'customer_name' => 'Customer ' . $n, 'customer_phone' => '0171111111' . $n,
            'shipping_address' => 'Dhaka', 'zone' => 'Inside District',
            'subtotal' => 800, 'reseller_price' => 800, 'reseller_profit' => 200,
            'shipping_fee' => 70, 'discount' => 0, 'total' => 870, 'remaining_cod' => 870, 'vendor_id' => $this->vendor->id,
            'carrybee_invoice_id' => $carrybeePaid ? 'IX' . $n : null,
            'carrybee_paid_at' => $carrybeePaid ? now() : null,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'shop_id' => $this->vendor->id, 'product_name' => 'Rice Cooker', 'sku' => 'RC-1',
            'unit_price' => 500, 'admin_price' => 600, 'reseller_price' => 800, 'qty' => 1, 'line_total' => 800, 'line_total_reseller_profit' => 200, 'status' => 1,
        ]);
        DeliveryAssignedInfo::create([
            'order_id' => $order->id, 'consignment_id' => 'FXP' . $n, 'merchant_order_id' => $order->order_number, 'delivery_company_id' => 1,
            'recipient_name' => 'C', 'recipient_phone' => '8801711111111', 'recipient_address' => 'Dhaka',
            'collectable_amount' => 870, 'delivery_fee' => 60, 'total_fee' => 68.70,
        ]);

        return $order->fresh();
    }

    private function payable(User $dropshipper): array
    {
        return $this->getJson('/api/dropshipper-payouts/payable-orders?dropshipper_id=' . $dropshipper->id, $this->headers($this->admin))
            ->assertOk()->json('data');
    }

    private function balance(User $user): float
    {
        $q = ResellerTransaction::where('reseller_id', $user->id)->where('status', 'completed');

        return round((float) (clone $q)->where('trx_type', 'credit')->sum('amount') - (float) (clone $q)->where('trx_type', 'debit')->sum('amount'), 2);
    }

    public function test_payable_orders_lists_only_this_dropshippers_unpaid_profit_with_reasons(): void
    {
        $paid = $this->order($this->rahim);
        $waiting = $this->order($this->rahim, carrybeePaid: false);
        $this->order($this->karim);

        $data = $this->payable($this->rahim);
        $rows = collect($data['rows'])->keyBy('order_id');

        $this->assertCount(2, $rows);
        $this->assertTrue($rows[$paid->id]['payable']);
        $this->assertGreaterThan(0, $rows[$paid->id]['profit']);
        $this->assertFalse($rows[$waiting->id]['payable']);
        $this->assertSame('CarryBee has not paid yet', $rows[$waiting->id]['reason']);
        $this->assertSame(1, $data['payable_count']);
        $this->assertEquals($rows[$paid->id]['profit'], $data['payable_total']);

        $this->getJson('/api/dropshipper-payouts/payable-orders?dropshipper_id=' . $this->rahim->id, $this->headers($this->rahim))->assertForbidden();
    }

    public function test_paying_several_orders_by_bkash_settles_them_with_one_transfer(): void
    {
        $a = $this->order($this->rahim);
        $b = $this->order($this->rahim);
        $total = $this->payable($this->rahim)['payable_total'];

        $body = ['dropshipper_id' => $this->rahim->id, 'order_ids' => [$a->id, $b->id], 'payment_method' => 'bkash',
            'trx_id' => 'BK123', 'receiver_account' => '01700000000', 'note' => 'Weekly payout'];

        // The balance / withdraw review must be confirmed first.
        $this->postJson('/api/dropshipper-payouts', $body, $this->headers($this->admin))
            ->assertStatus(422)->assertJsonPath('errors.code', 'review_required');
        // Only admins can create.
        $this->postJson('/api/dropshipper-payouts', $body + ['payout_review_confirmed' => true], $this->headers($this->rahim))->assertForbidden();

        $invoice = $this->postJson('/api/dropshipper-payouts', $body + ['payout_review_confirmed' => true], $this->headers($this->admin))
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.orders_count', 2)
            ->assertJsonPath('data.trx_id', 'BK123')
            ->json('data');

        $this->assertEquals($total, $invoice['total_dropshipper_payout']);
        $this->assertStringStartsWith('PAY-D' . $this->rahim->id . '-', $invoice['invoice_number']);

        // Both profit settlements are settled against this payment.
        $settlements = OrderSettlement::whereIn('order_id', [$a->id, $b->id])->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)->get();
        $this->assertCount(2, $settlements);
        $this->assertTrue($settlements->every(fn ($s) => $s->status === OrderSettlement::STATUS_SETTLED
            && $s->settled_trx_id === $invoice['invoice_number'] . '-' . $s->order_id
            && str_contains($s->admin_note, 'trx BK123')));

        // One earning per order, ONE transfer for the whole invoice, wallet nets to zero.
        $this->assertSame(2, ResellerTransaction::where('reseller_id', $this->rahim->id)->where('type', 'order_settlement')->count());
        $this->assertSame(1, WithdrawRequest::where('user_id', $this->rahim->id)->where('status', 'transferred')->where('payment_method', 'bkash')->count());
        $this->assertEquals($total, (float) WithdrawRequest::where('user_id', $this->rahim->id)->value('amount'));
        $this->assertEquals(0.0, $this->balance($this->rahim));

        // Paid orders are no longer payable, and can't be invoiced twice.
        $this->assertSame(0, $this->payable($this->rahim)['payable_count']);
        $this->postJson('/api/dropshipper-payouts', $body + ['payout_review_confirmed' => true, 'trx_id' => 'BK999'], $this->headers($this->admin))->assertStatus(422);
        $this->assertSame(1, SettlementInvoice::where('type', 'dropshipper_payout')->count());
    }

    public function test_wallet_payout_credits_the_wallet_without_a_transfer(): void
    {
        $a = $this->order($this->rahim);
        $total = $this->payable($this->rahim)['payable_total'];

        $this->postJson('/api/dropshipper-payouts', ['dropshipper_id' => $this->rahim->id, 'order_ids' => [$a->id],
            'payment_method' => 'wallet', 'payout_review_confirmed' => true], $this->headers($this->admin))->assertCreated();

        $this->assertEquals($total, $this->balance($this->rahim));
        $this->assertSame(0, WithdrawRequest::where('user_id', $this->rahim->id)->count());
    }

    public function test_orders_that_are_not_payable_are_refused(): void
    {
        $waiting = $this->order($this->rahim, carrybeePaid: false);
        $karims = $this->order($this->karim);
        $base = ['dropshipper_id' => $this->rahim->id, 'payment_method' => 'wallet', 'payout_review_confirmed' => true];

        $this->postJson('/api/dropshipper-payouts', $base + ['order_ids' => [$waiting->id]], $this->headers($this->admin))
            ->assertStatus(422)->assertJsonFragment(['message' => "Order {$waiting->order_number} can't be paid: CarryBee has not paid yet."]);
        $this->postJson('/api/dropshipper-payouts', $base + ['order_ids' => [$karims->id]], $this->headers($this->admin))->assertStatus(422);
        // Bank payments need a transaction id.
        $ok = $this->order($this->rahim);
        $this->postJson('/api/dropshipper-payouts', ['payment_method' => 'bkash', 'order_ids' => [$ok->id]] + $base, $this->headers($this->admin))
            ->assertStatus(422)->assertJsonFragment(['message' => 'Enter the transaction ID of the payment.']);
        $this->assertSame(0, SettlementInvoice::count());
        $this->assertSame(0, OrderSettlement::where('status', OrderSettlement::STATUS_SETTLED)->count());
    }

    public function test_unpaid_invoice_reserves_orders_until_paid_or_cancelled(): void
    {
        $a = $this->order($this->rahim);
        $b = $this->order($this->rahim);
        $admin = $this->headers($this->admin);

        $issued = $this->postJson('/api/dropshipper-payouts', ['dropshipper_id' => $this->rahim->id, 'order_ids' => [$a->id], 'status' => 'pending'], $admin)
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame(0, OrderSettlement::where('order_id', $a->id)->where('status', OrderSettlement::STATUS_SETTLED)->count());

        // Order A is reserved; B is still payable.
        $rows = collect($this->payable($this->rahim)['rows'])->keyBy('order_id');
        $this->assertFalse($rows[$a->id]['payable']);
        $this->assertStringContainsString($issued['invoice_number'], $rows[$a->id]['reason']);
        $this->assertTrue($rows[$b->id]['payable']);

        // Cancel frees it.
        $this->postJson("/api/dropshipper-payouts/{$issued['id']}/cancel", ['reason' => 'Wrong orders'], $admin)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertTrue(collect($this->payable($this->rahim)['rows'])->keyBy('order_id')[$a->id]['payable']);
        $this->postJson("/api/dropshipper-payouts/{$issued['id']}/pay", ['payment_method' => 'wallet', 'payout_review_confirmed' => true], $admin)->assertStatus(422);

        // Issue again, then pay it later.
        $second = $this->postJson('/api/dropshipper-payouts', ['dropshipper_id' => $this->rahim->id, 'order_ids' => [$a->id, $b->id], 'status' => 'pending'], $admin)->json('data');
        $this->postJson("/api/dropshipper-payouts/{$second['id']}/pay", ['payment_method' => 'nagad', 'trx_id' => 'NG1'], $admin)
            ->assertStatus(422)->assertJsonPath('errors.code', 'review_required');
        $this->postJson("/api/dropshipper-payouts/{$second['id']}/pay", ['payment_method' => 'nagad', 'trx_id' => 'NG1', 'payout_review_confirmed' => true], $admin)
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_method', 'nagad');
        $this->assertSame(2, OrderSettlement::whereIn('order_id', [$a->id, $b->id])->where('status', OrderSettlement::STATUS_SETTLED)->count());
    }

    public function test_dropshipper_sees_only_their_own_invoices(): void
    {
        $mine = $this->order($this->rahim);
        $theirs = $this->order($this->karim);
        $admin = $this->headers($this->admin);
        $rahimInvoice = $this->postJson('/api/dropshipper-payouts', ['dropshipper_id' => $this->rahim->id, 'order_ids' => [$mine->id], 'payment_method' => 'wallet', 'payout_review_confirmed' => true], $admin)->json('data');
        $karimInvoice = $this->postJson('/api/dropshipper-payouts', ['dropshipper_id' => $this->karim->id, 'order_ids' => [$theirs->id], 'payment_method' => 'wallet', 'payout_review_confirmed' => true], $admin)->json('data');

        $this->getJson('/api/dropshipper-payouts', $this->headers($this->rahim))
            ->assertOk()->assertJsonCount(1, 'data.invoices.data')->assertJsonPath('data.invoices.data.0.id', $rahimInvoice['id']);
        // Even asking for someone else's id.
        $this->getJson('/api/dropshipper-payouts?dropshipper_id=' . $this->karim->id, $this->headers($this->rahim))
            ->assertJsonCount(1, 'data.invoices.data')->assertJsonPath('data.invoices.data.0.id', $rahimInvoice['id']);

        $this->getJson("/api/dropshipper-payouts/{$rahimInvoice['id']}", $this->headers($this->rahim))
            ->assertOk()->assertJsonPath('data.orders_breakdown.0.order_number', $mine->order_number);
        $this->getJson("/api/dropshipper-payouts/{$karimInvoice['id']}", $this->headers($this->rahim))->assertForbidden();

        $this->getJson('/api/dropshipper-payouts', $admin)->assertOk()->assertJsonCount(2, 'data.invoices.data');
        $this->getJson('/api/dropshipper-payouts?dropshipper_id=' . $this->karim->id, $admin)->assertJsonCount(1, 'data.invoices.data');

        // Payout invoices never appear in the vendor invoice list or actions.
        $this->getJson('/api/order-settlements/vendor-invoices', $admin)->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson("/api/order-settlements/vendor-invoices/{$rahimInvoice['id']}", $admin)->assertStatus(404);
    }
}
