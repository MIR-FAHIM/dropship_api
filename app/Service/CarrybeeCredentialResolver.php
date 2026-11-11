<?php

namespace App\Service;

use App\Models\DeliveryCompany;
use App\Models\Vendor;
use App\Models\VendorCarryBeeCredintial;

/**
 * Picks the CarryBee account to call for a vendor:
 *  - vendor with its own delivery account -> that vendor's active credentials
 *  - otherwise -> the matching / latest active DeliveryCompany (platform account)
 */
class CarrybeeCredentialResolver
{
    /**
     * @return array{service: ?CarrybeeService, error: ?string, http_code: int}
     */
    public function forVendor($vendorId = null): array
    {
        $vendor = !empty($vendorId) ? Vendor::where('id', $vendorId)->first() : null;

        if ($vendor && $vendor->has_own_delivery_account) {
            $vendorUserIds = array_unique(array_filter([$vendor->user_id, $vendor->id, (int) $vendorId]));
            $vendorCredential = VendorCarryBeeCredintial::where('is_active', true)
                ->whereIn('vendor_id', $vendorUserIds)
                ->latest()
                ->first();

            if (!$vendorCredential) {
                return $this->fail('No active Carrybee credentials found for this vendor', 404);
            }
            if (!$vendorCredential->client_id || !$vendorCredential->client_secret || !$vendorCredential->client_context) {
                return $this->fail('Vendor Carrybee credentials are incomplete', 422);
            }

            return $this->ok(new CarrybeeService($vendorCredential->client_id, $vendorCredential->client_secret, $vendorCredential->client_context));
        }

        $credential = null;
        if (empty($vendor) && !empty($vendorId) && is_numeric($vendorId)) {
            $credential = DeliveryCompany::where('id', $vendorId)->where('is_active', true)->first();
        }
        if (!$credential) {
            $credential = DeliveryCompany::where('is_active', true)->latest()->first();
        }
        if (!$credential) {
            return $this->fail('No active Carrybee credentials found', 404);
        }
        if (!$credential->api_key || !$credential->secret_key || !$credential->client_context) {
            return $this->fail('Carrybee credentials are incomplete', 422);
        }

        return $this->ok(new CarrybeeService($credential->api_key, $credential->secret_key, $credential->client_context));
    }

    private function ok(CarrybeeService $service): array
    {
        return ['service' => $service, 'error' => null, 'http_code' => 200];
    }

    private function fail(string $error, int $code): array
    {
        return ['service' => null, 'error' => $error, 'http_code' => $code];
    }
}
