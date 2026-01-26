<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Shops;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopController extends Controller
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
     * POST /shops/create
     * Create shop for a user (typically vendor)
     */
    public function createShop(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => ['nullable', 'integer', 'exists:users,id'],

                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', 'unique:shops,slug'],
                'description' => ['nullable', 'string'],

                'logo' => ['nullable', 'string', 'max:255'],
                'banner' => ['nullable', 'string', 'max:255'],

                'phone' => ['nullable', 'string', 'max:50'],
                'email' => ['nullable', 'email', 'max:255'],

                'address' => ['nullable', 'string', 'max:1000'],
                'zone' => ['nullable', 'string', 'max:100'],
                'district' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:100'],
                'lat' => ['nullable', 'numeric'],
                'lon' => ['nullable', 'numeric'],

                'status' => ['nullable', 'string', 'max:50'], // pending, active, suspended
            ]);

            // Optional: enforce vendor role if user_id is provided 
            //delivery_boy, customer, seller, admin
            if (!empty($validated['user_id'])) {
                $user = User::find($validated['user_id']);
                if ($user && $user->user_type !== 'seller') {
                    $user->user_type = 'seller';
                    $user->save();
                }
            }

            // Optional: prevent multiple shops for same user (if you want one shop per vendor)
            if (!empty($validated['user_id'])) {
                $exists = Shops::where('user_id', $validated['user_id'])->exists();
                if ($exists) {
                    return $this->failed('This user already has a shop', null, 409);
                }
            }

            $shop = Shops::create([
                'user_id' => $validated['user_id'] ?? null,

                'name' => $validated['name'] ?? null,
                'slug' => $validated['slug'] ?? null,
                'description' => $validated['description'] ?? null,

                'logo' => $validated['logo'] ?? null,
                'banner' => $validated['banner'] ?? null,

                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,

                'address' => $validated['address'] ?? null,
                'zone' => $validated['zone'] ?? null,
                'district' => $validated['district'] ?? null,
                'area' => $validated['area'] ?? null,
                'lat' => $validated['lat'] ?? null,
                'lon' => $validated['lon'] ?? null,

                'status' => $validated['status'] ?? 'pending',
            ]);

            return $this->success('Shop created successfully', $shop, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /shops/list?status=&user_id=&per_page=&all=1
     */
    public function listShops(Request $request)
    {
        try {
            $query = Shops::query();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            $query->latest();

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                $shops = $query->get();
                return $this->success('Shops fetched successfully', $shops);
            }

            $perPage = (int) $request->get('per_page', 20);
            $shops = $query->with('user')->paginate($perPage);

            return $this->success('Shops fetched successfully', $shops);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /shops/details/{id}
     */
    public function getShopDetails($id)
    {
        try {
            $shop = Shops::find($id);

            if (!$shop) {
                return $this->failed('Shop not found', null, 404);
            }

            return $this->success('Shop fetched successfully', $shop);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /shops/products/{id}
     * List products by shop owner (shop.user_id === product.user_id)
     */
    public function getShopProducts(Request $request, $id)
    {
        try {
            $shop = Shops::find($id);

            if (!$shop) {
                return $this->failed('Shop not found', null, 404);
            }

            if (empty($shop->user_id)) {
                return $this->success('Shop has no owner user', []);
            }

            $query = Product::query()
                ->with(['primaryImage', 'images', 'category', 'subCategory', 'brand', 'productDiscount'])
                ->where('user_id', $shop->user_id);

            if ($request->filled('search')) {
                $search = trim($request->search);
                $tokens = preg_split('/\s+/', $search);

                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $token) {
                        $t = "%" . $token . "%";
                        $q->where(function ($qq) use ($t) {
                            $qq->where('name', 'like', $t)
                                ->orWhere('slug', 'like', $t)
                                ->orWhereHas('category', function ($qc) use ($t) {
                                    $qc->where('name', 'like', $t);
                                })
                                ->orWhereHas('brand', function ($qc) use ($t) {
                                    $qc->where('name', 'like', $t);
                                });
                        });
                    }
                });
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                $products = $query->latest()->get();
                return $this->success('Products fetched successfully', $products);
            }

            $perPage = (int) $request->get('per_page', 20);
            $products = $query->latest()->paginate($perPage);

            return $this->success('Products fetched successfully', $products);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /shops/update/{id}
     */
    public function updateShop(Request $request, $id)
    {
        try {
            $shop = Shops::find($id);

            if (!$shop) {
                return $this->failed('Shop not found', null, 404);
            }

            $validated = $request->validate([
                'user_id' => ['nullable', 'integer', 'exists:users,id'],

                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('shops', 'slug')->ignore($shop->id)],
                'description' => ['nullable', 'string'],

                'logo' => ['nullable', 'string', 'max:255'],
                'banner' => ['nullable', 'string', 'max:255'],

                'phone' => ['nullable', 'string', 'max:50'],
                'email' => ['nullable', 'email', 'max:255'],

                'address' => ['nullable', 'string', 'max:1000'],
                'zone' => ['nullable', 'string', 'max:100'],
                'district' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:100'],
                'lat' => ['nullable', 'numeric'],
                'lon' => ['nullable', 'numeric'],

                'status' => ['nullable', 'string', 'max:50'],
            ]);

            // Optional: enforce vendor role if user_id is being updated
            if (array_key_exists('user_id', $validated) && !empty($validated['user_id'])) {
                $user = User::find($validated['user_id']);
                if ($user && $user->role !== 'vendor') {
                    return $this->failed('Only vendor users can own a shop', null, 409);
                }

                // Optional: prevent multiple shops per user
                $anotherShopExists = Shops::where('user_id', $validated['user_id'])
                    ->where('id', '!=', $shop->id)
                    ->exists();

                if ($anotherShopExists) {
                    return $this->failed('This user already has another shop', null, 409);
                }
            }

            $shop->fill($validated);
            $shop->save();

            return $this->success('Shop updated successfully', $shop);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /shops/status/{id}
     */
    public function updateShopStatus(Request $request, $id)
    {
        try {
            $shop = Shops::find($id);

            if (!$shop) {
                return $this->failed('Shop not found', null, 404);
            }

            $validated = $request->validate([
                'status' => ['required', 'string', 'max:50'], // pending, active, suspended, banned
            ]);

            $shop->status = $validated['status'];
            $shop->save();

            return $this->success('Shop status updated successfully', $shop);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /shops/delete/{id}
     */
    public function deleteShop($id)
    {
        try {
            $shop = Shops::find($id);

            if (!$shop) {
                return $this->failed('Shop not found', null, 404);
            }

            $shop->delete();

            return $this->success('Shop deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
