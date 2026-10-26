<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('api_user') ?? $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthenticated',
                'errors' => null,
            ], 401);
        }

        if (isset($user->banned) && (int) $user->banned === 1) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Your account is deactivated or banned',
                'errors' => null,
            ], 403);
        }

        $roles = array_filter([
            strtolower((string) ($user->user_type ?? '')),
            strtolower((string) ($user->role ?? '')),
        ]);

        $allowedAdminRoles = ['admin', 'superadmin', 'product manager'];

        if (!array_intersect($roles, $allowedAdminRoles)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized: Admin access required',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
