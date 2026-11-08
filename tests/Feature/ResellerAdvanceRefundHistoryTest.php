<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Order;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Service\OrderAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerAdvanceRefundHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/transactions/reseller/advance-refunds';
    private const HISTORY_ENDPOINT = '/api/transactions/reseller/advance-history';

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
        $order = Order::create([
            'user_id' => $reseller->id,
            'order_number' => 'ORD-ADVANCE-REFUND',
            'status' => 9,
        ]);
        $newerRefund = ResellerTransaction::create($transaction + [
            'type' => 'advance_refund',
            'order_id' => $order->id,
        ]);
        ResellerTransaction::create($transaction + ['type' => 'order_advance']);
        $headers = $this->headersFor($admin);

        $this->getJson(self::ENDPOINT.'?per_page=1', $headers)
            ->assertOk()
            ->assertJsonPath('data.items.total', 2)
            ->assertJsonCount(1, 'data.items.data')
            ->assertJsonPath('data.items.data.0.id', $newerRefund->id)
            ->assertJsonPath('data.items.data.0.order', [
                'id' => $order->id,
                'order_number' => $order->order_number,
            ])
            ->assertJsonPath('data.items.data.0.reseller.name', $reseller->name);

        $this->getJson(self::ENDPOINT.'?per_page=1&page=2', $headers)
            ->assertOk()
            ->assertJsonPath('data.items.data.0.id', $olderRefund->id)
            ->assertJsonPath('data.items.data.0.order', null);
    }

    public function test_history_requires_an_accounts_admin(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->getJson(self::HISTORY_ENDPOINT)->assertUnauthorized();

        foreach (['reseller', 'product manager'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->getJson(self::ENDPOINT, $this->headersFor($user))->assertForbidden();
            $this->getJson(self::HISTORY_ENDPOINT, $this->headersFor($user))->assertForbidden();
        }
    }

    public function test_advance_history_includes_admin_entered_payments_without_a_wallet_record(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $reseller = User::factory()->create(['user_type' => 'reseller']);
        $order = Order::create([
            'user_id' => $reseller->id,
            'order_number' => 'ORD-ADMIN-ADVANCE',
            'status' => 1,
            'total' => 1130,
            'advance_status' => 'rejected',
            'advance_amount' => 130,
            'advance_payment_method' => 'pay_later',
        ]);
        $accounting = app(OrderAccountingService::class);
        $accounting->approveOrderAdvance($order, $admin->id, 'Verified', true, [
            'advance_trx_id' => 'ADMIN-ADVANCE-123',
            'advance_sender_phone' => '01711223344',
        ]);
        $this->assertDatabaseMissing('reseller_transactions', ['order_id' => $order->id, 'type' => 'order_advance']);
        $headers = $this->headersFor($admin);

        $this->getJson(self::HISTORY_ENDPOINT, $headers)
            ->assertOk()
            ->assertJsonPath('data.items.total', 1)
            ->assertJsonPath('data.items.data.0.type', 'order_advance')
            ->assertJsonPath('data.items.data.0.status', 'approved')
            ->assertJsonPath('data.items.data.0.trx_id', 'ADMIN-ADVANCE-123')
            ->assertJsonPath('data.items.data.0.order_number', $order->order_number)
            ->assertJsonPath('data.items.data.0.reseller_name', $reseller->name);

        $this->travel(1)->seconds();
        $accounting->refundOrderAdvance($order);
        $this->getJson(self::HISTORY_ENDPOINT.'?per_page=1', $headers)
            ->assertOk()
            ->assertJsonPath('data.items.total', 2)
            ->assertJsonPath('data.items.data.0.type', 'advance_refund');
        $this->getJson(self::HISTORY_ENDPOINT.'?per_page=1&page=2', $headers)
            ->assertOk()
            ->assertJsonPath('data.items.data.0.type', 'order_advance');
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
