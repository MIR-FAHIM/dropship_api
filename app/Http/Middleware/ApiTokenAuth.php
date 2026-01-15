<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Log;


class ApiTokenAuth
{
public function handle(Request $request, Closure $next, $scope = null)
{
    $plainToken = $request->bearerToken();

    if (!$plainToken) {
        return response()->json([
            'status'=>'error',
            'message'=>'API token missing'], 401);
    }

    $tokenHash = hash('sha256', $plainToken);

    $apiToken = ApiToken::with('user')
        ->where('token_hash', $tokenHash)
        ->first();

    if (!$apiToken || !$apiToken->isValid()) {
        return response()->json(['message'=>'Invalid or expired API token'], 401);
    }

    if (!$apiToken->user) {
        return response()->json(['message'=>'Token user not found'], 401);
    }

    if ($scope && !$apiToken->hasScope($scope)) {
        return response()->json(['message'=>'Insufficient permission'], 403);
    }

    // Attach user and token manually
    $request->attributes->set('api_user', $apiToken->user);
    $request->attributes->set('api_token', $apiToken);

    // Update last used info
    $apiToken->update([
        'last_used_at' => now(),
        'ip' => $request->ip(),
    ]);

    return $next($request);
}
}
