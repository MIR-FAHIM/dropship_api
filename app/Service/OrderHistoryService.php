<?php

namespace App\Service;

use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderHistoryService
{
    private const TOTALS = 'COUNT(DISTINCT oi.order_id) AS order_count, COUNT(*) AS item_count, SUM(COALESCE(oi.qty, 0)) AS total_quantity, SUM(COALESCE(oi.line_total, 0)) AS total_product_sales';

    private function items(array $filters, ?int $vendorId): Builder
    {
        $query = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id');
        if ($vendorId !== null) {
            $query->where('oi.shop_id', $vendorId);
        }
        foreach (['product_id', 'shop_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where('oi.'.$field, $filters[$field]);
            }
        }
        if (! empty($filters['unlinked_product'])) {
            $query->whereNull('oi.product_id');
        }
        foreach (['status', 'payment_status'] as $field) {
            if (isset($filters[$field])) {
                $query->where('o.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['date_from'])) {
            $query->where('o.created_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (isset($filters['date_to'])) {
            $query->where('o.created_at', '<', \Carbon\Carbon::parse($filters['date_to'])->addDay()->toDateString().' 00:00:00');
        }

        return $query;
    }

    public function groups(array $filters, ?int $vendorId, bool $groupByVendorProduct = false): LengthAwarePaginator
    {
        $base = $this->items($filters, $vendorId);
        $query = (clone $base)->select('oi.product_id')
            ->selectRaw('MAX(oi.product_name) AS product_name, MAX(o.created_at) AS latest_order_at')
            ->selectRaw(self::TOTALS)->groupBy('oi.product_id');
        if ($vendorId !== null || $groupByVendorProduct) {
            $query->addSelect('oi.shop_id')->groupBy('oi.shop_id');
        }
        $query->orderByDesc('latest_order_at')->orderBy('oi.product_id');
        if ($vendorId !== null || $groupByVendorProduct) {
            $query->orderBy('oi.shop_id');
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $rows = $page->getCollection();
        $productIds = $rows->pluck('product_id')->filter()->unique()->values();
        $products = Product::whereIn('id', $productIds)->get(['id', 'name', 'sku', 'slug'])->keyBy('id');

        $pairs = $rows->isEmpty() ? collect() : (clone $base)
            ->where(function (Builder $query) use ($productIds, $rows) {
                $query->whereIn('oi.product_id', $productIds);
                if ($rows->contains(fn ($row) => $row->product_id === null)) {
                    $query->orWhereNull('oi.product_id');
                }
            })->select('oi.product_id', 'oi.shop_id')->distinct()->get()->groupBy('product_id');

        $allShopIds = $pairs->flatten(1)->pluck('shop_id')->merge($rows->pluck('shop_id'))->filter()->unique();
        $shops = $this->shops($allShopIds);
        $panel = $vendorId === null ? 'admin' : 'vendor';

        $page->setCollection($rows->map(function ($row) use ($products, $pairs, $shops, $vendorId, $panel, $filters, $groupByVendorProduct) {
            $groupShops = $pairs->get($row->product_id ?? '', collect())->map(fn ($pair) => $shops->get($pair->shop_id))->filter()->values();
            $historyFilters = array_intersect_key($filters, array_flip(['shop_id', 'vendor_id', 'date_from', 'date_to', 'status', 'payment_status']));
            $historyFilters += $row->product_id === null ? ['unlinked_product' => 1] : ['product_id' => $row->product_id];
            if ($vendorId !== null || $groupByVendorProduct) {
                $historyFilters['shop_id'] = (int) $row->shop_id;
            }
            $data = [
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'product_name' => $row->product_name,
                'product' => $products->get($row->product_id),
                'totals' => $this->totals($row),
                'latest_order_at' => $row->latest_order_at,
                'shops' => $groupShops,
                'history_url' => url('api/'.$panel.'/orders/history/orders').'?'.http_build_query($historyFilters),
            ];
            if ($vendorId !== null || $groupByVendorProduct) {
                $data['shop_id'] = (int) $row->shop_id;
                $data['vendor_id'] = (int) $row->shop_id;
                $data['shop'] = $shops->get($row->shop_id);
                $data['vendor'] = $shops->get($row->shop_id)['vendor'] ?? null;
            }

            return $data;
        }));

        return $page;
    }

    public function orders(array $filters, ?int $vendorId, bool $embedded = false): LengthAwarePaginator
    {
        $base = $this->items($filters, $vendorId);
        $pageName = $embedded ? 'history_page' : 'page';
        $perPageName = $embedded ? 'history_per_page' : 'per_page';
        $page = (clone $base)->select('oi.order_id')->selectRaw(self::TOTALS)
            ->selectRaw('MAX(o.created_at) AS order_date')->groupBy('oi.order_id')
            ->orderByDesc('order_date')->orderByDesc('oi.order_id')
            ->paginate((int) ($filters[$perPageName] ?? 20), ['*'], $pageName, (int) ($filters[$pageName] ?? 1));
        $orderIds = $page->getCollection()->pluck('order_id');
        // Explicit projections avoid Order's consignment accessors and unrelated vendor items.
        $orders = DB::table('orders as o')->leftJoin('order_statuses as os', 'os.id', '=', 'o.status')
            ->whereIn('o.id', $orderIds)
            ->get(['o.id', 'o.order_number', 'o.status', 'o.payment_status', 'os.name as status_name', 'os.type as status_type'])
            ->keyBy('id');
        $items = (clone $base)->whereIn('oi.order_id', $orderIds)->select('oi.*')->orderBy('oi.id')->get();
        $shops = $this->shops($items->pluck('shop_id'));
        $itemsByOrder = $items->map(function ($item) use ($shops) {
            return [
                'id' => (int) $item->id,
                'order_id' => (int) $item->order_id,
                'product_id' => $item->product_id === null ? null : (int) $item->product_id,
                'shop_id' => $item->shop_id === null ? null : (int) $item->shop_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'attribute_id' => $item->attribute_id,
                'unit_price' => $this->price($item->unit_price),
                'admin_price' => $this->price($item->admin_price),
                'reseller_price' => $this->price($item->reseller_price),
                'qty' => (int) $item->qty,
                'line_total' => $this->price($item->line_total),
                'status' => $item->status,
                'shop' => $shops->get($item->shop_id),
            ];
        })->groupBy('order_id');

        $page->setCollection($page->getCollection()->map(function ($row) use ($orders, $itemsByOrder) {
            $order = $orders->get($row->order_id);

            return [
                'order_id' => (int) $row->order_id,
                'order_number' => $order?->order_number,
                'order_date' => $row->order_date,
                'status' => $order?->status,
                'order_status' => $order?->status_name === null ? null : [
                    'id' => (int) $order->status, 'name' => $order->status_name, 'type' => $order->status_type,
                ],
                'payment_status' => $order?->payment_status,
                'quantity' => (int) $row->total_quantity,
                'subtotal' => $this->price($row->total_product_sales),
                'items' => $itemsByOrder->get($row->order_id, collect())->values(),
            ];
        }));

        return $page;
    }

    private function shops(Collection $ids): Collection
    {
        $vendors = DB::table('vendors')->whereIn('id', $ids->filter()->unique())->get(['id', 'user_id', 'shop_name']);
        $profiles = DB::table('shops')->whereIn('user_id', $vendors->pluck('user_id'))
            ->get(['id', 'user_id', 'name', 'slug', 'address'])->groupBy('user_id');

        return $vendors->mapWithKeys(fn ($vendor) => [$vendor->id => [
            'id' => (int) $vendor->id,
            'name' => $vendor->shop_name,
            'vendor' => ['id' => (int) $vendor->id, 'user_id' => (int) $vendor->user_id, 'shop_name' => $vendor->shop_name],
            'shop_profiles' => $profiles->get($vendor->user_id, collect())->values(),
        ]]);
    }

    private function totals(object $row): array
    {
        return [
            'order_count' => (int) $row->order_count,
            'item_count' => (int) $row->item_count,
            'total_quantity' => (int) $row->total_quantity,
            'total_product_sales' => $this->price($row->total_product_sales),
        ];
    }

    private function price(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
