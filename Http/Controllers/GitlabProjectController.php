<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Gitlab\Http\Requests\StoreGitlabProjectRequest;
use MultiTenantSaas\Modules\Gitlab\Http\Resources\GitlabProjectResource;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabProjectService;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabQueryService;

/**
 * GitLab 项目投影与文档空间映射（创建/接管）。
 */
class GitlabProjectController extends BaseController
{
    public function __construct(
        private readonly GitlabQueryService $query,
        private readonly GitlabProjectService $projects,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->query->paginateProjects(
            $this->tenantId(),
            $request->only(['project_id', 'external_project_id', 'connection_id', 'visibility', 'keyword']),
            $request->integer('per_page') ?: null,
        );

        $items = array_map(
            static fn ($project): array => (new GitlabProjectResource($project))->resolve(),
            $paginator->items(),
        );

        return $this->successResponse(['data' => $items, 'total' => $paginator->total()]);
    }

    public function store(StoreGitlabProjectRequest $request): JsonResponse
    {
        $result = $this->projects->createOrAdopt($this->tenantId(), $request->validated());
        $payload = [
            'project' => (new GitlabProjectResource($result['project']))->resolve(),
            'created' => $result['created'],
            'adopted' => $result['adopted'],
            'mapping_error' => $result['mapping_error'],
        ];

        if ($result['created']) {
            return $this->createdResponse($payload, 'GitLab 文档空间已创建');
        }

        return $this->successResponse($payload, $result['adopted'] ? '已接管 GitLab 项目' : '复用已有映射');
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
