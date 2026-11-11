<?php

namespace Tests\Feature;

use App\Models\ResellerTransaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DropshipperPayoutReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gets_balance_withdraws_and_advances(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'banned' => 0]);
        $dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        $dropshipper->orders()->create([
            'status' => 2, 'order_number' => 'ORD-A', 'advance_amount' => 130, 'advance_status' => 'approved',
        ]);
        $dropshipper->orders()->create([
            'status' => 1, 'order_number' => 'ORD-B', 'advance_amount' => 70, 'advance_status' => 'pending',
        ]);

        WithdrawRequest::create(['user_id' => $dropshipper->id, 'amount' => 500, 'status' => 'pending', 'payment_method' => 'bkash']);
        WithdrawRequest::create(['user_id' => $dropshipper->id, 'amount' => 200, 'status' => 'transferred', 'payment_method' => 'nagad']);

        ResellerTransaction::create([
            'reseller_id' => $dropshipper->id, 'amount' => 1000, 'trx_id' => 'T1', 'trx_type' => 'credit',
            'type' => 'order_settlement', 'status' => 'completed',
        ]);
        ResellerTransaction::create([
            'reseller_id' => $dropshipper->id, 'amount' => 300, 'trx_id' => 'T2', 'trx_type' => 'debit',
            'type' => 'withdraw', 'status' => 'completed',
        ]);

        $token = ApiTokenService::create($admin, ['basic']);

        $this->getJson("/api/users/{$dropshipper->id}/payout-review", ['Authorization' => 'Bearer '.$token['plain']])
            ->assertOk()
            ->assertJsonPath('data.balance.balance', 700)
            ->assertJsonPath('data.withdraws.open_count', 1)
            ->assertJsonPath('data.withdraws.open_amount', 500)
            ->assertJsonPath('data.advances.total_count', 2)
            ->assertJsonPath('data.advances.approved_amount', 130)
            ->assertJsonPath('data.advances.pending_count', 1)
            ->assertJsonCount(2, 'data.advances.recent')
            ->assertJsonCount(2, 'data.withdraws.recent');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $token = ApiTokenService::create($dropshipper, ['basic']);

        $this->getJson("/api/users/{$dropshipper->id}/payout-review", ['Authorization' => 'Bearer '.$token['plain']])
            ->assertForbidden();
    }
}
