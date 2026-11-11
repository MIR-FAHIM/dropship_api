<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WithdrawRequest;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisburseDropshipperProfitGuardTest extends TestCase
{
    use RefreshDatabase;

    private function headersFor(User $user): array
    {
        $token = ApiTokenService::create($user, ['basic']);

        return ['Authorization' => 'Bearer '.$token['plain']];
    }

    private function url(int $orderId): string
    {
        return "/api/order-settlements/orders/{$orderId}/disburse-reseller-profit";
    }

    public function test_non_admin_cannot_disburse_profit(): void
    {
        $dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $order = $dropshipper->orders()->create(['status' => 9]);

        $this->postJson($this->url($order->id), [
            'amount' => 100,
            'payment_method' => 'wallet',
            'payout_review_confirmed' => true,
        ], $this->headersFor($dropshipper))->assertForbidden();
    }

    public function test_admin_must_confirm_payout_review(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'banned' => 0]);
        $dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $order = $dropshipper->orders()->create(['status' => 9]);

        $this->postJson($this->url($order->id), [
            'amount' => 100,
            'payment_method' => 'wallet',
        ], $this->headersFor($admin))
            ->assertStatus(422)
            ->assertJsonPath('errors.payout_review_confirmed.0', 'Confirm you reviewed the dropshipper balance, withdraw requests and advance payments before paying.');
    }

    public function test_admin_can_pay_out_via_rocket(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'banned' => 0]);
        $dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $order = $dropshipper->orders()->create(['status' => 9]);

        $this->postJson($this->url($order->id), [
            'amount' => 100,
            'payment_method' => 'rocket',
            'trx_id' => 'RKT123',
            'payout_review_confirmed' => true,
        ], $this->headersFor($admin))->assertOk();

        $this->assertTrue(
            WithdrawRequest::where('user_id', $dropshipper->id)->where('payment_method', 'rocket')->exists()
        );
    }
}
