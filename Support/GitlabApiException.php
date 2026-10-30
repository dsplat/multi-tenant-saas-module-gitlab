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
        public readonly string $reason = '',
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

    /**
     * 列表未在限额内取完：不得返回“看起来完整”的伪结果。
     */
    public static function forLimit(string $reason): self
    {
        return new self(0, sprintf('GitLab 列表未在限额内取完（%s）', $reason), null, $reason);
    }

    /**
     * 移动冲突：目标已存在、源并发变化，或网络超时后无法用证据对账。
     */
    public static function forConflict(string $reason): self
    {
        return new self(409, sprintf('GitLab 移动冲突（%s）', $reason), null, $reason);
    }

    public function isRetryable(): bool
    {
        if ($this->reason !== '') {
            return false;
        }

        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
