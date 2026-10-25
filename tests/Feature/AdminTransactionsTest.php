<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Order;
use App\Models\ResellerTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $adminToken;
    private User $reseller1;
    private User $reseller2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin_trx@test.com',
            'user_type' => 'admin',
            'role' => 'admin',
        ]);

        $plain = 'admin-secret-token-' . uniqid();
        $this->adminToken = $plain;

        ApiToken::create([
            'user_id' => $this->admin->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(7),
            'is_revoked' => false,
        ]);

        $this->reseller1 = User::factory()->create([
            'name' => 'Reseller One',
            'email' => 'reseller1@test.com',
            'phone' => '01711111111',
            'user_type' => 'customer',
            'role' => 'dropshipper',
        ]);

        $this->reseller2 = User::factory()->create([
            'name' => 'Reseller Two',
            'email' => 'reseller2@test.com',
            'phone' => '01822222222',
            'user_type' => 'customer',
            'role' => 'dropshipper',
        ]);
    }

    private function adminHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ];
    }

    public function test_admin_can_fetch_all_platform_transactions(): void
    {
        Transaction::create([
            'amount' => 1500,
            'trx_type' => 'credit',
            'status' => 'completed',
            'source' => 'cod',
            'type' => 'order_status',
            'trx_id' => 'TRX-COD-1',
            'note' => 'COD collection for order #1001',
        ]);

        Transaction::create([
            'amount' => 500,
            'trx_type' => 'debit',
            'status' => 'completed',
            'source' => 'withdraw',
            'type' => 'reseller_withdraw',
            'trx_id' => 'TRX-WD-1',
            'note' => 'Withdraw payout',
        ]);

        $response = $this->getJson('/api/transactions/all', $this->adminHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_credit', 1500)
            ->assertJsonPath('data.total_debit', 500)
            ->assertJsonPath('data.balance', 1000)
            ->assertJsonCount(2, 'data.items.data');
    }

    public function test_admin_can_filter_and_search_platform_transactions(): void
    {
        Transaction::create([
            'amount' => 2000,
            'trx_type' => 'credit',
            'status' => 'completed',
            'source' => 'bkash',
            'type' => 'wallet_topup',
            'trx_id' => 'TRX-BKASH-SPECIAL-99',
            'note' => 'Special wallet recharge',
        ]);

        Transaction::create([
            'amount' => 120,
            'trx_type' => 'debit',
            'status' => 'completed',
            'source' => 'delivery_company',
            'type' => 'shipping_charge',
            'trx_id' => 'TRX-SHIP-01',
            'note' => 'Delivery shipping charge',
        ]);

        // Search by TRX ID
        $response = $this->getJson('/api/transactions/all?search=SPECIAL-99', $this->adminHeaders());
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.items.data')
            ->assertJsonPath('data.items.data.0.trx_id', 'TRX-BKASH-SPECIAL-99');

        // Filter by trx_type
        $debitResponse = $this->getJson('/api/transactions/all?trx_type=debit', $this->adminHeaders());
        $debitResponse->assertStatus(200)
            ->assertJsonCount(1, 'data.items.data')
            ->assertJsonPath('data.items.data.0.type', 'shipping_charge');

        // Filter by type
        $topupResponse = $this->getJson('/api/transactions/all?type=wallet_topup', $this->adminHeaders());
        $topupResponse->assertStatus(200)
            ->assertJsonCount(1, 'data.items.data')
            ->assertJsonPath('data.items.data.0.source', 'bkash');
    }

    public function test_admin_can_fetch_all_reseller_transactions_across_all_resellers(): void
    {
        ResellerTransaction::create([
            'reseller_id' => $this->reseller1->id,
            'amount' => 350,
            'trx_type' => 'credit',
            'status' => 'completed',
            'source' => 'wallet',
            'type' => 'order_profit',
            'trx_id' => 'RT-PROF-1',
            'note' => 'Order profit for reseller 1',
        ]);

        ResellerTransaction::create([
            'reseller_id' => $this->reseller2->id,
            'amount' => 1000,
            'trx_type' => 'credit',
            'status' => 'completed',
            'source' => 'bkash',
            'type' => 'wallet_topup',
            'trx_id' => 'RT-TOPUP-2',
            'note' => 'Wallet recharge for reseller 2',
        ]);

        // Admin without reseller_id should receive ALL reseller transactions
        $response = $this->getJson('/api/transactions/reseller', $this->adminHeaders());
        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data.items.data');

        // Verify reseller details are eager-loaded
        $names = collect($response->json('data.items.data'))->pluck('reseller.name')->toArray();
        $this->assertContains('Reseller One', $names);
        $this->assertContains('Reseller Two', $names);

        // Admin filtering by reseller_id
        $filteredResponse = $this->getJson('/api/transactions/reseller?reseller_id=' . $this->reseller1->id, $this->adminHeaders());
        $filteredResponse->assertStatus(200)
            ->assertJsonCount(1, 'data.items.data')
            ->assertJsonPath('data.items.data.0.reseller_id', $this->reseller1->id);

        // Admin search by reseller phone
        $searchResponse = $this->getJson('/api/transactions/reseller?search=01822222222', $this->adminHeaders());
        $searchResponse->assertStatus(200)
            ->assertJsonCount(1, 'data.items.data')
            ->assertJsonPath('data.items.data.0.reseller_id', $this->reseller2->id);
    }
}
