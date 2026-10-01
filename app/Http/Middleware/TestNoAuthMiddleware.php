<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

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
        $testNoAuth = $_SERVER['ERUGO_TEST_NO_AUTH'] ?? $_ENV['ERUGO_TEST_NO_AUTH'] ?? env('ERUGO_TEST_NO_AUTH');
        if ($testNoAuth === 'true' || $testNoAuth === true) {
            $admin = User::where('is_admin', true)->first() ?? User::first();
            if ($admin) {
                auth()->setUser($admin);
            }
        }

        return $next($request);
    }
}
