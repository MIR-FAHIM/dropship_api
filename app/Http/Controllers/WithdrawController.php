<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\WithdrawRequest;
use App\Models\ResellerTransaction;
use App\Models\UserBankAccount;
use App\Service\CompanyTransactionService;
use Illuminate\Support\Facades\DB;

class WithdrawController extends Controller
{
	protected $companyTransactions;

	public function __construct(CompanyTransactionService $companyTransactions)
	{
		$this->companyTransactions = $companyTransactions;
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

			return response()->json(['success' => true, 'data' => $withdraw]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
			], 500);
		}
	}
}
