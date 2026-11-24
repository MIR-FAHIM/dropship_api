<?php

namespace Tests\Feature;

use App\Models\CarrybeeInvoiceLine;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCarryBeeCredintial;
use App\Service\ApiTokenService;
use App\Service\CarrybeeInvoiceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A vendor moved to a new CarryBee account (Ayan BD, 2026). The old account is saved as an inactive
 * credential: it is never used for new parcels, but sync reads its invoices so old orders still match.
 */
class CarrybeeOldAccountTest extends TestCase
{
    use RefreshDatabase;

    private array $admin;
    private Vendor $vendor;
    private VendorCarryBeeCredintial $old;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        OrderStatus::updateOrCreate(['id' => 9], ['name' => 'Completed', 'type' => 'delivery', 'is_active' => true]);
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->admin = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];

        $this->vendor = Vendor::create(['user_id' => User::factory()->create(['banned' => 0])->id, 'shop_name' => 'Ayan BD', 'shop_type' => 'Supplier', 'is_active' => true, 'has_own_delivery_account' => true]);
        $this->old = VendorCarryBeeCredintial::create(['vendor_id' => $this->vendor->id, 'client_id' => 'oldid', 'client_secret' => 's', 'client_context' => 'c', 'is_active' => false, 'note' => 'until Nov']);
        VendorCarryBeeCredintial::create(['vendor_id' => $this->vendor->id, 'client_id' => 'newid', 'client_secret' => 's', 'client_context' => 'c', 'is_active' => true]);

        $drop = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->order = Order::create(['order_number' => 'ORD-OLD1', 'user_id' => $drop->id, 'vendor_id' => $this->vendor->id, 'customer_name' => 'C', 'customer_phone' => '017',
            'shipping_address' => 'A', 'total' => 1100, 'subtotal' => 970, 'shipping_fee' => 130, 'remaining_cod' => 1100, 'courier_account_owner' => 'vendor', 'status' => 9, 'payment_status' => 'paid']);
        $p = Product::create(['name' => 'Cooker', 'slug' => 'cooker-old', 'vendor_id' => $this->vendor->id, 'shop_id' => $this->vendor->id, 'unit_price' => 680, 'admin_price' => 700,
            'price' => 970, 'category_id' => 1, 'brand_id' => 1, 'status' => 1, 'is_approved' => 1]);
        OrderItem::create(['order_id' => $this->order->id, 'product_id' => $p->id, 'shop_id' => $this->vendor->id, 'unit_price' => 680, 'admin_price' => 700, 'price' => 970, 'reseller_price' => 970, 'qty' => 1]);
        DeliveryAssignedInfo::create(['order_id' => $this->order->id, 'consignment_id' => 'FOLD1', 'recipient_name' => 'C', 'recipient_phone' => '017', 'recipient_address' => 'A',
            'collectable_amount' => 1100, 'delivery_fee' => 105, 'total_fee' => 116]);

        Http::fake(function (Request $request) {
            $old = $request->hasHeader('Client-ID', 'oldid');
            if (preg_match('#/api/v2/invoices/(I\w+)$#', $request->url(), $m)) {
                return $old && $m[1] === 'IOLD'
                    ? Http::response(['error' => false, 'data' => ['invoice_id' => 'IOLD', 'invoiced_date' => '2026-11-07', 'consignments' => [
                        ['consignment_id' => 'FOLD1', 'merchant_order_id' => 'ORD-OLD1', 'collected_amount' => 1100, 'delivery_fee' => 125, 'cod_fee' => 11, 'total_fee' => 136, 'delivery_status' => 'delivered'],
                    ]]])
                    : Http::response(['error' => true, 'message' => 'Invoice not found'], 404);
            }
            if (str_contains($request->url(), '/api/v2/invoices')) {
                // The current (new) account has no invoices yet; the old one has the history.
                return Http::response(['error' => false, 'data' => ['invoices' => $old ? [['invoice_id' => 'IOLD', 'receivable_status' => 'paid']] : []], 'pagination' => ['nextPage' => '']]);
            }

            return Http::response([], 404);
        });
    }

    public function test_old_account_is_listed_but_never_the_current_one(): void
    {
        $accounts = app(CarrybeeInvoiceReportService::class)->vendorAccounts($this->vendor->id);
        $this->assertCount(2, $accounts);
        $this->assertSame("vendor-{$this->vendor->id}", $accounts[0]['key']);
        $this->assertFalse($accounts[0]['old']);
        $this->assertSame("vendor-{$this->vendor->id}-old-{$this->old->id}", $accounts[1]['key']);
        $this->assertTrue($accounts[1]['old']);
        $this->assertStringContainsString('until Nov', $accounts[1]['label']);
    }

    public function test_syncing_the_vendor_also_reads_the_old_account(): void
    {
        $res = $this->postJson("/api/delivery-companies/carrybee/reconcile/vendor-{$this->vendor->id}/sync", [], $this->admin)->assertOk();

        $line = CarrybeeInvoiceLine::where('consignment_id', 'FOLD1')->first();
        $this->assertNotNull($line, 'old account invoice stored');
        $this->assertSame($this->order->id, $line->order_id);
        $this->assertSame("vendor-{$this->vendor->id}-old-{$this->old->id}", $line->account_key);
        $this->assertSame(1, $res->json('data.report.totals.orders'), 'vendor report includes old-account orders');
        $this->assertCount(2, $res->json('data.sync.accounts'));
        $this->assertEquals(125, $this->order->fresh()->actual_courier_fee, 'CarryBee figures from the old account applied');
    }

    public function test_invoice_details_fall_back_to_the_old_account(): void
    {
        $this->getJson("/api/delivery-companies/carrybee/{$this->vendor->id}/invoices/IOLD", $this->admin)
            ->assertOk()->assertJsonPath('data.data.invoice_id', 'IOLD');
    }
}
