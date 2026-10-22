<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Events;

/**
 * 连接连通性检查结果（不含 token / URL 凭据）。
 */
final readonly class GitlabConnectionChecked
{
    public function __construct(
        public int $tenantId,
        public int $connectionId,
        public bool $ok,
        public ?string $error = null,
    ) {}
}
