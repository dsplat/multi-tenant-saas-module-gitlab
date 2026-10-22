<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * GitLab 项目只读投影 / 映射落点。
 *
 * - `external_project_id` 是 GitLab project ID（不可变），映射以此为外部键；
 * - `project_id` 是宿主项目 ID（可空）；
 * - 不存 token；metadata 只放同步摘要/状态投影（MR、pipeline、tag）。
 */
class GitlabProject extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    protected $table = 'gitlab_projects';

    protected $primaryKey = 'gitlab_project_id';

    protected $fillable = [
        'tenant_id', 'connection_id', 'project_id', 'external_project_id',
        'path_with_namespace', 'name', 'default_branch', 'visibility',
        'last_synced_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'external_project_id' => 'string',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
