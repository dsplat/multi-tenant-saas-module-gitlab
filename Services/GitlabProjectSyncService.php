<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Gitlab\Models\GitlabProject;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabApiException;
use MultiTenantSaas\Modules\Integration\Services\ExternalMappingService;
use MultiTenantSaas\Modules\Integration\Services\IntegrationSyncCursorService;

/**
 * 项目投影同步（gitlab:sync-projects）。
 *
 * 只写本地投影，不修改 GitLab；dry-run 不调用外部 API（见需求 §9.6）。
 */
class GitlabProjectSyncService
{
    private const CURSOR_RESOURCE = 'gitlab.projects';

    public function __construct(
        private readonly GitlabConnectionService $connections,
        private readonly GitlabClientFactory $clients,
        private readonly ExternalMappingService $mappings,
        private readonly IntegrationSyncCursorService $cursors,
        private readonly GitlabProjectService $projects,
    ) {}

    /**
     * @return array{dry_run: bool, connection_id: int, fetched: int, upserted: int, mapped: int, existing: int, error: string|null}
     */
    public function sync(int $tenantId, int $connectionId, bool $dryRun = false): array
    {
        $connection = $this->connections->find($tenantId, $connectionId);
        $existing = GitlabProject::query()
            ->where('tenant_id', $tenantId)
            ->where('connection_id', $connectionId)
            ->count();

        if ($dryRun) {
            return [
                'dry_run' => true,
                'connection_id' => $connectionId,
                'fetched' => 0,
                'upserted' => 0,
                'mapped' => 0,
                'existing' => $existing,
                'error' => null,
            ];
        }

        $fetched = 0;
        $upserted = 0;
        $mapped = 0;
        $error = null;

        try {
            $client = $this->clients->forConnection($connection);
            $perPage = max(1, min(100, (int) config('gitlab.projects.per_page', 100)));
            $maxPages = max(1, (int) config('gitlab.projects.max_pages', 20));

            for ($page = 1; $page <= $maxPages; $page++) {
                $batch = $client->listProjects([
                    'membership' => 'true',
                    'simple' => 'true',
                    'per_page' => $perPage,
                    'page' => $page,
                    'order_by' => 'id',
                    'sort' => 'asc',
                ]);

                if ($batch === []) {
                    break;
                }

                foreach ($batch as $remote) {
                    if (! is_array($remote) || ! isset($remote['id'])) {
                        continue;
                    }

                    $fetched++;
                    $project = $this->projects->upsertProjection($tenantId, $connectionId, $remote);

                    if ($project === null) {
                        continue;
                    }

                    $upserted++;

                    if ($this->mappings->resolveBySource($tenantId, GitlabConnectionService::PROVIDER, 'project', (string) $remote['id']) !== null) {
                        $mapped++;
                    }
                }

                if (count($batch) < $perPage) {
                    break;
                }
            }

            $this->cursors->advance($tenantId, $connectionId, self::CURSOR_RESOURCE, (string) now(), [
                'fetched' => $fetched,
                'upserted' => $upserted,
            ]);
        } catch (GitlabApiException $e) {
            $error = $e->getMessage();
            $this->connections->markChecked($tenantId, $connectionId, $error);

            Log::warning('gitlab.projects.sync_failed', [
                'tenant_id' => $tenantId,
                'connection_id' => $connectionId,
                'status' => $e->status,
            ]);
        }

        return [
            'dry_run' => false,
            'connection_id' => $connectionId,
            'fetched' => $fetched,
            'upserted' => $upserted,
            'mapped' => $mapped,
            'existing' => $existing,
            'error' => $error,
        ];
    }
}
