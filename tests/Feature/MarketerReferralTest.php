<?php

namespace Tests\Feature;

use App\Models\MarketerClient;
use App\Models\Order;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Partner Portal F3: referral code on the Overview, joining by code, and the overview period.
class MarketerReferralTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketer = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0, 'name' => 'Tanvir', 'business_name' => 'ScaleUp Growth Lab']);
    }

    private function headers(User $user, array $scopes = ['basic']): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($user, $scopes)['plain']];
    }

    /** A dropshipper signed in themselves (their login token may give consent). */
    private function dropshipperHeaders(User $d): array
    {
        return $this->headers($d, ['basic', 'marketer-client-consent']);
    }

    private function code(): string
    {
        return $this->getJson('/api/marketer/overview', $this->headers($this->marketer))->assertOk()->json('data.ref_code');
    }

    public function test_overview_gives_a_stable_referral_code(): void
    {
        $code = $this->code();
        $this->assertSame("RB-SCALEUPG{$this->marketer->id}", $code);
        $this->assertSame($code, $this->code(), 'same code every time');
    }

    public function test_dropshipper_joins_a_marketer_with_the_code_after_consent(): void
    {
        $code = $this->code();
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $h = $this->dropshipperHeaders($d);

        $this->postJson('/api/marketer-client/referral/preview', ['ref_code' => 'RB-NOPE999'], $h)->assertNotFound();
        $this->postJson('/api/marketer-client/referral/preview', ['ref_code' => strtolower($code)], $h)->assertOk()
            ->assertJsonPath('data.business_name', 'ScaleUp Growth Lab')->assertJsonStructure(['data' => ['consent_text']]);
        $this->postJson('/api/marketer-client/referral/accept', ['ref_code' => $code], $h)->assertUnprocessable(); // no consent
        $this->postJson('/api/marketer-client/referral/accept', ['ref_code' => $code, 'consent' => true], $h)->assertCreated();

        $this->assertTrue(MarketerClient::where('marketer_user_id', $this->marketer->id)->where('active_dropshipper_user_id', $d->id)->exists());
        $this->postJson('/api/marketer-client/referral/accept', ['ref_code' => $code, 'consent' => true], $h)->assertStatus(409); // already linked
        $this->postJson('/api/marketer-client/referral/preview', ['ref_code' => $code], $h)->assertStatus(409);
    }

    public function test_only_the_dropshipper_themselves_can_join_and_banned_marketers_are_hidden(): void
    {
        $code = $this->code();
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        // Token without the consent scope (e.g. admin "login as") cannot accept.
        $this->postJson('/api/marketer-client/referral/accept', ['ref_code' => $code, 'consent' => true], $this->headers($d))->assertForbidden();
        // Another marketer cannot use it.
        $other = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
        $this->postJson('/api/marketer-client/referral/accept', ['ref_code' => $code, 'consent' => true], $this->headers($other))->assertForbidden();

        $this->marketer->update(['banned' => 1]);
        $this->postJson('/api/marketer-client/referral/preview', ['ref_code' => $code], $this->dropshipperHeaders($d))->assertNotFound();
    }

    public function test_overview_period_narrows_orders_but_not_the_tour_goal(): void
    {
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $client = MarketerClient::create(['marketer_user_id' => $this->marketer->id, 'dropshipper_user_id' => $d->id,
            'active_dropshipper_user_id' => $d->id, 'consent_version' => MarketerClient::CONSENT_VERSION, 'accepted_at' => now()]);
        $make = fn (int $status, $date) => tap(Order::create(['user_id' => $d->id, 'order_number' => 'O-' . uniqid(), 'status' => $status,
            'customer_name' => 'C', 'customer_phone' => '017', 'total' => 1000, 'marketer_client_id' => $client->id]))
            ->forceFill(['created_at' => $date])->save();
        $make(9, now()->subDays(2));
        $make(9, now()->subDays(60));
        $make(1, now()->subDays(1));

        $h = $this->headers($this->marketer);
        $all = $this->getJson('/api/marketer/overview', $h)->assertOk()->json('data');
        $this->assertSame(3, $all['totals']['orders_placed']);
        $this->assertSame(2, $all['totals']['orders_delivered']);

        $week = $this->getJson('/api/marketer/overview?period=7d', $h)->assertOk()->json('data');
        $this->assertSame('7d', $week['period']);
        $this->assertSame(2, $week['totals']['orders_placed']);
        $this->assertSame(1, $week['totals']['orders_delivered']);
        $this->assertEquals(1000, $week['totals']['sales_volume']);
        $this->assertSame(2, $week['totals']['orders_delivered_all_time'], 'tour goal stays all-time');
    }
}
