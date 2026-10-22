<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Events;

/**
 * GitLab 领域事件基类：只携带归一化事实，不含原始 payload / token。
 */
abstract readonly class GitlabEvent
{
    public function __construct(
        public int $tenantId,
        public int $connectionId,
        public int $eventId,
    ) {}
}
