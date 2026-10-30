<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Gitlab\Contracts\GitlabClientContract;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabApiException;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabUrlException;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use Throwable;

/**
 * GitLab 连接能力探针（E4：read / sync / move / write 分项）。
 *
 * 只做读取与前置条件预检，绝不执行移动/写入：
 * - read：GET project（doc_project_id）；
 * - sync：repositoryFileList(doc_project_id, 根路径, default_branch) 一次调用，
 *   验证仓库树/分页契约可用（客户端内部按自身页数/条数/时间上限收敛）；
 * - move：仅预检「源存在、目标不存在」（两次 repositoryFileGet），
 *   不做 rename、不发 PUT；只返回 ok（预检通过）或 skipped（缺配置/
 *   预检未通过/上游不可用，detail 给脱敏原因），不把 move 记为执行失败；
 * - write：固定 skipped / g3_not_authorized（夜间授权禁止真实写探针）。
 *
 * 安全：
 * - 结果显式带 provider / tenant_id / connection_id / checked_at / writes_performed；
 * - 失败 detail 只写脱敏 reason（HTTP 状态映射/客户端稳定 reason），
 *   不含 token、URL 查询串或响应体；
 * - 当前租户上下文与连接 tenant_id 不一致时 fail closed，不构造客户端、不发请求。
 *
 * 可选连接 config：
 * - `move_probe_source_path` / `move_probe_target_path`：move 预检的源/目标路径；
 *   未配置时 move 为 skipped（不会自行猜测业务路径）。
 */
class GitlabCapabilityProbe
{
    public const CAPABILITY_READ = 'read';

    public const CAPABILITY_SYNC = 'sync';

    public const CAPABILITY_MOVE = 'move';

    public const CAPABILITY_WRITE = 'write';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const DETAIL_WRITE_SKIPPED = 'g3_not_authorized';

    public const DETAIL_TENANT_MISMATCH = 'tenant_mismatch';

    public const DETAIL_MOVE_PATH_MISSING = 'move_paths_not_configured';

    public function __construct(
        private readonly GitlabClientFactory $clients,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     tenant_id: int,
     *     connection_id: int,
     *     checked_at: string,
     *     writes_performed: bool,
     *     capabilities: list<array{capability: string, status: string, detail: string}>
     * }
     */
    public function probe(IntegrationConnection $connection): array
    {
        if ($this->tenantMismatch($connection)) {
            return $this->envelope($connection, [
                $this->item(self::CAPABILITY_READ, self::STATUS_FAILED, self::DETAIL_TENANT_MISMATCH),
                $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, self::DETAIL_TENANT_MISMATCH),
                $this->item(self::CAPABILITY_MOVE, self::STATUS_SKIPPED, self::DETAIL_TENANT_MISMATCH),
                $this->writeSkipped(),
            ]);
        }

        try {
            $client = $this->clients->forConnection($connection);
        } catch (Throwable $e) {
            // 连接本身不可用（token 缺失/base_url 被 SSRF 守卫拒绝）：
            // 逐能力给脱敏 reason，move 因无法预检记为 skipped。
            $reason = $this->safeReason($e);

            return $this->envelope($connection, [
                $this->item(self::CAPABILITY_READ, self::STATUS_FAILED, $reason),
                $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, $reason),
                $this->item(self::CAPABILITY_MOVE, self::STATUS_SKIPPED, $reason),
                $this->writeSkipped(),
            ]);
        }

        return $this->envelope($connection, [
            $this->probeRead($client, $connection),
            $this->probeSync($client, $connection),
            $this->probeMove($client, $connection),
            $this->writeSkipped(),
        ]);
    }

    /**
     * @return array{capability: string, status: string, detail: string}
     */
    private function probeRead(GitlabClientContract $client, IntegrationConnection $connection): array
    {
        $projectId = $this->projectId($connection);

        if ($projectId === null) {
            return $this->item(self::CAPABILITY_READ, self::STATUS_FAILED, 'project_not_configured');
        }

        try {
            $client->getProject($projectId);

            return $this->item(self::CAPABILITY_READ, self::STATUS_OK, 'project_readable');
        } catch (Throwable $e) {
            return $this->item(self::CAPABILITY_READ, self::STATUS_FAILED, $this->safeReason($e));
        }
    }

    /**
     * @return array{capability: string, status: string, detail: string}
     */
    private function probeSync(GitlabClientContract $client, IntegrationConnection $connection): array
    {
        $projectId = $this->projectId($connection);

        if ($projectId === null) {
            return $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, 'project_not_configured');
        }

        try {
            $entries = $client->repositoryFileList($projectId, '', $this->ref($connection));

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    return $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, 'invalid_response');
                }
            }

            return $this->item(self::CAPABILITY_SYNC, self::STATUS_OK, 'repository_listed');
        } catch (Throwable $e) {
            return $this->item(self::CAPABILITY_SYNC, self::STATUS_FAILED, $this->safeReason($e));
        }
    }

    /**
     * 只做前置条件预检：源存在 + 目标不存在；不执行移动、不发 PUT。
     *
     * @return array{capability: string, status: string, detail: string}
     */
    private function probeMove(GitlabClientContract $client, IntegrationConnection $connection): array
    {
        $config = (array) ($connection->config ?? []);
        $source = $this->normalizePath((string) ($config['move_probe_source_path'] ?? ''));
        $target = $this->normalizePath((string) ($config['move_probe_target_path'] ?? ''));

        if ($source === null || $target === null) {
            return $this->item(self::CAPABILITY_MOVE, self::STATUS_SKIPPED, self::DETAIL_MOVE_PATH_MISSING);
        }

        if ($source === $target) {
            return $this->item(self::CAPABILITY_MOVE, self::STATUS_SKIPPED, 'move_paths_invalid');
        }

        $projectId = $this->projectId($connection);

        if ($projectId === null) {
            return $this->item(self::CAPABILITY_MOVE, self::STATUS_SKIPPED, 'project_not_configured');
        }

        $ref = $this->ref($connection);

        try {
            try {
                $client->repositoryFileGet($projectId, $source, $ref);
            } catch (GitlabApiException $e) {
                if ($e->status === 404) {
                    return $this->item(self::CAPABILITY_MOVE, self::STATUS_SKIPPED, 'source_missing');
                }

                throw $e;
            }

            try {
                $client->repositoryFileGet($projectId, $target, $ref);
            } catch (GitlabApiException $e) {
                if ($e->status === 404) {
                    return $this->item(self::CAPABILITY_MOVE, self::STATUS_OK, 'preconditions_ok');
                }

                throw $e;
            }

            return $this->item(self::CAPABILITY_MOVE, self::STATUS_SKIPPED, 'target_exists');
        } catch (Throwable $e) {
            return $this->item(self::CAPABILITY_MOVE, self::STATUS_SKIPPED, $this->safeReason($e));
        }
    }

    /**
     * @return array{capability: string, status: string, detail: string}
     */
    private function writeSkipped(): array
    {
        return $this->item(self::CAPABILITY_WRITE, self::STATUS_SKIPPED, self::DETAIL_WRITE_SKIPPED);
    }

    private function projectId(IntegrationConnection $connection): ?string
    {
        $config = (array) ($connection->config ?? []);
        $value = trim((string) ($config['doc_project_id'] ?? ''));

        return $value === '' ? null : $value;
    }

    private function ref(IntegrationConnection $connection): string
    {
        $config = (array) ($connection->config ?? []);
        $ref = trim((string) ($config['default_branch'] ?? ''));

        return $ref !== '' ? $ref : (string) config('gitlab.docs.default_branch', 'main');
    }

    /**
     * 仅接受非空、无反斜杠/空字节的相对仓库路径；不做任何远端写入。
     */
    private function normalizePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }

        $path = trim($path, '/');

        return $path === '' ? null : $path;
    }

    /**
     * 连接所属租户与当前上下文不一致时 fail closed；无上下文（CLI/显式调用）
     * 不猜测默认租户，结果仍限定在传入连接。
     */
    private function tenantMismatch(IntegrationConnection $connection): bool
    {
        $contextTenantId = TenantContext::getId();

        if ($contextTenantId === null || $contextTenantId === '') {
            return false;
        }

        return (string) ($connection->tenant_id ?? '') !== $contextTenantId;
    }

    /**
     * 脱敏 reason：只保留稳定枚举/状态映射，绝不带异常 message。
     */
    private function safeReason(Throwable $e): string
    {
        if ($e instanceof GitlabApiException) {
            if ($e->reason !== '') {
                return $e->reason;
            }

            return match (true) {
                $e->status === 0 => 'unavailable',
                $e->status === 401 => 'auth_failed',
                $e->status === 403 => 'forbidden',
                $e->status === 404 => 'not_found',
                $e->status === 409 => 'conflict',
                $e->status === 429 => 'rate_limited',
                $e->status >= 500 => 'unavailable',
                default => 'http_' . $e->status,
            };
        }

        if ($e instanceof GitlabUrlException) {
            return 'unsafe_base_url';
        }

        return 'probe_failed';
    }

    /**
     * @param  list<array{capability: string, status: string, detail: string}>  $capabilities
     * @return array{
     *     provider: string,
     *     tenant_id: int,
     *     connection_id: int,
     *     checked_at: string,
     *     writes_performed: bool,
     *     capabilities: list<array{capability: string, status: string, detail: string}>
     * }
     */
    private function envelope(IntegrationConnection $connection, array $capabilities): array
    {
        return [
            'provider' => 'gitlab',
            'tenant_id' => (int) ($connection->tenant_id ?? 0),
            'connection_id' => (int) ($connection->connection_id ?? 0),
            'checked_at' => now()->toIso8601String(),
            'writes_performed' => false,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * @return array{capability: string, status: string, detail: string}
     */
    private function item(string $capability, string $status, string $detail): array
    {
        return [
            'capability' => $capability,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
