<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Vendor;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VendorController extends Controller
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
     * POST /vendors/register
     */
    public function vendorRegister(Request $request)
    {
        try {
            $validated = $request->validate([
                // User fields
                'name' => ['required', 'string', 'max:191'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:6'],
                'phone' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:300'],
                // Vendor-specific fields
                'shop_name' => ['required', 'string', 'max:255'],
                'contact_person' => ['nullable', 'string', 'max:255'],
                'emergency_contact' => ['nullable', 'string', 'max:255'],
                'zone' => ['nullable', 'string', 'max:255'],
                'state' => ['nullable', 'string', 'max:255'],
                'city' => ['nullable', 'string', 'max:255'],
                'whatsapp' => ['nullable', 'string', 'max:50'],
                'owner_name' => ['nullable', 'string', 'max:255'],
                'shop_type' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
            ]);

            $result = DB::transaction(function () use ($validated) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'phone' => $validated['phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'state' => $validated['state'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'user_type' => 'vendor',
                ]);

                $vendor = Vendor::create([
                    'user_id' => $user->id,
                    'shop_name' => $validated['shop_name'],
                    'contact_person' => $validated['contact_person'] ?? null,
                    'emergency_contact' => $validated['emergency_contact'] ?? null,
                    'zone' => $validated['zone'] ?? null,
                    'whatsapp' => $validated['whatsapp'] ?? null,
                    'owner_name' => $validated['owner_name'] ?? null,
                    'shop_type' => $validated['shop_type'] ?? null,
                    'description' => $validated['description'] ?? null,
                  
                ]);

                $created = ApiTokenService::create($user, ['basic'], 30, 'vendor-register-token');

                return [
                    'token' => $created['plain'],
                    'token_type' => 'Bearer',
                    'expires_at' => $created['token']->expires_at,
                    'user' => $user,
                    'vendor' => $vendor,
                ];
            });

            return $this->success('Vendor registered successfully', $result, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
          $errors = $e->errors();
$firstError = collect($errors)->flatten()->first();
return $this->failed($firstError ?? 'Validation failed', null, 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /vendors/list
     */
    public function getVendorList()
    {
        try {
            $vendors = Vendor::with('user')->get();

            return $this->success('Vendor list fetched', $vendors);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /vendors/profile/{id}
     */
    public function getVendorProfile($id)
    {
        try {
            $vendor = Vendor::with('user')->find($id);

            if (!$vendor) {
                return $this->failed('Vendor not found', null, 404);
            }

            return $this->success('Vendor profile fetched', $vendor);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /vendors/remove/{id}
     */
    public function removeVendor($id)
    {
        try {
            $vendor = Vendor::find($id);

            if (!$vendor) {
                return $this->failed('Vendor not found', null, 404);
            }

            $vendor->delete();

            return $this->success('Vendor removed successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /vendors/is-active/{id}
     */
    public function isActiveVendor(Request $request, $id)
    {
        try {
            $vendor = Vendor::find($id);

            if (!$vendor) {
                return $this->failed('Vendor not found', null, 404);
            }

            // Validate if is_active is present, otherwise treat as toggle
            $validated = $request->validate([
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $current = (bool) $vendor->is_active;
            $newActive = array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : !$current;

            $vendor->update(['is_active' => $newActive]);

            $user = $vendor->user;
            if ($user) {
                $user->update(['banned' => $newActive ? 0 : 1]);
            }

            $status = $newActive ? 'activated' : 'deactivated';

            return $this->success("Vendor {$status} successfully", $vendor);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /vendors/update/{id}
     */
    public function updateVendor(Request $request, $id)
    {
        try {
            $vendor = Vendor::find($id);

            if (!$vendor) {
                return $this->failed('Vendor not found', null, 404);
            }

            $validated = $request->validate([
                // User fields
                'name' => ['nullable', 'string', 'max:191'],
                'email' => ['nullable', 'email', 'unique:users,email,' . $vendor->user_id],
                'password' => ['nullable', 'string', 'min:6'],
                'phone' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:300'],
                // Vendor fields
                'shop_name' => ['nullable', 'string', 'max:255'],
                'contact_person' => ['nullable', 'string', 'max:255'],
                'emergency_contact' => ['nullable', 'string', 'max:255'],
                'zone' => ['nullable', 'string', 'max:255'],
                'whatsapp' => ['nullable', 'string', 'max:50'],
                'owner_name' => ['nullable', 'string', 'max:255'],
                'shop_type' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
            ]);

            DB::transaction(function () use ($vendor, $validated) {
                // Update user fields
                $userFields = [];
                foreach (['name', 'email', 'phone', 'address'] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $userFields[$field] = $validated[$field];
                    }
                }
                if (!empty($validated['password'])) {
                    $userFields['password'] = Hash::make($validated['password']);
                }
                if (!empty($userFields)) {
                    $vendor->user->update($userFields);
                }

                // Update vendor fields
                $vendorFields = [];
                foreach (['shop_name', 'contact_person', 'emergency_contact', 'zone', 'whatsapp', 'owner_name', 'shop_type', 'description'] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $vendorFields[$field] = $validated[$field];
                    }
                }
                if (!empty($vendorFields)) {
                    $vendor->update($vendorFields);
                }
            });

            return $this->success('Vendor updated successfully', $vendor->load('user'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /vendors/products/{vendor_id}
     */
    public function getVendorProductList($vendorId)
    {
        try {
            $vendor = Vendor::find($vendorId);

            if (!$vendor) {
                return $this->failed('Vendor not found', null, 404);
            }

            $products = Product::with([
                'images.image',
                'vendor',
                'primaryImage',
                'brand',
                'category',
                'subCategory',
                'averageReview',
                'shop',
                'related',
                'productAttributes.attribute',
                'productAttributes.value',
                'productDiscount',
            ])->where('vendor_id', $vendorId)
                ->orderBy('id', 'desc')
                ->paginate(20);

            return $this->success('Vendor products fetched', $products);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
