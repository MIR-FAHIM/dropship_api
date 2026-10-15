<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\RegistrationErrorLog;
use App\Models\ResellerTransaction;
use App\Jobs\SendWhatsAppActivationJob;
use App\Service\ApiTokenService;
use App\Service\MuthobartaSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function maskSensitive(array $payload): array
    {
        unset($payload['password'], $payload['password_confirmation']);
        return $payload;
    }

    private function resolveUserForRegistrationLog(Request $request): ?User
    {
        $email = $request->input('email');

        if (!empty($email)) {
            return User::where('email', $email)->first();
        }

        return null;
    }

    private function storeRegistrationError(Request $request, string $message, ?User $user = null, string $level = 'error', ?\Throwable $exception = null): void
    {
        try {
            $logMessage = $message;
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                $logMessage .= ' | Errors: ' . json_encode($exception->errors(), JSON_UNESCAPED_UNICODE);
            } elseif ($exception) {
                $logMessage .= ' | ' . $exception->getMessage();
            }

            RegistrationErrorLog::create([
                'user_id' => $user?->id,
                'level' => $level,
                'message' => $logMessage,
                'file' => $exception?->getFile(),
                'line' => $exception?->getLine(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_data' => json_encode($this->maskSensitive($request->all()), JSON_UNESCAPED_UNICODE),
                'stack_trace' => $exception?->getTraceAsString(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Avoid breaking registration flow if logging fails.
        }
    }

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
     * POST /users/dropshipper-register
     */
    public function dropshipperRegister(Request $request)
    {
        try {
            // Trim inputs to prevent trailing/leading whitespace issues from mobile keyboards
            $request->merge([
                'name'  => is_string($request->name) ? trim($request->name) : $request->name,
                'email' => is_string($request->email) ? strtolower(trim($request->email)) : $request->email,
                'phone' => is_string($request->phone) ? trim($request->phone) : $request->phone,
            ]);

            // Check if user with this email already exists to give a clear, descriptive response
            if ($request->filled('email')) {
                $existingUser = User::where('email', $request->email)->first();
                if ($existingUser) {
                    if ($existingUser->banned == 1) {
                        return $this->failed('An account with this email has already registered and is currently awaiting admin activation.', [
                            'email' => ['An account with this email has already registered and is currently awaiting admin activation.'],
                        ], 409);
                    }
                    return $this->failed('This email is already registered. Please log in to your account.', [
                        'email' => ['The email has already been taken. Please log in.'],
                    ], 409);
                }
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:191'],
                'business_name' => ['nullable', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['nullable', 'string', 'min:4'],
                'phone' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:300'],
                'division_id' => ['nullable'],
                'district_id' => ['nullable'],
                'upazila_id' => ['nullable'],
                'division' => ['nullable', 'string', 'max:255'],
                'district' => ['nullable', 'string', 'max:255'],
                'upazila' => ['nullable', 'string', 'max:255'],
                'state' => ['nullable', 'string', 'max:255'],
                'city' => ['nullable', 'string', 'max:255'],
            ]);

            // Use provided password or auto-generate fallback
            if (!empty($validated['password'])) {
                $rawPassword = $validated['password'];
            } else {
                $digits = !empty($validated['phone']) ? preg_replace('/\D+/', '', $validated['phone']) : '';
                $rawPassword = strlen($digits) >= 6 ? $digits : '123456';
            }

            $divInput = $validated['division_id'] ?? $validated['division'] ?? $validated['state'] ?? null;
            $distInput = $validated['district_id'] ?? $validated['district'] ?? $validated['city'] ?? null;
            $upaInput = $validated['upazila_id'] ?? $validated['upazila'] ?? null;

            $user = User::create([
                'name' => $validated['name'],
                'business_name' => $validated['business_name'] ?? null,
                'email' => $validated['email'],
                'password' => Hash::make($rawPassword),
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'division_id' => is_numeric($divInput) ? (int)$divInput : null,
                'district_id' => is_numeric($distInput) ? (int)$distInput : null,
                'upazila_id' => is_numeric($upaInput) ? (int)$upaInput : null,
                'division' => is_string($divInput) && !is_numeric($divInput) ? $divInput : null,
                'district' => is_string($distInput) && !is_numeric($distInput) ? $distInput : null,
                'upazila' => is_string($upaInput) && !is_numeric($upaInput) ? $upaInput : null,
                'user_type' => 'dropshipper',
                'banned' => 1,
                'whatsapp_consent' => true,
                'whatsapp_consent_source' => 'dropshipper_registration',
                'whatsapp_consented_at' => now(),
            ]);

            $created = ApiTokenService::create($user, ['basic'], 30, 'dropshipper-register-token');

            return $this->success('Dropshipper registered successfully', [
                'token' => $created['plain'],
                'token_type' => 'Bearer',
                'expires_at' => $created['token']->expires_at,
                'user' => $user,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->storeRegistrationError($request, 'Dropshipper registration validation failed', $this->resolveUserForRegistrationLog($request), 'warning', $e);
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            $this->storeRegistrationError($request, 'Dropshipper registration failed with server error', $this->resolveUserForRegistrationLog($request), 'error', $e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /users/create
     */
    public function createUser(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:255'],
                'business_name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:6'],
                'role' => ['nullable', Rule::in(['admin', 'vendor', 'customer'])],

                'mobile' => ['nullable', 'string', 'max:50'],
                'optional_phone' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:1000'],
                'fcm_token' => ['nullable', 'string', 'max:500'],
                'status' => ['nullable', 'string', 'max:50'],

                'division_id' => ['nullable'],
                'district_id' => ['nullable'],
                'upazila_id' => ['nullable'],
                'division' => ['nullable', 'string', 'max:100'],
                'district' => ['nullable', 'string', 'max:100'],
                'upazila' => ['nullable', 'string', 'max:100'],
                'zone' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:100'],
                'lat' => ['nullable', 'numeric'],
                'lon' => ['nullable', 'numeric'],
                'is_banned' => ['nullable', 'boolean'],
            ]);

            $divInput = $validated['division_id'] ?? $validated['division'] ?? null;
            $distInput = $validated['district_id'] ?? $validated['district'] ?? null;
            $upaInput = $validated['upazila_id'] ?? $validated['upazila'] ?? $validated['zone'] ?? null;

            $user = User::create([
                'name' => $validated['name'] ?? null,
                'business_name' => $validated['business_name'] ?? null,
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'] ?? 'customer',

                'mobile' => $validated['mobile'] ?? null,
                'optional_phone' => $validated['optional_phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'fcm_token' => $validated['fcm_token'] ?? null,
                'status' => $validated['status'] ?? null,

                'division_id' => is_numeric($divInput) ? (int)$divInput : null,
                'district_id' => is_numeric($distInput) ? (int)$distInput : null,
                'upazila_id' => is_numeric($upaInput) ? (int)$upaInput : null,
                'division' => is_string($divInput) && !is_numeric($divInput) ? $divInput : null,
                'district' => is_string($distInput) && !is_numeric($distInput) ? $distInput : null,
                'upazila' => is_string($upaInput) && !is_numeric($upaInput) ? $upaInput : null,

                'area' => $validated['area'] ?? null,
                'lat' => $validated['lat'] ?? null,
                'lon' => $validated['lon'] ?? null,

                'banned' => 0,
            ]);

            return $this->success('User created successfully', $user, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->storeRegistrationError($request, 'User create validation failed', $this->resolveUserForRegistrationLog($request), 'warning', $e);
            $errors = $e->errors();
            $firstError = collect($errors)->flatten()->first();
            return $this->failed($firstError ?? 'Validation failed', null, 422);
        } catch (\Throwable $e) {
            $this->storeRegistrationError($request, 'User create failed with server error', $this->resolveUserForRegistrationLog($request), 'error', $e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /users/list?role=customer&is_banned=0&status=active&per_page=20
     */
    public function listUsers(Request $request)
    {
        try {
            $query = User::query();

            if ($request->filled('role')) {
                $query->where('role', $request->role);
            }

            if ($request->filled('is_banned')) {
                $query->where('is_banned', (int) $request->is_banned);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $perPage = (int) ($request->get('per_page', 20));
            $users = $query->latest()->paginate($perPage);

            return $this->success('Users fetched successfully', $users);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function listAdmins(Request $request)
    {
        try {
            $query = User::query();

            if ($request->filled('role')) {
                $query->where('role', $request->role);
            }

            if ($request->filled('is_banned')) {
                $query->where('is_banned', (int) $request->is_banned);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $perPage = (int) ($request->get('per_page', 20));
            $users = $query->where('user_type', 'admin')->latest()->paginate($perPage);

            return $this->success('Admins fetched successfully', $users);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /users/customers?per_page=20
     */
    public function getCustomers(Request $request)
    {
        try {
            $perPage = (int) ($request->get('per_page', 20));
            $customers = User::where('role', 'customer')->latest()->paginate($perPage);

            return $this->success('Customers fetched successfully', $customers);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /users/vendors?per_page=20
     */
    public function getVendors(Request $request)
    {
        try {
            $perPage = (int) ($request->get('per_page', 50));
            $vendors = User::where('user_type', 'seller')->latest()->paginate($perPage);

            return $this->success('Vendors fetched successfully', $vendors);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function getDeliveryMan(Request $request)
    {
        try {
            $perPage = (int) ($request->get('per_page', 20));
            $deliveryMen = User::where('user_type', 'delivery_boy')->latest()->paginate($perPage);

            return $this->success('Delivery men fetched successfully', $deliveryMen);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /users/dropshippers?per_page=20
     * Filters: id, name, email, mobile/phone, search/q, per_page, all
     */
    public function getDropshipperList(Request $request)
    {
        try {
            $perPage = (int) ($request->get('per_page', 20));
            $query = User::where('user_type', 'dropshipper');

            if ($request->filled('id')) {
                $query->where('id', $request->id);
            }

            if ($request->filled('name')) {
                $query->where('name', 'like', '%' . trim($request->name) . '%');
            }

            if ($request->filled('email')) {
                $query->where('email', 'like', '%' . trim($request->email) . '%');
            }

            $mobile = $request->input('mobile') ?? $request->input('phone');
            if (!empty($mobile)) {
                $query->where('phone', 'like', '%' . trim($mobile) . '%');
            }

            $search = $request->input('search') ?? $request->input('q');
            if (!empty($search)) {
                $search = trim($search);
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%");

                    if (is_numeric($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Dropshippers fetched successfully', $query->latest()->get());
            }

            $dropshippers = $query->latest()->paginate($perPage);

            return $this->success('Dropshippers fetched successfully', $dropshippers);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /users/details/{id}
     */
    public function getUserDetails($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->failed('User not found', null, 404);
            }

            return $this->success('User fetched successfully', $user);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function getUserBalance($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->failed('User not found', null, 404);
            }
            $query = ResellerTransaction::where('reseller_id', $user->id)
                ->where('status', 'completed');

            $credit = (float) (clone $query)->where('trx_type', 'credit')->sum('amount');
            $debit = (float) (clone $query)->where('trx_type', 'debit')->sum('amount');

            $balance = $credit - $debit;
            $resellerBalance = [
                'credit' => $credit,
                'debit' => $debit,
                'balance' => $balance,
            ];

            return $this->success('User balance fetched successfully', ['balance' => $resellerBalance]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /users/update/{id}
     */
    public function updateUser(Request $request, $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->failed('User not found', null, 404);
            }

            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:255'],
                'business_name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'password' => ['nullable', 'string', 'min:6'],
                'role' => ['nullable', Rule::in(['admin', 'vendor', 'customer'])],

                'mobile' => ['nullable', 'string', 'max:50'],
                'optional_phone' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:1000'],
                'fcm_token' => ['nullable', 'string', 'max:500'],
                'status' => ['nullable', 'string', 'max:50'],

                'division_id' => ['nullable'],
                'district_id' => ['nullable'],
                'upazila_id' => ['nullable'],
                'division' => ['nullable', 'string', 'max:100'],
                'district' => ['nullable', 'string', 'max:100'],
                'upazila' => ['nullable', 'string', 'max:100'],
                'zone' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:100'],
                'lat' => ['nullable', 'numeric'],
                'lon' => ['nullable', 'numeric'],

                'is_banned' => ['nullable', 'boolean'],
            ]);

            $user->fill([
                'name' => array_key_exists('name', $validated) ? $validated['name'] : $user->name,
                'business_name' => array_key_exists('business_name', $validated) ? $validated['business_name'] : $user->business_name,
                'email' => array_key_exists('email', $validated) ? $validated['email'] : $user->email,
                'role' => array_key_exists('role', $validated) ? $validated['role'] : $user->role,

                'mobile' => array_key_exists('mobile', $validated) ? $validated['mobile'] : $user->mobile,
                'optional_phone' => array_key_exists('optional_phone', $validated) ? $validated['optional_phone'] : $user->optional_phone,
                'address' => array_key_exists('address', $validated) ? $validated['address'] : $user->address,
                'fcm_token' => array_key_exists('fcm_token', $validated) ? $validated['fcm_token'] : $user->fcm_token,
                'status' => array_key_exists('status', $validated) ? $validated['status'] : $user->status,

                'division_id' => array_key_exists('division_id', $validated) ? $validated['division_id'] : $user->division_id,
                'district_id' => array_key_exists('district_id', $validated) ? $validated['district_id'] : $user->district_id,
                'upazila_id' => array_key_exists('upazila_id', $validated) ? $validated['upazila_id'] : $user->upazila_id,
                'division' => array_key_exists('division', $validated) ? $validated['division'] : $user->division,
                'district' => array_key_exists('district', $validated) ? $validated['district'] : $user->district,
                'upazila' => array_key_exists('upazila', $validated) ? $validated['upazila'] : (array_key_exists('zone', $validated) ? $validated['zone'] : $user->upazila),
                'area' => array_key_exists('area', $validated) ? $validated['area'] : $user->area,
                'lat' => array_key_exists('lat', $validated) ? $validated['lat'] : $user->lat,
                'lon' => array_key_exists('lon', $validated) ? $validated['lon'] : $user->lon,

                'banned' => array_key_exists('banned', $validated) ? (int) $validated['banned'] : (array_key_exists('is_banned', $validated) ? ($validated['is_banned'] ? 1 : 0) : $user->banned),
            ]);

            if (!empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            $user->save();

            return $this->success('User updated successfully', $user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    private function normalizePhoneForSms(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (!$digits) {
            return null;
        }

        if (str_starts_with($digits, '880')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '88' . $digits;
        }

        if (strlen($digits) === 10) {
            return '880' . $digits;
        }

        return $digits;
    }

    private function sendDropshipperUnbannedSms(User $user): void
    {
        $rawPhone = $user->phone ?? $user->mobile ?? null;
        $receiver = $this->normalizePhoneForSms($rawPhone);

        if (!$receiver) {
            return;
        }

        // Only send if user is a dropshipper (or customer/unspecified dropshipper registration)
        if ($user->user_type && !in_array($user->user_type, ['dropshipper', 'customer'])) {
            return;
        }

        try {
            $name = trim((string) ($user->name ?? ''));
            $greeting = $name !== '' ? "Hi {$name} ," : "Hi ,";
            $message = "{$greeting} Your ResellerBrain Account is activated , Please feel free to get any support. whatsapp 01941606310. Visit https://resellerbrain.com for more details. Thank you.";

            $smsService = app(MuthobartaSmsService::class);
            $smsService->send($receiver, $message, 'account_activation');
        } catch (\Throwable $e) {
            Log::warning('Failed to send dropshipper unban SMS: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'phone'   => $receiver,
            ]);
        }
    }

    private function dispatchDropshipperWhatsAppActivation(User $user): void
    {
        try {
            if ($user->user_type && !in_array($user->user_type, ['dropshipper', 'customer'])) {
                return;
            }

            SendWhatsAppActivationJob::dispatch($user)->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch WhatsApp activation notification: ' . $e->getMessage(), [
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * PATCH /users/ban/{id}
     */
    public function banToggle($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->failed('User not found', null, 404);
            }

            // Toggle banned status using only 'banned' column
            $user->banned = !$user->banned;
            $user->save();

            $status = $user->banned ? 'banned' : 'unbanned';

            if (!$user->banned) {
                $this->sendDropshipperUnbannedSms($user);
                $this->dispatchDropshipperWhatsAppActivation($user);
            }

            return $this->success("User {$status} successfully", $user);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /users/unban/{id}
     */
    public function unbanUser($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->failed('User not found', null, 404);
            }

            $user->banned = 0;
            $user->save();

            $this->sendDropshipperUnbannedSms($user);
            $this->dispatchDropshipperWhatsAppActivation($user);

            return $this->success('User unbanned successfully', $user);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /users/delete/{id}
     */
    public function deleteUser($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->failed('User not found', null, 404);
            }

            $user->delete();

            return $this->success('User deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /users/number-exists?number=...
     * Returns whether the given phone number exists for any user.
     */
    public function numberExists(Request $request)
    {
        try {
            $validated = $request->validate([
                'number' => ['required', 'string', 'max:100'],
            ]);

            $number = $validated['number'];

            $exists = User::where('mobile', $number)
                ->orWhere('optional_phone', $number)
                ->exists();

            return $this->success('Number lookup completed', ['exists' => (bool) $exists]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
