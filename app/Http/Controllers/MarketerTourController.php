<?php

namespace App\Http\Controllers;

use App\Models\MarketerClient;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner Portal F7 — Tour Milestones (motivation only: progress is shown, nothing is claimed or paid).
 * GET /marketer/tours: delivered orders of this marketer's clients (all time) against the tour targets
 * in config('marketer.tours'). Only dropshipper names and counts are shown — no shopper data.
 */
class MarketerTourController extends Controller
{
    /** Delivered, CarryBee paid, completed, partially delivered — same as the overview. */
    private const DELIVERED_STATUSES = [6, 9, 10, 13];

    public function index(Request $request): JsonResponse
    {
        $marketer = $request->attributes->get('api_user') ?? $request->user();
        $clients = MarketerClient::where('marketer_user_id', $marketer->id)->with('dropshipper:id,name,business_name')->get();
        $clientIds = $clients->pluck('id')->all();

        $perClient = $clientIds === [] ? collect() : Order::whereIn('marketer_client_id', $clientIds)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->selectRaw('marketer_client_id, COUNT(*) AS delivered')
            ->groupBy('marketer_client_id')->toBase()->get()->pluck('delivered', 'marketer_client_id');
        $delivered = (int) $perClient->sum();

        $tours = collect(config('marketer.tours', []))->sortBy('target')->values();
        $milestones = $tours->map(fn ($t) => $t + [
            'reached' => $delivered >= $t['target'],
            'percent' => $t['target'] > 0 ? min(100, (int) floor($delivered * 100 / $t['target'])) : 100,
            'remaining' => max(0, $t['target'] - $delivered),
        ])->all();
        $current = collect($milestones)->firstWhere('reached', false);

        return response()->json(['status' => 'success', 'message' => 'Tour milestones', 'data' => [
            'delivered_orders' => $delivered,
            'current' => $current,
            'all_reached' => $current === null && $milestones !== [],
            'reached_count' => collect($milestones)->where('reached', true)->count(),
            'milestones' => $milestones,
            'global_tours' => config('marketer.global_tours', []),
            'clients' => $clients->map(fn ($c) => [
                'name' => $c->dropshipper?->business_name ?: ($c->dropshipper?->name ?: 'Client #' . $c->id),
                'delivered' => (int) ($perClient[$c->id] ?? 0),
                'active' => $c->ended_at === null,
            ])->filter(fn ($c) => $c['delivered'] > 0 || $c['active'])->sortByDesc('delivered')->values()->all(),
            'counted_statuses' => self::DELIVERED_STATUSES,
        ]]);
    }
}
