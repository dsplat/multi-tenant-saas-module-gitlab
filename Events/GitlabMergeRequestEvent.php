<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Events;

/**
 * Merge Request 归一化事件（open/update/merged/closed 共用字段）。
 */
abstract readonly class GitlabMergeRequestEvent extends GitlabEvent
{
    /**
     * @param  list<string>  $taskKeys
     */
    public function __construct(
        int $tenantId,
        int $connectionId,
        int $eventId,
        public ?string $externalProjectId,
        public int $mrIid,
        public string $status,
        public array $taskKeys,
        public ?string $occurredAt = null,
        public ?string $url = null,
    ) {
        parent::__construct($tenantId, $connectionId, $eventId);
    }
}
