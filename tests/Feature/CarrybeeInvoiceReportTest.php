<?php

namespace Tests\Feature;

use App\Models\DeliveryCompany;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCarryBeeCredintial;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Admin → CarryBee Invoices: all accounts' invoices grouped and summed.
class CarrybeeInvoiceReportTest extends TestCase
{
    use RefreshDatabase;

    private array $admin;
    private Vendor $shared;
    private Vendor $own;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->admin = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];

        DeliveryCompany::create(['company_name' => 'CarryBee', 'is_active' => true, 'api_key' => 'pid', 'secret_key' => 'psecret', 'client_context' => 'pctx']);
        $this->shared = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor', 'banned' => 0])->id, 'shop_name' => 'Shared Shop', 'has_own_delivery_account' => false, 'is_active' => true]);
        $this->own = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor', 'banned' => 0])->id, 'shop_name' => 'Own Shop', 'has_own_delivery_account' => true, 'is_active' => true]);
        VendorCarryBeeCredintial::create(['vendor_id' => $this->own->id, 'client_id' => 'vid', 'client_secret' => 'vsecret', 'client_context' => 'vctx', 'is_active' => true]);
        Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor', 'banned' => 0])->id, 'shop_name' => 'No Creds', 'has_own_delivery_account' => true, 'is_active' => true]);

        $inv = fn (string $id, string $date, array $over = []) => $over + [
            'invoice_id' => $id, 'invoice_date' => $date, 'payment_method' => 'bank', 'collected' => 50000, 'total_fee' => 2050,
            'delivery_fee' => 1250, 'cod_fee' => 800, 'wallet_name' => 'Eastern Bank PLC', 'total_consignment' => 2, 'receivable' => 47950,
            'receivable_status' => 'unpaid', 'compensation_amount' => 0, 'payable_amount' => 0, 'payable_status' => 'received', 'collectable_amount' => 100000,
        ];
        Http::fake(function (Request $request) use ($inv) {
            if (!str_contains($request->url(), '/api/v2/invoices')) {
                return Http::response([], 404);
            }
            $page = (int) ($request->data()['page'] ?? 1);
            if ($request->hasHeader('Client-ID', 'pid')) {
                // Platform account: two pages.
                return $page === 1
                    ? Http::response(['error' => false, 'data' => ['invoices' => [$inv('IXP1', '2026-11-20')]], 'pagination' => ['currentPage' => '1', 'nextPage' => '2']])
                    : Http::response(['error' => false, 'data' => ['invoices' => [$inv('IXP2', '2026-10-01', ['receivable_status' => 'paid', 'payable_amount' => 300, 'payable_status' => 'unpaid'])]], 'pagination' => ['currentPage' => '2', 'nextPage' => '']]);
            }

            return Http::response(['error' => false, 'data' => ['invoices' => [$inv('IXV1', '2026-11-18', ['collected' => 10000, 'receivable' => 9590, 'total_fee' => 410, 'delivery_fee' => 310, 'cod_fee' => 100])]], 'pagination' => ['nextPage' => '']]);
        });

        Order::create(['user_id' => User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0])->id, 'vendor_id' => $this->shared->id,
            'order_number' => 'O-1', 'status' => 10, 'customer_name' => 'C', 'customer_phone' => '017', 'total' => 1000, 'carrybee_invoice_id' => 'IXP1']);
    }

    private function report(string $q = '')
    {
        return $this->getJson('/api/delivery-companies/carrybee/all-invoices' . $q, $this->admin);
    }

    public function test_groups_every_account_and_sums_amounts(): void
    {
        $data = $this->report()->assertOk()->json('data');
        $groups = collect($data['groups'])->keyBy('key');

        $this->assertCount(3, $groups); // platform, own shop, vendor without credentials
        $platform = $groups['platform'];
        $this->assertCount(2, $platform['invoices'], 'both pages read');
        $this->assertSame('IXP1', $platform['invoices'][0]['invoice_id'], 'newest first');
        $this->assertSame('Shared Shop', $platform['invoices'][0]['vendors'][0]['shop_name'], 'platform invoice tagged with vendor');
        $this->assertEquals(95900, $platform['totals']['receivable']);
        $this->assertEquals(47950, $platform['totals']['receivable_unpaid']);
        $this->assertEquals(300, $platform['totals']['payable_unpaid']);
        $this->assertSame(4, $platform['totals']['consignments']);

        $this->assertEquals(9590, $groups["vendor-{$this->own->id}"]['totals']['receivable']);
        $noCreds = $groups->first(fn ($g) => $g['label'] === 'No Creds');
        $this->assertNotNull($noCreds['error']);

        $this->assertSame(3, $data['totals']['invoices']);
        $this->assertEquals(105490, $data['totals']['receivable']);
        $this->assertEquals(110000, $data['totals']['collected']);
    }

    public function test_filters_by_date_and_status_and_caches_until_refresh(): void
    {
        $nov = $this->report('?from=2026-11-01&to=2026-11-30')->json('data');
        $this->assertSame(2, $nov['totals']['invoices']);
        $this->assertSame(1, $this->report('?receivable_status=paid')->json('data.totals.invoices'));

        $calls = count(Http::recorded());
        $this->report();
        $this->assertSame($calls, count(Http::recorded()), 'served from cache');
        $this->report('?refresh=1');
        $this->assertGreaterThan($calls, count(Http::recorded()));
    }

    public function test_only_order_admins(): void
    {
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->getJson('/api/delivery-companies/carrybee/all-invoices', ['Authorization' => 'Bearer ' . ApiTokenService::create($d, ['basic'])['plain']])->assertForbidden();
    }
}
