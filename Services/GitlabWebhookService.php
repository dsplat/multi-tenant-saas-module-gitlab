<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabMergeRequestClosed;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabMergeRequestMerged;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabMergeRequestOpened;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabMergeRequestUpdated;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabPipelineCompleted;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabTagPushed;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabWebhookFailed;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabWebhookReceived;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabProject;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabWebhookEvent;
use MultiTenantSaas\Modules\Gitlab\Support\SensitiveText;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Integration\Models\IntegrationInboxEvent;
use MultiTenantSaas\Modules\Integration\Services\IntegrationInboxService;
use MultiTenantSaas\Scopes\TenantScope;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GitLab webhook 归一化、幂等投影与重放。
 *
 * 顺序：
 *   验签 → 落 Integration 收件箱（唯一键幂等）→ 归一化投影（inbox_event_id 唯一）
 *   → 派发领域事件；失败只记录脱敏 error 并派发 GitlabWebhookFailed。
 *
 * 乱序：MR 以 object_attributes.updated_at、pipeline 以自增 pipeline ID 判新旧，
 * 旧事件不覆盖项目 metadata 中的最新状态，也不派发对应的领域事件。
 */
class GitlabWebhookService
{
    public function __construct(
        private readonly IntegrationInboxService $inbox,
        private readonly GitlabEventNormalizer $normalizer,
    ) {}

    /**
     * 公开 webhook 入口按连接 ID 找连接（无租户上下文）。
     *
     * 该查询绕过 TenantScope：tenant_id 随后由连接记录本身提供，
     * 未拿到连接前不存在可用的租户上下文。
     */
    public function findPublicConnection(string $connectionId): ?IntegrationConnection
    {
        if ($connectionId === '' || ! ctype_digit($connectionId)) {
            return null;
        }

        $connection = IntegrationConnection::withoutGlobalScope(TenantScope::class)
            ->where('provider', GitlabConnectionService::PROVIDER)
            ->where('connection_id', $connectionId)
            ->first();

        if (! $connection || $connection->status !== IntegrationConnection::STATUS_ACTIVE) {
            return null;
        }

        return $connection;
    }

    public function verifyToken(IntegrationConnection $connection, ?string $provided): bool
    {
        $credentials = (array) ($connection->credentials ?? []);
        $secret = (string) ($credentials['webhook_secret'] ?? '');
        $provided = (string) $provided;

        if ($secret === '' || $provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(array $payload, string $headerEventType): string
    {
        return 'gitlab.' . $this->kind($payload, $headerEventType);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordInbox(
        int $tenantId,
        int $connectionId,
        string $externalEventId,
        string $eventType,
        array $payload,
    ): IntegrationInboxEvent {
        return $this->inbox->record($tenantId, $connectionId, $externalEventId, $eventType, $payload);
    }

    /**
     * 处理一条收件箱事件；重复投递直接返回既有投影（不重复派发）。
     */
    public function process(int $tenantId, int $inboxEventId, bool $replay = false): ?GitlabWebhookEvent
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $inboxEventId, $replay): ?GitlabWebhookEvent {
            $inbox = IntegrationInboxEvent::query()
                ->where('tenant_id', $tenantId)
                ->find($inboxEventId);

            if (! $inbox) {
                return null;
            }

            $event = GitlabWebhookEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('inbox_event_id', $inboxEventId)
                ->first();

            if ($event !== null && ! $replay) {
                if ($inbox->status !== IntegrationInboxEvent::STATUS_PROCESSED) {
                    $this->inbox->markProcessed($tenantId, $inboxEventId);
                }

                return $event;
            }

            $event ??= new GitlabWebhookEvent([
                'tenant_id' => $tenantId,
                'connection_id' => (int) $inbox->connection_id,
                'inbox_event_id' => (int) $inbox->getKey(),
                'event_type' => (string) $inbox->event_type,
                'received_at' => $inbox->received_at ?? now(),
            ]);

            try {
                $payload = is_array($inbox->payload) ? $inbox->payload : [];
                $normalized = $this->normalizer->normalize($payload, (string) $inbox->event_type);
                $project = $this->ensureProject($tenantId, (int) $inbox->connection_id, $normalized['project']);
                $stale = $this->applyState($project, $normalized);

                $event->fill([
                    'external_project_id' => $normalized['external_project_id'],
                    'project_id' => $project?->project_id,
                    'event_type' => $normalized['event_type'],
                    'object_kind' => $normalized['object_kind'],
                    'mr_iid' => $normalized['mr_iid'],
                    'pipeline_id' => $normalized['pipeline_id'],
                    'status' => $normalized['status'],
                    'task_keys' => $normalized['task_keys'],
                    'received_at' => $inbox->received_at ?? now(),
                    'processed_at' => now(),
                    'error' => null,
                ]);

                if ($replay) {
                    $event->replay_count = (int) $event->replay_count + 1;
                    $event->replayed_at = now();
                }

                $event->save();
                $this->inbox->markProcessed($tenantId, $inboxEventId);

                $this->dispatchDomainEvents($event, $normalized, $stale, $replay);

                return $event->refresh();
            } catch (\Throwable $e) {
                $error = $this->errorSummary($e);
                $event->error = $error;
                $event->processed_at = now();
                $event->save();

                $this->inbox->markFailed($tenantId, $inboxEventId, $error);

                Log::warning('gitlab.webhook.processing_failed', [
                    'tenant_id' => $tenantId,
                    'connection_id' => (int) $inbox->connection_id,
                    'inbox_event_id' => $inboxEventId,
                    'error' => $e::class,
                ]);

                Event::dispatch(new GitlabWebhookFailed(
                    tenantId: $tenantId,
                    connectionId: (int) $inbox->connection_id,
                    eventId: (int) $event->getKey(),
                    inboxEventId: $inboxEventId,
                    eventType: (string) $inbox->event_type,
                    error: $error,
                ));

                return $event->refresh();
            }
        });
    }

    /**
     * 重放失败事件；成功重放后 error 清空，再次重放会 409（幂等收口）。
     */
    public function replay(int $tenantId, int $eventId): GitlabWebhookEvent
    {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $eventId): GitlabWebhookEvent {
            $event = GitlabWebhookEvent::query()
                ->where('tenant_id', $tenantId)
                ->find($eventId);

            if (! $event) {
                throw new NotFoundHttpException('GitLab 事件不存在');
            }

            if ($event->processed_at !== null && $event->error === null) {
                throw new ConflictHttpException('仅失败事件支持重放');
            }

            $replayed = $this->process($tenantId, (int) $event->inbox_event_id, true);

            if ($replayed === null) {
                throw new NotFoundHttpException('GitLab 事件对应的收件箱记录不存在');
            }

            return $replayed;
        });
    }

    /**
     * @param  array<string, mixed>  $projectMeta
     */
    private function ensureProject(int $tenantId, int $connectionId, array $projectMeta): ?GitlabProject
    {
        $externalId = isset($projectMeta['id']) && is_scalar($projectMeta['id']) ? (string) $projectMeta['id'] : '';

        if ($externalId === '') {
            return null;
        }

        $project = GitlabProject::query()
            ->where('tenant_id', $tenantId)
            ->where('external_project_id', $externalId)
            ->first();

        if ($project === null) {
            $project = new GitlabProject([
                'tenant_id' => $tenantId,
                'connection_id' => $connectionId,
                'external_project_id' => $externalId,
            ]);
        }

        $project->connection_id = $connectionId;

        foreach (['path_with_namespace', 'name', 'default_branch', 'visibility'] as $key) {
            $value = $projectMeta[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $project->{$key} = $value;
            }
        }

        $metadata = (array) ($project->metadata ?? []);
        $metadata['last_webhook_at'] = now()->toIso8601String();
        $project->metadata = $metadata;
        $project->save();

        return $project;
    }

    /**
     * 应用对象状态投影；返回 true 表示旧事件（乱序）已被忽略。
     *
     * @param  array<string, mixed>  $normalized
     */
    private function applyState(?GitlabProject $project, array $normalized): bool
    {
        if ($project === null) {
            return false;
        }

        $metadata = (array) ($project->metadata ?? []);
        $stale = false;

        if ($normalized['object_kind'] === 'merge_request' && $normalized['mr_iid'] !== null) {
            $iid = (string) $normalized['mr_iid'];
            $current = (array) ($metadata['mr_state'][$iid] ?? []);
            $currentAt = $this->timestamp($current['updated_at'] ?? null);
            $incomingAt = $this->timestamp($normalized['updated_at'] ?? null);

            if ($currentAt !== null && $incomingAt !== null && $incomingAt < $currentAt) {
                $stale = true;
            } else {
                $metadata['mr_state'][$iid] = [
                    'status' => $normalized['status'],
                    'source_branch' => $normalized['source_branch'] ?? null,
                    'target_branch' => $normalized['target_branch'] ?? null,
                    'updated_at' => $normalized['updated_at'] ?? $normalized['occurred_at'],
                    'url' => $normalized['url'],
                ];
            }
        }

        if ($normalized['object_kind'] === 'pipeline' && $normalized['pipeline_id'] !== null) {
            $incomingId = (int) $normalized['pipeline_id'];
            $current = (array) ($metadata['pipeline_state'] ?? []);
            $latestId = (int) ($current['latest_id'] ?? 0);

            if ($latestId > 0 && $incomingId < $latestId) {
                $stale = true;
            } else {
                $metadata['pipeline_state'] = [
                    'latest_id' => $incomingId,
                    'status' => $normalized['status'],
                    'ref' => $normalized['pipeline_ref'] ?? null,
                    'updated_at' => $normalized['occurred_at'],
                    'url' => $normalized['url'],
                ];
            }
        }

        if ($normalized['object_kind'] === 'tag_push') {
            $tag = $normalized['tag'] ?? null;

            if (is_string($tag) && $tag !== '') {
                $tags = (array) ($metadata['tags'] ?? []);
                $tags[$tag] = [
                    'at' => $normalized['occurred_at'],
                ];
                $metadata['tags'] = array_slice($tags, -50, null, true);
            }
        }

        $project->metadata = $metadata;
        $project->save();

        return $stale;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function dispatchDomainEvents(GitlabWebhookEvent $event, array $normalized, bool $stale, bool $replay): void
    {
        $common = [
            'tenantId' => (int) $event->tenant_id,
            'connectionId' => (int) $event->connection_id,
            'eventId' => (int) $event->getKey(),
        ];

        Event::dispatch(new GitlabWebhookReceived(
            tenantId: $common['tenantId'],
            connectionId: $common['connectionId'],
            eventId: $common['eventId'],
            inboxEventId: (int) $event->inbox_event_id,
            eventType: (string) $event->event_type,
            externalProjectId: $event->external_project_id,
            mrIid: $event->mr_iid,
            pipelineId: $event->pipeline_id,
            status: $event->status,
            taskKeys: (array) $event->task_keys,
            receivedAt: (string) $event->received_at,
        ));

        if ($stale) {
            return;
        }

        foreach ($normalized['dispatch'] as $dispatch) {
            if (str_starts_with((string) $dispatch, 'mr.') && $normalized['mr_iid'] === null) {
                continue;
            }

            if ($dispatch === 'pipeline.completed' && $normalized['pipeline_id'] === null) {
                continue;
            }

            if ($dispatch === 'tag.pushed' && ($normalized['tag'] ?? null) === null) {
                continue;
            }

            match ($dispatch) {
                'mr.opened' => Event::dispatch(new GitlabMergeRequestOpened(
                    ...$common,
                    externalProjectId: $normalized['external_project_id'],
                    mrIid: (int) $normalized['mr_iid'],
                    status: (string) $normalized['status'],
                    taskKeys: $normalized['task_keys'],
                    occurredAt: $normalized['occurred_at'],
                    url: $normalized['url'],
                )),
                'mr.updated' => Event::dispatch(new GitlabMergeRequestUpdated(
                    ...$common,
                    externalProjectId: $normalized['external_project_id'],
                    mrIid: (int) $normalized['mr_iid'],
                    status: (string) $normalized['status'],
                    taskKeys: $normalized['task_keys'],
                    occurredAt: $normalized['occurred_at'],
                    url: $normalized['url'],
                )),
                'mr.merged' => Event::dispatch(new GitlabMergeRequestMerged(
                    ...$common,
                    externalProjectId: $normalized['external_project_id'],
                    mrIid: (int) $normalized['mr_iid'],
                    status: (string) $normalized['status'],
                    taskKeys: $normalized['task_keys'],
                    occurredAt: $normalized['occurred_at'],
                    url: $normalized['url'],
                )),
                'mr.closed' => Event::dispatch(new GitlabMergeRequestClosed(
                    ...$common,
                    externalProjectId: $normalized['external_project_id'],
                    mrIid: (int) $normalized['mr_iid'],
                    status: (string) $normalized['status'],
                    taskKeys: $normalized['task_keys'],
                    occurredAt: $normalized['occurred_at'],
                    url: $normalized['url'],
                )),
                'pipeline.completed' => Event::dispatch(new GitlabPipelineCompleted(
                    ...$common,
                    externalProjectId: $normalized['external_project_id'],
                    pipelineId: (int) $normalized['pipeline_id'],
                    status: (string) $normalized['status'],
                    ref: $normalized['pipeline_ref'] ?? null,
                    taskKeys: $normalized['task_keys'],
                    occurredAt: $normalized['occurred_at'],
                    url: $normalized['url'],
                )),
                'tag.pushed' => Event::dispatch(new GitlabTagPushed(
                    ...$common,
                    externalProjectId: $normalized['external_project_id'],
                    tag: (string) ($normalized['tag'] ?? ''),
                    commit: $normalized['commit'] ?? null,
                    taskKeys: $normalized['task_keys'],
                    occurredAt: $normalized['occurred_at'],
                )),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function kind(array $payload, string $headerEventType): string
    {
        $kind = strtolower(trim((string) ($payload['object_kind'] ?? '')));

        if ($kind !== '') {
            return $kind;
        }

        return match ($headerEventType) {
            'Merge Request Hook' => 'merge_request',
            'Tag Push Hook' => 'tag_push',
            'Pipeline Hook' => 'pipeline',
            'Push Hook' => 'push',
            default => 'unknown',
        };
    }

    private function errorSummary(\Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return '数据库写入失败';
        }

        return SensitiveText::redact(mb_substr($e->getMessage(), 0, 500));
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }
}
