<?php

namespace App\Service;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Order journey sections, CarryBee panel style (All / New / Active / Delivered /
 * Returning / Returned / Cancelled & Lost), shared by the admin, vendor and dropshipper
 * order lists.
 *
 * Each order gets one "section.stage" key from its own status plus CarryBee's last event
 * for its consignment (delivery_assigned_infos.courier_stage). That one SQL CASE
 * expression is used for the filter, the counts and the per-order label, so they always
 * agree. No extra order statuses are needed: CarryBee's sub-stages come from the event.
 */
class OrderJourneyService
{
    /** Section => stage keys, in display order. */
    public const SECTIONS = [
        'new' => ['pending', 'assigned_to_vendor', 'ready_for_pickup', 'pickup_failed'],
        'active' => ['at_sorting', 'in_transit', 'at_delivery_hub', 'assigned_for_delivery', 'on_hold'],
        'delivered' => ['delivered', 'partial', 'exchanged'],
        'returning' => ['at_sorting', 'in_transit'],
        'returned' => ['returned', 'paid_return'],
        'closed' => ['cancelled', 'lost'],
    ];

    /** CarryBee's last event for the order's consignment. */
    private const COURIER_STAGE = '(SELECT dj.courier_stage FROM delivery_assigned_infos dj WHERE dj.order_id = orders.id ORDER BY dj.id DESC LIMIT 1)';

    /** SQL expression giving each order its "section.stage". */
    public static function caseExpression(): string
    {
        $cs = self::COURIER_STAGE;

        return "(CASE
            WHEN orders.status = 1 THEN 'new.pending'
            WHEN orders.status = 2 THEN 'new.assigned_to_vendor'
            WHEN orders.status = 3 AND {$cs} = 'order.pickup-failed' THEN 'new.pickup_failed'
            WHEN orders.status = 3 THEN 'new.ready_for_pickup'
            WHEN orders.status IN (4, 5) AND {$cs} IN ('order.delivery-on-hold', 'order.delivery-failed') THEN 'active.on_hold'
            WHEN orders.status IN (4, 5) AND {$cs} = 'order.assigned-for-delivery' THEN 'active.assigned_for_delivery'
            WHEN orders.status IN (4, 5) AND {$cs} = 'order.received-at-last-mile-hub' THEN 'active.at_delivery_hub'
            WHEN orders.status IN (4, 5) AND {$cs} IN ('order.picked', 'order.at-the-sorting-hub') THEN 'active.at_sorting'
            WHEN orders.status = 5 THEN 'active.assigned_for_delivery'
            WHEN orders.status = 4 THEN 'active.in_transit'
            WHEN orders.status = 13 THEN 'delivered.partial'
            WHEN orders.status IN (6, 9, 10) AND {$cs} = 'order.exchange' THEN 'delivered.exchanged'
            WHEN orders.status IN (6, 9, 10) THEN 'delivered.delivered'
            WHEN orders.status = 12 AND {$cs} = 'order.paid-return' THEN 'returned.paid_return'
            WHEN orders.status = 12 AND {$cs} = 'order.returned-at-sorting' THEN 'returning.at_sorting'
            WHEN orders.status = 12 THEN 'returning.in_transit'
            WHEN orders.status = 8 THEN 'returned.returned'
            WHEN orders.status = 7 THEN 'closed.cancelled'
            WHEN orders.status = 11 THEN 'closed.lost'
            ELSE 'other.other'
        END)";
    }

    /**
     * For a list query that already has every other filter applied:
     *  1. counts per section and stage (ignoring the section/stage filter, like CarryBee),
     *  2. applies ?section= and ?stage=,
     *  3. adds a "journey" column ("active.at_sorting") to each order.
     *
     * @return array{sections: array<string,int>, stages: array<string,int>}
     */
    public function apply(Builder $query, Request $request): array
    {
        $case = self::caseExpression();

        // Work out the journey in a subquery and group the outer query by its alias.
        // MySQL's only_full_group_by rejects GROUP BY on a CASE with a correlated subquery.
        $journeys = (clone $query)
            ->reorder()
            ->select(DB::raw("{$case} as journey"))
            ->toBase();

        $rows = DB::query()
            ->fromSub($journeys, 'oj')
            ->select('oj.journey', DB::raw('COUNT(*) as total'))
            ->groupBy('oj.journey')
            ->get();

        $stages = [];
        $sections = ['all' => 0];
        foreach (self::SECTIONS as $section => $keys) {
            $sections[$section] = 0;
            foreach ($keys as $key) {
                $stages["{$section}.{$key}"] = 0;
            }
        }
        foreach ($rows as $row) {
            $journey = (string) $row->journey;
            $total = (int) $row->total;
            $sections['all'] += $total;
            [$section] = explode('.', $journey, 2);
            if (array_key_exists($journey, $stages)) {
                $stages[$journey] += $total;
            }
            if (array_key_exists($section, $sections)) {
                $sections[$section] += $total;
            }
        }

        $section = (string) $request->get('section', '');
        $stage = (string) $request->get('stage', '');
        if (array_key_exists($section, self::SECTIONS)) {
            if ($stage !== '' && in_array($stage, self::SECTIONS[$section], true)) {
                $query->whereRaw("{$case} = ?", ["{$section}.{$stage}"]);
            } else {
                $query->whereRaw("{$case} LIKE ?", ["{$section}.%"]);
            }
        }

        // addSelect on a query with no columns would select ONLY "journey": keep orders.*.
        if (empty($query->getQuery()->columns)) {
            $query->select('orders.*');
        }
        $query->addSelect(DB::raw("{$case} as journey"));

        return ['sections' => $sections, 'stages' => $stages];
    }
}
