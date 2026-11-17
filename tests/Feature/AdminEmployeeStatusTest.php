<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\PasswordChangeLog;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Employees page (ban / reactivate admins) and admin changing their own password.
class AdminEmployeeStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $colleague;
    private array $myHeaders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0, 'password' => Hash::make('admin-old-1')]);
        $this->colleague = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->myHeaders = $this->headersFor($this->me);
    }

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($user, ['basic', 'admin'])['plain']];
    }

    private function setStatus(User $target, bool $banned, ?array $headers = null)
    {
        return $this->patchJson("/api/users/{$target->id}/employee-status", ['banned' => $banned], $headers ?? $this->myHeaders);
    }

    public function test_admin_bans_and_reactivates_an_employee(): void
    {
        $this->headersFor($this->colleague); // colleague is signed in somewhere

        $this->setStatus($this->colleague, true)->assertOk()->assertJsonPath('data.banned', 1);
        $this->assertSame(1, $this->colleague->fresh()->banned);
        $this->assertSame(0, ApiToken::where('user_id', $this->colleague->id)->where('is_revoked', false)->count(), 'banned employee is signed out');

        // Same value again is not a toggle.
        $this->setStatus($this->colleague, true)->assertOk();
        $this->assertSame(1, $this->colleague->fresh()->banned);

        $this->setStatus($this->colleague, false)->assertOk()->assertJsonPath('data.banned', 0);
        $this->assertSame(0, $this->colleague->fresh()->banned);
    }

    public function test_cannot_ban_yourself(): void
    {
        $this->setStatus($this->me, true)->assertStatus(422);
        $this->assertSame(0, $this->me->fresh()->banned);
    }

    public function test_last_active_admin_cannot_be_banned(): void
    {
        $this->setStatus($this->colleague, true)->assertOk(); // only "me" is still an active admin

        // A superadmin (not an admin-type account) can't ban the last active admin.
        $super = User::factory()->create(['user_type' => 'superadmin', 'role' => 'superadmin', 'banned' => 0]);
        $this->setStatus($this->me, true, $this->headersFor($super))
            ->assertStatus(422)->assertJsonPath('message', 'At least one admin must stay active.');
        $this->assertSame(0, $this->me->fresh()->banned);
    }

    public function test_banned_employee_is_signed_out_at_once(): void
    {
        $colleagueHeaders = $this->headersFor($this->colleague);
        $this->setStatus($this->colleague, true)->assertOk();

        // Their existing session no longer works.
        $this->getJson('/api/users/admin-list', $colleagueHeaders)->assertStatus(401);
    }

    public function test_only_admin_accounts_and_only_admins_can_change(): void
    {
        $dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->setStatus($dropshipper, true)->assertStatus(404);

        $this->setStatus($this->colleague, true, $this->headersFor($dropshipper))->assertStatus(403);
        $this->assertSame(0, $this->colleague->fresh()->banned);

        $this->patchJson("/api/users/{$this->colleague->id}/employee-status", [], $this->myHeaders)->assertStatus(422);
    }

    public function test_admin_changes_own_password(): void
    {
        $this->postJson('/api/users/change-password', [
            'current_password' => 'admin-old-1',
            'password' => 'admin-new-2',
            'password_confirmation' => 'admin-new-2',
        ], $this->myHeaders)->assertOk();

        $this->assertTrue(Hash::check('admin-new-2', $this->me->fresh()->password));
        $log = PasswordChangeLog::sole();
        $this->assertSame($this->me->id, $log->user_id);
        $this->assertSame(PasswordChangeLog::TYPE_SELF_CHANGE, $log->change_type);
    }
}
