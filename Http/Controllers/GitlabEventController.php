<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Gitlab\Http\Resources\GitlabEventResource;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabQueryService;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabWebhookService;

/**
 * Webhook 事件投影查询与失败事件重放。
 */
class GitlabEventController extends BaseController
{
    public function __construct(
        private readonly GitlabQueryService $query,
        private readonly GitlabWebhookService $webhooks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->query->paginateEvents(
            $this->tenantId(),
            $request->only([
                'project_id', 'external_project_id', 'connection_id',
                'event_type', 'status', 'mr_iid',
            ]),
            $request->integer('per_page') ?: null,
        );

        $items = array_map(
            static fn ($event): array => (new GitlabEventResource($event))->resolve(),
            $paginator->items(),
        );

        return $this->successResponse(['data' => $items, 'total' => $paginator->total()]);
    }

    public function replay(int $id): JsonResponse
    {
        $event = $this->webhooks->replay($this->tenantId(), $id);

        return $this->successResponse(
            (new GitlabEventResource($event))->resolve(),
            '事件已重放',
        );
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
