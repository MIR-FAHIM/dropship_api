<?php

namespace Tests\Feature;

use App\Models\CarrybeeInvoiceLine;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCarryBeeCredintial;
use App\Service\CarrybeeReconcileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CarryBee paid a parcel on its invoice but its "paid" webhook never reached us (order #199, Nov 2026).
 * Sync replays the payment so the order follows the normal paid journey.
 */
class CarrybeeMissedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1 => 'Pending', 6 => 'Delivered', 7 => 'Cancelled', 8 => 'Returned', 9 => 'Completed', 10 => 'Carrybee Paid', 11 => 'Lost', 12 => 'Return in Transit', 13 => 'Partially Delivered'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'delivery', 'is_active' => true]);
        }
        $this->vendor = Vendor::create(['user_id' => User::factory()->create(['banned' => 0])->id, 'shop_name' => 'Ayan BD', 'shop_type' => 'Supplier', 'is_active' => true, 'has_own_delivery_account' => true]);
        VendorCarryBeeCredintial::create(['vendor_id' => $this->vendor->id, 'client_id' => 'id', 'client_secret' => 's', 'client_context' => 'c', 'is_active' => true]);

        Http::fake(function (Request $request) {
            if (preg_match('#/api/v2/invoices/(I\w+)$#', $request->url())) {
                return Http::response(['error' => false, 'data' => ['invoice_id' => 'I2311', 'invoiced_date' => '2026-11-23', 'receivable_status' => 'received', 'consignments' => [
                    ['consignment_id' => 'F199', 'merchant_order_id' => 'ORD-199', 'collected_amount' => 800, 'delivery_fee' => 49, 'cod_fee' => 8, 'total_fee' => 57, 'delivery_status' => 'delivered'],
                    ['consignment_id' => 'F200', 'merchant_order_id' => 'ORD-200', 'collected_amount' => 500, 'delivery_fee' => 60, 'cod_fee' => 5, 'total_fee' => 65, 'delivery_status' => 'delivered'],
                ]]]);
            }
            if (str_contains($request->url(), '/api/v2/invoices')) {
                return Http::response(['error' => false, 'data' => ['invoices' => [['invoice_id' => 'I2311', 'receivable_status' => 'paid']]], 'pagination' => ['nextPage' => '']]);
            }

            return Http::response([], 404);
        });
    }

    private function order(string $no, string $cons, int $status, float $total, array $extra = []): Order
    {
        $drop = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $o = Order::create($extra + ['order_number' => $no, 'user_id' => $drop->id, 'vendor_id' => $this->vendor->id, 'customer_name' => 'C', 'customer_phone' => '017',
            'shipping_address' => 'A', 'total' => $total, 'subtotal' => $total - 70, 'shipping_fee' => 70, 'remaining_cod' => $total, 'courier_account_owner' => 'vendor',
            'status' => $status, 'payment_status' => 'unpaid']);
        $p = Product::create(['name' => "P {$no}", 'slug' => "p-{$no}", 'vendor_id' => $this->vendor->id, 'shop_id' => $this->vendor->id, 'unit_price' => 260, 'admin_price' => 280,
            'price' => $total - 70, 'category_id' => 1, 'brand_id' => 1, 'status' => 1, 'is_approved' => 1]);
        OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'shop_id' => $this->vendor->id, 'unit_price' => 260, 'admin_price' => 280, 'price' => $total - 70, 'reseller_price' => $total - 70, 'qty' => 1]);
        DeliveryAssignedInfo::create(['order_id' => $o->id, 'consignment_id' => $cons, 'recipient_name' => 'C', 'recipient_phone' => '017', 'recipient_address' => 'A',
            'collectable_amount' => $total, 'delivery_fee' => 60, 'total_fee' => 68]);

        return $o;
    }

    public function test_sync_replays_a_missed_paid_webhook(): void
    {
        $missed = $this->order('ORD-199', 'F199', 6, 800);                 // delivered, paid message never came
        $paid = $this->order('ORD-200', 'F200', 10, 500, ['carrybee_paid_at' => now()->subDay(), 'carrybee_invoice_id' => 'I2311']); // already paid

        $res = app(CarrybeeReconcileService::class)->syncAccount("vendor-{$this->vendor->id}");

        $this->assertSame(['ORD-199'], $res['recovered_paid']);
        $missed->refresh();
        $this->assertNotNull($missed->carrybee_paid_at);
        $this->assertSame('I2311', $missed->carrybee_invoice_id);
        $this->assertContains((int) $missed->status, [9, 10], 'moved to CarryBee Paid (or Completed)');
        $this->assertSame('paid', $missed->payment_status);
        $this->assertEquals(49, $missed->actual_courier_fee, "CarryBee's real fee used");
        $this->assertTrue(OrderSettlement::where('order_id', $missed->id)->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)->exists(), 'settlement started');
        // 800 − 57 − 260 = 483 the vendor will give admin
        $this->assertEquals(483, (float) OrderSettlement::where('order_id', $missed->id)->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)->value('settleable_amount'));

        $this->assertSame(0, OrderSettlement::where('order_id', $paid->id)->count(), 'already-paid order not replayed');

        // Running sync again does not replay it twice.
        $again = app(CarrybeeReconcileService::class)->syncAccount("vendor-{$this->vendor->id}", true);
        $this->assertSame([], $again['recovered_paid']);
    }

    public function test_cancelled_or_returned_orders_are_never_marked_paid(): void
    {
        $cancelled = $this->order('ORD-199', 'F199', 7, 800);

        app(CarrybeeReconcileService::class)->syncAccount("vendor-{$this->vendor->id}");

        $this->assertNull($cancelled->fresh()->carrybee_paid_at);
        $this->assertSame(1, CarrybeeInvoiceLine::where('order_id', $cancelled->id)->count(), 'line still stored for the audit');
    }
}
