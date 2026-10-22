<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * GitLab webhook 归一化投影。
 *
 * 原始 payload 只在 Integration 收件箱（integration_inbox_events）；
 * 本表只保存归一化字段与任务号，`inbox_event_id` 唯一保证重复投递幂等。
 */
class GitlabWebhookEvent extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    public const EVENT_MERGE_REQUEST = 'gitlab.merge_request';

    public const EVENT_PIPELINE = 'gitlab.pipeline';

    public const EVENT_TAG_PUSH = 'gitlab.tag_push';

    public const EVENT_PUSH = 'gitlab.push';

    protected $table = 'gitlab_webhook_events';

    protected $primaryKey = 'gitlab_event_id';

    // 投影表只有 created_at，没有 updated_at（事件事实不可变）
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'connection_id', 'inbox_event_id', 'external_project_id',
        'project_id', 'event_type', 'object_kind', 'mr_iid', 'pipeline_id',
        'status', 'task_keys', 'received_at', 'processed_at', 'error',
        'replay_count', 'replayed_at',
    ];

    protected function casts(): array
    {
        return [
            'inbox_event_id' => 'integer',
            'project_id' => 'integer',
            'external_project_id' => 'string',
            'mr_iid' => 'integer',
            'pipeline_id' => 'integer',
            'task_keys' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'replayed_at' => 'datetime',
            'replay_count' => 'integer',
        ];
    }
}
