<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Events;

/**
 * 任一允许的 GitLab webhook 已归一化并入投影。
 */
final readonly class GitlabWebhookReceived extends GitlabEvent
{
    /**
     * @param  list<string>  $taskKeys
     */
    public function __construct(
        int $tenantId,
        int $connectionId,
        int $eventId,
        public int $inboxEventId,
        public string $eventType,
        public ?string $externalProjectId,
        public ?int $mrIid,
        public ?int $pipelineId,
        public ?string $status,
        public array $taskKeys,
        public string $receivedAt,
    ) {
        parent::__construct($tenantId, $connectionId, $eventId);
    }
}
