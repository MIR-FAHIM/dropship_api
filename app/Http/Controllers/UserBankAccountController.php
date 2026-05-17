<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\PaymentMethod;
use App\Models\UserBankAccount;

class UserBankAccountController extends Controller
{
	// Add a new payment method
	public function addPaymentMethod(Request $request)
	{
		$validated = $request->validate([
			'name' => 'required|string|max:255',
			'is_active' => 'boolean',
			'type' => 'nullable|string|max:255',
			'logo' => 'nullable|string|max:255',
			'note' => 'nullable|string',
		]);
		$paymentMethod = PaymentMethod::create($validated);
		return response()->json(['status' => 'success', 'data' => $paymentMethod], 201);
	}

	// Get all payment methods
	public function getPaymentMethod()
	{
		$methods = PaymentMethod::all();
		return response()->json(['status' => 'success', 'data' => $methods]);
	}

	// Add a new user bank account
	public function addUserBankAccount(Request $request)
	{
		$validated = $request->validate([
			'user_id' => 'required|exists:users,id',
			'bank_name' => 'required|string|max:255',
			'acc_name' => 'nullable|string|max:255',
			'type' => 'nullable|string|max:255',
			'account_no' => 'required|string|max:255',
			'branch' => 'nullable|string|max:255',
			'route' => 'nullable|string|max:255',
			'address' => 'nullable|string|max:255',
			'is_active' => 'boolean',
			'payment_method_id' => 'nullable|exists:payment_methods,id',
		]);
		$bankAccount = UserBankAccount::create($validated);
		return response()->json(['status' => 'success', 'data' => $bankAccount], 201);
	}

	// Get all user bank accounts (optionally filter by user_id)
	public function getUserBankAccount($id)
	{
		$query = UserBankAccount::query();
		
			$query->where('user_id', $id);
		
		$accounts = $query->with(['user', 'paymentMethod'])->get();
		return response()->json(['status' => 'success', 'data' => $accounts]);
	}
}
