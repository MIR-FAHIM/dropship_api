<?php

namespace App\Http\Controllers;

use App\Models\DeliveryCompany;
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
}
