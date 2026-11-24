<?php

namespace App\Service;

use App\Models\DeliveryCompany;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\VendorCarryBeeCredintial;
use Illuminate\Support\Facades\Cache;

/**
 * Admin → CarryBee Invoices: every CarryBee invoice of every account, grouped and summed.
 *
 * CarryBee invoices belong to a CarryBee account (business), not to a vendor:
 *  - the Reseller Brain platform account ships for every vendor without its own account,
 *    so one platform invoice can hold parcels of several vendors;
 *  - a vendor with its own delivery account has its own CarryBee invoices.
 * Platform invoices are tagged with the vendors whose orders they paid (orders.carrybee_invoice_id).
 */
class CarrybeeInvoiceReportService
{
    private const PAGE_LIMIT = 100;
    private const MAX_PAGES = 50;
    private const CACHE_MINUTES = 10;

    /** Amount fields summed per group (see the page's "What the amounts mean"). */
    public const SUM_FIELDS = ['collectable_amount', 'collected', 'delivery_fee', 'cod_fee', 'total_fee', 'receivable', 'compensation_amount', 'payable_amount'];

    /**
     * @param array{from?:?string,to?:?string,receivable_status?:?string,refresh?:bool} $filters
     */
    public function report(array $filters = []): array
    {
        $groups = [];
        foreach ($this->accounts() as $account) {
            $groups[] = $this->group($account, $filters);
        }

        $all = collect($groups)->filter(fn ($g) => !$g['error']);

        return [
            'groups' => $groups,
            'totals' => $this->sum($all->flatMap(fn ($g) => $g['invoices'])->all()),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /** Platform account first, then every vendor with its own complete CarryBee credentials. */
    public function accounts(): array
    {
        $accounts = [];
        $platform = DeliveryCompany::where('is_active', true)->latest()->first();
        if ($platform) {
            $accounts[] = [
                'key' => 'platform', 'type' => 'platform', 'vendor_id' => null,
                'label' => ($platform->company_name ?: 'CarryBee') . ' — Reseller Brain platform account',
                'creds' => [$platform->api_key, $platform->secret_key, $platform->client_context],
            ];
        }

        Vendor::where('has_own_delivery_account', true)->orderBy('shop_name')->get()->each(function (Vendor $v) use (&$accounts) {
            $rows = VendorCarryBeeCredintial::whereIn('vendor_id', array_unique(array_filter([$v->user_id, $v->id])))->latest()->get();
            $cred = $rows->firstWhere('is_active', true);
            $name = $v->shop_name ?: "Vendor #{$v->id}";
            $accounts[] = [
                'key' => "vendor-{$v->id}", 'type' => 'vendor', 'vendor_id' => $v->id, 'old' => false,
                'label' => $name,
                'creds' => $cred ? [$cred->client_id, $cred->client_secret, $cred->client_context] : null,
            ];
            // Old accounts: a vendor that moved to a new CarryBee account keeps the old one saved as
            // inactive. It is never used for new parcels, only to read its past invoices.
            $rows->where('is_active', false)->each(function ($old) use (&$accounts, $v, $name) {
                if (!$old->client_id || !$old->client_secret || !$old->client_context) {
                    return;
                }
                $accounts[] = [
                    'key' => "vendor-{$v->id}-old-{$old->id}", 'type' => 'vendor', 'vendor_id' => $v->id, 'old' => true,
                    'label' => "{$name} — old CarryBee account #{$old->id}" . ($old->note ? " ({$old->note})" : ''),
                    'creds' => [$old->client_id, $old->client_secret, $old->client_context],
                ];
            });
        });

        return $accounts;
    }

    /** All CarryBee accounts of one vendor (current first, then old ones). */
    public function vendorAccounts(int $vendorId): array
    {
        return array_values(array_filter($this->accounts(), fn ($a) => $a['type'] === 'vendor' && (int) $a['vendor_id'] === $vendorId));
    }

    private function group(array $account, array $filters): array
    {
        $base = ['key' => $account['key'], 'type' => $account['type'], 'vendor_id' => $account['vendor_id'], 'label' => $account['label']];
        $fetched = $this->invoicesFor($account, !empty($filters['refresh']));
        if ($fetched['error']) {
            return $base + ['error' => $fetched['error'], 'invoices' => [], 'totals' => $this->sum([]), 'truncated' => false];
        }

        $invoices = array_values(array_filter($fetched['invoices'], fn ($inv) => $this->matches($inv, $filters)));
        if ($account['type'] === 'platform') {
            $invoices = $this->tagVendors($invoices);
        }
        usort($invoices, fn ($a, $b) => strcmp((string) ($b['invoice_date'] ?? ''), (string) ($a['invoice_date'] ?? '')));

        return $base + ['error' => null, 'invoices' => $invoices, 'totals' => $this->sum($invoices), 'truncated' => $fetched['truncated']];
    }

    /** CarryBee API client for an account from accounts(), or null when its credentials are incomplete. */
    public function serviceFor(array $account): ?CarrybeeService
    {
        [$id, $secret, $context] = $account['creds'] ?? [null, null, null];

        return ($id && $secret && $context) ? new CarrybeeService($id, $secret, $context) : null;
    }

    /** Every invoice of one account (cached 10 min). @return array{error:?string,invoices:array,truncated:bool} */
    public function invoicesFor(array $account, bool $refresh = false): array
    {
        $service = $this->serviceFor($account);
        if (!$service) {
            return ['error' => 'CarryBee credentials are missing or incomplete for this account.', 'invoices' => [], 'truncated' => false];
        }
        [$id, , $context] = $account['creds'];
        $cacheKey = 'carrybee-invoices:' . md5($id . '|' . $context);
        if ($refresh) {
            Cache::forget($cacheKey);
        }
        $fetched = Cache::get($cacheKey);
        if (!$fetched) {
            $fetched = $this->fetchAll($service);
            if (!$fetched['error']) {
                Cache::put($cacheKey, $fetched, now()->addMinutes(self::CACHE_MINUTES));
            }
        }

        return $fetched;
    }

    /** Read every page (CarryBee sends page numbers as strings). */
    private function fetchAll(CarrybeeService $service): array
    {
        $invoices = [];
        $page = 1;
        for ($read = 0; $read < self::MAX_PAGES; $read++) {
            $result = $service->listInvoices($page, self::PAGE_LIMIT);
            if ($result['status'] >= 400) {
                $body = is_array($result['body']) ? $result['body'] : [];

                return ['error' => 'CarryBee said: ' . ($body['message'] ?? $result['raw'] ?? 'request failed') . " (HTTP {$result['status']})", 'invoices' => [], 'truncated' => false];
            }
            $items = $result['body']['data']['invoices'] ?? [];
            $invoices = array_merge($invoices, is_array($items) ? $items : []);
            $next = (int) ($result['body']['pagination']['nextPage'] ?? 0);
            if (empty($items) || $next <= $page) {
                return ['error' => null, 'invoices' => $invoices, 'truncated' => false];
            }
            $page = $next;
        }

        return ['error' => null, 'invoices' => $invoices, 'truncated' => true];
    }

    private function matches(array $inv, array $filters): bool
    {
        $date = (string) ($inv['invoice_date'] ?? '');
        if (!empty($filters['from']) && $date !== '' && $date < $filters['from']) {
            return false;
        }
        if (!empty($filters['to']) && $date !== '' && $date > $filters['to']) {
            return false;
        }
        if (!empty($filters['receivable_status']) && $filters['receivable_status'] !== 'all'
            && strtolower((string) ($inv['receivable_status'] ?? '')) !== $filters['receivable_status']) {
            return false;
        }

        return true;
    }

    /** Which vendors' orders each platform invoice paid for (from our own orders table). */
    private function tagVendors(array $invoices): array
    {
        $ids = array_values(array_filter(array_map(fn ($i) => $i['invoice_id'] ?? null, $invoices)));
        if (!$ids) {
            return $invoices;
        }
        $rows = Order::whereIn('carrybee_invoice_id', $ids)
            ->leftJoin('vendors', 'vendors.id', '=', 'orders.vendor_id')
            ->selectRaw('orders.carrybee_invoice_id AS invoice_id, orders.vendor_id, vendors.shop_name, COUNT(*) AS orders')
            ->groupBy('orders.carrybee_invoice_id', 'orders.vendor_id', 'vendors.shop_name')
            ->toBase()->get()->groupBy('invoice_id');

        return array_map(function ($inv) use ($rows) {
            $inv['vendors'] = ($rows[$inv['invoice_id'] ?? ''] ?? collect())->map(fn ($r) => [
                'vendor_id' => $r->vendor_id, 'shop_name' => $r->shop_name ?: ($r->vendor_id ? "Vendor #{$r->vendor_id}" : 'No vendor'), 'orders' => (int) $r->orders,
            ])->values()->all();

            return $inv;
        }, $invoices);
    }

    private function sum(array $invoices): array
    {
        $totals = ['invoices' => count($invoices), 'consignments' => 0];
        foreach (self::SUM_FIELDS as $f) {
            $totals[$f] = 0.0;
        }
        $totals['receivable_unpaid'] = 0.0;
        $totals['payable_unpaid'] = 0.0;
        foreach ($invoices as $inv) {
            $totals['consignments'] += (int) ($inv['total_consignment'] ?? 0);
            foreach (self::SUM_FIELDS as $f) {
                $totals[$f] += (float) ($inv[$f] ?? 0);
            }
            if (strtolower((string) ($inv['receivable_status'] ?? '')) === 'unpaid') {
                $totals['receivable_unpaid'] += (float) ($inv['receivable'] ?? 0);
            }
            if (strtolower((string) ($inv['payable_status'] ?? '')) === 'unpaid') {
                $totals['payable_unpaid'] += (float) ($inv['payable_amount'] ?? 0);
            }
        }
        foreach ($totals as $k => $v) {
            if (is_float($v)) {
                $totals[$k] = round($v, 2);
            }
        }

        return $totals;
    }
}
