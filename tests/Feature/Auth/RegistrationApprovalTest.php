<?php

declare(strict_types=1);

use App\Mail\UserApproved;
use App\Mail\UserPendingApproval;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.self_hosted', true);
    config()->set('trypost.registration_open', true);
    config()->set('trypost.registration_requires_approval', true);
});

test('open registration creates a pending user and notifies the admin', function () {
    Mail::fake();

    $this->post(route('register.store'), [
        'name' => 'New Client',
        'email' => 'client@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $user = User::where('email', 'client@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->isPendingApproval())->toBeTrue();

    Mail::assertQueued(UserPendingApproval::class);
});

test('registration without the approval requirement is approved immediately', function () {
    config()->set('trypost.registration_requires_approval', false);

    Mail::fake();

    $this->post(route('register.store'), [
        'name' => 'New Client',
        'email' => 'client@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    expect(User::where('email', 'client@example.com')->first()->isPendingApproval())->toBeFalse();

    Mail::assertNothingQueued();
});

test('a pending user is redirected to the waiting page', function () {
    $user = pendingUser();

    $this->actingAs($user)
        ->get(route('app.calendar'))
        ->assertRedirect(route('approval.pending'));

    $this->actingAs($user)
        ->get(route('approval.pending'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/PendingApproval'));
});

test('an approved user is not affected by the approval gate', function () {
    $user = pendingUser();
    $user->update(['approved_at' => now()]);

    $this->actingAs($user)
        ->get(route('approval.pending'))
        ->assertRedirect(route('app.calendar'));

    $this->actingAs($user)->get(route('app.calendar'))->assertOk();
});

test('the signed link approves the user and notifies them', function () {
    Mail::fake();

    $user = pendingUser();

    $this->get(URL::signedRoute('users.approve', ['user' => $user->id]))
        ->assertOk();

    expect($user->fresh()->isPendingApproval())->toBeFalse();

    Mail::assertQueued(UserApproved::class, fn (UserApproved $mail) => $mail->hasTo($user->email));
});

test('an unsigned approval link is rejected', function () {
    $user = pendingUser();

    $this->get(route('users.approve', ['user' => $user->id]))->assertForbidden();

    expect($user->fresh()->isPendingApproval())->toBeTrue();
});

test('the artisan command approves a pending user', function () {
    Mail::fake();

    $user = pendingUser();

    $this->artisan('users:approve', ['email' => $user->email])->assertSuccessful();

    expect($user->fresh()->isPendingApproval())->toBeFalse();

    Mail::assertQueued(UserApproved::class);
});

function pendingUser(): User
{
    $user = User::factory()->create(['approved_at' => null]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $workspace->members()->attach($user->id, ['role' => \App\Enums\UserWorkspace\Role::Member->value]);

    return $user;
}
