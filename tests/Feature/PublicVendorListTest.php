<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicVendorListTest extends TestCase
{
    use RefreshDatabase;

    private function createVendors(): array
    {
        $userActive = User::create([
            'name'      => 'Active Vendor User',
            'email'     => 'active_vendor@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);

        $activeVendor = Vendor::create([
            'user_id'   => $userActive->id,
            'shop_name' => 'Active Gadget Store',
            'is_active' => true,
        ]);

        $userInactive = User::create([
            'name'      => 'Inactive Vendor User',
            'email'     => 'inactive_vendor@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 1,
        ]);

        $inactiveVendor = Vendor::create([
            'user_id'   => $userInactive->id,
            'shop_name' => 'Inactive Closed Shop',
            'is_active' => false,
        ]);

        return [
            'active'   => $activeVendor,
            'inactive' => $inactiveVendor,
        ];
    }

    public function test_public_can_get_vendor_list_without_token_and_sees_only_active_vendors(): void
    {
        $vendors = $this->createVendors();

        // 1. Hit public /api/vendors/list without token
        $response = $this->getJson('/api/vendors/list');

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Vendor list fetched',
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($vendors['active']->id, $data[0]['id']);
        $this->assertEquals('Active Gadget Store', $data[0]['shop_name']);
    }

    public function test_public_alias_endpoint_works_without_token(): void
    {
        $vendors = $this->createVendors();

        $response = $this->getJson('/api/public/vendors/list');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($vendors['active']->id, $data[0]['id']);
    }

    public function test_vendor_list_can_filter_explicitly_by_is_active_parameter(): void
    {
        $vendors = $this->createVendors();

        // Query active explicitly
        $responseActive = $this->getJson('/api/vendors/list?is_active=1');
        $responseActive->assertStatus(200);
        $dataActive = $responseActive->json('data');
        $this->assertCount(1, $dataActive);
        $this->assertEquals($vendors['active']->id, $dataActive[0]['id']);

        // Query inactive explicitly
        $responseInactive = $this->getJson('/api/vendors/list?is_active=0');
        $responseInactive->assertStatus(200);
        $dataInactive = $responseInactive->json('data');
        $this->assertCount(1, $dataInactive);
        $this->assertEquals($vendors['inactive']->id, $dataInactive[0]['id']);
    }

    public function test_admin_with_token_sees_all_vendors_by_default(): void
    {
        $vendors = $this->createVendors();

        $admin = User::create([
            'name'      => 'Super Admin',
            'email'     => 'admin@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'admin',
            'role'      => 'admin',
        ]);

        $tokenResult = ApiTokenService::create($admin, ['basic'], 60, 'admin-token');
        $token = $tokenResult['plain'];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/vendors/list');

        $response->assertStatus(200);
        $data = $response->json('data');
        // Admin sees both active and inactive
        $this->assertCount(2, $data);
    }
}
