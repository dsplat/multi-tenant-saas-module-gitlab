<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Events;

/**
 * Tag Push 归一化事件。
 */
final readonly class GitlabTagPushed extends GitlabEvent
{
    /**
     * @param  list<string>  $taskKeys
     */
    public function __construct(
        int $tenantId,
        int $connectionId,
        int $eventId,
        public ?string $externalProjectId,
        public string $tag,
        public ?string $commit = null,
        public array $taskKeys = [],
        public ?string $occurredAt = null,
    ) {
        parent::__construct($tenantId, $connectionId, $eventId);
    }
}
