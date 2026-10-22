<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use Illuminate\Support\Arr;

/**
 * GitLab webhook payload 归一化（P0：Push / Tag Push / Merge Request / Pipeline）。
 *
 * 只读取需要的事实字段，不把原始 payload 复制进投影表；任务号从源分支、
 * MR 标题/描述、commit message 提取。
 */
class GitlabEventNormalizer
{
    private const TERMINAL_PIPELINE_STATUSES = ['success', 'failed', 'canceled', 'skipped'];

    public function __construct(private readonly GitlabTaskKeyExtractor $taskKeys) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     event_type: string,
     *     object_kind: string,
     *     external_project_id: string|null,
     *     mr_iid: int|null,
     *     pipeline_id: int|null,
     *     status: string|null,
     *     task_keys: list<string>,
     *     occurred_at: string,
     *     updated_at: string|null,
     *     url: string|null,
     *     pipeline_ref: string|null,
     *     tag: string|null,
     *     commit: string|null,
     *     project: array<string, mixed>,
     *     dispatch: list<string>
     * }
     */
    public function normalize(array $payload, string $headerEventType): array
    {
        $kind = $this->objectKind($payload, $headerEventType);
        $project = is_array($payload['project'] ?? null) ? $payload['project'] : [];
        $updatedAt = null;
        $occurredAt = null;
        $mrIid = null;
        $pipelineId = null;
        $status = null;
        $url = null;
        $pipelineRef = null;
        $tag = null;
        $commit = null;
        $sourceBranch = null;
        $targetBranch = null;
        $sources = [];
        $dispatch = [];

        switch ($kind) {
            case 'merge_request':
                $attributes = is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];
                $mrIid = $this->toInt($attributes['iid'] ?? null);
                $action = (string) ($attributes['action'] ?? '');
                $state = (string) ($attributes['state'] ?? '');
                $status = $action !== '' ? $action : ($state !== '' ? $state : null);
                $updatedAt = $this->toString($attributes['updated_at'] ?? null);
                $occurredAt = $updatedAt;
                $url = $this->toString($attributes['url'] ?? null);
                $sourceBranch = $this->toString($attributes['source_branch'] ?? null);
                $targetBranch = $this->toString($attributes['target_branch'] ?? null);
                $sources = [
                    $sourceBranch,
                    $this->toString($attributes['title'] ?? null),
                    $this->toString($attributes['description'] ?? null),
                    $this->toString(Arr::get($attributes, 'last_commit.message')),
                ];
                $dispatch = [$this->mergeRequestDispatch($action, $state)];
                break;

            case 'pipeline':
                $attributes = is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];
                $pipelineId = $this->toInt($attributes['id'] ?? null);
                $status = $this->toString($attributes['status'] ?? null);
                $updatedAt = $this->toString($attributes['updated_at'] ?? null) ?? $this->toString($attributes['created_at'] ?? null);
                $occurredAt = $updatedAt;
                $url = $this->toString($attributes['url'] ?? null);
                $pipelineRef = $this->toString($attributes['ref'] ?? null);
                $commit = $this->toString($attributes['sha'] ?? null) ?? $this->toString(Arr::get($payload, 'commit.id'));
                $sources = [
                    $this->toString($attributes['ref'] ?? null),
                    $this->toString(Arr::get($payload, 'commit.message')),
                    $this->toString(Arr::get($attributes, 'commit.message')),
                ];

                if ($status !== null && in_array($status, self::TERMINAL_PIPELINE_STATUSES, true)) {
                    $dispatch = ['pipeline.completed'];
                }
                break;

            case 'tag_push':
                $ref = $this->toString($payload['ref'] ?? null) ?? '';
                $tag = $this->tagName($ref);
                $commit = $this->toString($payload['checkout_sha'] ?? null)
                    ?? $this->toString($payload['after'] ?? null);
                $sources = array_merge(
                    [$tag],
                    $this->commitMessages($payload),
                );
                $updatedAt = $this->toString(Arr::get($payload, 'commits.0.timestamp'));
                $occurredAt = $updatedAt;
                $dispatch = ['tag.pushed'];
                break;

            case 'push':
                $ref = $this->toString($payload['ref'] ?? null) ?? '';
                $commit = $this->toString($payload['checkout_sha'] ?? null)
                    ?? $this->toString($payload['after'] ?? null);
                $sources = array_merge(
                    [$ref !== '' ? preg_replace('#^refs/heads/#', '', $ref) : null],
                    $this->commitMessages($payload),
                );
                $updatedAt = $this->toString(Arr::get($payload, 'commits.0.timestamp'));
                $occurredAt = $updatedAt;
                break;
        }

        $status = $status !== null ? mb_substr($status, 0, 30) : null;

        return [
            'event_type' => 'gitlab.' . $kind,
            'object_kind' => $kind,
            'external_project_id' => $this->toIdString($project['id'] ?? null),
            'mr_iid' => $mrIid,
            'pipeline_id' => $pipelineId,
            'status' => $status,
            'task_keys' => $this->taskKeys->extract($sources),
            'occurred_at' => $occurredAt ?? now()->toIso8601String(),
            'updated_at' => $updatedAt,
            'url' => $url,
            'pipeline_ref' => $pipelineRef,
            'source_branch' => $sourceBranch,
            'target_branch' => $targetBranch,
            'tag' => $tag,
            'commit' => $commit,
            'project' => [
                'id' => $this->toIdString($project['id'] ?? null),
                'path_with_namespace' => $this->toString($project['path_with_namespace'] ?? null),
                'name' => $this->toString($project['name'] ?? null),
                'default_branch' => $this->toString($project['default_branch'] ?? null),
                'visibility' => $this->toString($project['visibility'] ?? null),
                'web_url' => $this->toString($project['web_url'] ?? null),
            ],
            'dispatch' => array_values(array_unique($dispatch)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function objectKind(array $payload, string $headerEventType): string
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

    private function mergeRequestDispatch(string $action, string $state): string
    {
        return match ($action !== '' ? $action : $state) {
            'open', 'opened' => 'mr.opened',
            'merge', 'merged' => 'mr.merged',
            'close', 'closed' => 'mr.closed',
            'update', 'updated', 'reopen', 'reopened' => 'mr.updated',
            default => 'mr.updated',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string|null>
     */
    private function commitMessages(array $payload): array
    {
        $messages = [];

        foreach ((array) ($payload['commits'] ?? []) as $commit) {
            if (is_array($commit) && isset($commit['message'])) {
                $messages[] = (string) $commit['message'];
            }
        }

        return $messages;
    }

    private function tagName(string $ref): ?string
    {
        $ref = trim($ref);

        if ($ref === '') {
            return null;
        }

        return preg_replace('#^refs/tags/#', '', $ref);
    }

    private function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function toIdString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function toString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
