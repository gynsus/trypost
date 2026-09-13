<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use Closure;
use Illuminate\Http\Request;

/**
 * Keeps users from open self-hosted registration out of the app until an
 * administrator approves them. Guests and approved users pass through; a
 * pending user only reaches the waiting page, logout, and email verification.
 */
class EnsureUserIsApproved
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user || $user->approved_at !== null || ! config('trypost.self_hosted')) {
            return $next($request);
        }

        if ($request->routeIs('approval.pending', 'logout', 'users.approve', 'verification.*')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'This account is awaiting administrator approval.');
        }

        return redirect()->route('approval.pending');
    }
}
