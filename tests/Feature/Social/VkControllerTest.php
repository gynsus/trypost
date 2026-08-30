<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);

    $this->api = rtrim((string) config('trypost.platforms.vk.api'), '/');
});

function fakeVkIdentity(string $api): array
{
    return [
        "{$api}/users.get*" => Http::response([
            'response' => [
                [
                    'id' => 111,
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'screen_name' => 'testuser',
                    'photo_200' => null,
                ],
            ],
        ], 200),
        "{$api}/groups.get*" => Http::response([
            'response' => [
                'count' => 1,
                'items' => [
                    [
                        'id' => 123456,
                        'name' => 'Test Community',
                        'screen_name' => 'testcommunity',
                        'photo_200' => null,
                    ],
                ],
            ],
        ], 200),
    ];
}

test('vk connect page can be rendered', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.vk.connect'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/VkConnect'));
});

test('submitting a valid token lists manageable walls', function () {
    Http::fake(fakeVkIdentity($this->api));

    $response = $this->actingAs($this->user)->post(route('app.social.vk.store'), [
        'access_token' => 'vk1.a.valid-test-token',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/VkConnect')
        ->has('targets', 2)
        ->where('targets.0.owner_id', 111)
        ->where('targets.1.owner_id', -123456)
        ->where('targets.1.is_group', true));

    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Vk->value,
    ]);
});

test('user can connect a vk community wall', function () {
    Http::fake(fakeVkIdentity($this->api));

    $response = $this->actingAs($this->user)->post(route('app.social.vk.store'), [
        'access_token' => 'vk1.a.valid-test-token',
        'owner_id' => -123456,
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Vk->value,
        'platform_user_id' => '-123456',
        'username' => 'testcommunity',
        'display_name' => 'Test Community',
        'status' => Status::Connected->value,
    ]);
});

test('connecting a wall the token does not manage is rejected', function () {
    Http::fake(fakeVkIdentity($this->api));

    $response = $this->actingAs($this->user)->post(route('app.social.vk.store'), [
        'access_token' => 'vk1.a.valid-test-token',
        'owner_id' => -999999,
    ]);

    $response->assertSessionHasErrors('owner_id');

    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Vk->value,
    ]);
});

test('a token vk rejects surfaces the api error on the token field', function () {
    Http::fake([
        "{$this->api}/users.get*" => Http::response([
            'error' => [
                'error_code' => 5,
                'error_msg' => 'User authorization failed: invalid access_token.',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.vk.store'), [
        'access_token' => 'vk1.a.revoked-token',
    ]);

    $response->assertSessionHasErrors('access_token');
});

test('a community access token connects its community directly', function () {
    Http::fake([
        "{$this->api}/users.get*" => Http::response([
            'error' => [
                'error_code' => 27,
                'error_msg' => 'Group authorization failed: method is unavailable with group auth.',
            ],
        ], 200),
        "{$this->api}/groups.getById*" => Http::response([
            'response' => [
                'groups' => [
                    [
                        'id' => 654321,
                        'name' => 'NJ Soft',
                        'screen_name' => 'njsoft',
                        'photo_200' => null,
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.vk.store'), [
        'access_token' => 'vk1.a.community-token',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Vk->value,
        'platform_user_id' => '-654321',
        'username' => 'njsoft',
        'display_name' => 'NJ Soft',
        'status' => Status::Connected->value,
    ]);

    $account = $this->workspace->socialAccounts()->where('platform', Platform::Vk->value)->first();

    expect(data_get($account->meta, 'community_token'))->toBeTrue()
        ->and(data_get($account->meta, 'owner_id'))->toBe(-654321)
        ->and(data_get($account->meta, 'is_group'))->toBeTrue();
});

test('a community token vk rejects surfaces the api error on the token field', function () {
    Http::fake([
        "{$this->api}/users.get*" => Http::response([
            'error' => [
                'error_code' => 27,
                'error_msg' => 'Group authorization failed: method is unavailable with group auth.',
            ],
        ], 200),
        "{$this->api}/groups.getById*" => Http::response([
            'error' => [
                'error_code' => 5,
                'error_msg' => 'Group authorization failed: invalid access_token.',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.vk.store'), [
        'access_token' => 'vk1.a.revoked-community-token',
    ]);

    $response->assertSessionHasErrors('access_token');

    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Vk->value,
    ]);
});
