<?php

namespace App\Http\Controllers;

use App\Models\DeliveryAssignedInfo;
use App\Models\DeliveryCompany;
use App\Models\CarryBeeOrderCreateForm;
use App\Models\VendorCarryBeeCredintial;
use App\Service\CarrybeeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryCompanyController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status'  => 'failed',
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    /**
     * POST /delivery-companies/add
     */
    public function addDeliveryCompany(Request $request)
    {
        try {
            $validated = $request->validate([
                'company_name'        => ['required', 'string', 'max:255'],
                'balance'             => ['nullable', 'numeric', 'min:0'],
                'support_number'      => ['nullable', 'string', 'max:50'],
                'contact_person_name' => ['nullable', 'string', 'max:255'],
                'email'               => ['nullable', 'email', 'max:255'],
                'secondary_number'    => ['nullable', 'string', 'max:50'],
                'is_active'           => ['nullable', 'boolean'],
                'secret_key'          => ['nullable', 'string', 'max:500'],
                'api_key'             => ['nullable', 'string', 'max:500'],
                'client_context'      => ['nullable', 'string', 'max:500'],
            ]);

            $company = DeliveryCompany::create($validated);

            return $this->success('Delivery company added successfully', $company, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/list
     */
    public function getDeliveryCompany(Request $request)
    {
        try {
            $query = DeliveryCompany::query();

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Delivery companies fetched successfully', $query->latest()->get());
            }

            $perPage = (int) $request->get('per_page', 20);
            $companies = $query->latest()->paginate($perPage);

            return $this->success('Delivery companies fetched successfully', $companies);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /delivery-companies/update/{id}
     */
    public function updateDeliveryCompany(Request $request, $id)
    {
        try {
            $company = DeliveryCompany::find($id);

            if (!$company) {
                return $this->failed('Delivery company not found', null, 404);
            }

            $validated = $request->validate([
                'company_name'        => ['nullable', 'string', 'max:255'],
                'balance'             => ['nullable', 'numeric', 'min:0'],
                'support_number'      => ['nullable', 'string', 'max:50'],
                'contact_person_name' => ['nullable', 'string', 'max:255'],
                'email'               => ['nullable', 'email', 'max:255'],
                'secondary_number'    => ['nullable', 'string', 'max:50'],
                'is_active'           => ['nullable', 'boolean'],
                'secret_key'          => ['nullable', 'string', 'max:500'],
                'api_key'             => ['nullable', 'string', 'max:500'],
                'client_context'      => ['nullable', 'string', 'max:500'],
            ]);

            $company->fill($validated);
            $company->save();

            return $this->success('Delivery company updated successfully', $company);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Carrybee third-party API proxy methods
    // -------------------------------------------------------------------------

    /**
     * Resolve a DeliveryCompany and build a CarrybeeService instance.
     * Returns the service or a failed JSON response.
     */
    private function carrybeeService($vendorId)
    {
        $credential = DeliveryCompany::where('is_active', true)->latest()->first();

        if (!$credential) {
            return $this->failed('No active Carrybee credentials found', null, 404);
        }

        if (!$credential->api_key || !$credential->secret_key || !$credential->client_context) {
            return $this->failed('Carrybee credentials are incomplete', null, 422);
        }

        return new CarrybeeService($credential->api_key, $credential->secret_key, $credential->client_context);
    }

    /**
     * GET /delivery-companies/carrybee/cities
     * No credentials required.
     */
    public function carrybeeCities()
    {
        try {
            $result = CarrybeeService::getCities();

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Cities fetched successfully', $result['body']);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/carrybee/{vendorId}/cities/{cityId}/zones
     */
    public function carrybeeZones($vendorId, int $cityId)
    {
        try {
            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service; // error response
            }

            $result = $service->getZones($cityId);

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Zones fetched successfully', $result['body']);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/carrybee/{vendorId}/cities/{cityId}/zones/{zoneId}/areas
     */
    public function carrybeeAreas($vendorId, int $cityId, int $zoneId)
    {
        try {
            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $result = $service->getAreas($cityId, $zoneId);

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Areas fetched successfully', $result['body']);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/carrybee/{vendorId}/area-suggestion?search=
     */
    public function carrybeeAreaSuggestion(Request $request, $vendorId)
    {
        try {
            $request->validate([
                'search' => ['required', 'string', 'max:255'],
            ]);

            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $result = $service->areaSuggestion($request->search);

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Area suggestions fetched successfully', $result['body']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/carrybee/{vendorId}/stores
     */
    public function carrybeeGetStores($vendorId)
    {
        try {
            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $result = $service->getStores();

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Stores fetched successfully', $result['body']);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /delivery-companies/carrybee/{vendorId}/stores
     */
    public function carrybeeCreateStore(Request $request, $vendorId)
    {
        try {
            $validated = $request->validate([
                'name'                            => ['required', 'string', 'max:255'],
                'contact_person_name'             => ['required', 'string', 'max:255'],
                'contact_person_number'           => ['required', 'string', 'max:50'],
                'contact_person_secondary_number' => ['nullable', 'string', 'max:50'],
                'address'                         => ['required', 'string', 'max:500'],
                'city_id'                         => ['required', 'integer'],
                'zone_id'                         => ['required', 'integer'],
                'area_id'                         => ['required', 'integer'],
            ]);

            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $result = $service->createStore($validated);

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Store created successfully', $result['body'], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/carrybee/{vendorId}/orders
     */

    public function carrybeeGetOrders($vendorId)
    {
        try {
            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $result = $service->getOrders();

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Orders fetched successfully', $result['body']);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /delivery-companies/carrybee/{vendorId}/orders
     */
    public function carrybeeCreateOrder(Request $request, $vendorId)
    {
        try {
            $validated = $request->validate([
                'order_id'                    => ['required', 'integer', 'exists:orders,id'],
                'store_id'                    => ['required'],
                'merchant_order_id'           => ['required', 'string', 'max:255'],
                'delivery_type'               => ['required', 'integer'],
                'product_type'                => ['required', 'integer'],
                'recipient_phone'             => ['required', 'string', 'max:50'],
                'recipient_secendary_phone'   => ['nullable', 'string', 'max:50'],
                'recipient_name'              => ['required', 'string', 'max:255'],
                'recipient_address'           => ['required', 'string', 'max:500'],
                'city_id'                     => ['required', 'integer'],
                'zone_id'                     => ['required', 'integer'],
                'area_id'                     => ['required', 'integer'],
                'special_instruction'         => ['nullable', 'string'],
                'product_description'         => ['nullable', 'string'],
                'item_weight'                 => ['required', 'numeric', 'min:0'],
                'item_quantity'               => ['required', 'integer', 'min:1'],
                'collectable_amount'          => ['required', 'numeric', 'min:0'],
                'is_closed_box'               => ['nullable', 'boolean'],
                'is_exchange'                 => ['nullable', 'boolean'],
                // Own fields
                'own_vendor_id'               => ['nullable', 'integer', 'exists:users,id'],
                'own_created_by'              => ['nullable', 'integer', 'exists:users,id'],
                'own_admin_status'            => ['nullable', 'string', 'max:100'],
                'own_is_vendor_ready'         => ['nullable', 'boolean'],
                'own_note'                    => ['nullable', 'string'],
            ]);

            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $orderId = $validated['order_id'];
            unset($validated['order_id']);

            if (DeliveryAssignedInfo::where('order_id', $orderId)->exists()) {
                return $this->failed('A delivery order has already been created for this order', null, 409);
            }

            // Save full form data as a draft record
            CarryBeeOrderCreateForm::updateOrCreate(
                ['order_id' => $orderId],
                array_merge(['order_id' => $orderId], $validated)
            );

            // Strip own_* fields before forwarding to Carrybee API
            $carrybeePayload = array_filter(
                $validated,
                fn($key) => !str_starts_with($key, 'own_'),
                ARRAY_FILTER_USE_KEY
            );

            $result = $service->createOrder($carrybeePayload);

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            $order = $result['body']['data']['order'] ?? null;

            if ($order) {
                DeliveryAssignedInfo::updateOrCreate(
                    ['order_id' => $orderId],
                    [
                        'consignment_id'     => $order['consignment_id'],
                        'merchant_order_id'  => $order['merchant_order_id'] ?? null,
                        'delivery_company_id'  => $vendorId,
                        'recipient_name'     => $order['recipient_name'],
                        'recipient_phone'    => $order['recipient_phone'],
                        'recipient_address'  => $order['recipient_address'],
                        'collectable_amount' => $order['collectable_amount'] ?? 0,
                        'delivery_fee'       => $order['delivery_fee'] ?? 0,
                        'total_fee'          => $order['total_fee'] ?? 0,
                        'transfer_status_id' => $order['transfer_status_id'] ?? 1,
                    ]
                );
            }

            return $this->success('Order created successfully', $result['body'], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /delivery-companies/carrybee/{vendorId}/orders/{consignmentId}/cancel
     */
    public function carrybeeCancelOrder(Request $request, $vendorId, string $consignmentId)
    {
        try {
            $validated = $request->validate([
                'cancellation_reason' => ['required', 'string', 'max:500'],
            ]);

            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $result = $service->cancelOrder($consignmentId, $validated['cancellation_reason']);

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Order cancelled successfully', $result['body']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/carrybee/{vendorId}/orders/{consignmentId}/details
     */
    public function carrybeeOrderDetails($vendorId, string $consignmentId)
    {
        try {
            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $result = $service->getOrderDetails($consignmentId);

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Order details fetched successfully', $result['body']);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /delivery-companies/carrybee/{vendorId}/address-details
     * Body: { "query": "Baridhara Jame Masjid, baridhara, Dhaka" }
     * Returns city_id and zone_id.
     */
    public function carrybeeAddressDetails(Request $request, $vendorId)
    {
        try {
            $request->validate([
                'query' => ['required', 'string', 'max:500'],
            ]);

            $service = $this->carrybeeService($vendorId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $result = $service->getAddressDetails($request->query('query') ?? $request->input('query'));

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            return $this->success('Address details', $result['body']['data'] ?? $result['body']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function getAssinedDeliveryOrderList($companyId)
    {
        try {
           $orders = DeliveryAssignedInfo::where('delivery_company_id', $companyId)->get();

            return $this->success('Assingned Delivery order fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // CarryBee Order Draft (CarryBeeOrderCreateForm)
    // -------------------------------------------------------------------------

    /**
     * POST /delivery-companies/order-drafts
     * Save or update a draft without submitting to Carrybee.
     */
    public function orderDraftSave(Request $request)
    {
        try {
            $validated = $request->validate([
                'order_id'                    => ['required', 'integer', 'exists:orders,id'],
                'store_id'                    => ['nullable'],
                'merchant_order_id'           => ['nullable', 'string', 'max:255'],
                'delivery_type'               => ['nullable', 'integer'],
                'product_type'                => ['nullable', 'integer'],
                'recipient_phone'             => ['nullable', 'string', 'max:50'],
                'recipient_secendary_phone'   => ['nullable', 'string', 'max:50'],
                'recipient_name'              => ['nullable', 'string', 'max:255'],
                'recipient_address'           => ['nullable', 'string', 'max:500'],
                'city_id'                     => ['nullable', 'integer'],
                'zone_id'                     => ['nullable', 'integer'],
                'area_id'                     => ['nullable', 'integer'],
                'special_instruction'         => ['nullable', 'string'],
                'product_description'         => ['nullable', 'string'],
                'item_weight'                 => ['nullable', 'numeric', 'min:0'],
                'item_quantity'               => ['nullable', 'integer', 'min:1'],
                'collectable_amount'          => ['nullable', 'numeric', 'min:0'],
                'is_closed_box'               => ['nullable', 'boolean'],
                'is_exchange'                 => ['nullable', 'boolean'],
                'own_vendor_id'               => ['nullable', 'integer', 'exists:users,id'],
                'own_created_by'              => ['nullable', 'integer', 'exists:users,id'],
                'own_admin_status'            => ['nullable', 'string', 'max:100'],
                'own_is_vendor_ready'         => ['nullable', 'boolean'],
                'own_note'                    => ['nullable', 'string'],
            ]);

            $draft = CarryBeeOrderCreateForm::updateOrCreate(
                ['order_id' => $validated['order_id']],
                $validated
            );

            return $this->success('Order draft saved successfully', $draft->load('order', 'vendor', 'createdBy'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/order-drafts
     */
    public function orderDraftList(Request $request)
    {
        try {
            $query = CarryBeeOrderCreateForm::with('order', 'vendor', 'createdBy');

            if ($request->filled('own_vendor_id')) {
                $query->where('own_vendor_id', $request->own_vendor_id);
            }
            if ($request->filled('own_admin_status')) {
                $query->where('own_admin_status', $request->own_admin_status);
            }
            if ($request->filled('own_is_vendor_ready')) {
                $query->where('own_is_vendor_ready', filter_var($request->own_is_vendor_ready, FILTER_VALIDATE_BOOLEAN));
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Order drafts fetched successfully', $query->latest()->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /delivery-companies/order-drafts/{id}
     */
    public function orderDraftDetails($id)
    {
        try {
            $draft = CarryBeeOrderCreateForm::with('order', 'vendor', 'createdBy')->find($id);

            if (!$draft) {
                return $this->failed('Order draft not found', null, 404);
            }

            return $this->success('Order draft fetched successfully', $draft);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /delivery-companies/order-drafts/{id}
     */
    public function orderDraftUpdate(Request $request, $id)
    {
        try {
            $draft = CarryBeeOrderCreateForm::find($id);

            if (!$draft) {
                return $this->failed('Order draft not found', null, 404);
            }

            $validated = $request->validate([
                'store_id'                    => ['sometimes', 'nullable'],
                'merchant_order_id'           => ['sometimes', 'nullable', 'string', 'max:255'],
                'delivery_type'               => ['sometimes', 'nullable', 'integer'],
                'product_type'                => ['sometimes', 'nullable', 'integer'],
                'recipient_phone'             => ['sometimes', 'nullable', 'string', 'max:50'],
                'recipient_secendary_phone'   => ['sometimes', 'nullable', 'string', 'max:50'],
                'recipient_name'              => ['sometimes', 'nullable', 'string', 'max:255'],
                'recipient_address'           => ['sometimes', 'nullable', 'string', 'max:500'],
                'city_id'                     => ['sometimes', 'nullable', 'integer'],
                'zone_id'                     => ['sometimes', 'nullable', 'integer'],
                'area_id'                     => ['sometimes', 'nullable', 'integer'],
                'special_instruction'         => ['sometimes', 'nullable', 'string'],
                'product_description'         => ['sometimes', 'nullable', 'string'],
                'item_weight'                 => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'item_quantity'               => ['sometimes', 'nullable', 'integer', 'min:1'],
                'collectable_amount'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'is_closed_box'               => ['sometimes', 'nullable', 'boolean'],
                'is_exchange'                 => ['sometimes', 'nullable', 'boolean'],
                'own_vendor_id'               => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
                'own_created_by'              => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
                'own_admin_status'            => ['sometimes', 'nullable', 'string', 'max:100'],
                'own_is_vendor_ready'         => ['sometimes', 'nullable', 'boolean'],
                'own_note'                    => ['sometimes', 'nullable', 'string'],
            ]);

            $draft->fill($validated)->save();

            return $this->success('Order draft updated successfully', $draft->load('order', 'vendor', 'createdBy'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /delivery-companies/order-drafts/{id}
     */
    public function orderDraftDelete($id)
    {
        try {
            $draft = CarryBeeOrderCreateForm::find($id);

            if (!$draft) {
                return $this->failed('Order draft not found', null, 404);
            }

            $draft->delete();

            return $this->success('Order draft deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
