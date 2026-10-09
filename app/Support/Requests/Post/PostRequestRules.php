<?php

declare(strict_types=1);

namespace App\Support\Requests\Post;

use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Rules\ContentTypeMatchesPlatform;
use App\Rules\ContentTypeMatchesPostPlatform;
use App\Rules\PostContentFitsMaxLength;
use App\Support\PostMediaRules;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Single source of the post create, batch, update and publish rules shared by
 * the public API FormRequests and the MCP post tools. Every input is explicit,
 * so the rules never depend on the current request or authenticated user.
 */
class PostRequestRules
{
    private const STATUSES = [Status::Draft->value, Status::Scheduled->value, Status::Publishing->value];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function store(Workspace $workspace, array $input): array
    {
        $platforms = (array) data_get($input, 'platforms', []);

        return [
            'content' => [
                'nullable',
                'string',
                new PostContentFitsMaxLength,
                Rule::when(
                    in_array(data_get($input, 'status'), [Status::Scheduled->value, Status::Publishing->value], true),
                    [new ContentFitsPlatformLimits(
                        self::selectedAccounts($workspace, $input),
                        PostPlatformMetaRules::metaByKey($platforms, 'social_account_id'),
                        PostPlatformMetaRules::contentTypesByKey($platforms, 'social_account_id'),
                    )],
                ),
            ],
            ...PostMediaRules::rules(),
            'platforms' => ['required', 'array', 'size:1'],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'platforms.*.social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id),
            ],
            'platforms.*.content_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                new ContentTypeMatchesPlatform,
            ],
            ...PostPlatformMetaRules::rules(),
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
            'queue' => PostStatusRules::queueRules(),
            'queue_slot' => ['nullable', 'date', 'before:2038-01-19', 'prohibited_unless:status,scheduled', 'prohibits:queue'],
            ...self::labelRules($workspace),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function batch(Workspace $workspace): array
    {
        return [
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            ...PostMediaRules::rules(),
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
            'queue' => PostStatusRules::queueRules(),
            ...self::labelRules($workspace),
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id),
            ],
            'destinations.*.content_type' => ['sometimes', 'nullable', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'destinations.*.content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            ...PostMediaRules::rules('destinations.*.media'),
            'destinations.*.meta' => ['sometimes', 'array'],
        ];
    }

    /**
     * Expects the input already passed through updateInput().
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(Workspace $workspace, Post $post, array $input): array
    {
        $status = data_get($input, 'status');
        $platforms = (array) data_get($input, 'platforms', []);

        return [
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'content' => [
                'nullable',
                'string',
                new PostContentFitsMaxLength,
                Rule::when(
                    in_array($status, [Status::Scheduled->value, Status::Publishing->value], true),
                    [new ContentFitsPlatformLimits(
                        self::submittedTargets($post, $input),
                        PostPlatformMetaRules::metaByKey($platforms, 'id'),
                        PostPlatformMetaRules::contentTypesByKey($platforms, 'id', self::storedContentTypes($post)),
                    )],
                ),
            ],
            ...PostMediaRules::rules(),
            'social_account_id' => ['prohibited'],
            'content_type' => ['sometimes', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'meta' => ['sometimes', 'array'],
            'platforms' => ['sometimes', 'array'],
            'platforms.*.id' => ['required', 'uuid', Rule::exists('post_platforms', 'id')->where('post_id', $post->id)->where('enabled', true)],
            'platforms.*.content_type' => [
                'sometimes',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                new ContentTypeMatchesPostPlatform,
            ],
            ...PostPlatformMetaRules::rules(),
            'scheduled_at' => PostStatusRules::scheduledAtRules($post, $status, filled(data_get($input, 'queue'))),
            'queue' => PostStatusRules::queueRules(),
            ...self::labelRules($workspace),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publish(): array
    {
        return [
            'post_id' => ['required', 'uuid'],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19', 'prohibits:queue'],
            'queue' => ['nullable', Rule::enum(QueuePosition::class)],
        ];
    }

    /**
     * The flat `content_type` / `meta` of a single-channel post rewritten as
     * `platforms[0]` for its enabled destination, so both input shapes are
     * validated by the same per-platform rules and report the same keys.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function updateInput(Post $post, array $input): array
    {
        if (array_key_exists('platforms', $input) || ! Arr::hasAny($input, ['content_type', 'meta'])) {
            return $input;
        }

        $targets = $post->postPlatforms()->enabled()->pluck('id');

        if ($targets->count() !== 1) {
            return $input;
        }

        return [
            ...Arr::except($input, ['content_type', 'meta']),
            'platforms' => [['id' => $targets->sole(), ...Arr::only($input, ['content_type', 'meta'])]],
        ];
    }

    /**
     * Required-on-publish meta and media compatibility checks of an update
     * that schedules or publishes, run after the field rules.
     *
     * @param  array<string, mixed>  $input
     */
    public static function afterUpdate(Validator $validator, Post $post, array $input): void
    {
        if (! in_array(data_get($input, 'status'), [Status::Scheduled->value, Status::Publishing->value], true)) {
            return;
        }

        self::addMediaCompatibilityErrors($validator, $post, $input);

        $targets = self::submittedTargets($post, $input);

        PostPlatformMetaRules::addRequiredOnPublishErrors(
            $validator,
            (array) data_get($input, 'platforms', []),
            fn (mixed $platform) => $targets[data_get($platform, 'id')] ?? null,
        );
    }

    /**
     * The workspace accounts chosen in `platforms[].social_account_id`, keyed by id.
     *
     * @param  array<string, mixed>  $input
     * @return Collection<string, SocialAccount>
     */
    public static function selectedAccounts(Workspace $workspace, array $input): Collection
    {
        $accountIds = collect((array) data_get($input, 'platforms', []))
            ->pluck('social_account_id')
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->all();

        if ($accountIds === []) {
            return collect();
        }

        return SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            ...PostPlatformMetaRules::messages(),
            ...PostStatusRules::queueMessages(),
            'scheduled_at.prohibits' => __('posts.errors.queue_with_scheduled_at'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return PostPlatformMetaRules::attributes();
    }

    /**
     * @return array<string, mixed>
     */
    private static function labelRules(Workspace $workspace): array
    {
        return [
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)->withoutTrashed()],
        ];
    }

    /**
     * Validates every submitted (or stored) content type against the
     * effective media: the request's media when sent, otherwise the stored one.
     *
     * @param  array<string, mixed>  $input
     */
    private static function addMediaCompatibilityErrors(Validator $validator, Post $post, array $input): void
    {
        if (filled(data_get($input, 'content_type')) && ! array_key_exists('platforms', $input)) {
            return;
        }

        $media = array_key_exists('media', $input) ? (array) data_get($input, 'media', []) : (array) ($post->media ?? []);

        $entries = ContentTypeCompatibleWithMedia::entriesForUpdate(
            $post,
            array_key_exists('platforms', $input) ? (array) data_get($input, 'platforms', []) : null,
            array_key_exists('media', $input) ? $media : null,
        );

        foreach (ContentTypeCompatibleWithMedia::errorsFor($entries, $media, $post->workspace) as $key => $message) {
            $validator->errors()->add($key, $message);
        }
    }

    /**
     * The post's destinations named in `platforms[].id`, each resolved to its
     * account (or its platform when the account is gone), keyed by id.
     *
     * @param  array<string, mixed>  $input
     * @return Collection<string, mixed>
     */
    private static function submittedTargets(Post $post, array $input): Collection
    {
        $ids = collect((array) data_get($input, 'platforms', []))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->all();

        if ($ids === []) {
            return collect();
        }

        return $post->postPlatforms()
            ->whereIn('id', $ids)
            ->with('socialAccount')
            ->get()
            ->mapWithKeys(fn (PostPlatform $postPlatform): array => [
                $postPlatform->id => $postPlatform->socialAccount ?? $postPlatform->platform,
            ]);
    }

    /**
     * @return array<string, string|null>
     */
    private static function storedContentTypes(Post $post): array
    {
        return $post->postPlatforms()->pluck('content_type', 'id')
            ->map(fn (?ContentType $contentType): ?string => $contentType?->value)
            ->all();
    }
}
