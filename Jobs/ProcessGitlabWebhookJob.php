<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MultiTenantSaas\Concerns\HasTaskAuthEnvelope;
use MultiTenantSaas\Context\CarriesTaskAuthEnvelope;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabWebhookService;

/**
 * 异步消费 GitLab webhook 收件箱事件。
 *
 * 入参只带 IDs：payload 已在收件箱表中，队列消息不复制事件内容，避免敏感数据
 * 进入队列存储/日志。失败在 service 内收口（脱敏 error + GitlabWebhookFailed）。
 */
class ProcessGitlabWebhookJob implements CarriesTaskAuthEnvelope, ShouldQueue
{
    use Dispatchable;
    use HasTaskAuthEnvelope;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $connectionId,
        public readonly int $inboxEventId,
    ) {
        $this->onQueue('gitlab');
    }

    public function handle(GitlabWebhookService $webhooks): void
    {
        $webhooks->process($this->tenantId, $this->inboxEventId);
    }
}
