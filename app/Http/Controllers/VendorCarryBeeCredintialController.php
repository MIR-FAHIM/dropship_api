<?php

namespace App\Http\Controllers;

use App\Models\VendorCarryBeeCredintial;
use Illuminate\Http\Request;

class VendorCarryBeeCredintialController extends Controller
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
     * POST /vendor-carrybee-credentials/add
     */
    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_id'      => ['required', 'integer', 'exists:users,id'],
                'base_url'       => ['nullable', 'string', 'max:500'],
                'client_id'      => ['nullable', 'string', 'max:500'],
                'client_secret'  => ['nullable', 'string', 'max:500'],
                'client_context' => ['nullable', 'string', 'max:500'],
                'is_active'      => ['nullable', 'boolean'],
                'created_by'     => ['nullable', 'integer', 'exists:users,id'],
                'note'           => ['nullable', 'string'],
            ]);

            $credential = VendorCarryBeeCredintial::create($validated);

            return $this->success('Credential added successfully', $credential->load('vendor', 'createdBy'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /vendor-carrybee-credentials/list
     */
    public function list(Request $request)
    {
        try {
            $query = VendorCarryBeeCredintial::with('vendor', 'createdBy');

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            return $this->success('Credentials fetched successfully', $query->latest()->get());
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /vendor-carrybee-credentials/{id}
     */
    public function details($id)
    {
        try {
            $credential = VendorCarryBeeCredintial::with('vendor', 'createdBy')->find($id);

            if (!$credential) {
                return $this->failed('Credential not found', null, 404);
            }

            return $this->success('Credential fetched successfully', $credential);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /vendor-carrybee-credentials/update/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $credential = VendorCarryBeeCredintial::find($id);

            if (!$credential) {
                return $this->failed('Credential not found', null, 404);
            }

            $validated = $request->validate([
                'base_url'       => ['sometimes', 'nullable', 'string', 'max:500'],
                'client_id'      => ['sometimes', 'nullable', 'string', 'max:500'],
                'client_secret'  => ['sometimes', 'nullable', 'string', 'max:500'],
                'client_context' => ['sometimes', 'nullable', 'string', 'max:500'],
                'is_active'      => ['sometimes', 'nullable', 'boolean'],
                'created_by'     => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
                'note'           => ['sometimes', 'nullable', 'string'],
            ]);

            $credential->fill($validated)->save();

            return $this->success('Credential updated successfully', $credential->load('vendor', 'createdBy'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /vendor-carrybee-credentials/delete/{id}
     */
    public function delete($id)
    {
        try {
            $credential = VendorCarryBeeCredintial::find($id);

            if (!$credential) {
                return $this->failed('Credential not found', null, 404);
            }

            $credential->delete();

            return $this->success('Credential deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}

