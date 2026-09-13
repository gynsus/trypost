<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\UserApproved;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ApproveUser extends Command
{
    protected $signature = 'users:approve {email : Email of the pending user}';

    protected $description = 'Approve a registration that is awaiting administrator approval';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if (! $user->isPendingApproval()) {
            $this->info("{$user->email} is already approved.");

            return self::SUCCESS;
        }

        $user->update(['approved_at' => now()]);

        Mail::to($user->email)->send(new UserApproved($user));

        $this->info("Approved {$user->email}. The user has been notified by email.");

        return self::SUCCESS;
    }
}
