<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API routes: only the ADMIN_EMAILS in .env. Checked per request, not
 * baked into the token, so removing an email locks them out immediately.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Only admins can do this.'], 403);
        }

        return $next($request);
    }
}
