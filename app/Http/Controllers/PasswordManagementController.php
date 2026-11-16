<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\PasswordChangeLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PasswordManagementController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }

    /**
     * POST /users/change-password
     * Logged-in user changes their own password with the current one.
     * Other sessions are signed out; the current one keeps working.
     */
    public function changeOwnPassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:5', 'max:191', 'confirmed', 'different:current_password'],
                'password_confirmation' => ['required', 'string'],
            ]);

            $user = $request->attributes->get('api_user') ?? $request->user();
            if (!$user) {
                return $this->failed('Unauthenticated', null, 401);
            }

            // 5 wrong current-password attempts per 15 minutes per user.
            $rateKey = 'change_password_' . $user->id;
            if (RateLimiter::tooManyAttempts($rateKey, 5)) {
                $seconds = RateLimiter::availableIn($rateKey);
                return $this->failed("Too many attempts. Please try again in {$seconds} seconds.", null, 429);
            }

            if (!Hash::check($validated['current_password'], (string) $user->password)) {
                RateLimiter::hit($rateKey, 900);
                return $this->failed('Validation failed', ['current_password' => ['Current password is incorrect.']], 422);
            }
            RateLimiter::clear($rateKey);

            $currentToken = $request->attributes->get('api_token');

            DB::transaction(function () use ($user, $validated, $currentToken, $request) {
                $user->password = Hash::make($validated['password']);
                $user->save();

                ApiToken::where('user_id', $user->id)
                    ->when($currentToken, fn ($q) => $q->where('id', '!=', $currentToken->id))
                    ->update(['is_revoked' => true]);

                PasswordChangeLog::record($user, $user, PasswordChangeLog::TYPE_SELF_CHANGE, $request);
            });

            return $this->success('Password changed successfully');
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /users/{id}/reset-password  (admin:accounts)
     * Admin sets a new password for a dropshipper. All of their sessions are signed out.
     */
    public function adminResetPassword(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'password' => ['required', 'string', 'min:5', 'max:191', 'confirmed'],
                'password_confirmation' => ['required', 'string'],
                'note' => ['nullable', 'string', 'max:250'],
            ]);

            $user = User::where('user_type', 'dropshipper')->find($id);
            if (!$user) {
                return $this->failed('Dropshipper not found', null, 404);
            }

            $actor = $request->attributes->get('api_user') ?? $request->user();

            DB::transaction(function () use ($user, $actor, $validated, $request) {
                $user->password = Hash::make($validated['password']);
                $user->save();

                ApiToken::where('user_id', $user->id)->update(['is_revoked' => true]);

                PasswordChangeLog::record($user, $actor, PasswordChangeLog::TYPE_ADMIN_RESET, $request, $validated['note'] ?? null);
            });

            return $this->success('Password reset successfully. The dropshipper has been signed out of all devices.');
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /users/password-logs  (admin:accounts)
     * Filters: user_id, change_type, search (user name / phone / email), date_from, date_to (Y-m-d, Bangladesh time).
     */
    public function logs(Request $request)
    {
        try {
            $request->validate([
                'user_id' => ['nullable', 'integer'],
                'change_type' => ['nullable', Rule::in(array_merge(PasswordChangeLog::TYPES, ['all']))],
                'search' => ['nullable', 'string', 'max:100'],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $query = PasswordChangeLog::with([
                'user:id,name,phone,email,user_type',
                'changedBy:id,name,phone,email,user_type,role',
            ]);

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->get('user_id'));
            }

            if ($request->filled('change_type') && $request->get('change_type') !== 'all') {
                $query->where('change_type', $request->get('change_type'));
            }

            $search = trim((string) $request->get('search', ''));
            if ($search !== '') {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            foreach (['date_from' => 'startOfDay', 'date_to' => 'endOfDay'] as $param => $edge) {
                if ($request->filled($param)) {
                    $boundary = \Carbon\Carbon::createFromFormat('Y-m-d', $request->get($param), 'Asia/Dhaka')->{$edge}()->utc();
                    $query->where('created_at', $param === 'date_from' ? '>=' : '<=', $boundary);
                }
            }

            $logs = $query->orderByDesc('created_at')->orderByDesc('id')
                ->paginate((int) $request->get('per_page', 20));

            return $this->success('Password change logs fetched successfully', $logs);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
