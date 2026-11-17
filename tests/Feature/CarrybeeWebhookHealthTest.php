<?php

namespace Tests\Feature;

use App\Models\CarrybeeWebhookEvent;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// The health board must count only webhooks CarryBee itself sent. Sync, simulator,
// manual and Postman rows look like events too, and once made the board say
// "Receiving" for accounts CarryBee had never sent anything for.
class CarrybeeWebhookHealthTest extends TestCase
{
    use RefreshDatabase;

    private array $adminHeaders;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->adminHeaders = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
    }

    private function vendor(string $name, ?string $storeId): Vendor
    {
        $user = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);

        return Vendor::create(['user_id' => $user->id, 'shop_name' => $name, 'has_own_delivery_account' => true, 'carryb_store_id' => $storeId]);
    }

    private function order(Vendor $vendor): Order
    {
        return Order::create(['user_id' => $vendor->user_id, 'vendor_id' => $vendor->id, 'order_number' => 'ORD-' . Str::random(6), 'status' => 4]);
    }

    private function event(array $attrs, string $at = null): CarrybeeWebhookEvent
    {
        $event = CarrybeeWebhookEvent::create(array_merge([
            'fingerprint' => Str::random(40),
            'event' => 'order.picked',
            'consignment_id' => 'F' . Str::random(8),
            'signature_valid' => true,
            'processing_status' => 'processed',
            'payload' => ['event' => 'order.picked'],
        ], $attrs));
        if ($at) {
            $event->forceFill(['created_at' => $at])->save();
        }

        return $event;
    }

    private function realHeaders(): array
    {
        return ['user-agent' => ['CarryBee-Webhook'], 'x-carrybee-webhook-signature' => ['secret'], 'content-type' => ['application/json']];
    }

    private function health(int $days = 7): array
    {
        return $this->getJson("/api/delivery-companies/carrybee/webhook-health?days={$days}", $this->adminHeaders)->assertOk()->json('data');
    }

    private function vendorRow(array $health, string $name): array
    {
        return collect($health['own_account_vendors'])->firstWhere('shop_name', $name);
    }

    public function test_source_is_read_from_the_stored_event(): void
    {
        $this->assertSame('carrybee', $this->event(['request_headers' => $this->realHeaders(), 'store_id' => '1'])->source());
        $this->assertSame('sync', $this->event(['request_headers' => ['x-resellerbrain-source' => ['catch-up']], 'store_id' => '1'])->source());
        $this->assertSame('reprocess', $this->event(['request_headers' => ['x-resellerbrain-source' => ['webhook']]])->source());
        $this->assertSame('simulator', $this->event(['request_headers' => ['X-Simulated-By' => 'admin']])->source());
        $this->assertSame('dev_test', $this->event(['request_headers' => ['postman-token' => ['x'], 'x-carrybee-webhook-signature' => ['s']]])->source());
        $this->assertSame('manual', $this->event(['payload' => ['source' => 'manual_admin_action']])->source());
        $this->assertSame('manual', $this->event(['request_headers' => null, 'signature_valid' => false, 'store_id' => null])->source());
        // A real CarryBee call with a wrong secret is still CarryBee calling us.
        $this->assertSame('carrybee', $this->event(['request_headers' => $this->realHeaders(), 'signature_valid' => false, 'processing_status' => 'rejected'])->source());
    }

    public function test_sync_and_manual_events_do_not_count_as_receiving(): void
    {
        $ful = $this->vendor('Ful basori shop', '33640');
        $order = $this->order($ful);
        $this->event(['order_id' => $order->id, 'store_id' => '33640', 'request_headers' => ['x-resellerbrain-source' => ['catch-up']]]);
        $this->event(['order_id' => $order->id, 'payload' => ['source' => 'manual_admin_action'], 'event' => 'order.paid']);

        $row = $this->vendorRow($this->health(), 'Ful basori shop');
        $this->assertSame('never', $row['state']);
        $this->assertFalse($row['healthy']);
        $this->assertSame(0, $row['events']);
        $this->assertNull($row['last_real_event_at']);
        $this->assertSame(['sync' => 1, 'manual' => 1], $row['other_events']);
        $this->assertNotNull($row['last_other_event_at']);
    }

    public function test_real_carrybee_events_show_receiving_with_last_real_time(): void
    {
        $bongo = $this->vendor('Bongobox', '30070');
        $order = $this->order($bongo);
        $this->event(['order_id' => $order->id, 'store_id' => '30070', 'request_headers' => $this->realHeaders()], now()->subHours(2)->toDateTimeString());
        $this->event(['order_id' => $order->id, 'store_id' => '30070', 'request_headers' => ['x-resellerbrain-source' => ['catch-up']]]);

        $health = $this->health();
        $row = $this->vendorRow($health, 'Bongobox');
        $this->assertSame('receiving', $row['state']);
        $this->assertTrue($row['healthy']);
        $this->assertSame(1, $row['events']);
        $this->assertSame(['processed' => 1], $row['by_status']);
        $this->assertSame(now()->subHours(2)->toDateTimeString(), $row['last_real_event_at']);
        $this->assertSame(1, $health['real_events']);
        $this->assertSame($row['last_real_event_at'], $health['last_real_event_at']);
    }

    public function test_only_rejected_real_events_mean_secret_is_wrong(): void
    {
        $this->vendor('Ayan BD', '31651');
        $this->event(['store_id' => '31651', 'request_headers' => $this->realHeaders(), 'signature_valid' => false, 'processing_status' => 'rejected']);

        $this->assertSame('secret_wrong', $this->vendorRow($this->health(), 'Ayan BD')['state']);
    }

    public function test_old_real_events_outside_the_window_show_quiet_with_their_date(): void
    {
        $v = $this->vendor('Old shop', '40000');
        $this->event(['order_id' => $this->order($v)->id, 'store_id' => '40000', 'request_headers' => $this->realHeaders()], now()->subDays(20)->toDateTimeString());

        $row = $this->vendorRow($this->health(7), 'Old shop');
        $this->assertSame('quiet', $row['state']);
        $this->assertSame(0, $row['events']);
        $this->assertSame(now()->subDays(20)->toDateTimeString(), $row['last_real_event_at']);
    }

    public function test_store_id_mismatch_is_flagged_from_what_carrybee_reports(): void
    {
        $ayan = $this->vendor('Ayan BD', '31650'); // saved with a typo
        $this->event(['order_id' => $this->order($ayan)->id, 'store_id' => '31651', 'request_headers' => ['x-resellerbrain-source' => ['catch-up']]]);
        $ok = $this->vendor('Bongobox', '30070');
        $this->event(['order_id' => $this->order($ok)->id, 'store_id' => '30070', 'request_headers' => ['x-resellerbrain-source' => ['catch-up']]]);

        $health = $this->health();
        $ayanRow = $this->vendorRow($health, 'Ayan BD');
        $this->assertTrue($ayanRow['store_mismatch']);
        $this->assertSame(['31651'], $ayanRow['seen_store_ids']);
        $this->assertFalse($this->vendorRow($health, 'Bongobox')['store_mismatch']);
    }
}
