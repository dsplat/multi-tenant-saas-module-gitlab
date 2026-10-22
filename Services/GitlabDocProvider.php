<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

use MultiTenantSaas\Modules\Archive\Contracts\DocProviderContract;
use MultiTenantSaas\Modules\Archive\Dto\DocFileContent;
use MultiTenantSaas\Modules\Archive\Dto\DocProviderFile;
use MultiTenantSaas\Modules\Archive\Exceptions\DocProviderException;
use MultiTenantSaas\Modules\Gitlab\Contracts\GitlabClientContract;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabApiException;
use MultiTenantSaas\Modules\Gitlab\Support\GitlabUrlException;
use MultiTenantSaas\Modules\Gitlab\Support\SensitiveText;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;

/**
 * GitLab 文档仓 provider：走 Repository Files API 存取 Archive 正文。
 *
 * 约定：
 * - 文件路径为仓库内相对路径，逐段 URL 编码由 GitlabClient 完成；
 * - UTF-8 文本按 text 提交，其余（含二进制）按 base64 提交；
 * - commit message 固定携带 source / actor / doc_id，便于审计追溯；
 * - 目录用 `.gitkeep` 占位；删除幂等；move = 新路径写入 + 旧路径删除；
 * - 只读连接拒绝所有写操作（PERMISSION_DENIED）；厂商异常归一化，不泄露 token。
 */
class GitlabDocProvider implements DocProviderContract
{
    private ?IntegrationConnection $connection = null;

    private ?GitlabClientContract $client = null;

    public function __construct(
        private readonly GitlabConnectionService $connections,
        private readonly int $tenantId,
        private readonly ?int $connectionId = null,
    ) {}

    public function name(): string
    {
        return 'gitlab';
    }

    public function capabilities(): array
    {
        return [
            'max_file_size' => (int) config('gitlab.docs.max_file_size', 25 * 1024 * 1024),
            'allowed_mime_types' => array_values((array) config('gitlab.docs.allowed_mime_types', [])),
            'supports_versions' => true,
            'supports_preview' => true,
            'supports_move' => true,
            'max_path_length' => (int) config('gitlab.docs.max_path_length', 500),
        ];
    }

    public function createDirectory(string $path): void
    {
        $path = $this->normalizePath($path, '/');
        $this->assertWritable();

        $file = $path === '' ? '.gitkeep' : $path . '/.gitkeep';

        if ($this->exists($file)) {
            return;
        }

        $this->run(fn (GitlabClientContract $client, string $projectId, string $branch) => $client->repositoryFilePut(
            $projectId,
            $file,
            '',
            $this->commitMessage($file, ['message' => 'chore: create docs directory ' . ($path === '' ? '/' : $path)]),
            $branch,
            'text',
        ));
    }

    public function putFile(string $path, mixed $stream, array $metadata = []): DocProviderFile
    {
        $path = $this->normalizePath($path);
        $this->assertWritable();

        $content = $this->readStream($stream);
        $max = (int) config('gitlab.docs.max_file_size', 25 * 1024 * 1024);

        if (strlen($content) > $max) {
            throw DocProviderException::make(DocProviderException::TOO_LARGE, '文档超过 GitLab 连接器允许的大小上限');
        }

        $isText = mb_check_encoding($content, 'UTF-8') && ! str_contains($content, "\0");
        $encoding = $isText ? 'text' : 'base64';
        $payload = $isText ? $content : base64_encode($content);

        $branch = $this->branch($metadata);
        $message = $this->commitMessage($path, $metadata);

        if (($metadata['overwrite'] ?? true) === false && $this->exists($path)) {
            throw DocProviderException::make(DocProviderException::CONFLICT, '目标文档已存在');
        }

        $response = $this->run(fn (GitlabClientContract $client, string $projectId) => $client->repositoryFilePut(
            $projectId,
            $path,
            $payload,
            $message,
            $branch,
            $encoding,
            $this->author($metadata),
        ));

        return $this->fileFromResponse($path, $response, strlen($content));
    }

    public function getFile(string $fileRef): DocFileContent
    {
        $path = $this->normalizePath($fileRef);
        $this->connection();

        $response = $this->run(fn (GitlabClientContract $client, string $projectId, string $branch) => $client->repositoryFileGet($projectId, $path, $branch));
        $encoding = (string) ($response['encoding'] ?? 'base64');
        $raw = (string) ($response['content'] ?? '');
        $content = $encoding === 'base64' ? (string) base64_decode($raw, true) : $raw;

        $stream = fopen('php://temp', 'r+b');

        if ($stream !== false) {
            fwrite($stream, $content);
            rewind($stream);
        }

        return new DocFileContent(
            stream: $stream === false ? $content : $stream,
            size: strlen($content),
            mimeType: $this->mimeType($path, $content),
            checksumSha256: hash('sha256', $content),
        );
    }

    public function deleteFile(string $fileRef): void
    {
        $path = $this->normalizePath($fileRef);
        $this->assertWritable();

        try {
            $this->run(fn (GitlabClientContract $client, string $projectId, string $branch) => $client->repositoryFileDelete(
                $projectId,
                $path,
                $this->commitMessage($path, ['message' => 'chore: delete document ' . $path]),
                $branch,
            ));
        } catch (DocProviderException $e) {
            if ($e->reason !== DocProviderException::NOT_FOUND) {
                throw $e;
            }
        }
    }

    public function moveFile(string $fileRef, string $newPath): DocProviderFile
    {
        $oldPath = $this->normalizePath($fileRef);
        $newPath = $this->normalizePath($newPath);
        $this->assertWritable();

        if ($oldPath === $newPath) {
            return $this->stat($oldPath);
        }

        if ($this->exists($newPath)) {
            throw DocProviderException::make(DocProviderException::CONFLICT, '目标路径已存在文档');
        }

        $file = $this->run(fn (GitlabClientContract $client, string $projectId, string $branch) => $client->repositoryFileMove(
            $projectId,
            $oldPath,
            $newPath,
            $this->commitMessage($newPath, ['message' => 'chore: move document ' . $oldPath . ' -> ' . $newPath]),
            $branch,
        ));

        $size = array_key_exists('size', $file) ? (int) $file['size'] : 0;

        return $this->fileFromResponse($newPath, $file, $size);
    }

    public function listFiles(string $path): array
    {
        $path = $this->normalizePath($path, '/');

        $entries = $this->run(fn (GitlabClientContract $client, string $projectId, string $branch) => $client->repositoryFileList($projectId, $path, $branch));

        $files = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = (string) ($entry['type'] ?? 'blob');

            if ($type !== 'blob') {
                continue;
            }

            $entryPath = (string) ($entry['path'] ?? '');

            if ($entryPath === '') {
                continue;
            }

            $files[] = new DocProviderFile(
                ref: $entryPath,
                path: $entryPath,
                size: (int) ($entry['size'] ?? 0),
                mimeType: $this->mimeType($entryPath, ''),
                commit: isset($entry['last_commit_id']) ? (string) $entry['last_commit_id'] : null,
            );
        }

        return $files;
    }

    public function stat(string $fileRef): DocProviderFile
    {
        $content = $this->getFile($fileRef);

        return new DocProviderFile(
            ref: $this->normalizePath($fileRef),
            path: $this->normalizePath($fileRef),
            size: $content->size,
            mimeType: $content->mimeType,
            checksumSha256: $content->checksumSha256,
        );
    }

    public function healthCheck(): bool
    {
        try {
            $this->run(fn (GitlabClientContract $client) => $client->currentUser());

            return true;
        } catch (DocProviderException) {
            return false;
        }
    }

    /**
     * @param  callable(GitlabClientContract, string, string): mixed  $work
     */
    private function run(callable $work): mixed
    {
        $connection = $this->connection();
        $projectId = $this->connections->docProjectId($connection);

        if ($projectId === null) {
            throw DocProviderException::make(DocProviderException::UNSUPPORTED, 'GitLab 连接未配置文档项目（doc_project_id）');
        }

        try {
            return $work($this->client(), $projectId, $this->connections->defaultBranch($connection));
        } catch (GitlabApiException $e) {
            throw $this->normalizeApiError($e);
        } catch (GitlabUrlException $e) {
            throw DocProviderException::make(
                DocProviderException::UNAVAILABLE,
                SensitiveText::redact(mb_substr($e->getMessage(), 0, 300)),
                $e,
            );
        }
    }

    private function connection(): IntegrationConnection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        try {
            $this->connection = $this->connections->resolveForTenant($this->tenantId, $this->connectionId);
        } catch (NotFoundHttpException $e) {
            throw DocProviderException::make(DocProviderException::NOT_FOUND, '未找到可用的 GitLab 连接', $e);
        }

        return $this->connection;
    }

    private function client(): GitlabClientContract
    {
        return $this->client ??= $this->connections->clientFor($this->connection());
    }

    private function assertWritable(): void
    {
        $connection = $this->connection();

        try {
            $this->connections->assertWritable($connection);
        } catch (AccessDeniedHttpException $e) {
            throw DocProviderException::make(DocProviderException::PERMISSION_DENIED, 'GitLab 连接为只读或已停用，禁止写文档', $e);
        }
    }

    private function exists(string $path): bool
    {
        try {
            $this->run(fn (GitlabClientContract $client, string $projectId, string $branch) => $client->repositoryFileGet($projectId, $path, $branch));

            return true;
        } catch (DocProviderException $e) {
            if ($e->reason === DocProviderException::NOT_FOUND) {
                return false;
            }

            throw $e;
        }
    }

    private function normalizeApiError(GitlabApiException $e): DocProviderException
    {
        $reason = match (true) {
            $e->status === 404 => DocProviderException::NOT_FOUND,
            $e->status === 400, $e->status === 409, $e->status === 422 => DocProviderException::CONFLICT,
            $e->status === 401, $e->status === 403 => DocProviderException::PERMISSION_DENIED,
            $e->status === 429 => DocProviderException::RATE_LIMITED,
            default => DocProviderException::UNAVAILABLE,
        };

        return DocProviderException::make($reason, $e->getMessage(), $e);
    }

    private function normalizePath(string $path, string $empty = ''): string
    {
        $max = (int) config('gitlab.docs.max_path_length', 500);

        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            throw DocProviderException::make(DocProviderException::INVALID_PATH, '文档路径包含非法字符');
        }

        $path = trim($path, '/');

        if (strlen($path) > $max) {
            throw DocProviderException::make(DocProviderException::INVALID_PATH, '文档路径超过长度上限');
        }

        if ($path === '') {
            return $empty;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw DocProviderException::make(DocProviderException::INVALID_PATH, '文档路径包含非法片段');
            }
        }

        return $path;
    }

    private function readStream(mixed $stream): string
    {
        if (is_string($stream)) {
            return $stream;
        }

        if (! is_resource($stream)) {
            throw DocProviderException::make(DocProviderException::INVALID_PATH, '文档内容必须是字符串或可读流');
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw DocProviderException::make(DocProviderException::UNAVAILABLE, '文档内容读取失败');
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function branch(array $metadata): string
    {
        $branch = trim((string) ($metadata['branch'] ?? ''));

        return $branch !== '' ? $branch : $this->connections->defaultBranch($this->connection());
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function author(array $metadata): ?string
    {
        $author = trim((string) ($metadata['author'] ?? ''));

        return $author === '' ? null : mb_substr($author, 0, 100);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function commitMessage(string $path, array $metadata): string
    {
        $message = trim((string) ($metadata['message'] ?? ''));
        $message = $message !== '' ? $message : 'docs: update ' . $path;
        $message = mb_substr($message, 0, 200);

        $context = [
            'source=' . mb_substr((string) ($metadata['source'] ?? 'archive'), 0, 50),
            'actor=' . mb_substr((string) ($metadata['actor'] ?? 'system'), 0, 100),
            'doc_id=' . mb_substr((string) ($metadata['doc_id'] ?? '-'), 0, 64),
        ];

        return $message . ' [' . implode('; ', $context) . ']';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fileFromResponse(string $path, array $response, int $size): DocProviderFile
    {
        return new DocProviderFile(
            ref: $path,
            path: (string) ($response['file_path'] ?? $path),
            size: $size,
            mimeType: $this->mimeType($path, ''),
            commit: isset($response['last_commit_id']) ? (string) $response['last_commit_id'] : null,
        );
    }

    private function mimeType(string $path, string $content): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension !== '') {
            $mime = MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null;

            if ($mime !== null) {
                return $mime;
            }
        }

        if ($content !== '') {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);

            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return null;
    }
}
