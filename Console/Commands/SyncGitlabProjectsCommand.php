<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabConnectionService;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabProjectSyncService;

/**
 * 手动同步 GitLab 项目投影。
 *
 * dry-run 只统计本地已有投影，不调用 GitLab（需求 §9.6）。
 */
class SyncGitlabProjectsCommand extends Command
{
    protected $signature = 'gitlab:sync-projects
        {--tenant= : 租户 ID（必填）}
        {--connection= : GitLab 连接 ID（缺省取租户第一条启用连接）}
        {--dry-run : 只统计不调用 GitLab、不写投影、不推进游标}';

    protected $description = '同步 GitLab 项目投影（只读，不修改 GitLab 仓库）';

    public function handle(GitlabProjectSyncService $sync, GitlabConnectionService $connections): int
    {
        $tenant = trim((string) $this->option('tenant'));

        if ($tenant === '' || ! ctype_digit($tenant)) {
            $this->error('--tenant 必须是正整数租户 ID');

            return self::FAILURE;
        }

        $tenantId = (int) $tenant;
        $connectionOption = trim((string) $this->option('connection'));

        if ($connectionOption !== '' && ! ctype_digit($connectionOption)) {
            $this->error('--connection 必须是正整数连接 ID');

            return self::FAILURE;
        }

        try {
            $stats = TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionOption, $sync, $connections): array {
                $connectionId = $connectionOption !== ''
                    ? (int) $connectionOption
                    : (int) $connections->resolveForTenant($tenantId)->connection_id;

                return $sync->sync($tenantId, $connectionId, (bool) $this->option('dry-run'));
            });
        } catch (\Throwable $e) {
            $this->error('同步失败：' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(['指标', '值'], [
            ['模式', $stats['dry_run'] ? 'dry-run' : 'write'],
            ['连接', $stats['connection_id']],
            ['已有投影', $stats['existing']],
            ['读取', $stats['fetched']],
            ['写入/更新', $stats['upserted']],
            ['已映射', $stats['mapped']],
            ['错误', $stats['error'] ?? '-'],
        ]);

        if ($stats['error'] !== null) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
