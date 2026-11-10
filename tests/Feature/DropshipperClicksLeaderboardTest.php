<?php

namespace Tests\Feature;

use App\Models\ProductClicks;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DropshipperClicksLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/product-clicks/leaderboard/dropshippers';

    public function test_it_ranks_only_dropshippers_with_attributed_clicks_and_keeps_ranks_across_pages(): void
    {
        $admin = $this->user('admin');
        $first = $this->user('dropshipper');
        $second = $this->user('dropshipper');
        $third = $this->user('dropshipper');
        $this->user('dropshipper');

        $this->click($first);
        $this->click($first, landing: true);
        $this->click($first, productId: 2);
        $this->click($second);
        $this->click($third);
        $this->click($admin);
        $this->click(null, landing: true);

        $this->withToken($this->token($admin))->getJson(self::ENDPOINT.'?per_page=1')
            ->assertOk()->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.data.0.id', $first->id)
            ->assertJsonPath('data.data.0.rank', 1)
            ->assertJsonPath('data.data.0.total_clicks', 3)
            ->assertJsonPath('data.data.0.unique_products_clicked', 2)
            ->assertJsonMissingPath('data.data.0.email');

        $this->getJson(self::ENDPOINT.'?per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.data.0.id', $second->id)
            ->assertJsonPath('data.data.0.rank', 2);
    }

    public function test_date_filters_include_the_whole_end_date_and_can_be_used_independently(): void
    {
        $admin = $this->user('admin');
        $dropshipper = $this->user('dropshipper');
        $this->click($dropshipper, at: '2026-11-01 00:00:00');
        $this->click($dropshipper, at: '2026-11-03 23:59:59');
        $this->click($dropshipper, at: '2026-11-04 00:00:00');

        $this->withToken($this->token($admin))
            ->getJson(self::ENDPOINT.'?start_date=2026-11-02&end_date=2026-11-03')
            ->assertOk()->assertJsonPath('data.data.0.total_clicks', 1);
        $this->getJson(self::ENDPOINT.'?end_date=2026-11-03')
            ->assertOk()->assertJsonPath('data.data.0.total_clicks', 2);
        $this->getJson(self::ENDPOINT.'?start_date=2026-11-04')
            ->assertOk()->assertJsonPath('data.data.0.total_clicks', 1);
        $this->getJson(self::ENDPOINT.'?start_date=2026-11-04&end_date=2026-11-03')
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_it_requires_an_admin_token(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->withToken($this->token($this->user('dropshipper')))
            ->getJson(self::ENDPOINT)->assertForbidden();
    }

    private function user(string $type): User
    {
        return User::factory()->create(['user_type' => $type, 'banned' => 0]);
    }

    private function token(User $user): string
    {
        return ApiTokenService::create($user, ['basic'])['plain'];
    }

    private function click(?User $viewer, int $productId = 1, bool $landing = false, ?string $at = null): void
    {
        $click = new ProductClicks([
            'product_id' => $productId,
            'page' => $landing ? 'reseller_product_page' : 'product_details',
            'clicked_by' => $viewer?->id,
            'is_guest' => $landing,
        ]);
        if ($at) {
            $click->created_at = $at;
        }
        $click->save();
    }
}
