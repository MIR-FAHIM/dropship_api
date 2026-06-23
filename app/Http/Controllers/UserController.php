<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ResellerTransaction;
use App\Service\ApiTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
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
     * POST /users/dropshipper-register
     */
    public function dropshipperRegister(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:191'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:6'],
                'phone' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:300'],
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'user_type' => 'dropshipper',
            ]);

            $created = ApiTokenService::create($user, ['basic'], 30, 'dropshipper-register-token');

            return $this->success('Dropshipper registered successfully', [
                'token' => $created['plain'],
                'token_type' => 'Bearer',
                'expires_at' => $created['token']->expires_at,
                'user' => $user,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
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
                'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:6'],
                'role' => ['nullable', Rule::in(['admin', 'vendor', 'customer'])],

                'mobile' => ['nullable', 'string', 'max:50'],
                'optional_phone' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:1000'],
                'fcm_token' => ['nullable', 'string', 'max:500'],
                'status' => ['nullable', 'string', 'max:50'],

                'zone' => ['nullable', 'string', 'max:100'],
                'district' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:100'],
                'lat' => ['nullable', 'numeric'],
                'lon' => ['nullable', 'numeric'],
                'is_banned' => ['nullable', 'boolean'],
            ]);

            $user = User::create([
                'name' => $validated['name'] ?? null,
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'] ?? 'customer',

                'mobile' => $validated['mobile'] ?? null,
                'optional_phone' => $validated['optional_phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'fcm_token' => $validated['fcm_token'] ?? null,
                'status' => $validated['status'] ?? null,

                'zone' => $validated['zone'] ?? null,
                'district' => $validated['district'] ?? null,
                'area' => $validated['area'] ?? null,
                'lat' => $validated['lat'] ?? null,
                'lon' => $validated['lon'] ?? null,

                'is_banned' => 0,
            ]);

            return $this->success('User created successfully', $user, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $firstError = collect($errors)->flatten()->first();
            return $this->failed($firstError ?? 'Validation failed', null, 422);
        } catch (\Throwable $e) {
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
     */
    public function getDropshipperList(Request $request)
    {
        try {
            $perPage = (int) ($request->get('per_page', 20));
            $dropshippers = User::where('user_type', 'dropshipper')->latest()->paginate($perPage);

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
                'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'password' => ['nullable', 'string', 'min:6'],
                'role' => ['nullable', Rule::in(['admin', 'vendor', 'customer'])],

                'mobile' => ['nullable', 'string', 'max:50'],
                'optional_phone' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:1000'],
                'fcm_token' => ['nullable', 'string', 'max:500'],
                'status' => ['nullable', 'string', 'max:50'],

                'zone' => ['nullable', 'string', 'max:100'],
                'district' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:100'],
                'lat' => ['nullable', 'numeric'],
                'lon' => ['nullable', 'numeric'],

                'is_banned' => ['nullable', 'boolean'],
            ]);

            $user->fill([
                'name' => array_key_exists('name', $validated) ? $validated['name'] : $user->name,
                'email' => array_key_exists('email', $validated) ? $validated['email'] : $user->email,
                'role' => array_key_exists('role', $validated) ? $validated['role'] : $user->role,

                'mobile' => array_key_exists('mobile', $validated) ? $validated['mobile'] : $user->mobile,
                'optional_phone' => array_key_exists('optional_phone', $validated) ? $validated['optional_phone'] : $user->optional_phone,
                'address' => array_key_exists('address', $validated) ? $validated['address'] : $user->address,
                'fcm_token' => array_key_exists('fcm_token', $validated) ? $validated['fcm_token'] : $user->fcm_token,
                'status' => array_key_exists('status', $validated) ? $validated['status'] : $user->status,

                'zone' => array_key_exists('zone', $validated) ? $validated['zone'] : $user->zone,
                'district' => array_key_exists('district', $validated) ? $validated['district'] : $user->district,
                'area' => array_key_exists('area', $validated) ? $validated['area'] : $user->area,
                'lat' => array_key_exists('lat', $validated) ? $validated['lat'] : $user->lat,
                'lon' => array_key_exists('lon', $validated) ? $validated['lon'] : $user->lon,

                'is_banned' => array_key_exists('is_banned', $validated) ? (bool) $validated['is_banned'] : $user->is_banned,
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

            $user->is_banned = false;
            $user->save();

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
