<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAllOrdersAdvanceTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_orders_includes_latest_order_advance_transaction(): void
    {
        $status = OrderStatus::create(['name' => 'Pending', 'type' => 'order', 'is_active' => true]);

        $admin = User::create([
            'name'      => 'Admin User',
            'email'     => 'admin_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'user_type' => 'admin',
            'banned'    => 0,
        ]);
        $token = ApiTokenService::create($admin, ['basic', 'admin'], 30, 'admin-token');

        $withAdvance = Order::create([
            'user_id'      => $admin->id,
            'order_number' => 'ORD-ADV-001',
            'status'       => $status->id,
            'total'        => 1130,
        ]);
        $withoutAdvance = Order::create([
            'user_id'      => $admin->id,
            'order_number' => 'ORD-ADV-002',
            'status'       => $status->id,
            'total'        => 500,
        ]);

        // Superseded MFS submission (older), then the wallet payment that replaced it (newer).
        ResellerTransaction::create([
            'reseller_id' => $admin->id,
            'order_id'    => $withAdvance->id,
            'amount'      => 130,
            'trx_type'    => 'credit',
            'type'        => 'order_advance',
            'status'      => 'rejected',
            'trx_id'      => 'BKASH-OLD',
        ]);
        $current = ResellerTransaction::create([
            'reseller_id' => $admin->id,
            'order_id'    => $withAdvance->id,
            'amount'      => 130,
            'trx_type'    => 'debit',
            'type'        => 'order_advance',
            'source'      => 'wallet',
            'status'      => 'completed',
            'trx_id'      => 'WALLET-NEW',
        ]);
        // A different transaction type on the same order must not be picked up.
        ResellerTransaction::create([
            'reseller_id' => $admin->id,
            'order_id'    => $withAdvance->id,
            'amount'      => 130,
            'trx_type'    => 'credit',
            'type'        => 'advance_refund',
            'source'      => 'wallet',
            'status'      => 'completed',
            'trx_id'      => 'REF-ADV-X',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token['plain']])
            ->getJson('/api/orders/all/orders');

        $response->assertStatus(200);
        $orders = collect($response->json('data.data'))->keyBy('order_number');

        $advance = $orders['ORD-ADV-001']['advance_transaction'];
        $this->assertNotNull($advance);
        $this->assertSame($current->id, $advance['id']);
        $this->assertSame('order_advance', $advance['type']);
        $this->assertSame('debit', $advance['trx_type']);
        $this->assertSame('completed', $advance['status']);

        $refund = $orders['ORD-ADV-001']['advance_refund_transaction'];
        $this->assertNotNull($refund);
        $this->assertSame('advance_refund', $refund['type']);
        $this->assertSame('REF-ADV-X', $refund['trx_id']);

        $this->assertArrayHasKey('advance_transaction', $orders['ORD-ADV-002']);
        $this->assertNull($orders['ORD-ADV-002']['advance_transaction']);
        $this->assertArrayHasKey('advance_refund_transaction', $orders['ORD-ADV-002']);
        $this->assertNull($orders['ORD-ADV-002']['advance_refund_transaction']);
    }
}
