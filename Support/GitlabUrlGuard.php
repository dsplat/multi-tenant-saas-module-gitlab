<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Support;

/**
 * GitLab base_url SSRF 守卫。
 *
 * 规则：
 * - 默认仅 https（allow_insecure_http=true 才接受 http）；
 * - 拒绝 URL 内嵌凭据、查询串、片段；
 * - 拒绝 localhost / 私网 / 链路本地 / 云元数据地址；
 * - allowed_hosts 非空时额外做主机白名单；
 * - allow_private_hosts 仅供本地/测试环境绕开主机检查（不绕开协议检查）。
 *
 * 客户端不跟随重定向（见 GitlabClient），因此“重定向到非允许主机”不会发生。
 */
class GitlabUrlGuard
{
    private const METADATA_HOSTS = [
        'metadata',
        'metadata.google.internal',
        'metadata.google.internal.',
        'instance-data',
        'instance-data.ec2.internal',
        '169.254.169.254',
    ];

    public function assertAllowed(string $baseUrl): string
    {
        $raw = trim($baseUrl);

        if ($raw === '' || strlen($raw) > 500) {
            throw new GitlabUrlException('GitLab 地址为空或过长');
        }

        $parts = parse_url($raw);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new GitlabUrlException('GitLab 地址格式非法');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $allowInsecure = (bool) config('gitlab.allow_insecure_http', false);

        if ($scheme !== 'https' && ! ($allowInsecure && $scheme === 'http')) {
            throw new GitlabUrlException('GitLab 地址必须使用 https');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new GitlabUrlException('GitLab 地址不得包含凭据、查询串或片段');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));

        if ($host === '') {
            throw new GitlabUrlException('GitLab 地址缺少主机');
        }

        $allowPrivate = (bool) config('gitlab.allow_private_hosts', false);

        if (! $allowPrivate) {
            $this->assertPublicHost($host);
        }

        $this->assertHostWhitelist($host);

        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new GitlabUrlException('GitLab 地址端口非法');
        }

        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        $path = preg_replace('#(^|/)api/v\d+$#', '', $path) ?? $path;

        return $scheme . '://' . $host
            . ($port !== null ? ':' . $port : '')
            . ($path !== '' ? '/' . $path : '');
    }

    private function assertPublicHost(string $host): void
    {
        if (in_array($host, self::METADATA_HOSTS, true)) {
            throw new GitlabUrlException('GitLab 地址指向云元数据地址');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new GitlabUrlException('GitLab 地址指向本机');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($isPublic === false) {
                throw new GitlabUrlException('GitLab 地址指向私网或保留地址');
            }

            return;
        }

        // 单标签主机（如 http://gitlab）通常由内网 DNS 解析，默认拒绝。
        if (! str_contains($host, '.')) {
            throw new GitlabUrlException('GitLab 地址不是可公开解析的主机名');
        }
    }

    private function assertHostWhitelist(string $host): void
    {
        /** @var array<int, string> $allowed */
        $allowed = (array) config('gitlab.allowed_hosts', []);

        if ($allowed === []) {
            return;
        }

        foreach ($allowed as $entry) {
            $entry = strtolower(trim((string) $entry));

            if ($entry === '') {
                continue;
            }

            if ($entry === $host) {
                return;
            }

            if (str_starts_with($entry, '*.') && str_ends_with($host, substr($entry, 1))) {
                return;
            }
        }

        throw new GitlabUrlException('GitLab 主机不在允许列表内');
    }
}
