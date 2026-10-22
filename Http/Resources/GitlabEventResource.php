<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabWebhookEvent;

/**
 * Webhook 事件投影输出（原始 payload 只在 Integration 收件箱，不在此返回）。
 *
 * @mixin GitlabWebhookEvent
 */
class GitlabEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'gitlab_event_id' => (int) $this->gitlab_event_id,
            'connection_id' => (int) $this->connection_id,
            'inbox_event_id' => (int) $this->inbox_event_id,
            'external_project_id' => $this->external_project_id,
            'project_id' => $this->project_id !== null ? (int) $this->project_id : null,
            'event_type' => (string) $this->event_type,
            'object_kind' => $this->object_kind,
            'mr_iid' => $this->mr_iid !== null ? (int) $this->mr_iid : null,
            'pipeline_id' => $this->pipeline_id !== null ? (int) $this->pipeline_id : null,
            'status' => $this->status,
            'task_keys' => array_values((array) ($this->task_keys ?? [])),
            'received_at' => $this->received_at,
            'processed_at' => $this->processed_at,
            'fetched_at' => $this->processed_at,
            'error' => $this->error,
            'replay_count' => (int) $this->replay_count,
            'replayed_at' => $this->replayed_at,
            'created_at' => $this->created_at,
        ];
    }
}
