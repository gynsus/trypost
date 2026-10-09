<?php

declare(strict_types=1);

use App\Actions\Post\BakeLegacyAspectRatioCrops;
use App\Actions\Post\ScheduleNextOccurrence;
use App\Dto\MediaItem;
use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\ImageDimensions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();
    Storage::fake();
    Sleep::fake();

    $this->migration = require database_path('migrations/2026_10_04_202400_remove_aspect_ratio_from_post_platforms_meta.php');
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->instagram = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_bake',
        'token_expires_at' => now()->addDays(60),
    ]);
    $this->facebook = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'page_bake',
        'token_expires_at' => null,
        'meta' => ['page_id' => 'page_bake'],
    ]);
});

function bakeJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/**
 * @param  list<array{0: int, 1: int}|'video'>  $files
 * @param  array<string, mixed>  $meta
 * @param  array<string, mixed>  $postAttributes
 * @return array{post: Post, target: PostPlatform, media: list<Media>}
 */
function bakeLegacyPost(Workspace $workspace, SocialAccount $account, ContentType $contentType, array $files, array $meta, PostStatus $status = PostStatus::Scheduled, PlatformStatus $targetStatus = PlatformStatus::Pending, array $postAttributes = []): array
{
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $workspace->user_id,
        'status' => $status,
        'content' => 'Legacy caption',
        ...$postAttributes,
    ]);
    $media = [];

    foreach ($files as $index => $file) {
        if ($file === 'video') {
            $row = Media::factory()->video()->stored()->ownedByPost($post)->create(['order' => $index]);
        } else {
            $row = Media::factory()->ownedByPost($post)->create([
                'path' => 'medias/'.Str::uuid().'.jpg',
                'order' => $index,
                'meta' => ['width' => $file[0], 'height' => $file[1]],
            ]);
            Storage::put($row->path, bakeJpeg($file[0], $file[1]));
        }

        $media[] = $row;
    }

    $items = array_map(fn (Media $row): array => MediaItem::fromMedia($row, $row->isImage() ? "alt {$row->id}" : null)->toArray(), $media);
    Post::query()->whereKey($post->id)->update(['media' => json_encode($items)]);

    $target = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => $contentType,
        'status' => $targetStatus,
        'meta' => $meta,
        'enabled' => true,
    ]);

    return ['post' => $post->fresh(), 'target' => $target, 'media' => $media];
}

/**
 * @return array{width: int, height: int}
 */
function bakedDimensions(array $item): array
{
    return ImageDimensions::fromBytes((string) Storage::get(data_get($item, 'path')));
}

function runBakeRelease(object $migration): void
{
    $migration->up();
    test()->artisan('posts:bake-aspect-ratio-crops')->assertSuccessful();
}

test('a scheduled instagram feed 9:16 photo with 4:5 selected is cropped to 4:5 and publishes that image', function () {
    ['post' => $post, 'target' => $target, 'media' => $media] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920]], ['aspect_ratio' => '4:5', 'is_ai_generated' => true]);

    runBakeRelease($this->migration);

    $post->refresh();
    $item = data_get($post->media, 0);
    $row = Media::query()->findOrFail(data_get($item, 'id'));

    expect(bakedDimensions($item))->toBe(['width' => 1080, 'height' => 1350])
        ->and($row->post_id)->toBe($post->id)
        ->and($row->collection)->toBe(Media::COLLECTION_MEDIA)
        ->and($row->upload_token)->toBeNull()
        ->and(data_get($row->meta, 'width'))->toBe(1080)
        ->and(data_get($row->meta, 'height'))->toBe(1350)
        ->and(data_get($item, 'meta.alt_text'))->toBe("alt {$media[0]->id}")
        ->and(Media::query()->whereKey($media[0]->id)->exists())->toBeFalse()
        ->and($target->fresh()->meta)->toEqual(['is_ai_generated' => true]);

    $graph = (string) config('trypost.platforms.instagram.graph_api');
    Http::fake(fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/ig_bake/media') => Http::response(['id' => 'container-1']),
        str_ends_with($request->url(), '/ig_bake/media_publish') => Http::response(['id' => 'ig-media-1']),
        str_contains($request->url(), '/container-1') => Http::response(['status_code' => 'FINISHED']),
        str_contains($request->url(), '/ig-media-1') => Http::response(['permalink' => 'https://www.instagram.com/p/baked/']),
        default => Http::response([], 404),
    });

    (new PublishToSocialPlatform($target->fresh()))->handle();

    expect($target->fresh()->status)->toBe(PlatformStatus::Published);
    Http::assertSent(fn (Request $request): bool => $request->url() === "{$graph}/ig_bake/media"
        && data_get($request->data(), 'image_url') === Storage::url($row->path));
});

test('an instagram carousel crops every image and keeps its video', function () {
    ['post' => $post, 'media' => $media] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920], 'video', [1600, 900]], ['aspect_ratio' => '1:1']);

    runBakeRelease($this->migration);

    $items = $post->fresh()->media;

    expect($items)->toHaveCount(3)
        ->and(bakedDimensions($items[0]))->toBe(['width' => 1080, 'height' => 1080])
        ->and(data_get($items, '1.id'))->toBe($media[1]->id)
        ->and(bakedDimensions($items[2]))->toBe(['width' => 900, 'height' => 900])
        ->and(Media::query()->where('post_id', $post->id)->orderBy('order')->pluck('id')->all())->toBe(array_column($items, 'id'));
});

test('a facebook post crops its images, and one led by a video keeps them', function () {
    ['post' => $images] = bakeLegacyPost($this->workspace, $this->facebook, ContentType::FacebookPost, [[1000, 1000], [800, 1000]], ['aspect_ratio' => '16:9']);
    ['post' => $video, 'media' => $videoMedia] = bakeLegacyPost($this->workspace, $this->facebook, ContentType::FacebookPost, ['video', [1000, 1000]], ['aspect_ratio' => '16:9']);

    runBakeRelease($this->migration);

    $items = $images->fresh()->media;

    expect(bakedDimensions($items[0]))->toBe(['width' => 1000, 'height' => 563])
        ->and(bakedDimensions($items[1]))->toBe(['width' => 800, 'height' => 450])
        ->and(array_column($video->fresh()->media, 'id'))->toBe([$videoMedia[0]->id, $videoMedia[1]->id]);

    Http::fake(['*/page_bake/photos' => Http::response(['id' => 'photo-1']), '*/page_bake/feed' => Http::response(['id' => 'page_bake_1'])]);

    $target = $images->postPlatforms()->sole();
    (new PublishToSocialPlatform($target))->handle();

    expect($target->fresh()->status)->toBe(PlatformStatus::Published);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/page_bake/photos')
        && data_get($request->data(), 'url') === Storage::url(data_get($items, '0.path')));
});

test('targets the publishers never cropped keep their media and lose the key', function () {
    ['post' => $story, 'target' => $storyTarget, 'media' => $storyMedia] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramStory, [[1080, 1920]], ['aspect_ratio' => '4:5']);
    ['post' => $published, 'target' => $publishedTarget, 'media' => $publishedMedia] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920]], ['aspect_ratio' => '4:5'], PostStatus::Published, PlatformStatus::Published);
    ['post' => $original, 'target' => $originalTarget, 'media' => $originalMedia] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1350]], ['aspect_ratio' => 'original']);
    ['post' => $atRatio, 'target' => $atRatioTarget, 'media' => $atRatioMedia] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1350]], ['aspect_ratio' => '4:5']);
    ['post' => $started, 'target' => $startedTarget, 'media' => $startedMedia] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920]], ['aspect_ratio' => '4:5'], PostStatus::Publishing, PlatformStatus::Publishing);
    $startedTarget->update(['error_context' => ['instagram_workflow' => ['stage' => 'final_container', 'container_id' => 'container-9']]]);

    runBakeRelease($this->migration);

    foreach ([[$story, $storyTarget, $storyMedia], [$published, $publishedTarget, $publishedMedia], [$original, $originalTarget, $originalMedia], [$atRatio, $atRatioTarget, $atRatioMedia], [$started, $startedTarget, $startedMedia]] as [$post, $target, $media]) {
        expect(data_get($post->fresh()->media, '0.id'))->toBe($media[0]->id)
            ->and($target->fresh()->meta)->toEqual([]);
    }
});

test('a target that publishes without user action is baked', function (PostStatus $status, PlatformStatus $targetStatus, array $postAttributes) {
    ['post' => $post, 'target' => $target, 'media' => $media] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920]], ['aspect_ratio' => '4:5'], $status, $targetStatus, $postAttributes);

    runBakeRelease($this->migration);

    expect(data_get($post->fresh()->media, '0.id'))->not->toBe($media[0]->id)
        ->and(bakedDimensions(data_get($post->fresh()->media, 0)))->toBe(['width' => 1080, 'height' => 1350])
        ->and($target->fresh()->meta)->toEqual([]);
})->with([
    'scheduled' => [PostStatus::Scheduled, PlatformStatus::Pending, []],
    'queued' => [PostStatus::Scheduled, PlatformStatus::Pending, ['schedule_mode' => ScheduleMode::Queue]],
    'pending approval' => [PostStatus::PendingApproval, PlatformStatus::Pending, []],
    'publishing' => [PostStatus::Publishing, PlatformStatus::Publishing, []],
    'retrying' => [PostStatus::Publishing, PlatformStatus::Retrying, []],
]);

test('a draft or a failed target keeps its original image and loses the key', function (PostStatus $status, PlatformStatus $targetStatus) {
    ['post' => $post, 'target' => $target, 'media' => $media] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920]], ['aspect_ratio' => '4:5', 'is_ai_generated' => true], $status, $targetStatus);

    expect(BakeLegacyAspectRatioCrops::pending())->toBe(0);

    runBakeRelease($this->migration);

    $item = data_get($post->fresh()->media, 0);

    expect(data_get($item, 'id'))->toBe($media[0]->id)
        ->and(bakedDimensions($item))->toBe(['width' => 1080, 'height' => 1920])
        ->and($target->fresh()->meta)->toEqual(['is_ai_generated' => true]);
})->with([
    'draft' => [PostStatus::Draft, PlatformStatus::Pending],
    'failed post' => [PostStatus::Failed, PlatformStatus::Failed],
    'failed target of a post still publishing' => [PostStatus::Publishing, PlatformStatus::Failed],
]);

test('an instagram target connected through facebook is baked like a direct one', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
    ]);
    ['post' => $post] = bakeLegacyPost($this->workspace, $account, ContentType::InstagramFeed, [[1080, 1920], [1920, 1080]], ['aspect_ratio' => '1:1']);

    runBakeRelease($this->migration);

    $items = $post->fresh()->media;

    expect(bakedDimensions($items[0]))->toBe(['width' => 1080, 'height' => 1080])
        ->and(bakedDimensions($items[1]))->toBe(['width' => 1080, 'height' => 1080]);
});

test('a recurring series is baked and its next occurrence carries the cropped image', function () {
    ['post' => $post] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920]], ['aspect_ratio' => '4:5'], postAttributes: [
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_interval' => 1,
        'recurrence_remaining' => 3,
    ]);

    runBakeRelease($this->migration);

    $next = ScheduleNextOccurrence::execute($post->fresh())->sole();

    expect(bakedDimensions(data_get($post->fresh()->media, 0)))->toBe(['width' => 1080, 'height' => 1350])
        ->and(bakedDimensions(data_get($next->media, 0)))->toBe(['width' => 1080, 'height' => 1350])
        ->and($next->postPlatforms()->sole()->meta)->toEqual([]);
});

test('a post whose media is shared with another enabled target is skipped and reported', function () {
    ['post' => $post, 'target' => $target, 'media' => $media] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920]], ['aspect_ratio' => '4:5'], PostStatus::Publishing, PlatformStatus::Pending);
    PostPlatform::factory()->create(['post_id' => $post->id, 'enabled' => true]);

    $this->migration->up();
    $this->artisan('posts:bake-aspect-ratio-crops')
        ->expectsOutputToContain("their media is shared; they keep their aspect ratio until a run after posts:split-legacy-active): {$post->id}")
        ->assertSuccessful();

    expect(data_get($post->fresh()->media, '0.id'))->toBe($media[0]->id)
        ->and($target->fresh()->meta)->toEqual(['aspect_ratio' => '4:5']);
});

test('a second run changes nothing', function () {
    ['post' => $post] = bakeLegacyPost($this->workspace, $this->instagram, ContentType::InstagramFeed, [[1080, 1920], [1920, 1080]], ['aspect_ratio' => '4:5']);

    runBakeRelease($this->migration);
    $media = $post->fresh()->media;
    $files = Storage::allFiles();
    $rows = Media::query()->pluck('id')->sort()->values()->all();

    $this->artisan('posts:bake-aspect-ratio-crops')
        ->expectsOutputToContain('Baked 0 cropped image(s) into 0 post(s)')
        ->assertSuccessful();

    expect($post->fresh()->media)->toBe($media)
        ->and(Storage::allFiles())->toBe($files)
        ->and(Media::query()->pluck('id')->sort()->values()->all())->toBe($rows)
        ->and(bakedDimensions($media[1]))->toBe(['width' => 864, 'height' => 1080]);
});
