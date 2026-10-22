<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services\Tools;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabProject;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabWebhookEvent;

/**
 * L1 只读：按 MR IID 汇总最近一次 GitLab MR 投影。
 *
 * 只读本地归一化投影（不读代码/diff、不返回原始 payload）。
 */
class GitlabMrSummaryHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $iid = (int) ($arguments['mr_iid'] ?? 0);

        if ($iid <= 0) {
            return ['found' => false, 'error' => 'mr_iid 必须是正整数'];
        }

        return TenantContext::runInTenant($tenantId, function () use ($arguments, $tenantId, $iid): array {
            $query = GitlabWebhookEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('event_type', GitlabWebhookEvent::EVENT_MERGE_REQUEST)
                ->where('mr_iid', $iid);

            $externalId = trim((string) ($arguments['external_project_id'] ?? ''));

            if ($externalId !== '') {
                $query->where('external_project_id', $externalId);
            }

            $event = $query
                ->orderByDesc('received_at')
                ->orderByDesc('gitlab_event_id')
                ->first();

            if (! $event instanceof GitlabWebhookEvent) {
                return ['found' => false, 'mr_iid' => $iid, 'message' => '尚无该 MR 的 webhook 投影'];
            }

            $project = $event->external_project_id === null ? null : GitlabProject::query()
                ->where('tenant_id', $tenantId)
                ->where('external_project_id', $event->external_project_id)
                ->first();

            $state = (array) (($project?->metadata ?? [])['mr_state'][(string) $iid] ?? []);

            return [
                'found' => true,
                'mr_iid' => $iid,
                'status' => $event->status,
                'task_keys' => array_values((array) $event->task_keys),
                'project' => $project?->path_with_namespace,
                'external_project_id' => $event->external_project_id,
                'url' => $state['url'] ?? null,
                'source_branch' => $state['source_branch'] ?? null,
                'target_branch' => $state['target_branch'] ?? null,
                'updated_at' => $state['updated_at'] ?? $event->received_at,
            ];
        });
    }
}
