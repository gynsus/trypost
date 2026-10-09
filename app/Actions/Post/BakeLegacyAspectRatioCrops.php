<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\SyncOwnedMedia;
use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Services\Media\MediaOptimizer;
use App\Support\Media\ImageDimensions;
use App\Support\Media\MediaCopyBatch;
use App\Support\Social\PublishCheckpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Before TryPost 2.0 the Facebook and Instagram publishers center-cropped every
 * image to the target's `meta.aspect_ratio` at publish time; 2.0 publishes
 * images at their own ratio and drops the key. This one-off release step bakes
 * that crop into the media of every target that will publish without user
 * action (a scheduled or queued post, one pending approval, or one already
 * publishing, while the target is pending, publishing or retrying), with the
 * crop math the publishers used (`MediaOptimizer::cropToAspectRatio`), so a
 * post scheduled before the deploy publishes the image it would have
 * published. A recurring series continues from its scheduled occurrence, so
 * the next occurrence copies the baked image. Drafts and failed targets are
 * not baked: they publish only after the user acts, keep their original image
 * and lose the key like every other row, and their image is cropped, if at
 * all, in the media editor.
 *
 * Instagram feed crops every image (single or carousel); a Facebook post crops
 * its images only when the first item is an image. An image already at the
 * ratio is left as it is, as the publishers did. The cropped file becomes a
 * temporary upload that `SyncOwnedMedia` moves into the post. A post with more
 * than one enabled target is skipped: its media is shared with a destination
 * that did not crop, so it keeps its key until `posts:split-legacy-active`
 * gives each target its own post and a later run bakes it. Once handled, the
 * target's key is dropped; at the end the key is dropped from every row except
 * posts that were skipped or failed, so a rerun retries only those.
 */
final class BakeLegacyAspectRatioCrops
{
    public const string META_KEY = 'aspect_ratio';

    public const string ORIGINAL = 'original';

    /**
     * @var array<string, float>
     */
    private const array RATIOS = ['1:1' => 1.0, '4:5' => 4 / 5, '16:9' => 16 / 9];

    private const float FALLBACK_RATIO = 1.0;

    private const float RATIO_TOLERANCE = 0.001;

    private const int CHUNK = 100;

    /**
     * @return Builder<PostPlatform>
     */
    public static function candidates(): Builder
    {
        return PostPlatform::query()
            ->enabled()
            ->whereIn('platform', [Platform::Facebook, Platform::Instagram, Platform::InstagramFacebook])
            ->whereIn('content_type', [ContentType::InstagramFeed, ContentType::FacebookPost])
            ->whereNotNull('meta->'.self::META_KEY)
            ->where('meta->'.self::META_KEY, '!=', self::ORIGINAL)
            ->whereIn('status', [Status::Pending, Status::Publishing, Status::Retrying])
            ->whereHas('post', fn (Builder $post): Builder => $post->whereIn('status', [PostStatus::Scheduled, PostStatus::PendingApproval, PostStatus::Publishing]));
    }

    public static function pending(): int
    {
        return self::candidates()->distinct()->count('post_id');
    }

    /**
     * @return array{baked_posts: int, baked_images: int, skipped_posts: list<string>, unreadable_images: list<string>, failed_posts: list<string>, stripped_targets: int}
     */
    public static function execute(): array
    {
        $result = ['baked_posts' => 0, 'baked_images' => 0, 'skipped_posts' => [], 'unreadable_images' => [], 'failed_posts' => [], 'stripped_targets' => 0];

        Post::query()
            ->whereIn('id', self::candidates()->select('post_id'))
            ->select('id')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($posts) use (&$result): void {
                foreach ($posts as $candidate) {
                    try {
                        $outcome = MediaCopyBatch::run(fn (MediaCopyBatch $batch): array => self::bakePost($candidate->id, $batch));
                    } catch (Throwable $exception) {
                        report($exception);
                        $result['failed_posts'][] = $candidate->id;

                        continue;
                    }

                    if (data_get($outcome, 'skipped')) {
                        $result['skipped_posts'][] = $candidate->id;
                    }

                    $baked = (int) data_get($outcome, 'baked', 0);
                    $result['baked_images'] += $baked;
                    $result['baked_posts'] += $baked > 0 ? 1 : 0;
                    $result['unreadable_images'] = [...$result['unreadable_images'], ...data_get($outcome, 'unreadable', [])];
                }
            });

        $result['stripped_targets'] = self::stripRemaining([...$result['failed_posts'], ...$result['skipped_posts']]);

        return $result;
    }

    /**
     * @return array{baked: int, skipped: bool, unreadable: list<string>}
     */
    private static function bakePost(string $postId, MediaCopyBatch $batch): array
    {
        $outcome = ['baked' => 0, 'skipped' => false, 'unreadable' => []];
        $post = Post::query()->lockForUpdate()->find($postId);

        if ($post === null) {
            return $outcome;
        }

        $targets = $post->postPlatforms()->enabled()->orderBy('id')->lockForUpdate()->get();
        $target = self::candidates()->where('post_id', $post->id)->first();

        if ($target === null) {
            return $outcome;
        }

        if ($targets->count() > 1) {
            return [...$outcome, 'skipped' => true];
        }

        if (PublishCheckpoint::instagramWorkflow($target->error_context) === null) {
            $ratio = self::RATIOS[(string) data_get($target->meta, self::META_KEY)] ?? self::FALLBACK_RATIO;
            $outcome = self::bakeMedia($post, $target, $ratio, $batch);
        }

        self::strip($target->id, $target->meta);

        return $outcome;
    }

    /**
     * @return array{baked: int, skipped: bool, unreadable: list<string>}
     */
    private static function bakeMedia(Post $post, PostPlatform $target, float $ratio, MediaCopyBatch $batch): array
    {
        $outcome = ['baked' => 0, 'skipped' => false, 'unreadable' => []];
        $items = array_values($post->media ?? []);
        $first = data_get($items, 0);
        $cropsImages = match ($target->content_type) {
            ContentType::InstagramFeed => true,
            ContentType::FacebookPost => is_array($first) && MediaItem::fromArray($first)->isImage(),
            default => false,
        };

        if (! $cropsImages) {
            return $outcome;
        }

        $baked = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! MediaItem::fromArray($item)->isImage()) {
                $baked[] = $item;

                continue;
            }

            $bytes = rescue(fn (): ?string => Storage::get((string) data_get($item, 'path')), null, report: false);
            $dimensions = is_string($bytes) ? ImageDimensions::fromBytes($bytes) : null;

            if ($dimensions === null) {
                $outcome['unreadable'][] = "{$post->id}:".data_get($item, 'id');
                $baked[] = $item;

                continue;
            }

            if (abs(data_get($dimensions, 'width') / data_get($dimensions, 'height') - $ratio) < self::RATIO_TOLERANCE) {
                $baked[] = $item;

                continue;
            }

            $upload = self::croppedUpload($post, $item, $bytes, $ratio, $batch);
            $baked[] = [
                ...array_intersect_key($item, array_flip(['meta', 'source', 'source_meta'])),
                'id' => $upload->id,
                'upload_token' => $upload->upload_token,
            ];
            $outcome['baked']++;
        }

        if ($outcome['baked'] > 0) {
            $updatedAt = $post->getRawOriginal('updated_at');
            SyncOwnedMedia::execute($post, $baked, $batch);
            Post::query()->whereKey($post->id)->toBase()->update(['updated_at' => $updatedAt]);
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function croppedUpload(Post $post, array $item, string $bytes, float $ratio, MediaCopyBatch $batch): Media
    {
        $input = tempnam(sys_get_temp_dir(), 'bake_in_');

        try {
            file_put_contents($input, $bytes);
            $cropped = app(MediaOptimizer::class)->cropToAspectRatio($input, $ratio);

            try {
                $name = pathinfo((string) (data_get($item, 'original_filename') ?: data_get($item, 'path')), PATHINFO_FILENAME);
                $upload = $post->workspace->addMediaFromPath($cropped, "{$name}.jpg", Media::COLLECTION_UPLOADS, mimeType: 'image/jpeg');
                $batch->rememberCopy($upload->path);
                $upload->issueUploadToken();

                return $upload;
            } finally {
                @unlink($cropped);
            }
        } finally {
            @unlink($input);
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private static function strip(string $postPlatformId, ?array $meta): void
    {
        $meta = (array) $meta;
        unset($meta[self::META_KEY]);

        PostPlatform::query()->whereKey($postPlatformId)->toBase()->update(['meta' => json_encode($meta)]);
    }

    /**
     * @param  list<string>  $keptPostIds
     */
    private static function stripRemaining(array $keptPostIds): int
    {
        $stripped = 0;

        DB::table('post_platforms')
            ->whereNotNull('meta->'.self::META_KEY)
            ->when($keptPostIds !== [], fn ($query) => $query->whereNotIn('post_id', $keptPostIds))
            ->select(['id', 'meta'])
            ->chunkById(500, function ($rows) use (&$stripped): void {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->meta, true);

                    if (! is_array($meta)) {
                        continue;
                    }

                    self::strip($row->id, $meta);
                    $stripped++;
                }
            });

        return $stripped;
    }
}
