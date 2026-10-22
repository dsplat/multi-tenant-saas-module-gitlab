<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabProject;

/**
 * GitLab 项目投影输出（只读，无凭据）。
 *
 * @mixin GitlabProject
 */
class GitlabProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'gitlab_project_id' => (int) $this->gitlab_project_id,
            'connection_id' => (int) $this->connection_id,
            'project_id' => $this->project_id !== null ? (int) $this->project_id : null,
            'external_project_id' => (string) $this->external_project_id,
            'path_with_namespace' => (string) $this->path_with_namespace,
            'name' => $this->name,
            'default_branch' => $this->default_branch,
            'visibility' => $this->visibility,
            'last_synced_at' => $this->last_synced_at,
            'fetched_at' => $this->last_synced_at,
            'metadata' => (array) ($this->metadata ?? []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
