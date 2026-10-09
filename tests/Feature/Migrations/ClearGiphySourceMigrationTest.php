<?php

declare(strict_types=1);

use App\Models\Idea;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_06_111329_clear_giphy_source_from_stored_media.php');
    $this->workspace = Workspace::factory()->create();
});

function giphyItem(string $id, ?string $source = 'giphy'): array
{
    return ['id' => $id, 'path' => "medias/{$id}.gif", 'url' => "https://example.test/{$id}.gif", 'source' => $source, 'source_meta' => ['attribution' => 'GIPHY']];
}

test('giphy source becomes null in posts, ideas and thread reply media while everything else is untouched', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->workspace->user_id,
        'media' => [giphyItem('a'), giphyItem('b', 'unsplash'), giphyItem('c', null)],
    ]);
    $untouched = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->workspace->user_id,
        'media' => [giphyItem('d', 'canva')],
    ]);
    $idea = Idea::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->workspace->user_id,
        'media' => [giphyItem('e')],
    ]);
    $target = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $this->workspace->id])->id,
        'meta' => ['thread_replies' => [['text' => 'one', 'media' => [giphyItem('f'), giphyItem('g', 'ai')]], 'plain text']],
    ]);

    $this->migration->up();

    $media = collect($post->fresh()->media);
    expect($media->pluck('source')->all())->toBe([null, 'unsplash', null])
        ->and($media->first()['source_meta'])->toEqual(['attribution' => 'GIPHY'])
        ->and($untouched->fresh()->media[0]['source'])->toBe('canva')
        ->and($idea->fresh()->media[0]['source'])->toBeNull()
        ->and($idea->fresh()->media[0]['source_meta'])->toEqual(['attribution' => 'GIPHY']);

    $replies = $target->fresh()->meta['thread_replies'];
    expect($replies[0]['media'][0]['source'])->toBeNull()
        ->and($replies[0]['media'][1]['source'])->toBe('ai')
        ->and($replies[1])->toBe('plain text');
});

test('running it again changes nothing', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->workspace->user_id,
        'media' => [giphyItem('a')],
    ]);

    $this->migration->up();
    $first = DB::table('posts')->where('id', $post->id)->value('media');
    $this->migration->up();

    expect(DB::table('posts')->where('id', $post->id)->value('media'))->toBe($first)
        ->and($post->fresh()->media[0]['source'])->toBeNull();
});
