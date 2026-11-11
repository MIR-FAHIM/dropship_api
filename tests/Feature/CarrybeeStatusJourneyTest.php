<?php

namespace Tests\Feature;

use App\Models\CarrybeeWebhookEvent;
use App\Models\Category;
use App\Models\DeliveryAssignedInfo;
use App\Models\DeliveryCompany;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use App\Service\CarrybeeWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The CarryBee status journey: webhook mapping, tracking, issue flags, review
 * holds, manual-change rules, catch-up and access control.
 */
class CarrybeeStatusJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reseller;
    private User $vendorUser;
    private Vendor $vendor;
    private Product $product;
    private int $consignmentSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['carrybee.webhook.secret' => 'journey-secret']);

        $names = [1 => 'Pending', 2 => 'Assigned To Vendor', 3 => 'Vendor Is Ready', 4 => 'Shipped', 5 => 'Out for Delivery',
            6 => 'Delivered', 7 => 'Cancelled', 8 => 'Returned', 9 => 'Completed', 10 => 'Carrybee Paid',
            11 => 'Lost / Damaged', 12 => 'Return in Transit', 13 => 'Partially Delivered'];
        foreach ($names as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'order', 'is_active' => true]);
        }

        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->reseller = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->vendorUser = User::factory()->create(['user_type' => 'vendor', 'role' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create([
            'user_id' => $this->vendorUser->id, 'shop_name' => 'Journey Shop',
            'has_own_delivery_account' => false, 'is_active' => true,
        ]);
        $category = Category::create(['name' => 'Kitchen', 'slug' => 'kitchen-' . uniqid(), 'is_active' => true]);
        $this->product = Product::create([
            'name' => 'Rice Cooker', 'slug' => 'rice-cooker-' . uniqid(), 'sku' => 'RC-1',
            'unit_price' => 500, 'admin_price' => 600, 'price' => 800,
            'current_stock' => 10, 'category_id' => $category->id, 'vendor_id' => $this->vendor->id, 'status' => 1,
        ]);
    }

    /* ---------------- helpers ---------------- */

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($user, ['basic'])['plain']];
    }

    private function makeOrder(int $status, bool $withConsignment = true): Order
    {
        $order = Order::create([
            'user_id' => $this->reseller->id, 'order_number' => 'ORD-J-' . uniqid(), 'status' => $status,
            'payment_status' => 'unpaid', 'customer_name' => 'Rahim', 'customer_phone' => '01711111111',
            'shipping_address' => 'House 1, Road 2, Dhaka', 'zone' => 'Inside District',
            'subtotal' => 800, 'reseller_price' => 800, 'reseller_profit' => 200,
            'shipping_fee' => 70, 'discount' => 0, 'total' => 870, 'remaining_cod' => 870,
            'vendor_id' => $this->vendor->id,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'shop_id' => $this->vendor->id,
            'product_name' => 'Rice Cooker', 'sku' => 'RC-1', 'unit_price' => 500, 'admin_price' => 600,
            'reseller_price' => 800, 'qty' => 1, 'line_total' => 800, 'line_total_reseller_profit' => 200, 'status' => 1,
        ]);

        if ($withConsignment) {
            DeliveryAssignedInfo::create([
                'order_id' => $order->id, 'consignment_id' => 'FXJ' . (++$this->consignmentSeq),
                'merchant_order_id' => $order->order_number, 'delivery_company_id' => 1,
                'recipient_name' => 'Rahim', 'recipient_phone' => '8801711111111', 'recipient_address' => 'House 1, Road 2, Dhaka',
                'collectable_amount' => 870, 'delivery_fee' => 60, 'total_fee' => 68.70,
            ]);
        }

        return $order->fresh();
    }

    private function fire(Order $order, string $event, array $extra = [], ?string $at = null): array
    {
        $info = DeliveryAssignedInfo::where('order_id', $order->id)->first();
        $payload = array_merge([
            'event' => $event,
            'store_id' => 'store-1',
            'consignment_id' => $info?->consignment_id,
            'merchant_order_id' => $order->order_number,
            'timestamptz' => $at ?? now()->addSeconds(++$this->consignmentSeq)->toIso8601String(),
        ], $extra);

        return app(CarrybeeWebhookService::class)->process($payload, json_encode($payload), 'journey-secret');
    }

    private function info(Order $order): DeliveryAssignedInfo
    {
        return DeliveryAssignedInfo::where('order_id', $order->id)->firstOrFail();
    }

    /* ---------------- webhook mapping ---------------- */

    public function test_order_created_keeps_vendor_ready_and_stores_fees(): void
    {
        $order = $this->makeOrder(3);

        $this->fire($order, 'order.created', ['collectable_amount' => '870', 'cod_fee' => 8.7, 'delivery_fee' => '60']);

        $order->refresh();
        $this->assertSame(3, (int) $order->status);
        $this->assertEquals(60.0, (float) $order->actual_courier_fee);
        $this->assertEquals(8.7, (float) $order->actual_cod_fee);
        $this->assertEquals(8.7, (float) $this->info($order)->cod_fee);
        $this->assertSame('order.created', $this->info($order)->courier_stage);
    }

    public function test_picked_ships_the_order_with_webhook_source(): void
    {
        $order = $this->makeOrder(3);

        $this->fire($order, 'order.picked');

        $this->assertSame(4, (int) $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status_id' => 4, 'source' => 'webhook']);
    }

    public function test_failed_delivery_keeps_status_raises_issue_and_logs_reason(): void
    {
        $order = $this->makeOrder(5);

        $this->fire($order, 'order.delivery-failed', ['attempt' => 2, 'reason' => 'Customer unreachable', 'remarks' => 'Phone off']);

        $info = $this->info($order);
        $this->assertSame(5, (int) $order->fresh()->status);
        $this->assertSame('delivery', $info->issue_flag);
        $this->assertStringContainsString('Customer unreachable', $info->issue_reason);
        $this->assertSame(2, $info->attempt);
        $history = OrderStatusHistory::where('order_id', $order->id)->latest('id')->first();
        $this->assertStringContainsString('reason=Customer unreachable', $history->note);
        $this->assertSame('webhook', $history->source);

        // Next attempt clears the flag and records the rider.
        $this->fire($order, 'order.assigned-for-delivery', ['attempt' => 3, 'agent_name' => 'Karim', 'agent_phone' => '8801999999999']);
        $info->refresh();
        $this->assertNull($info->issue_flag);
        $this->assertSame('Karim', $info->rider_name);
        $this->assertSame('8801999999999', $info->rider_phone);
    }

    public function test_amount_mismatch_holds_settlement_until_admin_approves(): void
    {
        $order = $this->makeOrder(5);

        $this->fire($order, 'order.delivered', ['collected_amount' => '500', 'attempt' => 1]);
        $info = $this->info($order);
        $this->assertSame(6, (int) $order->fresh()->status);
        $this->assertTrue($info->needs_review);
        $this->assertEquals(500.0, (float) $info->collected_amount);

        $this->fire($order, 'order.paid', ['invoice_id' => 'IXJ1']);
        $order->refresh();
        $this->assertSame(10, (int) $order->status);
        $this->assertSame(0, OrderSettlement::where('order_id', $order->id)->count(), 'settlement must wait for review');

        $this->postJson("/api/orders/{$order->id}/courier-review/approve", ['note' => 'Customer paid rest by bKash'], $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('data.settlement_started', true);

        $this->assertFalse($this->info($order)->needs_review);
        $this->assertGreaterThan(0, OrderSettlement::where('order_id', $order->id)->count());
    }

    public function test_matching_delivery_then_paid_settles_without_review(): void
    {
        $order = $this->makeOrder(5);

        $this->fire($order, 'order.delivered', ['collected_amount' => '870']);
        $this->assertFalse($this->info($order)->needs_review);

        $this->fire($order, 'order.paid', ['invoice_id' => 'IXJ2']);
        $this->assertGreaterThan(0, OrderSettlement::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status_id' => 10, 'source' => 'webhook']);
    }

    public function test_partial_delivery_goes_to_partially_delivered_and_needs_review(): void
    {
        $order = $this->makeOrder(5);

        $this->fire($order, 'order.partial-delivery', ['collected_amount' => '400', 'reason' => 'One item refused']);

        $this->assertSame(13, (int) $order->fresh()->status);
        $this->assertTrue($this->info($order)->needs_review);
    }

    public function test_return_reverses_money_only_when_back_with_vendor(): void
    {
        $order = $this->makeOrder(5);

        $this->fire($order, 'order.returned', ['reason' => 'Customer refused']);
        $this->assertSame(12, (int) $order->fresh()->status);

        $this->fire($order, 'order.returned-in-transit');
        $this->assertSame(12, (int) $order->fresh()->status);

        $this->fire($order, 'order.returned-to-merchant');
        $this->assertSame(8, (int) $order->fresh()->status);
    }

    public function test_pickup_cancelled_after_pickup_keeps_status(): void
    {
        $order = $this->makeOrder(4);

        $result = $this->fire($order, 'order.pickup-cancelled');

        $this->assertSame(4, (int) $order->fresh()->status);
        $this->assertStringContainsString('after pickup', $result['data']['status']);
    }

    public function test_pickup_cancelled_before_pickup_cancels(): void
    {
        $order = $this->makeOrder(3);

        $this->fire($order, 'order.pickup-cancelled');

        $this->assertSame(7, (int) $order->fresh()->status);
    }

    public function test_final_orders_are_not_moved_by_webhooks(): void
    {
        $order = $this->makeOrder(9);

        $result = $this->fire($order, 'order.returned');

        $this->assertSame(9, (int) $order->fresh()->status);
        $this->assertStringContainsString('final', $result['data']['status']);
    }

    public function test_older_event_does_not_overwrite_newer_tracking_stage(): void
    {
        $order = $this->makeOrder(4);

        $this->fire($order, 'order.received-at-last-mile-hub', [], '2026-11-10T12:00:00+00:00');
        $this->fire($order, 'order.in-transit', [], '2026-11-10T08:00:00+00:00');

        $this->assertSame('order.received-at-last-mile-hub', $this->info($order)->courier_stage);
    }

    public function test_create_failed_raises_issue_and_keeps_status(): void
    {
        $order = $this->makeOrder(3);

        $this->fire($order, 'order.create-failed', ['reason' => 'Validation error', 'causes' => ['recipient_phone' => [['type' => 'phone']]]]);

        $info = $this->info($order);
        $this->assertSame(3, (int) $order->fresh()->status);
        $this->assertSame('create_failed', $info->issue_flag);
        $this->assertStringContainsString('recipient_phone: phone', $info->issue_reason);
    }

    public function test_invalid_signature_is_recorded_as_rejected_and_valid_retry_still_works(): void
    {
        $order = $this->makeOrder(3);
        $payload = ['event' => 'order.picked', 'store_id' => 'store-1', 'consignment_id' => $this->info($order)->consignment_id,
            'merchant_order_id' => $order->order_number, 'timestamptz' => '2026-11-10T10:00:00+00:00'];
        $service = app(CarrybeeWebhookService::class);

        $bad = $service->process($payload, json_encode($payload), 'wrong-secret');
        $this->assertSame(401, $bad['http_code']);
        $this->assertDatabaseHas('carrybee_webhook_events', ['event' => 'order.picked', 'processing_status' => 'rejected', 'signature_valid' => false]);

        // A rejected (unsigned) event can't be reprocessed by an admin.
        $rejected = CarrybeeWebhookEvent::where('processing_status', 'rejected')->firstOrFail();
        $this->postJson("/api/delivery-companies/carrybee/webhook-events/{$rejected->id}/reprocess", [], $this->headers($this->admin))
            ->assertStatus(422);
        $this->assertSame(3, (int) $order->fresh()->status);

        $good = $service->process($payload, json_encode($payload), '"journey-secret"');
        $this->assertTrue($good['ok']);
        $this->assertSame(4, (int) $order->fresh()->status);
    }

    /* ---------------- manual status rules ---------------- */

    public function test_manual_change_rules(): void
    {
        $order = $this->makeOrder(5);
        $url = "/api/orders/update/status/{$order->id}";

        // Another user can't change it at all.
        $stranger = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->postJson($url, ['status_id' => 6], $this->headers($stranger))->assertForbidden();

        // Vendor can't set a courier status on a consignment order, and can't override.
        $this->postJson($url, ['status_id' => 6, 'override_reason' => 'x'], $this->headers($this->vendorUser))
            ->assertStatus(422)->assertJsonPath('errors.code', 'not_allowed');

        // Admin needs an override to go backwards...
        $this->postJson($url, ['status_id' => 2], $this->headers($this->admin))
            ->assertStatus(422)->assertJsonPath('errors.code', 'override_required');

        // ...and the override is recorded with who did it.
        $this->postJson($url, ['status_id' => 2, 'override_reason' => 'Wrong vendor, reassigning'], $this->headers($this->admin))->assertOk();
        $history = OrderStatusHistory::where('order_id', $order->id)->latest('id')->first();
        $this->assertSame(2, (int) $order->fresh()->status);
        $this->assertSame('manual', $history->source);
        $this->assertSame($this->admin->id, (int) $history->changed_by);
        $this->assertStringContainsString('Admin override: Wrong vendor, reassigning', $history->note);

        // Same status again is a no-op, not a duplicate history row.
        $count = OrderStatusHistory::where('order_id', $order->id)->count();
        $this->postJson($url, ['status_id' => 2], $this->headers($this->admin))->assertOk();
        $this->assertSame($count, OrderStatusHistory::where('order_id', $order->id)->count());

        // Vendor moving forward to Vendor Is Ready is fine.
        $this->postJson($url, ['status_id' => 3], $this->headers($this->vendorUser))->assertOk();
        $this->assertSame(3, (int) $order->fresh()->status);
    }

    public function test_completed_is_not_manual_on_consignment_orders(): void
    {
        $order = $this->makeOrder(6);

        $this->postJson("/api/orders/update/status/{$order->id}", ['status_id' => 9], $this->headers($this->admin))
            ->assertStatus(422)->assertJsonPath('errors.code', 'override_required');
    }

    public function test_non_consignment_order_moves_forward_manually(): void
    {
        $order = $this->makeOrder(3, withConsignment: false);

        $this->postJson("/api/orders/update/status/{$order->id}", ['status_id' => 4], $this->headers($this->vendorUser))->assertOk();
        $this->assertSame(4, (int) $order->fresh()->status);
    }

    /* ---------------- catch-up ---------------- */

    public function test_catch_up_applies_carrybee_details_for_quiet_consignments(): void
    {
        DeliveryCompany::create(['company_name' => 'CarryBee', 'is_active' => true, 'api_key' => 'id', 'secret_key' => 'secret', 'client_context' => 'ctx']);
        $order = $this->makeOrder(5);
        $consignment = $this->info($order)->consignment_id;

        Http::fake([
            "developers.carrybee.com/api/v2/orders/{$consignment}/details" => Http::response(['error' => false, 'data' => [
                'transfer_status' => 'Delivered', 'consignment_id' => $consignment, 'merchant_order_id' => $order->order_number,
                'collectable_amount' => '870', 'collected_amount' => '870', 'cod_fee' => 8.7, 'delivery_fee' => '60',
                'attempt' => 1, 'updated_at' => '2026-11-10T10:11:12+00:00', 'invoice_id' => 'IXCATCH', 'payment_status' => 'Paid',
            ]]),
        ]);

        $this->artisan('carrybee:catch-up')->assertSuccessful();

        $order->refresh();
        $this->assertContains((int) $order->status, [9, 10]);
        $this->assertSame('IXCATCH', $order->carrybee_invoice_id);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status_id' => 6, 'source' => 'catch-up']);
        $this->assertNotNull($this->info($order)->courier_checked_at);

        // Checked recently -> skipped on the next run.
        Http::fake(fn () => throw new \RuntimeException('should not be called'));
        $this->artisan('carrybee:catch-up')->expectsOutputToContain('Checked 0')->assertSuccessful();
    }

    public function test_catch_up_does_not_treat_unpaid_with_invoice_as_paid(): void
    {
        DeliveryCompany::create(['company_name' => 'CarryBee', 'is_active' => true, 'api_key' => 'id', 'secret_key' => 'secret', 'client_context' => 'ctx']);
        $order = $this->makeOrder(6);

        foreach (['Not Paid', 'Unpaid', 'Partially paid', 'Payment pending', ''] as $paymentStatus) {
            $this->info($order)->forceFill(['courier_checked_at' => null, 'courier_stage_at' => null])->save();
            Http::fake(['developers.carrybee.com/*' => Http::response(['error' => false, 'data' => [
                'transfer_status' => 'Delivered', 'collected_amount' => '870', 'invoice_id' => 'IXPENDING',
                'payment_status' => $paymentStatus, 'updated_at' => '2026-11-10T10:11:12+00:00',
            ]])]);

            $this->artisan('carrybee:catch-up')->assertSuccessful();

            $order->refresh();
            $this->assertSame(6, (int) $order->status, "payment_status '{$paymentStatus}' must not mark paid");
            $this->assertNull($order->carrybee_paid_at);
            $this->assertSame(0, OrderSettlement::where('order_id', $order->id)->count());
        }
    }

    public function test_catch_up_fills_invoice_for_orders_marked_paid_by_hand(): void
    {
        DeliveryCompany::create(['company_name' => 'CarryBee', 'is_active' => true, 'api_key' => 'id', 'secret_key' => 'secret', 'client_context' => 'ctx']);
        $paidByHand = $this->makeOrder(10);
        $completedByHand = $this->makeOrder(9);
        $alreadyInvoiced = $this->makeOrder(10);
        $alreadyInvoiced->forceFill(['carrybee_invoice_id' => 'IXOLD'])->save();

        Http::fake(['developers.carrybee.com/*' => Http::response(['error' => false, 'data' => [
            'transfer_status' => 'Delivered', 'collected_amount' => '870', 'invoice_id' => 'IXHAND',
            'payment_status' => 'Paid', 'updated_at' => '2026-11-10T10:11:12+00:00',
        ]])]);

        $this->artisan('carrybee:catch-up')->expectsOutputToContain('Checked 2')->assertSuccessful();

        $this->assertSame('IXHAND', $paidByHand->fresh()->carrybee_invoice_id);
        $this->assertNotNull($paidByHand->fresh()->carrybee_paid_at);
        $this->assertSame(9, (int) $completedByHand->fresh()->status);
        $this->assertSame('IXHAND', $completedByHand->fresh()->carrybee_invoice_id);
        $this->assertSame(0, OrderSettlement::where('order_id', $completedByHand->id)->count(), 'completed order settlement untouched');
        $this->assertSame('IXOLD', $alreadyInvoiced->fresh()->carrybee_invoice_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        DeliveryCompany::create(['company_name' => 'CarryBee', 'is_active' => true, 'api_key' => 'id', 'secret_key' => 'secret', 'client_context' => 'ctx']);
        $order = $this->makeOrder(5);
        Http::fake(['developers.carrybee.com/*' => Http::response(['error' => false, 'data' => [
            'transfer_status' => 'Delivered', 'invoice_id' => 'IX1', 'payment_status' => 'Paid', 'updated_at' => '2026-11-10T10:11:12+00:00',
        ]])]);

        $exit = \Illuminate\Support\Facades\Artisan::call('carrybee:catch-up', ['--dry-run' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('payment_status="Paid"', $output);
        $this->assertStringContainsString('would apply: order.delivered, order.paid', $output);

        $this->assertSame(5, (int) $order->fresh()->status);
        $this->assertNull($this->info($order)->courier_checked_at);
    }

    public function test_paid_on_completed_order_only_records_invoice(): void
    {
        $order = $this->makeOrder(9);

        $result = $this->fire($order, 'order.paid', ['invoice_id' => 'IXDONE']);

        $order->refresh();
        $this->assertSame(9, (int) $order->status);
        $this->assertSame('IXDONE', $order->carrybee_invoice_id);
        $this->assertSame(0, OrderSettlement::where('order_id', $order->id)->count());
        $this->assertStringContainsString('settlement unchanged', $result['data']['status']);
    }

    public function test_unknown_transfer_status_changes_nothing(): void
    {
        DeliveryCompany::create(['company_name' => 'CarryBee', 'is_active' => true, 'api_key' => 'id', 'secret_key' => 'secret', 'client_context' => 'ctx']);
        $order = $this->makeOrder(4);
        Http::fake(['developers.carrybee.com/*' => Http::response(['error' => false, 'data' => ['transfer_status' => 'Something New']])]);

        $this->artisan('carrybee:catch-up')->assertSuccessful();

        $this->assertSame(4, (int) $order->fresh()->status);
    }

    /* ---------------- access control ---------------- */

    public function test_courier_timeline_access(): void
    {
        $order = $this->makeOrder(4);
        $this->fire($order, 'order.in-transit');

        $this->getJson("/api/orders/{$order->id}/courier-events", $this->headers($this->reseller))
            ->assertOk()->assertJsonPath('data.events.0.label', 'In transit');
        $this->getJson("/api/orders/{$order->id}/courier-events", $this->headers($this->vendorUser))->assertOk();

        $stranger = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->getJson("/api/orders/{$order->id}/courier-events", $this->headers($stranger))->assertForbidden();
    }

    public function test_webhook_admin_routes_require_admin(): void
    {
        $headers = $this->headers($this->reseller);
        $this->getJson('/api/delivery-companies/carrybee/webhook-events', $headers)->assertForbidden();
        $this->getJson('/api/delivery-companies/carrybee/unacknowledged-paid-orders', $headers)->assertForbidden();
        $this->postJson('/api/delivery-companies/carrybee/acknowledge-paid-order/1', [], $headers)->assertForbidden();
        $this->postJson('/api/delivery-companies/carrybee/test-webhook', ['event' => 'order.paid'], $headers)->assertForbidden();
        $this->getJson('/api/delivery-companies/carrybee/webhook-health', $headers)->assertForbidden();

        $this->getJson('/api/delivery-companies/carrybee/webhook-health', $this->headers($this->admin))
            ->assertOk()->assertJsonPath('data.secret_configured', true);
    }

    public function test_reprocess_runs_an_unmatched_event_again(): void
    {
        $order = $this->makeOrder(3);
        $payload = ['event' => 'order.picked', 'consignment_id' => 'FX-NOT-LINKED', 'merchant_order_id' => 'UNKNOWN', 'timestamptz' => '2026-11-10T10:00:00+00:00'];
        app(CarrybeeWebhookService::class)->process($payload, json_encode($payload), 'journey-secret');
        $event = CarrybeeWebhookEvent::where('consignment_id', 'FX-NOT-LINKED')->firstOrFail();
        $this->assertSame('ignored', $event->processing_status);

        // Admin fixes the link, then reprocesses.
        $this->info($order)->update(['consignment_id' => 'FX-NOT-LINKED']);
        $this->postJson("/api/delivery-companies/carrybee/webhook-events/{$event->id}/reprocess", [], $this->headers($this->admin))->assertOk();

        $this->assertSame(4, (int) $order->fresh()->status);
        $this->assertSame('processed', $event->fresh()->processing_status);
    }

    public function test_default_store_accepts_text_ids(): void
    {
        $this->patchJson("/api/vendors/{$this->vendor->id}/carrybee-store", ['carryb_store_id' => 'abcd-1234'], $this->headers($this->admin))->assertOk();
        $this->assertSame('abcd-1234', $this->vendor->fresh()->carryb_store_id);

        $this->patchJson("/api/vendors/{$this->vendor->id}/carrybee-store", ['carryb_store_id' => 30070], $this->headers($this->admin))->assertOk();
        $this->assertSame('30070', (string) $this->vendor->fresh()->carryb_store_id);
    }
}
