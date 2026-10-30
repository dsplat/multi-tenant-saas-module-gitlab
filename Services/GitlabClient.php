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

        // 更新接口对不存在的文件返回 400（"A file with this name doesn't exist"）而非 404，
        // 异常里又刻意不带响应体，无法据此回落；先在目标分支判存在，决定更新还是创建。
        return $this->repositoryFileExists($projectId, $path, $branch)
            ? $this->request('PUT', $endpoint, payload: $payload)
            : $this->request('POST', $endpoint, payload: $payload);
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

        if ($this->repositoryFileExists($projectId, $newPath, $branch)) {
            throw GitlabApiException::forConflict('target_exists');
        }

        $endpoint = 'projects/' . rawurlencode((string) $projectId) . '/repository/files/' . $this->encodePath($newPath);

        $payload = array_filter([
            'branch' => $branch,
            'content' => $content,
            'encoding' => $encoding === 'base64' ? 'base64' : 'text',
            'commit_message' => $message,
            'previous_path' => trim($oldPath, '/'),
            'author_name' => $author,
        ], static fn ($value) => $value !== null);

        try {
            // E3：一次提交内 rename（previous_path），不再「先写目标再删源」。
            return $this->request('PUT', $endpoint, payload: $payload);
        } catch (GitlabApiException $e) {
            if ($e->status !== 0) {
                if (in_array($e->status, [400, 409, 422], true)) {
                    throw GitlabApiException::forConflict('target_exists');
                }

                throw $e;
            }

            // 网络超时：结果可能已提交，按目标/源证据对账，不盲目重放写操作。
            $targetExists = $this->repositoryFileExists($projectId, $newPath, $branch);
            $sourceExists = $this->repositoryFileExists($projectId, $oldPath, $branch);

            if ($targetExists && ! $sourceExists) {
                return $this->repositoryFileGet($projectId, $newPath, $branch);
            }

            if ($targetExists && $sourceExists) {
                throw GitlabApiException::forConflict('move_uncertain');
            }

            throw GitlabApiException::forConflict('move_uncertain_none');
        }
    }

    public function repositoryFileList(int|string $projectId, string $path, ?string $ref = null, bool $recursive = false): array
    {
        $perPage = min(100, max(1, (int) config('gitlab.list.per_page', 100)));
        $maxPages = max(1, (int) config('gitlab.list.max_pages', 20));
        $maxEntries = max(1, (int) config('gitlab.list.max_entries', 5000));
        $timeBudget = max(1, (int) config('gitlab.list.time_budget_seconds', 20));
        $deadline = microtime(true) + $timeBudget;

        $entries = [];
        $seen = [];
        $page = 1;

        while ($page <= $maxPages) {
            if (microtime(true) > $deadline) {
                throw GitlabApiException::forLimit('limit_time');
            }

            [$body, $headers] = $this->requestWithMeta(
                'GET',
                'projects/' . rawurlencode((string) $projectId) . '/repository/tree',
                query: array_filter([
                    'path' => trim($path, '/'),
                    'ref' => $ref,
                    'recursive' => $recursive ? 'true' : null,
                    'page' => $page,
                    'per_page' => $perPage,
                ], static fn ($value) => $value !== null && $value !== ''),
            );

            $items = array_values(array_filter($body, 'is_array'));

            foreach ($items as $item) {
                // E2：按稳定标识去重，重复页/重复项不得把结果撑大。
                $key = (string) ($item['id'] ?? $item['path'] ?? '');

                if ($key === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $entries[] = $item;

                if (count($entries) > $maxEntries) {
                    throw GitlabApiException::forLimit('limit_entries');
                }
            }

            $next = $this->nextPageFromHeaders($headers, $page);

            if ($next === null) {
                if (count($items) < $perPage || $items === []) {
                    break;
                }

                $next = $page + 1;
            }

            if ($next <= $page) {
                break;
            }

            if ($next > $maxPages) {
                // 仍有下一页但超过配置上限：抛错，不返回伪完整列表。
                throw GitlabApiException::forLimit('limit_pages');
            }

            $page = $next;
        }

        return $entries;
    }

    /**
     * 分页只认 GitLab 自己给的 `x-next-page`，不跟随 Link 里的任意 URL（避免凭据发往外部地址）。
     *
     * @param  array<string, array<string>>  $headers
     */
    private function nextPageFromHeaders(array $headers, int $page): ?int
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) !== 'x-next-page') {
                continue;
            }

            $raw = trim((string) (is_array($values) ? ($values[0] ?? '') : $values));

            if (preg_match('/^\d+$/', $raw) !== 1) {
                return null;
            }

            $next = (int) $raw;

            return $next > $page ? $next : null;
        }

        return null;
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
        return $this->requestWithMeta($method, $path, $payload, $query)[0];
    }

    /**
     * 同 request()，并返回响应头（分页用 `x-next-page`）。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array{0: array<string, mixed>, 1: array<string, array<string>>}
     */
    private function requestWithMeta(string $method, string $path, array $payload = [], array $query = []): array
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
                return [$this->decode($response), $response->headers()];
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

    /**
     * 目标分支上文件是否存在（404 视为不存在，其余异常照常上抛）。
     */
    private function repositoryFileExists(int|string $projectId, string $path, string $branch): bool
    {
        try {
            $this->repositoryFileGet($projectId, $path, $branch);

            return true;
        } catch (GitlabApiException $e) {
            if ($e->status === 404) {
                return false;
            }

            throw $e;
        }
    }
}
