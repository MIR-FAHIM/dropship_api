<?php

namespace Tests\Feature;

use App\Models\CarrybeeWebhookEvent;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarrybeePaidWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $adminHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed order statuses 1..11
        $statuses = [
            1 => 'Pending',
            2 => 'Confirmed',
            3 => 'Pickup Requested',
            4 => 'In Transit',
            5 => 'Assigned for Delivery',
            6 => 'Delivered',
            7 => 'Cancelled',
            8 => 'Returned',
            9 => 'Completed',
            10 => 'Failed',
            11 => 'Lost',
        ];

        foreach ($statuses as $id => $name) {
            OrderStatus::create([
                'id'        => $id,
                'name'      => $name,
                'type'      => 'delivery',
                'is_active' => true,
            ]);
        }

        // Create Admin and token
        $this->admin = User::create([
            'name'      => 'Admin User',
            'email'     => 'admin_carrybee_test@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'user_type' => 'admin',
            'banned'    => 0,
        ]);

        $tokenData = ApiTokenService::create($this->admin, ['basic', 'admin'], 30, 'admin-token');
        $this->adminHeaders = ['Authorization' => 'Bearer ' . $tokenData['plain']];
    }

    private function createVendor(bool $hasOwnDeliveryAccount = false): Vendor
    {
        $user = User::create([
            'name'      => 'Vendor ' . uniqid(),
            'email'     => 'vendor_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'vendor',
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);

        return Vendor::create([
            'user_id'                  => $user->id,
            'shop_name'                => 'Test Shop ' . uniqid(),
            'has_own_delivery_account' => $hasOwnDeliveryAccount,
            'is_active'                => true,
        ]);
    }

    public function test_order_paid_webhook_updates_order_and_sets_unacknowledged_alert_for_vendor_account(): void
    {
        $vendor = $this->createVendor(hasOwnDeliveryAccount: true);

        $order = Order::create([
            'order_number'        => 'ORD-PAID-001',
            'vendor_id'           => $vendor->id,
            'total'               => 1200,
            'shipping_fee'        => 100,
            'status'              => 5,
            'payment_status'      => 'unpaid',
            'remaining_cod'       => 1200,
            'actual_courier_fee'  => 80,
            'actual_cod_fee'      => 12,
            'actual_courier_cost' => 92,
        ]);

        DeliveryAssignedInfo::create([
            'order_id'            => $order->id,
            'consignment_id'      => 'FX1212124433',
            'merchant_order_id'   => 'ORD-PAID-001',
            'recipient_name'      => 'Customer One',
            'recipient_phone'     => '01711111111',
            'recipient_address'   => 'Banani, Dhaka',
            'collectable_amount'  => 1200,
            'delivery_fee'        => 80,
            'total_fee'           => 92,
            'transfer_status_id'  => 5,
        ]);

        $payload = [
            'event'             => 'order.paid',
            'store_id'          => 'store_xyz',
            'consignment_id'    => 'FX1212124433',
            'merchant_order_id' => 'ORD-PAID-001',
            'invoice_id'        => 'IX212124433',
            'timestamptz'       => '2025-07-30T10:11:12+00:00',
        ];

        // Carrybee webhook call (unauthenticated external webhook)
        $response = $this->postJson('/api/delivery-companies/carrybee/webhook', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Webhook processed',
                'data'    => [
                    'order_id'     => $order->id,
                    'disbursed_to' => 'vendor',
                    'invoice_id'   => 'IX212124433',
                ],
            ]);

        // Verify Order updated
        $freshOrder = $order->fresh();
        $this->assertEquals(9, $freshOrder->status);
        $this->assertEquals('paid', $freshOrder->payment_status);
        $this->assertEquals('IX212124433', $freshOrder->carrybee_invoice_id);
        $this->assertEquals('vendor', $freshOrder->carrybee_disbursed_to);
        $this->assertEquals('received', $freshOrder->courier_payout_status);

        // Verify DeliveryAssignedInfo updated
        $freshAssigned = DeliveryAssignedInfo::where('order_id', $order->id)->first();
        $this->assertEquals('IX212124433', $freshAssigned->carrybee_invoice_id);
        $this->assertEquals(9, $freshAssigned->transfer_status_id);

        // Verify unacknowledged event query (Admin Authenticated)
        $unackResponse = $this->withHeaders($this->adminHeaders)
            ->getJson('/api/delivery-companies/carrybee/unacknowledged-paid-orders');

        $unackResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $data = $unackResponse->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('ORD-PAID-001', $data[0]['order_number']);
        $this->assertEquals('IX212124433', $data[0]['invoice_id']);
        $this->assertEquals('vendor', $data[0]['disbursed_to']);
        $this->assertTrue($data[0]['vendor']['has_own_delivery_account']);

        $eventId = $data[0]['event_id'];

        // Test acknowledge endpoint
        $ackResponse = $this->withHeaders($this->adminHeaders)
            ->postJson("/api/delivery-companies/carrybee/acknowledge-paid-order/{$eventId}");

        $ackResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        // Verify it is no longer returned in unacknowledged list
        $freshUnack = $this->withHeaders($this->adminHeaders)
            ->getJson('/api/delivery-companies/carrybee/unacknowledged-paid-orders');
        $freshData = $freshUnack->json('data');
        $this->assertEmpty($freshData);
    }

    public function test_order_paid_webhook_flags_platform_when_vendor_has_no_delivery_account(): void
    {
        $vendor = $this->createVendor(hasOwnDeliveryAccount: false);

        $order = Order::create([
            'order_number'        => 'ORD-PAID-002',
            'vendor_id'           => $vendor->id,
            'total'               => 1500,
            'shipping_fee'        => 120,
            'status'              => 5,
            'payment_status'      => 'unpaid',
            'remaining_cod'       => 1500,
            'actual_courier_fee'  => 100,
            'actual_cod_fee'      => 15,
            'actual_courier_cost' => 115,
        ]);

        DeliveryAssignedInfo::create([
            'order_id'            => $order->id,
            'consignment_id'      => 'FX9999999999',
            'merchant_order_id'   => 'ORD-PAID-002',
            'recipient_name'      => 'Customer Two',
            'recipient_phone'     => '01822222222',
            'recipient_address'   => 'Gulshan, Dhaka',
            'collectable_amount'  => 1500,
            'delivery_fee'        => 100,
            'total_fee'           => 115,
            'transfer_status_id'  => 5,
        ]);

        $payload = [
            'event'             => 'order.paid',
            'store_id'          => 'store_platform',
            'consignment_id'    => 'FX9999999999',
            'merchant_order_id' => 'ORD-PAID-002',
            'invoice_id'        => 'IX9999999999',
            'timestamptz'       => '2025-07-30T10:15:00+00:00',
        ];

        // Hit the alias route /api/carrybee/webhook
        $response = $this->postJson('/api/carrybee/webhook', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Webhook processed',
                'data'    => [
                    'disbursed_to' => 'platform',
                    'invoice_id'   => 'IX9999999999',
                ],
            ]);

        $this->assertEquals('platform', $order->fresh()->carrybee_disbursed_to);
    }

    public function test_admin_simulation_endpoint_processes_test_webhook(): void
    {
        $vendor = $this->createVendor(hasOwnDeliveryAccount: true);

        $order = Order::create([
            'order_number' => 'ORD-SIM-123',
            'vendor_id'    => $vendor->id,
            'total'        => 2000,
        ]);

        DeliveryAssignedInfo::create([
            'order_id'           => $order->id,
            'consignment_id'     => 'FX-SIM-TEST',
            'recipient_name'     => 'Sim User',
            'recipient_phone'    => '01900000000',
            'recipient_address'  => 'Uttara, Dhaka',
            'collectable_amount' => 2000,
            'delivery_fee'       => 70,
            'total_fee'          => 70,
        ]);

        $testPayload = [
            'event'             => 'order.paid',
            'consignment_id'    => 'FX-SIM-TEST',
            'merchant_order_id' => 'ORD-SIM-123',
            'invoice_id'        => 'IX-SIM-TEST',
        ];

        $response = $this->withHeaders($this->adminHeaders)
            ->postJson('/api/delivery-companies/carrybee/test-webhook', $testPayload);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Webhook processed',
            ]);

        // Check webhook event list
        $listResponse = $this->withHeaders($this->adminHeaders)
            ->getJson('/api/delivery-companies/carrybee/webhook-events?event=order.paid');
        $listResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertGreaterThanOrEqual(1, count($listResponse->json('data.data')));
    }

    public function test_admin_can_manually_mark_and_reset_carrybee_payment(): void
    {
        $vendorUser = User::create([
            'name'      => 'Supplier Ripon',
            'email'     => 'ripon_manual@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
        ]);

        $vendor = Vendor::create([
            'user_id'                  => $vendorUser->id,
            'shop_name'                => 'Manual BD',
            'carryb_store_id'          => 44444,
            'has_own_delivery_account' => true,
        ]);

        $order = Order::create([
            'order_number'   => 'ORD-MANUAL-1',
            'vendor_id'      => $vendor->id,
            'status'         => 6,
            'payment_status' => 'unpaid',
            'total'          => 1500,
        ]);

        DeliveryAssignedInfo::create([
            'order_id'           => $order->id,
            'consignment_id'     => 'FX-MANUAL-1',
            'recipient_name'     => 'Manual Customer',
            'recipient_phone'    => '01700000000',
            'recipient_address'  => 'Dhaka',
            'collectable_amount' => 1500,
            'delivery_fee'       => 70,
            'total_fee'          => 70,
        ]);

        // 1. Manually mark as paid
        $markRes = $this->withHeaders($this->adminHeaders)
            ->postJson("/api/delivery-companies/carrybee/manual-order-payment/{$order->id}", [
                'action'       => 'mark_paid',
                'invoice_id'   => 'IX-MANUAL-888',
                'disbursed_to' => 'vendor',
                'note'         => 'Marked by admin',
            ]);

        $markRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $fresh = $order->fresh(['deliveryInformation']);
        $this->assertEquals('IX-MANUAL-888', $fresh->carrybee_invoice_id);
        $this->assertEquals('paid', $fresh->payment_status);
        $this->assertEquals('vendor', $fresh->carrybee_disbursed_to);
        $this->assertTrue($fresh->is_carrybee_paid);

        // 2. Manually reset
        $resetRes = $this->withHeaders($this->adminHeaders)
            ->postJson("/api/delivery-companies/carrybee/manual-order-payment/{$order->id}", [
                'action' => 'reset',
                'note'   => 'Reset by admin',
            ]);

        $resetRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $freshReset = $order->fresh(['deliveryInformation']);
        $this->assertNull($freshReset->carrybee_invoice_id);
        $this->assertNull($freshReset->carrybee_paid_at);
        $this->assertFalse($freshReset->is_carrybee_paid);

        // 3. Manually mark without note or invoice_id (empty optional fields)
        $markWithoutNoteRes = $this->withHeaders($this->adminHeaders)
            ->postJson("/api/delivery-companies/carrybee/manual-order-payment/{$order->id}", [
                'action' => 'mark_paid',
            ]);

        $markWithoutNoteRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $fresh2 = $order->fresh();
        $this->assertTrue($fresh2->is_carrybee_paid);
        $this->assertStringStartsWith('MANUAL-', $fresh2->carrybee_invoice_id);

        // 4. Manually reset without note
        $resetWithoutNoteRes = $this->withHeaders($this->adminHeaders)
            ->postJson("/api/delivery-companies/carrybee/manual-order-payment/{$order->id}", [
                'action' => 'reset',
            ]);

        $resetWithoutNoteRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertFalse($order->fresh()->is_carrybee_paid);
    }
}
