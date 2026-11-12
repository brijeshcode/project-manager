<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Signs out a client-role user whose client's portal access is blocked, on their very next request. */
class EnsurePortalAccess
{
    public const MESSAGE = 'Portal access for your account has been blocked. Please contact us.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->hasRole('client') && $user->client?->portalBlocked()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['form.email' => self::MESSAGE]);
        }

        return $next($request);
    }
}
