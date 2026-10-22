<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TaskAuthEnvelope;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Gitlab\Jobs\ProcessGitlabWebhookJob;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabWebhookService;

/**
 * GitLab webhook 公开入口（无登录）。
 *
 * 安全：
 * - X-Gitlab-Token 与连接 credentials.webhook_secret 常量时间比较；
 * - 连接不存在、已停用、签名错误统一 401，不区分原因；
 * - body 超过配置上限直接 413；
 * - X-Gitlab-Event 必须在允许列表内；
 * - 验签后才解析/落库，原始 payload 只进 Integration 收件箱。
 */
class GitlabWebhookController extends BaseController
{
    public function __construct(private readonly GitlabWebhookService $webhooks) {}

    public function __invoke(Request $request, string $connection): JsonResponse
    {
        $maxBytes = (int) config('gitlab.webhook.max_body_bytes', 2 * 1024 * 1024);
        $content = $request->getContent();
        $declared = (int) $request->header('Content-Length', '0');

        if (strlen($content) > $maxBytes || $declared > $maxBytes) {
            return $this->errorResponse('请求体过大', 413);
        }

        $record = $this->webhooks->findPublicConnection($connection);

        if ($record === null || ! $this->webhooks->verifyToken($record, $request->header('X-Gitlab-Token'))) {
            return $this->errorResponse('Webhook 校验失败', 401);
        }

        $eventHeader = trim((string) $request->header('X-Gitlab-Event', ''));
        $allowed = array_values((array) config('gitlab.webhook.allowed_events', []));

        if (! in_array($eventHeader, $allowed, true)) {
            return $this->errorResponse('不支持的事件类型', 422);
        }

        $payload = json_decode($content, true);

        if (! is_array($payload)) {
            return $this->errorResponse('事件内容不是合法 JSON 对象', 400);
        }

        $eventType = $this->webhooks->eventType($payload, $eventHeader);
        $externalEventId = trim((string) $request->header('X-Gitlab-Event-UUID', ''));

        if ($externalEventId === '') {
            $externalEventId = hash('sha256', $content);
        }

        $tenantId = (int) $record->tenant_id;
        $connectionId = (int) $record->connection_id;

        $inbox = $this->webhooks->recordInbox(
            $tenantId,
            $connectionId,
            $externalEventId,
            $eventType,
            $payload,
        );

        ProcessGitlabWebhookJob::dispatch($tenantId, $connectionId, (int) $inbox->getKey())
            ->withTaskAuthEnvelope(TaskAuthEnvelope::forChannelService(
                'gitlab.webhook',
                $tenantId,
                'gitlab.webhook',
            ));

        return $this->successResponse([
            'event_id' => (int) $inbox->getKey(),
            'event_type' => $inbox->event_type,
            'status' => $inbox->status,
        ], 'Accepted', 202);
    }
}
