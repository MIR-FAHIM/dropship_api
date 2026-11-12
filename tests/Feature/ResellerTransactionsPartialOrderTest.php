<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders loaded with only a few columns (as a nested summary) must serialize
 * without the computed fields that need `status` — that used to crash with
 * "Undefined property: App\Models\Order::$status".
 */
class ResellerTransactionsPartialOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_transactions_with_linked_orders_load(): void
    {
        $reseller = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $order = Order::create(['user_id' => $reseller->id, 'order_number' => 'ORD-TX-1', 'status' => 9, 'customer_name' => 'Rahim']);
        ResellerTransaction::create([
            'reseller_id' => $reseller->id, 'amount' => 150, 'trx_id' => 'TX-1', 'trx_type' => 'credit',
            'type' => 'order_settlement', 'order_id' => $order->id, 'status' => 'completed',
        ]);
        $headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($reseller, ['basic'])['plain']];

        $response = $this->getJson("/api/transactions/reseller?page=1&reseller_id={$reseller->id}", $headers)->assertOk();

        $this->assertStringContainsString('ORD-TX-1', $response->getContent());
    }

    public function test_partial_order_skips_computed_fields_but_full_order_keeps_them(): void
    {
        $order = Order::create(['order_number' => 'ORD-TX-2', 'status' => 6]);

        $partial = Order::select('id', 'order_number')->find($order->id)->toArray();
        $this->assertSame(['id' => $order->id, 'order_number' => 'ORD-TX-2'], $partial);

        $full = Order::find($order->id)->toArray();
        $this->assertArrayHasKey('is_carrybee_paid', $full);
        $this->assertArrayHasKey('has_consignment', $full);
    }
}
