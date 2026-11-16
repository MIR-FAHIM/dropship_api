<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\PasswordChangeLog;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $dropshipper;
    private array $adminHeaders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->adminHeaders = $this->headersFor($this->admin, ['basic', 'admin']);
        $this->dropshipper = User::factory()->create([
            'user_type' => 'dropshipper',
            'banned' => 0,
            'password' => Hash::make('old-password-1'),
        ]);
    }

    private function headersFor(User $user, array $scopes = ['basic']): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($user, $scopes)['plain']];
    }

    public function test_dropshipper_changes_own_password_and_other_sessions_are_revoked(): void
    {
        $current = $this->headersFor($this->dropshipper);
        $this->headersFor($this->dropshipper); // a second signed-in device

        $this->postJson('/api/users/change-password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-2',
            'password_confirmation' => 'new-password-2',
        ], $current)->assertOk();

        $this->assertTrue(Hash::check('new-password-2', $this->dropshipper->fresh()->password));

        $tokens = ApiToken::where('user_id', $this->dropshipper->id)->get();
        $this->assertSame(1, $tokens->where('is_revoked', false)->count(), 'only the current session stays signed in');

        $log = PasswordChangeLog::sole();
        $this->assertSame($this->dropshipper->id, $log->user_id);
        $this->assertSame($this->dropshipper->id, $log->changed_by);
        $this->assertSame(PasswordChangeLog::TYPE_SELF_CHANGE, $log->change_type);
        $this->assertArrayNotHasKey('password', $log->getAttributes());

        // The current session still works.
        $this->postJson('/api/users/change-password', [
            'current_password' => 'new-password-2',
            'password' => 'new-password-3',
            'password_confirmation' => 'new-password-3',
        ], $current)->assertOk();
    }

    public function test_wrong_current_password_is_rejected_and_not_logged(): void
    {
        $this->postJson('/api/users/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-2',
            'password_confirmation' => 'new-password-2',
        ], $this->headersFor($this->dropshipper))
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'Current password is incorrect.');

        $this->assertTrue(Hash::check('old-password-1', $this->dropshipper->fresh()->password));
        $this->assertSame(0, PasswordChangeLog::count());
    }

    public function test_change_password_validates_confirmation_length_and_difference(): void
    {
        $headers = $this->headersFor($this->dropshipper);

        $this->postJson('/api/users/change-password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-2',
            'password_confirmation' => 'something-else',
        ], $headers)->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);

        $this->postJson('/api/users/change-password', [
            'current_password' => 'old-password-1',
            'password' => 'abcd', // 4 characters: below the 5 minimum
            'password_confirmation' => 'abcd',
        ], $headers)->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);

        $this->postJson('/api/users/change-password', [
            'current_password' => 'old-password-1',
            'password' => 'old-password-1',
            'password_confirmation' => 'old-password-1',
        ], $headers)->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);

        $this->assertSame(0, PasswordChangeLog::count());
    }

    public function test_five_character_password_is_accepted(): void
    {
        $this->postJson('/api/users/change-password', [
            'current_password' => 'old-password-1',
            'password' => 'abcde',
            'password_confirmation' => 'abcde',
        ], $this->headersFor($this->dropshipper))->assertOk();
        $this->assertTrue(Hash::check('abcde', $this->dropshipper->fresh()->password));

        $this->postJson("/api/users/{$this->dropshipper->id}/reset-password", [
            'password' => '12345',
            'password_confirmation' => '12345',
        ], $this->adminHeaders)->assertOk();
        $this->assertTrue(Hash::check('12345', $this->dropshipper->fresh()->password));

        $this->postJson("/api/users/{$this->dropshipper->id}/reset-password", [
            'password' => '1234',
            'password_confirmation' => '1234',
        ], $this->adminHeaders)->assertStatus(422);
    }

    public function test_change_password_requires_login(): void
    {
        $this->postJson('/api/users/change-password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-2',
            'password_confirmation' => 'new-password-2',
        ])->assertStatus(401);
    }

    public function test_admin_resets_dropshipper_password_and_all_sessions_are_revoked(): void
    {
        $this->headersFor($this->dropshipper);

        $this->postJson("/api/users/{$this->dropshipper->id}/reset-password", [
            'password' => 'admin-set-pass',
            'password_confirmation' => 'admin-set-pass',
            'note' => 'Forgot password, called support',
        ], $this->adminHeaders)->assertOk();

        $this->assertTrue(Hash::check('admin-set-pass', $this->dropshipper->fresh()->password));
        $this->assertSame(0, ApiToken::where('user_id', $this->dropshipper->id)->where('is_revoked', false)->count());

        $log = PasswordChangeLog::sole();
        $this->assertSame($this->dropshipper->id, $log->user_id);
        $this->assertSame($this->admin->id, $log->changed_by);
        $this->assertSame(PasswordChangeLog::TYPE_ADMIN_RESET, $log->change_type);
        $this->assertSame('Forgot password, called support', $log->note);
    }

    public function test_non_admin_cannot_reset_another_users_password(): void
    {
        $other = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        $this->postJson("/api/users/{$this->dropshipper->id}/reset-password", [
            'password' => 'hijacked-pass',
            'password_confirmation' => 'hijacked-pass',
        ], $this->headersFor($other))->assertStatus(403);

        $this->assertTrue(Hash::check('old-password-1', $this->dropshipper->fresh()->password));
        $this->assertSame(0, PasswordChangeLog::count());
    }

    public function test_admin_reset_only_targets_dropshippers_and_validates(): void
    {
        $this->postJson("/api/users/{$this->admin->id}/reset-password", [
            'password' => 'admin-set-pass',
            'password_confirmation' => 'admin-set-pass',
        ], $this->adminHeaders)->assertStatus(404);

        $this->postJson("/api/users/{$this->dropshipper->id}/reset-password", [
            'password' => 'admin-set-pass',
            'password_confirmation' => 'mismatch-pass',
        ], $this->adminHeaders)->assertStatus(422);

        $this->assertSame(0, PasswordChangeLog::count());
    }

    public function test_admin_profile_edit_with_password_is_logged(): void
    {
        $this->patchJson("/api/users/edituserprofile/{$this->dropshipper->id}", [
            'password' => 'edited-pass-1',
        ], $this->adminHeaders)->assertOk();

        $log = PasswordChangeLog::sole();
        $this->assertSame(PasswordChangeLog::TYPE_ADMIN_RESET, $log->change_type);
        $this->assertSame($this->admin->id, $log->changed_by);
    }

    public function test_admin_can_list_and_filter_password_logs(): void
    {
        $other = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0, 'name' => 'Karim Unique']);
        PasswordChangeLog::record($this->dropshipper, $this->dropshipper, PasswordChangeLog::TYPE_SELF_CHANGE);
        PasswordChangeLog::record($this->dropshipper, $this->admin, PasswordChangeLog::TYPE_ADMIN_RESET, null, 'phone call');
        PasswordChangeLog::record($other, null, PasswordChangeLog::TYPE_OTP_RESET);

        $all = $this->getJson('/api/users/password-logs', $this->adminHeaders)->assertOk()->json('data');
        $this->assertSame(3, $all['total']);
        $this->assertArrayHasKey('changed_by', $all['data'][0]);

        $mine = $this->getJson('/api/users/password-logs?user_id=' . $this->dropshipper->id, $this->adminHeaders)->assertOk()->json('data');
        $this->assertSame(2, $mine['total']);

        $resets = $this->getJson('/api/users/password-logs?change_type=admin_reset', $this->adminHeaders)->assertOk()->json('data');
        $this->assertSame(1, $resets['total']);
        $this->assertSame($this->admin->id, $resets['data'][0]['changed_by']['id']);
        $this->assertSame('phone call', $resets['data'][0]['note']);

        $search = $this->getJson('/api/users/password-logs?search=Karim%20Unique', $this->adminHeaders)->assertOk()->json('data');
        $this->assertSame(1, $search['total']);
        $this->assertSame($other->id, $search['data'][0]['user_id']);
    }

    public function test_password_logs_are_admin_only(): void
    {
        $this->getJson('/api/users/password-logs', $this->headersFor($this->dropshipper))->assertStatus(403);
    }
}
