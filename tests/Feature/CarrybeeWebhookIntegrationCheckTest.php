<?php

namespace Tests\Feature;

use App\Models\CarrybeeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CarryBee merchant panel > Webhook integration > "Add Webhook".
 * CarryBee POSTs {"event":"webhook.integration"} with our secret in
 * X-CB-Webhook-Integration-Header and requires status 202 plus the same header
 * in the response carrying the value shown in that business's panel.
 */
class CarrybeeWebhookIntegrationCheckTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/delivery-companies/carrybee/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'carrybee.webhook.secret' => 'our-secret',
            'carrybee.webhook.integration_value' => '40489fe0-9386-4fc9-8c92-2b2fcb9d451c',
        ]);
    }

    private function check(string $url, ?string $secret = 'our-secret')
    {
        $headers = $secret === null ? [] : ['X-CB-Webhook-Integration-Header' => $secret];

        return $this->postJson($url, ['event' => 'webhook.integration'], $headers);
    }

    public function test_returns_202_and_the_panel_value_in_the_header(): void
    {
        $this->check(self::URL)
            ->assertStatus(202)
            ->assertHeader('X-CB-Webhook-Integration-Header', '40489fe0-9386-4fc9-8c92-2b2fcb9d451c');

        $log = CarrybeeWebhookEvent::sole();
        $this->assertSame('webhook.integration', $log->event);
        $this->assertSame('processed', $log->processing_status);
        $this->assertSame('carrybee', $log->source(), 'counts as a real CarryBee call on the health board');
    }

    public function test_each_business_can_put_its_own_value_in_the_url(): void
    {
        $this->check(self::URL . '?cb=11111111-2222-3333-4444-555555555555')
            ->assertStatus(202)
            ->assertHeader('X-CB-Webhook-Integration-Header', '11111111-2222-3333-4444-555555555555');
    }

    public function test_quoted_secret_is_accepted(): void
    {
        // CarryBee's examples wrap the value in double quotes.
        $this->check(self::URL, '"our-secret"')->assertStatus(202);
    }

    public function test_wrong_or_missing_secret_is_refused_without_the_header(): void
    {
        $this->check(self::URL, 'wrong')->assertStatus(401)->assertHeaderMissing('X-CB-Webhook-Integration-Header');
        $this->check(self::URL, null)->assertStatus(401)->assertHeaderMissing('X-CB-Webhook-Integration-Header');
        $this->assertSame(2, CarrybeeWebhookEvent::where('processing_status', 'rejected')->count());
    }

    public function test_missing_value_is_reported(): void
    {
        config(['carrybee.webhook.integration_value' => '']);
        $this->check(self::URL)->assertStatus(500)->assertJsonPath('message', 'CARRYBEE_WEBHOOK_INTEGRATION_VALUE is not set and the URL has no ?cb= value');
    }

    public function test_normal_events_accept_the_secret_in_the_integration_header_too(): void
    {
        $this->postJson(self::URL, [
            'event' => 'order.picked', 'store_id' => '1', 'consignment_id' => 'FX-UNKNOWN',
            'merchant_order_id' => 'NOPE', 'timestamptz' => '2026-11-17T10:00:00+06:00',
        ], ['X-CB-Webhook-Integration-Header' => 'our-secret'])->assertSuccessful();

        $this->assertNotSame('rejected', CarrybeeWebhookEvent::latest('id')->value('processing_status'));
    }
}
