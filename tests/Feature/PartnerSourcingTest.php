<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketerClient;
use App\Models\Notification;
use App\Models\PartnerProductRequirement;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Partner Portal F4: marketers send product sourcing requests; the sourcing team confirms a price.
class PartnerSourcingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $marketer;
    private User $otherMarketer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->marketer = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0, 'business_name' => 'ScaleUp']);
        $this->otherMarketer = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain'], 'Accept' => 'application/json'];
    }

    // 1x1 PNG (no GD needed to make test images).
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'product_name' => 'Smart Neck Massager',
            'product_image' => $this->png('massager.png'),
            'campaign_platform' => 'facebook_instagram',
            'daily_budget' => 60,
            'campaign_status' => 'active',
            'confirmation_deadline' => '2h',
            'special_note' => '40+ daily orders. Target under ৳580 with packaging.',
        ];
    }

    private function submit(User $u, array $over = [])
    {
        return $this->post('/api/marketer/requirements', $this->payload($over), $this->h($u));
    }

    public function test_marketer_submits_a_request_with_photo_and_admins_are_alerted(): void
    {
        $res = $this->submit($this->marketer)->assertCreated()->json('data');

        $this->assertSame('pending', $res['status']);
        $this->assertTrue($res['is_urgent']);
        Storage::disk('public')->assertExists($res['product_image']);
        $this->assertStringStartsWith("partner-requirements/{$this->marketer->id}/", $res['product_image']);
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, strtotime($res['deadline_at']), 5);
        $this->assertTrue(Notification::where('send_to', $this->admin->id)->where('title', 'like', '%URGENT%')->exists());
    }

    public function test_validation_photo_deadline_and_client(): void
    {
        $this->submit($this->marketer, ['product_image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])->assertUnprocessable();
        $this->submit($this->marketer, ['product_image' => UploadedFile::fake()->createWithContent('big.png', base64_decode(self::PNG) . str_repeat('0', 5200 * 1024))])->assertUnprocessable();
        $this->submit($this->marketer, ['confirmation_deadline' => 'days'])->assertUnprocessable(); // days missing
        $this->submit($this->marketer, ['confirmation_deadline' => 'date', 'deadline_date' => now()->subDay()->format('Y-m-d')])->assertUnprocessable();
        $this->submit($this->marketer, ['confirmation_deadline' => 'days', 'deadline_days' => 3])->assertCreated();

        // Client must be one of this marketer's connected clients.
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $theirs = MarketerClient::create(['marketer_user_id' => $this->otherMarketer->id, 'dropshipper_user_id' => $d->id,
            'active_dropshipper_user_id' => $d->id, 'consent_version' => MarketerClient::CONSENT_VERSION, 'accepted_at' => now()]);
        $this->submit($this->marketer, ['client_id' => $theirs->id])->assertUnprocessable();
    }

    public function test_marketer_sees_only_own_requests_and_can_withdraw_pending(): void
    {
        $id = $this->submit($this->marketer)->json('data.id');
        $this->submit($this->otherMarketer);

        $mine = $this->getJson('/api/marketer/requirements', $this->h($this->marketer))->assertOk()->json('data');
        $this->assertSame(1, $mine['requests']['total']);
        $this->assertSame(1, $mine['counts']['open']);

        $this->deleteJson("/api/marketer/requirements/{$id}", [], $this->h($this->otherMarketer))->assertNotFound();
        $this->deleteJson("/api/marketer/requirements/{$id}", [], $this->h($this->marketer))->assertOk()->assertJsonPath('data.status', 'withdrawn');
    }

    public function test_admin_checks_then_confirms_with_price_and_partner_is_notified(): void
    {
        $id = $this->submit($this->marketer)->json('data.id');
        $this->submit($this->marketer, ['confirmation_deadline' => '24h', 'product_name' => 'Juicer']);
        $list = $this->getJson('/api/admin/sourcing-requests', $this->h($this->admin))->assertOk()->json('data');
        $this->assertSame($id, $list['requests']['data'][0]['id'], '2-hour request is on top');
        $this->assertSame(2, $list['counts']['open']);
        $this->assertSame(1, $list['counts']['urgent']);

        $this->patchJson("/api/admin/sourcing-requests/{$id}", ['status' => 'checking'], $this->h($this->admin))->assertOk();
        $this->patchJson("/api/admin/sourcing-requests/{$id}", ['status' => 'confirmed'], $this->h($this->admin))->assertUnprocessable(); // price needed

        $vendor = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor', 'banned' => 0])->id, 'shop_name' => 'S']);
        $product = Product::create(['name' => 'Neck Massager', 'slug' => 'nm', 'sku' => 'NM', 'vendor_id' => $vendor->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c'])->id, 'unit_price' => 500, 'admin_price' => 525]);
        $this->patchJson("/api/admin/sourcing-requests/{$id}", ['status' => 'confirmed', 'quoted_price_bdt' => 575, 'linked_product_id' => $product->id], $this->h($this->admin))
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.linked_product.id', $product->id);

        $row = PartnerProductRequirement::find($id);
        $this->assertEquals(575, $row->quoted_price_bdt);
        $this->assertNotNull($row->responded_at);
        $this->assertTrue(Notification::where('send_to', $this->marketer->id)->where('subtitle', 'like', '%575%')->exists());

        $mine = $this->getJson('/api/marketer/requirements?status=confirmed', $this->h($this->marketer))->json('data.requests.data');
        $this->assertEquals(575, $mine[0]['quoted_price_bdt']);
    }

    public function test_rejection_needs_a_reason_and_withdrawn_cannot_be_answered(): void
    {
        $id = $this->submit($this->marketer)->json('data.id');
        $this->patchJson("/api/admin/sourcing-requests/{$id}", ['status' => 'rejected'], $this->h($this->admin))->assertUnprocessable();
        $this->patchJson("/api/admin/sourcing-requests/{$id}", ['status' => 'rejected', 'admin_note' => 'Not available in BD'], $this->h($this->admin))->assertOk();

        $w = $this->submit($this->marketer)->json('data.id');
        $this->deleteJson("/api/marketer/requirements/{$w}", [], $this->h($this->marketer))->assertOk();
        $this->patchJson("/api/admin/sourcing-requests/{$w}", ['status' => 'checking'], $this->h($this->admin))->assertUnprocessable();
    }

    public function test_access_rules(): void
    {
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->post('/api/marketer/requirements', $this->payload(), $this->h($d))->assertForbidden();
        $this->getJson('/api/admin/sourcing-requests', $this->h($this->marketer))->assertForbidden();
        $this->getJson('/api/admin/sourcing-requests', $this->h($d))->assertForbidden();
    }
}
