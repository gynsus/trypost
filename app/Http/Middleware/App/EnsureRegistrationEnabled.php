<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Models\Invite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('trypost.self_hosted')) {
            return $next($request);
        }

        // Self-hosted installs are invite-only by default, but an operator
        // running the instance as a client-facing service can open public
        // registration explicitly (REGISTRATION_OPEN=true).
        if (config('trypost.registration_open')) {
            return $next($request);
        }

        // `query` covers the GET form; `input` covers the invite field posted
        // with the registration form (a hidden input, not a query param).
        $inviteId = $request->query('invite') ?? $request->input('invite') ?? $request->session()->get('pending_invite_id');

        if (is_string($inviteId) && Invite::fromId($inviteId) !== null) {
            $request->session()->put('pending_invite_id', $inviteId);

            return $next($request);
        }

        throw new NotFoundHttpException;
    }
}
