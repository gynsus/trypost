<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Services\Social\Vk\VkApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * VK connects with a user access token (scope: wall, photos, groups, video,
 * offline) instead of OAuth — VK stopped granting the `wall` scope to new
 * OAuth apps, so users bring a token from a standalone app or an approved
 * application of their own. Two-step form: the token is validated and the
 * manageable walls (own profile + administered communities) are listed, then
 * the chosen wall is stored as the account.
 */
class VkController extends SocialController
{
    protected SocialPlatform $platform = SocialPlatform::Vk;

    public function connect(Request $request): InertiaResponse
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return Inertia::render('accounts/VkConnect', [
            'errors' => session('errors')?->getBag('default')?->toArray() ?? [],
        ]);
    }

    public function store(Request $request): InertiaResponse
    {
        $this->ensurePlatformEnabled();

        $request->validate([
            'access_token' => 'required|string|min:10',
            'owner_id' => 'nullable|integer',
        ]);

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        try {
            $user = $this->callVk($request->access_token, 'users.get', [
                'fields' => 'screen_name,photo_200',
            ])[0] ?? null;

            if (! is_array($user)) {
                throw ValidationException::withMessages(['access_token' => __('accounts.vk.invalid_token')]);
            }

            $targets = $this->buildTargets($request->access_token, $user);

            if (! $request->filled('owner_id')) {
                return Inertia::render('accounts/VkConnect', [
                    'errors' => [],
                    'targets' => array_values($targets),
                ]);
            }

            $target = $targets[(int) $request->owner_id] ?? null;

            if ($target === null) {
                throw ValidationException::withMessages(['owner_id' => __('accounts.vk.invalid_target')]);
            }

            $avatarPath = $target['photo'] ? uploadFromUrl($target['photo']) : null;

            $workspace->socialAccounts()->updateOrCreate(
                [
                    'platform' => $this->platform->value,
                    'platform_user_id' => (string) $target['owner_id'],
                ],
                [
                    'username' => $target['screen_name'],
                    'display_name' => $target['name'],
                    'avatar_url' => $avatarPath,
                    'access_token' => $request->access_token,
                    'refresh_token' => null,
                    // vkhost/standalone tokens are issued with the `offline`
                    // scope and never expire; there is no refresh flow.
                    'token_expires_at' => null,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'owner_id' => $target['owner_id'],
                        'is_group' => $target['owner_id'] < 0,
                        'vk_user_id' => (int) data_get($user, 'id'),
                    ],
                ],
            );

            return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('VK connection error', [
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages(['access_token' => __('accounts.vk.connection_error')]);
        }
    }

    /**
     * Walls the token may publish to: the user's own profile plus communities
     * where the user is an administrator or editor. Keyed by owner_id so the
     * second form step can only pick something this token really manages.
     *
     * @param  array<string, mixed>  $user
     * @return array<int, array{owner_id: int, name: string, screen_name: ?string, photo: ?string, is_group: bool}>
     */
    private function buildTargets(string $accessToken, array $user): array
    {
        $targets = [];

        $userId = (int) data_get($user, 'id');
        $targets[$userId] = [
            'owner_id' => $userId,
            'name' => trim(data_get($user, 'first_name', '').' '.data_get($user, 'last_name', '')),
            'screen_name' => data_get($user, 'screen_name'),
            'photo' => data_get($user, 'photo_200'),
            'is_group' => false,
        ];

        $groups = $this->callVk($accessToken, 'groups.get', [
            'filter' => 'admin,editor',
            'extended' => 1,
            'fields' => 'screen_name,photo_200',
            'count' => 200,
        ]);

        foreach (data_get($groups, 'items', []) as $group) {
            $groupId = (int) data_get($group, 'id');
            $targets[-$groupId] = [
                'owner_id' => -$groupId,
                'name' => (string) data_get($group, 'name'),
                'screen_name' => data_get($group, 'screen_name'),
                'photo' => data_get($group, 'photo_200'),
                'is_group' => true,
            ];
        }

        return $targets;
    }

    /**
     * Call a VK method and return its `response` payload. VK reports failures
     * as HTTP 200 with an `error` object — surfaced here as a validation
     * error on the token field so the form shows what VK said.
     *
     * @return array<mixed>
     */
    private function callVk(string $accessToken, string $method, array $params): array
    {
        $response = Http::asForm()->post(
            VkApi::endpoint($method),
            $params + VkApi::baseParams($accessToken),
        );

        $error = $response->json('error');

        if ($response->failed() || $error !== null) {
            Log::error('VK connect API call failed', [
                'method' => $method,
                'status' => $response->status(),
                'error_code' => data_get($error, 'error_code'),
            ]);

            throw ValidationException::withMessages([
                'access_token' => data_get($error, 'error_msg') ?: __('accounts.vk.connection_error'),
            ]);
        }

        return (array) $response->json('response');
    }
}
