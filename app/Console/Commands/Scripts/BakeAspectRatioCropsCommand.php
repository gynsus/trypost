<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Post\BakeLegacyAspectRatioCrops;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posts:bake-aspect-ratio-crops')]
#[Description('Bake the pre-2.0 Facebook/Instagram aspect ratio crop into the media of every scheduled or in-flight target, then drop meta.aspect_ratio')]
class BakeAspectRatioCropsCommand extends Command
{
    public function handle(): int
    {
        $result = BakeLegacyAspectRatioCrops::execute();

        $this->info("Baked {$result['baked_images']} cropped image(s) into {$result['baked_posts']} post(s); dropped the aspect ratio from {$result['stripped_targets']} other target(s).");

        if ($result['skipped_posts'] !== []) {
            $ids = implode(', ', $result['skipped_posts']);
            $this->warn('Left '.count($result['skipped_posts'])." post(s) with more than one enabled target uncropped for now (their media is shared; they keep their aspect ratio until a run after posts:split-legacy-active): {$ids}");
        }

        if ($result['unreadable_images'] !== []) {
            $ids = implode(', ', $result['unreadable_images']);
            $this->warn('Left '.count($result['unreadable_images'])." image(s) whose file could not be read uncropped (post:media): {$ids}");
        }

        if ($result['failed_posts'] !== []) {
            $ids = implode(', ', $result['failed_posts']);
            $this->error('Failed to bake '.count($result['failed_posts'])." post(s), which keep their aspect ratio; run the command again: {$ids}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
