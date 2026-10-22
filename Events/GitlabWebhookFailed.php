<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Events;

/**
 * Webhook 归一化失败（供告警/重放工作台消费）；error 已脱敏且截断。
 */
final readonly class GitlabWebhookFailed extends GitlabEvent
{
    public function __construct(
        int $tenantId,
        int $connectionId,
        int $eventId,
        public int $inboxEventId,
        public string $eventType,
        public string $error,
    ) {
        parent::__construct($tenantId, $connectionId, $eventId);
    }
}
