<?php

namespace App\Http\Controllers;

use App\Models\ResellerStoreProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    private function logoRules(Request $request, bool $isUpdate = false): array
    {
        $presence = $isUpdate ? 'sometimes' : 'nullable';

        if ($request->hasFile('logo')) {
            return [$presence, 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
        }

        return [$presence, 'nullable', 'string', 'max:255'];
    }

    private function storeLogo(Request $request, int $resellerId): ?string
    {
        if (!$request->hasFile('logo')) {
            return null;
        }

        return $request->file('logo')->store("reseller-store-profiles/{$resellerId}", 'public');
    }

    private function deleteOldLogo(?string $path): void
    {
        if (!$path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
                'reseller_id' => ['required', 'integer', 'exists:users,id', 'unique:reseller_store_profiles,reseller_id'],
                'shop_name' => ['nullable', 'string', 'max:255'],
                'logo' => $this->logoRules($request),
                'phone' => ['nullable', 'string', 'max:50'],
                'whatsapp' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string'],
                'details' => ['nullable', 'string'],
                'facebook_url' => ['nullable', 'string', 'max:255'],
                'website' => ['nullable', 'string', 'max:255'],
                'theme' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $logoPath = $this->storeLogo($request, (int) $validated['reseller_id']);
            if ($logoPath) {
                $validated['logo'] = $logoPath;
            }

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
                'logo' => $this->logoRules($request, true),
                'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
                'whatsapp' => ['sometimes', 'nullable', 'string', 'max:50'],
                'address' => ['sometimes', 'nullable', 'string'],
                'details' => ['sometimes', 'nullable', 'string'],
                'facebook_url' => ['sometimes', 'nullable', 'string', 'max:255'],
                'website' => ['sometimes', 'nullable', 'string', 'max:255'],
                'theme' => ['sometimes', 'nullable', 'string', 'max:255'],
                'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            ]);

            $logoPath = $this->storeLogo($request, (int) ($validated['reseller_id'] ?? $profile->reseller_id));
            if ($logoPath) {
                $this->deleteOldLogo($profile->logo);
                $validated['logo'] = $logoPath;
            }

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
