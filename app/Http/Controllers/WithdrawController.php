<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\WithdrawRequest;
use App\Models\ResellerTransaction;
use App\Models\UserBankAccount;
use App\Service\CompanyTransactionService;
use App\Service\NotificationService;
use Illuminate\Support\Facades\DB;

class WithdrawController extends Controller
{
	protected $companyTransactions;
	protected $notificationService;

	public function __construct(CompanyTransactionService $companyTransactions, NotificationService $notificationService)
	{
		$this->companyTransactions = $companyTransactions;
		$this->notificationService = $notificationService;
	}

	private function notifyWithdrawCreated(WithdrawRequest $withdraw): void
	{
		$amount = number_format((float) $withdraw->amount, 2, '.', '');

		$this->notificationService->createNotificationSafely([
			'title'      => 'Withdraw Request Submitted',
			'subtitle'   => "Your withdraw request #{$withdraw->id} for {$amount} BDT is pending.",
			'created_by' => $withdraw->user_id,
			'send_to'    => $withdraw->user_id,
			'is_seen'    => false,
			'type'       => 'payment',
			'is_active'  => true,
			'image'      => null,
			'module'     => 'withdraw',
		]);

		$this->notificationService->createAdminNotifications([
			'title'      => 'New Withdraw Request',
			'subtitle'   => "Withdraw request #{$withdraw->id} submitted. Amount {$amount} BDT.",
			'created_by' => $withdraw->user_id,
			'is_seen'    => false,
			'type'       => 'payment',
			'is_active'  => true,
			'image'      => null,
			'module'     => 'withdraw',
		]);
	}

	private function notifyWithdrawStatusChanged(WithdrawRequest $withdraw, string $previousStatus): void
	{
		$amount = number_format((float) $withdraw->amount, 2, '.', '');
		$status = ucfirst($withdraw->status);

		$this->notificationService->createNotificationSafely([
			'title'      => "Withdraw Request {$status}",
			'subtitle'   => "Your withdraw request #{$withdraw->id} changed from {$previousStatus} to {$withdraw->status}. Amount {$amount} BDT.",
			'created_by' => null,
			'send_to'    => $withdraw->user_id,
			'is_seen'    => false,
			'type'       => 'payment',
			'is_active'  => true,
			'image'      => null,
			'module'     => 'withdraw',
		]);

		$this->notificationService->createAdminNotifications([
			'title'      => 'Withdraw Status Changed',
			'subtitle'   => "Withdraw request #{$withdraw->id} changed from {$previousStatus} to {$withdraw->status}. Amount {$amount} BDT.",
			'created_by' => null,
			'is_seen'    => false,
			'type'       => 'payment',
			'is_active'  => true,
			'image'      => null,
			'module'     => 'withdraw',
		]);
	}

	/**
	 * Add a new withdraw request.
	 */
	public function addWithdrawRequest(Request $request)
	{
		try {
			$validated = $request->validate([
				'amount' => 'required|numeric|min:1',
				'user_id' => 'required|exists:users,id',
				'bank_id' => 'nullable|exists:user_bank_accounts,id',
				'note' => 'nullable|string',
				'type' => 'nullable|string',
			]);
			$userBank = UserBankAccount::where('id', $validated['bank_id'])->where('user_id', $request->user_id)->first();
			if (!$userBank) {
				return response()->json(['success' => false, 'message' => 'Invalid bank account.'], 400);
			}
			$withdraw = WithdrawRequest::create([
				'user_id' => $request->user_id,
				'amount' => $validated['amount'],
				'status' => 'pending',
				'payment_method' => $userBank->payment_method_id,
				'bank_id' => $validated['bank_id'] ?? null,
				'note' => $validated['note'] ?? null,
				'type' => $validated['type'] ?? null,
			]);
			$this->notifyWithdrawCreated($withdraw);
			return response()->json(['success' => true, 'data' => $withdraw], 201);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get all withdraw requests (admin).
	 */
	public function getAllWithdrawRequest()
	{
		try {
			$withdraws = WithdrawRequest::with(['user', 'bank'])->orderByDesc('id')->get();
			return response()->json(['success' => true, 'data' => $withdraws]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get withdraw requests for the authenticated user.
	 */
	public function getUserWithdrawRequest($id)
	{
		try {
			$userId = $id;
			$withdraws = WithdrawRequest::with(['bank'])->where('user_id', $userId)->orderByDesc('id')->get();
			return response()->json(['success' => true, 'data' => $withdraws]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Change the status of a withdraw request.
	 */
	public function withdrawStatusChange(Request $request, $id)
	{
		try {
			$validated = $request->validate([
				'status' => 'required|in:pending,approved,rejected',
			]);
			$withdraw = WithdrawRequest::findOrFail($id);
			$wasApproved = $withdraw->status === 'approved';
			$previousStatus = $withdraw->status;

			DB::transaction(function () use ($withdraw, $validated, $wasApproved) {
				$withdraw->status = $validated['status'];
				$withdraw->save();

				if ($validated['status'] === 'approved' && !$wasApproved) {
					$trxId = 'WD-' . $withdraw->id . '-' . time();

					ResellerTransaction::updateOrCreate(
						[
							'reseller_id' => $withdraw->user_id,
							'ref_id' => $withdraw->id,
							'type' => 'withdraw',
							'trx_type' => 'debit',
						],
						[
							'amount' => $withdraw->amount,
							'trx_id' => $trxId,
							'note' => 'Withdraw approved',
							'status' => 'completed',
							'source' => 'withdraw',
							'order_id' => null,
						]
					);

					$this->companyTransactions->recordWithdrawApproval($withdraw, $trxId);
				}
			});

			$withdraw->refresh();
			if ($previousStatus !== $withdraw->status) {
				$this->notifyWithdrawStatusChanged($withdraw, $previousStatus);
			}

			return response()->json(['success' => true, 'data' => $withdraw]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
			], 500);
		}
	}
}
