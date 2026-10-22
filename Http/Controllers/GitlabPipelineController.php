<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Gitlab\Http\Resources\GitlabEventResource;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabQueryService;

/**
 * Pipeline 只读投影（事实展示，不做门禁判定）。
 */
class GitlabPipelineController extends BaseController
{
    public function __construct(private readonly GitlabQueryService $query) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->query->paginatePipelines(
            $this->tenantId(),
            $request->only(['project_id', 'external_project_id', 'connection_id', 'status', 'pipeline_id']),
            $request->integer('per_page') ?: null,
        );

        $items = array_map(
            static fn ($event): array => (new GitlabEventResource($event))->resolve(),
            $paginator->items(),
        );

        return $this->successResponse(['data' => $items, 'total' => $paginator->total()]);
    }

    public function show(int $id): JsonResponse
    {
        $event = $this->query->findPipeline($this->tenantId(), $id);

        return $this->successResponse((new GitlabEventResource($event))->resolve());
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
