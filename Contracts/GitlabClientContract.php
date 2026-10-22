<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Contracts;

/**
 * GitLab REST API 客户端契约（Repository Files / Projects / Webhooks）。
 *
 * 实现统一走 `/api/{version}`，注入 Illuminate\Http\Client\Factory，
 * 测试用 `Http::fake()` 拦截（测试基类已开启 preventStrayRequests）。
 *
 * 约束：
 * - 不跟随重定向（SSRF：不允许跳到非允许主机）；
 * - 非 2xx 抛 GitlabApiException（message 为脱敏摘要，绝不含 token）；
 * - 所有路径段由实现负责 URL 编码。
 */
interface GitlabClientContract
{
    public function currentUser(): array;

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function listProjects(array $query = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getProject(int|string $projectId): array;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createProject(array $attributes): array;

    /**
     * @return array<string, mixed>
     */
    public function getPipeline(int|string $projectId, int|string $pipelineId): array;

    /**
     * @return array<string, mixed>
     */
    public function repositoryFileGet(int|string $projectId, string $path, ?string $ref = null): array;

    /**
     * 创建或更新文件（先 PUT，404 时 POST 创建）。
     *
     * @param  'text'|'base64'  $encoding
     * @return array<string, mixed>
     */
    public function repositoryFilePut(
        int|string $projectId,
        string $path,
        string $content,
        string $message,
        string $branch,
        string $encoding = 'text',
        ?string $author = null,
    ): array;

    public function repositoryFileDelete(
        int|string $projectId,
        string $path,
        string $message,
        string $branch,
        ?string $author = null,
    ): void;

    /**
     * 移动文件（新路径写入 + 旧路径删除；调用方负责业务补偿）。
     *
     * @return array<string, mixed>
     */
    public function repositoryFileMove(
        int|string $projectId,
        string $oldPath,
        string $newPath,
        string $message,
        string $branch,
        ?string $author = null,
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function repositoryFileList(int|string $projectId, string $path, ?string $ref = null, bool $recursive = false): array;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createWebhook(int|string $projectId, array $attributes): array;
}
