<?php

namespace Tests\Feature;

use App\Models\EcommerceWebsiteLead;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcommerceWebsiteLeadTest extends TestCase
{
    use RefreshDatabase;

    private User $dropshipper;
    private string $dropshipperToken;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropshipper = User::factory()->create(['email' => 'lead_dropshipper@test.com', 'user_type' => 'dropshipper', 'banned' => 0]);
        $this->dropshipperToken = ApiTokenService::create($this->dropshipper, ['basic'], 60, 'tok')['plain'];

        $admin = User::factory()->create(['email' => 'lead_admin@test.com', 'user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->adminToken = ApiTokenService::create($admin, ['basic', 'admin'], 60, 'tok')['plain'];
    }

    public function test_dropshipper_can_submit_interested_later_lead(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->dropshipperToken)
            ->postJson('/api/ecommerce-website-leads', ['lead_type' => 'interested_later']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ecommerce_website_leads', [
            'user_id' => $this->dropshipper->id,
            'lead_type' => 'interested_later',
            'status' => 'new',
            'message' => null,
        ]);
    }

    public function test_question_lead_requires_a_message(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->dropshipperToken)
            ->postJson('/api/ecommerce-website-leads', ['lead_type' => 'question']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('ecommerce_website_leads', 0);
    }

    public function test_question_lead_stores_the_message(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->dropshipperToken)
            ->postJson('/api/ecommerce-website-leads', [
                'lead_type' => 'question',
                'message' => 'ডোমেইন নিজের থাকলে কি দাম কম হবে?',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ecommerce_website_leads', [
            'user_id' => $this->dropshipper->id,
            'lead_type' => 'question',
            'message' => 'ডোমেইন নিজের থাকলে কি দাম কম হবে?',
        ]);
    }

    public function test_invalid_lead_type_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->dropshipperToken)
            ->postJson('/api/ecommerce-website-leads', ['lead_type' => 'not_a_real_type']);

        $response->assertStatus(422);
    }

    public function test_non_admin_cannot_list_leads(): void
    {
        EcommerceWebsiteLead::create(['user_id' => $this->dropshipper->id, 'lead_type' => 'interested_later', 'status' => 'new']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->dropshipperToken)
            ->getJson('/api/ecommerce-website-leads');

        $response->assertStatus(403);
    }

    public function test_admin_can_list_leads_with_dropshipper_info(): void
    {
        EcommerceWebsiteLead::create([
            'user_id' => $this->dropshipper->id,
            'lead_type' => 'question',
            'message' => 'প্যাকেজের দাম কত?',
            'status' => 'new',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson('/api/ecommerce-website-leads');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('প্যাকেজের দাম কত?', $data[0]['message']);
        $this->assertEquals($this->dropshipper->id, $data[0]['user']['id']);
    }

    public function test_admin_can_mark_a_lead_contacted(): void
    {
        $lead = EcommerceWebsiteLead::create(['user_id' => $this->dropshipper->id, 'lead_type' => 'interested_later', 'status' => 'new']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/ecommerce-website-leads/{$lead->id}/status", [
                'status' => 'contacted',
                'admin_note' => 'ফোনে কথা হয়েছে, আগ্রহী।',
            ]);

        $response->assertStatus(200);
        $lead->refresh();
        $this->assertEquals('contacted', $lead->status);
        $this->assertEquals('ফোনে কথা হয়েছে, আগ্রহী।', $lead->admin_note);
        $this->assertNotNull($lead->contacted_at);
        $this->assertNotNull($lead->contacted_by);
    }
}
