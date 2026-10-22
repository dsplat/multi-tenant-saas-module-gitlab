<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabProject;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabApiException;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Integration\Services\ExternalMappingService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GitLab 项目投影与映射（GitLab project ID 为不可变外部键）。
 *
 * 映射统一落 Integration `external_mappings`：
 *   project     → 宿主 project_id
 *   doc_project → 宿主 archive_scope_id
 */
class GitlabProjectService
{
    public function __construct(
        private readonly GitlabConnectionService $connections,
        private readonly GitlabClientFactory $clients,
        private readonly ExternalMappingService $mappings,
    ) {}

    /**
     * 创建或接管文档空间；同名/path 已存在但无映射时 409，要求人工确认接管。
     *
     * @param  array<string, mixed>  $data
     * @return array{project: GitlabProject, created: bool, adopted: bool, mapping_error: bool}
     */
    public function createOrAdopt(int $tenantId, array $data): array
    {
        $connection = $this->connections->find($tenantId, (int) $data['connection_id']);
        $this->connections->assertWritable($connection);
        $client = $this->clients->forConnection($connection);

        $externalId = isset($data['external_project_id']) ? trim((string) $data['external_project_id']) : '';

        if ($externalId !== '') {
            try {
                $remote = $client->getProject($externalId);
            } catch (GitlabApiException $e) {
                if ($e->status === 404) {
                    throw new NotFoundHttpException('GitLab 项目不存在');
                }

                throw $e;
            }

            return $this->persist($tenantId, $connection, $remote, $data, false, true);
        }

        $path = trim((string) ($data['path'] ?? ''));

        if ($path === '') {
            throw new ConflictHttpException('创建文档空间必须提供 path');
        }

        $existing = GitlabProject::query()
            ->where('tenant_id', $tenantId)
            ->where('path_with_namespace', $path)
            ->first();

        if ($existing !== null) {
            $mapping = $this->mappings->resolveBySource(
                $tenantId,
                GitlabConnectionService::PROVIDER,
                'project',
                $existing->external_project_id,
            );

            if ($mapping === null) {
                throw new ConflictHttpException('同名项目已存在但未建立映射，请人工确认接管');
            }

            if (isset($data['project_id']) && (int) $mapping->local_id !== (int) $data['project_id']) {
                throw new ConflictHttpException('该 GitLab 项目已映射到其他宿主项目');
            }

            return [
                'project' => $existing,
                'created' => false,
                'adopted' => false,
                'mapping_error' => false,
            ];
        }

        try {
            $remote = $client->createProject([
                'name' => (string) ($data['name'] ?? ''),
                'path' => $path,
                'description' => (string) ($data['description'] ?? ''),
                'visibility' => (string) ($data['visibility'] ?? config('gitlab.projects.visibility', 'private')),
                'default_branch' => (string) ($data['default_branch'] ?? config('gitlab.docs.default_branch', 'main')),
                'initialize_with_readme' => (bool) ($data['initialize_with_readme'] ?? config('gitlab.projects.initialize_with_readme', true)),
            ]);
        } catch (GitlabApiException $e) {
            if (in_array($e->status, [400, 409], true)) {
                throw new ConflictHttpException('GitLab 项目创建冲突，请人工确认接管');
            }

            throw $e;
        }

        return $this->persist($tenantId, $connection, $remote, $data, true, false);
    }

    /**
     * 写入/刷新项目投影（webhook 与同步共用），保留已有 metadata 状态投影。
     *
     * @param  array<string, mixed>  $remote
     */
    public function upsertProjection(int $tenantId, int $connectionId, array $remote): ?GitlabProject
    {
        $externalId = isset($remote['id']) ? (string) $remote['id'] : '';

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

        $project->fill(array_filter([
            'path_with_namespace' => $this->stringOrNull($remote['path_with_namespace'] ?? null),
            'name' => $this->stringOrNull($remote['name'] ?? null),
            'default_branch' => $this->stringOrNull($remote['default_branch'] ?? null),
            'visibility' => $this->stringOrNull($remote['visibility'] ?? null),
        ], static fn ($value) => $value !== null));

        $project->connection_id = $connectionId;
        $project->last_synced_at = now();
        $project->save();

        return $project->refresh();
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $data
     * @return array{project: GitlabProject, created: bool, adopted: bool, mapping_error: bool}
     */
    private function persist(
        int $tenantId,
        IntegrationConnection $connection,
        array $remote,
        array $data,
        bool $created,
        bool $adopted,
    ): array {
        $externalId = (string) $remote['id'];
        $project = $this->upsertProjection($tenantId, (int) $connection->getKey(), $remote);

        if ($project === null) {
            throw new ConflictHttpException('GitLab 返回的项目 ID 非法');
        }

        $mappingError = false;

        try {
            if (isset($data['project_id']) && (int) $data['project_id'] > 0) {
                $this->assertMappingTarget($tenantId, 'project', $externalId, (int) $data['project_id']);

                $this->mappings->upsert(
                    $tenantId,
                    GitlabConnectionService::PROVIDER,
                    'project',
                    $externalId,
                    'project',
                    (int) $data['project_id'],
                );
            }

            if (isset($data['archive_scope_id']) && (int) $data['archive_scope_id'] > 0) {
                $this->assertMappingTarget($tenantId, 'doc_project', $externalId, (int) $data['archive_scope_id']);

                $this->mappings->upsert(
                    $tenantId,
                    GitlabConnectionService::PROVIDER,
                    'doc_project',
                    $externalId,
                    'archive_scope',
                    (int) $data['archive_scope_id'],
                );
            }
        } catch (ConflictHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 项目已创建但映射写入失败：记录孤儿项目 ID，供重试补齐；不自动删除项目
            $mappingError = true;
            $metadata = (array) ($project->metadata ?? []);
            $metadata['mapping_error'] = true;
            $metadata['orphan_external_project_id'] = $externalId;
            $project->metadata = $metadata;
            $project->save();

            Log::warning('gitlab.project.mapping_failed', [
                'tenant_id' => $tenantId,
                'connection_id' => (int) $connection->getKey(),
                'external_project_id' => $externalId,
                'error' => $e::class,
            ]);
        }

        return [
            'project' => $project->refresh(),
            'created' => $created,
            'adopted' => $adopted,
            'mapping_error' => $mappingError,
        ];
    }

    private function assertMappingTarget(int $tenantId, string $sourceType, string $externalId, int $localId): void
    {
        $mapping = $this->mappings->resolveBySource(
            $tenantId,
            GitlabConnectionService::PROVIDER,
            $sourceType,
            $externalId,
        );

        if ($mapping !== null && (int) $mapping->local_id !== $localId) {
            throw new ConflictHttpException('该 GitLab 项目已映射到其他本地对象，请先人工解除映射');
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
