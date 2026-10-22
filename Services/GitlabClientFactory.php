<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use Illuminate\Http\Client\Factory;
use MultiTenantSaas\Modules\Gitlab\Contracts\GitlabClientContract;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabApiException;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabUrlGuard;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;

/**
 * 按连接构造 GitLab 客户端（base_url 每次构造都过 SSRF 守卫）。
 *
 * token 只从 encrypted:array 的 credentials 读取，不落日志、不进异常。
 */
class GitlabClientFactory
{
    public function __construct(
        private readonly Factory $http,
        private readonly GitlabUrlGuard $guard,
    ) {}

    public function forConnection(IntegrationConnection $connection, ?string $tokenOverride = null): GitlabClientContract
    {
        $baseUrl = $this->guard->assertAllowed((string) $connection->base_url);
        $credentials = (array) ($connection->credentials ?? []);
        $token = $tokenOverride ?? (string) ($credentials['token'] ?? '');

        if ($token === '') {
            throw GitlabApiException::forStatus(401);
        }

        $config = (array) ($connection->config ?? []);
        $http = (array) config('gitlab.http', []);

        return new GitlabClient(
            http: $this->http,
            baseUrl: $baseUrl,
            token: $token,
            tlsVerify: (bool) ($config['tls_verify'] ?? config('gitlab.tls_verify', true)),
            timeout: (int) ($config['timeout'] ?? config('gitlab.timeout', 10)),
            apiVersion: (string) ($config['api_version'] ?? config('gitlab.api_version', 'v4')),
            retryTimes: (int) ($http['retry_times'] ?? 1),
            retrySleepMs: (int) ($http['retry_sleep_ms'] ?? 200),
        );
    }
}
