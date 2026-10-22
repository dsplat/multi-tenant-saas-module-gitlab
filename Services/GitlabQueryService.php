<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use MultiTenantSaas\Access\DataScopeContext;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabProject;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabWebhookEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GitLab 只读投影查询（项目 / 事件 / pipeline）。
 *
 * 列表一律走本地投影，返回 LengthAwarePaginator；存在 DataScopeContext 时
 * 在构建期注入行级谓词（ambient scope）。排序以非唯一时间列开头时补主键
 * 作为次级键，保证分页稳定。
 */
class GitlabQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateProjects(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->scopedProjectQuery($tenantId);

        return $query
            ->when($filters['project_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('project_id', (int) $value))
            ->when($filters['external_project_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('external_project_id', (string) $value))
            ->when($filters['connection_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('connection_id', (int) $value))
            ->when($filters['visibility'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('visibility', (string) $value))
            ->when($filters['keyword'] ?? null, function (Builder $builder, mixed $value): void {
                $keyword = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $value) . '%';
                $builder->where(fn (Builder $nested) => $nested
                    ->where('name', 'like', $keyword)
                    ->orWhere('path_with_namespace', 'like', $keyword));
            })
            ->orderByDesc('last_synced_at')
            ->orderByDesc('gitlab_project_id')
            ->paginate($this->perPage($perPage));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateEvents(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->scopedEventQuery($tenantId);

        return $query
            ->when($filters['project_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('project_id', (int) $value))
            ->when($filters['external_project_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('external_project_id', (string) $value))
            ->when($filters['connection_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('connection_id', (int) $value))
            ->when($filters['event_type'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('event_type', (string) $value))
            ->when($filters['status'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('status', (string) $value))
            ->when($filters['mr_iid'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('mr_iid', (int) $value))
            ->orderByDesc('received_at')
            ->orderByDesc('gitlab_event_id')
            ->paginate($this->perPage($perPage));
    }

    /**
     * pipeline 投影 = 归一化的 Pipeline Hook 事件（按 pipeline_id 聚合展示）。
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginatePipelines(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->scopedEventQuery($tenantId)
            ->whereNotNull('pipeline_id')
            ->where(function (Builder $builder): void {
                $builder->where('event_type', GitlabWebhookEvent::EVENT_PIPELINE)
                    ->orWhere('object_kind', 'pipeline');
            });

        return $query
            ->when($filters['project_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('project_id', (int) $value))
            ->when($filters['external_project_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('external_project_id', (string) $value))
            ->when($filters['connection_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('connection_id', (int) $value))
            ->when($filters['status'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('status', (string) $value))
            ->when($filters['pipeline_id'] ?? null, fn (Builder $builder, mixed $value) => $builder->where('pipeline_id', (int) $value))
            ->orderByDesc('received_at')
            ->orderByDesc('gitlab_event_id')
            ->paginate($this->perPage($perPage));
    }

    public function findEvent(int $tenantId, int $eventId): GitlabWebhookEvent
    {
        $event = $this->scopedEventQuery($tenantId)->find($eventId);

        if (! $event instanceof GitlabWebhookEvent) {
            throw new NotFoundHttpException('GitLab 事件不存在');
        }

        return $event;
    }

    public function findPipeline(int $tenantId, int $pipelineId): GitlabWebhookEvent
    {
        $event = $this->scopedEventQuery($tenantId)
            ->where('pipeline_id', $pipelineId)
            ->where(function (Builder $builder): void {
                $builder->where('event_type', GitlabWebhookEvent::EVENT_PIPELINE)
                    ->orWhere('object_kind', 'pipeline');
            })
            ->orderByDesc('received_at')
            ->orderByDesc('gitlab_event_id')
            ->first();

        if (! $event instanceof GitlabWebhookEvent) {
            throw new NotFoundHttpException('GitLab pipeline 投影不存在');
        }

        return $event;
    }

    public function findProject(int $tenantId, int $gitlabProjectId): GitlabProject
    {
        $project = $this->scopedProjectQuery($tenantId)->find($gitlabProjectId);

        if (! $project instanceof GitlabProject) {
            throw new NotFoundHttpException('GitLab 项目投影不存在');
        }

        return $project;
    }

    private function scopedProjectQuery(int $tenantId): Builder
    {
        $query = GitlabProject::query()->where('tenant_id', $tenantId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        return $query;
    }

    private function scopedEventQuery(int $tenantId): Builder
    {
        $query = GitlabWebhookEvent::query()->where('tenant_id', $tenantId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        return $query;
    }

    private function perPage(?int $perPage): int
    {
        return max(1, min(100, $perPage ?? (int) config('gitlab.per_page', 20)));
    }
}
