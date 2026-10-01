<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * TEST ENVIRONMENT ONLY: Auto-authenticate as the first admin user.
 *
 * Activated by setting ERUGO_TEST_NO_AUTH=true in the environment.
 * NEVER set this in production — it bypasses all authentication.
 */
class TestNoAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (env('ERUGO_TEST_NO_AUTH') === 'true' || env('ERUGO_TEST_NO_AUTH') === true) {
            $admin = User::where('is_admin', true)->first() ?? User::first();
            if ($admin) {
                auth()->setUser($admin);
                // Also set the JWT token so API calls work
                try {
                    $token = JWTAuth::fromUser($admin);
                    $request->headers->set('Authorization', 'Bearer ' . $token);
                } catch (\Exception $e) {
                    // JWT not available, rely on session auth
                }
            }
        }

        return $next($request);
    }
}
