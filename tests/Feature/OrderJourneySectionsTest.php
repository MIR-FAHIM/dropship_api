<?php

namespace Tests\Feature;

use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// CarryBee-style sections on the admin, vendor and dropshipper order lists.
class OrderJourneySectionsTest extends TestCase
{
    use RefreshDatabase;

    private array $adminHeaders;
    private User $dropshipper;
    private Vendor $vendor;
    private int $n = 0;

    /** [status, courier_stage|null, expected "section.stage"] */
    private const CASES = [
        [1, null, 'new.pending'],
        [2, null, 'new.assigned_to_vendor'],
        [3, 'order.created', 'new.ready_for_pickup'],
        [3, 'order.pickup-failed', 'new.pickup_failed'],
        [4, 'order.picked', 'active.at_sorting'],
        [4, 'order.at-the-sorting-hub', 'active.at_sorting'],
        [4, 'order.on-the-way-to-central-warehouse', 'active.in_transit'],
        [4, 'order.at-central-warehouse', 'active.in_transit'],
        [4, 'order.in-transit', 'active.in_transit'],
        [4, null, 'active.in_transit'],
        [4, 'order.received-at-last-mile-hub', 'active.at_delivery_hub'],
        [5, 'order.assigned-for-delivery', 'active.assigned_for_delivery'],
        [5, null, 'active.assigned_for_delivery'],
        [5, 'order.delivery-on-hold', 'active.on_hold'],
        [5, 'order.delivery-failed', 'active.on_hold'],
        [6, 'order.delivered', 'delivered.delivered'],
        [10, 'order.paid', 'delivered.delivered'],
        [9, 'order.paid', 'delivered.delivered'],
        [13, 'order.partial-delivery', 'delivered.partial'],
        [6, 'order.exchange', 'delivered.exchanged'],
        [12, 'order.returned', 'returning.in_transit'],
        [12, 'order.returned-in-transit', 'returning.in_transit'],
        [12, 'order.returned-at-sorting', 'returning.at_sorting'],
        [12, 'order.paid-return', 'returned.paid_return'],
        [8, 'order.returned-to-merchant', 'returned.returned'],
        [7, 'order.pickup-cancelled', 'closed.cancelled'],
        [11, null, 'closed.lost'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->adminHeaders = $this->headersFor($admin, ['basic', 'admin']);
        $this->dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $vendorUser = User::factory()->create(['user_type' => 'vendor', 'role' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create(['user_id' => $vendorUser->id, 'shop_name' => 'Shop']);
    }

    private function headersFor(User $u, array $scopes = ['basic']): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, $scopes)['plain']];
    }

    private function order(int $status, ?string $stage): Order
    {
        $n = ++$this->n;
        $order = Order::create(['user_id' => $this->dropshipper->id, 'vendor_id' => $this->vendor->id, 'order_number' => "J-{$n}",
            'status' => $status, 'customer_name' => "Customer {$n}", 'customer_phone' => '0170000000' . $n, 'total' => 500]);
        OrderItem::create(['order_id' => $order->id, 'shop_id' => $this->vendor->id, 'product_name' => 'P', 'sku' => 'P1',
            'unit_price' => 100, 'qty' => 1, 'line_total' => 100, 'status' => 1]);
        if ($stage !== null || $status >= 3) {
            DeliveryAssignedInfo::create(['order_id' => $order->id, 'consignment_id' => "FJ{$n}", 'merchant_order_id' => $order->order_number,
                'delivery_company_id' => 1, 'recipient_name' => 'C', 'recipient_phone' => '017', 'recipient_address' => 'D',
                'courier_stage' => $stage]);
        }

        return $order;
    }

    private function seedAll(): array
    {
        $map = [];
        foreach (self::CASES as [$status, $stage, $expected]) {
            $map[$this->order($status, $stage)->order_number] = $expected;
        }

        return $map;
    }

    public function test_every_status_and_carrybee_event_maps_to_its_section(): void
    {
        $expected = $this->seedAll();

        $rows = $this->getJson('/api/orders/all/orders?cancelled=include&per_page=100', $this->adminHeaders)->assertOk()->json('data.data');
        $actual = collect($rows)->mapWithKeys(fn ($r) => [$r['order_number'] => $r['journey']])->all();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_counts_and_filters_on_the_admin_list(): void
    {
        $this->seedAll();
        $get = fn (array $q) => $this->getJson('/api/orders/all/orders?' . http_build_query($q + ['cancelled' => 'include', 'per_page' => 100]), $this->adminHeaders)->assertOk()->json('data');

        $all = $get([]);
        $this->assertSame(27, $all['journey_counts']['sections']['all']);
        $this->assertSame(4, $all['journey_counts']['sections']['new']);
        $this->assertSame(11, $all['journey_counts']['sections']['active']);
        $this->assertSame(5, $all['journey_counts']['sections']['delivered']);
        $this->assertSame(3, $all['journey_counts']['sections']['returning']);
        $this->assertSame(2, $all['journey_counts']['sections']['returned']);
        $this->assertSame(2, $all['journey_counts']['sections']['closed']);
        $this->assertSame(4, $all['journey_counts']['stages']['active.in_transit'], 'incl. the Shipped order without a CarryBee event');
        $this->assertSame(2, $all['journey_counts']['stages']['active.on_hold']);

        $active = $get(['section' => 'active']);
        $this->assertSame(11, $active['total']);
        $this->assertSame(27, $active['journey_counts']['sections']['all'], 'counts ignore the section filter');

        $this->assertSame(2, $get(['section' => 'active', 'stage' => 'at_sorting'])['total']);
        $this->assertSame(1, $get(['section' => 'returned', 'stage' => 'paid_return'])['total']);
        $this->assertSame(27, $get(['section' => 'nonsense'])['total'], 'unknown section is ignored');

        // Other filters narrow the counts too (search one order).
        $one = $get(['search' => 'J-5']);
        $this->assertSame(1, $one['journey_counts']['sections']['all']);
    }

    public function test_vendor_list_has_sections_search_and_counts(): void
    {
        $this->seedAll();
        $vendorHeaders = $this->headersFor(User::find($this->vendor->user_id));
        $get = fn (array $q) => $this->getJson("/api/orders/vendor/{$this->vendor->id}?" . http_build_query($q + ['per_page' => 100]), $vendorHeaders)->assertOk()->json('data');

        $all = $get([]);
        $this->assertArrayHasKey('journey_counts', $all);
        $this->assertSame(0, $all['journey_counts']['stages']['new.pending'], 'vendor list hides Pending orders');
        $this->assertSame(11, $get(['section' => 'active'])['total']);
        $this->assertSame(1, $get(['section' => 'active', 'stage' => 'at_delivery_hub'])['total']);
        $this->assertSame(['J-3'], collect($get(['search' => 'FJ3'])['data'])->pluck('order_number')->all());
    }

    public function test_dropshipper_list_has_sections_and_counts(): void
    {
        $this->seedAll();
        $headers = $this->headersFor($this->dropshipper);
        $get = fn (array $q) => $this->getJson("/api/orders/list/{$this->dropshipper->id}?" . http_build_query($q + ['per_page' => 100]), $headers)->assertOk()->json('data');

        $all = $get([]);
        $this->assertSame(27, $all['journey_counts']['sections']['all']);
        $this->assertSame(3, $get(['section' => 'returning'])['total']);
        $this->assertSame('delivered.partial', collect($get(['section' => 'delivered', 'stage' => 'partial'])['data'])->first()['journey']);
        $this->assertSame('P', $all['data'][0]['items'][0]['product_name'], 'items are listed for the thumbnails');
    }

    public function test_order_lists_flag_supplier_and_dropshipper_invoices(): void
    {
        $paidOut = $this->order(10, 'order.paid');
        $supplierOnly = $this->order(9, 'order.paid');
        $cancelledInvoice = $this->order(6, 'order.delivered');
        $plain = $this->order(6, 'order.delivered');

        \App\Models\SettlementInvoice::create(['invoice_number' => 'PAY-1', 'type' => 'dropshipper_payout', 'dropshipper_id' => $this->dropshipper->id,
            'status' => 'paid', 'order_ids' => [$paidOut->id], 'paid_at' => now()]);
        \App\Models\SettlementInvoice::create(['invoice_number' => 'PAY-X', 'type' => 'dropshipper_payout', 'dropshipper_id' => $this->dropshipper->id,
            'status' => 'cancelled', 'order_ids' => [$cancelledInvoice->id]]);
        \App\Models\SettlementInvoice::create(['invoice_number' => 'INV-S1', 'type' => 'vendor_settlement', 'vendor_id' => $this->vendor->id,
            'status' => 'pending', 'order_ids' => [(string) $paidOut->id, $supplierOnly->id]]);

        $admin = collect($this->getJson('/api/orders/all/orders?per_page=100', $this->adminHeaders)->assertOk()->json('data.data'))->keyBy('order_number');
        $this->assertSame('PAY-1', $admin[$paidOut->order_number]['invoices']['dropshipper']['invoice_number']);
        $this->assertSame('paid', $admin[$paidOut->order_number]['invoices']['dropshipper']['status']);
        $this->assertSame('INV-S1', $admin[$paidOut->order_number]['invoices']['supplier']['invoice_number']);
        $this->assertSame('INV-S1', $admin[$supplierOnly->order_number]['invoices']['supplier']['invoice_number']);
        $this->assertNull($admin[$supplierOnly->order_number]['invoices']['dropshipper']);
        $this->assertNull($admin[$cancelledInvoice->order_number]['invoices']['dropshipper'], 'cancelled invoices are ignored');
        $this->assertSame(['supplier' => null, 'dropshipper' => null], $admin[$plain->order_number]['invoices']);

        $mine = collect($this->getJson("/api/orders/list/{$this->dropshipper->id}?per_page=100", $this->headersFor($this->dropshipper))
            ->assertOk()->json('data.data'))->keyBy('order_number');
        $this->assertSame('PAY-1', $mine[$paidOut->order_number]['invoices']['dropshipper']['invoice_number']);
        $this->assertArrayNotHasKey('supplier', $mine[$paidOut->order_number]['invoices'], 'dropshippers do not see supplier invoices');
    }

    public function test_dropshipper_list_has_separate_field_filters(): void
    {
        $this->seedAll();
        $headers = $this->headersFor($this->dropshipper);
        $numbers = fn (array $q) => collect($this->getJson("/api/orders/list/{$this->dropshipper->id}?" . http_build_query($q + ['per_page' => 100]), $headers)
            ->assertOk()->json('data.data'))->pluck('order_number')->sort()->values()->all();

        $this->assertSame(['J-12'], $numbers(['consignment_id' => 'FJ12']));
        $this->assertSame(['J-7'], $numbers(['order_number' => 'J-7']));
        $this->assertSame(['J-9'], $numbers(['customer_name' => 'Customer 9']));
        $this->assertSame(['J-4'], $numbers(['customer_phone' => '+8801700000004']), 'country code is ignored');
        $this->assertSame(['J-12', 'J-13', 'J-14', 'J-15'], $numbers(['status' => 5]));
        $this->assertSame([], $numbers(['status' => 5, 'customer_name' => 'Customer 9']), 'fields combine with AND');
    }
}
