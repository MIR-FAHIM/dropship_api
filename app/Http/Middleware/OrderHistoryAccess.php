<?php

namespace App\Http\Middleware;

use App\Models\Vendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderHistoryAccess
{
    public function handle(Request $request, Closure $next, string $panel): Response
    {
        $user = $request->attributes->get('api_user') ?? $request->user();
        if (! $user) {
            return response()->json(['status' => 'failed', 'message' => 'Unauthenticated', 'errors' => null], 401);
        }

        $roles = [strtolower((string) $user->user_type), strtolower((string) $user->role)];
        $allowed = $panel === 'admin' ? ['admin', 'superadmin'] : ['vendor', 'seller'];
        if (! array_intersect($roles, $allowed)) {
            return response()->json(['status' => 'failed', 'message' => 'Forbidden', 'errors' => null], 403);
        }

        if ($panel === 'vendor') {
            $vendorId = Vendor::where('user_id', $user->id)->value('id');
            if (! $vendorId) {
                return response()->json(['status' => 'failed', 'message' => 'Vendor profile not found', 'errors' => null], 403);
            }
            $request->attributes->set('order_history_vendor_id', (int) $vendorId);
        }

        return $next($request);
    }
}
