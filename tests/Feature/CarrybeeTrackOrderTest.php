<?php

namespace Tests\Feature;

use App\Models\DeliveryAssignedInfo;
use App\Models\DeliveryCompany;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCarryBeeCredintial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CarrybeeTrackOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_order_with_vendor_own_credentials(): void
    {
        $user = User::create([
            'name'      => 'Vendor User',
            'email'     => 'vendor@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'vendor',
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);

        $vendor = Vendor::create([
            'user_id'                  => $user->id,
            'shop_name'                => 'Test Vendor Shop',
            'has_own_delivery_account' => true,
            'is_active'                => true,
        ]);

        VendorCarryBeeCredintial::create([
            'vendor_id'      => $vendor->id,
            'client_id'      => 'vendor_client_id_123',
            'client_secret'  => 'vendor_client_secret_123',
            'client_context' => 'vendor_client_context_123',
            'is_active'      => true,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-TEST-001',
            'vendor_id'    => $vendor->id,
            'total'        => 500,
        ]);

        $assigned = DeliveryAssignedInfo::create([
            'order_id'            => $order->id,
            'consignment_id'      => 'CB-VENDOR-001',
            'delivery_company_id' => (string) $vendor->id,
            'recipient_name'      => 'Karim Mia',
            'recipient_phone'     => '01700000001',
            'recipient_address'   => 'Dhanmondi, Dhaka',
            'collectable_amount'  => 500,
            'delivery_fee'        => 60,
            'total_fee'           => 560,
            'transfer_status_id'  => 1,
        ]);

        Http::fake([
            'https://developers.carrybee.com/api/v2/orders/CB-VENDOR-001/details' => Http::response([
                'status'  => 200,
                'message' => 'Success',
                'data'    => [
                    'consignment_id'     => 'CB-VENDOR-001',
                    'transfer_status_id' => 4,
                    'order_status'       => 'in_transit',
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/delivery-companies/carrybee/track/CB-VENDOR-001');

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Order tracking details fetched successfully',
                'data'    => [
                    'local_delivery_info' => [
                        'order_id'           => $order->id,
                        'order_number'       => 'ORD-TEST-001',
                        'recipient_name'     => 'Karim Mia',
                        'recipient_phone'    => '01700000001',
                        'transfer_status_id' => 4,
                    ],
                ],
            ]);

        // Verify that transfer_status_id was updated in database
        $this->assertEquals(4, $assigned->fresh()->transfer_status_id);

        // Verify vendor credentials were sent in request headers
        Http::assertSent(function ($request) {
            return $request->url() === 'https://developers.carrybee.com/api/v2/orders/CB-VENDOR-001/details'
                && $request->header('Client-ID')[0] === 'vendor_client_id_123'
                && $request->header('Client-Secret')[0] === 'vendor_client_secret_123'
                && $request->header('Client-Context')[0] === 'vendor_client_context_123';
        });
    }

    public function test_track_order_fallback_to_global_credentials(): void
    {
        DeliveryCompany::create([
            'company_name'   => 'Carrybee Global',
            'api_key'        => 'global_client_id_456',
            'secret_key'     => 'global_client_secret_456',
            'client_context' => 'global_client_context_456',
            'is_active'      => true,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-TEST-002',
            'total'        => 800,
        ]);

        DeliveryAssignedInfo::create([
            'order_id'            => $order->id,
            'consignment_id'      => 'CB-GLOBAL-002',
            'delivery_company_id' => null,
            'recipient_name'      => 'Rahim Uddin',
            'recipient_phone'     => '01800000002',
            'recipient_address'   => 'Mirpur, Dhaka',
            'collectable_amount'  => 800,
            'delivery_fee'        => 70,
            'total_fee'           => 870,
            'transfer_status_id'  => 1,
        ]);

        Http::fake([
            'https://developers.carrybee.com/api/v2/orders/CB-GLOBAL-002/details' => Http::response([
                'status'  => 200,
                'message' => 'Success',
                'data'    => [
                    'consignment_id'     => 'CB-GLOBAL-002',
                    'transfer_status_id' => 6,
                    'order_status'       => 'delivered',
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/delivery-companies/carrybee/track/CB-GLOBAL-002');

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Order tracking details fetched successfully',
            ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://developers.carrybee.com/api/v2/orders/CB-GLOBAL-002/details'
                && $request->header('Client-ID')[0] === 'global_client_id_456';
        });
    }

    public function test_track_order_not_in_assigned_infos_uses_global_credentials(): void
    {
        DeliveryCompany::create([
            'company_name'   => 'Carrybee Global',
            'api_key'        => 'global_client_id_789',
            'secret_key'     => 'global_client_secret_789',
            'client_context' => 'global_client_context_789',
            'is_active'      => true,
        ]);

        Http::fake([
            'https://developers.carrybee.com/api/v2/orders/CB-UNASSIGNED-999/details' => Http::response([
                'status'  => 200,
                'message' => 'Success',
                'data'    => [
                    'consignment_id' => 'CB-UNASSIGNED-999',
                    'order_status'   => 'picked',
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/delivery-companies/carrybee/track/CB-UNASSIGNED-999');

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Order tracking details fetched successfully',
                'data'    => [
                    'local_delivery_info' => null,
                ],
            ]);
    }

    public function test_track_order_carrybee_api_error_returns_failed_response(): void
    {
        DeliveryCompany::create([
            'company_name'   => 'Carrybee Global',
            'api_key'        => 'global_client_id_789',
            'secret_key'     => 'global_client_secret_789',
            'client_context' => 'global_client_context_789',
            'is_active'      => true,
        ]);

        Http::fake([
            'https://developers.carrybee.com/api/v2/orders/CB-NOT-FOUND/details' => Http::response([
                'status'  => 404,
                'message' => 'Consignment not found',
            ], 404),
        ]);

        $response = $this->getJson('/api/delivery-companies/carrybee/track/CB-NOT-FOUND');

        $response->assertStatus(404)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'Carrybee API error',
            ]);
    }

    public function test_track_order_when_no_credentials_exist_returns_404(): void
    {
        $response = $this->getJson('/api/delivery-companies/carrybee/track/CB-ANY-001');

        $response->assertStatus(404)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'No active Carrybee credentials found',
            ]);
    }
}
