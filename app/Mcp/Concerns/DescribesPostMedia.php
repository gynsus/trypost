<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ArrayType;

/**
 * The `media` input of the post tools, described the same way everywhere. The
 * rules live in `PostMediaRules::rules()`.
 */
trait DescribesPostMedia
{
    protected function mediaSchema(JsonSchema $schema, string $description): ArrayType
    {
        return $schema->array()
            ->items($schema->object(fn (JsonSchema $item): array => [
                'upload_token' => $item->string()->description('Token of a file uploaded earlier with request-media-upload-tool; valid once, before it expires. To use the same file on another post, pass the media id the first post returned.'),
                'url' => $item->string()->description('Public http(s) URL that serves the file itself (an image, video or PDF; redirects are not followed and web pages are refused); downloaded once per call.'),
                'id' => $item->string()->description('UUID of a media item of this workspace, as returned in a post\'s media (get-post-tool, list-posts-tool); copied for the post. An id already on the post keeps it, so send the current ids in the new order to reorder, and leave one out to remove it.'),
                'alt' => $item->string()->description('Alt text for an image (ignored for video and PDF).'),
                'meta' => $item->object()->description('Per-item settings: alt_text (string, up to 2000 characters), user_tags (Instagram feed images only: up to 20 of {username, x, y}, x and y between 0 and 1 from the top-left corner), cover_offset_ms (video cover frame in milliseconds, for content types with supports_video_cover).'),
            ]))
            ->description("{$description} Items keep the order given (the first is the cover of a carousel). Each item gives exactly one of upload_token, url or id. Check the content type's media rules with list-content-types-tool.");
    }
}
