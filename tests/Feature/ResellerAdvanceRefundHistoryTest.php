<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ResellerTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerAdvanceRefundHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/transactions/reseller/advance-refunds';

    public function test_admin_can_paginate_refunds_without_including_advance_payments(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $reseller = User::factory()->create(['user_type' => 'reseller']);
        $transaction = [
            'reseller_id' => $reseller->id,
            'amount' => 130,
            'trx_type' => 'credit',
            'source' => 'wallet',
            'status' => 'completed',
        ];
        $olderRefund = ResellerTransaction::create($transaction + ['type' => 'advance_refund']);
        $newerRefund = ResellerTransaction::create($transaction + ['type' => 'advance_refund']);
        ResellerTransaction::create($transaction + ['type' => 'order_advance']);
        $headers = $this->headersFor($admin);

        $this->getJson(self::ENDPOINT.'?per_page=1', $headers)
            ->assertOk()
            ->assertJsonPath('data.items.total', 2)
            ->assertJsonCount(1, 'data.items.data')
            ->assertJsonPath('data.items.data.0.id', $newerRefund->id)
            ->assertJsonPath('data.items.data.0.reseller.name', $reseller->name);

        $this->getJson(self::ENDPOINT.'?per_page=1&page=2', $headers)
            ->assertOk()
            ->assertJsonPath('data.items.data.0.id', $olderRefund->id);
    }

    public function test_history_requires_an_accounts_admin(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        foreach (['reseller', 'product manager'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->getJson(self::ENDPOINT, $this->headersFor($user))->assertForbidden();
        }
    }

    private function headersFor(User $user): array
    {
        $token = bin2hex(random_bytes(20));
        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'is_revoked' => false,
            'expires_at' => now()->addDay(),
        ]);

        return ['Authorization' => 'Bearer '.$token];
    }
}
