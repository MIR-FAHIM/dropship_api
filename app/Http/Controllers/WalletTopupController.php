<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WalletTopup;
use App\Service\NotificationService;
use App\Service\OrderAccountingService;
use Illuminate\Support\Facades\DB;

class WalletTopupController extends Controller
{
    protected $notificationService;
    protected $orderAccountingService;

    public function __construct(
        NotificationService $notificationService,
        OrderAccountingService $orderAccountingService
    ) {
        $this->notificationService = $notificationService;
        $this->orderAccountingService = $orderAccountingService;
    }

    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status'  => 'failed',
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    /**
     * POST /wallet/topup
     * Dropshipper submits wallet recharge request.
     */
    public function requestTopup(Request $request)
    {
        try {
            $userId = (int) ($request->user()?->id ?: $request->input('user_id'));
            if (!$userId) {
                return $this->failed('User ID is required', null, 400);
            }

            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:50'],
                'payment_method' => ['required', 'string', 'max:50'],
                'trx_id' => ['required', 'string', 'max:100'],
                'sender_phone' => ['nullable', 'string', 'max:30'],
                'receipt_image' => ['nullable', 'string', 'max:255'],
            ]);

            $existing = WalletTopup::where('trx_id', $validated['trx_id'])->first();
            if ($existing) {
                return $this->failed('This transaction ID has already been submitted.', null, 422);
            }

            $topup = WalletTopup::create([
                'user_id' => $userId,
                'amount' => (float) $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'sender_phone' => $validated['sender_phone'] ?? null,
                'trx_id' => $validated['trx_id'],
                'receipt_image' => $validated['receipt_image'] ?? null,
                'status' => 'pending',
            ]);

            $formattedAmount = number_format((float) $topup->amount, 2, '.', '');

            // Notify Admin of new topup request
            $this->notificationService->createAdminNotifications([
                'title'      => 'New Wallet Recharge Request',
                'subtitle'   => "Reseller submitted wallet recharge of {$formattedAmount} BDT via {$topup->payment_method} (Trx: {$topup->trx_id}).",
                'created_by' => $userId,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'wallet',
            ]);

            // Notify Reseller request received
            $this->notificationService->createNotificationSafely([
                'title'      => 'Recharge Request Submitted',
                'subtitle'   => "Your wallet recharge request of {$formattedAmount} BDT is pending admin verification.",
                'created_by' => $userId,
                'send_to'    => $userId,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'wallet',
            ]);

            return $this->success('Wallet recharge request submitted successfully.', $topup, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /wallet/topup/my-history
     * Dropshipper views their recharge history.
     */
    public function myTopups(Request $request)
    {
        try {
            $userId = (int) ($request->user()?->id ?: $request->input('user_id'));
            if (!$userId) {
                return $this->failed('User ID is required', null, 400);
            }

            $perPage = (int) $request->input('per_page', 20);
            $query = WalletTopup::where('user_id', $userId)->latest();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $items = $query->paginate($perPage);

            return $this->success('Wallet recharge history fetched successfully.', $items);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /wallet/topup/all
     * Admin views all recharge requests.
     */
    public function allTopups(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $query = WalletTopup::with(['user:id,name,email,phone'])->latest();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('trx_id', 'like', "%{$search}%")
                      ->orWhere('sender_phone', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($sub) use ($search) {
                          $sub->where('name', 'like', "%{$search}%")
                              ->orWhere('phone', 'like', "%{$search}%");
                      });
                });
            }

            $items = $query->paginate($perPage);

            $pendingCount = WalletTopup::where('status', 'pending')->count();
            $approvedTotal = (float) WalletTopup::where('status', 'approved')->sum('amount');

            return $this->success('All recharge requests fetched successfully.', [
                'items' => $items,
                'pending_count' => $pendingCount,
                'approved_total' => $approvedTotal,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /wallet/topup/{id}/approve
     * Admin approves a recharge request.
     */
    public function approveTopup(Request $request, $id)
    {
        try {
            $topup = WalletTopup::find($id);
            if (!$topup) {
                return $this->failed('Top-up request not found', null, 404);
            }

            if ($topup->status === 'approved') {
                return $this->failed('This recharge request is already approved.', null, 400);
            }

            $adminId = (int) ($request->user()?->id ?: 1);
            $note = $request->input('admin_note', 'Approved by Admin');

            $topup = $this->orderAccountingService->approveWalletTopup($topup, $adminId, $note);
            $formattedAmount = number_format((float) $topup->amount, 2, '.', '');

            // Notify dropshipper
            $this->notificationService->createNotificationSafely([
                'title'      => 'Wallet Recharge Approved',
                'subtitle'   => "Your wallet recharge of {$formattedAmount} BDT has been verified and added to your balance.",
                'created_by' => $adminId,
                'send_to'    => $topup->user_id,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'wallet',
            ]);

            return $this->success('Wallet recharge approved successfully.', $topup);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /wallet/topup/{id}/reject
     * Admin rejects a recharge request.
     */
    public function rejectTopup(Request $request, $id)
    {
        try {
            $topup = WalletTopup::find($id);
            if (!$topup) {
                return $this->failed('Top-up request not found', null, 404);
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:255'],
            ]);

            $adminId = (int) ($request->user()?->id ?: 1);
            $topup = $this->orderAccountingService->rejectWalletTopup($topup, $adminId, $validated['reason']);
            $formattedAmount = number_format((float) $topup->amount, 2, '.', '');

            // Notify dropshipper
            $this->notificationService->createNotificationSafely([
                'title'      => 'Wallet Recharge Rejected',
                'subtitle'   => "Your recharge request of {$formattedAmount} BDT was rejected: {$validated['reason']}.",
                'created_by' => $adminId,
                'send_to'    => $topup->user_id,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'wallet',
            ]);

            return $this->success('Wallet recharge rejected successfully.', $topup);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
