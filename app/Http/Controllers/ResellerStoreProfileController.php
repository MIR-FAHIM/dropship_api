<?php

namespace App\Http\Controllers;

use App\Models\ResellerStoreProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResellerStoreProfileController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
                'reseller_id' => ['required', 'integer', 'exists:users,id', 'unique:reseller_store_profiles,reseller_id'],
                'shop_name' => ['nullable', 'string', 'max:255'],
                'logo' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'whatsapp' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string'],
                'details' => ['nullable', 'string'],
                'facebook_url' => ['nullable', 'string', 'max:255'],
                'website' => ['nullable', 'string', 'max:255'],
                'theme' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $profile = ResellerStoreProfile::create($validated);

            return $this->success('Reseller store profile created successfully', $profile->load('reseller'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $profile = ResellerStoreProfile::find($id);

            if (!$profile) {
                return $this->failed('Reseller store profile not found', null, 404);
            }

            $validated = $request->validate([
                'reseller_id' => [
                    'sometimes',
                    'integer',
                    'exists:users,id',
                    Rule::unique('reseller_store_profiles', 'reseller_id')->ignore($profile->id),
                ],
                'shop_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'logo' => ['sometimes', 'nullable', 'string', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
                'whatsapp' => ['sometimes', 'nullable', 'string', 'max:50'],
                'address' => ['sometimes', 'nullable', 'string'],
                'details' => ['sometimes', 'nullable', 'string'],
                'facebook_url' => ['sometimes', 'nullable', 'string', 'max:255'],
                'website' => ['sometimes', 'nullable', 'string', 'max:255'],
                'theme' => ['sometimes', 'nullable', 'string', 'max:255'],
                'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            ]);

            $profile->update($validated);

            return $this->success('Reseller store profile updated successfully', $profile->load('reseller'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $profile = ResellerStoreProfile::with('reseller')->find($id);

            if (!$profile) {
                return $this->failed('Reseller store profile not found', null, 404);
            }

            return $this->success('Reseller store profile fetched successfully', $profile);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function getByReseller($resellerId)
    {
        try {
            $profile = ResellerStoreProfile::with('reseller')
                ->where('reseller_id', $resellerId)
                ->first();

            if (!$profile) {
                return $this->failed('Reseller store profile not found', null, 404);
            }

            return $this->success('Reseller store profile fetched successfully', $profile);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
