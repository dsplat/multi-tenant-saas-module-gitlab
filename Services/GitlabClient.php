<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use MultiTenantSaas\Modules\Gitlab\Contracts\GitlabClientContract;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabApiException;

/**
 * GitLab REST 客户端（PRIVATE-TOKEN 认证）。
 *
 * - 注入 Illuminate\Http\Client\Factory：测试用 Http::fake() 即可拦截；
 * - 不跟随重定向（allow_redirects=false），3xx 视为异常，杜绝 SSRF 跳转；
 * - 仅对 429/5xx/网络错误做有上限的退避重试；
 * - 异常信息仅含状态码，不含响应体与 token。
 */
class GitlabClient implements GitlabClientContract
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly bool $tlsVerify = true,
        private readonly int $timeout = 10,
        private readonly string $apiVersion = 'v4',
        private readonly int $retryTimes = 1,
        private readonly int $retrySleepMs = 200,
    ) {}

    public function currentUser(): array
    {
        return $this->request('GET', 'user');
    }

    public function listProjects(array $query = []): array
    {
        return $this->request('GET', 'projects', query: $query);
    }

    public function getProject(int|string $projectId): array
    {
        return $this->request('GET', 'projects/' . rawurlencode((string) $projectId));
    }

    public function createProject(array $attributes): array
    {
        return $this->request('POST', 'projects', payload: $attributes);
    }

    public function getPipeline(int|string $projectId, int|string $pipelineId): array
    {
        return $this->request(
            'GET',
            'projects/' . rawurlencode((string) $projectId) . '/pipelines/' . rawurlencode((string) $pipelineId),
        );
    }

    public function repositoryFileGet(int|string $projectId, string $path, ?string $ref = null): array
    {
        return $this->request(
            'GET',
            'projects/' . rawurlencode((string) $projectId) . '/repository/files/' . $this->encodePath($path),
            query: array_filter(['ref' => $ref], static fn ($value) => $value !== null && $value !== ''),
        );
    }

    public function repositoryFilePut(
        int|string $projectId,
        string $path,
        string $content,
        string $message,
        string $branch,
        string $encoding = 'text',
        ?string $author = null,
    ): array {
        $endpoint = 'projects/' . rawurlencode((string) $projectId) . '/repository/files/' . $this->encodePath($path);
        $payload = array_filter([
            'branch' => $branch,
            'content' => $content,
            'commit_message' => $message,
            'encoding' => $encoding,
            'author_name' => $author,
        ], static fn ($value) => $value !== null);

        try {
            return $this->request('PUT', $endpoint, payload: $payload);
        } catch (GitlabApiException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }

        return $this->request('POST', $endpoint, payload: $payload);
    }

    public function repositoryFileDelete(
        int|string $projectId,
        string $path,
        string $message,
        string $branch,
        ?string $author = null,
    ): void {
        $this->request(
            'DELETE',
            'projects/' . rawurlencode((string) $projectId) . '/repository/files/' . $this->encodePath($path),
            query: array_filter([
                'branch' => $branch,
                'commit_message' => $message,
                'author_name' => $author,
            ], static fn ($value) => $value !== null),
        );
    }

    public function repositoryFileMove(
        int|string $projectId,
        string $oldPath,
        string $newPath,
        string $message,
        string $branch,
        ?string $author = null,
    ): array {
        $file = $this->repositoryFileGet($projectId, $oldPath, $branch);
        $encoding = (string) ($file['encoding'] ?? 'base64');
        $content = (string) ($file['content'] ?? '');

        $written = $this->repositoryFilePut(
            $projectId,
            $newPath,
            $content,
            $message,
            $branch,
            $encoding === 'base64' ? 'base64' : 'text',
            $author,
        );

        $this->repositoryFileDelete($projectId, $oldPath, $message, $branch, $author);

        return $written;
    }

    public function repositoryFileList(int|string $projectId, string $path, ?string $ref = null, bool $recursive = false): array
    {
        $tree = $this->request(
            'GET',
            'projects/' . rawurlencode((string) $projectId) . '/repository/tree',
            query: array_filter([
                'path' => trim($path, '/'),
                'ref' => $ref,
                'recursive' => $recursive ? 'true' : null,
                'per_page' => 100,
            ], static fn ($value) => $value !== null && $value !== ''),
        );

        return array_values(array_filter($tree, 'is_array'));
    }

    public function createWebhook(int|string $projectId, array $attributes): array
    {
        return $this->request(
            'POST',
            'projects/' . rawurlencode((string) $projectId) . '/hooks',
            payload: $attributes,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/' . $this->apiVersion . '/' . ltrim($path, '/');
        $attempts = max(1, $this->retryTimes + 1);
        $last = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $response = $this->pending($query)->send($method, $url, $payload === [] ? [] : ['json' => $payload]);
            } catch (ConnectionException) {
                $last = GitlabApiException::forStatus(0);

                if ($i < $attempts - 1) {
                    $this->backoff($i);

                    continue;
                }

                throw $last;
            }

            if ($response->successful()) {
                return $this->decode($response);
            }

            $last = GitlabApiException::forStatus($response->status());

            if ($last->isRetryable() && $i < $attempts - 1) {
                $this->backoff($i);

                continue;
            }

            throw $last;
        }

        throw $last ?? GitlabApiException::forStatus(0);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function pending(array $query): PendingRequest
    {
        $pending = $this->http
            ->withHeaders(array_filter([
                'PRIVATE-TOKEN' => $this->token !== '' ? $this->token : null,
                'Accept' => 'application/json',
            ]))
            ->withOptions([
                'verify' => $this->tlsVerify,
                'allow_redirects' => false,
            ])
            ->timeout($this->timeout);

        if ($query !== []) {
            $pending = $pending->withQueryParameters($query);
        }

        return $pending;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $status = $response->status();

        if ($status === 204 || trim($response->body()) === '') {
            return [];
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function backoff(int $attempt): void
    {
        $sleep = max(0, $this->retrySleepMs) * ($attempt + 1);

        if ($sleep > 0) {
            usleep($sleep * 1000);
        }
    }

    /**
     * files API 的 file_path 必须整段编码：GitLab 路由只匹配单个 URL 段，
     * 字面量 `/` 会让嵌套路径 404（文档示例 app%2Fmodels%2Fapplication.rb）。
     */
    private function encodePath(string $path): string
    {
        return rawurlencode(trim($path, '/'));
    }
}
