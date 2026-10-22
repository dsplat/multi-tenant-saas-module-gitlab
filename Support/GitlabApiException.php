<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Support;

use RuntimeException;
use Throwable;

/**
 * GitLab API 调用异常（脱敏）。
 *
 * message 只允许“HTTP 状态 + 阶段”这类摘要；响应体、URL 查询串、token
 * 一律不进入异常，避免随日志/接口错误透出凭据。
 */
class GitlabApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function forStatus(int $status): self
    {
        $message = $status === 0
            ? 'GitLab 网络请求失败'
            : sprintf('GitLab API 返回异常状态（HTTP %d）', $status);

        return new self($status, $message);
    }

    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
