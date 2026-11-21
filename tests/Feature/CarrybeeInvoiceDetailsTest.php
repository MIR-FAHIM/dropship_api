<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCarryBeeCredintial;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CarrybeeInvoiceDetailsTest extends TestCase
{
    use RefreshDatabase;

    private array $adminHeaders;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->adminHeaders = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];

        $user = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create(['user_id' => $user->id, 'shop_name' => 'Shop', 'has_own_delivery_account' => true, 'is_active' => true]);
        VendorCarryBeeCredintial::create([
            'vendor_id'      => $this->vendor->id,
            'client_id'      => 'cid',
            'client_secret'  => 'csecret',
            'client_context' => 'cctx',
            'is_active'      => true,
        ]);
    }

    public function test_admin_gets_carrybee_invoice_details(): void
    {
        Http::fake([
            'https://developers.carrybee.com/api/v2/invoices/IX212124433' => Http::response([
                'error'   => false,
                'message' => 'Invoice details',
                'data'    => [
                    'invoice_id'       => 'IX212124433',
                    'received_amount'  => 47950,
                    'consignments'     => [['consignment_id' => 'FX1', 'collected_amount' => 10000]],
                ],
            ], 200),
        ]);

        $this->getJson("/api/delivery-companies/carrybee/{$this->vendor->id}/invoices/IX212124433", $this->adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.data.invoice_id', 'IX212124433')
            ->assertJsonPath('data.data.received_amount', 47950)
            ->assertJsonPath('data.data.consignments.0.consignment_id', 'FX1');

        Http::assertSent(fn ($request) => $request->url() === 'https://developers.carrybee.com/api/v2/invoices/IX212124433'
            && $request->header('Client-ID')[0] === 'cid');
    }

    public function test_manual_invoice_ids_are_not_sent_to_carrybee(): void
    {
        Http::fake();

        $this->getJson("/api/delivery-companies/carrybee/{$this->vendor->id}/invoices/MANUAL-ABC123", $this->adminHeaders)
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_carrybee_error_is_passed_through(): void
    {
        Http::fake(['https://developers.carrybee.com/api/v2/invoices/*' => Http::response(['error' => true, 'message' => 'Invoice not found'], 404)]);

        $this->getJson("/api/delivery-companies/carrybee/{$this->vendor->id}/invoices/IX0", $this->adminHeaders)
            ->assertStatus(404);
    }

    public function test_requires_admin(): void
    {
        Http::fake();

        $this->getJson("/api/delivery-companies/carrybee/{$this->vendor->id}/invoices/IX212124433")
            ->assertStatus(401);

        Http::assertNothingSent();
    }
}
