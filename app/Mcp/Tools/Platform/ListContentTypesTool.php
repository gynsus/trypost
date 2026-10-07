<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Platform;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the valid content_types per social platform plus their media constraints: max/min media count, requires_media, accept_images/accept_videos/accept_documents, accepts_gif, accepts_mov, forbids_mixed_media, max_video_duration_sec (null = no cap), max_image_bytes / max_video_bytes / max_document_bytes, aspect_ratio_min / aspect_ratio_max for images and video_aspect_ratio_min / video_aspect_ratio_max for videos (width / height, the range the network accepts, null = not checked; GIFs are never checked; auto_fits_image when images are fitted into the frame on publish instead), image_min_width / image_min_height / image_max_width / image_max_height in pixels, supports_alt_text, supports_user_tags (Instagram tags in media meta.user_tags) and supports_video_cover (meta.cover_offset_ms), and the platform default_content_type. These caps mirror each network\'s official limits and are enforced when a post is scheduled, queued or published (create-post-tool, create-posts-tool, update-post-tool, publish-post-tool): a post whose media breaks a rule of its content_type is rejected with a per-platform error. Drafts are never blocked. Call this before attaching media or choosing a content_type.')]
class ListContentTypesTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $platforms = [];

        foreach (Platform::cases() as $platform) {
            $contentTypes = array_map(
                fn (ContentType $type) => $type->toListingArray(),
                array_values(ContentType::forPlatform($platform)),
            );

            $platforms[] = [
                'platform' => $platform->value,
                'label' => $platform->label(),
                'max_content_length' => $platform->maxContentLength(),
                'recommended_content_length' => $platform->recommendedAiContentLength(),
                'allowed_media_types' => array_map(
                    fn ($type) => $type->value,
                    $platform->allowedMediaTypes(),
                ),
                'default_content_type' => ContentType::defaultFor($platform)->value,
                'content_types' => $contentTypes,
            ];
        }

        return Response::structured(['platforms' => $platforms]);
    }
}
