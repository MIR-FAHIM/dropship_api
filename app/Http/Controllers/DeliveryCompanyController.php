<?php

namespace App\Http\Controllers;

use App\Models\DeliveryAssignedInfo;
use App\Models\DeliveryCompany;
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
    private function carrybeeService($companyId)
    {
        $company = DeliveryCompany::find($companyId);

        if (!$company) {
            return $this->failed('Delivery company not found', null, 404);
        }

        if (!$company->api_key || !$company->secret_key || !$company->client_context) {
            return $this->failed('Carrybee credentials are incomplete for this company', null, 422);
        }

        return new CarrybeeService($company->api_key, $company->secret_key, $company->client_context);
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
     * GET /delivery-companies/carrybee/{companyId}/cities/{cityId}/zones
     */
    public function carrybeeZones($companyId, int $cityId)
    {
        try {
            $service = $this->carrybeeService($companyId);

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
     * GET /delivery-companies/carrybee/{companyId}/cities/{cityId}/zones/{zoneId}/areas
     */
    public function carrybeeAreas($companyId, int $cityId, int $zoneId)
    {
        try {
            $service = $this->carrybeeService($companyId);

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
     * GET /delivery-companies/carrybee/{companyId}/area-suggestion?search=
     */
    public function carrybeeAreaSuggestion(Request $request, $companyId)
    {
        try {
            $request->validate([
                'search' => ['required', 'string', 'max:255'],
            ]);

            $service = $this->carrybeeService($companyId);

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
     * GET /delivery-companies/carrybee/{companyId}/stores
     */
    public function carrybeeGetStores($companyId)
    {
        try {
            $service = $this->carrybeeService($companyId);

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
     * POST /delivery-companies/carrybee/{companyId}/stores
     */
    public function carrybeeCreateStore(Request $request, $companyId)
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

            $service = $this->carrybeeService($companyId);

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
     * GET /delivery-companies/carrybee/{companyId}/orders
     */

    public function carrybeeGetOrders($companyId)
    {
        try {
            $service = $this->carrybeeService($companyId);

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
     * POST /delivery-companies/carrybee/{companyId}/orders
     */
    public function carrybeeCreateOrder(Request $request, $companyId)
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
            ]);

            $service = $this->carrybeeService($companyId);

            if (!$service instanceof CarrybeeService) {
                return $service;
            }

            $orderId = $validated['order_id'];
            unset($validated['order_id']);

            $result = $service->createOrder($validated);

            if ($result['status'] >= 400) {
                return $this->failed('Carrybee API error', $result['body'], $result['status']);
            }

            $order = $result['body']['data']['data']['order'] ?? null;

            
                DeliveryAssignedInfo::updateOrCreate(
                    ['order_id' => $orderId],
                    [
                        'consignment_id'     => $order['consignment_id'],
                        'merchant_order_id'  => $order['merchant_order_id'] ?? null,
                        'recipient_name'     => $order['recipient_name'],
                        'recipient_phone'    => $order['recipient_phone'],
                        'recipient_address'  => $order['recipient_address'],
                        'collectable_amount' => $order['collectable_amount'] ?? 0,
                        'delivery_fee'       => $order['delivery_fee'] ?? 0,
                        'total_fee'          => $order['total_fee'] ?? 0,
                        'transfer_status_id' => $order['transfer_status_id'] ?? 1,
                    ]
                );
            

            return $this->success('Order created successfully', $result['body'], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /delivery-companies/carrybee/{companyId}/orders/{consignmentId}/cancel
     */
    public function carrybeeCancelOrder(Request $request, $companyId, string $consignmentId)
    {
        try {
            $validated = $request->validate([
                'cancellation_reason' => ['required', 'string', 'max:500'],
            ]);

            $service = $this->carrybeeService($companyId);

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
     * GET /delivery-companies/carrybee/{companyId}/orders/{consignmentId}/details
     */
    public function carrybeeOrderDetails($companyId, string $consignmentId)
    {
        try {
            $service = $this->carrybeeService($companyId);

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
}
