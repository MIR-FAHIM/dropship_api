<?php

namespace Tests\Feature;

use App\Models\User;
use App\Service\ApiTokenService;
use App\Service\SellerTeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Passive Income phase 1: seller code after 10 delivered orders, sign-up with a code, 4-level team, admin tools. */
class SellerTeamTest extends TestCase
{
    use RefreshDatabase;

    private function drop(array $attrs = []): User
    {
        return User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0] + $attrs);
    }

    private function delivered(User $u, int $n): void
    {
        $u->orders()->createMany(array_fill(0, $n, ['status' => 9]));
    }

    private function seller(array $attrs = []): User
    {
        $u = $this->drop($attrs);

        return app(SellerTeamService::class)->verify($u, null, 'test');
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain']];
    }

    private function register(array $extra = [])
    {
        static $n = 0;
        $n++;

        return $this->postJson('/api/dropshipper-register', $extra + [
            'name' => "New Seller {$n}", 'email' => "new{$n}@test.com", 'phone' => '0171100000' . $n, 'password' => 'secret12',
        ]);
    }

    public function test_seller_code_comes_at_the_tenth_delivered_order_not_before(): void
    {
        $u = $this->drop();
        $this->delivered($u, 9);
        $u->orders()->create(['status' => 1]); // not delivered
        $team = app(SellerTeamService::class);

        $this->assertFalse($team->checkVerification($u));
        $this->getJson('/api/seller-team/summary', $this->h($u))->assertOk()
            ->assertJsonPath('data.verified', false)->assertJsonPath('data.orders_left', 1)->assertJsonPath('data.seller_code', null);

        $this->delivered($u, 1);
        $res = $this->getJson('/api/seller-team/summary', $this->h($u))->assertOk()->assertJsonPath('data.verified', true);
        $this->assertMatchesRegularExpression('/^RS-[A-Z2-9]{6}$/', $res->json('data.seller_code'));
        $this->assertSame(1, DB::table('notifications')->where('send_to', $u->id)->where('module', 'seller_team')->count());
        $this->assertFalse($team->checkVerification($u->fresh())); // only once
    }

    public function test_signup_with_code_joins_the_team_and_bad_codes_are_refused(): void
    {
        $seller = $this->seller(['name' => 'Rahim Uddin', 'phone' => '01799999999']);

        $this->getJson("/api/seller-team/code/" . strtolower($seller->seller_code))->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.seller_name', 'Rahim U.');
        $this->getJson('/api/seller-team/code/RS-ZZZZZZ')->assertOk()->assertJsonPath('data.valid', false);

        $this->register(['seller_code' => $seller->seller_code])->assertCreated();
        $new = User::where('email', 'like', 'new%')->latest('id')->first();
        $this->assertSame($seller->id, $new->referred_by);
        $this->assertSame('joined', DB::table('seller_team_logs')->where('user_id', $new->id)->value('action'));

        // Wrong code → 422 and no account made.
        $before = User::count();
        $this->register(['seller_code' => 'RS-ZZZZZZ'])->assertStatus(422)->assertJsonValidationErrors('seller_code');
        // Same phone as the code owner → refused.
        $this->register(['seller_code' => $seller->seller_code, 'phone' => '+8801799999999'])->assertStatus(422);
        $this->assertSame($before, User::count());
        // No code is fine.
        $this->register()->assertCreated();
    }

    public function test_code_of_an_unverified_or_banned_seller_does_not_work(): void
    {
        $seller = $this->seller();
        $admin = User::factory()->create(['user_type' => 'admin', 'banned' => 0]);
        $this->postJson("/api/admin/seller-team/users/{$seller->id}/unverify", ['reason' => 'fake orders'], $this->h($admin))->assertOk();
        $this->getJson("/api/seller-team/code/{$seller->seller_code}")->assertJsonPath('data.valid', false);

        $banned = $this->seller();
        $banned->forceFill(['banned' => 1])->save();
        $this->getJson("/api/seller-team/code/{$banned->seller_code}")->assertJsonPath('data.valid', false);
    }

    public function test_four_levels_of_team_and_privacy_of_deeper_levels(): void
    {
        $top = $this->seller();
        $chain = [$top];
        for ($i = 1; $i <= 5; $i++) { // 5 below: the 5th is level 5 and is NOT part of the team
            $next = $i < 5 ? $this->seller(['name' => "Member {$i} Khan", 'phone' => "0181234567{$i}"]) : $this->drop(['name' => 'Member 5 Khan']);
            $next->forceFill(['referred_by' => end($chain)->id, 'referred_at' => now()])->save();
            $chain[] = $next;
        }
        $this->delivered($chain[1], 3);

        $sum = $this->getJson('/api/seller-team/summary', $this->h($top))->assertOk();
        $this->assertSame([1, 1, 1, 1], collect($sum->json('data.levels'))->pluck('members')->all());
        $this->assertSame(4, $sum->json('data.team_size'));

        $l1 = $this->getJson('/api/seller-team/members?level=1', $this->h($top))->assertOk();
        $this->assertSame('Member 1 Khan', $l1->json('data.members.0.name'));
        $this->assertSame(3, $l1->json('data.members.0.delivered_orders'));
        $this->assertNotNull($l1->json('data.members.0.phone'));

        $l3 = $this->getJson('/api/seller-team/members?level=3', $this->h($top))->assertOk();
        $this->assertSame('Member 3.', $l3->json('data.members.0.name')); // first name + initial only
        $this->assertSame('Member 2.', $l3->json('data.members.0.via'));
        $this->assertNull($l3->json('data.members.0.phone'));

        // The level-1 member sees their own upline, not the whole tree above.
        $this->getJson('/api/seller-team/summary', $this->h($chain[1]))->assertJsonPath('data.team_size', 4); // members 2,3,4,5 = its 4 levels
    }

    public function test_admin_changes_upline_with_reason_and_loops_are_refused(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'banned' => 0]);
        $a = $this->seller();
        $b = $this->seller();
        $c = $this->drop();
        $b->forceFill(['referred_by' => $a->id])->save();

        // Reason is required.
        $this->putJson("/api/admin/seller-team/users/{$c->id}/upline", ['upline_id' => $b->id], $this->h($admin))->assertStatus(422);
        $this->putJson("/api/admin/seller-team/users/{$c->id}/upline", ['seller_code' => $b->seller_code, 'reason' => 'joined offline'], $this->h($admin))
            ->assertOk()->assertJsonPath('data.uplines.0.id', $b->id)->assertJsonPath('data.uplines.1.id', $a->id);

        // a under c would be a loop (c → b → a → c).
        $this->putJson("/api/admin/seller-team/users/{$a->id}/upline", ['upline_id' => $c->id, 'reason' => 'test'], $this->h($admin))->assertStatus(422);
        // Self is refused.
        $this->putJson("/api/admin/seller-team/users/{$a->id}/upline", ['upline_id' => $a->id, 'reason' => 'test'], $this->h($admin))->assertStatus(422);
        // Clear.
        $this->putJson("/api/admin/seller-team/users/{$c->id}/upline", ['upline_id' => null, 'reason' => 'wrong team'], $this->h($admin))->assertOk();
        $this->assertNull($c->fresh()->referred_by);
        $this->assertSame(2, DB::table('seller_team_logs')->where('user_id', $c->id)->where('action', 'upline_changed')->count());

        // A dropshipper can't use admin tools.
        $this->putJson("/api/admin/seller-team/users/{$c->id}/upline", ['upline_id' => $a->id, 'reason' => 'x'], $this->h($b))->assertStatus(403);
    }

    public function test_upline_cannot_be_set_by_the_user_through_registration_fields(): void
    {
        $seller = $this->seller();
        $this->register(['referred_by' => $seller->id])->assertCreated();
        $this->assertNull(User::latest('id')->first()->referred_by);
    }
}
