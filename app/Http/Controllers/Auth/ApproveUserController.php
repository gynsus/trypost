<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\UserApproved;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

/**
 * Approves a pending registration. The route is protected by a signed URL
 * that only the administrator receives by email, so no session is required —
 * approving from a mail client just works.
 */
class ApproveUserController extends Controller
{
    public function __invoke(User $user): Response
    {
        if (! $user->isPendingApproval()) {
            return response("{$user->email} is already approved.");
        }

        $user->update(['approved_at' => now()]);

        Mail::to($user->email)->send(new UserApproved($user));

        return response("Approved {$user->email}. The user has been notified by email.");
    }
}
