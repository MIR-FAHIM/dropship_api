<?php

namespace Tests\Feature;

use App\Models\MarketerProfile;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Partner Portal F8: public partner sign-up → admin approval, level and the rate it sets.
class MarketerApplicationTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Mail::fake();
        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain'], 'Accept' => 'application/json'];
    }

    private function apply(array $over = [])
    {
        return $this->post('/api/partner/apply', $over + [
            'name' => 'Tanvir Ahmed', 'business_name' => 'ScaleUp Growth Lab', 'email' => 'tanvir@example.com', 'password' => 'secret123',
            'whatsapp' => '+880 1711-000000', 'facebook_page' => 'https://facebook.com/scaleup', 'portfolio_url' => 'https://scaleupads.com',
            'ad_spend_usd' => 18500, 'campaigns_run' => 145, 'niche' => 'gadgets_electronics', 'campaign_type' => 'purchase_website',
            'avatar' => UploadedFile::fake()->createWithContent('me.png', base64_decode(self::PNG)),
            'agency_logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG)),
            'campaign_screenshot' => UploadedFile::fake()->createWithContent('ads.png', base64_decode(self::PNG)),
        ], ['Accept' => 'application/json']);
    }

    private function login(string $email = 'tanvir@example.com', string $password = 'secret123')
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_application_creates_a_pending_pro_partner_that_cannot_sign_in_yet(): void
    {
        $this->apply()->assertCreated()->assertJsonPath('data.level', 'pro')->assertJsonPath('data.application_status', 'pending');

        $user = User::where('email', 'tanvir@example.com')->first();
        $this->assertSame('marketer', $user->user_type);
        $this->assertSame(1, (int) $user->banned);
        $this->assertSame('01711000000', $user->marketerProfile->whatsapp);
        Storage::disk('public')->assertExists($user->marketerProfile->avatar);
        Storage::disk('public')->assertExists($user->marketerProfile->campaign_screenshot);

        $this->login()->assertForbidden()->assertJsonPath('message', 'Your partner application is under review. We will email you when it is approved.');
        // "Activate" can't skip approval.
        $this->patchJson("/api/admin/marketers/{$user->id}/suspension", ['suspended' => false], $this->h($this->admin))->assertStatus(422);
    }

    public function test_level_rule_and_validation(): void
    {
        // Many sign-ups in one minute: the 5/min limit is tested separately below.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->apply(['email' => 'mid@example.com', 'ad_spend_usd' => 9999, 'campaigns_run' => 99])->assertCreated()->assertJsonPath('data.level', 'mid');
        $this->apply(['email' => 'camps@example.com', 'ad_spend_usd' => 0, 'campaigns_run' => 100])->assertCreated()->assertJsonPath('data.level', 'pro');

        $this->apply(['email' => 'x1@example.com', 'whatsapp' => '12345'])->assertUnprocessable();
        $this->apply(['email' => 'x2@example.com', 'facebook_page' => 'not a link'])->assertUnprocessable();
        $this->apply(['email' => 'x3@example.com', 'niche' => 'weapons'])->assertUnprocessable();
        $this->apply(['email' => 'x4@example.com', 'password' => '123'])->assertUnprocessable();
        $this->apply(['email' => 'mid@example.com'])->assertUnprocessable(); // email already used
    }

    public function test_admin_approves_and_the_partner_signs_in_with_the_level_rate(): void
    {
        $this->apply(['ad_spend_usd' => 500, 'campaigns_run' => 5]); // mid
        $user = User::where('email', 'tanvir@example.com')->first();

        $pending = $this->getJson('/api/admin/marketers?status=pending', $this->h($this->admin))->assertOk()->json('data');
        $this->assertSame(1, $pending['pending_count']);
        $this->assertSame($user->id, $pending['data'][0]['id']);

        $this->postJson("/api/admin/marketers/{$user->id}/approve", [], $this->h($this->admin))->assertOk()
            ->assertJsonPath('data.application_status', 'approved')->assertJsonPath('data.commission_rate', 15);
        $this->login()->assertOk();

        // Admin raises the level → Pro rate; a custom rate still wins.
        $this->patchJson("/api/admin/marketers/{$user->id}/level", ['level' => 'pro'], $this->h($this->admin))->assertOk()->assertJsonPath('data.commission_rate', 20);
        $this->assertSame('admin', $user->marketerProfile()->value('level_source'));
        $this->patchJson("/api/admin/marketers/{$user->id}/commission-rate", ['rate' => 25], $this->h($this->admin))->assertOk();
        $this->getJson('/api/marketer/profile', $this->h($user->fresh()))->assertOk()->assertJsonPath('data.commission_rate', 25);
    }

    public function test_admin_rejects_with_a_reason(): void
    {
        $this->apply();
        $user = User::where('email', 'tanvir@example.com')->first();
        $this->postJson("/api/admin/marketers/{$user->id}/reject", [], $this->h($this->admin))->assertUnprocessable();
        $this->postJson("/api/admin/marketers/{$user->id}/reject", ['reason' => 'Portfolio could not be verified'], $this->h($this->admin))->assertOk();

        $this->login()->assertForbidden()->assertJsonPath('message', 'Your partner application was not approved. Reason: Portfolio could not be verified');
    }

    public function test_partner_edits_profile_and_auto_level_follows_unless_admin_set_it(): void
    {
        $this->apply(['ad_spend_usd' => 500, 'campaigns_run' => 5]);
        $user = User::where('email', 'tanvir@example.com')->first();
        $this->postJson("/api/admin/marketers/{$user->id}/approve", [], $this->h($this->admin));
        $h = $this->h($user->fresh());
        $details = ['name' => 'Tanvir A.', 'business_name' => 'ScaleUp', 'whatsapp' => '01811000000', 'facebook_page' => 'https://facebook.com/scaleup',
            'ad_spend_usd' => 12000, 'campaigns_run' => 20, 'niche' => 'gadgets_electronics', 'campaign_type' => 'mixed'];

        $this->post('/api/marketer/profile', $details, $h)->assertOk()->assertJsonPath('data.level', 'pro')->assertJsonPath('data.name', 'Tanvir A.');

        $this->patchJson("/api/admin/marketers/{$user->id}/level", ['level' => 'mid'], $this->h($this->admin));
        $this->post('/api/marketer/profile', $details + ['ad_spend_usd' => 50000], $h)->assertOk()->assertJsonPath('data.level', 'mid');
    }

    public function test_sign_up_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->apply(['email' => "spam{$i}@example.com"])->assertCreated();
        }
        $this->apply(['email' => 'spam6@example.com'])->assertStatus(429);
    }

    public function test_admin_created_marketers_keep_the_default_rate_and_others_cannot_review(): void
    {
        $old = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
        $this->getJson('/api/marketer/profile', $this->h($old))->assertOk()
            ->assertJsonPath('data.application_status', 'approved')->assertJsonPath('data.level', null)->assertJsonPath('data.commission_rate', 20);

        $this->apply();
        $user = User::where('email', 'tanvir@example.com')->first();
        $this->postJson("/api/admin/marketers/{$user->id}/approve", [], $this->h($old))->assertForbidden();
        $this->assertSame(MarketerProfile::STATUS_PENDING, $user->marketerProfile()->value('application_status'));
    }
}
