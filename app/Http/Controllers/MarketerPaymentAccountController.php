<?php

namespace App\Http\Controllers;

use App\Models\UserBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Partner Portal F2: where a marketer wants their commission paid (bKash / Nagad / Rocket / bank).
 * Stored in user_bank_accounts like dropshippers' accounts; a marketer only sees and edits their own.
 * (Marketer logins may only use marketer.panel.* routes, so they can't use the generic endpoints.)
 */
class MarketerPaymentAccountController extends Controller
{
    public const METHODS = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank_transfer' => 'Bank'];

    /** GET /marketer/payment-accounts */
    public function index(Request $request): JsonResponse
    {
        return $this->ok('Payment accounts', $this->accountsOf($request->user()->id));
    }

    /** POST /marketer/payment-accounts */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'method' => ['required', 'in:' . implode(',', array_keys(self::METHODS))],
                'account_no' => ['required', 'string', 'max:64'],
                'acc_name' => ['nullable', 'string', 'max:191'],
                'bank_name' => ['required_if:method,bank_transfer', 'nullable', 'string', 'max:191'],
                'branch' => ['nullable', 'string', 'max:191'],
                'route' => ['nullable', 'string', 'max:64'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'failed', 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        $accountNo = trim($data['account_no']);
        if ($data['method'] !== 'bank_transfer') {
            // Mobile wallets: an 11-digit BD mobile number (Bangla digits and +880 allowed).
            $digits = preg_replace('/\D+/', '', strtr($accountNo, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
            $digits = preg_replace('/^880/', '0', $digits);
            if (!preg_match('/^01[3-9]\d{8}$/', $digits)) {
                return response()->json(['status' => 'failed', 'message' => 'Enter a valid 11-digit mobile number, e.g. 017XXXXXXXX.',
                    'errors' => ['account_no' => ['Enter a valid 11-digit mobile number.']]], 422);
            }
            $accountNo = $digits;
        }

        $userId = $request->user()->id;
        if (UserBankAccount::where('user_id', $userId)->where('type', $data['method'])->where('account_no', $accountNo)->exists()) {
            return response()->json(['status' => 'failed', 'message' => 'This account is already saved.', 'errors' => null], 422);
        }

        $account = UserBankAccount::create([
            'user_id' => $userId,
            'type' => $data['method'],
            'bank_name' => $data['method'] === 'bank_transfer' ? trim($data['bank_name']) : self::METHODS[$data['method']],
            'account_no' => $accountNo,
            'acc_name' => $data['acc_name'] ?? null,
            'branch' => $data['branch'] ?? null,
            'route' => $data['route'] ?? null,
            'is_active' => true,
        ]);

        return $this->ok('Payment account saved', $this->present($account), 201);
    }

    /** DELETE /marketer/payment-accounts/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = UserBankAccount::where('user_id', $request->user()->id)->find($id);
        if (!$account) {
            return response()->json(['status' => 'failed', 'message' => 'Payment account not found', 'errors' => null], 404);
        }
        $account->delete();

        return $this->ok('Payment account removed');
    }

    /** Active accounts of a user, shaped for the panels (also used by the admin pay window). */
    public function accountsOf(int $userId): array
    {
        return UserBankAccount::where('user_id', $userId)->where('is_active', true)->latest('id')->get()
            ->map(fn (UserBankAccount $a) => $this->present($a))->values()->all();
    }

    private function present(UserBankAccount $a): array
    {
        $method = array_key_exists((string) $a->type, self::METHODS) ? $a->type : 'bank_transfer';

        return [
            'id' => $a->id,
            'method' => $method,
            'method_label' => self::METHODS[$method],
            'bank_name' => $a->bank_name,
            'account_no' => $a->account_no,
            'acc_name' => $a->acc_name,
            'branch' => $a->branch,
            'route' => $a->route,
        ];
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }
}
