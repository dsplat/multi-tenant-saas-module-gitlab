<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Modules\Gitlab\Contracts\GitlabClientContract;
use MultiTenantSaas\Modules\Gitlab\Events\GitlabConnectionChecked;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabApiException;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabUrlException;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabUrlGuard;
use MultiTenantSaas\Modules\Gitlab\Support\SensitiveText;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use MultiTenantSaas\Modules\Integration\Services\IntegrationConnectionService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GitLab 连接管理：全部落 Integration 的 integration_connections（provider=gitlab）。
 *
 * 安全约束：
 * - token / webhook_secret 只写 credentials（encrypted:array），任何返回值都不含凭据；
 * - base_url 每次创建/更新都过 SSRF 守卫；
 * - read_only=true 的连接拒绝一切写操作（文档写 / 建项目 / 写探针）。
 */
class GitlabConnectionService
{
    public const PROVIDER = 'gitlab';

    public function __construct(
        private readonly IntegrationConnectionService $connections,
        private readonly GitlabUrlGuard $guard,
        private readonly GitlabClientFactory $clients,
    ) {}

    /**
     * @return list<IntegrationConnection>
     */
    public function list(int $tenantId): array
    {
        return $this->connections->forProvider($tenantId, self::PROVIDER);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $tenantId, array $data): IntegrationConnection
    {
        $baseUrl = $this->guardBaseUrl((string) ($data['base_url'] ?? ''));
        $credentials = $this->buildCredentials($data);

        if (! isset($credentials['token'])) {
            throw ValidationException::withMessages(['token' => 'GitLab Access Token 不能为空']);
        }

        try {
            $connection = $this->connections->create($tenantId, (string) $data['name'], self::PROVIDER, [
                'base_url' => $baseUrl,
                'auth_type' => IntegrationConnection::AUTH_BEARER,
                'credentials' => $credentials,
                'config' => $this->buildConfig($data),
                'status' => $data['status'] ?? IntegrationConnection::STATUS_ACTIVE,
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages(['name' => '同一租户下连接名称已存在']);
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, int $connectionId, array $data): IntegrationConnection
    {
        $connection = $this->find($tenantId, $connectionId);
        $attributes = [];

        if (array_key_exists('base_url', $data)) {
            $attributes['base_url'] = $this->guardBaseUrl((string) $data['base_url']);
        }

        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $attributes['name'] = (string) $data['name'];
        }

        if (array_key_exists('status', $data)) {
            $attributes['status'] = (string) $data['status'];
        }

        $credentials = (array) ($connection->credentials ?? []);

        if (array_key_exists('token', $data) && trim((string) $data['token']) !== '') {
            $credentials['token'] = (string) $data['token'];
        }

        if (array_key_exists('webhook_secret', $data)) {
            $secret = trim((string) $data['webhook_secret']);

            if ($secret === '') {
                unset($credentials['webhook_secret']);
            } else {
                $credentials['webhook_secret'] = $secret;
            }
        }

        if ($credentials !== []) {
            $attributes['credentials'] = $credentials;
        }

        $config = (array) ($connection->config ?? []);

        foreach ($this->buildConfig($data) as $key => $value) {
            $config[$key] = $value;
        }

        $attributes['config'] = $config;

        try {
            return $this->connections->update($tenantId, $connectionId, $attributes);
        } catch (QueryException) {
            throw ValidationException::withMessages(['name' => '同一租户下连接名称已存在']);
        }
    }

    public function find(int $tenantId, int $connectionId): IntegrationConnection
    {
        $connection = $this->connections->find($tenantId, $connectionId);

        if (! $connection || $connection->provider !== self::PROVIDER) {
            throw new NotFoundHttpException('GitLab 连接不存在');
        }

        return $connection;
    }

    /**
     * 文档 provider 用的连接解析：显式 ID 优先，否则取租户第一条启用连接。
     */
    public function resolveForTenant(int $tenantId, ?int $connectionId = null): IntegrationConnection
    {
        if ($connectionId !== null) {
            return $this->find($tenantId, $connectionId);
        }

        foreach ($this->list($tenantId) as $connection) {
            if ($connection->status === IntegrationConnection::STATUS_ACTIVE) {
                return $connection;
            }
        }

        throw new NotFoundHttpException('租户尚未配置 GitLab 连接');
    }

    /**
     * 只读探测（GET /user）；writeProbe=true 时才在专用测试项目执行写探针。
     *
     * @return array{ok: bool, error: string|null, checked_at: string, read_only: bool}
     */
    public function test(int $tenantId, int $connectionId, bool $writeProbe = false): array
    {
        $connection = $this->find($tenantId, $connectionId);

        if ($writeProbe && $this->isReadOnly($connection)) {
            throw new AccessDeniedHttpException('只读连接不允许执行写探针');
        }

        $error = null;

        try {
            $client = $this->clients->forConnection($connection);
            $client->currentUser();

            if ($writeProbe) {
                $this->probeWrite($connection, $client);
            }
        } catch (GitlabApiException|GitlabUrlException $e) {
            $error = $this->safeError($e);
        }

        $this->connections->markChecked($tenantId, $connectionId, $error);
        Event::dispatch(new GitlabConnectionChecked($tenantId, $connectionId, $error === null, $error));

        return [
            'ok' => $error === null,
            'error' => $error,
            'checked_at' => (string) now(),
            'read_only' => $this->isReadOnly($connection),
        ];
    }

    /**
     * 轮换 token：先用新 token 做只读探测，通过后才替换凭据。
     */
    public function rotate(int $tenantId, int $connectionId, string $token, ?string $webhookSecret = null): IntegrationConnection
    {
        $connection = $this->find($tenantId, $connectionId);
        $token = trim($token);

        if ($token === '') {
            throw ValidationException::withMessages(['token' => '新 Token 不能为空']);
        }

        try {
            $this->clients->forConnection($connection, $token)->currentUser();
        } catch (GitlabApiException|GitlabUrlException $e) {
            throw ValidationException::withMessages(['token' => '新 Token 校验失败：' . $this->safeError($e)]);
        }

        $credentials = (array) ($connection->credentials ?? []);
        $credentials['token'] = $token;

        if ($webhookSecret !== null && trim($webhookSecret) !== '') {
            $credentials['webhook_secret'] = trim($webhookSecret);
        }

        $updated = $this->connections->update($tenantId, $connectionId, [
            'credentials' => $credentials,
            'last_checked_at' => now(),
            'last_check_error' => null,
        ]);

        Event::dispatch(new GitlabConnectionChecked($tenantId, $connectionId, true, null));

        return $updated;
    }

    public function clientFor(IntegrationConnection $connection, ?string $tokenOverride = null): GitlabClientContract
    {
        return $this->clients->forConnection($connection, $tokenOverride);
    }

    public function isReadOnly(IntegrationConnection $connection): bool
    {
        $config = (array) ($connection->config ?? []);

        return (bool) ($config['read_only'] ?? config('gitlab.default_read_only', true));
    }

    public function assertWritable(IntegrationConnection $connection): void
    {
        if ($this->isReadOnly($connection)) {
            throw new AccessDeniedHttpException('GitLab 连接为只读，禁止写操作');
        }

        if ($connection->status !== IntegrationConnection::STATUS_ACTIVE) {
            throw new AccessDeniedHttpException('GitLab 连接已停用');
        }
    }

    public function docProjectId(IntegrationConnection $connection): ?string
    {
        $config = (array) ($connection->config ?? []);
        $projectId = $config['doc_project_id'] ?? null;

        return $projectId === null || $projectId === '' ? null : (string) $projectId;
    }

    public function defaultBranch(IntegrationConnection $connection): string
    {
        $config = (array) ($connection->config ?? []);

        return (string) ($config['default_branch'] ?? config('gitlab.docs.default_branch', 'main'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function buildCredentials(array $data): array
    {
        $credentials = [];

        $token = trim((string) ($data['token'] ?? ''));

        if ($token !== '') {
            $credentials['token'] = $token;
        }

        $secret = trim((string) ($data['webhook_secret'] ?? ''));

        if ($secret !== '') {
            $credentials['webhook_secret'] = $secret;
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildConfig(array $data): array
    {
        $config = [];

        if (array_key_exists('read_only', $data)) {
            $config['read_only'] = (bool) $data['read_only'];
        }

        foreach (['tls_verify'] as $key) {
            if (array_key_exists($key, $data)) {
                $config[$key] = (bool) $data[$key];
            }
        }

        if (array_key_exists('timeout', $data)) {
            $config['timeout'] = max(1, min(60, (int) $data['timeout']));
        }

        if (array_key_exists('token_scopes', $data)) {
            $config['token_scopes'] = array_values(array_map(
                static fn ($scope): string => mb_substr((string) $scope, 0, 64),
                array_filter((array) $data['token_scopes'], static fn ($scope): bool => is_string($scope) && trim($scope) !== ''),
            ));
        }

        if (array_key_exists('token_expires_at', $data)) {
            $config['token_expires_at'] = $data['token_expires_at'] === null
                ? null
                : (string) $data['token_expires_at'];
        }

        foreach (['doc_group', 'default_branch'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) $data[$key]);
                $config[$key] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('doc_project_id', $data)) {
            $value = trim((string) $data['doc_project_id']);
            $config['doc_project_id'] = $value === '' ? null : $value;
        }

        if (array_key_exists('write_probe_project_id', $data)) {
            $value = trim((string) $data['write_probe_project_id']);
            $config['write_probe_project_id'] = $value === '' ? null : $value;
        }

        return $config;
    }

    private function guardBaseUrl(string $baseUrl): string
    {
        try {
            return $this->guard->assertAllowed($baseUrl);
        } catch (GitlabUrlException $e) {
            throw ValidationException::withMessages(['base_url' => $e->getMessage()]);
        }
    }

    private function probeWrite(IntegrationConnection $connection, GitlabClientContract $client): void
    {
        $config = (array) ($connection->config ?? []);
        $projectId = $config['write_probe_project_id'] ?? config('gitlab.docs.probe_project_id');

        if ($projectId === null || $projectId === '') {
            throw new GitlabApiException(400, '写探针未配置专用测试项目');
        }

        $path = '.gitlab-connector-probe/probe-' . (string) $connection->getKey() . '-' . bin2hex(random_bytes(4)) . '.txt';
        $branch = $this->defaultBranch($connection);

        $client->repositoryFilePut(
            (string) $projectId,
            $path,
            'gitlab connection write probe',
            'chore: gitlab connector write probe',
            $branch,
        );

        $client->repositoryFileDelete((string) $projectId, $path, 'chore: gitlab connector write probe cleanup', $branch);
    }

    /**
     * 安全错误摘要：只保留状态码类信息，绝不含 token。
     */
    private function safeError(GitlabApiException|GitlabUrlException $e): string
    {
        return SensitiveText::redact(mb_substr($e->getMessage(), 0, 500));
    }
}
