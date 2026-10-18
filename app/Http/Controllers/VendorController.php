<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\User;
use App\Models\ApiToken;
use App\Service\ApiTokenService;
use Carbon\Carbon;
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
                'business_name' => ['nullable', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:6'],
                'phone' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:300'],
                // Vendor-specific fields
                'shop_name' => ['nullable', 'string', 'max:255'],
                'contact_person' => ['nullable', 'string', 'max:255'],
                'emergency_contact' => ['nullable', 'string', 'max:255'],
                'division_id' => ['nullable'],
                'district_id' => ['nullable'],
                'upazila_id' => ['nullable'],
                'division' => ['nullable', 'string', 'max:255'],
                'district' => ['nullable', 'string', 'max:255'],
                'upazila' => ['nullable', 'string', 'max:255'],
                'zone' => ['nullable', 'string', 'max:255'],
                'state' => ['nullable', 'string', 'max:255'],
                'city' => ['nullable', 'string', 'max:255'],
                'whatsapp' => ['nullable', 'string', 'max:50'],
                'owner_name' => ['nullable', 'string', 'max:255'],
                'shop_type' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'has_own_delivery_account' => ['sometimes', 'boolean'],
                // KYC Document files
                'nid_front' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
                'nid_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
                'trade_license' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
                'irc' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            ]);

            $result = DB::transaction(function () use ($validated, $request) {
                $divInput = $validated['division_id'] ?? $validated['division'] ?? $validated['state'] ?? null;
                $distInput = $validated['district_id'] ?? $validated['district'] ?? $validated['city'] ?? null;
                $upaInput = $validated['upazila_id'] ?? $validated['upazila'] ?? $validated['zone'] ?? null;

                $shopName = !empty($validated['shop_name'])
                    ? $validated['shop_name']
                    : ($validated['owner_name'] ?? $validated['name']);

                $user = User::create([
                    'name' => $validated['name'],
                    'business_name' => $validated['business_name'] ?? $shopName,
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'phone' => $validated['phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'division_id' => is_numeric($divInput) ? (int)$divInput : null,
                    'district_id' => is_numeric($distInput) ? (int)$distInput : null,
                    'upazila_id' => is_numeric($upaInput) ? (int)$upaInput : null,
                    'division' => is_string($divInput) && !is_numeric($divInput) ? $divInput : null,
                    'district' => is_string($distInput) && !is_numeric($distInput) ? $distInput : null,
                    'upazila' => is_string($upaInput) && !is_numeric($upaInput) ? $upaInput : null,
                    'user_type' => 'vendor',
                ]);

                $vendor = Vendor::create([
                    'user_id' => $user->id,
                    'shop_name' => $shopName,
                    'contact_person' => $validated['contact_person'] ?? null,
                    'emergency_contact' => $validated['emergency_contact'] ?? null,
                    'division' => $validated['division'] ?? $validated['state'] ?? null,
                    'district' => $validated['district'] ?? $validated['city'] ?? null,
                    'zone' => $validated['upazila'] ?? $validated['zone'] ?? null,
                    'whatsapp' => $validated['whatsapp'] ?? null,
                    'owner_name' => $validated['owner_name'] ?? $validated['name'],
                    'shop_type' => $validated['shop_type'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'has_own_delivery_account' => isset($validated['has_own_delivery_account']) ? (bool)$validated['has_own_delivery_account'] : false,
                ]);

                // Store KYC documents if uploaded
                $docTypes = [
                    'nid_front' => 'NID Front Side',
                    'nid_back' => 'NID Back Side',
                    'trade_license' => 'Trade License',
                    'irc' => 'Import Registration Certificate - IRC',
                ];

                foreach ($docTypes as $docKey => $docLabel) {
                    if ($request->hasFile($docKey)) {
                        $filePath = $request->file($docKey)->store("kyc-documents/{$user->id}", 'public');
                        \App\Models\DocumentKyc::create([
                            'user_id' => $user->id,
                            'document_name' => $docLabel,
                            'type' => $docKey,
                            'status' => 'submitted',
                            'document_file_path' => $filePath,
                        ]);
                    }
                }

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
     * Query params:
     * - is_active (boolean, optional): 1/0 or true/false
     * - status (string, optional): 'active' or 'inactive'
     */
    public function getVendorList(Request $request)
    {
        try {
            $query = Vendor::with('user', 'carryBeeInfo', 'district')
                ->withCount(['products as total_products'])
                ->orderBy('id', 'desc');

            // Resolve authenticated user if token is present
            $authUser = $request->attributes->get('api_user') ?? $request->user();
            if (!$authUser && $plainToken = $request->bearerToken()) {
                $tokenHash = hash('sha256', $plainToken);
                $apiToken = ApiToken::with('user')->where('token_hash', $tokenHash)->first();
                if ($apiToken && $apiToken->isValid()) {
                    $authUser = $apiToken->user;
                }
            }

            $isAdmin = $authUser && in_array($authUser->user_type ?? $authUser->role, ['admin', 'superadmin']);

            // 1. Explicit query parameter filtering (?is_active=1 or ?is_active=0)
            if ($request->has('is_active')) {
                $isActive = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive !== null) {
                    $query->where('is_active', $isActive);
                }
            } elseif ($request->has('status')) {
                if ($request->query('status') === 'active') {
                    $query->where('is_active', true);
                } elseif ($request->query('status') === 'inactive') {
                    $query->where('is_active', false);
                }
            } elseif (!$isAdmin) {
                // For public / resellers / non-admins, default to active vendors only
                $query->where('is_active', true);
            }

            $vendors = $query->get();

            return $this->success('Vendor list fetched', $vendors);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /reseller/stores or GET /vendors/stores
     * Dedicated stores API for resellers:
     * - Only active vendors (is_active = true)
     * - Active user account (banned = 0)
     * - Only vendors with more than 1 product (> 1)
     * - Returns vendortype, logo, banner, product count, etc.
     */
    public function getResellerStores(Request $request)
    {
        try {
            $productCondition = function ($q) {
                $q->where(function ($sub) {
                    $sub->where('published', 1)->orWhereNull('published');
                });
            };

            $query = Vendor::with(['user', 'district', 'shop.logo', 'shop.banner'])
                ->withCount(['products as total_products' => $productCondition])
                ->where('is_active', true)
                ->whereHas('user', function ($q) {
                    $q->where('banned', 0);
                })
                ->whereHas('products', $productCondition, '>', 1);

            // Optional keyword search
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('shop_name', 'like', "%{$search}%")
                        ->orWhere('owner_name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('shop_type', 'like', "%{$search}%")
                        ->orWhere('zone', 'like', "%{$search}%");
                });
            }

            // Optional vendortype / shop_type filter
            if ($request->filled('vendortype')) {
                $query->where('shop_type', $request->vendortype);
            } elseif ($request->filled('shop_type')) {
                $query->where('shop_type', $request->shop_type);
            }

            // Optional district filter
            if ($request->filled('district')) {
                $district = trim($request->district);
                $query->where(function ($q) use ($district) {
                    $q->where('district', $district)
                        ->orWhereHas('district', function ($qd) use ($district) {
                            $qd->where('name', 'like', "%{$district}%");
                        });
                });
            }

            // Sorting
            $sort = $request->get('sort', 'products');
            if ($sort === 'newest' || $sort === 'latest') {
                $query->orderByDesc('id');
            } elseif ($sort === 'name') {
                $query->orderBy('shop_name', 'asc');
            } else {
                $query->orderByDesc('total_products')->orderByDesc('id');
            }

            $transformStore = function ($vendor) {
                $districtName = null;
                if ($vendor->relationLoaded('district') && $vendor->district instanceof \App\Models\District) {
                    $districtName = $vendor->district->name;
                } elseif (is_string($vendor->getRawOriginal('district'))) {
                    $districtName = $vendor->getRawOriginal('district');
                } elseif ($vendor->user && !empty($vendor->user->city)) {
                    $districtName = $vendor->user->city;
                }

                return [
                    'id'               => $vendor->id,
                    'code'             => 's' . $vendor->id,
                    'shop_name'        => $vendor->shop_name ?: ($vendor->owner_name ?: 'Store ' . $vendor->id),
                    'name'             => $vendor->shop_name ?: ($vendor->owner_name ?: 'Store ' . $vendor->id),
                    'vendortype'       => $vendor->vendortype,
                    'shop_type'        => $vendor->shop_type ?: $vendor->vendortype,
                    'logo'             => $vendor->logo_url,
                    'banner'           => $vendor->banner_url,
                    'is_active'        => (bool) $vendor->is_active,
                    'total_products'   => (int) ($vendor->total_products ?? 0),
                    'products_count'   => (int) ($vendor->total_products ?? 0),
                    'owner_name'       => $vendor->owner_name,
                    'contact_person'   => $vendor->contact_person,
                    'phone'            => $vendor->user?->phone,
                    'whatsapp'         => $vendor->whatsapp,
                    'district'         => $districtName ?: 'N/A',
                    'zone'             => $vendor->zone ?: 'N/A',
                    'description'      => $vendor->description,
                    'rating'           => 4.9,
                    'fulfilment_rate'  => '98%',
                    'avg_dispatch_time'=> '24h',
                    'created_at'       => $vendor->created_at?->toIso8601String(),
                ];
            };

            if ($request->has('page') || $request->has('per_page')) {
                $perPage = (int) $request->get('per_page', 15);
                $paginated = $query->paginate($perPage);
                $paginated->getCollection()->transform($transformStore);
                return $this->success('Stores fetched successfully', $paginated);
            }

            $stores = $query->get()->map($transformStore);

            return $this->success('Stores fetched successfully', $stores);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    /**
     * GET /vendors/vendor/id/{id}
     */
    public function getVendorId($id)
    {
        try {
            $vendor = Vendor::where('user_id', $id)->first();

            if (!$vendor) {
                return $this->failed('Vendor not found', null, 404);
            }

            return $this->success('Vendor fetched successfully', ['vendor_id' => $vendor->id]);
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
            $vendor = Vendor::with('user', 'division', 'district')->find($id);

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
          
                $user->update(['banned' => $newActive ? 0 : 1]);
            

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
                'has_own_delivery_account' => ['sometimes', 'boolean'],
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
                foreach (['shop_name', 'contact_person', 'emergency_contact', 'zone', 'whatsapp', 'owner_name', 'shop_type', 'description', 'has_own_delivery_account'] as $field) {
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

    /**
     * GET /vendors/dashboard/report/{vendor_id}
     */
    public function getVendorDashboardReport($vendorId)
    {
        try {
            $vendor = Vendor::with('user')->find($vendorId);

            if (!$vendor) {
                return $this->failed('Vendor not found', null, 404);
            }

            // --- Products ---
            $totalProducts = Product::where('vendor_id', $vendorId)->count();
            $publishedProducts = Product::where('vendor_id', $vendorId)->where('published', 1)->count();
            $approvedProducts = Product::where('vendor_id', $vendorId)->where('approved', 1)->count();
            $lowStockProducts = Product::where('vendor_id', $vendorId)
                ->whereColumn('current_stock', '<=', 'low_stock_quantity')
                ->count();
            $outOfStockProducts = Product::where('vendor_id', $vendorId)
                ->where('current_stock', '<=', 0)
                ->count();

            // Product IDs for this vendor (used in order queries)
            $productIds = Product::where('vendor_id', $vendorId)->pluck('id');

            // --- Orders ---
            // Distinct order IDs that contain at least one vendor product
            $vendorOrderIds = OrderItem::whereIn('product_id', $productIds)
                ->distinct()
                ->pluck('order_id');

            $totalOrders = $vendorOrderIds->count();

            // Orders grouped by status name
            $ordersByStatus = DB::table('orders')
                ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                ->leftJoin('order_statuses', 'orders.status', '=', 'order_statuses.id')
                ->whereIn('order_items.product_id', $productIds)
                ->select(
                    DB::raw('COALESCE(order_statuses.name, orders.status, "Unknown") as status'),
                    DB::raw('COUNT(DISTINCT orders.id) as total')
                )
                ->groupBy(DB::raw('COALESCE(order_statuses.name, orders.status, "Unknown")'))
                ->get()
                ->map(function ($item) {
                    return [
                        'status'          => $item->status,
                        'delivery_status' => strtolower($item->status),
                        'total'           => (int) $item->total,
                        'count'           => (int) $item->total,
                    ];
                });

            // --- Sales (Completed vs Pending, Cancelled Eliminated) ---
            $now = Carbon::now();

            $completedStatuses = ['delivered', 'completed', 'paid', 'complete'];
            $cancelledKeywords = ['cancel', 'return', 'fail', 'reject'];

            $baseQuery = function () use ($productIds) {
                return DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->leftJoin('order_statuses', 'orders.status', '=', 'order_statuses.id')
                    ->whereIn('order_items.product_id', $productIds);
            };

            $applyCompletedFilter = function ($q) use ($completedStatuses) {
                $q->where(function ($sub) use ($completedStatuses) {
                    $sub->whereIn(DB::raw('LOWER(TRIM(COALESCE(order_statuses.name, orders.status, "")))'), $completedStatuses)
                        ->orWhereIn(DB::raw('LOWER(TRIM(COALESCE(order_statuses.type, "")))'), $completedStatuses);
                });
            };

            $applyPendingFilter = function ($q) use ($completedStatuses, $cancelledKeywords) {
                $q->where(function ($sub) use ($completedStatuses, $cancelledKeywords) {
                    // Exclude completed
                    $sub->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(order_statuses.name, orders.status, "")))'), $completedStatuses)
                        ->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(order_statuses.type, "")))'), $completedStatuses);

                    // Exclude cancelled / returned / failed
                    foreach ($cancelledKeywords as $keyword) {
                        $sub->where(DB::raw('LOWER(COALESCE(order_statuses.name, orders.status, ""))'), 'NOT LIKE', "%{$keyword}%")
                            ->where(DB::raw('LOWER(COALESCE(order_statuses.type, ""))'), 'NOT LIKE', "%{$keyword}%");
                    }
                });
            };

            $sumCompleted = function ($extraConditions = null) use ($baseQuery, $applyCompletedFilter) {
                $q = $baseQuery();
                $applyCompletedFilter($q);
                if ($extraConditions) {
                    $extraConditions($q);
                }
                return (float) ($q->sum('order_items.line_total') ?? 0);
            };

            $sumPending = function ($extraConditions = null) use ($baseQuery, $applyPendingFilter) {
                $q = $baseQuery();
                $applyPendingFilter($q);
                if ($extraConditions) {
                    $extraConditions($q);
                }
                return (float) ($q->sum('order_items.line_total') ?? 0);
            };

            // Totals
            $totalCompletedSell = $sumCompleted();
            $totalPendingSell = $sumPending();

            // This month
            $thisMonthCompleted = $sumCompleted(function ($q) use ($now) {
                $q->whereYear('orders.created_at', $now->year)
                  ->whereMonth('orders.created_at', $now->month);
            });
            $thisMonthPending = $sumPending(function ($q) use ($now) {
                $q->whereYear('orders.created_at', $now->year)
                  ->whereMonth('orders.created_at', $now->month);
            });

            // Last month
            $lastMonthDate = $now->copy()->subMonth();
            $lastMonthCompleted = $sumCompleted(function ($q) use ($lastMonthDate) {
                $q->whereYear('orders.created_at', $lastMonthDate->year)
                  ->whereMonth('orders.created_at', $lastMonthDate->month);
            });

            // Today
            $todayCompleted = $sumCompleted(function ($q) use ($now) {
                $q->whereDate('orders.created_at', $now->toDateString());
            });
            $todayPending = $sumPending(function ($q) use ($now) {
                $q->whereDate('orders.created_at', $now->toDateString());
            });

            // Yesterday
            $yesterdayDate = $now->copy()->subDay()->toDateString();
            $yesterdayCompleted = $sumCompleted(function ($q) use ($yesterdayDate) {
                $q->whereDate('orders.created_at', $yesterdayDate);
            });

            // Last 7 days
            $last7DaysDate = $now->copy()->subDays(6)->toDateString();
            $last7DaysCompleted = $sumCompleted(function ($q) use ($last7DaysDate) {
                $q->whereDate('orders.created_at', '>=', $last7DaysDate);
            });
            $last7DaysPending = $sumPending(function ($q) use ($last7DaysDate) {
                $q->whereDate('orders.created_at', '>=', $last7DaysDate);
            });

            // Daily breakdown for last 7 days (sell shows completed sales)
            $dailyBreakdown = [];
            for ($i = 0; $i < 7; $i++) {
                $date = $now->copy()->subDays($i)->toDateString();
                $cDay = $sumCompleted(fn($q) => $q->whereDate('orders.created_at', $date));
                $pDay = $sumPending(fn($q) => $q->whereDate('orders.created_at', $date));
                $dailyBreakdown[] = [
                    'date'      => $date,
                    'sell'      => $cDay,
                    'completed' => $cDay,
                    'pending'   => $pDay,
                ];
            }

            // Monthly breakdown for last 6 months (sell shows completed sales)
            $monthlyBreakdown = [];
            for ($i = 0; $i < 6; $i++) {
                $mDate = $now->copy()->subMonths($i);
                $cMonth = $sumCompleted(fn($q) => $q->whereYear('orders.created_at', $mDate->year)->whereMonth('orders.created_at', $mDate->month));
                $pMonth = $sumPending(fn($q) => $q->whereYear('orders.created_at', $mDate->year)->whereMonth('orders.created_at', $mDate->month));
                $monthlyBreakdown[] = [
                    'month'     => $mDate->format('Y-m'),
                    'sell'      => $cMonth,
                    'completed' => $cMonth,
                    'pending'   => $pMonth,
                ];
            }

            // --- Top 5 selling products ---
            $topProducts = Product::where('vendor_id', $vendorId)
                ->orderBy('num_of_sale', 'desc')
                ->take(5)
                ->get(['id', 'name', 'num_of_sale', 'current_stock', 'unit_price', 'admin_price']);

            return $this->success('Vendor dashboard report fetched', [
                'vendor' => $vendor,
                'products' => [
                    'total' => $totalProducts,
                    'published' => $publishedProducts,
                    'approved' => $approvedProducts,
                    'low_stock' => $lowStockProducts,
                    'out_of_stock' => $outOfStockProducts,
                ],
                'orders' => [
                    'total' => $totalOrders,
                    'by_status' => $ordersByStatus,
                ],
                'sales' => [
                    'total' => $totalCompletedSell,
                    'completed_sales' => $totalCompletedSell,
                    'pending_sales' => $totalPendingSell,
                    'pending' => $totalPendingSell,
                    'this_month' => $thisMonthCompleted,
                    'last_month' => $lastMonthCompleted,
                    'today' => $todayCompleted,
                    'yesterday' => $yesterdayCompleted,
                    'last_7_days' => $last7DaysCompleted,
                    'pending_this_month' => $thisMonthPending,
                    'pending_today' => $todayPending,
                    'pending_last_7_days' => $last7DaysPending,
                    'daily_breakdown' => $dailyBreakdown,
                    'monthly_breakdown' => $monthlyBreakdown,
                ],
                'top_selling_products' => $topProducts,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
