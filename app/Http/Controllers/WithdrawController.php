<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\WithdrawRequest;
use Illuminate\Support\Facades\Auth;

class WithdrawController extends Controller
{
	/**
	 * Add a new withdraw request.
	 */
	public function addWithdrawRequest(Request $request)
	{
		$validated = $request->validate([
			'amount' => 'required|numeric|min:1',
			'payment_method' => 'required|string',
			'bank_id' => 'nullable|exists:user_bank_accounts,id',
			'note' => 'nullable|string',
			'type' => 'nullable|string',
		]);

		$withdraw = WithdrawRequest::create([
			'user_id' => Auth::id() ?? $request->user_id,
			'amount' => $validated['amount'],
			'status' => 'pending',
			'payment_method' => $validated['payment_method'],
			'bank_id' => $validated['bank_id'] ?? null,
			'note' => $validated['note'] ?? null,
			'type' => $validated['type'] ?? null,
		]);

		return response()->json(['success' => true, 'data' => $withdraw], 201);
	}

	/**
	 * Get all withdraw requests (admin).
	 */
	public function getAllWithdrawRequest()
	{
		$withdraws = WithdrawRequest::with(['user', 'bank'])->orderByDesc('id')->get();
		return response()->json(['success' => true, 'data' => $withdraws]);
	}

	/**
	 * Get withdraw requests for the authenticated user.
	 */
	public function getUserWithdrawRequest(Request $request)
	{
		$userId = Auth::id() ?? $request->user_id;
		$withdraws = WithdrawRequest::with(['bank'])->where('user_id', $userId)->orderByDesc('id')->get();
		return response()->json(['success' => true, 'data' => $withdraws]);
	}
}
