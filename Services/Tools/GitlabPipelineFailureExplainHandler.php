<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services\Tools;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabProject;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabWebhookEvent;
use MultiTenantSaas\Modules\Gitlab\Support\SensitiveText;

/**
 * L1 只读：解释 pipeline 失败（只给事实与脱敏摘要）。
 *
 * P0 不读取 job 完整日志；任何进入结果的错误文本都先脱敏、再截断。
 */
class GitlabPipelineFailureExplainHandler implements ToolHandlerContract
{
    private const LOG_EXCERPT_LIMIT = 1000;

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $pipelineId = (int) ($arguments['pipeline_id'] ?? 0);

        if ($pipelineId <= 0) {
            return ['found' => false, 'error' => 'pipeline_id 必须是正整数'];
        }

        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $pipelineId): array {
            $event = GitlabWebhookEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('pipeline_id', $pipelineId)
                ->where(function ($query): void {
                    $query->where('event_type', GitlabWebhookEvent::EVENT_PIPELINE)
                        ->orWhere('object_kind', 'pipeline');
                })
                ->orderByDesc('received_at')
                ->orderByDesc('gitlab_event_id')
                ->first();

            if (! $event instanceof GitlabWebhookEvent) {
                return ['found' => false, 'pipeline_id' => $pipelineId, 'message' => '尚无该 pipeline 的 webhook 投影'];
            }

            $project = $event->external_project_id === null ? null : GitlabProject::query()
                ->where('tenant_id', $tenantId)
                ->where('external_project_id', $event->external_project_id)
                ->first();

            $state = (array) (($project?->metadata ?? [])['pipeline_state'] ?? []);
            $status = (string) ($event->status ?? '');
            $excerpt = $this->sanitize($event->error);

            return [
                'found' => true,
                'pipeline_id' => $pipelineId,
                'status' => $status,
                'failed' => in_array($status, ['failed', 'canceled'], true),
                'project' => $project?->path_with_namespace,
                'external_project_id' => $event->external_project_id,
                'ref' => $state['ref'] ?? null,
                'url' => $state['url'] ?? null,
                'task_keys' => array_values((array) $event->task_keys),
                'explanation' => $this->explain($status),
                'failure_excerpt' => $excerpt,
                'log_truncated' => $excerpt !== null,
                'note' => 'P0 不读取 job 完整日志；请到 GitLab pipeline 页面查看失败 job。',
            ];
        });
    }

    private function explain(string $status): string
    {
        return match ($status) {
            'failed' => '流水线以失败结束，通常由某个 job 失败导致；平台只投影事实，不做门禁判定。',
            'canceled' => '流水线被取消，非代码失败结论。',
            'success' => '流水线成功结束。',
            'skipped' => '流水线被跳过。',
            default => '流水线状态非终态或未知，请稍后重试或查看 GitLab。',
        };
    }

    private function sanitize(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return SensitiveText::redact(mb_substr($text, 0, self::LOG_EXCERPT_LIMIT));
    }
}
