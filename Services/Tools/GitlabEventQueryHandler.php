<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services\Tools;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabWebhookEvent;

/**
 * L1 只读：查询 GitLab webhook 事件投影。
 *
 * 只返回归一化字段；原始 payload、请求头、token 一律不出现在结果中。
 */
class GitlabEventQueryHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $limit = max(1, min(20, (int) ($arguments['limit'] ?? 10)));

        return TenantContext::runInTenant($tenantId, function () use ($arguments, $tenantId, $limit): array {
            $query = GitlabWebhookEvent::query()->where('tenant_id', $tenantId);

            foreach (['event_type', 'status', 'external_project_id'] as $key) {
                $value = $arguments[$key] ?? null;

                if (is_scalar($value) && trim((string) $value) !== '') {
                    $query->where($key, (string) $value);
                }
            }

            if (isset($arguments['project_id']) && (int) $arguments['project_id'] > 0) {
                $query->where('project_id', (int) $arguments['project_id']);
            }

            $items = $query
                ->orderByDesc('received_at')
                ->orderByDesc('gitlab_event_id')
                ->limit($limit)
                ->get()
                ->map(static fn (GitlabWebhookEvent $event): array => [
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'external_project_id' => $event->external_project_id,
                    'mr_iid' => $event->mr_iid,
                    'pipeline_id' => $event->pipeline_id,
                    'task_keys' => array_values((array) $event->task_keys),
                    'received_at' => $event->received_at?->toIso8601String(),
                ])
                ->all();

            return ['items' => $items, 'total' => count($items)];
        });
    }
}
