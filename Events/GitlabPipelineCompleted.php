<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Events;

/**
 * Pipeline 到达终态（success/failed/canceled/skipped）的归一化事件。
 */
final readonly class GitlabPipelineCompleted extends GitlabEvent
{
    /**
     * @param  list<string>  $taskKeys
     */
    public function __construct(
        int $tenantId,
        int $connectionId,
        int $eventId,
        public ?string $externalProjectId,
        public int $pipelineId,
        public string $status,
        public ?string $ref = null,
        public array $taskKeys = [],
        public ?string $occurredAt = null,
        public ?string $url = null,
    ) {
        parent::__construct($tenantId, $connectionId, $eventId);
    }
}
