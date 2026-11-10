<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LandingPageOrder;
use App\Models\MarketerClient;
use App\Models\MarketerInvitation;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PartnerStore;
use App\Models\Product;
use App\Models\ResellerProductPage;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_suspend_a_marketer_and_marketer_tokens_are_isolated(): void
    {
        $admin = $this->user('admin');
        $dropshipper = $this->user('dropshipper');
        $data = ['name' => 'Test Marketer', 'email' => 'MARKETER@example.com'];

        $this->withToken($this->token($dropshipper))->postJson('/api/admin/marketers', $data)->assertForbidden();
        $id = $this->withToken($this->token($admin))->postJson('/api/admin/marketers', $data)
            ->assertCreated()->assertJsonPath('data.user_type', 'marketer')
            ->assertJsonPath('data.email', 'marketer@example.com')->json('data.id');
        $token = $this->token(User::findOrFail($id));

        $this->withToken($token)->getJson('/api/marketer/clients')->assertOk();
        $this->withToken($token)->getJson('/api/orders/list/'.$dropshipper->id)->assertForbidden();
        $this->withToken($token)->getJson('/api/transactions/reseller?reseller_id='.$dropshipper->id)->assertForbidden();
        $this->withToken($this->token($dropshipper))->getJson('/api/marketer/clients')->assertForbidden();

        $this->withToken($this->token($admin))->patchJson("/api/admin/marketers/{$id}/suspension", ['suspended' => true])->assertOk();
        $this->withToken($token)->getJson('/api/marketer/clients')->assertForbidden();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_invitation_requires_the_matching_dropshipper_direct_login_and_explicit_consent(): void
    {
        $marketer = $this->user('marketer');
        $dropshipper = $this->user('dropshipper');
        $other = $this->user('dropshipper');
        $token = $this->login($dropshipper);
        $code = $this->invite($marketer, $dropshipper);

        $this->withToken($this->token($dropshipper))->postJson('/api/marketer-client/invitation/accept', $this->consent($code))->assertForbidden();
        $this->withToken($this->login($other))->postJson('/api/marketer-client/invitation/preview', ['code' => $code])->assertNotFound();
        $this->withToken($token)->postJson('/api/marketer-client/invitation/preview', ['code' => $code])
            ->assertOk()->assertJsonPath('data.marketer_name', $marketer->name);
        $this->withToken($token)->postJson('/api/marketer-client/invitation/accept', ['code' => $code])->assertUnprocessable();
        $this->assertDatabaseCount('marketer_clients', 0);

        $client = $this->accept($token, $code);
        $this->withToken($token)->postJson('/api/marketer-client/invitation/accept', $this->consent($code))->assertNotFound();
        $this->withToken($token)->getJson('/api/marketer-client/relationship')->assertJsonPath('data.id', $client);
        $this->assertSame('dropshipper', $dropshipper->fresh()->user_type);
    }

    public function test_expired_and_revoked_invitations_cannot_be_used(): void
    {
        $marketer = $this->user('marketer');
        $otherMarketer = $this->user('marketer');
        $dropshipper = $this->user('dropshipper');
        $token = $this->login($dropshipper);

        $expired = $this->invite($marketer, $dropshipper);
        MarketerInvitation::where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subSecond()]);
        $this->withToken($token)->postJson('/api/marketer-client/invitation/accept', $this->consent($expired))->assertNotFound();

        $revoked = $this->invite($marketer, $dropshipper);
        $id = MarketerInvitation::where('token_hash', hash('sha256', $revoked))->value('id');
        $this->withToken($this->token($otherMarketer))->deleteJson("/api/marketer/invitations/{$id}")->assertNotFound();
        $this->withToken($this->token($marketer))->deleteJson("/api/marketer/invitations/{$id}")->assertOk();
        $this->withToken($token)->postJson('/api/marketer-client/invitation/accept', $this->consent($revoked))->assertNotFound();
    }

    public function test_orders_keep_original_attribution_and_points_drop_on_return(): void
    {
        OrderStatus::create(['id' => 1, 'name' => 'Pending', 'type' => 'pending']);
        $admin = $this->user('admin');
        $marketerA = $this->user('marketer');
        $marketerB = $this->user('marketer');
        $dropshipper = $this->user('dropshipper');
        $token = $this->login($dropshipper);
        $oldOrder = Order::create(['user_id' => $dropshipper->id, 'status' => 9]);

        $linkA = $this->accept($token, $this->invite($marketerA, $dropshipper));
        $orderA = Order::create(['user_id' => $dropshipper->id, 'status' => 9, 'customer_phone' => '01711111111']);
        $landing = $this->createLandingOrder($dropshipper);
        $nextCode = $this->invite($marketerB, $dropshipper);
        $this->withToken($token)->postJson('/api/marketer-client/invitation/accept', $this->consent($nextCode))->assertConflict();
        $this->withToken($this->token($admin))->patchJson("/api/admin/marketers/clients/{$linkA}/end")->assertOk();
        $linkB = $this->accept($token, $nextCode);
        $orderB = Order::create(['user_id' => $dropshipper->id, 'status' => 10]);

        $this->assertNull($oldOrder->fresh()->marketer_client_id);
        $this->assertEquals($linkA, $orderA->fresh()->marketer_client_id);
        $this->assertEquals($linkB, $orderB->fresh()->marketer_client_id);
        $this->assertNotNull(MarketerClient::findOrFail($linkA)->ended_at);

        $this->withToken($token)->postJson("/api/landing-page-orders/pass-order-to-reseller-brain/{$landing->id}")->assertCreated();
        $this->assertEquals($linkA, $landing->fresh()->order->marketer_client_id);
        $this->withToken($token)->postJson("/api/landing-page-orders/pass-order-to-reseller-brain/{$landing->id}")->assertOk();

        $tokenA = $this->token($marketerA);
        $tokenB = $this->token($marketerB);
        $this->withToken($tokenA)->getJson('/api/marketer/clients')->assertJsonCount(0, 'data.data');
        $this->withToken($tokenA)->getJson('/api/marketer/performance')->assertJsonPath('data.placed', 2)
            ->assertJsonPath('data.points', 1)->assertDontSee('01711111111');
        $this->withToken($tokenB)->getJson('/api/marketer/performance?client_id='.$linkA)->assertJsonPath('data.points', 0);
        $this->withToken($tokenB)->getJson('/api/marketer/performance?client_id='.$linkB)->assertJsonPath('data.points', 1);

        $orderA->update(['status' => 8]);
        $this->withToken($tokenA)->getJson('/api/marketer/performance')
            ->assertJsonPath('data.returned', 1)->assertJsonPath('data.points', 0);
    }

    public function test_legacy_user_and_checkout_routes_cannot_impersonate_other_accounts(): void
    {
        $dropshipper = $this->user('dropshipper');
        $other = $this->user('dropshipper');

        $this->postJson('/api/users/create', ['name' => 'Fake Admin', 'role' => 'admin', 'password' => 'password'])->assertUnprocessable();
        $this->withToken($this->token($dropshipper))->putJson('/api/users/update/'.$dropshipper->id, ['role' => 'admin'])->assertForbidden();
        $this->putJson('/api/users/update/'.$other->id, ['password' => 'replacement'])->assertForbidden();
        $this->postJson('/api/orders/checkout', ['user_id' => $other->id, 'delivery_charge' => 50])->assertForbidden();
        $this->postJson('/api/auth/login-as-vendor', ['email' => $this->user('vendor')->email])->assertForbidden();
    }

    public function test_checkout_and_partner_api_persist_attribution_from_the_order_owner(): void
    {
        OrderStatus::create(['id' => 1, 'name' => 'Pending', 'type' => 'pending']);
        $marketer = $this->user('marketer');
        $dropshipper = $this->user('dropshipper');
        $token = $this->login($dropshipper);
        $link = $this->accept($token, $this->invite($marketer, $dropshipper));
        $product = $this->createLandingOrder($dropshipper)->product;
        $buyer = ['customer_name' => 'Shopper', 'customer_phone' => '01733333333', 'shipping_address' => 'Test Address'];

        $this->withToken($token)->postJson('/api/orders/checkout', $buyer + [
            'user_id' => $dropshipper->id, 'delivery_charge' => 50, 'product_id' => $product->id,
            'reseller_price' => 150, 'marketer_client_id' => 999,
        ])->assertCreated();
        $this->assertEquals($link, Order::latest('id')->firstOrFail()->marketer_client_id);

        $store = PartnerStore::create([
            'user_id' => $dropshipper->id, 'store_name' => 'Partner Test Store',
            'api_key' => PartnerStore::generateApiKey(), 'api_secret' => PartnerStore::generateApiSecret(),
        ]);
        $number = $this->postJson('/api/partner/v1/orders/create', $buyer + [
            'external_order_id' => 'partner-test-order', 'shipping_fee' => 50,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'selling_price' => 150]],
        ], [
            'X-RB-API-KEY' => $store->api_key, 'X-RB-API-SECRET' => $store->api_secret,
        ])->assertCreated()->json('data.order_number');
        $this->assertDatabaseHas('orders', [
            'order_number' => $number, 'user_id' => $dropshipper->id, 'marketer_client_id' => $link,
        ]);
    }

    public function test_marketer_can_directly_create_dropshipper_account_and_link_as_active_client(): void
    {
        $marketer = $this->user('marketer');
        $marketerToken = $this->token($marketer);
        $dropshipper = $this->user('dropshipper');

        // Non-marketer cannot create dropshipper
        $this->withToken($this->token($dropshipper))->postJson('/api/marketer/clients/create', [
            'name' => 'New Dropshipper',
            'email' => 'newdrop@example.com',
            'phone' => '01812345678',
        ])->assertForbidden();

        // Marketer creates dropshipper with custom password
        $response = $this->withToken($marketerToken)->postJson('/api/marketer/clients/create', [
            'name' => 'New Dropshipper',
            'email' => 'NEWDROP@example.com',
            'phone' => '01812345678',
            'password' => 'secret123',
            'business_name' => 'Drop Store',
        ])->assertCreated();

        $dropshipperId = $response->json('data.dropshipper.id');
        $this->assertNotNull($dropshipperId);
        $this->assertEquals('newdrop@example.com', $response->json('data.dropshipper.email'));

        $createdUser = User::findOrFail($dropshipperId);
        $this->assertEquals('dropshipper', $createdUser->user_type);
        $this->assertEquals(0, $createdUser->banned);
        $this->assertEquals('01812345678', $createdUser->phone);
        $this->assertEquals('Drop Store', $createdUser->business_name);

        // Verify active MarketerClient relationship was created
        $this->assertDatabaseHas('marketer_clients', [
            'marketer_user_id' => $marketer->id,
            'dropshipper_user_id' => $dropshipperId,
            'active_dropshipper_user_id' => $dropshipperId,
        ]);

        // Dropshipper appears in marketer's clients list
        $this->withToken($marketerToken)->getJson('/api/marketer/clients')
            ->assertOk()
            ->assertJsonPath('data.data.0.dropshipper.name', 'New Dropshipper');

        // Created dropshipper can log in immediately with their password
        $this->postJson('/api/auth/login', [
            'email' => 'newdrop@example.com',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('data.user.id', $dropshipperId);

        // Duplicate email fails validation
        $this->withToken($marketerToken)->postJson('/api/marketer/clients/create', [
            'name' => 'Duplicate Dropshipper',
            'email' => 'newdrop@example.com',
            'phone' => '01899999999',
        ])->assertUnprocessable();

        // Alias endpoint /api/marketer/clients works as well (auto-generated password fallback)
        $aliasResponse = $this->withToken($marketerToken)->postJson('/api/marketer/clients', [
            'name' => 'Second Dropshipper',
            'email' => 'second@example.com',
            'phone' => '01987654321',
        ])->assertCreated();

        $secondId = $aliasResponse->json('data.dropshipper.id');
        $this->assertDatabaseHas('marketer_clients', [
            'marketer_user_id' => $marketer->id,
            'dropshipper_user_id' => $secondId,
            'active_dropshipper_user_id' => $secondId,
        ]);
        // Auto-generated password from phone digits works for login
        $this->postJson('/api/auth/login', [
            'email' => 'second@example.com',
            'password' => '01987654321',
        ])->assertOk()->assertJsonPath('data.user.id', $secondId);
    }

    private function user(string $type): User
    {
        return User::factory()->create(['user_type' => $type, 'banned' => 0]);
    }

    private function token(User $user): string
    {
        return ApiTokenService::create($user, ['basic'], 1, 'test')['plain'];
    }

    private function login(User $user): string
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->json('data.token');
    }

    private function invite(User $marketer, User $dropshipper): string
    {
        $response = $this->withToken($this->token($marketer))->postJson('/api/marketer/invitations', ['email' => $dropshipper->email])
            ->assertCreated()->assertJsonMissingPath('data.token_hash');
        $mail = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame($dropshipper->email, $mail->getTo()[0]->getAddress());
        preg_match('/code: ([A-Za-z0-9]{48})/', $mail->getTextBody(), $matches);
        $this->assertNotEmpty($matches[1]);
        $response->assertDontSee($matches[1]);

        return $matches[1];
    }

    private function consent(string $code): array
    {
        return ['code' => $code, 'consent' => true, 'consent_version' => MarketerClient::CONSENT_VERSION];
    }

    private function accept(string $token, string $code): int
    {
        return $this->withToken($token)->postJson('/api/marketer-client/invitation/accept', $this->consent($code))
            ->assertCreated()->json('data.id');
    }

    private function createLandingOrder(User $dropshipper): LandingPageOrder
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category']);
        $product = Product::create([
            'name' => 'Product', 'slug' => 'product', 'vendor_id' => $dropshipper->id,
            'category_id' => $category->id, 'unit_price' => 100, 'admin_price' => 120, 'current_stock' => 10,
        ]);
        $page = ResellerProductPage::create([
            'reseller_id' => $dropshipper->id, 'product_id' => $product->id, 'slug' => 'product-page',
            'selling_price' => 150, 'delivery_charge' => 50, 'published_status' => 'published',
        ]);
        $id = $this->postJson('/api/landing-page-orders/create', [
            'reseller_product_page_id' => $page->id, 'customer_name' => 'Shopper',
            'customer_phone' => '01722222222', 'customer_address' => 'Test Address',
        ])->assertCreated()->json('data.id');

        return LandingPageOrder::findOrFail($id);
    }
}
