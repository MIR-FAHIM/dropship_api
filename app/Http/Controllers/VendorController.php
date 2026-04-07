<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;
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
                'shop_name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:vendors,email'],
                'password' => ['required', 'string', 'min:6'],
                'contact_person' => ['nullable', 'string', 'max:255'],
                'emergency_contact' => ['nullable', 'string', 'max:255'],
                'address' => ['nullable', 'string'],
                'zone' => ['nullable', 'string', 'max:255'],
                'mobile' => ['nullable', 'string', 'max:50'],
                'whatsapp' => ['nullable', 'string', 'max:50'],
                'owner_name' => ['nullable', 'string', 'max:255'],
                'shop_type' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
            ]);

            $validated['password'] = Hash::make($validated['password']);

            $vendor = Vendor::create($validated);

            return $this->success('Vendor registered successfully', $vendor, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /vendors/login
     */
    public function vendorLogin(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            $vendor = Vendor::where('email', $validated['email'])->first();

            if (!$vendor || !Hash::check($validated['password'], $vendor->password)) {
                return $this->failed('Invalid credentials', null, 401);
            }

            if (!$vendor->is_active) {
                return $this->failed('Vendor account is inactive', null, 403);
            }

            return $this->success('Login successful', $vendor);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
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
            $vendor = Vendor::find($id);

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

            $validated = $request->validate([
                'is_active' => ['required', 'boolean'],
            ]);

            $vendor->update(['is_active' => $validated['is_active']]);

            $status = $validated['is_active'] ? 'activated' : 'deactivated';

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
                'shop_name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'unique:vendors,email,' . $id],
                'password' => ['nullable', 'string', 'min:6'],
                'contact_person' => ['nullable', 'string', 'max:255'],
                'emergency_contact' => ['nullable', 'string', 'max:255'],
                'address' => ['nullable', 'string'],
                'zone' => ['nullable', 'string', 'max:255'],
                'mobile' => ['nullable', 'string', 'max:50'],
                'whatsapp' => ['nullable', 'string', 'max:50'],
                'owner_name' => ['nullable', 'string', 'max:255'],
                'shop_type' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
            ]);

            if (!empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $vendor->update($validated);

            return $this->success('Vendor updated successfully', $vendor);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
