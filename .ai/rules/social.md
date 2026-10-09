---
paths:
  - app/Services/Social/GoogleBusinessPublisher.php
  - app/Support/Social/GoogleBusinessDerivativeCleaner.php
  - app/Actions/Post/DeletePost.php
  - app/Actions/Post/UpdatePost.php
  - app/Support/Social/AbandonGoogleBusinessReview.php
  - app/Actions/Workspace/PurgeWorkspace.php
  - app/Http/Controllers/Auth/SocialController.php
  - app/Support/Social/ThreadProgress.php
  - app/Support/ThreadReplies.php
  - app/Services/Social/Concerns/PublishesThreads.php
  - app/Services/Social/FacebookPublisher.php
  - app/Services/Social/InstagramPublisher.php
---

# Social

## GBP JPEG must outlive PROCESSING
Google fetches Local Post sourceUrl after create while LocalPostState is Processing/Scheduled/Unspecified. Keep the JPEG derivative on disk until reconcile settle() or RecoverStuckPosts fails the target. Deleting in publish() finally races PHOTO_FETCH_FAILED. Live/Rejected on the create response may prune immediately. Path is deterministic: google-business-derivatives/{postPlatformId}.jpg. Wire values live on App\Enums\GoogleBusiness\LocalPostState — never compare raw PROCESSING/SCHEDULED strings. RecoverStuckPosts must prune the JPEG on the 1h Publishing/Pending/Retrying timeout (the worker can die after writing the file and before PendingReview), on a disabled GBP target (reconcile and the 24h ceiling skip `enabled=false`), and again on the 24h review ceiling. UpdatePost mass-updates `enabled=false` (observers never fire): abandon any GBP still in pending_review that is not in the kept set, then prune leftover JPEGs after commit. Disconnect deletes the channel's posts (`DeleteChannelPosts`) before the account delete and prunes their JPEGs, so no pending_review row is left with a null account. DeletePost and PurgeWorkspace must prune every Google Business PostPlatform JPEG before the row disappears — a user delete during pending_review or a workspace wipe would otherwise leak the file, and a DB cascade on post_platforms does not fire Eloquent observers.

## Thread checkpoints resume, never re-post
Bluesky/Mastodon/X thread segments already live are checkpointed in post_platforms.error_context.thread_progress (ThreadProgress) after each segment, so any retry (incl. posts:retry, which keeps them) resumes from the next segment. A resume keeps the stored root hash: the root is the target's identity even if the same text would now hash differently. Never clear thread_progress on retry and never re-post a checkpointed segment. thread_reply_ids lists the reply ids so ImportExternalPosts skips TryPost's own replies.

## No server-side aspect ratio crop or meta.aspect_ratio
Removed October 2026 by user decision: the Facebook and Instagram publishers send every image at its own ratio, and there is no `meta.aspect_ratio` (rule, enum, crop helper and composer radios are gone; migration 2026_10_04_202400 strips stored keys, except on IG feed / FB post targets that will publish without user action (pending/publishing/retrying on a scheduled, pending approval or publishing post), whose crop the one-off `posts:bake-aspect-ratio-crops` release step bakes into the post's media with main's crop math before dropping the key, so pre-2.0 scheduled posts publish the image they were planned with; drafts and failed targets are not baked, keep their original image and lose the key (owner decision 2026-10-06); `MediaOptimizer::cropToAspectRatio` exists only for that step). Instagram feed images outside 3:4–1.91:1 are caught at save by ContentTypeCompatibleWithMedia and fixed per image in the media editor; Facebook feed accepts any ratio. Stories still go through FitsImageToCanvas. Do not reintroduce a ratio selector or a publish-time crop.
