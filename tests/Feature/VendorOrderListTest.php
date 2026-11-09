<?php

namespace Tests\Feature;

use App\Models\CarrybeeWebhookEvent;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorOrderListTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_list_excludes_only_cancelled_orders_without_a_consignment_before_pagination(): void
    {
        $user = User::create([
            'name' => 'Test Vendor',
            'email' => 'vendor-orders@example.test',
            'password' => bcrypt('password'),
            'role' => 'vendor',
            'user_type' => 'vendor',
            'banned' => 0,
        ]);
        $vendor = Vendor::create(['user_id' => $user->id, 'shop_name' => 'Test Shop']);
        $token = ApiTokenService::create($user, ['basic'], 1, 'test')['plain'];

        $order = function (string $number, int $status, int $seconds) use ($vendor): Order {
            $order = new Order(['order_number' => $number, 'status' => $status]);
            $order->created_at = now()->addSeconds($seconds);
            $order->save();
            OrderItem::create([
                'order_id' => $order->id,
                'shop_id' => $vendor->id,
                'product_name' => 'Test product',
                'qty' => 1,
                'unit_price' => 100,
                'line_total' => 100,
            ]);

            return $order;
        };

        $order('ACTIVE', 2, 1);
        $assigned = $order('CANCELLED-ASSIGNED', 7, 2);
        $webhook = $order('CANCELLED-WEBHOOK', 7, 3);
        $order('CANCELLED-NO-CONSIGNMENT', 7, 4);
        $blank = $order('CANCELLED-BLANK-CONSIGNMENT', 7, 5);
        $order('PENDING', 1, 6);

        foreach ([[$assigned, 'CB-ASSIGNED'], [$blank, ' ']] as [$linkedOrder, $consignment]) {
            DeliveryAssignedInfo::create([
                'order_id' => $linkedOrder->id,
                'consignment_id' => $consignment,
                'recipient_name' => 'Test Recipient',
                'recipient_phone' => '01700000000',
                'recipient_address' => 'Test Address',
            ]);
        }

        CarrybeeWebhookEvent::create([
            'order_id' => $webhook->id,
            'fingerprint' => hash('sha256', 'vendor-list-webhook'),
            'event' => 'order.created',
            'consignment_id' => 'CB-WEBHOOK',
            'payload' => [],
            'signature_valid' => true,
            'processing_status' => 'processed',
        ]);

        $url = "/api/orders/vendor/{$vendor->id}?per_page=2";
        $this->withToken($token)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.data.0.order_number', 'CANCELLED-WEBHOOK')
            ->assertJsonPath('data.data.1.order_number', 'CANCELLED-ASSIGNED');

        $this->getJson($url . '&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.order_number', 'ACTIVE');
    }
}
