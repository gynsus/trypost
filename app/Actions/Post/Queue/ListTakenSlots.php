<?php

declare(strict_types=1);

namespace App\Actions\Post\Queue;

use App\Models\Post;
use App\Models\PostPlatform;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ListTakenSlots
{
    /**
     * The instants already held on each channel (Post::scopeOccupyingSlotsOn()),
     * as UTC strings keyed by channel id, in one query for every channel,
     * from `$from` and before `$until`.
     *
     * @param  list<string>  $channelIds
     * @return array<string, list<string>>
     */
    public static function handle(array $channelIds, CarbonInterface $from, CarbonInterface $until): array
    {
        $taken = PostPlatform::query()
            ->enabled()
            ->whereIn('post_platforms.social_account_id', $channelIds)
            ->whereHas('post', fn (Builder $post): Builder => $post->holdingSlot()
                ->where('scheduled_at', '>=', $from)
                ->where('scheduled_at', '<', $until))
            ->select('post_platforms.social_account_id')
            ->addSelect(['slot_at' => Post::query()->select('scheduled_at')->whereColumn('posts.id', 'post_platforms.post_id')])
            ->withCasts(['slot_at' => 'datetime'])
            ->get()
            ->groupBy('social_account_id');

        return collect($channelIds)
            ->mapWithKeys(fn (string $channelId): array => [$channelId => $taken->get($channelId, collect())
                ->sortBy(fn (PostPlatform $platform): int => $platform->slot_at->getTimestamp())
                ->map(fn (PostPlatform $platform): string => $platform->slot_at->toIso8601ZuluString())
                ->unique()
                ->values()
                ->all()])
            ->all();
    }
}
