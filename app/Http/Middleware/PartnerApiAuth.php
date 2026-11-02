<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\PartnerStore;

class PartnerApiAuth
{
    /**
     * Handle an incoming partner request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-RB-API-KEY') 
            ?: $request->header('X-Partner-Key') 
            ?: $request->header('X-API-KEY');

        $apiSecret = $request->header('X-RB-API-SECRET') 
            ?: $request->header('X-Partner-Secret') 
            ?: $request->header('X-API-SECRET');

        // Optional fallback: support Authorization Bearer <api_secret> if X-RB-API-KEY is provided
        if (!$apiSecret && $request->bearerToken() && str_starts_with($request->bearerToken(), 'rb_live_sk_')) {
            $apiSecret = $request->bearerToken();
        }

        if (!$apiKey || !$apiSecret) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Partner authentication failed: Missing X-RB-API-KEY or X-RB-API-SECRET headers.',
            ], 401);
        }

        $store = PartnerStore::with('user')
            ->where('api_key', $apiKey)
            ->first();

        if (!$store) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Invalid API Key.',
            ], 401);
        }

        if (!$store->is_active) {
            return response()->json([
                'status' => 'failed',
                'message' => 'This Partner Store integration has been deactivated.',
            ], 403);
        }

        if (!hash_equals($store->api_secret, $apiSecret)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Invalid API Secret.',
            ], 401);
        }

        if (!$store->user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Store owner account not found.',
            ], 401);
        }

        // Attach store and user to request
        $request->attributes->set('partner_store', $store);
        $request->attributes->set('partner_user', $store->user);
        $request->attributes->set('api_user', $store->user);
        $request->setUserResolver(fn () => $store->user);

        // Update last sync time quietly
        $store->timestamps = false;
        $store->update(['last_sync_at' => now()]);
        $store->timestamps = true;

        return $next($request);
    }
}
