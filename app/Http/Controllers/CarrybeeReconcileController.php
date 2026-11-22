<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderReturnPenalty;
use App\Models\PayoutFlag;
use App\Service\CarrybeeReconcileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Admin → Delivery → CarryBee Invoices → "Match payouts with CarryBee".
 * See CarrybeeReconcileService.
 */
class CarrybeeReconcileController extends Controller
{
    public function __construct(private readonly CarrybeeReconcileService $reconcile)
    {
    }

    /** GET /delivery-companies/carrybee/reconcile/{account} */
    public function report(string $account): JsonResponse
    {
        return $this->run(fn () => $this->reconcile->report($account), 'Reconciliation report');
    }

    /** POST /delivery-companies/carrybee/reconcile/{account}/sync  {refresh?} */
    public function sync(Request $request, string $account): JsonResponse
    {
        return $this->run(function () use ($request, $account) {
            $result = $this->reconcile->syncAccount($account, $request->boolean('refresh'));

            return ['sync' => $result, 'report' => $this->reconcile->report($account)];
        }, 'Synced with CarryBee');
    }

    /** POST /delivery-companies/carrybee/reconcile/orders/{orderId}/penalty  {payer: vendor|admin|dropshipper|null, note?} */
    public function penalty(Request $request, int $orderId): JsonResponse
    {
        $data = $request->validate([
            'payer' => ['nullable', Rule::in(OrderReturnPenalty::PAYERS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $order = Order::findOrFail($orderId);

        return $this->run(fn () => $this->reconcile->setPenaltyPayer($order, $data['payer'] ?? null, $data['note'] ?? null, $this->adminId($request)), 'Return charge payer saved');
    }

    /** POST /delivery-companies/carrybee/reconcile/flags/{id}/resolve  {note?} */
    public function resolveFlag(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->run(fn () => $this->reconcile->resolveFlag(PayoutFlag::findOrFail($id), $data['note'] ?? null, $this->adminId($request)), 'Flag resolved');
    }

    /** POST /order-settlements/vendor-invoices/{id}/reissue */
    public function reissue(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => $this->reconcile->reissueVendorInvoice($id, $this->adminId($request)), 'Corrected vendor invoice created');
    }

    private function adminId(Request $request): ?int
    {
        return ($request->attributes->get('api_user') ?? $request->user())?->id;
    }

    private function run(callable $fn, string $message): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'message' => $message, 'data' => $fn()]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'failed', 'message' => 'Not found'], 404);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'failed', 'message' => 'Something went wrong', 'errors' => ['error' => $e->getMessage()]], 500);
        }
    }
}
