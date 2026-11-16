<?php

namespace Tests\Feature;

use App\Models\PartnerStore;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// users.balance is gone: balances come from the reseller_transactions ledger.
class UserLedgerBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $rahim;
    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rahim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->karim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        $this->trx($this->rahim, 1000, 'credit', 'completed', 'T1');
        $this->trx($this->rahim, 300, 'debit', 'completed', 'T2');
        $this->trx($this->rahim, 500, 'credit', 'pending', 'T3'); // not counted
        $this->trx($this->karim, 50, 'debit', 'completed', 'T4');
    }

    private function trx(User $user, float $amount, string $type, string $status, string $trxId): void
    {
        ResellerTransaction::create([
            'reseller_id' => $user->id, 'amount' => $amount, 'trx_id' => $trxId, 'trx_type' => $type,
            'type' => $type === 'credit' ? 'order_settlement' : 'withdraw', 'status' => $status,
        ]);
    }

    public function test_users_table_has_no_balance_column(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'balance'));
    }

    public function test_dropshipper_list_shows_ledger_wallet_balance(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
        $empty = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        $rows = collect($this->getJson('/api/users/dropshippers', $headers)->assertOk()->json('data.data'))->keyBy('id');

        $this->assertEquals(700.0, $rows[$this->rahim->id]['wallet_balance']);
        $this->assertEquals(-50.0, $rows[$this->karim->id]['wallet_balance']);
        $this->assertEquals(0.0, $rows[$empty->id]['wallet_balance']);
        $this->assertArrayNotHasKey('balance', $rows[$this->rahim->id]);
    }

    public function test_partner_auth_verify_returns_ledger_balance(): void
    {
        WithdrawRequest::create(['user_id' => $this->rahim->id, 'amount' => 200, 'status' => 'pending', 'payment_method' => 'bkash']);
        PartnerStore::create([
            'user_id' => $this->rahim->id, 'store_name' => 'Rahim Shop', 'api_key' => 'rb_live_pk_test',
            'api_secret' => 'rb_live_sk_test', 'is_active' => true,
        ]);

        $this->getJson('/api/partner/v1/auth/verify', ['X-RB-API-KEY' => 'rb_live_pk_test', 'X-RB-API-SECRET' => 'rb_live_sk_test'])
            ->assertOk()
            ->assertJsonPath('data.dropshipper.balance', 700)
            ->assertJsonPath('data.dropshipper.available_balance', 500);
    }
}
