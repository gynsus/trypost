<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Enums\Post\Status;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['user', 'approvalRequestedBy', 'approver']);

        $requester = $this->approval_requested_by !== null
            ? $this->approvalRequestedBy
            : ($this->status === Status::PendingApproval ? $this->user : null);

        return [
            'id' => $this->id,
            'post_group_id' => $this->post_group_id,
            'author' => $this->person($this->user),
            'content' => $this->content,
            'media' => $this->media,
            'status' => $this->status?->value,
            'schedule_mode' => $this->schedule_mode?->value,
            'scheduled_at' => $this->scheduled_at?->format('Y-m-d H:i:s'),
            'published_at' => $this->published_at?->format('Y-m-d H:i:s'),
            'approval_requested_by' => $this->person($requester),
            'approval_requested_at' => $this->approval_requested_at?->format('Y-m-d H:i:s'),
            'approved_by' => $this->person($this->approver),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'recurrence' => $this->recurrence_frequency !== null && $this->recurrence_interval !== null ? [
                'interval' => $this->recurrence_interval,
                'frequency' => $this->recurrence_frequency->value,
                'remaining' => $this->recurrence_remaining,
            ] : null,
            'origin' => $this->origin->value,
            'platforms' => PostPlatformResource::collection($this->whenLoaded('postPlatforms')),
            'labels' => LabelResource::collection($this->whenLoaded('labels')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Only the id and name of a workspace member, never their email or other personal data.
     *
     * @return array{id: string, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
