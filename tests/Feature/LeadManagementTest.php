<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadProductTag;
use App\Models\Order;
use App\Models\ProductClicks;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $otherAdmin;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->otherAdmin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->h = $this->headersFor($this->admin);
    }

    private function headersFor(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic', 'admin'])['plain']];
    }

    private function create(array $data, ?array $headers = null)
    {
        return $this->postJson('/api/leads', $data, $headers ?? $this->h);
    }

    public function test_phone_is_normalized_and_duplicates_rejected(): void
    {
        $this->assertSame('01711111111', Lead::normalizePhone('+880 1711-111111'));
        $this->assertSame('01711111111', Lead::normalizePhone('1711111111'));

        $id = $this->create(['phone' => '+8801711111111', 'role' => 'supplier', 'note' => 'Saw page'])
            ->assertCreated()->json('data.lead.id');
        $lead = Lead::find($id);
        $this->assertSame('01711111111', $lead->phone_normalized);
        $this->assertSame('supplier', $lead->role);
        $this->assertSame('new', $lead->status);
        $this->assertSame($this->admin->id, $lead->created_by);

        $this->create(['phone' => '01711-111111'])->assertStatus(422)
            ->assertJsonPath('errors.existing_lead_id', $id);
        $this->create(['phone' => 'abc'])->assertStatus(422);
    }

    public function test_new_lead_matching_a_dropshipper_phone_is_linked(): void
    {
        $ds = User::factory()->create(['user_type' => 'dropshipper', 'phone' => '+8801822222222', 'name' => 'Karim', 'banned' => 1]);

        $res = $this->create(['phone' => '01822222222'])->assertCreated();
        $this->assertSame($ds->id, $res->json('data.matched_dropshipper.id'));
        $this->assertSame($ds->id, $res->json('data.lead.user_id'));
        $this->assertSame('Karim', $res->json('data.lead.name'));
        $this->assertSame(1, $res->json('data.lead.user.banned'), 'UI shows "Activate account" for linked inactive accounts');
    }

    public function test_convert_dropshipper_to_lead_once(): void
    {
        $ds = User::factory()->create(['user_type' => 'dropshipper', 'phone' => '01933333333', 'name' => 'Rahim', 'banned' => 0]);

        $res = $this->postJson("/api/leads/from-dropshipper/{$ds->id}", ['note' => 'No orders lately'], $this->h)->assertCreated();
        $this->assertFalse($res->json('data.already_exists'));
        $lead = Lead::find($res->json('data.lead.id'));
        $this->assertSame('dropshipper', $lead->source);
        $this->assertSame($ds->id, $lead->user_id);
        $this->assertSame('Rahim', $lead->name);

        $again = $this->postJson("/api/leads/from-dropshipper/{$ds->id}", [], $this->h)->assertOk();
        $this->assertTrue($again->json('data.already_exists'));
        $this->assertSame(1, Lead::count());

        $vendor = User::factory()->create(['user_type' => 'vendor', 'phone' => '01944444444']);
        $this->postJson("/api/leads/from-dropshipper/{$vendor->id}", [], $this->h)->assertStatus(404);
    }

    public function test_contact_and_followup_count_for_today_and_move_new_to_contacted(): void
    {
        $a = Lead::find($this->create(['phone' => '01700000001'])->json('data.lead.id'));
        $b = Lead::find($this->create(['phone' => '01700000002'])->json('data.lead.id'));
        $this->create(['phone' => '01700000003']);

        $this->postJson("/api/leads/{$a->id}/contact", ['channel' => 'call'], $this->h)->assertOk()->assertJsonPath('data.status', 'contacted');
        $this->postJson("/api/leads/{$a->id}/contact", ['channel' => 'whatsapp'], $this->headersFor($this->otherAdmin))->assertOk();
        // Due now (UTC ISO, as the browser sends it), so it's always within today's schedule.
        $this->putJson("/api/leads/{$b->id}", ['followup_tag' => 'call_later', 'next_call_at' => now()->subMinute()->toIso8601String(),
            'note' => 'Busy, call evening'], $this->h)->assertOk();

        $stats = $this->getJson('/api/leads/stats', $this->h)->assertOk()->json('data');
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['new']);
        $this->assertSame(2, $stats['contacted']);
        $this->assertSame(3, $stats['today_created']);
        $this->assertSame(2, $stats['today_contacted'], 'a lead counts once per day');
        $this->assertSame(1, $stats['scheduled_today']);

        $types = LeadActivity::where('lead_id', $b->id)->pluck('type')->all();
        foreach (['created', 'followup_changed', 'scheduled', 'note', 'status_changed'] as $t) {
            $this->assertContains($t, $types);
        }
        $this->assertSame($this->otherAdmin->id, LeadActivity::where('lead_id', $a->id)->where('type', 'whatsapp')->value('admin_id'));
    }

    public function test_status_converted_sets_date_and_filters_work(): void
    {
        $tag = LeadProductTag::create(['name' => 'Gadgets']);
        $x = $this->create(['phone' => '01600000001', 'name' => 'Alpha Store', 'product_tag_id' => $tag->id])->json('data.lead.id');
        $this->create(['phone' => '01600000002', 'role' => 'supplier']);
        $this->create(['phone' => '01600000003', 'name' => 'Gamma']);

        $this->putJson("/api/leads/{$x}", ['status' => 'converted'], $this->h)->assertOk();
        $this->assertNotNull(Lead::find($x)->converted_at);
        $this->putJson("/api/leads/{$x}", ['status' => 'contacted'], $this->h)->assertOk();
        $this->assertNull(Lead::find($x)->converted_at);

        $count = fn (array $q) => $this->getJson('/api/leads?' . http_build_query($q), $this->h)->assertOk()->json('data.total');
        $this->assertSame(3, $count([]));
        $this->assertSame(1, $count(['role' => 'supplier']));
        $this->assertSame(1, $count(['product_tag_id' => $tag->id]));
        $this->assertSame(1, $count(['search' => 'alpha']));
        $this->assertSame(1, $count(['search' => '600000003']));
        $this->assertSame(1, $count(['status' => 'contacted']));
        $this->assertSame(0, $count(['schedule' => 'today']));
    }

    public function test_every_admin_sees_every_lead_and_non_admins_are_blocked(): void
    {
        $this->create(['phone' => '01500000001']);
        $this->assertSame(1, $this->getJson('/api/leads', $this->headersFor($this->otherAdmin))->json('data.total'));

        $ds = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $dsHeaders = ['Authorization' => 'Bearer ' . ApiTokenService::create($ds, ['basic'])['plain']];
        $this->getJson('/api/leads', $dsHeaders)->assertStatus(403);
        $this->postJson('/api/leads', ['phone' => '01500000009'], $dsHeaders)->assertStatus(403);
    }

    public function test_delete_and_tags(): void
    {
        $id = $this->create(['phone' => '01400000001'])->json('data.lead.id');
        $this->deleteJson("/api/leads/{$id}", [], $this->h)->assertOk();
        $this->assertSame(0, $this->getJson('/api/leads', $this->h)->json('data.total'));

        $this->postJson('/api/leads/tags', ['name' => 'Fashion'], $this->h)->assertCreated();
        $this->postJson('/api/leads/tags', ['name' => 'Fashion'], $this->h)->assertStatus(422);
        $this->assertSame(['Fashion'], collect($this->getJson('/api/leads/tags', $this->h)->json('data'))->pluck('name')->all());
    }

    public function test_dropshipper_list_inactive_filter_and_lead_badge(): void
    {
        $active = User::factory()->create(['user_type' => 'dropshipper', 'phone' => '01311111111', 'banned' => 0]);
        $clicker = User::factory()->create(['user_type' => 'dropshipper', 'phone' => '01322222222', 'banned' => 0]);
        $idle = User::factory()->create(['user_type' => 'dropshipper', 'phone' => '01333333333', 'banned' => 0]);
        $oldOnly = User::factory()->create(['user_type' => 'dropshipper', 'phone' => '01344444444', 'banned' => 0]);

        Order::create(['user_id' => $active->id, 'order_number' => 'O-1', 'status' => 1]);
        ProductClicks::create(['product_id' => 1, 'page' => 'product_details', 'clicked_by' => $clicker->id, 'is_guest' => false]);
        $old = Order::create(['user_id' => $oldOnly->id, 'order_number' => 'O-2', 'status' => 9]);
        $old->forceFill(['created_at' => now()->subDays(45)])->save();

        $ids = collect($this->getJson('/api/users/dropshippers?inactive=1', $this->h)->assertOk()->json('data.data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$idle->id, $oldOnly->id])->sort()->values()->all(), $ids);

        $this->postJson("/api/leads/from-dropshipper/{$idle->id}", [], $this->h)->assertCreated();
        $rows = collect($this->getJson('/api/users/dropshippers', $this->h)->json('data.data'))->keyBy('id');
        $this->assertNotNull($rows[$idle->id]['lead_id']);
        $this->assertSame('new', $rows[$idle->id]['lead_status']);
        $this->assertNull($rows[$active->id]['lead_id']);
    }
}
